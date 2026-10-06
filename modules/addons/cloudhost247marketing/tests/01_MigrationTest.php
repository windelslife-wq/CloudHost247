<?php
/** Migrations, settings, secrets and RBAC — the foundations. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Migrator;
use Ch247Mkt\Core\Rbac;
use Ch247Mkt\Core\Secret;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;

ch247m_boot();

T::section('Migrations');
$expected = [
    'settings', 'rate_limits', 'role_permissions', 'audit_log',
    'lists', 'subscribers', 'list_members', 'segments',
    'templates', 'campaigns', 'campaign_recipients',
    'email_queue', 'email_events', 'suppressions', 'links',
    'automations', 'automation_steps', 'automation_enrollments',
    'cart_activity',
];
foreach ($expected as $table) {
    T::ok('table ' . $table . ' exists', Db::tableExists($table));
}

T::section('Migrations are idempotent');
$before = Db::count('templates');
(new Migrator(dirname(__DIR__) . '/install/migrations'))->migrate();
T::eq('re-running migrate() changes nothing', $before, Db::count('templates'));
T::ok('template library seeded', Db::count('templates') >= 12);
T::eq('default list created', 1, Db::count('lists', ['slug' => 'all-customers']));

T::section('Settings');
T::eq('sending is off until an operator turns it on', '0', Settings::DEFAULTS['sending_enabled']);
Settings::resetOverrides();
T::eq('unknown key falls back', 'fallback', Settings::string('not_a_real_key', 'fallback'));
Settings::put('from_name', 'CloudHost247 Marketing');
T::eq('round-trips through the database', 'CloudHost247 Marketing', Settings::string('from_name', ''));
T::throws('unknown key is rejected on write', function () {
    Settings::put('definitely_not_a_setting', 'x');
}, \Ch247Mkt\Core\Ch247MktException::class);

T::section('Secrets');
Secret::setKey('test-key-for-the-suite');
$sealed = Secret::seal('hunter2');
T::ok('sealed value is marked', Secret::isSealed($sealed));
T::notContains('plaintext is not in the sealed blob', 'hunter2', $sealed);
T::eq('unseals to the original', 'hunter2', Secret::open($sealed));
T::eq('opening a plain value is a no-op', 'plain', Secret::open('plain'));

Settings::put('smtp_password', 's3cret-smtp');
$raw = Db::first('settings', ['setting' => 'smtp_password']);
T::notContains('secret is not stored in the clear', 's3cret-smtp', (string) $raw['value']);
T::eq('secret reads back correctly', 's3cret-smtp', Settings::string('smtp_password', ''));
T::ok('hasSecret reports presence', Settings::hasSecret('smtp_password'));

T::section('RBAC');
\Ch247Mkt\Core\Identity::setAdmin(null);
T::ok('no admin session means no access', !Rbac::adminCan(Rbac::MKT_READ));

ch247m_as_super_admin(); // admin 1, role 1
T::ok('super admin can do everything', Rbac::adminCan(Rbac::MKT_SEND));

Rbac::setRoleGroups(2, [Rbac::MKT_READ, Rbac::MKT_COMPOSE]);
\Ch247Mkt\Core\Identity::setAdmin(2); // role 2
T::ok('granted group is allowed', Rbac::adminCan(Rbac::MKT_COMPOSE));
T::ok('compose does not imply send', !Rbac::adminCan(Rbac::MKT_SEND));
T::ok('unknown group denied even for a granted role', !Rbac::adminCan('mkt.nonsense'));
T::throws('requireAdmin throws for a missing group', function () {
    Rbac::requireAdmin(Rbac::MKT_SEND);
}, \Ch247Mkt\Core\ForbiddenException::class);

\Ch247Mkt\Core\Identity::setAdmin(3); // role 3, nothing granted
T::ok('role with no grants is denied', !Rbac::adminCan(Rbac::MKT_READ));
$granted = Rbac::groupsForRole(2);
sort($granted);
T::eq('groupsForRole lists exactly what was granted', [Rbac::MKT_COMPOSE, Rbac::MKT_READ], $granted);
T::eq('super admin role reports every group', Rbac::ALL_GROUPS, Rbac::groupsForRole(1));
ch247m_as_super_admin();

T::section('Clock');
ch247m_freeze('2026-10-06 12:00:00');
T::eq('now() is frozen', '2026-10-06 12:00:00', Clock::now());
T::eq('in() adds seconds', '2026-10-06 13:00:00', Clock::in(3600));
T::eq('ago() subtracts seconds', '2026-10-06 11:00:00', Clock::ago(3600));

T::section('Str helpers');
T::ok('valid address accepted', Str::isEmail('a.b+tag@example.co.uk'));
T::ok('header injection rejected', !Str::isEmail("a@b.test\r\nBcc: evil@x.test"));
T::ok('overlong address rejected', !Str::isEmail(str_repeat('a', 200) . '@x.test'));
T::eq('normalisation lowercases and trims', 'a@b.test', Str::normalizeEmail('  A@B.TEST '));
T::eq('idempotency key is stable', Str::idempotencyKey(5, 'A@B.test'), Str::idempotencyKey(5, 'a@b.test'));
T::ok('idempotency key varies by campaign', Str::idempotencyKey(5, 'a@b.test') !== Str::idempotencyKey(6, 'a@b.test'));
T::notContains('masked email hides the local part', 'someone', Str::maskEmail('someone@example.test'));
T::contains('masked email keeps the TLD', '.test', Str::maskEmail('someone@example.test'));

T::finish();
