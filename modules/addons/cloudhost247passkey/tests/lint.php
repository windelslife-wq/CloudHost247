<?php
/** Parse and autoload gate for Passkey's shipped Phase 3 and Phase 4 files. */

require_once dirname(__DIR__) . '/autoload.php';

$moduleRoot = dirname(__DIR__);
$files = [];
foreach ([$moduleRoot . '/lib', $moduleRoot . '/install', $moduleRoot . '/tests'] as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (substr($file->getPathname(), -4) === '.php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach (glob($moduleRoot . '/*.php') as $file) {
    $files[] = $file;
}
$files = array_values(array_unique($files));
sort($files);

$bad = [];
foreach ($files as $file) {
    try {
        token_get_all(file_get_contents($file), TOKEN_PARSE);
    } catch (Throwable $e) {
        $bad[] = substr($file, strlen($moduleRoot) + 1) . ': ' . $e->getMessage();
    }
}

$classes = [
    'CloudHost247\\Passkey\\Core\\Db',
    'CloudHost247\\Passkey\\Core\\Schema',
    'CloudHost247\\Passkey\\Core\\Migrator',
    'CloudHost247\\Passkey\\Model\\CredentialRecord',
    'CloudHost247\\Passkey\\Model\\ChallengeRecord',
    'CloudHost247\\Passkey\\Model\\SecurityEventRecord',
    'CloudHost247\\Passkey\\Model\\SettingsRecord',
    'CloudHost247\\Passkey\\Core\\PasskeyLoginCoordinator',
    'CloudHost247\\Passkey\\Core\\PasskeyLoginPolicy',
    'CloudHost247\\Passkey\\Integration\\WhmcsIdentity',
    'CloudHost247\\Passkey\\Integration\\WhmcsAuthHandoff',
];
foreach ($classes as $class) {
    try {
        if (!class_exists($class)) {
            $bad[] = 'autoload failed: ' . $class;
        }
    } catch (Throwable $e) {
        $bad[] = 'autoload failed: ' . $class . ': ' . $e->getMessage();
    }
}

echo 'PHP_FILES=' . count($files) . "\n";
echo 'BAD=' . count($bad) . "\n";
foreach ($bad as $line) {
    echo $line . "\n";
}
exit(count($bad) > 0 ? 1 : 0);
