<?php
/**
 * CloudHost247 App Cloud — adapter factory.
 *
 * Chooses the adapter that implements a manifest's deployment engine. The
 * orchestrator asks for "the adapter for this context" and never branches on
 * engine names itself, which is what keeps Docker, cPanel and Kubernetes logic
 * from bleeding into each other.
 *
 * A fake adapter can be installed globally (tests, and the `adapters_dry_run`
 * setting for a staging platform with no nodes), and any engine can be
 * overridden with a custom implementation.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Servers\AgentClient;

class AdapterFactory
{
    /** Engine name → adapter class. */
    const ENGINES = [
        'docker-compose' => 'Ch247Apps\Adapters\DockerAdapter',
        'docker' => 'Ch247Apps\Adapters\DockerAdapter',
        'cpanel' => 'Ch247Apps\Adapters\CpanelAdapter',
        'whm' => 'Ch247Apps\Adapters\CpanelAdapter',
        'uapi' => 'Ch247Apps\Adapters\CpanelAdapter',
        'kubernetes' => 'Ch247Apps\Adapters\KubernetesAdapter',
    ];

    /** @var FakeAdapter|null */
    private static $fake;

    /** @var array<string,AdapterInterface> */
    private static $overrides = [];

    /** @var AgentClient|null shared client (one signing key lookup per worker run) */
    private static $client;

    /** The adapter for a deployment context. */
    public static function forContext(DeploymentContext $context, Actor $actor = null)
    {
        $engine = $context->manifest->engine();
        // An installation keeps the adapter it was created with: re-deploying a
        // Docker installation must never silently switch engines.
        $recorded = $context->adapterName();
        if ($recorded !== '' && $recorded !== 'docker' && self::classForAdapter($recorded) !== null) {
            $engine = self::classForAdapter($recorded);
        }
        return self::forEngine($engine, $actor);
    }

    /** @param string $engine manifest deployment engine */
    public static function forEngine($engine, Actor $actor = null)
    {
        $engine = strtolower(trim((string) $engine));
        $actor = $actor ?: Actor::system('AdapterFactory');

        if (isset(self::$overrides[$engine])) {
            return self::$overrides[$engine];
        }
        if (self::isDryRun()) {
            return self::$fake;
        }

        if (!isset(self::ENGINES[$engine])) {
            throw new ConfigurationException(
                'No adapter implements the deployment engine "' . $engine . '".',
                ['error_code' => 'ADAPTER_ENGINE_UNSUPPORTED', 'engine' => $engine,
                    'supported' => array_values(array_unique(array_keys(self::ENGINES)))]
            );
        }
        $class = self::ENGINES[$engine];
        if (!class_exists($class)) {
            throw new ConfigurationException(
                'The ' . $engine . ' adapter is not installed on this platform.',
                ['error_code' => 'ADAPTER_NOT_INSTALLED', 'engine' => $engine, 'class' => $class]
            );
        }
        if ($class === 'Ch247Apps\Adapters\DockerAdapter') {
            return new DockerAdapter($actor, self::client($actor));
        }
        return new $class($actor);
    }

    /** The installed fake adapter, when one is in use. */
    public static function fake()
    {
        return self::$fake;
    }

    /** Install (or clear, with null) a fake adapter and enable dry-run mode. */
    public static function setFake(FakeAdapter $fake = null)
    {
        self::$fake = $fake === null ? new FakeAdapter() : $fake;
        Settings::override('adapters_dry_run', '1');
        return self::$fake;
    }

    /** Stop using a fake adapter. */
    public static function clearFake()
    {
        self::$fake = null;
        Settings::override('adapters_dry_run', '0');
    }

    public static function isDryRun()
    {
        return self::$fake !== null && Settings::bool('adapters_dry_run', false);
    }

    /** Replace the implementation for one engine (tests, custom infrastructure). */
    public static function register($engine, AdapterInterface $adapter)
    {
        self::$overrides[strtolower((string) $engine)] = $adapter;
    }

    public static function setClient(AgentClient $client = null)
    {
        self::$client = $client;
    }

    public static function reset()
    {
        self::$fake = null;
        self::$overrides = [];
        self::$client = null;
    }

    /**
     * Which engines this platform can actually deploy right now — used by the
     * admin console and by the install wizard's compatibility matrix.
     */
    public static function availability()
    {
        $out = [];
        foreach (['docker-compose', 'cpanel', 'kubernetes'] as $engine) {
            $class = self::ENGINES[$engine];
            $installed = class_exists($class);
            $enabled = $engine === 'docker-compose'
                ? true
                : ($engine === 'cpanel' ? Settings::bool('cpanel_enabled', true)
                    : Settings::bool('kubernetes_enabled', false));
            $out[$engine] = [
                'engine' => $engine,
                'installed' => $installed,
                'enabled' => $enabled && $installed,
                'dry_run' => self::isDryRun(),
                'reason' => !$installed ? 'ADAPTER_NOT_INSTALLED' : (!$enabled ? 'ADAPTER_DISABLED' : null),
            ];
        }
        return $out;
    }

    /** Adapter name (docker|cpanel|kubernetes) → engine, or null. */
    private static function classForAdapter($adapterName)
    {
        $map = ['docker' => 'docker-compose', 'cpanel' => 'cpanel', 'kubernetes' => 'kubernetes',
            'fake' => 'docker-compose'];
        return isset($map[(string) $adapterName]) ? $map[(string) $adapterName] : null;
    }

    private static function client(Actor $actor)
    {
        if (self::$client === null) {
            self::$client = new AgentClient($actor);
        }
        return self::$client;
    }
}
