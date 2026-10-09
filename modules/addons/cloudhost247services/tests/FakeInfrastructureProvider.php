<?php
/**
 * Deterministic recording double for an infrastructure provider (never
 * referenced by production code).
 *
 * Answers every InfrastructureProviderInterface call from in-memory state and
 * records each mutating call, so suites assert exactly which provider
 * operations the provisioning pipeline performed — without a network.
 *
 * Failure injection: set $failWith[method] = ProviderFailure (or $failOnce)
 * to simulate timeouts, auth failures, unavailable images etc.
 */

use Chs\Providers\Infrastructure\AbstractInfrastructureProvider;
use Chs\Providers\Infrastructure\ProviderFailure;

class FakeInfrastructureProvider extends AbstractInfrastructureProvider
{
    /** @var int */
    public $id;
    /** @var string */
    public $name;
    /** @var bool */
    public $configured = true;
    /** @var array<int,array<string,bool>> method => supported (default: all) */
    public $capsOverride = [];
    /** @var array<string,array> provider_server_id => server state */
    public $servers = [];
    /** @var array<string,array{id:string,name:string,architecture:string,region:string}> */
    public $images = [];
    /** @var array<string,array[]> method => call list */
    public $calls = [];
    /** @var array<string,ProviderFailure> method => failure to throw on next call(s) */
    public $failWith = [];
    /** @var int how many status polls report 'installing' before 'active' */
    public $installingTicks = 0;
    /** @var string|null IP to assign (null = assign automatically) */
    public $assignIp = '203.0.113.10';
    /** @var int */
    private $nextId = 1;
    /** @var int */
    private $polls = [];

    public function __construct($id = 1, $name = 'Fake Cloud')
    {
        parent::__construct([]);
        $this->id = (int) $id;
        $this->name = (string) $name;
    }

    public function providerId()
    {
        return (string) $this->id;
    }

    public function providerName()
    {
        return $this->name;
    }

    public function providerType()
    {
        return 'fake';
    }

    public function isConfigured()
    {
        return $this->configured;
    }

    public function capabilities()
    {
        $caps = parent::capabilities();
        foreach ($caps as $key => $on) {
            $caps[$key] = !array_key_exists($key, $this->capsOverride) ? true : (bool) $this->capsOverride[$key];
        }
        return $caps;
    }

    public function healthCheck()
    {
        return ['status' => 'ok', 'detail' => 'fake provider reachable'];
    }

    private function record($method, array $args)
    {
        $this->calls[$method][] = $args;
        if (isset($this->failWith[$method])) {
            $failure = $this->failWith[$method];
            unset($this->failWith[$method]); // one-shot by default
            throw $failure;
        }
    }

    public function callsTo($method)
    {
        return isset($this->calls[$method]) ? $this->calls[$method] : [];
    }

    public function getAvailableImages()
    {
        $this->record('getAvailableImages', []);
        return array_values($this->images);
    }

    public function getImage($imageId)
    {
        $this->record('getImage', ['image_id' => (string) $imageId]);
        return isset($this->images[(string) $imageId]) ? $this->images[(string) $imageId] : null;
    }

    public function createServer(array $spec)
    {
        $this->record('createServer', [$spec]);
        $id = 'fake-' . $this->nextId++;
        $this->servers[$id] = [
            'status'   => $this->installingTicks > 0 ? 'installing' : 'active',
            'ip'       => $this->installingTicks > 0 ? null : $this->assignIp,
            'image'    => isset($spec['image_id']) ? $spec['image_id'] : (isset($spec['template_id']) ? $spec['template_id'] : ''),
            'hostname' => isset($spec['hostname']) ? $spec['hostname'] : '',
            'spec'     => $spec,
            'deleted'  => false,
        ];
        $this->polls[$id] = 0;
        return [
            'provider_server_id' => $id,
            'ip_address'         => $this->servers[$id]['ip'],
        ];
    }

    public function startServer($providerServerId)
    {
        $this->record('startServer', [$providerServerId]);
        if (isset($this->servers[$providerServerId])) {
            $this->servers[$providerServerId]['status'] = 'active';
        }
    }

    public function stopServer($providerServerId)
    {
        $this->record('stopServer', [$providerServerId]);
        if (isset($this->servers[$providerServerId])) {
            $this->servers[$providerServerId]['status'] = 'stopped';
        }
    }

    public function rebootServer($providerServerId)
    {
        $this->record('rebootServer', [$providerServerId]);
    }

    public function shutdownServer($providerServerId)
    {
        $this->record('shutdownServer', [$providerServerId]);
        if (isset($this->servers[$providerServerId])) {
            $this->servers[$providerServerId]['status'] = 'stopped';
        }
    }

    public function deleteServer($providerServerId)
    {
        $this->record('deleteServer', [$providerServerId]);
        if (isset($this->servers[$providerServerId])) {
            $this->servers[$providerServerId]['deleted'] = true;
            $this->servers[$providerServerId]['status'] = 'deleted';
        }
    }

    public function getServerStatus($providerServerId)
    {
        $this->record('getServerStatus', [$providerServerId]);
        if (!isset($this->servers[$providerServerId])) {
            return 'unknown';
        }
        $server = $this->servers[$providerServerId];
        if ($server['deleted']) {
            return 'unknown';
        }
        $this->polls[$providerServerId] = isset($this->polls[$providerServerId]) ? $this->polls[$providerServerId] + 1 : 1;
        if ($server['status'] === 'installing' && $this->polls[$providerServerId] > $this->installingTicks) {
            $this->servers[$providerServerId]['status'] = 'active';
            $this->servers[$providerServerId]['ip'] = $this->assignIp;
            return 'active';
        }
        return $server['status'];
    }

    public function getServerIp($providerServerId)
    {
        $this->record('getServerIp', [$providerServerId]);
        if (!isset($this->servers[$providerServerId])) {
            return null;
        }
        return $this->servers[$providerServerId]['ip'];
    }

    public function reinstallServer($providerServerId, $imageId, array $options = [])
    {
        $this->record('reinstallServer', [$providerServerId, $imageId, $options]);
        if (isset($this->servers[$providerServerId])) {
            $this->servers[$providerServerId]['image'] = (string) $imageId;
            $this->servers[$providerServerId]['status'] = $this->installingTicks > 0 ? 'installing' : 'active';
            $this->servers[$providerServerId]['ip'] = $this->installingTicks > 0 ? null : $this->assignIp;
            $this->polls[$providerServerId] = 0;
        }
        return ['provider_server_id' => (string) $providerServerId];
    }

    public function configureServer($providerServerId, array $config)
    {
        $this->record('configureServer', [$providerServerId, $config]);
    }

    public function enterRescueMode($providerServerId)
    {
        $this->record('enterRescueMode', [$providerServerId]);
    }

    public function getConsole($providerServerId)
    {
        $this->record('getConsole', [$providerServerId]);
        return ['url' => 'https://console.example.test/' . rawurlencode((string) $providerServerId), 'type' => 'vnc'];
    }

    public function getServerMetrics($providerServerId)
    {
        $this->record('getServerMetrics', [$providerServerId]);
        return ['cpu' => 12.5, 'memory' => 40.0, 'disk' => 22.0, 'bandwidth' => 8.0];
    }
}
