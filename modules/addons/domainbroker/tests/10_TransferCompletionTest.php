<?php
/**
 * Suite 10 — how a domain transfer is proven complete.
 *
 * With the registry check on (rdap_enabled, the code default), the registry must
 * show the gaining registrar as sponsor. With it off, only finance may attest,
 * with a registrar reference. Fund release refuses a transfer with no recorded basis.
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\Settings;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\DomainIntelService;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\TransferService;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\TransferStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$transfers = new TransferService();
$payments = new PaymentService();
$finance = Harness::admin(5, 'admin_finance', 'Finance Officer');

// What the registry (RDAP) says about the domain. Tests set these before acting.
$registryMode = 'registered';          // registered | unreachable | unregistered
$registryName = 'CloudHost247 Registrar';
DomainIntelService::setResolver(function ($domain, $type) use (&$registryMode, &$registryName) {
    if ($type !== 'rdap') {
        return null;
    }
    if ($registryMode === 'unreachable') {
        return null;
    }
    if ($registryMode === 'unregistered') {
        return ['_not_found' => true];
    }
    return ['entities' => [[
        'roles' => ['registrar'],
        'vcardArray' => ['vcard', [['fn', [], 'text', $registryName]]],
        'publicIds' => [['type' => 'IANA Registrar ID', 'identifier' => '1000']],
    ]]];
});

/** A transfer waiting in PENDING for a fresh secured acquisition. */
$openTransfer = function ($domain, $clientId, $gaining) use ($transfers) {
    $fixture = Harness::securedRequest($domain, $clientId);
    $transfer = $transfers->start($fixture['broker'], $fixture['request']['id'], [
        'losing_registrar' => 'Tucows',
        'gaining_registrar' => $gaining,
        'destination_account' => 'client@example.test',
    ]);
    foreach ([TransferStatus::AUTH_RECEIVED, TransferStatus::INITIATED, TransferStatus::PENDING] as $step) {
        $transfer = $transfers->updateStatus($fixture['broker'], $transfer['id'], $step, [
            'note' => 'Advancing to ' . $step . '.',
        ]);
    }
    return ['fixture' => $fixture, 'transfer' => $transfer];
};

/* ----------------------------------------------------- registry check on */

section('Registry check on: the registry must show the gaining registrar');

Settings::override('rdap_enabled', '1');

$matched = $openTransfer('registry-match.com', 21, 'CloudHost247 Registrar');
$done = T::nothrow('the broker confirms completion and the registry agrees', function () use ($transfers, $matched) {
    return $transfers->markCompleted($matched['fixture']['broker'], $matched['transfer']['id'], [
        'evidence' => 'Broker checked the registry record.',
    ]);
});
T::is('the transfer completes', TransferStatus::COMPLETED, $done['status']);
T::is('the basis is the registry', TransferService::BASIS_REGISTRY, $done['completion_basis']);
T::ok('the registry evidence is stored', strpos((string) $done['registry_check'], 'CloudHost247 Registrar') !== false);

$registrarMismatch = $openTransfer('registry-other.com', 22, 'CloudHost247 Registrar');
$registryName = 'Tucows Domains Inc.';
T::throws('a registry showing another sponsor refuses completion', ConflictException::class,
    function () use ($transfers, $registrarMismatch) {
        $transfers->markCompleted($registrarMismatch['fixture']['broker'], $registrarMismatch['transfer']['id'], [
            'evidence' => 'Broker says it is done.',
        ]);
    });
$registryName = 'CloudHost247 Registrar';
T::is('and the transfer stays open', TransferStatus::PENDING,
    Db::first('transfers', ['id' => (int) $registrarMismatch['transfer']['id']])['status']);

$unreachable = $openTransfer('registry-down.com', 23, 'CloudHost247 Registrar');
$registryMode = 'unreachable';
T::throws('an unreachable registry refuses completion rather than accepting the note', ConflictException::class,
    function () use ($transfers, $unreachable) {
        $transfers->markCompleted($unreachable['fixture']['broker'], $unreachable['transfer']['id'], [
            'evidence' => 'Broker says it is done.',
        ]);
    });
$registryMode = 'registered';

$unregistered = $openTransfer('registry-gone.com', 24, 'CloudHost247 Registrar');
$registryMode = 'unregistered';
T::throws('a domain the registry does not know refuses completion', ConflictException::class,
    function () use ($transfers, $unregistered) {
        $transfers->markCompleted($unregistered['fixture']['broker'], $unregistered['transfer']['id'], [
            'evidence' => 'Broker says it is done.',
        ]);
    });
$registryMode = 'registered';

$noNameSet = $openTransfer('registry-blank.com', 25, '');
T::throws('an unnamed gaining registrar can never match (fail closed)', ConflictException::class,
    function () use ($transfers, $noNameSet) {
        $transfers->markCompleted($noNameSet['fixture']['broker'], $noNameSet['transfer']['id'], [
            'evidence' => 'Broker says it is done.',
        ]);
    });

/* ----------------------------------------------------- registry check off */

section('Registry check off: only finance can attest, with a reference');

Settings::override('rdap_enabled', '0');

$attested = $openTransfer('attest-me.com', 26, 'CloudHost247 Registrar');
T::throws('a broker cannot attest completion', AuthorizationException::class,
    function () use ($transfers, $attested) {
        $transfers->markCompleted($attested['fixture']['broker'], $attested['transfer']['id'], [
            'evidence' => 'Broker says it is done.', 'registrar_reference' => 'REF-B',
        ]);
    });
T::throws('finance still needs the registrar reference', ValidationException::class,
    function () use ($transfers, $finance, $attested) {
        $transfers->markCompleted($finance, $attested['transfer']['id'], ['evidence' => 'Confirmed by email.']);
    });
$attestedDone = T::nothrow('finance attests with the reference', function () use ($transfers, $finance, $attested) {
    return $transfers->markCompleted($finance, $attested['transfer']['id'], [
        'evidence' => 'Confirmed by email.', 'registrar_reference' => 'TUCOWS-EMAIL-7',
    ]);
});
T::is('the basis is the finance attestation', TransferService::BASIS_ATTESTED, $attestedDone['completion_basis']);
T::is('the reference is stored', 'TUCOWS-EMAIL-7', $attestedDone['registrar_reference']);

/* ------------------------------------------------- release needs a basis */

section('Fund release refuses a transfer with no recorded completion basis');

$legacy = Harness::securedRequest('legacy-complete.com', 27);
$legacyTransfer = $transfers->start($legacy['broker'], $legacy['request']['id'], [
    'losing_registrar' => 'Tucows', 'gaining_registrar' => 'CloudHost247 Registrar',
    'destination_account' => 'client@example.test',
]);
// Simulate a transfer completed before the basis was recorded.
Db::update('transfers', ['status' => TransferStatus::COMPLETED, 'completion_basis' => null],
    ['id' => (int) $legacyTransfer['id']]);
$legacyPayment = Db::first('payments', ['request_id' => (int) $legacy['request']['id']]);
T::throws('release is refused without a recorded basis', InvalidTransitionException::class,
    function () use ($payments, $finance, $legacyPayment) {
        $payments->releaseFunds($finance, $legacyPayment['id']);
    });
T::is('and the money is still held', PaymentStatus::FUNDS_SECURED,
    Db::first('payments', ['id' => (int) $legacyPayment['id']])['status']);

Settings::override('rdap_enabled', '1');
Harness::shutdown();
exit(T::summary());
