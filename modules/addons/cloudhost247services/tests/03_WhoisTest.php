<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\ProviderException;
use Chs\Core\Settings;
use Chs\Core\ServiceUnavailableException;
use Chs\Providers\Whois\CachedWhoisProvider;
use Chs\Providers\Whois\SocketWhoisProvider;
use Chs\Providers\Whois\WhoisParser;
use Chs\Services\WhoisService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_freeze();

const COM_RAW = <<<'EOT'
   Domain Name: EXAMPLE.COM
   Registry Domain ID: 2336799_DOMAIN_COM-VRSN
   Registrar WHOIS Server: whois.reserved-registrar.test
   Registrar URL: http://www.example-registrar.test
   Updated Date: 2025-08-14T07:01:38Z
   Creation Date: 1995-08-14T04:00:00Z
   Registry Expiry Date: 2027-08-13T04:00:00Z
   Registrar: Reserved Registrar LLC
   Registrar IANA ID: 376
   Domain Status: clientDeleteProhibited https://icann.org/epp#clientDeleteProhibited
   Name Server: A.IANA-SERVERS.NET
   Name Server: B.IANA-SERVERS.NET
   DNSSEC: signedDelegation

Registry Registrant ID: Not Disclosed
Registrant Name: REDACTED FOR PRIVACY
Registrant Organization: REDACTED FOR PRIVACY
Registrar Abuse Contact Email: abuse@reserved-registrar.test
>>> Last update of whois database: 2026-10-05T22:03:19Z <<<
EOT;

const IANA_RAW = "% IANA WHOIS server\ndomain:       COM\nwhois:        whois.verisign-grs.com\nrefer:        whois.verisign-grs.com\nstatus:       ACTIVE\n\n";

/**
 * Build a readable preloaded stream resource for SocketWhoisProvider's
 * injected connector: a padding zone absorbs the outgoing query write,
 * then the canned registry body is what gets read back.
 */
function chs_fake_socket($body)
{
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, str_repeat("\n", 128));   // absorb the "\r\n"-terminated query write
    fwrite($fp, $body);
    rewind($fp);
    return $fp;
}

T::section('Parser — classic thick-registry record');
$p = WhoisParser::parse(COM_RAW);
T::eq('registrar lifted', 'Reserved Registrar LLC', $p['registrar']);
T::eq('created normalised', '1995-08-14 04:00:00', $p['created']);
T::eq('expiry normalised', '2027-08-13 04:00:00', $p['expires']);
T::eq('updated normalised', '2025-08-14 07:01:38', $p['updated']);
T::eq('nameservers lowercased', ['a.iana-servers.net', 'b.iana-servers.net'], $p['nameservers']);
T::ok('status captured without URL noise', (function () use ($p) {
    return count($p['statuses']) === 1
        && strpos($p['statuses'][0], 'clientDeleteProhibited') === 0;
})());
T::ok('redaction detected', $p['privacy_protected'] === true);
T::ok('redacted org never surfaces', $p['registrant_org'] === null);
T::eq('abuse contact kept public', 'abuse@reserved-registrar.test', $p['abuse_email']);
T::eq('dnssec captured', 'signedDelegation', $p['dnssec']);
T::ok('raw_available true', $p['raw_available']);

T::section('Parser — empty / unparseable bodies');
$e = WhoisParser::parse("");
T::ok('empty body honest', $e['raw_available'] === false && $e['registrar'] === null);
$n = WhoisParser::parse("No match for domain \"ZZZZ-NOPE.COM\".\n>>> Last update <<<\n");
T::ok('not-found text does not fabricate fields', $n['registrar'] === null && $n['created'] === null && $n['nameservers'] === []);

T::section('Socket provider — full bootstrap → registry → registrar referral');
$opened = [];
$provider = new SocketWhoisProvider(function ($host) use (&$opened) {
    $opened[] = $host;
    switch ($host) {
        case 'whois.iana.org':
            return chs_fake_socket(IANA_RAW);
        case 'whois.verisign-grs.com':
        case 'whois.reserved-registrar.test':
            return chs_fake_socket(COM_RAW);
    }
    return null; // anything else is unreachable in the sandbox
});
$out = $provider->lookup('example.com', 'com');
T::eq('server path exact', ['whois.iana.org', 'whois.verisign-grs.com', 'whois.reserved-registrar.test'], $opened);
T::eq('final responder recorded', 'whois.reserved-registrar.test', $out['server']);
T::ok('raw carried through', strpos($out['raw'], 'Domain Name: EXAMPLE.COM') !== false);
T::ok('redaction markers survive end-to-end', WhoisParser::parse($out['raw'])['privacy_protected'] === true);

T::section('Socket provider — unreachable registry never pretends');
$dead = new SocketWhoisProvider(function () {
    return null;
});
T::throws('socket failure raises ProviderException', function () use ($dead) {
    $dead->lookup('example.com', 'com');
}, ProviderException::class);

T::section('Socket provider — registry answer kept when registrar is down');
$partial = new SocketWhoisProvider(function ($host) {
    if ($host === 'whois.iana.org') {
        return chs_fake_socket(IANA_RAW);
    }
    if ($host === 'whois.verisign-grs.com') {
        return chs_fake_socket(COM_RAW);
    }
    return null; // registrar unreachable
});
$res = $partial->lookup('example.com', 'com');
T::eq('falls back to registry answer', 'whois.verisign-grs.com', $res['server']);
T::ok('registry data still parseable', WhoisParser::parse($res['raw'])['registrar'] === 'Reserved Registrar LLC');

T::section('Caching decorator — replay protection for the registry');
$count = 0;
$counting = new SocketWhoisProvider(function ($host) use (&$count) {
    $count++;
    return chs_fake_socket($host === 'whois.iana.org' ? IANA_RAW : COM_RAW);
});
$cache = new CachedWhoisProvider($counting);
Settings::override('whois_cache_minutes', '120');
$count = 0;
$a = $cache->lookup('cached-domain.com', 'com');
$afterA = $count;
$b = $cache->lookup('cached-domain.com', 'com');
T::eq('first lookup hit the wire', true, $afterA > 0);
T::eq('second lookup served from cache', $afterA, $count);
T::ok('cache row stored with parsed payload', (function () {
    $row = Db::first('whois_cache', ['domain' => 'cached-domain.com']);
    return $row && $row['parsed'] && json_decode($row['parsed'], true)['registrar'] === 'Reserved Registrar LLC';
})());
chs_freeze('+121 minutes');
$c = $cache->lookup('cached-domain.com', 'com');
T::ok('expired cache refetches', $count > $afterA);
chs_freeze();
Settings::override('whois_cache_minutes', null);

T::section('WhoisService — rate limits, enable flag, audit');
$svc = new WhoisService($cache);
$info = $svc->lookup('service-check.com');
T::eq('service returns fqdn', 'service-check.com', $info['domain']);
T::eq('tld split', 'com', $info['tld']);
T::ok('parsed shape exposed', isset($info['parsed']['registrar']));
T::ok('privacy flag surfaced', array_key_exists('privacy_protected', $info));
T::ok('audit trail written', Db::count('audit_log', ['action' => 'whois.lookup']) > 0);

$_SERVER['REMOTE_ADDR'] = '198.51.100.230';
Settings::override('whois_daily_limit_per_ip', '2');
$svc2 = new WhoisService($cache);
$svc2->lookup('limit-one.net');
$svc2->lookup('limit-two.net');
T::throws('third lookup in window denied', function () use ($svc2) {
    $svc2->lookup('limit-three.net');
}, \Chs\Core\RateLimitException::class);
Settings::override('whois_daily_limit_per_ip', null);

T::throws('service switch-off is explicit', function () {
    Settings::override('whois_enabled', '0');
    try {
        (new WhoisService())->lookup('x.com');
    } finally {
        Settings::override('whois_enabled', null);
    }
}, ServiceUnavailableException::class);

T::section('Availability via gateway (search oracle path)');
$gateway->availability = []; // unknown by default
$avail = new \Chs\Services\AvailabilityService();
$unknown = $avail->check(DomainName::parse('mystery-name-zzz.com'), false);
T::ok('gateway default shape', array_key_exists('available', $unknown) && is_string($unknown['status']));
T::ok('unknown does NOT mean available', $unknown['available'] !== true || $gateway->availability !== []);

T::finish();
