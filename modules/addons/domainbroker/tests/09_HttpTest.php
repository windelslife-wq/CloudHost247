<?php
/**
 * Domain Broker — HTML surface tests.
 *
 * Exercises the three browser-facing controllers (customer portal, admin
 * console, broker desk) end to end: page rendering, CSRF enforcement,
 * post/redirect/get, authorisation, and the rule that a customer never sees
 * broker-private data.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\Csrf;
use DomainBroker\Core\Db;
use DomainBroker\Core\Identity;
use DomainBroker\Core\Money;
use DomainBroker\Core\Settings;
use DomainBroker\Http\AdminPortal;
use DomainBroker\Http\BrokerDesk;
use DomainBroker\Http\Controller;
use DomainBroker\Http\CustomerPortal;
use DomainBroker\Http\Html;
use DomainBroker\Http\View;
use DomainBroker\Services\MessageService;
use DomainBroker\Workflow\RequestStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();
Controller::$testMode = true;

/* ------------------------------------------------------------- helpers -- */

/** Reset the superglobals between simulated requests. */
function http_reset()
{
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    Controller::$redirects = [];
}

/** Simulate a GET of a client-area page. */
function client_get(Actor $actor, array $query = [])
{
    http_reset();
    $_GET = $query;
    Identity::override($actor);
    $portal = new CustomerPortal($actor);
    return $portal->handle([]);
}

/** Simulate a POST to the client area, with a valid CSRF token by default. */
function client_post(Actor $actor, $action, array $body = [], $withToken = true)
{
    http_reset();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['action' => $action];
    $_POST = $body;
    $_POST['action'] = $action;
    if ($withToken) {
        $_POST[Csrf::FIELD] = Csrf::token();
    }
    Identity::override($actor);
    $portal = new CustomerPortal($actor);
    $page = $portal->handle([]);
    $flash = '';
    foreach ($page['vars']['flash'] as $message) {
        $flash .= $message['type'] . ':' . $message['message'] . "\n";
    }
    return [
        'page' => $page,
        'flash' => $flash,
        'redirect' => Controller::$redirects ? Controller::$redirects[0]['url'] : null,
    ];
}

function admin_get(Actor $actor, array $query = [])
{
    http_reset();
    $_GET = $query;
    Identity::override($actor);
    $portal = new AdminPortal($actor);
    return $portal->handle(['modulelink' => 'addonmodules.php?module=domainbroker']);
}

function admin_post(Actor $actor, array $query, array $body, $withToken = true)
{
    http_reset();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = $query;
    $_POST = array_merge($query, $body);
    if ($withToken) {
        $_POST[Csrf::FIELD] = Csrf::token();
    }
    Identity::override($actor);
    $portal = new AdminPortal($actor);
    $html = $portal->handle(['modulelink' => 'addonmodules.php?module=domainbroker']);
    return [
        'html' => $html,
        'redirect' => Controller::$redirects ? Controller::$redirects[0]['url'] : null,
    ];
}

function desk_get(Actor $actor, array $query = [])
{
    http_reset();
    $_GET = $query;
    Identity::override($actor);
    $desk = new BrokerDesk($actor);
    return $desk->handle(['modulelink' => 'addonmodules.php?module=domainbroker']);
}

function desk_post(Actor $actor, array $query, array $body, $withToken = true)
{
    http_reset();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = $query;
    $_POST = array_merge($query, $body);
    if ($withToken) {
        $_POST[Csrf::FIELD] = Csrf::token();
    }
    Identity::override($actor);
    $desk = new BrokerDesk($actor);
    $html = $desk->handle(['modulelink' => 'addonmodules.php?module=domainbroker']);
    return [
        'html' => $html,
        'redirect' => Controller::$redirects ? Controller::$redirects[0]['url'] : null,
    ];
}

/** Drop anything still queued so one assertion cannot see another's message. */
function flash_clear()
{
    $_SESSION[Controller::FLASH_KEY] = [];
}

/* ---------------------------------------------------------------- setup -- */

$customer = Harness::client(1, 'Ada Lovelace');
$otherCustomer = Harness::client(2, 'Grace Hopper');
$admin = Harness::admin(1, 'admin_super', 'Root Admin');
$viewer = Harness::admin(9, 'admin_viewer', 'Read Only');
$brokerRow = Harness::broker('Dana Broker', 'broker', 7);
$broker = Harness::brokerActor($brokerRow);

section('View helpers');

T::is('a client-area URL carries the module', 'index.php?m=domainbroker&action=dashboard', View::url('dashboard'));
T::is('an admin URL carries the action', 'addonmodules.php?module=domainbroker&action=requests', View::adminLink(['action' => 'requests']));
T::is('a workflow tone maps onto a bootstrap class', 'success', View::toneClass('success'));
T::is('an unknown tone falls back', 'default', View::toneClass('nonsense'));

$tracker = View::tracker(RequestStatus::NEGOTIATION);
T::is('the tracker has one step per pipeline stage', count(RequestStatus::PIPELINE), count($tracker));
$current = array_values(array_filter($tracker, function ($step) {
    return $step['state'] === 'current';
}));
T::is('and marks exactly one as current', 1, count($current));
T::is('at the right stage', RequestStatus::NEGOTIATION, $current[0]['key']);

$terminal = View::tracker(RequestStatus::CANCELLED);
T::is('a terminal outcome appends a stopped marker', 'stopped', $terminal[count($terminal) - 1]['state']);

$pagination = View::pagination(47, 2, 15, function ($n) {
    return '?p=' . $n;
});
T::is('pagination counts the pages', 4, $pagination['pages']);
T::is('and the window', '?p=1', $pagination['window'][0]['url']);
T::ok('and knows there is a next page', $pagination['has_next']);

T::contains('markup is escaped', '&lt;script&gt;', Html::e('<script>x</script>'));
T::contains('attributes are escaped', 'x&quot;y', Html::link('a', 'x"y'));

section('The customer portal renders');

$page = client_get($customer, ['action' => 'dashboard']);
T::is('the dashboard uses its template', 'dashboard', $page['templatefile']);
T::ok('and requires a login', $page['requirelogin']);
T::is('with no acquisitions yet', 0, $page['vars']['summary']['total']);
T::ok('and a CSRF token', strlen($page['vars']['csrf_token']) > 10);
T::is('the nav marks the current page', true, $page['vars']['nav'][0]['active']);

$page = client_get($customer, ['action' => 'new']);
T::is('the request form renders', 'request_new', $page['templatefile']);
T::ok('and offers the configured currencies', isset($page['vars']['currencies']['USD']));
T::contains('and explains the fee', 'brokerage fee', $page['vars']['fee_note']);

$page = client_get($customer, ['action' => 'help']);
T::is('the FAQ renders', 'help', $page['templatefile']);
T::ok('with questions', count($page['vars']['faqs']) >= 6);

$page = client_get($customer, ['action' => 'transactions']);
T::is('transactions render', 'transactions', $page['templatefile']);
T::is('with nothing yet', 0, count($page['vars']['transactions']));

$page = client_get(Actor::guest(), ['action' => 'dashboard']);
T::is('a signed-out visitor is asked to sign in', 'login_required', $page['templatefile']);

$page = client_get($admin, ['action' => 'dashboard']);
T::is('and so is an administrator browsing the client area', 'login_required', $page['templatefile']);
T::ok('who is pointed at the admin area instead', $page['vars']['is_staff']);

section('Creating a request through the portal');

flash_clear();
$result = client_post($customer, 'create', [
    'domain' => 'Portal-Example.com',
    'budget' => '4500.00',
    'currency' => 'USD',
    'budget_includes_fees' => '1',
    'message' => 'We want this for a product launch in the spring.',
    'anonymous' => '1',
]);
T::ok('a valid submission redirects to the new request', strpos((string) $result['redirect'], 'action=view') !== false);
$created = Db::first('requests', ['domain' => 'portal-example.com']);
T::ok('and the request exists', $created !== null);
T::is('filed against the authenticated client', 1, (int) $created['client_id']);
T::is('with the budget in minor units', 450000, (int) $created['budget_minor']);
T::is('and the client-area source recorded', 'clientarea', $created['source']);
T::contains('with a success message', 'received', $result['flash']);

flash_clear();
$result = client_post($customer, 'create', [
    'domain' => 'not a domain',
    'budget' => '4500.00',
    'currency' => 'USD',
]);
T::contains('an invalid domain reports the problem', 'domain', $result['flash']);
T::is('and nothing is created', 1, Db::count('requests', ['client_id' => 1]));

flash_clear();
$result = client_post($customer, 'create', [
    'domain' => 'csrf-example.com',
    'budget' => '1000.00',
    'currency' => 'USD',
], false);
T::contains('a submission without a CSRF token is refused', 'session has expired', $result['flash']);
T::is('and writes nothing', 0, Db::count('requests', ['domain' => 'csrf-example.com']));

flash_clear();
$result = client_post($customer, 'create', [
    'domain' => 'portal-example.com',
    'budget' => '9000.00',
    'currency' => 'USD',
]);
T::contains('a duplicate live request is refused', 'already', strtolower($result['flash']));

section('A customer may only see their own acquisitions');

$requestId = (int) $created['id'];

$page = client_get($customer, ['action' => 'view', 'id' => $requestId]);
T::is('the owner sees the detail page', 'request_detail', $page['templatefile']);
T::is('with their reference', $created['reference'], $page['vars']['request']['reference']);
T::ok('a tracker', count($page['vars']['tracker']) > 0);
T::ok('and a capability map', isset($page['vars']['can']['pay']));
T::is('no broker yet', null, $page['vars']['broker']);

$page = client_get($otherCustomer, ['action' => 'view', 'id' => $requestId]);
T::isnt('another client cannot open it', 'request_detail', $page['templatefile']);

flash_clear();
$result = client_post($otherCustomer, 'cancel', ['id' => $requestId, 'reason' => 'Not mine']);
T::isnt('nor cancel it', '', $result['flash']);
$still = Db::first('requests', ['id' => $requestId]);
T::isnt('and the request is untouched', RequestStatus::CANCELLED, $still['status']);

section('The admin console renders');

$html = admin_get($admin, ['action' => 'dashboard']);
T::contains('the dashboard renders', 'Domain Broker', $html);
T::contains('with the request tabs', 'action=requests', $html);
T::contains('and the unassigned queue', 'Unassigned queue', $html);

$html = admin_get($admin, ['action' => 'requests']);
T::contains('the request list renders', $created['reference'], $html);
T::contains('with a filter form', 'Any status', $html);

$html = admin_get($admin, ['action' => 'request', 'id' => $requestId]);
T::contains('the request detail renders', 'portal-example.com', $html);
T::contains('with the audit chain verified', 'Hash chain verified', $html);
T::contains('and an assignment panel', 'Assign a broker', $html);
T::contains('and an override panel for a super admin', 'Override status', $html);

$html = admin_get($viewer, ['action' => 'request', 'id' => $requestId]);
T::ok('a read-only admin sees the request', strpos($html, 'portal-example.com') !== false);
T::ok('but no override panel', strpos($html, 'Override status') === false);
T::ok('and no refund form', strpos($html, 'Issue refund') === false);

$html = admin_get($viewer, ['action' => 'settings']);
T::ok('and cannot open settings', strpos($html, 'Administrative roles') === false);

$html = admin_get($admin, ['action' => 'settings']);
T::contains('a super admin can', 'Administrative roles', $html);
T::contains('and is told secrets come from the environment', 'environment variables', $html);

$html = admin_get($admin, ['action' => 'audit']);
T::contains('the audit log renders', 'hash chained', $html);
T::contains('with the creation entry', 'Acquisition request submitted', $html);

$html = admin_get($admin, ['action' => 'reports']);
T::contains('reports render', 'Broker performance', $html);
T::contains('with an export control', 'Requests CSV', $html);

$html = admin_get($admin, ['action' => 'fees']);
T::contains('the fee rules render', 'standard', $html);
T::contains('with a calculator', 'Fee calculator', $html);

$html = admin_get($admin, ['action' => 'brokers']);
T::contains('the broker roster renders', 'Dana Broker', $html);

section('Administrative writes');

flash_clear();
$result = admin_post($admin, ['action' => 'request', 'id' => $requestId, 'do' => 'assign'], [
    'broker_id' => (int) $brokerRow['id'],
    'reason' => 'Specialist in this extension',
]);
T::contains('assignment redirects back to the request', 'action=request', (string) $result['redirect']);
$assigned = Db::first('requests', ['id' => $requestId]);
T::is('and the broker is recorded', (int) $brokerRow['id'], (int) $assigned['assigned_broker_id']);
T::contains('with a confirmation', 'Broker assigned', $result['html']);

flash_clear();
admin_post($admin, ['action' => 'request', 'id' => $requestId, 'do' => 'assign'], [
    'broker_id' => (int) $brokerRow['id'],
], true);
flash_clear();

$result = admin_post($viewer, ['action' => 'request', 'id' => $requestId, 'do' => 'override'], [
    'status' => RequestStatus::COMPLETED,
    'reason' => 'Because I want to',
]);
T::contains('a read-only admin cannot override a status', 'permission', $result['html']);
$afterOverride = Db::first('requests', ['id' => $requestId]);
T::isnt('and the status is unchanged', RequestStatus::COMPLETED, $afterOverride['status']);

flash_clear();
$result = admin_post($admin, ['action' => 'request', 'id' => $requestId, 'do' => 'note'], [
    'body' => 'Owner is represented by an agent; expect a slow reply.',
]);
T::is('an internal note is stored', 1, Db::count('messages', ['request_id' => $requestId, 'is_internal' => 1]));

flash_clear();
$result = admin_post($admin, ['action' => 'request', 'id' => $requestId, 'do' => 'assign'], [
    'broker_id' => (int) $brokerRow['id'],
], false);
T::contains('an admin write without a CSRF token is refused', 'Security token', $result['html']);

section('The broker desk');

$html = desk_get($broker, ['action' => 'desk']);
T::contains('the desk renders', 'Broker desk', $html);
T::contains('with the queue bucket', 'New &amp; unassigned', $html);
T::contains('and the assigned acquisition', $created['reference'], $html);

$html = desk_get($broker, ['action' => 'request', 'id' => $requestId]);
T::contains('the broker opens their request', 'portal-example.com', $html);
T::contains('and can contact the owner', 'Contact the owner', $html);
T::contains('and can offer', 'Submit an offer to the owner', $html);
T::contains('and sees the internal thread', 'Internal notes', $html);
T::contains('including the administrator\'s note', 'represented by an agent', $html);

flash_clear();
$result = desk_post($broker, ['action' => 'request', 'id' => $requestId, 'do' => 'contact-owner'], [
    'channel' => 'email',
    'summary' => 'Introduced ourselves and asked whether the owner would consider an offer.',
]);
T::is('recording the approach advances the request', RequestStatus::OWNER_CONTACTED,
    Db::first('requests', ['id' => $requestId])['status']);
T::is('and creates a negotiation round', 1, Db::count('negotiations', ['request_id' => $requestId]));

flash_clear();
$result = desk_post($broker, ['action' => 'request', 'id' => $requestId, 'do' => 'offer'], [
    'direction' => 'to_owner',
    'amount' => '3000.00',
    'message' => 'Our client can move quickly.',
    'internal_note' => 'Ceiling is 4500; opening low.',
]);
T::is('the broker files an offer', 1, Db::count('offers', ['request_id' => $requestId, 'direction' => 'to_owner']));

flash_clear();
$result = desk_post($broker, ['action' => 'request', 'id' => $requestId, 'do' => 'offer'], [
    'direction' => 'to_customer',
    'amount' => '4200.00',
    'message' => 'The owner will accept 4,200.',
    'requires_customer_approval' => '1',
]);
$ownerOffer = Db::first('offers', ['request_id' => $requestId, 'direction' => 'to_customer']);
T::ok('the owner offer is recorded', $ownerOffer !== null);
T::is('and is waiting on the customer', 'pending', $ownerOffer['status']);

section('The customer sees the offer but never the private notes');

$page = client_get($customer, ['action' => 'view', 'id' => $requestId]);
T::ok('a pending offer is surfaced', $page['vars']['pending_offer'] !== null);
T::is('at the owner\'s price', 420000, $page['vars']['pending_offer']['amount']['minor']);
T::ok('and the customer may respond', $page['vars']['can']['respond_to_offer']);

$serialised = json_encode($page['vars']);
T::ok('the broker\'s internal note is absent', strpos($serialised, 'Ceiling is 4500') === false);
T::ok('and the administrator\'s internal note is absent', strpos($serialised, 'represented by an agent') === false);

foreach ($page['vars']['messages'] as $message) {
    T::ok('no customer-visible message is internal', empty($message['is_internal']));
}

section('The budget ceiling is enforced server-side');

// 4,200 plus the 10% fee is 4,620 — over the 4,500 ceiling the customer set,
// and their budget was declared as fee-inclusive.
flash_clear();
$result = client_post($customer, 'accept-offer', [
    'id' => $requestId,
    'offer_id' => (int) $ownerOffer['id'],
]);
T::contains('an over-budget offer cannot be accepted', 'exceeds your stated budget', $result['flash']);
T::is('and the request does not advance', RequestStatus::OFFER_RECEIVED,
    Db::first('requests', ['id' => $requestId])['status']);

flash_clear();
desk_post($broker, ['action' => 'request', 'id' => $requestId, 'do' => 'withdraw-offer'], [
    'offer_id' => (int) $ownerOffer['id'],
    'reason' => 'Renegotiated downwards to fit the client budget.',
]);
T::is('the broker withdraws it', 'withdrawn',
    Db::first('offers', ['id' => (int) $ownerOffer['id']])['status']);

flash_clear();
desk_post($broker, ['action' => 'request', 'id' => $requestId, 'do' => 'offer'], [
    'direction' => 'to_customer',
    'amount' => '4000.00',
    'message' => 'The owner will take 4,000.',
    'requires_customer_approval' => '1',
]);
$finalOffer = Db::first('offers', [
    'request_id' => $requestId,
    'direction' => 'to_customer',
    'status' => 'pending',
]);
T::ok('and files one inside the budget', $finalOffer !== null);
T::is('the original offer is still on file', 2,
    Db::count('offers', ['request_id' => $requestId, 'direction' => 'to_customer']));

section('Accepting, invoicing and paying through the portal');

flash_clear();
$result = client_post($customer, 'accept-offer', [
    'id' => $requestId,
    'offer_id' => (int) $finalOffer['id'],
]);
$accepted = Db::first('requests', ['id' => $requestId]);
T::is('accepting moves the request on', RequestStatus::OFFER_ACCEPTED, $accepted['status']);
T::ok('and an agreed total is recorded', (int) $accepted['total_minor'] > 400000);

flash_clear();
$result = client_post($customer, 'pay', ['id' => $requestId]);
$payment = Db::first('payments', ['request_id' => $requestId]);
T::ok('an invoice is raised', $payment !== null);
T::ok('and the customer is sent to the WHMCS invoice',
    strpos((string) $result['redirect'], 'viewinvoice.php?id=') === 0);
T::is('the request is now awaiting payment', RequestStatus::PAYMENT_PENDING,
    Db::first('requests', ['id' => $requestId])['status']);

// Paying is a double-submit risk: the same post must not raise a second invoice.
flash_clear();
client_post($customer, 'pay', ['id' => $requestId]);
T::is('a repeated pay click does not raise a second invoice', 1,
    Db::count('payments', ['request_id' => $requestId, 'type' => 'acquisition']));

section('Messaging and documents through the portal');

flash_clear();
$result = client_post($customer, 'send-message', [
    'id' => $requestId,
    'body' => 'Please confirm the owner will release the auth code promptly.',
]);
T::contains('the message redirects to the thread', '#messages', (string) $result['redirect']);
$thread = (new MessageService())->customerThread($customer, $requestId);
$bodies = array_column($thread, 'body');
T::ok('and the message is in the customer thread',
    in_array('Please confirm the owner will release the auth code promptly.', $bodies, true));

flash_clear();
$result = client_post($customer, 'upload-document', [
    'id' => $requestId,
    'category' => 'identity',
]);
T::isnt('an upload with no file is refused', '', $result['flash']);
T::is('and nothing is stored', 0, Db::count('documents', ['request_id' => $requestId]));

section('Dispute from the portal');

flash_clear();
$result = client_post($customer, 'open-dispute', [
    'id' => $requestId,
    'reason_code' => 'seller_unresponsive',
    'description' => 'The owner has not responded for three weeks and we are past our launch date.',
]);
$dispute = Db::first('disputes', ['request_id' => $requestId]);
T::ok('the dispute is opened', $dispute !== null);
T::is('and the request is frozen', RequestStatus::DISPUTED,
    Db::first('requests', ['id' => $requestId])['status']);

$html = admin_get($admin, ['action' => 'disputes']);
T::contains('it appears in the admin dispute list', $dispute['reference'], $html);

$html = admin_get($admin, ['action' => 'dispute', 'id' => (int) $dispute['id']]);
T::contains('and can be managed', 'Resolve', $html);

flash_clear();
$result = admin_post($viewer, ['action' => 'dispute', 'id' => (int) $dispute['id'], 'do' => 'reject'], [
    'reason' => 'No evidence provided',
]);
T::contains('a read-only admin cannot reject a dispute', 'permission', $result['html']);
T::is('and it stays open', 'open', Db::first('disputes', ['id' => (int) $dispute['id']])['status']);

section('Exports and settings');

flash_clear();
AdminPortal::$lastExport = null;
$result = admin_post($admin, ['action' => 'reports', 'do' => 'export'], ['dataset' => 'requests']);
T::ok('an export produces a CSV', AdminPortal::$lastExport !== null);
T::contains('with a filename', '.csv', AdminPortal::$lastExport['filename']);
T::contains('and the request reference', $created['reference'], AdminPortal::$lastExport['body']);

flash_clear();
$result = admin_post($viewer, ['action' => 'reports', 'do' => 'export'], ['dataset' => 'requests']);
T::contains('a read-only admin cannot export', 'permission', $result['html']);

flash_clear();
$result = admin_post($admin, ['action' => 'settings', 'do' => 'save'], [
    'setting_offer_validity_hours' => '72',
    'setting_encryption_key' => 'nice-try',
]);
Settings::flush();
T::is('an operational setting is saved', '72', (string) Settings::get('offer_validity_hours'));
T::contains('but a secret is refused', 'encryption_key', $result['html']);
T::is('and never written to the settings table', 0, Db::count('settings', ['setting_key' => 'encryption_key']));

section('Request listing and filtering');

Harness::acceptedRequest('filterable-one.com', 1, ['brokerRow' => $brokerRow]);
Harness::acceptedRequest('filterable-two.com', 1, ['brokerRow' => $brokerRow]);

$page = client_get($customer, ['action' => 'requests']);
T::ok('the list shows every request', $page['vars']['pagination']['total'] >= 3);

$page = client_get($customer, ['action' => 'requests', 'search' => 'filterable-one']);
T::is('a search narrows it', 1, count($page['vars']['requests']));
T::is('to the right domain', 'filterable-one.com', $page['vars']['requests'][0]['domain']);

$page = client_get($customer, ['action' => 'requests', 'status' => RequestStatus::OFFER_ACCEPTED]);
T::is('a status filter narrows it', 2, count($page['vars']['requests']));

$page = client_get($otherCustomer, ['action' => 'requests']);
T::is('another client sees none of them', 0, $page['vars']['pagination']['total']);

section('Transactions');

$page = client_get($customer, ['action' => 'transactions']);
T::ok('the paid-for acquisition appears', count($page['vars']['transactions']) >= 1);
$transaction = $page['vars']['transactions'][0];
T::ok('with a link back to the request', strpos($transaction['request_url'], 'action=view') !== false);
T::ok('and no escrow reference leaks', strpos(json_encode($transaction), 'escrow_reference') === false);

Harness::shutdown();
T::summary();
