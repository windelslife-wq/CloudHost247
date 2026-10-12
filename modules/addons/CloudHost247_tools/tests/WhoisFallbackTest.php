<?php
/**
 * Deterministic RDAP/WHOIS fallback tests. No network or database is used.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/tools/dns_tools.php';

$pass = 0;
$fail = 0;
function whois_ok($label, $condition)
{
    global $pass, $fail;
    if ($condition) { $pass++; }
    else { $fail++; echo "  FAIL $label\n"; }
}
function whois_eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else {
        $fail++;
        echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
    }
}
function whois_adapters($rdapResponse, $whoisResponse, &$calls)
{
    return [
        'rdap' => function ($url, $options) use ($rdapResponse, &$calls) {
            $calls['rdap']++;
            whois_ok('RDAP transport uses the fixed HTTPS endpoint',
                $url === 'https://rdap.org/domain/example.com');
            whois_ok('RDAP transport requests structured JSON',
                isset($options['headers'][0]) && $options['headers'][0] === 'Accept: application/rdap+json');
            if ($rdapResponse instanceof Throwable) { throw $rdapResponse; }
            return $rdapResponse;
        },
        'whois' => function ($domain) use ($whoisResponse, &$calls) {
            $calls['whois']++;
            whois_eq('WHOIS fallback receives the validated domain', $domain, 'example.com');
            if ($whoisResponse instanceof Throwable) { throw $whoisResponse; }
            return $whoisResponse;
        },
    ];
}
function run_whois_case($rdapResponse, $whoisResponse, &$calls)
{
    $calls = ['rdap' => 0, 'whois' => 0];
    return CloudHost247_tool_domain_whois(
        ['domain' => 'EXAMPLE.COM'],
        whois_adapters($rdapResponse, $whoisResponse, $calls)
    );
}

// A matching structured RDAP record short-circuits WHOIS.
$rdap = [
    'status' => 200,
    'body' => json_encode([
        'objectClassName' => 'domain',
        'ldhName' => 'example.com',
        'handle' => 'EXAMPLE-123',
        'status' => ['active'],
        'events' => [['eventAction' => 'registration', 'eventDate' => '2025-01-02T00:00:00Z']],
        'nameservers' => [['ldhName' => 'NS1.EXAMPLE.COM']],
        'entities' => [],
        'secureDNS' => ['delegationSigned' => true],
    ]),
];
$r = run_whois_case($rdap, ['result' => 'must not be called'], $calls);
whois_eq('matching RDAP object is accepted', $r['availability_status'], 'record_found');
whois_eq('matching RDAP record marks registered', $r['registered'], true);
whois_eq('matching RDAP record is the source', $r['source'], 'RDAP');
whois_eq('matching RDAP object short-circuits WHOIS', $calls['whois'], 0);
whois_eq('RDAP registrar event is parsed', $r['dates']['registration'] ?? null, '2025-01-02T00:00:00Z');
whois_eq('RDAP nameserver is normalized', $r['nameservers'][0] ?? null, 'ns1.example.com');

// RDAP 404 is an explicit no-match report, not a registrar availability guarantee.
$r = run_whois_case(['status' => 404, 'body' => '{}'], ['result' => 'must not be called'], $calls);
whois_eq('RDAP 404 remains a reported no-match', $r['availability_status'], 'no_match_reported');
whois_eq('RDAP 404 is not a registration record', $r['registered'], false);
whois_eq('RDAP 404 short-circuits WHOIS', $calls['whois'], 0);
whois_ok('RDAP 404 carries registrar-confirmation caveat', strpos($r['availability_note'], 'does not guarantee') !== false);

// Network failure and wrong-domain RDAP records both use the WHOIS fallback.
$registeredWhois = [
    'server' => 'whois.verisign-grs.com',
    'result' => "Domain Name: EXAMPLE.COM\nRegistrar: Example Registrar\nName Server: NS1.EXAMPLE.COM\n",
];
$r = run_whois_case(new CloudHost247ToolsSecurityException('secret internal transport detail'), $registeredWhois, $calls);
whois_eq('RDAP exception reaches WHOIS fallback', $calls['whois'], 1);
whois_eq('matching WHOIS domain record is confirmed', $r['availability_status'], 'record_found');
whois_eq('WHOIS matching record marks registered', $r['registered'], true);
whois_eq('WHOIS fallback identifies its source', $r['source'], 'WHOIS (port 43)');
whois_ok('RDAP transport detail is not exposed', strpos(json_encode($r), 'secret internal transport detail') === false);
whois_eq('WHOIS registrar text is extracted', $r['registrar'], 'Example Registrar');

$wrongDomainRdap = [
    'status' => 200,
    'body' => json_encode(['objectClassName' => 'domain', 'ldhName' => 'attacker.example']),
];
$r = run_whois_case($wrongDomainRdap, $registeredWhois, $calls);
whois_eq('RDAP object for another domain is rejected', $r['source'], 'WHOIS (port 43)');
whois_eq('mismatched RDAP object triggers WHOIS', $calls['whois'], 1);
whois_eq('matching fallback WHOIS record is still usable', $r['availability_status'], 'record_found');

$wrongClassRdap = [
    'status' => 200,
    'body' => json_encode(['objectClassName' => 'entity', 'ldhName' => 'example.com']),
];
$r = run_whois_case($wrongClassRdap, ['server' => 'whois.example', 'result' => 'No match for "example.com".'], $calls);
whois_eq('non-domain RDAP object is rejected', $calls['whois'], 1);
whois_eq('WHOIS no-match is classified conservatively', $r['availability_status'], 'no_match_reported');
whois_eq('WHOIS no-match maps to not registered, not guaranteed availability', $r['registered'], false);
whois_ok('WHOIS no-match retains caveat', strpos($r['availability_note'], 'does not guarantee') !== false);

// A broad/foreign WHOIS response never becomes a false registration or availability claim.
$r = run_whois_case(['status' => 502, 'body' => ''], ['server' => 'whois.example', 'result' => "Domain Name: OTHER.COM\n"], $calls);
whois_eq('foreign WHOIS record remains unconfirmed', $r['availability_status'], 'unconfirmed');
whois_eq('foreign WHOIS record does not set registered false or true', $r['registered'], null);

$r = run_whois_case(['status' => 503, 'body' => ''], [
    'server' => 'whois.example',
    'result' => "Domain Name: EXAMPLE.COM\nNo match for example.com\n",
    'truncated' => true,
], $calls);
whois_eq('truncated WHOIS cannot confirm a matching-looking record', $r['availability_status'], 'unconfirmed');
whois_eq('truncated WHOIS leaves registration unknown', $r['registered'], null);

$r = run_whois_case(['status' => 502, 'body' => ''], ['error' => 'connection failed', 'result' => ''], $calls);
whois_ok('empty WHOIS fallback returns an error envelope', isset($r['error']));
whois_ok('empty WHOIS error is generic', strpos($r['error'], 'connection failed') === false);

// The optional seam is not fed from user input and rejects incomplete internal adapters.
$r = CloudHost247_tool_domain_whois(['domain' => 'example.com'], ['rdap' => function () {}]);
whois_ok('incomplete internal transport adapters are rejected', isset($r['error']));
$calls = ['rdap' => 0, 'whois' => 0];
$r = CloudHost247_tool_domain_whois(['domain' => ['example.com']], whois_adapters($rdap, $registeredWhois, $calls));
whois_ok('array domain input is rejected before any transport call', isset($r['error']) && $calls['rdap'] === 0 && $calls['whois'] === 0);

printf("\nPASS=%d FAIL=%d\n", $pass, $fail);
exit($fail ? 1 : 0);
