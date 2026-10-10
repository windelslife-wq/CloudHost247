<?php
/**
 * Domain Broker — the REST API: routing, authentication, token scopes,
 * RBAC, validation, idempotency, rate limiting and error mapping.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\Router;
use DomainBroker\Core\Actor;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\Identity;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Services\ApiTokenService;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$router = new Router();
$tokens = new ApiTokenService();

$admin = Harness::admin(1, 'admin_super');
$viewer = Harness::admin(6, 'admin_viewer', 'Support Agent');

/**
 * Issue a call through the router. Token calls carry a bearer header; session
 * calls set the resolved identity the way the front controller would.
 */
function call($method, $path, array $body = [], array $options = [])
{
    global $router;

    $headers = isset($options['headers']) ? $options['headers'] : [];
    $query = isset($options['query']) ? $options['query'] : [];

    if (isset($options['token'])) {
        $headers['Authorization'] = 'Bearer ' . $options['token'];
        Identity::override(null);
    } else {
        Identity::override(isset($options['as']) ? $options['as'] : Actor::guest());
        // A browser call carries the session CSRF token; omit it only when the
        // test is deliberately probing the CSRF guard.
        if (empty($options['omit_csrf'])) {
            $headers['X-CSRF-Token'] = \DomainBroker\Core\Csrf::token();
        }
    }

    $request = new ApiRequest($method, $path, $query, $body, $headers, json_encode($body));
    if (isset($options['files'])) {
        $request->files = $options['files'];
    }
    $response = $router->dispatch($request);
    Identity::override(null);
    return $response;
}

function body($response)
{
    return is_array($response->body) ? $response->body : [];
}

function data($response)
{
    $b = body($response);
    return isset($b['data']) ? $b['data'] : null;
}

function errorCode($response)
{
    $b = body($response);
    return isset($b['error']['code']) ? $b['error']['code'] : null;
}

/* ------------------------------------------------------------ routing */

section('Routing');

$ping = call('GET', 'ping');
T::is('the service answers unauthenticated on ping', 200, $ping->status);
T::is('with an envelope', true, body($ping)['success']);
T::is('and its version', '1.0', data($ping)['version']);

T::is('a leading slash is tolerated', 200, call('GET', '/ping')->status);
T::is('a trailing slash is tolerated', 200, call('GET', 'ping/')->status);
T::is('an unknown path is a 404', 404, call('GET', 'does/not/exist')->status);
T::is('with a machine readable code', 'unknown_endpoint', errorCode(call('GET', 'does/not/exist')));
T::is('a wrong method is a 405', 405, call('DELETE', 'ping')->status);
T::is('with its own code', 'method_not_allowed', errorCode(call('DELETE', 'ping')));
T::is('OPTIONS is answered for preflight', 204, call('OPTIONS', 'requests')->status);

/* ------------------------------------------------------ authentication */

section('Authentication');

T::is('an anonymous read is rejected', 401, call('GET', 'requests')->status);
T::is('with an authentication code', 'authentication_required', errorCode(call('GET', 'requests')));
T::is('an anonymous write is rejected too', 401, call('POST', 'requests', ['domain' => 'x.com'])->status);
T::is('a bogus bearer token is rejected', 401, call('GET', 'requests', [], ['token' => 'dbk_not_a_real_token'])->status);

$customer = Harness::client(1, 'Alice Customer');
T::is('a signed-in client can list', 200, call('GET', 'requests', [], ['as' => $customer])->status);

$me = call('GET', 'me', [], ['as' => $customer]);
T::is('identity is reported from the server side', 'customer', data($me)['type']);
T::is('with the resolved role', 'customer', data($me)['role']);
T::ok('and the permission set', in_array('request.create', data($me)['permissions'], true));
T::ok('which excludes privileged entries', !in_array('payment.refund', data($me)['permissions'], true));

/* ------------------------------------------------------------- tokens */

section('API tokens');

$issued = $tokens->issue($admin, [
    'name' => 'Integration robot',
    'actor_type' => Actor::TYPE_CUSTOMER,
    'actor_id' => 1,
    'actor_label' => 'Alice Customer',
]);
$plain = $issued['token'];
T::ok('a token is issued with a recognisable prefix', strpos($plain, 'dbk_') === 0);
T::ok('it is long enough to resist guessing', strlen($plain) >= 40);
T::is('only the hash is stored', 0, Db::count('tokens', ['token_hash' => $plain]));
T::is('and the hash matches', 1, Db::count('tokens', ['token_hash' => hash('sha256', $plain)]));
T::ok('a hint is kept for the UI', substr($plain, -4) === $issued['record']['token_hint']);
T::ok('the issue is audited', Db::count('activity', ['action' => 'api.token.issued']) === 1);
$auditRow = Db::first('activity', ['action' => 'api.token.issued']);
T::ok('the audit entry never contains the token', strpos(json_encode($auditRow), $plain) === false);

$tokenMe = call('GET', 'me', [], ['token' => $plain]);
T::is('the token authenticates', 200, $tokenMe->status);
T::is('as the right principal', 1, data($tokenMe)['client_id']);
T::is('and records how', 'api_token', data($tokenMe)['auth_method']);
T::is('usage is counted', 1, (int) Db::first('tokens', ['id' => (int) $issued['record']['id']])['request_count']);

$tokens->revoke($admin, $issued['record']['id'], 'Rotated during the quarterly review.');
T::is('a revoked token stops working', 401, call('GET', 'me', [], ['token' => $plain])->status);
T::ok('the revocation is audited', Db::count('activity', ['action' => 'api.token.revoked']) === 1);

$expiring = $tokens->issue($admin, [
    'name' => 'Short lived', 'actor_type' => Actor::TYPE_CUSTOMER, 'actor_id' => 1,
    'expires_at' => '2001-01-01 00:00:00',
]);
T::is('an expired token is refused', 401, call('GET', 'me', [], ['token' => $expiring['token']])->status);

$scoped = $tokens->issue($admin, [
    'name' => 'Read only robot', 'actor_type' => Actor::TYPE_CUSTOMER, 'actor_id' => 1,
    'scopes' => ['requests.index', 'system.me'],
]);
T::is('a scoped token reaches its scope', 200, call('GET', 'requests', [], ['token' => $scoped['token']])->status);
$outOfScope = call('POST', 'requests', ['domain' => 'scope-test.com', 'budget' => '1000.00', 'currency' => 'USD'], [
    'token' => $scoped['token'],
]);
T::is('and nothing else', 403, $outOfScope->status);
T::contains('with an explanatory message', 'scope', body($outOfScope)['error']['message']);

T::throws('a read-only admin cannot mint tokens at all', \DomainBroker\Core\AuthorizationException::class, function () use ($tokens, $viewer) {
    $tokens->issue($viewer, [
        'name' => 'Privilege escalation', 'actor_type' => Actor::TYPE_ADMIN,
        'actor_id' => 6, 'actor_role' => 'admin_super',
    ]);
});
T::throws('and an unknown role is refused', \DomainBroker\Core\ValidationException::class, function () use ($tokens, $admin) {
    $tokens->issue($admin, [
        'name' => 'Nonsense role', 'actor_type' => Actor::TYPE_ADMIN,
        'actor_id' => 2, 'actor_role' => 'admin_wizard',
    ]);
});

/* ------------------------------------------------------------ creation */

section('Creating a request');

$created = call('POST', 'requests', [
    'domain' => 'api-created.com',
    'budget' => '12000.00',
    'currency' => 'USD',
    'message' => 'Please approach the registrant discreetly.',
    'budget_includes_fees' => true,
], ['as' => $customer]);
T::is('a valid submission is created', 201, $created->status);
T::ok('a reference is returned', strpos(data($created)['reference'], 'DB-') === 0);
T::is('the domain is normalised', 'api-created.com', data($created)['domain']);
T::is('a low risk request advances to review automatically', RequestStatus::UNDER_REVIEW, data($created)['status']);
T::is('and is recorded as having arrived through the API', 'api',
    Db::first('requests', ['id' => (int) data($created)['id']])['source']);
T::is('the budget is echoed in minor units', 1200000, data($created)['budget']['minor']);
T::ok('and formatted for display', data($created)['budget']['formatted'] !== '');
$requestId = data($created)['id'];

$invalid = call('POST', 'requests', ['domain' => 'not a domain', 'budget' => '100.00', 'currency' => 'USD'], ['as' => $customer]);
T::is('an invalid domain is a 422', 422, $invalid->status);
T::is('with a validation code', 'validation_failed', errorCode($invalid));
T::ok('and per-field detail', isset(body($invalid)['error']['details']['domain']));

$noBudget = call('POST', 'requests', ['domain' => 'no-budget.com', 'currency' => 'USD'], ['as' => $customer]);
T::is('a missing budget is a 422', 422, $noBudget->status);

$badCurrency = call('POST', 'requests', ['domain' => 'bad-ccy.com', 'budget' => '100.00', 'currency' => 'ZZZ'], ['as' => $customer]);
T::is('an unsupported currency is a 422', 422, $badCurrency->status);

$duplicate = call('POST', 'requests', [
    'domain' => 'api-created.com', 'budget' => '19000.00', 'currency' => 'USD',
], ['as' => $customer]);
T::is('a second live request for the same domain conflicts', 409, $duplicate->status);
T::is('with a conflict code', 'conflict', errorCode($duplicate));

$spoof = call('POST', 'requests', [
    'domain' => 'spoofed-owner.com', 'budget' => '1000.00', 'currency' => 'USD', 'client_id' => 99,
], ['as' => $customer]);
T::is('a client cannot file on behalf of someone else', 201, $spoof->status);
T::is('the request is filed against the authenticated client', 1,
    (int) Db::first('requests', ['id' => (int) data($spoof)['id']])['client_id']);

/* -------------------------------------------------------- idempotency */

section('Idempotency');

$key = 'client-generated-key-001';
$first = call('POST', 'requests', [
    'domain' => 'idempotent.com', 'budget' => '5000.00', 'currency' => 'USD',
], ['as' => $customer, 'headers' => ['Idempotency-Key' => $key]]);
T::is('the first call creates', 201, $first->status);

$replay = call('POST', 'requests', [
    'domain' => 'idempotent.com', 'budget' => '5000.00', 'currency' => 'USD',
], ['as' => $customer, 'headers' => ['Idempotency-Key' => $key]]);
T::is('the replay returns the same status', 201, $replay->status);
T::is('and the same record', data($first)['id'], data($replay)['id']);
T::is('and is marked as a replay', 'true', $replay->headers['Idempotent-Replay']);
T::is('only one row exists', 1, Db::count('requests', ['domain' => 'idempotent.com']));

$clash = call('POST', 'requests', [
    'domain' => 'something-else.com', 'budget' => '5000.00', 'currency' => 'USD',
], ['as' => $customer, 'headers' => ['Idempotency-Key' => $key]]);
T::is('reusing a key with a different payload conflicts', 409, $clash->status);
T::is('and nothing was created', 0, Db::count('requests', ['domain' => 'something-else.com']));

/* --------------------------------------------------------- authorisation */

section('Authorisation through the API');

$intruder = Harness::client(2, 'Mallory');
T::is('another client cannot read the request', 404, call('GET', 'requests/' . $requestId, [], ['as' => $intruder])->status);
T::is('nor cancel it', 404, call('POST', 'requests/' . $requestId . '/cancel', ['reason' => 'mine now'], ['as' => $intruder])->status);
T::is('nor read its timeline', 404, call('GET', 'requests/' . $requestId . '/timeline', [], ['as' => $intruder])->status);
T::is('nor its audit trail', 403, call('GET', 'requests/' . $requestId . '/audit', [], ['as' => $intruder])->status);

T::is('a customer cannot assign brokers', 403, call('POST', 'requests/' . $requestId . '/assign', ['broker_id' => 1], ['as' => $customer])->status);
T::is('nor override a status', 403, call('POST', 'requests/' . $requestId . '/status', ['status' => 'completed', 'reason' => 'done'], ['as' => $customer])->status);
T::is('nor manage fees', 403, call('POST', 'fees', ['code' => 'x', 'name' => 'X', 'calculation' => 'fixed'], ['as' => $customer])->status);
T::is('nor read the reports', 403, call('GET', 'reports/overview', [], ['as' => $customer])->status);
T::is('nor list brokers', 403, call('GET', 'brokers', [], ['as' => $customer])->status);

T::is('a read-only admin cannot approve', 403, call('POST', 'requests/' . $requestId . '/approve', [], ['as' => $viewer])->status);
T::is('nor export', 403, call('GET', 'reports/export', [], ['as' => $viewer, 'query' => ['dataset' => 'requests']])->status);
T::is('but can read the overview', 200, call('GET', 'reports/overview', [], ['as' => $viewer])->status);

/* -------------------------------------------------- the full lifecycle */

section('A full acquisition over the API');

$brokerRow = Harness::broker('Grace Hopper', 'broker', 11);
$broker = Harness::brokerActor($brokerRow);

$assigned = call('POST', 'requests/' . $requestId . '/assign', [
    'broker_id' => $brokerRow['id'], 'reason' => 'Specialist in this TLD.',
], ['as' => $admin]);
T::is('an admin assigns a broker', 200, $assigned->status);
T::is('and the request reflects it', RequestStatus::BROKER_ASSIGNED, data($assigned)['status']);

$contact = call('POST', 'requests/' . $requestId . '/owner-contact', [
    'summary' => 'Emailed the registrant through the registrar relay.',
    'channel' => 'email',
], ['as' => $broker]);
T::is('the broker records the approach', 201, $contact->status);

$offer = call('POST', 'requests/' . $requestId . '/offers', [
    'amount' => '9500.00',
    'direction' => 'to_customer',
    'message' => 'The registrant will take this figure.',
    'internal_note' => 'They actually hinted at 9000 — hold this line.',
], ['as' => $broker]);
T::is('the broker files an offer', 201, $offer->status);
$offerId = data($offer)['id'];
T::ok('the broker sees their own internal note', isset(data($offer)['internal_note']));

$customerOffers = call('GET', 'requests/' . $requestId . '/offers', [], ['as' => $customer]);
T::is('the customer can see the offer', 200, $customerOffers->status);
T::is('exactly one of them', 1, count(data($customerOffers)));
T::is('without the internal note field', false, array_key_exists('internal_note', data($customerOffers)[0]));
T::ok('and with no trace of its text', strpos(json_encode(data($customerOffers)), 'hold this line') === false);

$detail = call('GET', 'requests/' . $requestId, [], ['as' => $customer]);
T::is('the detail view loads', 200, $detail->status);
T::ok('with the broker profile', !empty(data($detail)['broker']['name']));
T::ok('a timeline', count(data($detail)['timeline']) > 0);
T::ok('and an offer list', count(data($detail)['offers']) === 1);
T::ok('the detail view leaks no internal note', strpos(json_encode(data($detail)), 'hold this line') === false);
T::is('and no risk score for a customer', false, array_key_exists('risk_score', data($detail)));

$counter = call('POST', 'offers/' . $offerId . '/counter', [
    'amount' => '8800.00', 'message' => 'I can stretch to 8,800.',
], ['as' => $customer]);
T::is('the customer counters', 201, $counter->status);
T::is('the counter is linked to its parent', $offerId, data($counter)['parent_offer_id']);
T::is('and marked as a counteroffer', true, data($counter)['is_counteroffer']);
T::is('the original offer is preserved', 'countered',
    Db::first('offers', ['id' => (int) $offerId])['status']);

$final = call('POST', 'requests/' . $requestId . '/offers', [
    'amount' => '9000.00', 'direction' => 'to_customer', 'message' => 'They will meet you at 9,000.',
], ['as' => $broker]);
$finalId = data($final)['id'];

T::is('a stranger cannot accept the offer', 404,
    call('POST', 'offers/' . $finalId . '/accept', [], ['as' => $intruder])->status);
T::is('nor can the broker accept on the client\'s behalf', 403,
    call('POST', 'offers/' . $finalId . '/accept', [], ['as' => $broker])->status);

$accepted = call('POST', 'offers/' . $finalId . '/accept', [], ['as' => $customer]);
T::is('the customer accepts', 200, $accepted->status);
T::is('the offer is accepted', 'accepted', data($accepted)['status']);
T::is('the request moves to offer accepted', RequestStatus::OFFER_ACCEPTED,
    Db::first('requests', ['id' => (int) $requestId])['status']);

$doubleAccept = call('POST', 'offers/' . $finalId . '/accept', [], ['as' => $customer]);
T::ok('accepting twice is absorbed or refused, never duplicated', in_array($doubleAccept->status, [200, 409], true));

$invoice = call('POST', 'requests/' . $requestId . '/invoice', [], ['as' => $customer]);
T::is('the invoice is raised', 201, $invoice->status);
T::is('the payment is pending', PaymentStatus::PENDING, data($invoice)['status']);
T::ok('a WHMCS invoice is linked', data($invoice)['invoice_id'] > 0);
$paymentId = data($invoice)['id'];

$gateway->payInvoice((int) data($invoice)['invoice_id']);
$synced = call('POST', 'payments/' . $paymentId . '/sync', [], ['as' => $customer]);
T::is('settlement is picked up from billing', PaymentStatus::FUNDS_SECURED, data($synced)['status']);
T::is('but the request is not complete', RequestStatus::PAYMENT_SECURED,
    Db::first('requests', ['id' => (int) $requestId])['status']);
T::is('the escrow reference never ships', false, array_key_exists('escrow_reference_enc', data($synced)));

T::is('a customer cannot refund themselves', 403,
    call('POST', 'payments/' . $paymentId . '/refund', ['reason' => 'I want my money back'], ['as' => $customer])->status);

$transfer = call('POST', 'requests/' . $requestId . '/transfer', [
    'losing_registrar' => 'Tucows', 'gaining_registrar' => 'CloudHost247 Registrar',
], ['as' => $broker]);
T::is('the broker opens the transfer', 201, $transfer->status);
$transferId = data($transfer)['id'];

$authCode = call('POST', 'transfers/' . $transferId . '/auth-code', [
    'auth_code' => 'EPP-API-Secret-99',
], ['as' => $broker]);
T::is('the auth code is accepted', 200, $authCode->status);
T::is('the response never echoes it', false, strpos(json_encode(body($authCode)), 'EPP-API-Secret') !== false);
T::ok('only a masked hint comes back', substr(data($authCode)['auth_code_hint'], -2) === '99');

call('POST', 'transfers/' . $transferId . '/status', ['status' => 'initiated'], ['as' => $broker]);
call('POST', 'transfers/' . $transferId . '/status', ['status' => 'pending'], ['as' => $broker]);
// The registry check is off in tests, so completion is an attestation: a broker
// is refused, and finance (here the super admin) attests with the reference.
$brokerAttempt = call('POST', 'transfers/' . $transferId . '/complete', [
    'evidence' => 'Registry confirmed the transfer on 2026-10-06.',
], ['as' => $broker]);
T::is('a broker cannot attest the transfer complete', 403, $brokerAttempt->status);
$done = call('POST', 'transfers/' . $transferId . '/complete', [
    'evidence' => 'Registry confirmed the transfer on 2026-10-06.',
    'registrar_reference' => 'TUCOWS-API-1',
], ['as' => $admin]);
T::is('the transfer completes', 200, $done->status);
T::is('the request is in verification, not completed', RequestStatus::TRANSFER_VERIFICATION,
    Db::first('requests', ['id' => (int) $requestId])['status']);

$premature = call('POST', 'requests/' . $requestId . '/complete', ['note' => 'all good'], ['as' => $broker]);
T::is('completion is blocked by verification', 409, $premature->status);
T::is('with the transition code', 'invalid_transition', errorCode($premature));

foreach (['registrar', 'ownership', 'transfer_authorization'] as $type) {
    call('POST', 'requests/' . $requestId . '/verification', [
        'type' => $type, 'method' => 'Checked against the registry record and the signed agreement.',
    ], ['as' => $broker]);
    $item = Db::first('verifications', ['request_id' => (int) $requestId, 'type' => $type]);
    call('POST', 'verifications/' . $item['id'] . '/approve', ['decision' => 'approve'], ['as' => $admin]);
}
$completed = call('POST', 'requests/' . $requestId . '/complete', ['note' => 'Delivered.'], ['as' => $broker]);
T::is('now it completes', 200, $completed->status);
T::is('the acquisition is done', RequestStatus::COMPLETED, data($completed)['status']);

/* --------------------------------------------------------- messaging */

section('Messaging and documents over the API');

$sent = call('POST', 'requests/' . $requestId . '/messages', [
    'body' => 'Thank you — when will the domain appear in my account?',
], ['as' => $customer]);
T::is('the customer posts a message', 201, $sent->status);
T::is('into the shared thread', 'customer', data($sent)['thread']);

$sneaky = call('POST', 'requests/' . $requestId . '/messages', [
    'body' => 'Let me in', 'thread' => 'internal',
], ['as' => $customer]);
T::is('a customer cannot post internally', 403, $sneaky->status);

call('POST', 'requests/' . $requestId . '/messages', [
    'body' => 'Client is asking about provisioning — do not quote the 9000 floor.',
    'thread' => 'internal',
], ['as' => $broker]);

$customerThreads = call('GET', 'requests/' . $requestId . '/messages', [], ['as' => $customer]);
T::is('the customer sees only their thread', ['customer'], array_keys(data($customerThreads)));
T::ok('and no internal text', strpos(json_encode(data($customerThreads)), 'do not quote') === false);

$brokerThreads = call('GET', 'requests/' . $requestId . '/messages', [], ['as' => $broker]);
T::ok('the broker sees more threads', count(array_keys(data($brokerThreads))) > 1);

$read = call('POST', 'requests/' . $requestId . '/messages/read', [], ['as' => $customer]);
T::is('messages can be marked read', 200, $read->status);

$noFile = call('POST', 'requests/' . $requestId . '/documents', [], ['as' => $customer]);
T::is('an upload with no file is rejected', 422, $noFile->status);
T::is('with a file code', 'file_rejected', errorCode($noFile));

$tmp = sys_get_temp_dir() . '/dbk-api-upload.pdf';
file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
$upload = call('POST', 'requests/' . $requestId . '/documents', ['category' => 'payment_document'], [
    'as' => $customer,
    'files' => ['file' => [
        'name' => 'receipt.pdf', 'tmp_name' => $tmp,
        'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK,
    ]],
]);
T::is('a valid upload is accepted', 201, $upload->status);
T::is('the storage path is never returned', false, array_key_exists('storage_path', data($upload)));
T::is('nor the stored filename', false, array_key_exists('stored_name', data($upload)));

$evil = sys_get_temp_dir() . '/dbk-api-upload.php';
file_put_contents($evil, "<?php echo 'pwned';");
$rejected = call('POST', 'requests/' . $requestId . '/documents', [], [
    'as' => $customer,
    'files' => ['file' => [
        'name' => 'shell.php', 'tmp_name' => $evil,
        'size' => filesize($evil), 'error' => UPLOAD_ERR_OK,
    ]],
]);
T::is('a script upload is refused', 422, $rejected->status);

/* ---------------------------------------------------------- reporting */

section('Reporting and export over the API');

$overview = call('GET', 'reports/overview', [], ['as' => $admin]);
T::is('the overview loads', 200, $overview->status);
T::ok('with request counters', data($overview)['requests']['total'] > 0);

$export = call('GET', 'reports/export', [], ['as' => $admin, 'query' => ['dataset' => 'requests']]);
T::is('the export downloads', 200, $export->status);
T::is('as a raw body', true, $export->raw);
T::contains('with a CSV content type', 'text/csv', $export->headers['Content-Type']);
T::contains('and an attachment disposition', 'attachment;', $export->headers['Content-Disposition']);
T::contains('the CSV has a header row', 'Reference', (string) $export->body);

$quote = call('GET', 'fees/quote', [], ['as' => $customer, 'query' => [
    'amount' => '10000.00', 'currency' => 'USD', 'domain' => 'quote-me.com',
]]);
T::is('a customer can price an acquisition', 200, $quote->status);
T::is('the fee is the configured 10%', 100000, data($quote)['fee_minor']);
T::is('and the internal rule id is withheld', false, array_key_exists('rule_id', data($quote)));
$adminQuote = call('GET', 'fees/quote', [], ['as' => $admin, 'query' => [
    'amount' => '10000.00', 'currency' => 'USD',
]]);
T::ok('an administrator does see it', array_key_exists('rule_id', data($adminQuote)));

$audit = call('GET', 'requests/' . $requestId . '/audit', [], ['as' => $admin]);
T::is('the audit trail is readable by staff', 200, $audit->status);
T::is('and its hash chain verifies', true, body($audit)['meta']['chain']['valid']);
T::ok('entries carry the actor, ip and action', isset(data($audit)[0]['ip_address'], data($audit)[0]['action']));

/* ------------------------------------------------------ rate limiting */

section('Rate limiting the API');

RateLimiter::resetConfiguration();
RateLimiter::configure(['api.read' => [3, 60]]);
$limited = null;
for ($i = 0; $i < 6; $i++) {
    $limited = call('GET', 'me', [], ['as' => $customer]);
    if ($limited->status === 429) {
        break;
    }
}
T::is('the bucket closes', 429, $limited->status);
T::is('with the standard code', 'rate_limited', errorCode($limited));
T::ok('and a Retry-After header', isset($limited->headers['Retry-After']));
Harness::relaxRateLimits();

/* ------------------------------------------------------- error shape */

section('Error envelope');

$notFound = call('GET', 'requests/999999', [], ['as' => $admin]);
T::is('a missing resource is a 404', 404, $notFound->status);
T::is('the envelope reports failure', false, body($notFound)['success']);
T::ok('with a message', !empty(body($notFound)['error']['message']));
T::is('and no data key', false, array_key_exists('data', body($notFound)));

$badStatus = call('POST', 'requests/' . $requestId . '/status', [
    'status' => 'teleported', 'reason' => 'because',
], ['as' => $admin]);
T::ok('an unknown status is refused', in_array($badStatus->status, [409, 422], true));
T::ok('no stack trace escapes', strpos(json_encode(body($badStatus)), '/app/lib') === false);

Harness::shutdown();
exit(T::summary());
