<?php
/**
 * Infrastructure provider contract.
 *
 * One interface for every virtualization / bare-metal / cloud provider
 * (OVH, Proxmox, Virtualizor, SolusVM, Hetzner, OpenStack, or a generic
 * JSON API). Implementations are isolated under lib/Providers/Infrastructure
 * and never leak provider-specific identifiers or credentials to callers:
 * services speak in CloudHost247 terms (OS version, architecture, region)
 * and the adapter translates to the provider's image/template ids.
 *
 * Every operation fails honestly — a ProviderFailure with a machine code —
 * when the provider cannot answer. Nothing is ever fabricated.
 *
 * @package Chs\Providers\Infrastructure
 */

namespace Chs\Providers\Infrastructure;

interface InfrastructureProviderInterface
{
    /** @return string stable provider id (row id or 'null') */
    public function providerId();

    /** @return string human name */
    public function providerName();

    /** @return string provider type slug (http, ovh, proxmox, …) */
    public function providerType();

    /** @return bool whether credentials + endpoint are configured */
    public function isConfigured();

    /**
     * @return array<string,bool> capability => supported. Known keys:
     *   create start stop reboot shutdown delete status ip reinstall
     *   images console metrics configure
     */
    public function capabilities();

    /** @return array{status:string,detail:string} ok | degraded | unavailable */
    public function healthCheck();

    /**
     * @return array<int,array{id:string,name:string,architecture:string,region:string}>
     */
    public function getAvailableImages();

    /**
     * @return array|null one image descriptor, or null when it does not exist
     */
    public function getImage($imageId);

    /**
     * Create a server with an OS image deployed.
     *
     * @param array $spec hostname, image_id, template_id, region, plan,
     *                    architecture, ssh_key, metadata
     * @return array{provider_server_id:string, ip_address:?string}
     */
    public function createServer(array $spec);

    public function startServer($providerServerId);

    public function stopServer($providerServerId);

    public function rebootServer($providerServerId);

    public function shutdownServer($providerServerId);

    public function deleteServer($providerServerId);

    /** @return string normalized: active | stopped | installing | error | unknown */
    public function getServerStatus($providerServerId);

    /** @return string|null */
    public function getServerIp($providerServerId);

    /**
     * Reinstall (re-image) a server. Destructive at the provider.
     *
     * @return array{provider_server_id:string}
     */
    public function reinstallServer($providerServerId, $imageId, array $options = []);

    /**
     * Apply initial configuration (hostname, SSH, firewall, agent).
     *
     * @param array $config hostname, ssh_key, monitoring, firewall
     */
    public function configureServer($providerServerId, array $config);

    /** Boot the server into rescue mode. */
    public function enterRescueMode($providerServerId);

    /** @return array{url:string,type:string}|null console access descriptor */
    public function getConsole($providerServerId);

    /**
     * @return array{cpu:?float,memory:?float,disk:?float,bandwidth:?float,raw?:array}
     */
    public function getServerMetrics($providerServerId);
}
