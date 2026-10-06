<?php
/**
 * Reference WHOIS provider: the public whois protocol (TCP/43), starting at
 * the IANA bootstrap server and following the registry referral. No external
 * credentials needed; registry-side GDPR redaction is preserved verbatim —
 * this provider never sees data the registry chose to withhold.
 *
 * Sockets are injectable so the test suite can replay recorded responses
 * without touching the network.
 *
 * @package Chs\Providers\Whois
 */

namespace Chs\Providers\Whois;

use Chs\Core\ProviderException;
use Chs\Core\Settings;

class SocketWhoisProvider implements WhoisProviderInterface
{
    /** @var callable|null fn(host, port, timeout): resource — test seam */
    private $connector;

    /** @var int */
    private $maxBytes;

    /**
     * @param callable|null $connector receives ($host, $port, $timeoutSeconds) and
     *                                 returns a stream resource (or throws).
     */
    public function __construct(callable $connector = null, $maxBytes = null)
    {
        $this->connector = $connector;
        $this->maxBytes = $maxBytes !== null ? (int) $maxBytes : Settings::int('whois_max_response_bytes', 262144);
    }

    public function lookup($domain, $tld)
    {
        $timeout = max(3, Settings::int('whois_timeout_seconds', 8));

        // Thick registries (com/net) need the registrar referral followed;
        // most thin registries answer fully at the TLD server.
        $server = $this->bootstrapServer($tld, $timeout);
        $first = $this->query($server, $domain, $timeout);

        $response = $first;
        $usedServer = $server;

        $extraServer = $this->extractReferralServer($first);
        if ($extraServer !== null && stripos($extraServer, $server) === false) {
            try {
                $second = $this->query($extraServer, $domain, $timeout);
                if (trim($second) !== '') {
                    $response = $second;
                    $usedServer = $extraServer;
                }
            } catch (ProviderException $e) {
                // Keep the registry-level answer — it is still useful.
            }
        }

        return ['server' => $usedServer, 'raw' => $response];
    }

    /**
     * Ask whois.iana.org which server owns this TLD.
     */
    protected function bootstrapServer($tld, $timeout)
    {
        if (preg_match('/^[a-z0-9.-]+$/', $tld) !== 1) {
            throw new ProviderException('Invalid TLD for WHOIS bootstrap.');
        }
        $answer = $this->query('whois.iana.org', $tld, $timeout);
        if (preg_match('/^refer:\s*(\S+)$/mi', $answer, $m)) {
            return strtolower(trim($m[1]));
        }
        // Reasonable conventional fallback; many registries follow this naming.
        return 'whois.' . explode('.', $tld)[0];
    }

    /**
     * One whois-protocol conversation.
     *
     * @return string raw response body
     */
    protected function query($server, $queryString, $timeout)
    {
        if ($this->connector) {
            $fp = call_user_func($this->connector, $server, 43, $timeout);
        } else {
            $fp = @fsockopen($server, 43, $errno, $errstr, $timeout);
        }
        if (!$fp) {
            throw new ProviderException('The WHOIS server for this extension is unreachable right now.');
        }
        stream_set_timeout($fp, $timeout);
        fwrite($fp, $queryString . "\r\n");
        $body = '';
        while (!feof($fp)) {
            $chunk = fgets($fp, 4096);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) >= $this->maxBytes) {
                $body = substr($body, 0, $this->maxBytes);
                break;
            }
        }
        fclose($fp);
        return $body;
    }

    /** Registrar Data Access / thick-registry handoff. */
    protected function extractReferralServer($raw)
    {
        // Registry responses indent their fields (e.g. "   Registrar WHOIS Server:")
        if (preg_match('/^\s*(?:Registrar WHOIS Server|ReferralServer):\s*(\S+)\s*$/mi', $raw, $m)) {
            $server = strtolower(trim(preg_replace('#^r?whois://#', '', $m[1])));
            return preg_match('/^[a-z0-9.-]+$/', $server) === 1 ? $server : null;
        }
        return null;
    }
}
