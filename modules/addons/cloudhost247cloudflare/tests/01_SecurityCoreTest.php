<?php
/**
 * cloudhost247cloudflare - security core tests.
 *
 * This module shipped with no test suite at all, so these assertions pin the
 * security-critical invariants that were previously verified only by reading:
 *
 *   - credential encryption must be authenticated, keyed off a real secret,
 *     and must never fall back to plaintext
 *   - the outbound API host must be pinned to api.cloudflare.com over HTTPS
 *   - user-supplied DNS records must be validated per type and bounded
 *
 * Everything here is pure and needs no database: Crypto carries a key seam,
 * and the validators are static.
 */

define('CH247CF_TESTING', true);
require __DIR__ . '/../autoload.php';

use CloudHost247\Cloudflare\Core\Crypto;
use CloudHost247\Cloudflare\Core\ConfigurationException;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Provider\CloudflareClient;
use CloudHost247\Cloudflare\Service\DnsRecordValidator;
use CloudHost247\Cloudflare\Service\DomainName;

$pass = 0;
$fail = 0;

function cf_ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL $label\n"; }
}

function cf_eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else {
        $fail++;
        echo "  FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n";
    }
}

/** Assert that $fn throws an instance of $class. */
function cf_throws($label, $class, callable $fn)
{
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        echo "  FAIL $label: no exception thrown (expected $class)\n";
    } catch (\Throwable $e) {
        if ($e instanceof $class) { $pass++; }
        else {
            $fail++;
            echo "  FAIL $label: expected $class, got " . get_class($e) . ': ' . $e->getMessage() . "\n";
        }
    }
}

/* ------------------------------------------------------- crypto -- */

echo "== credential encryption ==\n";

if (!function_exists('openssl_encrypt')) {
    echo "  SKIP: OpenSSL unavailable in this runtime\n";
} else {
    Crypto::setKeyForTests('test-key-material-abcdefghijklmnop');

    $sealed = Crypto::seal('cloudflare-api-token-abc123');
    cf_ok('sealed payload is prefixed', strpos($sealed, 'cfenc:v1:') === 0);
    cf_ok('sealed payload is not the plaintext', $sealed !== 'cloudflare-api-token-abc123');
    cf_ok('ciphertext does not contain the token', strpos($sealed, 'cloudflare-api-token-abc123') === false);
    cf_eq('round trip', Crypto::open($sealed), 'cloudflare-api-token-abc123');

    // Randomised nonce: the same plaintext must not seal twice alike.
    cf_ok('nonce is randomised', Crypto::seal('same-value') !== Crypto::seal('same-value'));

    // Empty in, empty out - not an error.
    cf_eq('empty plaintext seals to empty', Crypto::seal(''), '');
    cf_eq('empty ciphertext opens to empty', Crypto::open(''), '');

    // The module must refuse to read anything it did not seal.
    cf_throws('plaintext stored value is rejected', ConfigurationException::class, function () {
        Crypto::open('cloudflare-api-token-abc123');
    });
    cf_throws('foreign prefix is rejected', ConfigurationException::class, function () {
        Crypto::open('other:v1:AAAA');
    });
    cf_throws('truncated payload is rejected', ConfigurationException::class, function () {
        Crypto::open('cfenc:v1:' . base64_encode('short'));
    });
    cf_throws('garbage payload is rejected', ConfigurationException::class, function () {
        Crypto::open('cfenc:v1:!!!!not-base64!!!!');
    });

    // Tamper detection: GCM must fail on any modification.
    $sealed2 = Crypto::seal('another-token');
    $raw = base64_decode(substr($sealed2, strlen('cfenc:v1:')), true);
    $flipped = $raw;
    $flipped[strlen($flipped) - 1] = chr(ord($flipped[strlen($flipped) - 1]) ^ 0x01);
    cf_throws(
        'tampered ciphertext fails authentication',
        ConfigurationException::class,
        function () use ($flipped) { Crypto::open('cfenc:v1:' . base64_encode($flipped)); }
    );

    // AAD binding: the ciphertext must not open under a different key.
    $sealed3 = Crypto::seal('key-rotation-token');
    Crypto::setKeyForTests('a-completely-different-key-material');
    cf_throws('wrong key fails to open', ConfigurationException::class, function () use ($sealed3) {
        Crypto::open($sealed3);
    });
    Crypto::setKeyForTests('test-key-material-abcdefghijklmnop');
    cf_eq('correct key still opens after rotation attempt', Crypto::open($sealed3), 'key-rotation-token');

    Crypto::setKeyForTests(null);
}

/* ------------------------------------------- outbound host pinning -- */

echo "== outbound API host pinning ==\n";

$good = null;
try {
    $good = new CloudflareClient('https://api.cloudflare.com/client/v4', 'token');
    cf_ok('official HTTPS endpoint accepted', true);
} catch (\Throwable $e) {
    cf_ok('official HTTPS endpoint accepted: ' . $e->getMessage(), false);
}

// Everything else must be refused, so a misconfiguration cannot turn the
// Cloudflare API token into an SSRF credential.
// Several of these carry a valid /client/v4 path on purpose, so they can only
// be refused by the host check and not by the path check. Otherwise a weakened
// host comparison would still pass by accident.
$badEndpoints = [
    'http://api.cloudflare.com/client/v4'            => 'plain HTTP',
    'https://api.cloudflare.com.evil.test/client/v4' => 'lookalike host, valid path',
    'https://evil.test/client/v4'                    => 'unrelated host, valid path',
    'https://api.cloudflare.com.evil.test'           => 'lookalike host, no path',
    'https://api.cloudflare.com:22/client/v4'        => 'alternative port, valid path',
    'https://user:pw@api.cloudflare.com/client/v4'   => 'embedded credentials, valid path',
    'https://api.cloudflare.com/client/v4?a=1'       => 'query string',
    'https://api.cloudflare.com/client/v4#frag'      => 'fragment',
    'https://api.cloudflare.com/other'               => 'valid host, wrong path',
];
foreach ($badEndpoints as $url => $why) {
    cf_throws("endpoint refused ($why)", ConfigurationException::class, function () use ($url) {
        new CloudflareClient($url, 'token');
    });
}

/* -------------------------------------------------- DNS validation -- */

echo "== DNS record validation ==\n";

$zone = 'example.com';

// Valid records of every supported type.
$valid = [
    'A'     => ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10'],
    'AAAA'  => ['type' => 'AAAA', 'name' => 'www', 'content' => '2001:db8::1'],
    'CNAME' => ['type' => 'CNAME', 'name' => 'shop', 'content' => 'target.example.net'],
    'MX'    => ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => 10],
    'TXT'   => ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 include:_spf.example.com ~all'],
    'NS'    => ['type' => 'NS', 'name' => 'sub', 'content' => 'ns1.example.net'],
    'CAA'   => ['type' => 'CAA', 'name' => '@', 'content' => '0 issue letsencrypt.org'],
];
foreach ($valid as $type => $input) {
    try {
        $r = DnsRecordValidator::validate($input, $zone);
        cf_eq("valid $type record accepted", $r['type'], $type);
    } catch (\Throwable $e) {
        cf_ok("valid $type record accepted: " . $e->getMessage(), false);
    }
}

// Per-type content rules must actually bite.
$rejects = [
    'unsupported type'   => ['type' => 'PTR', 'name' => 'x', 'content' => 'y'],
    'A with IPv6'        => ['type' => 'A', 'name' => 'www', 'content' => '2001:db8::1'],
    'A with hostname'    => ['type' => 'A', 'name' => 'www', 'content' => 'not-an-ip'],
    'AAAA with IPv4'     => ['type' => 'AAAA', 'name' => 'www', 'content' => '203.0.113.10'],
    'empty content'      => ['type' => 'A', 'name' => 'www', 'content' => ''],
    'CAA missing tag'    => ['type' => 'CAA', 'name' => '@', 'content' => '0'],
    'CAA bad flag'       => ['type' => 'CAA', 'name' => '@', 'content' => '99 issue letsencrypt.org'],
];
foreach ($rejects as $label => $input) {
    cf_throws("rejected: $label", ValidationException::class, function () use ($input, $zone) {
        DnsRecordValidator::validate($input, $zone);
    });
}

// Bounds.
cf_throws('TTL below 60 refused', ValidationException::class, function () use ($zone) {
    DnsRecordValidator::validate(['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => 59], $zone);
});
cf_throws('TTL above 86400 refused', ValidationException::class, function () use ($zone) {
    DnsRecordValidator::validate(['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => 86401], $zone);
});
cf_throws('MX priority above 65535 refused', ValidationException::class, function () use ($zone) {
    DnsRecordValidator::validate(['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => 70000], $zone);
});
cf_throws('over-long comment refused', ValidationException::class, function () use ($zone) {
    DnsRecordValidator::validate(
        ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'comment' => str_repeat('x', 513)],
        $zone
    );
});
cf_throws('over-long TXT refused', ValidationException::class, function () use ($zone) {
    DnsRecordValidator::validate(['type' => 'TXT', 'name' => '@', 'content' => str_repeat('a', 2049)], $zone);
});

// Proxying is only meaningful for A/AAAA/CNAME and forces TTL to Automatic.
$proxied = DnsRecordValidator::validate(
    ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => 300, 'proxied' => true],
    $zone
);
cf_eq('proxied record forces TTL Automatic', $proxied['ttl'], 1);
$notProxiable = DnsRecordValidator::validate(
    ['type' => 'TXT', 'name' => '@', 'content' => 'hello', 'ttl' => 300, 'proxied' => true],
    $zone
);
cf_eq('proxied flag dropped for non-proxiable type', $notProxiable['proxied'], false);
cf_eq('TTL preserved for non-proxiable type', $notProxiable['ttl'], 300);

/* -------------------------------------------------- firewall rules -- */

echo "== firewall rule validation ==\n";

cf_throws('invalid IP rejected', ValidationException::class, function () {
    DnsRecordValidator::firewallRule(['address' => 'not-an-ip', 'description' => 'block bad']);
});
cf_throws('invalid CIDR prefix rejected', ValidationException::class, function () {
    DnsRecordValidator::firewallRule(['address' => '203.0.113.0/99', 'description' => 'block bad']);
});
cf_throws('unknown action rejected', ValidationException::class, function () {
    DnsRecordValidator::firewallRule(['address' => '203.0.113.1', 'rule_action' => 'nuke', 'description' => 'x']);
});
cf_throws('empty description rejected', ValidationException::class, function () {
    DnsRecordValidator::firewallRule(['address' => '203.0.113.1']);
});

$rule = DnsRecordValidator::firewallRule([
    'address' => '203.0.113.1', 'rule_action' => 'challenge', 'description' => 'suspicious host',
]);
cf_eq('action is mapped to the Cloudflare name', $rule['action'], 'managed_challenge');
cf_eq('single address uses eq', $rule['expression'], '(ip.src eq 203.0.113.1)');
$cidr = DnsRecordValidator::firewallRule(['address' => '203.0.113.0/24', 'description' => 'range']);
cf_eq('CIDR uses in {}', $cidr['expression'], '(ip.src in { 203.0.113.0/24 })');

// The address is embedded in the expression, so validation is what prevents
// expression injection - confirm the guard holds for hostile input.
cf_throws('expression injection blocked', ValidationException::class, function () {
    DnsRecordValidator::firewallRule([
        'address' => '1.1.1.1) or (ip.src neq 2.2.2.2', 'description' => 'injection',
    ]);
});

/* ------------------------------------------------------ domain names -- */

echo "== domain name handling ==\n";

cf_eq('normalize lowercases', DomainName::normalize('EXAMPLE.COM'), 'example.com');
cf_eq('normalize strips trailing dot', DomainName::normalize('example.com.'), 'example.com');
cf_eq('normalize trims whitespace', DomainName::normalize('  example.com  '), 'example.com');
cf_ok('isWithinZone accepts a subdomain', DomainName::isWithinZone('www.example.com', 'example.com'));
cf_ok('isWithinZone rejects another zone', !DomainName::isWithinZone('www.evil.test', 'example.com'));
cf_ok(
    'isWithinZone rejects a suffix lookalike',
    !DomainName::isWithinZone('example.com.evil.test', 'example.com')
);

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
