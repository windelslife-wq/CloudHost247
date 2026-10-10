<?php
/**
 * Loads every shipped PHP file through the real runtime: proves each parses,
 * every extended class/interface resolves through the autoloader, and every
 * class body is well formed. Entry points and CLI scripts are tokenised rather
 * than included, because they legitimately die() outside WHMCS.
 */

require_once dirname(__DIR__) . '/autoload.php';

// Phases 11–13 reuse the legacy Cloudflare API boundary and add the App Cloud DNS bridge.
// Sibling addon root: absolute php-wasm mount first, native-checkout relative path fallback (CI runs native PHP).
$ch247CfLint = is_file('/cloudflare/autoload.php') ? '/cloudflare' : dirname(__DIR__) . '/../cloudhost247cloudflare';
if (is_file($ch247CfLint . '/autoload.php')) {
    require_once $ch247CfLint . '/autoload.php';
    foreach ([
        'CloudHost247\\Cloudflare\\Provider\\CloudflareClient',
        'CloudHost247\\Cloudflare\\Provider\\CloudflareApi',
        'CloudHost247\\Cloudflare\\Repository\\AccountRepository',
        'CloudHost247\\Cloudflare\\Service\\IntegrationStatus',
        'CloudHost247\\Cloudflare\\Service\\DnsInventoryService',
        'Ch247Apps\\Domains\\DnsInventoryProviderRegistry',
        'Ch247Apps\\Domains\\CloudflareDnsInventoryAdapter',
    ] as $cloudflareClass) {
        if (!class_exists($cloudflareClass)) {
            throw new RuntimeException('The compatibility class did not autoload: ' . $cloudflareClass);
        }
    }
    if (!interface_exists('Ch247Apps\\Domains\\DnsInventoryProviderInterface')) {
        throw new RuntimeException('The DNS inventory provider contract did not autoload.');
    }
}

$moduleRoot = dirname(__DIR__);

$files = [];
$roots = [
    $moduleRoot . '/lib',
    $moduleRoot . '/install',
    $moduleRoot . '/manifests',
];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (substr($path, -4) === '.php') {
            $files[] = $path;
        }
    }
}
foreach (glob($moduleRoot . '/*.php') as $path) {
    $files[] = $path;
}
foreach (glob($moduleRoot . '/api/*.php') as $path) {
    $files[] = $path;
}
foreach (glob($moduleRoot . '/cron/*.php') as $path) {
    $files[] = $path;
}
foreach (glob($moduleRoot . '/worker/*.php') as $path) {
    $files[] = $path;
}
foreach (glob($moduleRoot . '/agent/*.php') as $path) {
    $files[] = $path;
}
$files = array_values(array_unique($files));
sort($files);
echo 'FILES=' . count($files) . "\n";

$bad = [];
foreach ($files as $file) {
    $short = substr($file, strlen($moduleRoot) + 1);
    $isEntry = preg_match('#^(cloudhost247apps\.php|hooks\.php|api/|cron/|worker/|agent/)#', $short) === 1;
    try {
        if ($isEntry) {
            $tokens = @token_get_all(file_get_contents($file), TOKEN_PARSE);
            unset($tokens);
            continue;
        }
        require_once $file;
    } catch (\Throwable $e) {
        $bad[] = $short . ': ' . $e->getMessage();
    }
}

// Every shipped YAML manifest must parse and validate. `registry.yaml` is the
// wide catalog (a list of entries, not a manifest) and is checked as such.
foreach (glob($moduleRoot . '/manifests/*.yaml') ?: [] as $manifest) {
    try {
        $parsed = Ch247Apps\Core\Yaml::parse(file_get_contents($manifest));
        if (basename($manifest) === 'registry.yaml') {
            $entries = isset($parsed['applications']) && is_array($parsed['applications']) ? $parsed['applications'] : [];
            if ($entries === []) {
                $bad[] = basename($manifest) . ': registry has no applications';
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || empty($entry['id']) || empty($entry['name'])) {
                    $bad[] = basename($manifest) . ': registry entry without id/name';
                    break;
                }
            }
            continue;
        }
        if (!is_array($parsed) || empty($parsed['id'])) {
            $bad[] = basename($manifest) . ': manifest has no id';
        }
    } catch (\Throwable $e) {
        $bad[] = basename($manifest) . ': ' . $e->getMessage();
    }
}

echo 'BAD=' . count($bad) . "\n";
foreach ($bad as $line) {
    echo $line . "\n";
}
exit(count($bad) > 0 ? 1 : 0);
