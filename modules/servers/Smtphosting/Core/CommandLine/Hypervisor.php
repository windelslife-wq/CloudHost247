<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\CommandLine;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Models\Commands;

/**
 * We need that for calculating interval between operations and for killing process for PHP < 7.1
 * Don't remove it!
 */
declare(ticks=1);

/**
 * Class Hypervisor
 *
 * Manages the lifecycle of long-running command-line processes, providing
 * locking, heartbeat pings, graceful shutdown on signals, and sleep intervals.
 *
 * @package ModulesGarden\ProductsReseller\Server\Smtphosting\Core\CommandLine
 */
class Hypervisor
{
    /**
     * command name
     * @var string|null
     */
    protected $name = null;

    /**
     * command params
     * @var array|null
     */
    protected $params = null;

    /**
     * @var \ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Models\Commands
     */
    protected $entity = null;

    /**
     * Tick interval in seconds for heartbeat pings.
     * @var int
     */
    protected $tickTime = 1;

    /**
     * Maximum sleep interval before re-checking state.
     * @var int
     */
    protected $sleepInterval = 5;

    /**
     * Hypervisor constructor.
     *
     * @param string $name Command name
     * @param array $params Command parameters
     */
    public function __construct($name, $params)
    {
        $this->name   = $name;
        $this->params = $params;
    }

    /**
     * Lock script
     *
     * @return $this
     * @throws \Exception
     */
    public function lock()
    {
        if ($this->isActive()) {
            throw new \Exception('Cannot create lock. Command already running');
        }

        $this->registerTickHandler();
        $this->registerSignalHandler();

        $this->getEntity()->setRunning();

        return $this;
    }

    /**
     * Unlock script
     *
     * @return $this
     */
    public function unlock()
    {
        $this->getEntity()
            ->setStopped()
            ->clearAction();

        return $this;
    }

    /**
     * Update information about running command
     *
     * @return $this
     */
    public function ping()
    {
        $this->getEntity()->ping();

        return $this;
    }

    /**
     * Sleep for the specified interval, pinging periodically.
     *
     * @param int $interval Sleep duration in seconds
     * @return $this
     */
    public function sleep($interval)
    {
        $this->getEntity()->setSleeping();

        $elapsed = 0;
        while ($elapsed < $interval) {
            $sleepTime = min($this->sleepInterval, $interval - $elapsed);
            sleep($sleepTime);
            $elapsed += $sleepTime;
        }

        $this->getEntity()->setRunning();

        return $this;
    }

    /**
     * Check if the process should be stopped.
     *
     * @return bool
     */
    public function shouldBeStopped()
    {
        return $this->getEntity()->shouldBeStopped();
    }

    /**
     * Verify that the script is currently running.
     *
     * @return bool
     * @throws \Exception
     */
    public function checkIfRunning()
    {
        if (!$this->isRunning()) {
            throw new \Exception('Script is not running');
        }

        return true;
    }

    /**
     * Check if the process is stopped.
     *
     * @return bool
     */
    public function isStopped()
    {
        return $this->getEntity()->shouldBeStopped();
    }

    /**
     * Check if the command is currently running.
     *
     * @return bool
     */
    public function isRunning()
    {
        $entity = $this->getEntity();
        return $entity->isRunning();
    }

    /**
     * Returns true if command is running or sleeping
     *
     * @return bool
     */
    public function isActive()
    {
        $entity = $this->getEntity();
        $diff   = time() - $entity->updated_at->timestamp;

        if ($entity->isSleeping() && $diff <= $this->sleepInterval) {
            return true;
        }

        if ($entity->isRunning() && $entity->updated_at->timestamp + $this->tickTime > time()) {
            return true;
        }

        return false;
    }

    /**
     * Get or create the command entity.
     *
     * @param bool $force Force reload from database
     * @return \ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Models\Commands
     */
    protected function getEntity($force = false)
    {
        if ($this->entity && $force === false) {
            return $this->entity;
        }

        $this->entity = Commands::where('name', $this->name)->first();

        if (!$this->entity) {
            $this->entity       = new Commands();
            $this->entity->name = $this->name;
            $this->entity->uuid = uniqid();
            $this->entity->save();
        }

        return $this->entity;
    }

    /**
     * Register the tick handler for periodic pings.
     */
    protected function registerTickHandler()
    {
        $tick = new Tick(function () {
            $this->ping();
        }, 10);
        $tick->start();
    }

    /**
     * Mark process as stopped and exit. This function will be called when you press ctrl c from console
     */
    protected function registerSignalHandler()
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        //works from PHP 7.1
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $exit = function () {
            $this->getEntity()->setStopped();
            exit;
        };

        pcntl_signal(SIGINT, $exit);
        pcntl_signal(SIGHUP, $exit);
        pcntl_signal(SIGUSR1, $exit);
    }
}
