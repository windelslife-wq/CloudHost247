<?php
/**
 * Narrow inbound server-agent heartbeat. This is not the server daemon or a
 * general-purpose agent API: it accepts liveness and, behind an independent
 * switch, a single Linux kernel uptime value. No health claims or jobs.
 */
namespace Ch247Apps\Api;

use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\RateLimiter;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Servers\AgentAuthenticator;
use Ch247Apps\Servers\ServerService;

class AgentHeartbeatIngress
{
    const PATH = '/agent/v1/heartbeat';
    const MAX_BODY_BYTES = 4096;

    /** Raw body is essential: the authenticator signs its exact bytes. */
    public function dispatch($method, $path, $rawBody)
    {
        if (!Settings::bool('agent_heartbeat_ingress_enabled', false)) {
            return self::error(503, 'AGENT_INGRESS_DISABLED', 'Agent heartbeat ingress is disabled.');
        }
        if ($method !== 'POST' || $path !== self::PATH) {
            return self::error(404, 'NOT_FOUND', 'Agent operation not found.');
        }
        if (!is_string($rawBody) || strlen($rawBody) > self::MAX_BODY_BYTES) {
            return self::error(413, 'REQUEST_TOO_LARGE', 'Agent heartbeat exceeds 4 KiB.');
        }
        try {
            $actor = AgentAuthenticator::authenticate($method, self::PATH, $rawBody);
            RateLimiter::hit('agent', $actor->identity());

            // Reject all unknown fields rather than silently accepting arbitrary
            // metrics, claimed server IDs, capacity or operation results.
            $decoded = json_decode($rawBody);
            if (!is_object($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('Heartbeat body must be a JSON object.');
            }
            foreach (array_keys(get_object_vars($decoded)) as $field) {
                if (!in_array($field, ['agent_version', 'uptime_seconds'], true)) {
                    throw new ValidationException('Unsupported heartbeat field.');
                }
            }
            if (property_exists($decoded, 'agent_version') && (!is_string($decoded->agent_version)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,39}$/D', $decoded->agent_version))) {
                throw new ValidationException('Invalid agent version.');
            }
            $reportsUptime = property_exists($decoded, 'uptime_seconds');
            if ($reportsUptime && (!is_int($decoded->uptime_seconds)
                || $decoded->uptime_seconds < 0 || $decoded->uptime_seconds > 2147483647)) {
                throw new ValidationException('Uptime must be a bounded nonnegative integer in seconds.');
            }
            if ($reportsUptime && !Settings::bool('agent_uptime_ingress_enabled', false)) {
                return self::error(503, 'AGENT_UPTIME_DISABLED', 'Agent uptime reporting is disabled.');
            }

            $agent = Db::first('agents', ['id' => (int) $actor->agentId, 'deleted_at' => null]);
            $server = Db::first('servers', ['id' => (int) $actor->serverId, 'deleted_at' => null]);
            // A rotated, unassigned, removed or disabled agent must never
            // resurrect a server by sending a correctly signed heartbeat.
            if (!$agent || !$server || (int) $server['agent_id'] !== (int) $actor->agentId
                || (int) $agent['server_id'] !== (int) $server['id']
                || (string) $server['status'] === ServerService::STATUS_DISABLED) {
                return self::error(403, 'AGENT_INACTIVE', 'Agent is not assigned to an active server.');
            }
            if ($reportsUptime && !(int) $server['monitoring_enabled']) {
                return self::error(403, 'MONITORING_DISABLED', 'Server monitoring is disabled.');
            }
            if ($reportsUptime) {
                RateLimiter::hit('agent.uptime', $actor->identity());
            }
            $payload = ['ip' => Http::clientIp()];
            if (property_exists($decoded, 'agent_version')) {
                $payload['agent_version'] = $decoded->agent_version;
            }
            $beat = Db::transaction(function () use ($actor, $agent, $payload, $reportsUptime, $decoded) {
                $service = new ServerService($actor);
                $beat = $service->heartbeat((string) $agent['agent_uuid'], $payload);
                if ($reportsUptime) {
                    // Only this explicit, node-sourced metric is tagged for
                    // retention. WHMCS supplies the timestamp on arrival.
                    $service->recordNodeUptime((int) $beat['server_id'], $decoded->uptime_seconds);
                }
                return $beat;
            });
            return ['status' => 200, 'body' => ['data' => [
                'status' => $beat['status'], 'recorded_at' => $beat['recorded_at'],
            ]]];
        } catch (AppsException $e) {
            // Authentication failures share the authenticator's generic answer.
            return self::error($e->status(), $e->errorCode(), $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('Agent heartbeat ingress failed.', ['exception' => get_class($e), 'source' => 'agent']);
            return self::error(500, 'INTERNAL_ERROR', 'An unexpected error occurred.');
        }
    }

    private static function error($status, $code, $message)
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }
}
