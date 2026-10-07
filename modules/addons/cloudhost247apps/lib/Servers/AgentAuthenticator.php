<?php
/**
 * CloudHost247 App Cloud — inbound agent authentication.
 *
 * Every request an agent sends (heartbeat, metrics, operation results, log tails)
 * must prove four things before the platform acts on it:
 *
 *   1. identity  — a registered agent UUID bound to a server, in an active state
 *   2. freshness — a timestamp inside the configured TTL window
 *   3. integrity — an HMAC-SHA256 signature over METHOD\npath\ntimestamp\nnonce\n
 *                  sha256(body), computed with that agent's sealed shared secret
 *   4. uniqueness — a nonce that has not been seen inside the window, so a
 *                  captured request cannot be replayed
 *
 * Failure is recorded (audit + agent rejected_count) and answered with a generic
 * 401: the detail goes to the log, not to the caller, so the endpoint cannot be
 * used to enumerate agents or probe the signing scheme.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Servers;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthenticationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;

class AgentAuthenticator
{
    const HEADER_UUID      = 'X-CH247-Agent-Uuid';
    const HEADER_TIMESTAMP = 'X-CH247-Timestamp';
    const HEADER_NONCE     = 'X-CH247-Nonce';
    const HEADER_SIGNATURE = 'X-CH247-Signature';
    const HEADER_VERSION   = 'X-CH247-Agent-Version';

    /** Secret context: binds the sealed HMAC key to this agent's UUID. */
    const SECRET_CONTEXT = 'agents.shared_secret|';

    /**
     * Authenticate the current request.
     *
     * @param string $method HTTP method
     * @param string $path   the path the signature was computed over
     * @param string $body   raw request body ('' when there is none)
     * @return Actor the agent actor, bound to its server
     * @throws AuthenticationException
     */
    public static function authenticate($method, $path, $body = null)
    {
        $body = $body === null ? Http::rawBody() : (string) $body;
        $uuid = trim((string) Http::header(self::HEADER_UUID));
        $timestamp = trim((string) Http::header(self::HEADER_TIMESTAMP));
        $nonce = trim((string) Http::header(self::HEADER_NONCE));
        $signature = trim((string) Http::header(self::HEADER_SIGNATURE));

        if ($uuid === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return self::reject(null, 'missing_header', 'Agent identity headers are required.');
        }
        if (strlen($nonce) < 16 || strlen($nonce) > 128) {
            return self::reject(null, 'bad_nonce', 'The nonce must be 16–128 characters of random data.');
        }
        if (!ctype_digit($timestamp)) {
            return self::reject(null, 'bad_timestamp', 'The timestamp must be a unix epoch value.');
        }

        $agent = Db::first('agents', ['agent_uuid' => $uuid, 'deleted_at' => null]);
        if (!$agent) {
            return self::reject(null, 'unknown_agent', 'That agent is not registered.');
        }
        if ($agent['status'] === 'revoked' || $agent['status'] === 'suspended') {
            return self::reject($agent, 'agent_' . $agent['status'], 'That agent is ' . $agent['status'] . '.');
        }

        $ttl = Settings::int('agent_request_ttl_seconds', 120);
        $skew = abs(Clock::timestamp() - (int) $timestamp);
        if ($skew > $ttl) {
            return self::reject($agent, 'timestamp_skew', 'The request timestamp is outside the accepted window.');
        }

        $secret = self::secretFor($agent);
        if ($secret === null) {
            return self::reject($agent, 'no_secret', 'That agent has no shared secret on record.');
        }

        $expected = Crypto::signMessage($secret, strtoupper((string) $method), (string) $path, $body,
            (int) $timestamp, $nonce);
        if (!Crypto::hashEquals($expected, $signature)) {
            return self::reject($agent, 'bad_signature', 'The request signature does not match.');
        }

        if (!self::claimNonce((int) $agent['id'], $nonce, $ttl)) {
            return self::reject($agent, 'replayed_nonce', 'That nonce has already been used.');
        }

        Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('agents'))
            . ' SET request_count = request_count + 1, last_seen_at = ?, last_seen_ip = ?, updated_at = ? WHERE id = ?',
            [Clock::now(), Str::clip(Http::clientIp(), 45), Clock::now(), (int) $agent['id']]);

        $version = trim((string) Http::header(self::HEADER_VERSION));
        return Actor::agent((int) $agent['id'], (int) $agent['server_id'], (string) $agent['name'], [
            'ip' => Http::clientIp(),
            'userAgent' => $version !== '' ? 'ch247-agent/' . $version : Http::userAgent(),
            'authMethod' => 'agent_hmac',
            'meta' => ['agent_uuid' => $uuid],
        ]);
    }

    /**
     * Sign an outbound request to an agent (used by AgentClient and by the
     * install script that shows an operator how the agent must sign).
     *
     * @return array{headers: array<string,string>, timestamp: int, nonce: string}
     */
    public static function sign(array $agent, $method, $path, $body = '')
    {
        $secret = self::secretFor($agent);
        if ($secret === null) {
            throw new AuthenticationException('That agent has no shared secret on record.', ['reason' => 'no_secret']);
        }
        $timestamp = Clock::timestamp();
        $nonce = Crypto::randomToken(16);
        $signature = Crypto::signMessage($secret, strtoupper((string) $method), (string) $path, (string) $body,
            $timestamp, $nonce);

        return [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
            'headers' => [
                self::HEADER_UUID => (string) $agent['agent_uuid'],
                self::HEADER_TIMESTAMP => (string) $timestamp,
                self::HEADER_NONCE => $nonce,
                self::HEADER_SIGNATURE => $signature,
                self::HEADER_VERSION => CH247APPS_VERSION,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    /** The agent's shared secret, unsealed. @internal */
    public static function secretFor(array $agent)
    {
        if (empty($agent['encrypted_shared_secret'])) {
            return null;
        }
        return Crypto::tryDecrypt($agent['encrypted_shared_secret'], self::SECRET_CONTEXT . $agent['agent_uuid']);
    }

    /**
     * Remember a nonce for the length of the TTL window.
     *
     * The unique index is the replay guard: a second insert of the same nonce
     * fails, which is the only race-free way to do this without a lock.
     */
    private static function claimNonce($agentId, $nonce, $ttl)
    {
        $now = Clock::now();
        try {
            Db::insert('agent_nonces', [
                'nonce_hash' => hash('sha256', (string) $agentId . '|' . $nonce),
                'agent_id' => (int) $agentId,
                'direction' => 'inbound',
                'expires_at' => Clock::at($ttl * 2),
                'created_at' => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Drop nonces that can no longer be replayed (cron). */
    public static function pruneNonces()
    {
        return Db::delete('agent_nonces', ['expires_at' => ['<', Clock::now()]]);
    }

    private static function reject($agent, $reason, $detail)
    {
        $agentId = $agent ? (int) $agent['id'] : 0;
        $serverId = $agent ? (int) $agent['server_id'] : null;

        if ($agentId) {
            Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('agents'))
                . ' SET rejected_count = rejected_count + 1, updated_at = ? WHERE id = ?',
                [Clock::now(), $agentId]);
        }

        Logger::warning('Agent request rejected.', [
            'agent_id' => $agentId, 'agent_uuid' => $agent ? $agent['agent_uuid'] : Http::header(self::HEADER_UUID),
            'reason' => $reason, 'detail' => $detail, 'ip' => Http::clientIp(),
            'path' => isset($_SERVER['REQUEST_URI']) ? Str::clip((string) $_SERVER['REQUEST_URI'], 255) : null,
            'source' => 'agent',
        ]);
        Audit::record(Actor::system('AgentAuthenticator'), Audit::AGENT_REQUEST_REJECTED, [
            'resource_type' => 'agent', 'resource_id' => $agentId ?: null, 'server_id' => $serverId,
            'metadata' => ['reason' => $reason, 'ip' => Http::clientIp()],
            'severity' => in_array($reason, ['bad_signature', 'replayed_nonce', 'unknown_agent'], true)
                ? 'error' : 'warning',
        ]);

        // One generic answer for every failure mode.
        throw new AuthenticationException('Agent authentication failed.', ['reason' => $reason]);
    }
}
