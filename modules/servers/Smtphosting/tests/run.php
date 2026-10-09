<?php

/**
 * Smtphosting unit/regression tests (no WHMCS, no PHPUnit required).
 *
 * Usage:   php tests/run.php
 * Exit code: 0 when every test passes, 1 otherwise.
 *
 * Compatible with PHP 7.2+. See tests/README.md for the verification plan
 * (including how this suite was run when no local PHP binary is available).
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Application;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppController as BaseAppController;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers\Addon;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers\Api;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers\Cron;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers\Hooks;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers\Http;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Instances\Api\ApiController;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Interfaces\DefaultController;

class SmtphostingAssertionFailed extends \Exception
{
}

$GLOBALS['smtp_tests'] = [];

function smtp_test($name, $fn)
{
    $GLOBALS['smtp_tests'][$name] = $fn;
}

function smtp_assert($condition, $message)
{
    if (!$condition) {
        throw new SmtphostingAssertionFailed($message);
    }
}

function smtp_assert_throws($expectedClass, $messageFragment, $fn)
{
    try {
        $fn();
    } catch (\Throwable $t) {
        if (!($t instanceof $expectedClass)) {
            throw new SmtphostingAssertionFailed(
                'expected ' . $expectedClass . ', got ' . get_class($t) . ': ' . $t->getMessage()
            );
        }
        if ($messageFragment !== null && strpos($t->getMessage(), $messageFragment) === false) {
            throw new SmtphostingAssertionFailed(
                'exception message does not contain "' . $messageFragment . '": ' . $t->getMessage()
            );
        }
        return;
    }
    throw new SmtphostingAssertionFailed('expected ' . $expectedClass . ' to be thrown, nothing was thrown');
}

/**
 * Subclass that pins the module name so routing can be tested without WHMCS
 * (the real getModuleName() reads WHMCS-backed application parameters).
 */
class SmtphostingTestApplication extends Application
{
    public function getModuleName()
    {
        return 'Smtphosting';
    }
}

/* ------------------------------------------------------------------------ *
 * Stub AppControllers (Api, Cron, Hooks): must be real, fail-closed controllers
 * ------------------------------------------------------------------------ */

$stubControllers = [
    'Api'   => Api::class,
    'Cron'  => Cron::class,
    'Hooks' => Hooks::class,
];

foreach ($stubControllers as $label => $class) {
    smtp_test("$label extends the abstract AppController (runController is available)", function () use ($class) {
        smtp_assert(is_subclass_of($class, BaseAppController::class),
            "$class must extend " . BaseAppController::class . ' so Application::run() can call runController()');
        smtp_assert(method_exists($class, 'runController'), "$class::runController() must exist");
    });

    smtp_test("$label::runController fails closed with an explicit RuntimeException", function () use ($class) {
        smtp_assert_throws(\RuntimeException::class, 'not implemented in this build', function () use ($class) {
            (new $class())->runController('Smtphosting_api', []);
        });
    });

    smtp_test("$label::getControllerInstanceClass never silently returns null", function () use ($class) {
        smtp_assert_throws(\RuntimeException::class, 'not implemented in this build', function () use ($class) {
            (new $class())->getControllerInstanceClass('Smtphosting_api', []);
        });
    });
}

/* ------------------------------------------------------------------------ *
 * Instances\Api\ApiController: must load and satisfy DefaultController
 * ------------------------------------------------------------------------ */

smtp_test('ApiController loads and implements DefaultController', function () {
    $ref = new \ReflectionClass(ApiController::class);
    smtp_assert($ref->implementsInterface(DefaultController::class),
        'ApiController must implement ' . DefaultController::class);
});

smtp_test('ApiController::execute fails closed with an explicit RuntimeException', function () {
    smtp_assert_throws(\RuntimeException::class, 'not implemented in this build', function () {
        (new ApiController())->execute();
    });
});

smtp_test('ApiController::runExecuteProcess fails closed with an explicit RuntimeException', function () {
    smtp_assert_throws(\RuntimeException::class, 'not implemented in this build', function () {
        (new ApiController())->runExecuteProcess();
    });
});

/* ------------------------------------------------------------------------ *
 * Regression: working controllers and routing are unchanged
 * ------------------------------------------------------------------------ */

smtp_test('Regression: Addon controller is an AppController and exposes runController', function () {
    smtp_assert(is_subclass_of(Addon::class, BaseAppController::class), 'Addon must extend AppController');
    smtp_assert(method_exists(Addon::class, 'runController'), 'Addon::runController() must exist');
});

smtp_test('Regression: Application routes api -> Api, output/clientarea -> Http, others -> Addon', function () {
    $app = new SmtphostingTestApplication();

    smtp_assert($app->getControllerClass('Smtphosting_api') === Api::class, 'Smtphosting_api must route to Api');
    smtp_assert($app->getControllerClass('Smtphosting_output') === Http::class, 'Smtphosting_output must route to Http');
    smtp_assert($app->getControllerClass('Smtphosting_clientarea') === Http::class, 'Smtphosting_clientarea must route to Http');
    smtp_assert($app->getControllerClass('Smtphosting_CreateAccount') === Addon::class, 'other callers must route to Addon');
});

/* ------------------------------------------------------------------------ *
 * Regression: misspelled exception classes (typo guard over module source)
 *
 * A catch clause with a fully-qualified, namespace-less class name such as
 * "catch (\Excpetion $e)" never matches and silently lets exceptions escape.
 * Every such name must be a real global class.
 * ------------------------------------------------------------------------ */

smtp_test('Regression: every fully-qualified global catch type in module source is a real class', function () {
    $moduleRoot = dirname(__DIR__);
    $dirs = [$moduleRoot . DS . 'Core', $moduleRoot . DS . 'App', $moduleRoot . DS . 'Helpers', $moduleRoot . DS . 'Actions', $moduleRoot . DS . 'Calls', $moduleRoot . DS . 'Submodules'];
    $unknown = [];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (substr($file->getPathname(), -4) !== '.php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (preg_match_all('/catch\s*\(\s*\\\\([A-Za-z_][A-Za-z0-9_]*)\s+\$/', $source, $matches)) {
                foreach (array_unique($matches[1]) as $name) {
                    if (!class_exists($name, false) && !interface_exists($name, false)) {
                        $unknown[] = $name . ' in ' . str_replace($moduleRoot . DS, '', $file->getPathname());
                    }
                }
            }
        }
    }

    smtp_assert(count($unknown) === 0, 'unknown global catch types: ' . implode('; ', $unknown));
});

/* ------------------------------------------------------------------------ *
 * Runner
 * ------------------------------------------------------------------------ */

$passed = 0;
$failed = [];
foreach ($GLOBALS['smtp_tests'] as $name => $fn) {
    try {
        $fn();
        $passed++;
        echo "[PASS] $name\n";
    } catch (\Throwable $t) {
        $failed[] = $name;
        echo "[FAIL] $name\n       " . get_class($t) . ': ' . $t->getMessage() . "\n";
    }
}

$total = $passed + count($failed);
echo "\n" . "Smtphosting tests: $passed/$total passed, " . count($failed) . " failed (PHP " . PHP_VERSION . ")\n";

exit(count($failed) === 0 ? 0 : 1);
