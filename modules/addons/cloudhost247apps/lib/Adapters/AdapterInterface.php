<?php
/**
 * CloudHost247 App Cloud — adapter contract.
 *
 * Every deployment target speaks this interface. Docker, cPanel/WHM, Kubernetes
 * and the fake adapter used in tests are interchangeable behind it, and the
 * orchestrator never contains a single `if ($engine === 'docker')` branch: it
 * asks the adapter for a plan, runs the plan step by step, and asks the same
 * adapter to compensate when a step fails.
 *
 * Contract rules:
 *   • plan() returns an ordered list of steps, each with a stable key, a
 *     human-readable name, whether it is reversible and whether it creates a
 *     resource that must be recorded in the created-resources ledger
 *   • executeStep() must be idempotent: the worker may retry a step after a
 *     crash, and re-running it must not duplicate infrastructure
 *   • executeStep() returns an array describing what it created (resource type,
 *     name, metadata) so the orchestrator can record and later roll it back
 *   • nothing here touches Docker/WHM/Kubernetes directly from a web request:
 *     adapters run inside the worker only
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Catalog\Manifest;

interface AdapterInterface
{
    /** Stable adapter name: docker, cpanel, kubernetes, fake. */
    public function name();

    /** The deployment engine this adapter implements (manifest deployment.engine). */
    public function engine();

    /** Can this adapter deploy the manifest onto that server? */
    public function supports(Manifest $manifest, array $server);

    /**
     * The ordered step plan for a deployment context.
     *
     * @return array[] [['key','name','reversible','creates_resource','timeout'], …]
     */
    public function plan(DeploymentContext $context);

    /**
     * Execute one step.
     *
     * @return array{output?: mixed, resources?: array[], message?: string}
     */
    public function executeStep($key, DeploymentContext $context);

    /**
     * Compensate one step (rollback). Must be safe to call when the step never
     * completed, and must not throw for a resource that is already gone.
     *
     * @return array{removed?: array[], message?: string}
     */
    public function rollbackStep($key, DeploymentContext $context);

    /** Real runtime state: container/account status, or `unknown` when unverifiable. */
    public function status(DeploymentContext $context);

    /** Recent log lines for one service (or the whole project). */
    public function logs(DeploymentContext $context, $service = null, $lines = 200);

    /** Health probe result: ['state' => healthy|unhealthy|unknown, …]. */
    public function health(DeploymentContext $context);

    /** Resource usage for the deployment: ['cpu_percent','memory_used_mb',…] or nulls. */
    public function metrics(DeploymentContext $context);

    /** Stop the workload without removing data. */
    public function stop(DeploymentContext $context);

    /** Start a stopped workload. */
    public function start(DeploymentContext $context);

    /** Restart the workload. */
    public function restart(DeploymentContext $context);

    /** Remove everything this deployment created, including volumes when asked. */
    public function destroy(DeploymentContext $context, $removeData = true);
}
