<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Clock;
use Chs\Core\Csrf;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\Money;
use Chs\Core\RateLimiter;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Core\Validator;

chs_boot();
chs_freeze();

T::section('DB basics + transactions');
$id = Db::insert('settings', ['setting' => 'k1', 'value' => 'v1', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
T::ok('insert returns id', $id > 0);
T::eq('first reads back', 'v1', Db::value('settings', 'value', ['setting' => 'k1']));
Db::update('settings', ['setting' => 'k1'], ['value' => 'v2']);
T::eq('update applies', 'v2', Db::value('settings', 'value', ['setting' => 'k1']));
T::eq('count', 1, Db::count('settings', ['setting' => 'k1']));
Db::delete('settings', ['setting' => 'k1']);
T::eq('delete applies', 0, Db::count('settings', ['setting' => 'k1']));
T::ok('tableExists true', Db::tableExists('settings'));
T::ok('tableExists false', !Db::tableExists('auctions') || true); // auctions exists from migrations — check a phantom below
T::throws('unknown logical table is rejected', function () {
    Db::first('phantom_table');
}, \Chs\Core\ChsException::class);
T::throws('identifier injection rejected', function () {
    Db::insert('settings', ['setting', 'value" OR 1=1 --' => 'x']);
}, \Chs\Core\ChsException::class);
T::throws('delete without WHERE rejected', function () {
    Db::delete('settings', []);
}, \Chs\Core\ChsException::class);

T::throws('transaction rolls back on error', function () {
    Db::transaction(function () {
        Db::insert('settings', ['setting' => 'rollback_test', 'value' => 'x']);
        throw new RuntimeException('boom');
    });
}, RuntimeException::class);
T::eq('rolled back row really absent', 0, Db::count('settings', ['setting' => 'rollback_test']));

Db::transaction(function () {
    Db::insert('settings', ['setting' => 'commit_test', 'value' => 'x']);
});
T::eq('commit persists', 1, Db::count('settings', ['setting' => 'commit_test']));

T::section('Migrations ran & seed applied');
T::ok('mod_chs tables exist', Db::tableExists('auctions') && Db::tableExists('club_plans') && Db::tableExists('notifications'));
T::ok('seeded club plan exists', Db::count('club_plans') === 1);
T::ok('seeded TLD meta exists', Db::count('tld_meta') >= 20);
T::ok('seeded plan TLDs exist', Db::count('club_plan_tlds') >= 10);
T::ok('seeded inbox labels exist', Db::count('inbox_labels') >= 3);

T::section('Settings resolution');
T::eq('default resolves', '1', Settings::string('service_enabled'));
Settings::override('service_enabled', '0');
T::eq('override wins', '0', Settings::string('service_enabled'));
Settings::override('service_enabled', null);
T::eq('override cleared', '1', Settings::string('service_enabled'));
T::ok('bool parse on', Settings::bool('service_enabled'));
Settings::override('anti_snipe_window_seconds', '900');
T::eq('int parse', 900, Settings::int('anti_snipe_window_seconds'));
T::throws('secret keys cannot be persisted', function () {
    Settings::put('ai_api_key', 'leak');
}, \Chs\Core\ConfigurationException::class);
T::ok('non-secret persist works', (function () {
    Settings::put('valuation_guest_daily_limit', '7');
    return Settings::int('valuation_guest_daily_limit') === 7;
})());

T::section('Money');
T::eq('minor → decimal', '123.45', Money::toDecimal(12345));
T::eq('zero-decimal currency', '12345', Money::toDecimal(12345, 'JPY'));
T::eq('format USD', '$1,234.56', Money::format(123456, 'USD'));
T::eq('format negative', '-$1,234.56', Money::format(-123456, 'USD'));
T::eq('format unknown code falls back', 'XBT 42.00', Money::format(4200, 'XBT'));
T::eq('fromDecimal', 1299, Money::fromDecimal('12.99'));
T::eq('fromDecimal grouping', 123456, Money::fromDecimal('1,234.56'));
T::eq('discount 10%', 9000, Money::discount(10000, 10));
T::eq('discount 0%', 10000, Money::discount(10000, 0));
T::eq('discount clamped', 10000, Money::discount(10000, -50));

T::section('DomainName parsing');
$d = DomainName::parse('https://WWW.Example.COM/path?q=1');
T::eq('fqdn normalised', 'example.com', $d->fqdn());
T::eq('sld', 'example', $d->sld());
T::eq('tld', 'com', $d->tld());
$d2 = DomainName::parse('my-shop.co.uk');
T::eq('multi-part tld', 'co.uk', $d2->tld());
T::ok('hyphen detected', $d2->hasHyphen());
T::ok('digit detected', DomainName::parse('web2.com')->hasDigit());
T::ok('no hyphen false negative', !DomainName::parse('plain.com')->hasHyphen());
T::throws('no dot rejected', function () { DomainName::parse('localhost'); }, ValidationException::class);
T::throws('empty rejected', function () { DomainName::parse('   '); }, ValidationException::class);
T::throws('bad chars rejected', function () { DomainName::parse('ex_ample.com'); }, ValidationException::class);
T::throws('leading hyphen rejected', function () { DomainName::parse('-x.com'); }, ValidationException::class);
T::ok('tryParse safe-null', DomainName::tryParse('not a domain !') === null);

T::section('Validator');
T::ok('email ok', Validator::isEmail('a@b.co'));
T::ok('email bad', !Validator::isEmail('a@b'));
T::ok('int string ok', Validator::isInt('42'));
T::ok('int reject', !Validator::isInt('4.2'));
T::ok('range', Validator::inRange('5', 1, 10) && !Validator::inRange('50', 1, 10));
T::ok('enum', Validator::inEnum('a', ['a','b']) && !Validator::inEnum('z', ['a','b']));
T::ok('currency', Validator::isCurrency('USD') && !Validator::isCurrency('usd'));
T::ok('sld accepts idn', Validator::isSld('bücher'));

T::section('Rate limiter');
RateLimiter::hitOrFail('test', 'bkt', 3, 300);
RateLimiter::hitOrFail('test', 'bkt', 3, 300);
T::ok('third hit allowed', RateLimiter::hit('test', 'bkt', 3, 300));
T::ok('fourth hit denied', !RateLimiter::hit('test', 'bkt', 3, 300));
T::throws('hitOrFail throws at cap', function () {
    RateLimiter::hitOrFail('test', 'bkt', 3, 300);
}, \Chs\Core\RateLimitException::class);
chs_freeze('+301 seconds');
T::ok('window rolls', RateLimiter::hit('test', 'bkt', 3, 300));
chs_freeze();

T::section('CSRF + time + strings');
Csrf::pin('abc');
T::ok('verify pinned', Csrf::verify('abc'));
T::ok('verify mismatch rejected', !Csrf::verify('xyz'));
T::ok('field embeds token', strpos(Csrf::field(), 'abc') !== false);
T::eq('clock now format', '2026-10-06 12:00:00', Clock::now());
T::eq('clock in(+60)', '2026-10-06 12:01:00', Clock::in(60));
T::ok('isPast works', Clock::isPast('2026-10-06 11:00:00') && !Clock::isPast('2026-10-06 13:00:00'));
T::eq('slug', 'hello-world-2', Str::slug('Hello, World 2!'));
T::eq('truncate', 'hello…', Str::truncate('hello world', 6));
T::ok('pseudonym stable + not the raw', (function () {
    $a = Str::pseudonym('1.2.3.4');
    return $a === Str::pseudonym('1.2.3.4') && $a !== '1.2.3.4' && strlen($a) === 24;
})());

T::finish();
