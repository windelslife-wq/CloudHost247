<?php
/**
 * Contract for a real infrastructure-provider integration.
 *
 * Implementations must recover repeated create/delete calls for a supplied
 * idempotency key, validate every response, enforce TLS, and never log credentials.
 * Without a provider-enforced atomic create-idempotency primitive, an ambiguous
 * POST must fail terminally for manual reconciliation, not automatically retry
 * a billable create based solely on a possibly stale lookup.
 * A provider must be explicitly registered with ProviderRegistry; there is no
 * default/fake adapter in production.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Infrastructure;

interface InfrastructureProviderInterface
{
    /** Stable slug, e.g. `hetzner` or `ovhcloud`. */
    public function key();

    /** Human-readable provider name. */
    public function name();

    /** Boolean map keyed by `server.create`, `server.get`, `server.delete`, etc. */
    public function capabilities();

    /** Return true only after the provider has authenticated the supplied account. */
    public function verifyCredentials(array $credentials, array $accountConfig);

    /**
     * Create or recover the resource associated with this idempotency key.
     * Return promptly with a normalized resource id/state when the provider is
     * asynchronous; the control plane will poll in a later worker job.
     */
    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey);

    /** Return a normalized resource snapshot; absence must be represented explicitly. */
    public function getServer(array $credentials, array $accountConfig, $providerServerId);

    /** Delete or recover the delete operation associated with this key. */
    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey);

    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey);

    public function powerOnServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey);

    public function powerOffServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey);

    public function rebuildServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey);

    public function resizeServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey);

    public function createSnapshot(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey);

    public function deleteSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey);

    public function restoreSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey);

    /** Provider-sourced metrics only; never return guessed or synthetic data. */
    public function getMetrics(array $credentials, array $accountConfig, $providerServerId);
}
