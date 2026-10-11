<?php
/**
 * Suite 26 — adapter factory engine resolution.
 *
 * Pins engine resolution through the factory: shipped engines resolve to
 * their adapter class, unknown engines are rejected, and dry-run (fake)
 * mode takes precedence over engine resolution.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Adapters\AdapterFactory;
use Ch247Apps\Adapters\FakeAdapter;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Settings;

Harness::boot();
Harness::relaxRateLimits();

// Real resolution path: no fake installed, dry-run off.
Settings::override('adapters_dry_run', '0');

T::ok('kubernetes is a shipped adapter class', class_exists('Ch247Apps\\Adapters\\KubernetesAdapter'));
T::ok('cpanel is a shipped adapter class', class_exists('Ch247Apps\\Adapters\\CpanelAdapter'));
T::is('kubernetes engine resolves to its adapter', 'Ch247Apps\\Adapters\\KubernetesAdapter',
    get_class(AdapterFactory::forEngine('kubernetes')));
T::is('cpanel engine resolves to its adapter', 'Ch247Apps\\Adapters\\CpanelAdapter',
    get_class(AdapterFactory::forEngine('cpanel')));
T::is('the whm engine alias resolves too', 'Ch247Apps\\Adapters\\CpanelAdapter',
    get_class(AdapterFactory::forEngine('whm')));

try {
    AdapterFactory::forEngine('not-a-real-engine');
    T::ok('unknown engine must be rejected', false);
} catch (ConfigurationException $e) {
    T::is('unknown engine reports ADAPTER_ENGINE_UNSUPPORTED', 'ADAPTER_ENGINE_UNSUPPORTED', $e->errorCode());
}

// Dry-run requires an installed fake; with dry-run on, the fake wins for every engine.
$fake = AdapterFactory::setFake(new FakeAdapter());
Settings::override('adapters_dry_run', '1');
T::ok('dry-run returns the installed fake for kubernetes', AdapterFactory::forEngine('kubernetes') === $fake);
T::ok('dry-run returns the installed fake for an unknown engine', AdapterFactory::forEngine('not-a-real-engine') === $fake);
T::ok('isDryRun is true only with a fake installed', AdapterFactory::isDryRun());

// Turning dry-run off restores real resolution, even though the fake object remains installed.
Settings::override('adapters_dry_run', '0');
T::ok('dry-run off means isDryRun is false', !AdapterFactory::isDryRun());
T::is('real resolution returns once dry-run is off', 'Ch247Apps\\Adapters\\KubernetesAdapter',
    get_class(AdapterFactory::forEngine('kubernetes')));

Settings::override('adapters_dry_run', '0');
Harness::shutdown();
exit(T::summary());
