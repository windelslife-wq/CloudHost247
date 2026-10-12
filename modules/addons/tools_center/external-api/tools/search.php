<?php
/**
 * Search Tools Module
 * Conservative domain availability lookup using explicit registry WHOIS results.
 */

class SearchTools {
    private $config;
    const WHOIS_MAX_BYTES = 65536;
    const WHOIS_TIMEOUT_SECONDS = 5;

    public function __construct($config) {
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * Domain availability search. DNS absence alone is never treated as proof
     * that a name is available; unsupported or inconclusive registry responses
     * are returned as UNKNOWN.
     */
    public function domainSearch($params) {
        if (!is_array($params)) {
            throw new InvalidArgumentException('A domain or search name is required');
        }
        $query = $this->normalizeDomain($params['domain'] ?? '');
        if ($query === '') {
            throw new InvalidArgumentException('A valid domain or search name is required');
        }

        if (strpos($query, '.') === false) {
            if (!$this->isValidSearchLabel($query)) {
                throw new InvalidArgumentException('Invalid domain search name');
            }
            $domains = [];
            foreach (['.com', '.net', '.org', '.io', '.co', '.info', '.biz', '.us', '.eu', '.me'] as $tld) {
                $domains[] = $query . $tld;
            }
        } else {
            if (!$this->isValidDomain($query)) {
                throw new InvalidArgumentException('Invalid domain name format');
            }
            $domains = [$query];
        }

        $results = [];
        foreach ($domains as $domain) {
            $results[] = $this->checkDomainAvailability($domain);
        }

        return [
            'query' => $query,
            'results' => $results,
            'timestamp' => date('Y-m-d H:i:s'),
            'note' => 'Availability is preliminary. Confirm with a registrar before purchasing.',
        ];
    }

    private function checkDomainAvailability($domain) {
        $dns = $this->lookupDns($domain);
        $whois = $this->getBasicWhois($domain);
        $whoisStatus = $this->classifyWhois($domain, $whois);
        $hasDns = $dns['ok'] && !empty($dns['records']);

        if ($whoisStatus === 'registered') {
            $status = 'REGISTERED';
            $available = false;
            $whoisInfo = 'The registry WHOIS response contains a matching domain record.';
        } elseif ($whoisStatus === 'no_match' && !$hasDns) {
            $status = 'AVAILABLE';
            $available = true;
            $whoisInfo = 'The registry WHOIS response explicitly reports no matching domain record.';
        } else {
            $status = 'UNKNOWN';
            $available = null;
            $whoisInfo = 'Availability could not be confirmed from a complete, unambiguous registry response.';
        }

        $nameservers = [];
        if ($dns['ok']) {
            foreach ($dns['records'] as $record) {
                if (isset($record['type'], $record['target']) && strtoupper((string) $record['type']) === 'NS') {
                    $nameservers[] = strtolower(rtrim((string) $record['target'], '.'));
                }
            }
        }

        $parts = explode('.', $domain);
        $tld = '.' . end($parts);
        array_pop($parts);
        return [
            'domain' => $domain,
            'sld' => implode('.', $parts),
            'tld' => $tld,
            'available' => $available,
            'status' => $status,
            'whois_status' => $whoisStatus,
            'dns_lookup_ok' => (bool) $dns['ok'],
            'has_dns' => (bool) $hasDns,
            'nameservers' => array_values(array_unique($nameservers)),
            // Do not return raw WHOIS text, which can contain registrant PII.
            'whois_info' => $whoisInfo,
        ];
    }

    /** Resolve common public DNS records; an error is distinct from no records. */
    private function lookupDns($domain) {
        if (isset($this->config['dns_lookup']) && is_callable($this->config['dns_lookup'])) {
            try {
                $result = call_user_func($this->config['dns_lookup'], $domain);
                if (is_array($result) && isset($result['ok']) && is_bool($result['ok'])
                    && isset($result['records']) && is_array($result['records'])) {
                    return $result;
                }
            } catch (Throwable $e) {
                // Report an unknown DNS result rather than turning failures into availability.
            }
            return ['ok' => false, 'records' => []];
        }

        $types = DNS_A | DNS_AAAA | DNS_MX | DNS_NS | DNS_CNAME;
        $records = @dns_get_record($domain, $types);
        return is_array($records)
            ? ['ok' => true, 'records' => $records]
            : ['ok' => false, 'records' => []];
    }

    /** Bounded WHOIS lookup using only a fixed registry-server allowlist. */
    private function getBasicWhois($domain) {
        $whoisServers = [
            'com' => 'whois.verisign-grs.com',
            'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org',
            'io' => 'whois.nic.io',
            'co' => 'whois.nic.co',
            'info' => 'whois.afilias.net',
            'biz' => 'whois.biz',
            'us' => 'whois.nic.us',
            'eu' => 'whois.eu',
            'me' => 'whois.nic.me',
        ];
        $parts = explode('.', $domain);
        $tld = strtolower((string) end($parts));
        if (!isset($whoisServers[$tld])) {
            return ['body' => '', 'complete' => false, 'server' => null];
        }
        $server = $whoisServers[$tld];

        if (isset($this->config['whois_lookup']) && is_callable($this->config['whois_lookup'])) {
            try {
                $result = call_user_func($this->config['whois_lookup'], $domain, $server);
                if (!is_array($result) || !isset($result['body']) || !is_string($result['body'])) {
                    return ['body' => '', 'complete' => false, 'server' => $server];
                }
                $body = $result['body'];
                $complete = !empty($result['complete']) && strlen($body) <= self::WHOIS_MAX_BYTES;
                return [
                    'body' => substr($body, 0, self::WHOIS_MAX_BYTES),
                    'complete' => $complete,
                    'server' => $server,
                ];
            } catch (Throwable $e) {
                return ['body' => '', 'complete' => false, 'server' => $server];
            }
        }

        // Every outbound socket must use the shared public-address guard. If it
        // is unavailable, fail closed rather than making an unguarded socket.
        if (!function_exists('tc_open_public_socket')) {
            return ['body' => '', 'complete' => false, 'server' => $server];
        }

        $errno = 0;
        $errstr = '';
        try {
            $socket = tc_open_public_socket($server, 43, self::WHOIS_TIMEOUT_SECONDS, $errno, $errstr);
        } catch (Throwable $e) {
            return ['body' => '', 'complete' => false, 'server' => $server];
        }
        if (!$socket) {
            return ['body' => '', 'complete' => false, 'server' => $server];
        }

        stream_set_timeout($socket, self::WHOIS_TIMEOUT_SECONDS);
        $query = $domain . "\r\n";
        $sent = @fwrite($socket, $query);
        $body = '';
        if ($sent === strlen($query)) {
            while (!feof($socket) && strlen($body) <= self::WHOIS_MAX_BYTES) {
                $chunk = @fread($socket, min(4096, self::WHOIS_MAX_BYTES + 1 - strlen($body)));
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
        }
        $meta = stream_get_meta_data($socket);
        $complete = $sent === strlen($query)
            && empty($meta['timed_out'])
            && feof($socket)
            && strlen($body) > 0
            && strlen($body) <= self::WHOIS_MAX_BYTES;
        fclose($socket);

        return [
            'body' => substr($body, 0, self::WHOIS_MAX_BYTES),
            'complete' => $complete,
            'server' => $server,
        ];
    }

    /** Only recognize explicit domain-specific registry outcomes. */
    private function classifyWhois($domain, $response) {
        if (!is_array($response) || empty($response['complete']) || !isset($response['body'])
            || !is_string($response['body']) || $response['body'] === '') {
            return 'unknown';
        }

        $body = $response['body'];
        $quotedDomain = preg_quote($domain, '/');
        $registeredPatterns = [
            '/^\\s*Domain Name:\\s*' . $quotedDomain . '\\s*$/im',
            '/^\\s*Domain:\\s*' . $quotedDomain . '\\s*$/im',
        ];
        foreach ($registeredPatterns as $pattern) {
            if (preg_match($pattern, $body)) {
                return 'registered';
            }
        }

        $noMatchPatterns = [
            '/^\\s*No match for\\s+["\\\']?' . $quotedDomain . '["\\\']?\\s*$/im',
            '/^\\s*(?:Domain|Object) not found\\.?\\s*$/im',
            '/^\\s*NOT FOUND\\s*$/im',
            '/^\\s*No Data Found\\.?\\s*$/im',
            '/^\\s*Status:\\s*AVAILABLE\\s*$/im',
        ];
        foreach ($noMatchPatterns as $pattern) {
            if (preg_match($pattern, $body)) {
                return 'no_match';
            }
        }
        return 'unknown';
    }

    /** Normalize only recognized URL/domain syntax; never strip arbitrary bytes. */
    private function normalizeDomain($value) {
        if (!is_string($value) || strlen($value) > 2048) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            $parts = parse_url($value);
            if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port'])
                || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
                || isset($parts['query']) || isset($parts['fragment'])) {
                return '';
            }
            $value = $parts['host'];
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) || preg_match('/[\\s\\/:?#@]/', $value)) {
            return '';
        }

        $value = strtolower($value);
        if (substr($value, -1) === '.') {
            $value = substr($value, 0, -1);
        }
        if (substr($value, -1) === '.') {
            return '';
        }
        if (strpos($value, 'www.') === 0) {
            $value = substr($value, 4);
        }
        return $value;
    }

    private function isValidSearchLabel($label) {
        return strlen($label) >= 1 && strlen($label) <= 63
            && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) === 1;
    }

    private function isValidDomain($domain) {
        if (!is_string($domain) || strlen($domain) > 253) {
            return false;
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (!$this->isValidSearchLabel($label)) {
                return false;
            }
        }
        $tld = end($labels);
        if (strlen($tld) < 2 || !preg_match('/[a-z]/', $tld)) {
            return false;
        }
        if (!function_exists('tc_is_public_dns_name') || !tc_is_public_dns_name($domain)) {
            return false;
        }
        if (isset($this->config['allowed_domains_regex']) && is_string($this->config['allowed_domains_regex'])
            && @preg_match($this->config['allowed_domains_regex'], $domain) !== 1) {
            return false;
        }
        return true;
    }
}
