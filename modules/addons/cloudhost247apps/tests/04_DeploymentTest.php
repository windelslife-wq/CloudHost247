<?php
/**
 * Suite 04 — the deployment engine.
 *
 * Covers everything between a customer clicking "install" and a container being
 * healthy: the job queue (leases, retries, backoff, dead letters, cancellation),
 * the payment gate that alone may release provisioning, the orchestrator that
 * drives an adapter step by step, and the rollback that must leave nothing
 * unrecorded when a deployment fails halfway.
 *
 * The engine is exercised against the FakeAdapter in dry-run mode: it renders the
 * real compose and environment artifacts, so the assertions check what a node
 * would actually receive, not a stub's return value.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Adapters\AdapterFactory;
use Ch247Apps\Adapters\DeploymentContext;
use Ch247Apps\Adapters\FakeAdapter;
use Ch247Apps\Billing\PaymentGate;
use Ch247Apps\Catalog\ManifestRepository;
use Ch247Apps\Catalog\VersionService;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\ImagePullException;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\PaymentException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\DeploymentService;
use Ch247Apps\Deployments\EnvironmentService;
use Ch247Apps\Deployments\InstallationService;
use Ch247Apps\Deployments\InstallationState;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Domains\DomainService;
use Ch247Apps\Servers\ServerService;

Harness::boot([
    'apps_base_path' => '/opt/cloudhost247/apps',
    'app_preview_domain' => 'apps.cloudhost247.test',
    'deployment_timeout_seconds' => '600',
    'crash_loop_restart_threshold' => '3',
    'crash_loop_window_minutes' => '30',
    'deployment_max_step_attempts' => '3',
]);
Harness::relaxRateLimits();

$super = Harness::adminActor(1);
$customer = Harness::customerActor(42);
$worker = Actor::system('Worker');

Harness::catalog(true);

// Dry run: every adapter call is served by the fake, which still renders the real
// artifacts. Nothing in this suite talks to a real node.
$fake = AdapterFactory::setFake(new FakeAdapter());

$queue = new JobQueue();
$servers = new ServerService($super);
$deployments = new DeploymentService($super);
$orchestrator = new Orchestrator($worker);
$environment = new EnvironmentService($worker);
$domains = new DomainService($customer);

$node = Harness::onlineServer();

/**
 * Re-check a server in. The queue section travels the clock by hours, and a
 * server that has not reported is honestly ineligible — so every scenario that
 * installs something starts with a fresh heartbeat.
 */
$beat = function ($serverId = null) use ($servers, $node) {
    $serverId = $serverId === null ? (int) $node['id'] : (int) $serverId;
    $agents = $servers->agents($serverId);
    if ($agents !== []) {
        $servers->heartbeat($agents[0]['agent_uuid'], ['agent_version' => '1.4.2', 'ip' => '198.51.100.10']);
    }
    return $servers->present($serverId);
};

$app = Harness::application('n8n');
$version = Harness::version('n8n');
$manifest = ManifestRepository::forVersion((int) $version['id']);

/* ------------------------------------------------------------ job queue -- */

section('The job queue');

$job = $queue->enqueue(JobQueue::TYPE_HEALTHCHECK, ['installation_id' => 1], [
    'queue' => JobQueue::QUEUE_MAINTENANCE,
    'priority' => JobQueue::PRIORITY_LOW,
    'client_id' => 42,
]);
T::ok('a job is queued with an id', (int) $job['id'] > 0);
T::is('and starts queued', JobQueue::STATUS_QUEUED, $job['status']);
T::is('with no attempts yet', 0, $job['attempts']);
T::is('on the requested queue', JobQueue::QUEUE_MAINTENANCE, $job['queue']);
T::is('at the requested priority', JobQueue::PRIORITY_LOW, $job['priority']);
T::ok('and a uuid for tracing', strlen((string) $job['uuid']) > 10);

$again = $queue->enqueue(JobQueue::TYPE_HEALTHCHECK, ['installation_id' => 1], [
    'idempotency_key' => 'hc:1', 'queue' => JobQueue::QUEUE_MAINTENANCE,
]);
$dup = $queue->enqueue(JobQueue::TYPE_HEALTHCHECK, ['installation_id' => 1], [
    'idempotency_key' => 'hc:1', 'queue' => JobQueue::QUEUE_MAINTENANCE,
]);
T::is('an idempotency key dedupes a double submit', (int) $again['id'], (int) $dup['id']);
T::is('and only one row exists', 1, Db::count('jobs', ['idempotency_key' => 'hc:1']));

T::throws('an unknown job type is rejected', ValidationException::class, function () use ($queue) {
    $queue->enqueue('launch_missiles');
});

Settings::override('worker_enabled', '0');
T::throws('a disabled worker refuses new jobs', StateException::class, function () use ($queue) {
    $queue->enqueue(JobQueue::TYPE_HEALTHCHECK);
});
Settings::override('worker_enabled', '1');

$delayed = $queue->enqueue(JobQueue::TYPE_INSTALL, [], [
    'available_at' => Clock::at(120), 'queue' => JobQueue::QUEUE_DEPLOYMENT,
]);
T::is('a delayed job is not leaseable yet', [], $queue->lease('worker-a', JobQueue::QUEUE_DEPLOYMENT, 5));

Clock::travel(130);
$leased = $queue->lease('worker-a', JobQueue::QUEUE_DEPLOYMENT, 5);
T::is('and becomes leaseable once due', 1, count($leased));
T::is('leased to the worker that claimed it', 'worker-a', $leased[0]['leased_by']);
T::is('in leased status', JobQueue::STATUS_LEASED, $leased[0]['status']);
T::ok('with a lease expiry', !empty($leased[0]['lease_expires_at']));
T::is('a second worker cannot claim the same job', [], $queue->lease('worker-b', JobQueue::QUEUE_DEPLOYMENT, 5));

$running = $queue->start((int) $leased[0]['id'], 'worker-a');
T::is('starting a job moves it to running', JobQueue::STATUS_RUNNING, $running['status']);
T::is('and counts the attempt', 1, $running['attempts']);
T::ok('with a start time', !empty($running['started_at']));
T::ok('a long job can extend its lease', $queue->touch((int) $leased[0]['id']));

$done = $queue->complete((int) $leased[0]['id'], ['state' => 'deployed']);
T::is('a completed job says so', JobQueue::STATUS_COMPLETED, $done['status']);
T::is('and keeps its result', 'deployed', $done['result']['state']);
T::ok('with a duration', $done['duration_ms'] !== null);

$critical = $queue->enqueue(JobQueue::TYPE_STOP, [], ['priority' => JobQueue::PRIORITY_CRITICAL]);
$bulk = $queue->enqueue(JobQueue::TYPE_CLEANUP, [], ['priority' => JobQueue::PRIORITY_BULK]);
$order = $queue->lease('worker-c', JobQueue::QUEUE_DEPLOYMENT, 5);
T::is('both due jobs are claimed', 2, count($order));
T::is('critical work is leased before bulk work', (int) $critical['id'], (int) $order[0]['id']);
$queue->complete((int) $critical['id']);
$queue->complete((int) $bulk['id']);

$flaky = $queue->enqueue(JobQueue::TYPE_INSTALL, [], [
    'idempotency_key' => 'flaky', 'max_attempts' => 3,
]);
$queue->lease('worker-d', JobQueue::QUEUE_DEPLOYMENT, 5);
$queue->start((int) $flaky['id'], 'worker-d');
$retried = $queue->fail((int) $flaky['id'], 'Agent did not answer.', 'AGENT_UNREACHABLE', true);
T::is('a retryable failure goes back to queued', JobQueue::STATUS_QUEUED, $retried['status']);
T::is('and keeps the attempt count', 1, $retried['attempts']);
T::is('with the error code recorded', 'AGENT_UNREACHABLE', $retried['error_code']);
T::contains('and a human readable message', 'did not answer', (string) $retried['error_message']);
T::ok('and a backoff delay in the future', Clock::isFuture($retried['available_at']));
T::ok('the backoff grows with attempts', $queue->backoffSeconds(3) > $queue->backoffSeconds(1));
T::is('it is still retryable', true, $retried['retryable']);

$doomed = $queue->enqueue(JobQueue::TYPE_CLEANUP, [], [
    'idempotency_key' => 'doomed', 'max_attempts' => 2, 'priority' => JobQueue::PRIORITY_BULK,
]);
$doomedId = (int) $doomed['id'];
for ($round = 0; $round < 4; $round++) {
    Clock::travel(7200);
    $claim = $queue->lease('worker-e', JobQueue::QUEUE_DEPLOYMENT, 10);
    $mine = null;
    foreach ($claim as $row) {
        if ((int) $row['id'] === $doomedId) {
            $mine = $row;
        }
    }
    if ($mine === null) {
        break;
    }
    $queue->start($doomedId, 'worker-e');
    $queue->fail($doomedId, 'Still broken.', 'STEP_FAILED', true);
}
T::is('a job that keeps failing reaches the dead-letter queue',
    JobQueue::STATUS_DEAD, $queue->row($doomedId)['status']);
T::is('and is listed for an administrator', 1, count(array_filter($queue->dead(),
    function ($row) use ($doomedId) {
        return (int) $row['id'] === $doomedId;
    })));

$requeued = $queue->requeue($doomedId);
T::is('a dead job can be requeued by hand', JobQueue::STATUS_QUEUED, $requeued['status']);
T::is('with its attempts reset', 0, $requeued['attempts']);
$queue->cancel($doomedId, 'No longer needed');

$cancelMe = $queue->enqueue(JobQueue::TYPE_INSTALL, [], ['idempotency_key' => 'cancel-me']);
$cancelled = $queue->cancel((int) $cancelMe['id'], 'Customer changed their mind');
T::is('a queued job can be cancelled outright', JobQueue::STATUS_CANCELLED, $cancelled['status']);
T::contains('with the reason recorded', 'changed their mind', (string) $cancelled['error_message']);

$interrupt = $queue->enqueue(JobQueue::TYPE_INSTALL, [], ['idempotency_key' => 'interrupt']);
$queue->lease('worker-f', JobQueue::QUEUE_DEPLOYMENT, 20, 300);
$queue->start((int) $interrupt['id'], 'worker-f');
$queue->cancel((int) $interrupt['id'], 'Customer cancelled mid-deployment');
T::is('a running job is not killed, it is asked to stop',
    JobQueue::STATUS_RUNNING, $queue->row((int) $interrupt['id'])['status']);
T::is('and the checkpoint flag is set', true, $queue->cancelRequested((int) $interrupt['id']));

$stalled = $queue->enqueue(JobQueue::TYPE_INSTALL, [], ['idempotency_key' => 'stalled']);
$queue->lease('worker-g', JobQueue::QUEUE_DEPLOYMENT, 20, 1);
Clock::travel(5);
T::is('an expired lease is released back to the queue', 1, $queue->releaseExpiredLeases());
T::is('and the stranded job is queued again',
    JobQueue::STATUS_QUEUED, $queue->row((int) $stalled['id'])['status']);

$stats = $queue->statistics();
T::ok('statistics count every state', isset($stats['counts'][JobQueue::STATUS_COMPLETED])
    && isset($stats['counts'][JobQueue::STATUS_DEAD]));
T::ok('completed work is counted', (int) $stats['counts'][JobQueue::STATUS_COMPLETED] >= 3);
T::ok('cancelled work is counted', (int) $stats['counts'][JobQueue::STATUS_CANCELLED] >= 1);

T::throws('an unknown job id is a 404', NotFoundException::class, function () use ($queue) {
    $queue->row(999999);
});
T::throws('only a live job can be cancelled', StateException::class, function () use ($queue, $cancelMe) {
    $queue->cancel((int) $cancelMe['id']);
});

/* ------------------------------------------------- creating an installation */

section('Creating an installation');

$node = $beat();
T::is('the node is online and eligible again', ServerService::STATUS_ONLINE, $node['status']);

$paidPlan = Harness::plan(['slug' => 'app-cloud-starter', 'price_minor' => 2500]);
$freePlan = Harness::plan(['name' => 'App Cloud Trial', 'slug' => 'app-cloud-trial', 'price_minor' => 0]);

$customerInstalls = new InstallationService($customer);
$order = Harness::$gateway->createOrder([
    'clientid' => 42, 'pid' => (int) $paidPlan['whmcs_product_id'], 'billingcycle' => 'Monthly',
    'domain' => '',
]);

$wizardInput = [
    'application_id' => (int) $app['id'],
    'application_version_id' => (int) $version['id'],
    'server_id' => (int) $node['id'],
    'plan_id' => (int) $paidPlan['id'],
    'name' => 'Ada workflows',
    'domain' => 'flow.example.test',
    'whmcs_order_id' => $order['order_id'],
    'whmcs_invoice_id' => $order['invoice_id'],
    'environment' => ['TZ' => 'Africa/Lagos'],
    'idempotency_key' => 'wizard-42-1',
];
$pending = $customerInstalls->create($wizardInput);

$installationId = (int) $pending['installation']['id'];
T::ok('the installation row is created', $installationId > 0);
T::is('and waits for payment', InstallationState::PENDING, $pending['installation']['status']);
T::is('nothing is queued yet', true, $pending['awaiting_payment']);
T::is('and no deployment exists', null, $pending['deployment']);
T::is('and no job exists', null, $pending['job']);
T::is('the payment status is unpaid', 'unpaid', $pending['installation']['payment_status']);
T::ok('the reference is human readable', strpos((string) $pending['installation']['reference'], 'APP-') === 0);
T::is('the container project is namespaced per customer',
    'c42n8n', $pending['installation']['container_project']);
T::is('the domain is attached as primary', 'flow.example.test', $pending['installation']['domain']);
T::ok('an order link records what is owed', isset($pending['order']['id']));
T::is('the order link is pending', PaymentGate::STATUS_PENDING, $pending['order']['status']);
T::is('and points at the invoice', (int) $order['invoice_id'], (int) $pending['order']['whmcs_invoice_id']);

$replayed = $customerInstalls->create($wizardInput);
T::is('replaying the same request returns the same installation',
    $installationId, (int) $replayed['installation']['id']);
T::is('and says it was replayed', true, $replayed['replayed']);
T::is('without creating a second row', 1, Db::count('installations', ['customer_id' => 42]));

$tamperedReplay = $wizardInput;
$tamperedReplay['name'] = 'Something else entirely';
T::throws('replaying the same key with different data is refused',
    \Ch247Apps\Core\ConflictException::class,
    function () use ($customerInstalls, $tamperedReplay) {
        $customerInstalls->create($tamperedReplay);
    });

$claimOrder = Harness::$gateway->createOrder([
    'clientid' => 42, 'pid' => (int) $paidPlan['whmcs_product_id'], 'billingcycle' => 'Monthly',
]);
$claimedPaid = $customerInstalls->create([
    'application_id' => (int) $app['id'],
    'application_version_id' => (int) $version['id'],
    'server_id' => (int) $node['id'],
    'plan_id' => (int) $paidPlan['id'],
    'domain' => 'claim.example.test',
    'whmcs_order_id' => $claimOrder['order_id'],
    'whmcs_invoice_id' => $claimOrder['invoice_id'],
    'payment_status' => 'paid',
    'idempotency_key' => 'wizard-42-claim',
]);
T::is('a customer claiming "I paid" is ignored', true, $claimedPaid['awaiting_payment']);
T::is('and their installation still waits', 'unpaid', $claimedPaid['installation']['payment_status']);

/* ------------------------------------- payment rules at creation time -- */

// A customer install with no plan has no price and no invoice, so nothing could
// ever confirm payment. It must be refused, not provisioned for free.
$noPlanInstalls = Db::count('installations', ['customer_id' => 42]);
$noPlanInput = $wizardInput;
unset($noPlanInput['plan_id']);
$noPlanInput['idempotency_key'] = 'wizard-42-noplan';
$noPlanInput['domain'] = 'noplan.example.test';
T::throws('a customer install without a plan is refused', ValidationException::class,
    function () use ($customerInstalls, $noPlanInput) {
        $customerInstalls->create($noPlanInput);
    });
T::is('and no installation row is created for it', $noPlanInstalls,
    Db::count('installations', ['customer_id' => 42]));

// bypass_payment in a request body must not skip payment for a customer.
$bypassOrder = Harness::$gateway->createOrder([
    'clientid' => 42, 'pid' => (int) $paidPlan['whmcs_product_id'], 'billingcycle' => 'Monthly',
]);
$customerBypass = $customerInstalls->create([
    'application_id' => (int) $app['id'],
    'application_version_id' => (int) $version['id'],
    'server_id' => (int) $node['id'],
    'plan_id' => (int) $paidPlan['id'],
    'domain' => 'bypass.example.test',
    'whmcs_order_id' => $bypassOrder['order_id'],
    'whmcs_invoice_id' => $bypassOrder['invoice_id'],
    'bypass_payment' => true,
    'idempotency_key' => 'wizard-42-bypass',
]);
T::is('a customer bypass_payment flag is ignored: the install still waits', true,
    $customerBypass['awaiting_payment']);
T::is('and it is still unpaid', 'unpaid', $customerBypass['installation']['payment_status']);
T::is('and nothing is queued', null, $customerBypass['job']);

// An administrator who manages plans may bypass payment deliberately.
$adminActor = Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Root Admin', ['ip' => '198.51.100.4']);
$adminBypass = (new InstallationService($adminActor))->create([
    'customer_id' => 42,
    'application_id' => (int) $app['id'],
    'application_version_id' => (int) $version['id'],
    'server_id' => (int) $node['id'],
    'plan_id' => (int) $paidPlan['id'],
    'domain' => 'admin-bypass.example.test',
    'bypass_payment' => true,
    'idempotency_key' => 'admin-42-bypass',
]);
T::is('an administrator bypass is honoured and provisioning starts', false, $adminBypass['awaiting_payment']);
T::is('and the payment status records that payment was not required', 'not_required',
    $adminBypass['installation']['payment_status']);

/* ------------------------------------------------------- the payment gate -- */

section('The payment gate');

$gate = new PaymentGate(Actor::system('Webhook'));
$secret = 'whsec_harness_secret';
Settings::override('webhook_secret_stripe', $secret);

/** Build a signed Stripe-shaped webhook for one invoice. */
$webhook = function ($eventId, $invoiceId, array $extra = []) use ($secret) {
    $payload = array_merge([
        'id' => $eventId, 'type' => 'invoice.paid',
        'invoice_id' => (int) $invoiceId, 'amount' => 2500, 'currency' => 'usd',
    ], $extra);
    $body = Str::jsonEncode($payload);
    $timestamp = Clock::timestamp();
    return [$body, 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret)];
};

list($body, $noSignature) = $webhook('evt_unsigned', (int) $order['invoice_id']);
$unsigned = $gate->handleWebhook('stripe', $body, '');
T::is('a webhook with no signature is refused', 'failed', $unsigned['status']);
T::is('with a clear error code', 'WEBHOOK_SIGNATURE_MISSING', $unsigned['error_code']);
T::is('and provisions nothing', [], $unsigned['provisioned']);

$noSecretProvider = $gate->handleWebhook('paypal', $body, 'deadbeef');
T::is('a provider with no configured secret is refused', 'failed', $noSecretProvider['status']);
T::is('so an unconfigured provider can never provision', 'WEBHOOK_SECRET_NOT_CONFIGURED',
    $noSecretProvider['error_code']);

list($body, $signature) = $webhook('evt_unpaid', (int) $order['invoice_id']);
$unpaid = null;
try {
    $gate->handleWebhook('stripe', $body, $signature);
} catch (\Throwable $e) {
    $unpaid = $e;
}
T::ok('a correctly signed webhook for an unpaid invoice still refuses', $unpaid instanceof PaymentException);
T::is('with PAYMENT_NOT_CONFIRMED', 'PAYMENT_NOT_CONFIRMED',
    $unpaid instanceof PaymentException ? $unpaid->errorCode() : 'none');
T::is('so nothing was provisioned', 'unpaid', Db::first('installations', ['id' => $installationId])['payment_status']);

list($body, $badSignature) = $webhook('evt_tampered', (int) $order['invoice_id']);
$tampered = $gate->handleWebhook('stripe', $body, 't=' . Clock::timestamp() . ',v1=' . str_repeat('0', 64));
T::is('a wrong signature is refused', 'failed', $tampered['status']);
T::is('and recorded as such', 'WEBHOOK_SIGNATURE_INVALID', $tampered['error_code']);

list($body, $staleSignature) = $webhook('evt_stale', (int) $order['invoice_id']);
$staleTimestamp = Clock::timestamp() - 99999;
$stale = $gate->handleWebhook('stripe', $body,
    't=' . $staleTimestamp . ',v1=' . hash_hmac('sha256', $staleTimestamp . '.' . $body, $secret));
T::is('an old webhook is refused', 'failed', $stale['status']);
T::is('by the replay window', 'WEBHOOK_TIMESTAMP_SKEW', $stale['error_code']);

$failedEvent = Db::first('payment_events', ['provider_event_id' => 'evt_tampered']);
T::is('a refused webhook is still stored for the audit trail', 'failed', $failedEvent['status']);
T::is('with its signature verdict', 'failed', $failedEvent['signature_status']);
T::contains('with the reason it was refused', 'signature did not match',
    (string) $failedEvent['error_message']);

$ignored = $gate->handleWebhook('stripe',
    Str::jsonEncode(['id' => 'evt_ignored', 'type' => 'customer.updated',
        'invoice_id' => (int) $order['invoice_id']]),
    't=' . Clock::timestamp() . ',v1=' . hash_hmac('sha256',
        Clock::timestamp() . '.' . Str::jsonEncode(['id' => 'evt_ignored', 'type' => 'customer.updated',
            'invoice_id' => (int) $order['invoice_id']]), $secret));
T::is('an event that is not a payment is ignored', 'ignored', $ignored['status']);
T::is('and provisions nothing', [], $ignored['provisioned']);

Harness::$gateway->payInvoice((int) $order['invoice_id']);

list($body, $signature) = $webhook('evt_paid', (int) $order['invoice_id']);
$processed = $gate->handleWebhook('stripe', $body, $signature);
T::is('a signed webhook for a paid invoice is processed', 'processed', $processed['status']);
T::is('and provisions exactly one installation', 1, count($processed['provisioned']));
T::is('the right one', $installationId, (int) $processed['provisioned'][0]['installation_id']);

$again = $gate->handleWebhook('stripe', $body, $signature);
T::is('the same provider event is never processed twice', true, (bool) $again['replayed']);
T::is('and provisions nothing the second time', [], $again['provisioned']);

list($body, $signature) = $webhook('evt_paid_again', (int) $order['invoice_id']);
$third = $gate->handleWebhook('stripe', $body, $signature);
T::is('a new event for the same invoice does not deploy again', true,
    !empty($third['provisioned'][0]['already_triggered']));
T::is('and only one install job exists for the installation', 1,
    Db::count('jobs', ['installation_id' => $installationId, 'job_type' => JobQueue::TYPE_INSTALL]));

$failedPayment = null;
try {
    list($body, $signature) = $webhook('evt_declined', (int) $claimOrder['invoice_id'],
        ['type' => 'invoice.payment_failed']);
    $failedPayment = $gate->handleWebhook('stripe', $body, $signature);
} catch (\Throwable $e) {
    $failedPayment = $e;
}
T::ok('a declined payment is processed without provisioning', is_array($failedPayment));
T::is('and recorded as a failure outcome', 'payment_failed',
    is_array($failedPayment) ? $failedPayment['outcome'] : 'none');

$paidRow = Db::first('installations', ['id' => $installationId]);
T::is('the installation is now paid', 'paid', $paidRow['payment_status']);
T::is('and queued for deployment', InstallationState::QUEUED, $paidRow['status']);
T::ok('with a paid timestamp', !empty($paidRow['paid_at']));
$link = Db::first('order_links', ['installation_id' => $installationId]);
T::is('the order link is paid', PaymentGate::STATUS_PAID, $link['status']);
T::is('and its provisioning gate is closed', 1, (int) $link['provisioning_triggered']);
T::is('the still-unpaid installation was left alone', 'unpaid',
    Db::first('installations', ['id' => (int) $claimedPaid['installation']['id']])['payment_status']);

T::contains('the payment was audited', Audit::PAYMENT_CONFIRMED,
    array_map(function ($row) {
        return $row['action'];
    }, Audit::search(['action' => Audit::PAYMENT_CONFIRMED])));

/* --------------------------------------------------- running the deployment */

section('Running the deployment');

// Clear the queue of the jobs earlier scenarios left behind, so the worker picks
// up the install job and nothing else.
foreach (Db::fetch('jobs', ['status' => ['in', JobQueue::LIVE]]) as $row) {
    if ((int) $row['installation_id'] === $installationId) {
        continue;
    }
    try {
        $queue->cancel((int) $row['id'], 'test cleanup');
    } catch (\Throwable $e) {
        // already finished
    }
}

$installDeployment = $deployments->latestFor($installationId, DeploymentService::ACTION_INSTALL);
T::ok('a deployment record exists', $installDeployment !== null);
$deploymentId = (int) $installDeployment['id'];
T::is('for the install action', DeploymentService::ACTION_INSTALL, $installDeployment['action']);
T::is('queued until a worker takes it', DeploymentService::STATUS_QUEUED, $installDeployment['status']);
T::ok('with an idempotency key', !empty($installDeployment['idempotency_key']));
T::ok('and a deployment reference', !empty($installDeployment['reference']));

$waiting = $queue->pendingFor($installationId);
T::is('exactly one job is waiting for the installation', 1, count($waiting));
T::is('it is an install job', JobQueue::TYPE_INSTALL, $waiting[0]['job_type']);
T::is('linked to the deployment', $deploymentId, (int) $waiting[0]['deployment_id']);
T::is('and the deployment points back at the job', (int) $waiting[0]['id'],
    (int) $installDeployment['job_id']);
T::is('at high priority', JobQueue::PRIORITY_HIGH, $waiting[0]['priority']);

$beat();
$claimed = $queue->lease('worker-1', JobQueue::QUEUE_DEPLOYMENT, 5);
T::is('the worker claims the install job', (int) $waiting[0]['id'], (int) $claimed[0]['id']);

$runResult = $orchestrator->runJob($claimed[0]);
T::is('and the job completes', 'completed', $runResult['status']);
T::is('the deployment succeeded', DeploymentService::STATUS_SUCCEEDED, $runResult['result']['status']);
T::is('the adapter that ran it is recorded', 'fake', $runResult['result']['adapter']);

$steps = $deployments->steps($deploymentId);
T::ok('every planned step has a record', count($steps) >= 8);
$notSucceeded = array_values(array_filter($steps, function ($step) {
    return (string) $step['status'] !== DeploymentService::STEP_SUCCEEDED;
}));
T::is('and every step succeeded', 0, count($notSucceeded));
T::is('steps are ordered', range(1, count($steps)), array_map(function ($step) {
    return (int) $step['step_order'];
}, $steps));
T::contains('the image pull is one of them', 'pull_images', array_map(function ($step) {
    return $step['key'];
}, $steps));
T::contains('and so is the health check', 'health_check', array_map(function ($step) {
    return $step['key'];
}, $steps));
T::ok('each step recorded how long it took', $steps[0]['duration_ms'] !== null);

$finished = $deployments->row($deploymentId);
T::is('progress reached 100', 100, (int) $finished['progress']);
T::ok('with a duration', (int) $finished['duration_ms'] >= 0);
T::ok('and a completion time', !empty($finished['completed_at']));
T::is('the job is completed too', JobQueue::STATUS_COMPLETED,
    $queue->row((int) $waiting[0]['id'])['status']);

$installed = Db::first('installations', ['id' => $installationId]);
T::is('the installation is healthy', InstallationState::HEALTHY, $installed['status']);
T::is('health comes from the probe, not a guess', 'healthy', $installed['health_status']);
T::ok('with a health timestamp', !empty($installed['health_checked_at']));
T::ok('and an install timestamp', !empty($installed['installed_at']));
T::is('the access url is the customer\'s own domain', 'https://flow.example.test', $installed['access_url']);
T::is('the recorded version matches', (string) $version['version'], $installed['current_version']);

$capacity = $servers->capacity((int) $node['id']);
T::ok('server cpu was reserved', $capacity['cpu_millicores']['allocated'] >= 1000);
T::ok('and memory too', $capacity['memory_mb']['allocated'] >= 1024);
T::ok('and storage too', $capacity['storage_mb']['allocated'] >= 5120);

$resources = $deployments->resourcesFor($deploymentId);
$resourceTypes = array_values(array_unique(array_map(function ($resource) {
    return $resource['resource_type'];
}, $resources)));
T::contains('the deployment directory is recorded', 'directory', $resourceTypes);
T::contains('the compose project is recorded', 'compose_project', $resourceTypes);
T::contains('every container is recorded', 'container', $resourceTypes);
T::contains('and every route is recorded', 'traefik_route', $resourceTypes);
T::is('all of them are still live', 0, count(array_filter($resources, function ($resource) {
    return (string) $resource['status'] !== DeploymentService::RESOURCE_CREATED;
})));
T::is('nothing leaked', 0, count($deployments->leaked()));
T::is('the containers belong to the project', 2, count(array_filter($resources,
    function ($resource) {
        return $resource['resource_type'] === 'container';
    })));

$live = $deployments->liveEvents($installationId, 0);
T::ok('the console can stream the deployment', count($live) > 0);
$eventNames = array_map(function ($event) {
    return isset($event['event']) ? $event['event'] : '';
}, $live);
T::contains('including step progress', Events::DEPLOYMENT_STEP_DONE, $eventNames);
T::contains('and the completion event', Events::DEPLOYMENT_COMPLETED, $eventNames);

$logs = $deployments->logs($deploymentId);
T::ok('the worker logged what it did', count($logs) >= count($steps));
$logText = implode(' ', array_map(function ($line) {
    return (string) $line['message'];
}, $logs));
T::contains('naming the adapter it used', 'fake', $logText);
T::contains('and the step it ran', 'Pull', $logText . ' ' . $logText);

T::contains('the completion was audited', Audit::DEPLOYMENT_COMPLETED, array_map(function ($row) {
    return $row['action'];
}, Audit::search(['action' => Audit::DEPLOYMENT_COMPLETED])));
T::contains('and so was the status change', Audit::INSTALLATION_STATUS_CHANGED, array_map(function ($row) {
    return $row['action'];
}, Audit::search(['action' => Audit::INSTALLATION_STATUS_CHANGED])));

/* --------------------------------------------------- what the node receives */

section('What a node actually receives');

$project = $installed['container_project'];
$artifacts = $fake->artifacts($project);
T::ok('the adapter rendered real artifacts', $artifacts !== null);
T::is('for the customer-namespaced project', $project, $artifacts['project']);
T::contains('under the configured apps directory', '/opt/cloudhost247/apps', $artifacts['base_path']);
T::contains('in a per-customer directory', '/customer-42/', $artifacts['base_path']);
T::contains('scoped to the application', 'n8n', $artifacts['base_path']);
T::is('the compose file has a fixed name', 'compose.yaml', $artifacts['compose_file']);
T::is('and the environment file is hidden', '.env', $artifacts['env_file']);

T::ok('both services from the manifest are rendered',
    isset($artifacts['services']['app']) && isset($artifacts['services']['db']));
T::is('the primary service is exposed', true, $artifacts['services']['app']['exposed']);
T::is('the database is not', false, $artifacts['services']['db']['exposed']);
T::is('with the pinned image from the manifest', 'n8nio/n8n:1.70.1', $artifacts['services']['app']['image']);

$compose = $artifacts['compose'];
T::contains('compose names the project', $project, $compose);
T::contains('and pins the image', 'n8nio/n8n:1.70.1', $compose);
T::contains('including the database', 'postgres:16-alpine', $compose);
T::contains('routing is generated from the real domain', 'flow.example.test', $compose);
T::contains('through Traefik labels', 'traefik.http.routers.', $compose);
T::contains('with TLS enabled', '.tls=true', $compose);
T::notContains('no container port is published on the host', 'ports:', $compose);
T::contains('the database sits on an internal network', 'internal: true', $compose);
T::contains('cpu is capped', 'cpus:', $compose);
T::contains('and memory is capped', 'memory:', $compose);
T::contains('with a restart policy', 'restart:', $compose);
T::contains('and a container healthcheck', 'healthcheck:', $compose);
T::contains('volumes stay inside the deployment directory', './volumes/', $compose);
T::notContains('nothing is mounted from an arbitrary host path', '/etc/passwd', $compose);

$limits = $artifacts['limits']['app'];
T::ok('the plan\'s cpu ceiling is enforced', $limits['cpu_millicores'] >= 1000);
T::ok('and never below the manifest minimum', $limits['cpu_millicores'] <= 2000);
T::ok('memory is between the minimum and the plan', $limits['memory_mb'] >= 1024
    && $limits['memory_mb'] <= 4096);
T::is('the primary service takes the larger share of the plan', '1.50', $limits['limits']['cpus']);
T::ok('and the database gets the rest', $artifacts['limits']['db']['cpu_millicores'] > 0
    && $artifacts['limits']['db']['cpu_millicores'] < $limits['cpu_millicores']);
T::contains('and megabytes for memory', 'M', $limits['limits']['memory']);

$envFile = $artifacts['env'];
T::contains('the customer value is in the environment file', 'TZ=Africa/Lagos', $envFile);
T::contains('the generated secret is too', 'N8N_ENCRYPTION_KEY=', $envFile);
T::contains('and the database password', 'DB_PASSWORD=', $envFile);
T::contains('the primary domain is interpolated', 'flow.example.test', $envFile);
T::notContains('a secret never appears in compose.yaml',
    (string) $environment->reveal($installationId, 'N8N_ENCRYPTION_KEY'), $compose);
T::contains('only in the environment file',
    (string) $environment->reveal($installationId, 'N8N_ENCRYPTION_KEY'), $envFile);

/* ----------------------------------------------------- environment values -- */

section('Environment values');

$customerEnv = new EnvironmentService($customer);
$listing = $customerEnv->listing($installationId);
$byKey = [];
foreach ($listing as $entry) {
    $byKey[$entry['key']] = $entry;
}
T::ok('the customer can list their variables', count($listing) >= 4);
T::ok('the generated secret is there', isset($byKey['N8N_ENCRYPTION_KEY']));
T::is('and flagged secret', true, $byKey['N8N_ENCRYPTION_KEY']['is_secret']);
T::is('its value is never returned', null, $byKey['N8N_ENCRYPTION_KEY']['value']);
T::ok('it is masked instead', !empty($byKey['N8N_ENCRYPTION_KEY']['masked']));
T::is('generated values say where they came from', EnvironmentService::SOURCE_GENERATED,
    $byKey['N8N_ENCRYPTION_KEY']['source']);
T::is('customer values say so too', EnvironmentService::SOURCE_CUSTOMER, $byKey['TZ']['source']);
T::is('and a non-secret value is shown', 'Africa/Lagos', $byKey['TZ']['value']);

T::throws('a customer cannot reveal a secret', AuthorizationException::class,
    function () use ($customerEnv, $installationId) {
        $customerEnv->reveal($installationId, 'N8N_ENCRYPTION_KEY');
    });
T::throws('nor read them all', AuthorizationException::class,
    function () use ($customerEnv, $installationId) {
        $customerEnv->revealAll($installationId);
    });

$revealed = $environment->revealAll($installationId);
T::ok('the worker can read every value', count($revealed) >= 4);
T::ok('the generated secret is long enough to be a key',
    strlen((string) $revealed['N8N_ENCRYPTION_KEY']) >= 32);
T::isnt('and is not a placeholder', '', (string) $revealed['N8N_ENCRYPTION_KEY']);
T::ok('the database password was generated', strlen((string) $revealed['DB_PASSWORD']) >= 16);
T::is('the customer value round-trips', 'Africa/Lagos', $revealed['TZ']);

T::throws('an undeclared variable is refused', ValidationException::class,
    function () use ($environment, $installationId) {
        $environment->write($installationId, 'NOT_IN_THE_MANIFEST', 'whatever');
    });

T::throws('update() refuses an undeclared variable too', ValidationException::class,
    function () use ($customerEnv, $installationId) {
        $customerEnv->update($installationId, ['ARBITRARY_VAR' => 'x']);
    });
$customerEnv->update($installationId, ['TZ' => 'Europe/Zurich']);
T::is('a declared variable can be changed', 'Europe/Zurich', $environment->reveal($installationId, 'TZ'));
T::contains('and the change is audited', Audit::ENVIRONMENT_CHANGED, array_map(function ($row) {
    return $row['action'];
}, Audit::search(['action' => Audit::ENVIRONMENT_CHANGED])));

T::throws('another customer cannot touch these values', NotFoundException::class,
    function () use ($installationId) {
        $other = new EnvironmentService(Actor::customer(99, 'Someone Else'));
        $other->listing($installationId);
    });

$rotated = (new EnvironmentService($super))->reencryptAll();
T::is('nothing needed re-encryption yet', 0, $rotated['rewritten']);
T::is('and nothing failed', 0, $rotated['failed']);
T::is('values still decrypt afterwards', 'Europe/Zurich', $environment->reveal($installationId, 'TZ'));


Harness::shutdown();
exit(T::summary());
