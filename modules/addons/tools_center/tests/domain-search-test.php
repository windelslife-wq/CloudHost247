<?php
/** Offline, deterministic tests for the Tools Center domain-search resolver. */
require __DIR__ . '/../external-api/outbound.php';
require __DIR__ . '/../external-api/tools/search.php';

$pass = 0;
$fail = 0;
function domain_chk($label, $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
}
function domain_result($result) {
    return isset($result['results'][0]) ? $result['results'][0] : [];
}
function domain_throws($callback) {
    try { $callback(); return false; } catch (InvalidArgumentException $e) { return true; }
}

$baseConfig = [
    'allowed_domains_regex' => '/^[a-zA-Z0-9][a-zA-Z0-9\\-]{0,63}(\\.[a-zA-Z0-9][a-zA-Z0-9\\-]{0,63})+$/',
    'dns_lookup' => function ($domain) {
        if ($domain === 'conflict.com') {
            return ['ok' => true, 'records' => [['type' => 'A', 'ip' => '192.0.2.5']]];
        }
        return ['ok' => true, 'records' => []];
    },
    'whois_lookup' => function ($domain, $server) {
        if ($domain === 'registered.com') {
            return ['body' => "Domain Name: REGISTERED.COM\nRegistrar: Test Registry\n", 'complete' => true];
        }
        if ($domain === 'ambiguous.com') {
            return ['body' => "Registry service notice; no outcome provided.\n", 'complete' => true];
        }
        if ($domain === 'partial.com') {
            return ['body' => 'No match for "PARTIAL.COM"', 'complete' => false];
        }
        if ($domain === 'oversize.com') {
            return ['body' => str_repeat('x', 65537), 'complete' => true];
        }
        return ['body' => 'No match for "' . strtoupper($domain) . '"', 'complete' => true];
    },
];
$search = new SearchTools($baseConfig);

$blockedLookupCalls = 0;
$blockedConfig = $baseConfig;
$blockedConfig['dns_lookup'] = function ($domain) use (&$blockedLookupCalls) {
    $blockedLookupCalls++;
    return ['ok' => true, 'records' => []];
};
$blockedConfig['whois_lookup'] = function ($domain, $server) use (&$blockedLookupCalls) {
    $blockedLookupCalls++;
    return ['body' => '', 'complete' => false];
};
$blockedSearch = new SearchTools($blockedConfig);
domain_chk('local-use .local domains are rejected before DNS or WHOIS lookup',
    domain_throws(function () use ($blockedSearch) { $blockedSearch->domainSearch(['domain' => 'mail.local']); })
    && $blockedLookupCalls === 0);
domain_chk('local-use .internal domains are rejected before DNS or WHOIS lookup',
    domain_throws(function () use ($blockedSearch) { $blockedSearch->domainSearch(['domain' => 'metadata.internal']); })
    && $blockedLookupCalls === 0);

$available = domain_result($search->domainSearch(['domain' => 'https://www.available.com/']));
domain_chk('an explicit WHOIS no-match result can report available', $available['status'] === 'AVAILABLE' && $available['available'] === true && $available['whois_status'] === 'no_match');
domain_chk('URL normalization returns only the validated host', $available['domain'] === 'available.com');

$registered = domain_result($search->domainSearch(['domain' => 'registered.com']));
domain_chk('a matching WHOIS domain record reports registered', $registered['status'] === 'REGISTERED' && $registered['available'] === false);

$conflict = domain_result($search->domainSearch(['domain' => 'conflict.com']));
domain_chk('DNS evidence conflicting with WHOIS no-match stays unknown', $conflict['status'] === 'UNKNOWN' && $conflict['available'] === null);

$ambiguous = domain_result($search->domainSearch(['domain' => 'ambiguous.com']));
domain_chk('an unrecognized WHOIS response stays unknown', $ambiguous['status'] === 'UNKNOWN' && $ambiguous['available'] === null);

$partial = domain_result($search->domainSearch(['domain' => 'partial.com']));
domain_chk('an incomplete WHOIS response cannot claim availability', $partial['status'] === 'UNKNOWN' && $partial['available'] === null);

$oversize = domain_result($search->domainSearch(['domain' => 'oversize.com']));
domain_chk('oversize WHOIS data cannot claim availability', $oversize['status'] === 'UNKNOWN' && $oversize['available'] === null);

$unsupported = domain_result($search->domainSearch(['domain' => 'example.xyz']));
domain_chk('unsupported registry TLD returns unknown rather than a DNS-only guess', $unsupported['status'] === 'UNKNOWN' && $unsupported['available'] === null);

$bare = $search->domainSearch(['domain' => 'new-company']);
domain_chk('bare search label expands to the existing supported TLD set', count($bare['results']) === 10);
domain_chk('bare search results use explicit WHOIS outcomes', count(array_filter($bare['results'], function ($row) { return $row['status'] === 'AVAILABLE'; })) === 10);

domain_chk('one terminal DNS dot is normalized', domain_result($search->domainSearch(['domain' => 'example.com.']))['domain'] === 'example.com');
domain_chk('credentials in input URLs are rejected', domain_throws(function () use ($search) { $search->domainSearch(['domain' => 'https://user:pass@example.com']); }));
domain_chk('non-HTTP URL schemes are rejected', domain_throws(function () use ($search) { $search->domainSearch(['domain' => 'ftp://example.com']); }));
domain_chk('paths on a URL are not silently discarded', domain_throws(function () use ($search) { $search->domainSearch(['domain' => 'https://example.com/path']); }));
domain_chk('nonstandard URL ports are rejected', domain_throws(function () use ($search) { $search->domainSearch(['domain' => 'https://example.com:8443']); }));
domain_chk('multiple trailing dots are rejected', domain_throws(function () use ($search) { $search->domainSearch(['domain' => 'example.com..']); }));
domain_chk('invalid punctuation is rejected, not stripped into a different name', domain_throws(function () use ($search) { $search->domainSearch(['domain' => 'example.com<script>']); }));

$dnsFailureConfig = $baseConfig;
$dnsFailureConfig['dns_lookup'] = function ($domain) { return ['ok' => false, 'records' => []]; };
$dnsFailure = domain_result((new SearchTools($dnsFailureConfig))->domainSearch(['domain' => 'available.com']));
domain_chk('DNS lookup failure is marked separately from an empty answer', $dnsFailure['dns_lookup_ok'] === false && $dnsFailure['status'] === 'AVAILABLE');

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
