<?php
/** Initial schema: module infrastructure + agent registry + run pipeline. */

use Ch247Ai\Core\Migrator;

return [
    'id' => '0001_core',
    'description' => 'Core tables: settings, rate limits, role permissions, agents, prompt versions, tools, tasks, runs, run steps, tool calls',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('settings', function ($b) {
            $b->id()->string('setting', 100)->unique('setting')->text('value')->dateTime('updated_at', true);
        });

        $m->createTable('rate_limits', function ($b) {
            $b->id()->string('bucket', 120)->string('action', 80)->integer('hits', false, 0)->dateTime('window_start');
            $b->unique(['bucket', 'action'], 'uq_rl_bucket_action');
        });

        $m->createTable('role_permissions', function ($b) {
            $b->id()->integer('role_id', false, 0)->string('permission', 60);
            $b->unique(['role_id', 'permission'], 'uq_rp_role_perm');
        });

        $m->createTable('agents', function ($b) {
            $b->id()->string('agent', 60)->unique('agent')->string('name', 120)->text('description')
                ->string('run_mode', 20)->text('tool_allowlist')->string('model_profile', 20, true)
                ->integer('prompt_version', false, 1)->integer('risk_level', false, 1)->integer('enabled', false, 1)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
        });

        $m->createTable('prompt_versions', function ($b) {
            $b->id()->string('agent', 60)->integer('version', false, 1)->text('system_prompt')->string('notes', 255, true)
                ->integer('created_by', false, 0)->dateTime('created_at', true);
            $b->unique(['agent', 'version'], 'uq_pv_agent_version');
        });

        $m->createTable('tools', function ($b) {
            $b->id()->string('slug', 60)->unique('slug')->text('description')->string('permission', 60)
                ->string('risk_level', 30)->integer('requires_approval', false, 0)->integer('enabled', false, 1)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
        });

        $m->createTable('tasks', function ($b) {
            $b->id()->string('agent', 60)->string('origin', 20)->integer('requested_by_admin_id', false, 0)
                ->integer('client_id', true)->string('subject', 190, true)->text('input')
                ->string('status', 30, false, 'pending')->string('priority', 10, false, 'normal')
                ->dateTime('created_at', true)->dateTime('finished_at', true);
            $b->index('agent');
            $b->index('status');
        });

        $m->createTable('runs', function ($b) {
            $b->id()->integer('task_id', true)->string('agent', 60)->string('source', 20, false, 'interactive')
                ->string('status', 30, false, 'running')->string('actor_type', 10, false, 'system')->integer('actor_id', false, 0)
                ->text('input')->text('output')->text('citations')->integer('tokens_in', false, 0)->integer('tokens_out', false, 0)
                ->dateTime('started_at', true)->dateTime('finished_at', true);
            $b->index('agent');
            $b->index('status');
            $b->index('task_id');
        });

        $m->createTable('run_steps', function ($b) {
            $b->id()->integer('run_id', false, 0)->integer('seq', false, 0)->string('type', 10)
                ->text('payload_ref')->dateTime('created_at', true);
            $b->index('run_id');
        });

        $m->createTable('tool_calls', function ($b) {
            $b->id()->integer('run_id', false, 0)->string('agent', 60)->string('tool', 60)->text('arguments')
                ->string('result_digest', 64)->integer('permission_checked', false, 0)->integer('approval_id', true)
                ->string('status', 20, false, 'executed')->string('error', 400, true)->integer('duration_ms', false, 0)
                ->dateTime('created_at', true);
            $b->index('run_id');
            $b->index('agent');
        });
    },
];
