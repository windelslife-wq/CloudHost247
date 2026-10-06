<?php
/**
 * Domain Broker — security: the RBAC matrix, per-resource authorisation,
 * internal-note confinement, the contact vault, upload hardening, CSRF,
 * rate limiting, injection resistance and the tamper-evident audit trail.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Csrf;
use DomainBroker\Core\Db;
use DomainBroker\Core\FileRejectedException;
use DomainBroker\Core\Http;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\RateLimitException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\ContactVaultService;
use DomainBroker\Services\DocumentService;
use DomainBroker\Services\MessageService;
use DomainBroker\Services\RequestService;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$requests = new RequestService();
$messages = new MessageService();
$documents = new DocumentService();
$vault = new ContactVaultService();

$fixture = Harness::acceptedRequest('secure-me.com', 1);
$request = $fixture['request'];
$customer = $fixture['customer'];
$broker = $fixture['broker'];
$admin = $fixture['admin'];

$viewer = Harness::admin(6, 'admin_viewer', 'Support Agent');
$manager = Harness::admin(7, 'admin_manager', 'Operations Manager');
$finance = Harness::admin(5, 'admin_finance', 'Finance Officer');

$intruder = Harness::client(2, 'Unrelated Client');
$otherBrokerRow = Harness::broker('Ada Lovelace', 'broker', 12);
$otherBroker = Harness::brokerActor($otherBrokerRow);

/* ----------------------------------------------------------- RBAC matrix */

section('The role matrix');

$matrix = [
    // role actor,            permission,                      expected
    [$customer,  Rbac::REQUEST_CREATE,            true],
    [$customer,  Rbac::OFFER_RESPOND,             true],
    [$customer,  Rbac::NOTE_INTERNAL_READ,        false],
    [$customer,  Rbac::OWNER_CONTACT_VIEW,        false],
    [$customer,  Rbac::REQUEST_VIEW_ALL,          false],
    [$customer,  Rbac::PAYMENT_REFUND,            false],
    [$customer,  Rbac::STATUS_OVERRIDE,           false],
    [$customer,  Rbac::TRANSFER_CREDENTIAL_VIEW,  false],
    [$broker,    Rbac::OFFER_CREATE,              true],
    [$broker,    Rbac::NOTE_INTERNAL_WRITE,       true],
    [$broker,    Rbac::OWNER_CONTACT_VIEW,        true],
    [$broker,    Rbac::VERIFICATION_APPROVE,      false],
    [$broker,    Rbac::PAYMENT_REFUND,            false],
    [$broker,    Rbac::PAYMENT_RELEASE,           false],
    [$broker,    Rbac::TRANSFER_CREDENTIAL_VIEW,  false],
    [$broker,    Rbac::STATUS_OVERRIDE,           false],
    [$broker,    Rbac::REQUEST_VIEW_ALL,          false],
    [$broker,    Rbac::FEE_MANAGE,                false],
    [$viewer,    Rbac::REQUEST_VIEW_ALL,          true],
    [$viewer,    Rbac::AUDIT_VIEW,                true],
    [$viewer,    Rbac::REQUEST_APPROVE,           false],
    [$viewer,    Rbac::BROKER_ASSIGN,             false],
    [$viewer,    Rbac::PAYMENT_REFUND,            false],
    [$viewer,    Rbac::NOTE_INTERNAL_READ,        false],
    [$manager,   Rbac::BROKER_ASSIGN,             true],
    [$manager,   Rbac::REQUEST_APPROVE,           true],
    [$manager,   Rbac::VERIFICATION_APPROVE,      true],
    [$manager,   Rbac::PAYMENT_REFUND,            false],
    [$manager,   Rbac::STATUS_OVERRIDE,           false],
    [$manager,   Rbac::SETTINGS_MANAGE,           false],
    [$manager,   Rbac::PII_VIEW,                  false],
    [$finance,   Rbac::PAYMENT_REFUND,            true],
    [$finance,   Rbac::PAYMENT_RELEASE,           true],
    [$finance,   Rbac::FEE_MANAGE,                true],
    [$finance,   Rbac::STATUS_OVERRIDE,           false],
    [$finance,   Rbac::BROKER_ASSIGN,             false],
    [$finance,   Rbac::VERIFICATION_APPROVE,      false],
    [$admin,     Rbac::STATUS_OVERRIDE,           true],
    [$admin,     Rbac::SETTINGS_MANAGE,           true],
    [$admin,     Rbac::PII_VIEW,                  true],
];
$matrixOk = true;
foreach ($matrix as $case) {
    list($who, $permission, $expected) = $case;
    if (Rbac::allows($who, $permission) !== $expected) {
        $matrixOk = false;
        T::ok('matrix: ' . $who->role . ' / ' . $permission . ' should be ' . ($expected ? 'granted' : 'denied'), false);
    }
}
T::ok('every one of the ' . count($matrix) . ' matrix expectations holds', $matrixOk);

T::is('the system actor holds no interactive permission', [], array_values(array_filter(
    [Rbac::REQUEST_CREATE, Rbac::PAYMENT_REFUND, Rbac::STATUS_OVERRIDE, Rbac::OFFER_CREATE],
    function ($p) {
        return Rbac::allows(Actor::system('cron'), $p);
    }
)));
T::is('a guest holds none either', [], array_values(array_filter(
    [Rbac::REQUEST_VIEW_OWN, Rbac::REQUEST_CREATE, Rbac::MESSAGE_SEND],
    function ($p) {
        return Rbac::allows(Actor::guest(), $p);
    }
)));
T::is('an unknown permission is never granted', false, Rbac::allows($admin, 'nuke.everything'));
T::is('an unknown role is never granted', false, Rbac::allows(Actor::admin(99, 'wizard', 'Made Up'), Rbac::REQUEST_VIEW_ALL));

/* --------------------------------------------------- per-resource access */

section('Per-resource authorisation');

T::throws('another client cannot open the request', NotFoundException::class, function () use ($requests, $intruder, $request) {
    $requests->findForActor($intruder, $request['id']);
});
T::throws('an unassigned broker cannot open it either', AuthorizationException::class, function () use ($requests, $otherBroker, $request) {
    $requests->findForActor($otherBroker, $request['id']);
});
T::nothrow('the owning client can', function () use ($requests, $customer, $request) {
    return $requests->findForActor($customer, $request['id']);
});
T::nothrow('the assigned broker can', function () use ($requests, $broker, $request) {
    return $requests->findForActor($broker, $request['id']);
});
T::nothrow('a read-only admin can', function () use ($requests, $viewer, $request) {
    return $requests->findForActor($viewer, $request['id']);
});

$intruderList = $requests->listForActor($intruder, []);
T::is('listings are scoped to the caller', 0, count(array_filter($intruderList, function ($r) use ($request) {
    return (int) $r['id'] === (int) $request['id'];
})));
T::throws('a guest cannot list anything', AuthorizationException::class, function () use ($requests) {
    $requests->listForActor(Actor::guest(), []);
});
$brokerList = $requests->listForActor($otherBroker, []);
T::is('a broker only lists their own assignments', 0, count(array_filter($brokerList, function ($r) use ($request) {
    return (int) $r['id'] === (int) $request['id'];
})));

T::throws('an unrelated client cannot cancel it', NotFoundException::class, function () use ($requests, $intruder, $request) {
    $requests->cancelByCustomer($intruder, $request['id'], 'Changed my mind');
});
T::throws('a guessed id does not leak existence', NotFoundException::class, function () use ($requests, $intruder) {
    $requests->findForActor($intruder, 999999);
});

/* ----------------------------------------------------- internal notes */

section('Internal notes never reach the customer');

$secret = 'INTERNAL: registrant will settle at 6500 — do not reveal to the client.';
$note = $messages->addInternalNote($broker, $request['id'], $secret);
T::is('the note is flagged internal', 1, (int) $note['is_internal']);

$messages->send($broker, $request['id'], ['body' => 'We are making progress with the registrant.']);
$messages->send($customer, $request['id'], ['body' => 'Thanks — keep me posted.']);

$customerThread = $messages->customerThread($customer, $request['id']);
T::is('the customer sees only the shared thread', 2, count($customerThread));
T::ok('and never the note', strpos(json_encode($customerThread), 'do not reveal') === false);
T::is('no internal rows leak in', 0, count(array_filter($customerThread, function ($m) {
    return (int) $m['is_internal'] === 1;
})));

T::throws('a customer cannot read the internal thread', AuthorizationException::class, function () use ($messages, $customer, $request) {
    $messages->internalThread($customer, $request['id']);
});
T::throws('nor write to it', AuthorizationException::class, function () use ($messages, $customer, $request) {
    $messages->send($customer, $request['id'], ['body' => 'let me in', 'thread' => MessageService::THREAD_INTERNAL]);
});
T::throws('nor to the owner thread', AuthorizationException::class, function () use ($messages, $customer, $request) {
    $messages->send($customer, $request['id'], ['body' => 'hello registrant', 'thread' => MessageService::THREAD_OWNER]);
});
T::throws('an unknown thread is rejected', ValidationException::class, function () use ($messages, $broker, $request) {
    $messages->send($broker, $request['id'], ['body' => 'hi', 'thread' => 'admin_only']);
});

$brokerThread = $messages->internalThread($broker, $request['id']);
T::is('the broker sees the note', 1, count($brokerThread));
T::ok('a read-only admin cannot read internal notes', !Rbac::allows($viewer, Rbac::NOTE_INTERNAL_READ));

$timeline = Audit::timeline($request['id'], Audit::VIS_CUSTOMER);
T::ok('the customer timeline carries no internal entries', !array_filter($timeline, function ($e) {
    return $e['visibility'] !== Audit::VIS_CUSTOMER;
}));
T::ok('and no note text', strpos(json_encode($timeline), 'do not reveal') === false);
T::ok('the note itself is audited internally', Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'note.added', 'visibility' => Audit::VIS_INTERNAL,
]) >= 1);

$summary = $requests->customerSummary($customer);
T::ok('the customer summary is a plain counter set', is_array($summary) && isset($summary['total']));
T::ok('and leaks no note text', strpos(json_encode($summary), 'do not reveal') === false);

/* ----------------------------------------------- anonymous negotiation */

section('The registrant contact vault');

$contact = $vault->store($broker, $request['id'], [
    'role' => ContactVaultService::ROLE_OWNER,
    'name' => 'Marguerite Devereaux',
    'organisation' => 'Devereaux Holdings SARL',
    'email' => 'marguerite@devereaux-holdings.test',
    'phone' => '+33 1 23 45 67 89',
    'address' => '14 Rue de Rivoli, Paris',
    'notes' => 'Prefers email, replies in the evening CET.',
]);
T::ok('a contact was stored', !empty($contact['id']));

$raw = Db::first('contacts', ['id' => (int) $contact['id']]);
foreach (['name_enc', 'email_enc', 'phone_enc', 'address_enc'] as $column) {
    T::ok($column . ' is ciphertext', !empty($raw[$column]) && strpos((string) $raw[$column], 'Devereaux') === false);
}
T::ok('the raw row contains no plaintext email', strpos(json_encode($raw), 'marguerite@') === false);
T::ok('nor a plaintext phone number', strpos(json_encode($raw), '23 45 67') === false);
T::ok('a blind index exists for lookup', !empty($raw['email_index']));
T::is('and it is a hash, not the address', false, strpos((string) $raw['email_index'], '@') !== false);
T::is('lookup by email finds the request', [(int) $request['id']],
    array_map('intval', $vault->requestIdsForEmail('marguerite@devereaux-holdings.test')));
T::is('a wrong address finds nothing', [], $vault->requestIdsForEmail('someone-else@example.test'));

$masked = $vault->summary($contact['id']);
T::is('the summary proves an email exists', true, $masked['has_email']);
T::is('without containing it', false, in_array('email', array_keys($masked), true));
T::ok('the summary leaks nothing', strpos(json_encode($masked), 'marguerite') === false);

T::throws('the customer cannot reveal the registrant', AuthorizationException::class, function () use ($vault, $customer, $contact) {
    $vault->reveal($customer, $contact['id'], 'curiosity');
});
T::throws('nor can a read-only admin', AuthorizationException::class, function () use ($vault, $viewer, $contact) {
    $vault->reveal($viewer, $contact['id'], 'curiosity');
});
T::throws('nor an unassigned broker', AuthorizationException::class, function () use ($vault, $otherBroker, $contact) {
    $vault->reveal($otherBroker, $contact['id'], 'curiosity');
});
$revealed = T::nothrow('the assigned broker can, with a reason', function () use ($vault, $broker, $contact) {
    return $vault->reveal($broker, $contact['id'], 'Sending the signed agreement.');
});
T::is('and gets the real address', 'marguerite@devereaux-holdings.test', $revealed['email']);
T::is('every reveal is audited', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'contact.revealed',
]));
$revealLog = Db::first('activity', ['request_id' => (int) $request['id'], 'action' => 'contact.revealed']);
T::is('as an internal event', Audit::VIS_INTERNAL, $revealLog['visibility']);
T::ok('the audit entry does not repeat the contact details', strpos(json_encode($revealLog), 'marguerite') === false);

$wrongContext = Crypto::tryDecrypt($raw['email_enc'], 'transfer.auth_code');
T::is('ciphertext bound to one context will not open in another', null, $wrongContext);

$vault->forget($admin, $contact['id'], 'Erasure request under GDPR article 17.');
T::is('forgetting soft-deletes the row', 0, Db::count('contacts', ['id' => (int) $contact['id'], 'deleted_at' => null]));
T::is('the history row still exists', 1, Db::count('contacts', ['id' => (int) $contact['id']]));
$forgotten = Db::first('contacts', ['id' => (int) $contact['id']]);
T::is('and the ciphertext has been destroyed', null, $forgotten['email_enc']);

/* ------------------------------------------------------------- uploads */

section('Upload hardening');

$tmp = sys_get_temp_dir() . '/domainbroker-upload-tests';
@mkdir($tmp, 0700, true);
$make = function ($name, $bytes) use ($tmp) {
    $path = $tmp . '/' . bin2hex(random_bytes(6)) . '-' . $name;
    file_put_contents($path, $bytes);
    return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
};
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

T::throws('a PHP script is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('shell.php', "<?php system(\$_GET['c']); ?>"));
});
T::throws('a double extension is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('invoice.php.pdf', 'harmless looking'));
});
T::throws('an .htaccess is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('.htaccess', 'AddType application/x-httpd-php .pdf'));
});
T::throws('an executable is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('payload.exe', 'MZ binary'));
});
T::throws('an unknown extension is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('notes.xyz', 'whatever'));
});
T::throws('a file with no extension is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('README', 'whatever'));
});
T::throws('an empty file is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('empty.pdf', ''));
});
T::throws('PHP smuggled inside a .txt is rejected', FileRejectedException::class, function () use ($documents, $make) {
    $documents->validateUpload($make('readme.txt', "hello\n<?php echo 'pwned'; ?>"));
});
T::throws('a failed upload is rejected', FileRejectedException::class, function () use ($documents, $tmp) {
    $documents->validateUpload(['name' => 'x.pdf', 'tmp_name' => $tmp . '/nope', 'size' => 10, 'error' => UPLOAD_ERR_PARTIAL]);
});

Settings::overrideMany(['document_max_bytes' => 65536]);
T::throws('an oversized file is rejected', FileRejectedException::class, function () use ($documents, $make, $pdf) {
    $documents->validateUpload($make('big.pdf', str_pad($pdf, 200000, ' ')));
});
Settings::clearOverrides();
T::nothrow('and the same file passes at the default limit', function () use ($documents, $make, $pdf) {
    return $documents->validateUpload($make('big.pdf', str_pad($pdf, 200000, ' ')));
});

$meta = T::nothrow('a genuine PDF passes validation', function () use ($documents, $make, $pdf) {
    return $documents->validateUpload($make('agreement.pdf', $pdf));
});
T::is('the extension is normalised', 'pdf', $meta['extension']);
T::is('the content hash is recorded', hash('sha256', $pdf), $meta['sha256']);

$doc = T::nothrow('the customer uploads a document', function () use ($documents, $customer, $request, $make, $pdf) {
    return $documents->upload($customer, $request['id'], $make('identity.pdf', $pdf), [
        'category' => DocumentService::CATEGORY_IDENTITY,
        'visibility' => DocumentService::VIS_INTERNAL, // attempted privilege escalation
        'description' => 'Passport scan',
    ]);
});
T::is('a customer upload is forced to the shared scope', DocumentService::VIS_CUSTOMER, $doc['visibility']);
T::ok('the stored name is not the original', $doc['stored_name'] !== 'identity.pdf');
T::ok('the stored name is unguessable', strlen($doc['stored_name']) > 24);
T::ok('and is neutralised with a .bin suffix', substr($doc['stored_name'], -4) === '.bin');
T::ok('the path is relative to private storage', strpos($doc['storage_path'], '..') === false);
T::ok('and is not under a web root', strpos($doc['storage_path'], 'public') === false);

$internalDoc = $documents->upload($broker, $request['id'], $make('broker-notes.pdf', $pdf), [
    'category' => DocumentService::CATEGORY_CORRESPONDENCE,
    'visibility' => DocumentService::VIS_INTERNAL,
]);
$customerDocs = $documents->listFor($customer, $request['id']);
T::is('the customer sees only their scope', 1, count($customerDocs));
T::is('and not the internal document', 0, count(array_filter($customerDocs, function ($d) use ($internalDoc) {
    return (int) $d['id'] === (int) $internalDoc['id'];
})));
T::throws('the customer cannot download it by id', NotFoundException::class, function () use ($documents, $customer, $internalDoc) {
    $documents->openForDownload($customer, $internalDoc['id']);
});
T::throws('an unrelated client cannot download anything', NotFoundException::class, function () use ($documents, $intruder, $doc) {
    $documents->openForDownload($intruder, $doc['id']);
});
T::nothrow('the owner can download their own', function () use ($documents, $customer, $doc) {
    return $documents->openForDownload($customer, $doc['id']);
});

$documents->softDelete($admin, $doc['id'], 'Superseded by a clearer scan.');
T::is('deletion is soft', 1, Db::count('documents', ['id' => (int) $doc['id']]));
T::ok('the row is marked deleted', !empty(Db::first('documents', ['id' => (int) $doc['id']])['deleted_at']));
T::is('and it disappears from listings', 0, count(array_filter($documents->listFor($customer, $request['id']), function ($d) use ($doc) {
    return (int) $d['id'] === (int) $doc['id'];
})));

/* --------------------------------------------------------------- CSRF */

section('CSRF');

$_SESSION = [];
$token = Csrf::token();
T::ok('a token is issued', is_string($token) && strlen($token) >= 32);
T::is('it is stable within the session', $token, Csrf::token());
T::is('a valid token passes', true, Csrf::matches($token));
T::is('a forged token fails', false, Csrf::matches('not-the-token'));
T::is('an empty token fails', false, Csrf::matches(''));
T::is('a null token with no post data fails', false, Csrf::matches(null));
T::throws('and verify() refuses loudly', AuthorizationException::class, function () {
    Csrf::verify('wrong');
});
$field = Csrf::field();
T::contains('the hidden field is renderable', 'type="hidden"', $field);
T::ok('the field carries the token', strpos($field, $token) !== false);
$_SESSION = [];
T::is('a different session does not accept the old token', false, Csrf::matches($token));

/* -------------------------------------------------------- rate limiting */

section('Rate limiting');

RateLimiter::resetConfiguration();
$burstIp = '198.51.100.77';
Http::overrideIp($burstIp);
$burst = Harness::client(8, 'Burst Client');
$caught = null;
$made = 0;
for ($i = 0; $i < 12; $i++) {
    try {
        $requests->create($burst, ['domain' => 'burst-' . $i . '.com', 'budget' => '1200.00', 'currency' => 'USD']);
        $made++;
    } catch (RateLimitException $e) {
        $caught = $e;
        break;
    }
}
T::ok('the burst was stopped', $caught !== null);
T::ok('after a bounded number of requests', $made > 0 && $made <= 6);
T::ok('the error tells the caller when to retry', $caught->retryAfter > 0);
T::is('and nothing extra was written', $made, Db::count('requests', ['client_id' => 8]));
Harness::relaxRateLimits();
Http::overrideIp('198.51.100.20');

/* ------------------------------------------------------ injection / XSS */

section('Injection and encoding');

$payload = "Bobby'); DROP TABLE domain_broker_requests;--";
$messages->send($customer, $request['id'], ['body' => $payload]);
T::ok('the requests table survived a SQL payload', Db::count('requests') > 0);
$stored = Db::first('messages', ['request_id' => (int) $request['id']], ['order' => 'id', 'dir' => 'desc']);
T::is('the payload is stored verbatim as data', $payload, $stored['body']);

$xss = '<script>alert(document.cookie)</script>';
$xssRequest = $requests->create(Harness::client(9, 'Script Kiddie'), [
    'domain' => 'xss-attempt.com',
    'budget' => '5000.00',
    'currency' => 'USD',
    'message' => $xss . ' please acquire this',
]);
T::ok('markup is not executed on the way in', strpos($xssRequest['customer_message'], '<script>') === false
    || Str::e($xssRequest['customer_message']) !== $xssRequest['customer_message']);
T::is('output encoding neutralises it', '&lt;script&gt;alert(document.cookie)&lt;/script&gt;', Str::e($xss));
T::is('and the JS encoder escapes quotes', true, strpos(Str::js('a"b\'c'), '"') === false
    || strpos(Str::js('a"b\'c'), '\\') !== false);

T::throws('a domain field will not take markup', ValidationException::class, function () use ($requests, $intruder) {
    $requests->create($intruder, ['domain' => '<script>x</script>.com', 'budget' => '100.00', 'currency' => 'USD']);
});
T::throws('nor an unsupported currency', ValidationException::class, function () use ($requests, $intruder) {
    $requests->create($intruder, ['domain' => 'currency-test.com', 'budget' => '100.00', 'currency' => 'XYZ']);
});
T::throws('nor a negative budget', ValidationException::class, function () use ($requests, $intruder) {
    $requests->create($intruder, ['domain' => 'negative-test.com', 'budget' => '-500.00', 'currency' => 'USD']);
});

/* ------------------------------------------------------------- secrets */

section('Secrets never come from source');

foreach (['encryption_key', 'escrow_api_key', 'escrow_webhook_secret'] as $key) {
    T::throws('settings refuse to persist ' . $key, \DomainBroker\Core\ConfigurationException::class, function () use ($key) {
        Settings::set($key, 'hunter2', 'test');
    });
    T::is($key . ' is not stored in the database', 0, Db::count('settings', ['setting_key' => $key]));
}
T::ok('an encryption key is derived, not hardcoded', Crypto::isConfigured());
$a = Crypto::encrypt('same plaintext', 'probe');
$b = Crypto::encrypt('same plaintext', 'probe');
T::isnt('encryption is randomised per call', $a, $b);
T::is('but both decrypt', 'same plaintext', Crypto::decrypt($b, 'probe'));
T::is('and neither leaks the plaintext', false, strpos($a, 'same plaintext') !== false);
$grepSecrets = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/lib')) as $f) {
    if (substr($f->getFilename(), -4) !== '.php') {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    if (preg_match('/(api_key|secret|password)\s*=\s*[\'"][A-Za-z0-9_\\-]{12,}[\'"]/i', $src)) {
        $grepSecrets++;
        T::ok('no literal secret in ' . $f->getFilename(), false);
    }
}
T::is('no source file contains a literal credential', 0, $grepSecrets);

/* ------------------------------------------------------- audit integrity */

section('The audit trail is tamper evident');

$chain = Audit::verifyChain($request['id']);
T::is('the chain is intact', true, $chain['valid']);

$entry = Db::first('activity', ['request_id' => (int) $request['id']], ['order' => 'id', 'dir' => 'asc', 'offset' => 2]);
Db::update('activity', ['action' => 'request.quietly.changed'], ['id' => (int) $entry['id']]);
$broken = Audit::verifyChain($request['id']);
T::is('editing a row is detected', false, $broken['valid']);
T::is('and the break is located', (int) $entry['id'], (int) $broken['broken_at']);
Db::update('activity', ['action' => $entry['action']], ['id' => (int) $entry['id']]);
T::is('restoring the value restores the chain', true, Audit::verifyChain($request['id'])['valid']);

$before = Db::count('activity', ['request_id' => (int) $request['id']]);
T::throws('audited actions that need a reason refuse without one', ValidationException::class, function () use ($admin, $request) {
    Audit::record($admin, 'payment.refunded', ['request_id' => (int) $request['id'], 'reason' => '']);
});
T::is('and nothing was appended', $before, Db::count('activity', ['request_id' => (int) $request['id']]));

$sample = Db::first('activity', ['request_id' => (int) $request['id']], ['order' => 'id', 'dir' => 'desc']);
foreach (['actor_type', 'actor_id', 'action', 'request_id', 'ip_address', 'created_at'] as $column) {
    T::ok('every entry records ' . $column, array_key_exists($column, $sample));
}
T::ok('the IP is captured', !empty($sample['ip_address']));

Harness::shutdown();
exit(T::summary());
