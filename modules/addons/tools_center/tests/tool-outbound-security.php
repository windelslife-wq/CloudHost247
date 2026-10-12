<?php
/**
 * Offline runtime regression tests for host-directed Tools Center IP/WHOIS calls.
 * Transport and command seams are overridden below; there are no live network
 * or shell calls. The real address-deny-list is still used for IP literals.
 */
define('TOOLS_API', true);
require __DIR__ . '/../external-api/outbound.php';
require __DIR__ . '/../external-api/tools/ip.php';
require __DIR__ . '/../external-api/tools/network.php';
require __DIR__ . '/../external-api/tools/security.php';
require __DIR__ . '/../external-api/tools/dns.php';
require __DIR__ . '/../external-api/tools/productivity.php';

$pass = 0;
$fail = 0;
function tool_security_check($label, $ok) {
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}
function tool_security_throws($callback, $messagePart = null) {
    try {
        $callback();
    } catch (Throwable $e) {
        return $messagePart === null || strpos($e->getMessage(), $messagePart) !== false;
    }
    return false;
}

class ToolsSecurityTestIp extends IpTools {
    public $commands = [];
    public $geoRequests = [];
    public $geoResponses = [];
    public $whoisRequests = [];
    public $dnsRecordRequests = [];
    public $hostLookupRequests = [];
    public $dnsFixtures = [];
    public $hostFixtures = [];

    protected function performDNSLookup($host, $type) {
        $this->dnsRecordRequests[] = ['host' => $host, 'type' => $type];
        return $this->dnsFixtures[$host][$type] ?? [];
    }

    protected function performGetHostByName($host) {
        $this->hostLookupRequests[] = $host;
        return $this->hostFixtures[$host] ?? $host;
    }

    protected function resolvePublicCommandTarget($host) {
        // A reserved test-only domain avoids DNS while exercising host pinning.
        if (strtolower($host) === 'unit.test') {
            return '8.8.4.4';
        }
        return parent::resolvePublicCommandTarget($host);
    }

    protected function executeCommand($command, &$output, &$returnCode) {
        $this->commands[] = $command;
        $returnCode = 0;
        if (preg_match('/(?:traceroute|tracert)/i', $command)) {
            $output = ['1  8.8.4.4  1.25 ms  1.20 ms  1.30 ms'];
            return;
        }
        $count = 1;
        if (preg_match('/-(?:c|n)\s+(\d+)/', $command, $match)) {
            $count = (int)$match[1];
        }
        $output = [
            $count . ' packets transmitted, ' . $count . ' received, 0% packet loss',
            'rtt min/avg/max = 1.0/1.1/1.2 ms',
        ];
    }

    protected function fetchGeoResponse($url, array $options) {
        $this->geoRequests[] = ['url' => $url, 'options' => $options];
        if ($this->geoResponses) {
            return array_shift($this->geoResponses);
        }
        return [
            'status' => 200,
            'body' => json_encode([
                'status' => 'success',
                'continent' => 'North America',
                'continentCode' => 'NA',
                'country' => 'United States',
                'countryCode' => 'US',
                'regionName' => 'Test Region',
                'city' => 'Test City',
                'zip' => '00000',
                'lat' => 0,
                'lon' => 0,
                'timezone' => 'Etc/UTC',
                'currency' => 'USD',
                'isp' => 'Test ISP',
                'org' => 'Test Organization',
                'as' => 'AS64500',
                'proxy' => false,
                'hosting' => false,
            ]),
        ];
    }

    protected function queryWhoisResponse($query, $server) {
        $this->whoisRequests[] = ['query' => $query, 'server' => $server];
        if (strpos($query, ':') !== false) {
            return "inet6num: 2606:4700::/32\norg-name: Example Test Org\ncountry: US\n";
        }
        return "NetRange: 8.8.8.0 - 8.8.8.255\nOrganization: Example Test Org\nCountry: US\n";
    }
}

class ToolsSecurityTestNetwork extends NetworkTools {
    public $whoisRequests = [];

    protected function queryWhoisResponse($query, $server) {
        $this->whoisRequests[] = ['query' => $query, 'server' => $server];
        return "route: 8.8.0.0/16\nOrgName: Example Test ISP\nCountry: US\n";
    }
}

class ToolsSecurityTestSsl extends SecurityTools {
    public $sslRequests = [];

    protected function openPublicTlsSocket($host, $port, $timeout, array $sslOptions, &$errno, &$errstr) {
        $this->sslRequests[] = [
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'options' => $sslOptions,
        ];
        $errno = 111;
        $errstr = 'Mock TLS failure';
        return false;
    }
}

class ToolsSecurityTestDns extends DnsTools {
    public $dnsRequests = [];
    public $dnsRecordRequests = [];
    public $hostLookupRequests = [];
    public $dnsFixtures = [];
    public $hostFixtures = [];

    protected function performDNSLookup($host, $type) {
        $this->dnsRecordRequests[] = ['host' => $host, 'type' => $type];
        return $this->dnsFixtures[$host][$type] ?? [];
    }

    protected function performGetHostByName($host) {
        $this->hostLookupRequests[] = $host;
        return $this->hostFixtures[$host] ?? $host;
    }

    protected function queryDNSViaResolver($domain, $type, $resolverIP) {
        $this->dnsRequests[] = ['domain' => $domain, 'type' => $type, 'resolver' => $resolverIP];
        return ['status' => 'RESOLVED', 'records' => ['synthetic-test-answer']];
    }
}

$ipTools = new ToolsSecurityTestIp([]);
$initialCommands = count($ipTools->commands);
tool_security_check('IP blacklist rejects private addresses before DNSBL lookups', tool_security_throws(function () use ($ipTools) {
    $ipTools->ipBlacklist(['ip' => '10.0.0.1']);
}, 'Only public IP addresses') );
tool_security_check('IP reverse lookup rejects loopback before PTR resolution', tool_security_throws(function () use ($ipTools) {
    $ipTools->resolveIPtoHostname(['ip' => '127.0.0.1']);
}, 'Only public IP addresses') );
tool_security_check('DNS reverse lookup rejects private IPs before PTR resolution', tool_security_throws(function () {
    (new DnsTools([]))->reverseIpLookup(['ip' => '192.168.1.10']);
}, 'Only public IP addresses') );
tool_security_check('ASN DNS lookup rejects private IPs before resolver access', tool_security_throws(function () {
    (new NetworkTools([]))->asnWhois(['ip' => '10.0.0.1']);
}, 'Only public IP addresses') );
tool_security_check('IPv6 compatibility check rejects local-use DNS names before lookup', tool_security_throws(function () use ($ipTools) {
    $ipTools->ipv6Compatibility(['host' => 'router.local']);
}, 'public fully qualified hostname') );
tool_security_check('website-to-IP rejects local-use DNS names before lookup', tool_security_throws(function () use ($ipTools) {
    $ipTools->websiteToIP(['url' => 'https://metadata.internal']);
}, 'public fully qualified website hostname') );
tool_security_check('DNS record tools reject local-use suffixes before lookup', tool_security_throws(function () {
    (new DnsTools([]))->spfChecker(['domain' => 'mail.local']);
}, 'Domain is required') );
tool_security_check('BIMI lookup rejects local-use suffixes before resolver access', tool_security_throws(function () {
    (new ProductivityTools([]))->bimiChecker(['domain' => 'metadata.internal', 'action' => 'check']);
}, 'public fully qualified domain') );
tool_security_check('ping rejects loopback before command execution', tool_security_throws(function () use ($ipTools) {
    $ipTools->ping(['host' => '127.0.0.1']);
}, 'Target address is not public') && count($ipTools->commands) === $initialCommands);
tool_security_check('traceroute rejects cloud link-local metadata before command execution', tool_security_throws(function () use ($ipTools) {
    $ipTools->traceroute(['host' => '169.254.169.254']);
}, 'Target address is not public') && count($ipTools->commands) === $initialCommands);
tool_security_check('hostname target is pinned to its validated address and lower bounds are enforced', (function () use ($ipTools) {
    $result = $ipTools->ping(['host' => 'unit.test', 'count' => -5, 'timeout' => -2]);
    $command = end($ipTools->commands);
    $countFlag = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '/-n\s+1/' : '/-c\s+1/';
    return $result['host'] === 'unit.test'
        && $result['resolved_ip'] === '8.8.4.4'
        && $result['reachable'] === true
        && strpos($command, '8.8.4.4') !== false
        && strpos($command, 'unit.test') === false
        && preg_match($countFlag, $command) === 1;
})());
tool_security_check('ping upper packet and timeout limits remain bounded', (function () use ($ipTools) {
    $ipTools->ping(['host' => 'unit.test', 'count' => 999, 'timeout' => 999]);
    $command = end($ipTools->commands);
    $countOk = preg_match(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '/-n\s+10/' : '/-c\s+10/', $command) === 1;
    $timeoutOk = preg_match(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '/-w\s+30000/' : '/-W\s+30/', $command) === 1;
    return $countOk && $timeoutOk;
})());
tool_security_check('traceroute pins hostname and enforces positive lower bounds', (function () use ($ipTools) {
    $result = $ipTools->traceroute(['host' => 'unit.test', 'max_hops' => -1, 'timeout' => 0]);
    $command = end($ipTools->commands);
    $hopOk = preg_match(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '/-h\s+1/' : '/-m\s+1/', $command) === 1;
    return $result['resolved_ip'] === '8.8.4.4'
        && strpos($command, '8.8.4.4') !== false
        && strpos($command, 'unit.test') === false
        && $hopOk;
})());
tool_security_check('traceroute upper hop and timeout limits remain bounded', (function () use ($ipTools) {
    $ipTools->traceroute(['host' => 'unit.test', 'max_hops' => 999, 'timeout' => 999]);
    $command = end($ipTools->commands);
    $hopOk = preg_match(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '/-h\s+50/' : '/-m\s+50/', $command) === 1;
    $timeoutOk = preg_match(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '/-w\s+30000/' : '/-w\s+30/', $command) === 1;
    return $hopOk && $timeoutOk;
})());
tool_security_check('IPv6 ping validates and uses the pinned IPv6 literal', (function () use ($ipTools) {
    $result = $ipTools->ping(['host' => '2606:4700:4700::1111', 'count' => 1]);
    $command = end($ipTools->commands);
    return $result['ip_version'] === 'IPv6'
        && $result['resolved_ip'] === '2606:4700:4700::1111'
        && strpos($command, '2606:4700:4700::1111') !== false;
})());
tool_security_check('IPv6 traceroute validates and uses the pinned IPv6 literal', (function () use ($ipTools) {
    $result = $ipTools->traceroute(['host' => '2606:4700:4700::1111', 'max_hops' => 2]);
    $command = end($ipTools->commands);
    return $result['resolved_ip'] === '2606:4700:4700::1111'
        && strpos($command, '2606:4700:4700::1111') !== false
        && (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' || strpos($command, 'tracert -6') === 0);
})());
tool_security_check('geolocation uses guarded fetch and maps only provider-returned fields', (function () use ($ipTools) {
    $result = $ipTools->ipLocation(['ip' => '8.8.8.8']);
    $_SERVER['REMOTE_ADDR'] = '8.8.4.4';
    $myIp = $ipTools->whatIsMyIP([]);
    $isp = $ipTools->ispChecker(['ip' => '8.8.8.8']);
    $request = $ipTools->geoRequests[0];
    return $result['city'] === 'Test City'
        && $result['continent']['name'] === 'North America'
        && $result['country']['code'] === 'US'
        && $result['timezone']['name'] === 'Etc/UTC'
        && $result['isp']['name'] === 'Test ISP'
        && $myIp['location']['country_code'] === 'US'
        && $myIp['timezone'] === 'Etc/UTC'
        && $isp['is_hosting'] === false
        && $isp['is_proxy'] === false
        && $isp['is_vpn'] === null
        && strpos($request['url'], 'http://ip-api.com/json/8.8.8.8?fields=') === 0
        && $request['options']['timeout'] === 5
        && $request['options']['max_redirects'] === 0;
})());
tool_security_check('geolocation does not disclose non-public IPs or follow provider redirects', (function () use ($ipTools) {
    $before = count($ipTools->geoRequests);
    $nonPublic = $ipTools->ipLocation(['ip' => '192.0.2.10']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $local = $ipTools->whatIsMyIP([]);
    $ipTools->geoResponses[] = ['status' => 301, 'body' => ''];
    $redirect = $ipTools->ipLocation(['ip' => '1.1.1.1']);
    return $nonPublic['is_public'] === false
        && count($ipTools->geoRequests) === $before + 1
        && $local['is_private'] === true
        && $redirect['city'] === null
        && end($ipTools->geoRequests)['options']['max_redirects'] === 0;
})());
tool_security_check('IPv4 WHOIS uses the expected server through the guarded transport seam', (function () use ($ipTools) {
    $result = $ipTools->ipWhois(['ip' => '8.8.8.8']);
    $request = end($ipTools->whoisRequests);
    return $request['server'] === 'whois.arin.net'
        && $request['query'] === '8.8.8.8'
        && $result['organization'] === 'Example Test Org';
})());
tool_security_check('IPv6 WHOIS uses the expected server through the guarded transport seam', (function () use ($ipTools) {
    $result = $ipTools->ipv6Whois(['ip' => '2606:4700:4700::1111']);
    $request = end($ipTools->whoisRequests);
    return $request['server'] === 'whois.ripe.net'
        && $request['query'] === '2606:4700:4700::1111'
        && $result['country'] === 'US';
})());
tool_security_check('ASN WHOIS uses fixed public registry hosts through the guarded transport seam', (function () {
    $tools = new ToolsSecurityTestNetwork([]);
    $result = $tools->asnWhois(['asn' => 'AS15169']);
    $request = end($tools->whoisRequests);
    return $request['server'] === 'whois.radb.net'
        && $request['query'] === 'AS15169'
        && $result['asn'] === 'AS15169'
        && $result['organization'] === 'Example Test ISP';
})());
tool_security_check('SSL checker rejects a private target before opening a TLS socket', (function () {
    $tools = new SecurityTools([]);
    $result = $tools->sslChecker(['host' => '127.0.0.1']);
    return $result['ssl_available'] === false
        && strpos($result['error'], 'permitted public host') !== false;
})());
tool_security_check('SSL checker preserves hostname SNI while using the guarded TLS transport', (function () {
    $tools = new ToolsSecurityTestSsl([]);
    $result = $tools->sslChecker(['host' => 'unit.test', 'port' => 8443]);
    $request = end($tools->sslRequests);
    return $result['ssl_available'] === false
        && $request['host'] === 'unit.test'
        && $request['port'] === 8443
        && $request['timeout'] === 30
        && $request['options']['SNI_enabled'] === true
        && $request['options']['peer_name'] === 'unit.test'
        && $request['options']['capture_peer_cert'] === true;
})());
tool_security_check('SSL checker rejects invalid ports before opening a socket', (function () {
    $tools = new ToolsSecurityTestSsl([]);
    $result = $tools->sslChecker(['host' => 'unit.test', 'port' => 0]);
    return $result['ssl_available'] === false
        && $result['error'] === 'Invalid port'
        && count($tools->sslRequests) === 0;
})());
tool_security_check('DNS propagation rejects unsupported dig types and option-shaped domains before querying', (function () {
    $tools = new ToolsSecurityTestDns([]);
    $badType = tool_security_throws(function () use ($tools) {
        $tools->dnsPropagation(['domain' => 'example.com', 'type' => 'AXFR']);
    }, 'Invalid DNS propagation record type');
    $badDomain = tool_security_throws(function () use ($tools) {
        $tools->dnsPropagation(['domain' => '--help', 'type' => 'A']);
    }, 'A valid fully qualified domain is required');
    $localDomain = tool_security_throws(function () use ($tools) {
        $tools->dnsPropagation(['domain' => 'metadata.internal', 'type' => 'A']);
    }, 'A valid fully qualified domain is required');
    return $badType && $badDomain && $localDomain && count($tools->dnsRequests) === 0;
})());
tool_security_check('DNS propagation queries only the fixed public resolver list with an allowlisted type', (function () {
    $tools = new ToolsSecurityTestDns([]);
    $result = $tools->dnsPropagation(['domain' => 'example.com', 'type' => 'mx']);
    $allowed = ['8.8.8.8', '8.8.4.4', '1.1.1.1', '1.0.0.1', '208.67.222.222', '208.67.220.220', '9.9.9.9', '149.112.112.112'];
    $queried = array_column($tools->dnsRequests, 'resolver');
    return count($tools->dnsRequests) === 8
        && $queried === $allowed
        && count(array_unique(array_column($tools->dnsRequests, 'type'))) === 1
        && $tools->dnsRequests[0]['type'] === 'MX'
        && count($result['results']) === 8;
})());
tool_security_check('DNS CNAME, NS, and MX answers cannot trigger resolver queries for local targets', (function () {
    $tools = new ToolsSecurityTestDns([]);
    $tools->dnsFixtures = [
        'example.com' => [
            DNS_CNAME => [['host' => 'example.com', 'target' => 'service.internal.', 'ttl' => 60]],
            DNS_NS => [['host' => 'example.com', 'target' => 'ns.local.', 'ttl' => 60]],
            DNS_MX => [['host' => 'example.com', 'target' => 'mail.internal.', 'pri' => 10, 'ttl' => 60]],
        ],
    ];
    $cname = $tools->cnameLookup(['domain' => 'example.com']);
    $ns = $tools->nsLookup(['domain' => 'example.com']);
    $mx = $tools->mxLookup(['domain' => 'example.com']);
    $queriedNames = array_column($tools->dnsRecordRequests, 'host');
    return $cname['final_target'] === 'service.internal.'
        && $cname['chain_length'] === 1
        && $ns['nameservers'][0]['ip'] === null
        && $ns['nameservers'][0]['ipv6'] === null
        && $mx['mx_records'][0]['ip'] === null
        && $mx['mx_records'][0]['ipv6'] === null
        && $tools->hostLookupRequests === []
        && array_unique($queriedNames) === ['example.com'];
})());
tool_security_check('IPv6 compatibility lookup refuses local NS and MX targets from public DNS answers', (function () use ($ipTools) {
    $ipTools->dnsRecordRequests = [];
    $ipTools->dnsFixtures = [
        'example.com' => [
            DNS_A => [['host' => 'example.com', 'ip' => '8.8.8.8']],
            DNS_AAAA => [],
            DNS_MX => [['host' => 'example.com', 'target' => 'mail.local.', 'pri' => 10]],
            DNS_NS => [['host' => 'example.com', 'target' => 'ns.internal.', 'ttl' => 60]],
        ],
    ];
    $result = $ipTools->ipv6Compatibility(['host' => 'example.com']);
    $queriedNames = array_column($ipTools->dnsRecordRequests, 'host');
    return $result['has_ipv4'] === true
        && $result['mx_records'][0]['has_ipv6'] === false
        && $ipTools->hostLookupRequests === []
        && array_unique($queriedNames) === ['example.com'];
})());

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail ? 1 : 0);
