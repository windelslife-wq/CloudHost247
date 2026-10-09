<?php
/**
 * Base class for infrastructure providers: capability reporting plus honest
 * "unsupported operation" failures. Concrete providers override what they
 * genuinely implement; everything else fails closed with a machine code.
 *
 * @package Chs\Providers\Infrastructure
 */

namespace Chs\Providers\Infrastructure;

abstract class AbstractInfrastructureProvider implements InfrastructureProviderInterface
{
    /** @var array<string,mixed> non-secret provider configuration */
    protected $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /** @return array<string,bool> */
    public function capabilities()
    {
        // Conservative default: nothing. Concrete providers declare the
        // operations they genuinely implement — the UI only offers actions
        // the selected provider actually supports.
        return [
            'create'    => false,
            'start'     => false,
            'stop'      => false,
            'reboot'    => false,
            'shutdown'  => false,
            'delete'    => false,
            'status'    => false,
            'ip'        => false,
            'reinstall' => false,
            'rescue'    => false,
            'images'    => false,
            'console'   => false,
            'metrics'   => false,
            'configure' => false,
        ];
    }

    /** @return array{status:string,detail:string} */
    public function healthCheck()
    {
        return ['status' => 'unavailable', 'detail' => 'PROVIDER_NOT_CONFIGURED'];
    }

    /**
     * Guard for optional operations: throws a permanent, machine-coded
     * failure when the provider does not declare the capability.
     */
    protected function requireCapability($capability)
    {
        $caps = $this->capabilities();
        if (empty($caps[$capability])) {
            throw ProviderFailure::unsupported($capability);
        }
    }

    /** Normalize a provider-specific status string to the shared vocabulary. */
    protected function normalizeStatus($status)
    {
        $s = strtolower(trim((string) $status));
        if (in_array($s, ['active', 'running', 'online', 'up', 'powered_on', 'on'], true)) {
            return 'active';
        }
        if (in_array($s, ['stopped', 'halted', 'poweroff', 'off', 'shutoff', 'suspended'], true)) {
            return 'stopped';
        }
        if (in_array($s, ['installing', 'building', 'provisioning', 'pending', 'creating', 'starting'], true)) {
            return 'installing';
        }
        if (in_array($s, ['error', 'failed', 'crashed'], true)) {
            return 'error';
        }
        return 'unknown';
    }
}
