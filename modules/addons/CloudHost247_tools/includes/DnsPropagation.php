<?php
/**
 * CloudHost247 Tools - DNS wire client for the DNS Propagation Checker.
 *
 * Queries each public resolver directly over UDP (falling back to TCP when an
 * answer is truncated) using PHP sockets. No shell command is run, so the
 * result never depends on whether a `dig` binary exists on the host.
 *
 * Message building, parsing and classification are pure functions so they can
 * be tested offline (tests/DnsPropagationTest.php). Only the transport touches
 * the network.
 *
 * Compatible with PHP 7.4.
 */

if (!defined("WHMCS") && !defined("CLOUDHOST247_TOOLS")) {
    die("This file cannot be accessed directly");
}

/** Record types the propagation checker can query, mapped to wire type codes. */
function CloudHost247_dns_qtypes()
{
    return ['A' => 1, 'AAAA' => 28, 'CNAME' => 5, 'MX' => 15, 'NS' => 2, 'TXT' => 16];
}

/** Public resolvers queried by the propagation checker (name, IPv4 address). */
function CloudHost247_dns_resolvers()
{
    return [
        ['name' => 'Google', 'ip' => '8.8.8.8'],
        ['name' => 'Google 2', 'ip' => '8.8.4.4'],
        ['name' => 'Cloudflare', 'ip' => '1.1.1.1'],
        ['name' => 'Cloudflare 2', 'ip' => '1.0.0.1'],
        ['name' => 'Quad9', 'ip' => '9.9.9.9'],
        ['name' => 'OpenDNS', 'ip' => '208.67.222.222'],
        ['name' => 'OpenDNS 2', 'ip' => '208.67.220.220'],
        ['name' => 'Level3', 'ip' => '209.244.0.3'],
        ['name' => 'Verisign', 'ip' => '64.6.64.6'],
        ['name' => 'DNS.WATCH', 'ip' => '84.200.69.80'],
    ];
}

/**
 * Parse the administrator's comma-separated list of enabled record types.
 * Unknown entries are dropped. An empty or entirely unknown list falls back to
 * every supported type.
 *
 * @return string[]
 */
function CloudHost247_dns_parse_type_list($csv)
{
    $supported = array_keys(CloudHost247_dns_qtypes());
    $selected = [];
    foreach (explode(',', (string) $csv) as $part) {
        $type = strtoupper(trim($part));
        if (in_array($type, $supported, true) && !in_array($type, $selected, true)) {
            $selected[] = $type;
        }
    }
    return $selected ?: $supported;
}

/**
 * Build a DNS query packet (RD set, one question, class IN).
 *
 * @return string|null Binary packet, or null for an invalid name, type or id.
 */
function CloudHost247_dns_build_query($name, $type, $id)
{
    $qtypes = CloudHost247_dns_qtypes();
    if (!isset($qtypes[$type]) || !is_int($id) || $id < 0 || $id > 65535) {
        return null;
    }
    $name = strtolower(rtrim((string) $name, '.'));
    if ($name === '' || strlen($name) > 253) {
        return null;
    }
    $wire = '';
    foreach (explode('.', $name) as $label) {
        $length = strlen($label);
        if ($length < 1 || $length > 63 || !preg_match('/^[a-z0-9_-]+$/', $label)) {
            return null;
        }
        $wire .= chr($length) . $label;
    }
    $wire .= "\x00";

    return pack('n6', $id, 0x0100, 1, 0, 0, 0) . $wire . pack('nn', $qtypes[$type], 1);
}

/**
 * Read a (possibly compressed) domain name from a DNS message.
 *
 * @param int|null $next Set to the offset just after the name in the original
 *                       position (not after any pointer target).
 * @return string|null Lower-case name without trailing dot, or null if malformed.
 */
function CloudHost247_dns_read_name($msg, $off, &$next)
{
    $labels = [];
    $jumped = false;
    $hops = 0;
    $length = strlen($msg);
    $next = null;

    while (true) {
        if ($off < 0 || $off >= $length) {
            return null;
        }
        $byte = ord($msg[$off]);
        if ($byte === 0) {
            if (!$jumped) {
                $next = $off + 1;
            }
            break;
        }
        if (($byte & 0xC0) === 0xC0) {
            if ($off + 1 >= $length || ++$hops > 20) {
                return null;
            }
            if (!$jumped) {
                $next = $off + 2;
            }
            $jumped = true;
            $off = (($byte & 0x3F) << 8) | ord($msg[$off + 1]);
            continue;
        }
        if (($byte & 0xC0) !== 0 || $off + 1 + $byte > $length) {
            return null;
        }
        $labels[] = substr($msg, $off + 1, $byte);
        $off += 1 + $byte;
    }

    return strtolower(implode('.', $labels));
}

/**
 * Decode one resource record's RDATA into a display string.
 *
 * @return string|null Null when the type is not decoded or the data is malformed.
 */
function CloudHost247_dns_rdata($msg, $off, $rdlength, $type)
{
    $rdata = substr($msg, $off, $rdlength);
    switch ($type) {
        case 1: // A
            if (strlen($rdata) !== 4) {
                return null;
            }
            return implode('.', array_map('ord', str_split($rdata)));

        case 28: // AAAA
            if (strlen($rdata) !== 16) {
                return null;
            }
            $ip = inet_ntop($rdata);
            return $ip === false ? null : strtolower($ip);

        case 2:  // NS
        case 5:  // CNAME
            $host = CloudHost247_dns_read_name($msg, $off, $next);
            return $host === null ? null : $host;

        case 15: // MX
            if ($rdlength < 3) {
                return null;
            }
            $pref = unpack('n', substr($msg, $off, 2));
            $host = CloudHost247_dns_read_name($msg, $off + 2, $next);
            return ($pref === false || $host === null) ? null : $pref[1] . ' ' . $host;

        case 16: // TXT: one or more length-prefixed strings
            $text = '';
            $pos = 0;
            while ($pos < $rdlength) {
                $chunk = ord($rdata[$pos]);
                if ($pos + 1 + $chunk > $rdlength) {
                    return null;
                }
                $text .= substr($rdata, $pos + 1, $chunk);
                $pos += 1 + $chunk;
            }
            return $text;
    }
    return null;
}

/**
 * Parse a DNS response for the given query id and type.
 *
 * Returns ['ok' => bool, 'error' => string] on failure. On success it returns
 * 'rcode', 'truncated', 'records' (answers of the queried type) and 'cname'
 * (CNAME targets seen in the answer section).
 */
function CloudHost247_dns_parse_response($msg, $id, $type)
{
    $qtypes = CloudHost247_dns_qtypes();
    if (!isset($qtypes[$type])) {
        return ['ok' => false, 'error' => 'unsupported record type'];
    }
    if (!is_string($msg) || strlen($msg) < 12) {
        return ['ok' => false, 'error' => 'response too short'];
    }

    $header = unpack('nid/nflags/nqd/nan', substr($msg, 0, 8));
    if ($header['id'] !== $id) {
        return ['ok' => false, 'error' => 'id mismatch'];
    }
    if (($header['flags'] & 0x8000) === 0) {
        return ['ok' => false, 'error' => 'not a response'];
    }
    $rcode = $header['flags'] & 0x000F;
    $truncated = ($header['flags'] & 0x0200) !== 0;

    if ($rcode === 3) { // NXDOMAIN: the resolver answered, with no records
        return ['ok' => true, 'rcode' => 3, 'truncated' => $truncated, 'records' => [], 'cname' => []];
    }
    if ($rcode !== 0) {
        return ['ok' => false, 'error' => 'resolver returned RCODE ' . $rcode];
    }

    $length = strlen($msg);
    $off = 12;
    for ($i = 0; $i < $header['qd']; $i++) {
        if (CloudHost247_dns_read_name($msg, $off, $next) === null || $next + 4 > $length) {
            return ['ok' => false, 'error' => 'malformed question section'];
        }
        $off = $next + 4;
    }

    $records = [];
    $cnames = [];
    for ($i = 0; $i < $header['an']; $i++) {
        if (CloudHost247_dns_read_name($msg, $off, $next) === null || $next === null) {
            return ['ok' => false, 'error' => 'malformed answer section'];
        }
        $off = $next;
        if ($off + 10 > $length) {
            return ['ok' => false, 'error' => 'malformed answer section'];
        }
        $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($msg, $off, 10));
        $off += 10;
        if ($off + $rr['rdlength'] > $length) {
            return ['ok' => false, 'error' => 'malformed answer section'];
        }
        if ($rr['class'] === 1) {
            $value = CloudHost247_dns_rdata($msg, $off, $rr['rdlength'], $rr['type']);
            if ($value !== null) {
                if ($rr['type'] === $qtypes[$type]) {
                    $records[] = $value;
                } elseif ($rr['type'] === 5) {
                    $cnames[] = $value;
                }
            }
        }
        $off += $rr['rdlength'];
    }

    return ['ok' => true, 'rcode' => 0, 'truncated' => $truncated, 'records' => $records, 'cname' => $cnames];
}

/**
 * Turn a parsed response into a per-resolver outcome.
 *
 * outcome 'answer'     the resolver returned records
 * outcome 'no_records' the resolver answered but holds no records for this type
 * outcome 'error'      no usable answer (timeout, refusal, malformed, ...)
 */
function CloudHost247_dns_outcome(array $parsed, $type)
{
    if (empty($parsed['ok'])) {
        $error = isset($parsed['error']) ? (string) $parsed['error'] : 'no usable answer';
        return ['outcome' => 'error', 'records' => [], 'error' => $error];
    }
    $records = $parsed['records'];
    // For A/AAAA a CNAME-only answer is still an answer; show the alias target.
    if (empty($records) && ($type === 'A' || $type === 'AAAA') && !empty($parsed['cname'])) {
        foreach ($parsed['cname'] as $target) {
            $records[] = 'CNAME ' . $target;
        }
    }
    return [
        'outcome' => empty($records) ? 'no_records' : 'answer',
        'records' => $records,
        'error' => '',
    ];
}

/**
 * Classify the per-resolver rows into the propagation status.
 *
 * propagated      every resolver answered, and all answers are identical
 * partial         some resolvers answer and others do not, or answers differ
 * not_propagated  resolvers answered and none holds the record
 * unknown         no resolver could be reached
 *
 * @param array $rows Each row has 'outcome' and 'records'.
 */
function CloudHost247_dns_classify(array $rows)
{
    $total = count($rows);
    $answered = 0;
    $noRecords = 0;
    $unreachable = 0;
    $sets = [];
    foreach ($rows as $row) {
        if ($row['outcome'] === 'answer') {
            $answered++;
            $set = $row['records'];
            sort($set, SORT_STRING);
            $sets[implode("\n", $set)] = true;
        } elseif ($row['outcome'] === 'no_records') {
            $noRecords++;
        } else {
            $unreachable++;
        }
    }
    $responded = $answered + $noRecords;
    $distinct = count($sets);

    if ($responded === 0) {
        $status = 'unknown';
        $summary = 'No resolver could be reached, so propagation could not be checked.';
    } elseif ($answered === 0) {
        $status = 'not_propagated';
        $summary = 'No resolver returns this record yet.';
    } elseif ($answered === $total && $distinct === 1) {
        $status = 'propagated';
        $summary = 'All ' . $total . ' resolvers return the same record.';
    } else {
        $status = 'partial';
        $summary = $answered . ' of ' . $total . ' resolvers return the record'
            . ($distinct > 1 ? ', and their answers differ' : '') . '.';
    }
    if ($unreachable > 0 && $responded > 0) {
        $summary .= ' ' . $unreachable . ' resolver' . ($unreachable === 1 ? ' did' : 's did')
            . ' not reply.';
    }

    return [
        'status' => $status,
        'propagated' => $status === 'propagated',
        'summary' => $summary,
        'total' => $total,
        'answering' => $answered,
        'responded' => $responded,
        'distinct_answers' => $distinct,
        'unreachable' => $unreachable,
    ];
}

/**
 * Query one TCP resolver for a truncated answer. Sequential, one connection.
 */
function CloudHost247_dns_query_tcp($ip, $packet, $id, $type, $timeout)
{
    $sock = @stream_socket_client('tcp://' . $ip . ':53', $errno, $errstr, $timeout);
    if ($sock === false) {
        return ['outcome' => 'error', 'records' => [], 'error' => 'could not connect over TCP'];
    }
    stream_set_timeout($sock, (int) $timeout);
    $message = null;
    if (fwrite($sock, pack('n', strlen($packet)) . $packet) === strlen($packet) + 2) {
        $prefix = CloudHost247_dns_read_exact($sock, 2);
        if ($prefix !== null) {
            $unpacked = unpack('n', $prefix);
            $message = CloudHost247_dns_read_exact($sock, $unpacked[1]);
        }
    }
    fclose($sock);

    if ($message === null) {
        return ['outcome' => 'error', 'records' => [], 'error' => 'no complete reply over TCP'];
    }
    return CloudHost247_dns_outcome(CloudHost247_dns_parse_response($message, $id, $type), $type);
}

/** Read exactly $length bytes from a stream, or null if it ends or times out first. */
function CloudHost247_dns_read_exact($sock, $length)
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($sock, $length - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }
    return $buffer;
}

/**
 * Query every resolver in parallel over UDP, then retry truncated answers over TCP.
 *
 * @param array  $servers Entries with an 'ip' key.
 * @param float  $timeout Seconds to wait for UDP replies, shared by all resolvers.
 * @return array Outcome rows keyed like $servers: outcome, records, error.
 */
function CloudHost247_dns_query_servers(array $servers, $name, $type, $timeout = 3.0)
{
    $servers = array_values($servers);
    $results = [];

    if (CloudHost247_dns_build_query($name, $type, 0) === null) {
        foreach ($servers as $i => $server) {
            $results[$i] = ['outcome' => 'error', 'records' => [], 'error' => 'invalid name or record type'];
        }
        return $results;
    }
    if (!function_exists('stream_socket_client')) {
        foreach ($servers as $i => $server) {
            $results[$i] = ['outcome' => 'error', 'records' => [], 'error' => 'sockets are not available on this server'];
        }
        return $results;
    }

    $pending = [];
    $retry = [];
    foreach ($servers as $i => $server) {
        $id = random_int(0, 65535);
        $packet = CloudHost247_dns_build_query($name, $type, $id);
        $sock = @stream_socket_client('udp://' . $server['ip'] . ':53', $errno, $errstr, 2);
        if ($sock === false) {
            $results[$i] = ['outcome' => 'error', 'records' => [], 'error' => 'could not open a connection to this resolver'];
            continue;
        }
        stream_set_blocking($sock, false);
        if (fwrite($sock, $packet) !== strlen($packet)) {
            fclose($sock);
            $results[$i] = ['outcome' => 'error', 'records' => [], 'error' => 'could not send the query'];
            continue;
        }
        $pending[$i] = ['sock' => $sock, 'id' => $id, 'packet' => $packet, 'ip' => $server['ip']];
    }

    $deadline = microtime(true) + $timeout;
    while (!empty($pending) && microtime(true) < $deadline) {
        $read = [];
        foreach ($pending as $entry) {
            $read[] = $entry['sock'];
        }
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 200000);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            continue;
        }
        foreach ($read as $sock) {
            $i = null;
            foreach ($pending as $index => $entry) {
                if ($entry['sock'] === $sock) {
                    $i = $index;
                    break;
                }
            }
            if ($i === null) {
                continue;
            }
            $data = fread($sock, 4096);
            if ($data === false || $data === '') {
                // Readable but empty: a spurious wake-up, or an ICMP error on a
                // connected socket. Keep waiting until the deadline, pausing briefly
                // so an error loop does not spin the CPU. A real reply still counts.
                usleep(20000);
                continue;
            }
            $parsed = CloudHost247_dns_parse_response($data, $pending[$i]['id'], $type);
            if (isset($parsed['error']) && $parsed['error'] === 'id mismatch') {
                continue; // stray or spoofed datagram: keep waiting for the real reply
            }
            fclose($sock);
            if (!empty($parsed['ok']) && !empty($parsed['truncated'])) {
                $retry[$i] = $pending[$i];
            } else {
                $results[$i] = CloudHost247_dns_outcome($parsed, $type);
            }
            unset($pending[$i]);
        }
    }

    foreach ($pending as $i => $entry) {
        fclose($entry['sock']);
        $results[$i] = ['outcome' => 'error', 'records' => [], 'error' => 'no reply before the timeout'];
    }
    foreach ($retry as $i => $entry) {
        $results[$i] = CloudHost247_dns_query_tcp($entry['ip'], $entry['packet'], $entry['id'], $type, 2);
    }

    ksort($results);
    return $results;
}
