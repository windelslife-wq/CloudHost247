<?php
/**
 * Seed: agents (registry), tools (registry), prompt v1 per agent, event
 * subscriptions live in code (Subscribers::MAP). Additive only — never
 * deletes or resets operator flags.
 */

use Ch247Ai\Agents\AgentRegistry;
use Ch247Ai\Approval\ApprovalEngine;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Tools\Bootstrap;

return [
    'id' => '0003_seed',
    'description' => 'Seed agent registry, tool registry, prompt versions v1',
    'up' => function () {
        AgentRegistry::sync();

        foreach (Bootstrap::tools() as $tool) {
            if (Db::first('tools', ['slug' => $tool->name]) === null) {
                Db::insert('tools', [
                    'slug' => $tool->name,
                    'description' => $tool->description,
                    'permission' => $tool->permission,
                    'risk_level' => $tool->risk,
                    'requires_approval' => ApprovalEngine::requiresApproval($tool->risk) ? 1 : 0,
                    'enabled' => 1,
                    'created_at' => Clock::now(),
                ]);
            }
        }

        // Roadmap seats have no charter to version — they never run.
        foreach (AgentRegistry::available() as $def) {
            if (Db::first('prompt_versions', ['agent' => $def->slug, 'version' => 1]) === null) {
                Db::insert('prompt_versions', [
                    'agent' => $def->slug,
                    'version' => 1,
                    'system_prompt' => $def->promptRole,
                    'notes' => 'Initial charter from the agent registry (v1).',
                    'created_by' => 0,
                    'created_at' => Clock::now(),
                ]);
            }
        }
    },
];
