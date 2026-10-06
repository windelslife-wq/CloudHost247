<?php
require_once __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\Identity;
use Chs\Services\ConsentService;

chs_boot();
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

T::section('Consent records are strict, pseudonymous and retained as history');
$service = new ConsentService();
$consentId = 'browser-20261006-abcdef123456';
T::ok('allow decision persists', $service->record('allow', $consentId, 'necessary,analytics,marketing', 'cookie-policy-v1', 'ar'));
$row = Db::first('consent_records', [], 'id DESC');
T::eq('status is recorded', 'allow', $row['status']);
T::eq('categories are recorded', 'necessary,analytics,marketing', $row['categories']);
T::eq('language is recorded', 'ar', $row['language']);
T::eq('client is anonymous when not signed in', 0, (int) $row['client_id']);
T::eq('raw browser id is not stored', hash('sha256', $consentId), $row['consent_hash']);
T::ok('invalid browser id is rejected', !$service->record('allow', 'too-short', 'necessary', 'cookie-policy-v1', 'en'));
Identity::setClient(11);
T::ok('revoke decision appends history', $service->record('revoke', $consentId, 'necessary,analytics', 'cookie-policy-v1', 'en'));
$row = Db::first('consent_records', [], 'id DESC');
T::eq('revoke is recorded', 'revoke', $row['status']);
T::eq('revoke keeps necessary only', 'necessary', $row['categories']);
T::eq('signed-in client is linked', 11, (int) $row['client_id']);
T::eq('two decisions remain auditable', 2, Db::count('consent_records'));

T::finish();
