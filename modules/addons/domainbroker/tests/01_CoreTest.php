<?php
/**
 * Domain Broker — core primitives.
 *
 * Money arithmetic, domain normalisation, validation, encryption, the clock,
 * rate limiting, idempotency, RBAC and the tamper-evident audit chain.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\DomainName;
use DomainBroker\Core\Idempotency;
use DomainBroker\Core\Money;
use DomainBroker\Core\RateLimiter;
use DomainBroker\Core\RateLimitException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Core\Validator;

Harness::boot();

/* ------------------------------------------------------------------ money */

section('Money');

T::is('USD has two decimals', 2, Money::exponent('USD'));
T::is('JPY has none', 0, Money::exponent('JPY'));
T::is('BHD has three', 3, Money::exponent('BHD'));
T::is('decimal → minor', 125000, Money::toMinor('1250.00', 'USD'));
T::is('comma grouping tolerated', 125000, Money::toMinor('1,250.00', 'USD'));
T::is('JPY has no scaling', 1250, Money::toMinor('1250', 'JPY'));
T::is('minor → decimal', '1250.00', Money::toDecimalString(125000, 'USD'));
T::is('percent of', 12500, Money::percentOf(125000, 10));
T::is('percent rounds half up', 1, Money::percentOf(10, 10));
T::is('clamp floor', 500, Money::clamp(100, 500, null));
T::is('clamp ceiling', 900, Money::clamp(10000, null, 900));
T::ok('valid currency', Money::isValidCurrency('GBP'));
T::ok('rejects junk currency', !Money::isValidCurrency('US$'));
T::throws('negative amount rejected', ValidationException::class, function () {
    Money::toMinor('-5.00', 'USD');
});

/* ----------------------------------------------------------------- domain */

section('DomainName');

T::is('lowercases and trims', 'example.com', DomainName::normalise('  ExAmple.COM. '));
T::is('strips scheme and path', 'example.com', DomainName::normalise('https://example.com/some/page?a=1'));
T::is('strips www', 'example.com', DomainName::normalise('www.example.com'));
T::is('registrable from host', 'example.co.uk', DomainName::registrable('shop.example.co.uk'));
T::is('registrable single label tld', 'example.com', DomainName::registrable('a.b.example.com'));
T::is('tld', 'co.uk', DomainName::tld('example.co.uk'));
T::is('sld', 'example', DomainName::sld('example.co.uk'));
T::is('rejects nonsense', null, DomainName::normalise('not a domain'));
T::is('rejects bare tld', null, DomainName::normalise('com'));
T::ok('idn becomes punycode', strpos((string) DomainName::normalise('bücher.de'), 'xn--') === 0);

/* -------------------------------------------------------------- validator */

section('Validator');

$valid = Validator::make([
    'domain' => 'Example.com',
    'currency' => 'usd',
    'budget' => '5000.00',
    'anonymous' => '1',
    'message' => "  hello\x00 world  ",
])->domain('domain')->currency('currency', ['USD', 'EUR'])->money('budget', 'USD', 100, 0)
  ->boolean('anonymous')->text('message', 100)->validate();

T::is('domain normalised', 'example.com', $valid['domain']);
T::is('currency upper-cased', 'USD', $valid['currency']);
T::is('budget in minor units', 500000, $valid['budget']);
T::is('boolean coerced', true, $valid['anonymous']);
T::is('null byte stripped', 'hello world', $valid['message']);

$errors = Validator::make(['domain' => 'nope', 'currency' => 'ZZZ', 'budget' => '1.00'])
    ->domain('domain')->currency('currency', ['USD'])->money('budget', 'USD', 100000, 0);
T::ok('invalid input fails', $errors->fails());
T::is('three field errors', 3, count($errors->errors()));
T::throws('validate() throws', ValidationException::class, function () use ($errors) {
    $errors->validate();
});

T::throws('budget below minimum rejected', ValidationException::class, function () {
    Validator::make(['budget' => '1.00'])->money('budget', 'USD', 100000, 0)->validate();
});
T::throws('budget above maximum rejected', ValidationException::class, function () {
    Validator::make(['budget' => '999999.00'])->money('budget', 'USD', 0, 100000)->validate();
});
T::throws('unknown enum rejected', ValidationException::class, function () {
    Validator::make(['mode' => 'wat'])->in('mode', ['a', 'b'])->validate();
});
T::throws('bad email rejected', ValidationException::class, function () {
    Validator::make(['email' => 'not-an-email'])->email('email')->validate();
});

/* ------------------------------------------------------------------ crypto */

section('Crypto');

T::ok('key is configured from env', Crypto::isConfigured());
$cipher = Crypto::encrypt('auth-code-SECRET-123', 'transfer.auth_code');
T::isnt('ciphertext differs from plaintext', 'auth-code-SECRET-123', $cipher);
T::is('round trips', 'auth-code-SECRET-123', Crypto::decrypt($cipher, 'transfer.auth_code'));
T::is('wrong context fails closed', null, Crypto::tryDecrypt($cipher, 'contact.email'));
T::isnt('same plaintext encrypts differently', $cipher, Crypto::encrypt('auth-code-SECRET-123', 'transfer.auth_code'));
T::is('blind index is stable', Crypto::blindIndex('a@b.com', 'contact.email'), Crypto::blindIndex('a@b.com', 'contact.email'));
T::isnt('blind index is context bound', Crypto::blindIndex('a@b.com', 'contact.email'), Crypto::blindIndex('a@b.com', 'other'));
T::is('tampered token rejected', null, Crypto::tryDecrypt($cipher . 'x', 'transfer.auth_code'));

/* ---------------------------------------------------------------- settings */

section('Settings');

T::is('default is returned', '120', Settings::get('offer_validity_hours'));
T::is('int cast', 120, Settings::int('offer_validity_hours'));
T::is('bool cast', true, Settings::bool('notifications_inapp'));
T::is('list parsing', ['USD', 'EUR', 'GBP'], Settings::listOf('allowed_currencies'));
T::throws('secrets cannot be persisted', \DomainBroker\Core\DomainBrokerException::class, function () {
    Settings::set('escrow_api_key', 'sk_live_oops');
});
Settings::set('offer_validity_hours', '48');
T::is('operational setting persists', 48, Settings::int('offer_validity_hours'));
Settings::set('offer_validity_hours', '120');

/* ------------------------------------------------------------------- clock */

section('Clock');

Clock::freeze(strtotime('2026-03-01 12:00:00 UTC'));
T::is('frozen now', '2026-03-01 12:00:00', Clock::now());
T::is('in days', '2026-03-08 12:00:00', Clock::inDays(7));
T::is('in hours', '2026-03-01 18:00:00', Clock::inHours(6));
T::ok('past detected', Clock::isPast('2026-02-28 00:00:00'));
T::ok('future not past', !Clock::isPast('2026-03-02 00:00:00'));
Clock::travel(86400);
T::is('travel moves the clock', '2026-03-02 12:00:00', Clock::now());
Clock::unfreeze();

/* ------------------------------------------------------------ rate limiter */

section('RateLimiter');

T::nothrow('first five request.create calls pass', function () {
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit('request.create', 'client:999');
    }
});
T::throws('sixth is limited', RateLimitException::class, function () {
    RateLimiter::hit('request.create', 'client:999');
});
T::nothrow('a different principal is unaffected', function () {
    RateLimiter::hit('request.create', 'client:1000');
});

/* ------------------------------------------------------------- idempotency */

section('Idempotency');

$calls = 0;
$op = function () use (&$calls) {
    $calls++;
    return ['id' => 42];
};
$first = Idempotency::run('demo', 'key-1', ['a' => 1], $op);
$second = Idempotency::run('demo', 'key-1', ['a' => 1], $op);
T::is('operation ran once', 1, $calls);
T::is('first was not a replay', false, $first['replayed']);
T::is('second was a replay', true, $second['replayed']);
T::is('replay returns the same result', 42, $second['result']['id']);
T::throws('same key with different payload conflicts', \DomainBroker\Core\ConflictException::class, function () use ($op) {
    Idempotency::run('demo', 'key-1', ['a' => 2], $op);
});
T::nothrow('failures are not cached as success', function () {
    try {
        Idempotency::run('demo', 'key-2', ['b' => 1], function () {
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException $e) {
        // expected
    }
    return Idempotency::run('demo', 'key-2', ['b' => 1], function () {
        return ['ok' => true];
    });
});

/* -------------------------------------------------------------------- rbac */

section('RBAC');

$customer = Actor::customer(1, 'Ada');
$broker = Actor::broker(1, 'broker', 'Grace');
$viewer = Actor::admin(2, 'admin_viewer');
$finance = Actor::admin(3, 'admin_finance');
$super = Actor::admin(4, 'admin_super');
$system = Actor::system();
$guest = Actor::guest();

T::ok('customer can create a request', Rbac::allows($customer, Rbac::REQUEST_CREATE));
T::ok('customer cannot assign brokers', !Rbac::allows($customer, Rbac::BROKER_ASSIGN));
T::ok('customer cannot read internal notes', !Rbac::allows($customer, Rbac::NOTE_INTERNAL_READ));
T::ok('customer cannot see owner contacts', !Rbac::allows($customer, Rbac::OWNER_CONTACT_VIEW));
T::ok('broker can record owner offers', Rbac::allows($broker, Rbac::OFFER_RECORD_OWNER));
T::ok('broker cannot approve verification', !Rbac::allows($broker, Rbac::VERIFICATION_APPROVE));
T::ok('broker cannot refund', !Rbac::allows($broker, Rbac::PAYMENT_REFUND));
T::ok('broker cannot reveal auth codes', !Rbac::allows($broker, Rbac::TRANSFER_CREDENTIAL_VIEW));
T::ok('read-only admin cannot refund', !Rbac::allows($viewer, Rbac::PAYMENT_REFUND));
T::ok('read-only admin cannot assign', !Rbac::allows($viewer, Rbac::BROKER_ASSIGN));
T::ok('read-only admin can view', Rbac::allows($viewer, Rbac::REQUEST_VIEW_ALL));
T::ok('finance can refund', Rbac::allows($finance, Rbac::PAYMENT_REFUND));
T::ok('finance cannot override status', !Rbac::allows($finance, Rbac::STATUS_OVERRIDE));
T::ok('super can override status', Rbac::allows($super, Rbac::STATUS_OVERRIDE));
T::ok('system cannot refund', !Rbac::allows($system, Rbac::PAYMENT_REFUND));
T::ok('system cannot approve verification', !Rbac::allows($system, Rbac::VERIFICATION_APPROVE));
T::ok('guest can do nothing', !Rbac::allows($guest, Rbac::REQUEST_VIEW_OWN));
T::throws('assert throws for the wrong role', AuthorizationException::class, function () use ($customer) {
    Rbac::assert($customer, Rbac::PAYMENT_REFUND);
});
T::ok('matrix was seeded into the database', Db::count('roles') > 0);

/* ------------------------------------------------------------------- audit */

section('Audit chain');

$admin = Harness::admin();
Audit::record($admin, 'test.one', ['request_id' => 1, 'new' => ['a' => 1], 'visibility' => Audit::VIS_INTERNAL]);
Audit::record($admin, 'test.two', ['request_id' => 1, 'new' => ['a' => 2], 'visibility' => Audit::VIS_CUSTOMER]);
Audit::record($admin, 'test.three', ['request_id' => 1, 'new' => ['a' => 3], 'visibility' => Audit::VIS_BROKER]);

T::is('three entries', 3, Db::count('activity', ['request_id' => 1]));
T::ok('chain verifies', Audit::verifyChain(1)['valid']);
T::is('customer sees only customer entries', 1, count(Audit::timeline(1, 'customer')));
T::is('broker sees customer + broker', 2, count(Audit::timeline(1, 'broker')));
T::is('admin sees everything', 3, count(Audit::timeline(1, 'admin')));

$row = Db::first('activity', ['request_id' => 1, 'action' => 'test.two']);
T::ok('entries carry the actor', $row['actor_type'] === 'admin' && (int) $row['actor_id'] === 1);
T::ok('entries carry the ip', $row['ip_address'] === '203.0.113.1');
T::ok('entries are hashed', strlen($row['record_hash']) === 64);

// Tamper with a row and prove the chain notices.
Db::run(
    'UPDATE ' . Db::quoteIdentifier(Db::table('activity')) . ' SET new_value = ? WHERE id = ?',
    ['{"a":999}', (int) $row['id']]
);
$verification = Audit::verifyChain(1);
T::ok('tampering breaks the chain', !$verification['valid']);
T::is('the tampered row is identified', (int) $row['id'], (int) $verification['broken_at']);

T::throws('reason-required actions refuse to be silent', ValidationException::class, function () use ($admin) {
    Audit::record($admin, 'payment.refunded', ['request_id' => 1]);
});

/* --------------------------------------------------------------------- str */

section('Str');

T::is('clip keeps length', 10, strlen(Str::clip('abcdefghijklmnop', 10)));
T::is('escapes html', '&lt;script&gt;', Str::e('<script>'));
T::ok('reference has the prefix', strpos(Str::reference('DB', 8), 'DB-') === 0);
T::isnt('references differ', Str::reference('DB', 8), Str::reference('DB', 8));
T::is('mask hides the middle', '********3f2a', Str::mask('secret-code-3f2a', 0, 4));
T::ok('masked values stay short', strlen(Str::mask(str_repeat('x', 120), 0, 4)) <= 20);
T::is('json round trips', ['a' => 1], Str::jsonDecode(Str::jsonEncode(['a' => 1])));
T::is('bad json yields the default', [], Str::jsonDecode('{not json', []));

Harness::shutdown();
exit(T::summary());
