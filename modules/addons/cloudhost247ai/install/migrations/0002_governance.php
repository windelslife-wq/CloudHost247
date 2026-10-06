<?php
/** Governance + knowledge + memory + reports + usage. */

use Ch247Ai\Core\Migrator;

return [
    'id' => '0002_governance',
    'description' => 'Approvals, events, knowledge sources/chunks, memories, reports, usage daily, audit log',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('approvals', function ($b) {
            $b->id()->integer('run_id', true)->string('agent', 60)->string('tool', 60)->text('arguments')
                ->string('risk', 30)->text('reason')->text('evidence', true)->string('status', 20, false, 'pending')
                ->string('requested_by_type', 10, false, 'agent')->integer('requested_by_id', false, 0)
                ->integer('decided_by', true)->dateTime('decided_at', true)->string('decision_note', 1000, true)
                ->dateTime('executed_at', true)->dateTime('created_at', true);
            $b->index('status');
            $b->index('agent');
        });

        $m->createTable('events', function ($b) {
            $b->id()->string('event_type', 40)->string('entity_type', 30, false, 'other')->integer('entity_id', false, 0)
                ->integer('client_id', true)->text('payload')->string('status', 20, false, 'pending')
                ->integer('attempts', false, 0)->text('error', true)->dateTime('created_at', true)->dateTime('processed_at', true);
            $b->index('status');
            $b->index('event_type');
        });

        $m->createTable('knowledge_sources', function ($b) {
            $b->id()->string('type', 20, false, 'doc')->string('title', 190)->string('tags', 190, true)
                ->string('visibility', 10, false, 'admin')->integer('version', false, 1)->string('checksum', 64)
                ->string('status', 20, false, 'active')->dateTime('indexed_at', true)->dateTime('created_at', true)->dateTime('updated_at', true);
            $b->index('status');
        });

        $m->createTable('knowledge_chunks', function ($b) {
            $b->id()->integer('source_id', false, 0)->integer('seq', false, 1)->text('content')
                ->string('content_hash', 64)->text('embedding', true)->integer('token_count', false, 0);
            $b->index('source_id');
            $b->index('content_hash');
        });

        $m->createTable('memories', function ($b) {
            $b->id()->string('agent', 60)->string('scope', 20, false, 'short')->string('subject_type', 30, false, 'other')
                ->integer('subject_id', false, 0)->integer('client_id', true)->text('content')->string('evidence_ref', 120, true)
                ->dateTime('expires_at', true)->dateTime('created_at', true);
            $b->index('agent');
            $b->index('subject_type');
        });

        $m->createTable('reports', function ($b) {
            $b->id()->string('type', 20, false, 'daily')->date('period_start')->date('period_end')->text('metric_pack')
                ->text('sections')->text('narrative', true)->string('model', 60, true)->integer('metrics_only', false, 0)
                ->dateTime('created_at', true);
            $b->index('type');
        });

        $m->createTable('usage_daily', function ($b) {
            $b->id()->date('day')->string('agent', 60)->integer('runs', false, 0)->integer('tokens_in', false, 0)
                ->integer('tokens_out', false, 0)->integer('cost_micros', false, 0);
            $b->unique(['day', 'agent'], 'uq_ud_day_agent');
        });

        $m->createTable('audit_log', function ($b) {
            $b->id()->string('actor_type', 10)->integer('actor_id', false, 0)->string('actor_label', 190, true)
                ->string('action', 60)->string('entity_type', 60, false, 'system')->integer('entity_id', false, 0)
                ->text('context')->string('prev_hash', 64)->string('record_hash', 64)->dateTime('created_at');
            $b->index('action');
            $b->index('entity_type');
        });

        $m->createTable('evaluations', function ($b) {
            $b->id()->string('agent', 60)->string('metric', 60)->string('value', 60)->date('window_start')->date('window_end')
                ->integer('sample_size', false, 0)->dateTime('created_at', true);
            $b->index('agent');
        });
    },
];
