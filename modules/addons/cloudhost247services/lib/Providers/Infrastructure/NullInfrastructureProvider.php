<?php
/**
 * The honest null provider: selected when no infrastructure provider is
 * configured (or the requested one is disabled). Every operation fails
 * closed with PROVIDER_NOT_CONFIGURED — the UI surfaces that state instead
 * of inventing servers, IPs or statuses.
 *
 * @package Chs\Providers\Infrastructure
 */

namespace Chs\Providers\Infrastructure;

class NullInfrastructureProvider extends AbstractInfrastructureProvider
{
    public function providerId()
    {
        return 'null';
    }

    public function providerName()
    {
        return 'No provider configured';
    }

    public function providerType()
    {
        return 'null';
    }

    public function isConfigured()
    {
        return false;
    }

    /** @return array{status:string,detail:string} */
    public function healthCheck()
    {
        return ['status' => 'unavailable', 'detail' => 'PROVIDER_NOT_CONFIGURED'];
    }

    public function getAvailableImages()
    {
        throw ProviderFailure::notConfigured();
    }

    public function getImage($imageId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function createServer(array $spec)
    {
        throw ProviderFailure::notConfigured();
    }

    public function startServer($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function stopServer($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function rebootServer($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function shutdownServer($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function deleteServer($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function getServerStatus($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function getServerIp($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function reinstallServer($providerServerId, $imageId, array $options = [])
    {
        throw ProviderFailure::notConfigured();
    }

    public function configureServer($providerServerId, array $config)
    {
        throw ProviderFailure::notConfigured();
    }

    public function enterRescueMode($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function getConsole($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }

    public function getServerMetrics($providerServerId)
    {
        throw ProviderFailure::notConfigured();
    }
}
