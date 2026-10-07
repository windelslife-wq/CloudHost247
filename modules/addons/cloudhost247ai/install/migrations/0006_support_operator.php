<?php
/**
 * Phase 4 — embedded AI Support Operator tables + seed knowledge.
 *
 * Additive only. Conversations live in addon tables; escalations reuse the
 * native WHMCS ticket system (linked by ticket_id) instead of duplicating
 * it. Seed knowledge is generic, true, and editable from the Knowledge
 * page — never prices, specs, or policies.
 */

use Ch247Ai\Core\Db;
use Ch247Ai\Core\Migrator;
use Ch247Ai\Knowledge\KnowledgeService;

return [
    'id' => '0006_support_operator',
    'description' => 'AI Support Operator: conversations, messages, presence, newsletter fallback, seed knowledge',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('support_conversations', function ($b) {
            $b->id()->string('public_id', 64)->unique('public_id')->integer('client_id', true)
                ->string('guest_name', 120, true)->string('guest_email', 190, true)
                ->string('status', 24, false, 'ai_active')->string('flow', 32, true)->text('flow_data')
                ->string('subject', 190, true)->string('escalation_reason', 40, true)
                ->integer('ticket_id', true)->integer('assigned_admin_id', true)
                ->integer('message_count', false, 0)->dateTime('last_message_at', true)
                ->dateTime('created_at', true)->dateTime('updated_at', true);
            $b->index('client_id');
            $b->index('status');
            $b->index('guest_email');
        });

        $m->createTable('support_messages', function ($b) {
            $b->id()->integer('conversation_id', false, 0)->string('author', 16)
                ->text('body')->text('meta')->dateTime('created_at', true);
            $b->index('conversation_id');
        });

        $m->createTable('support_presence', function ($b) {
            $b->id()->integer('admin_id', false, 0)->unique('admin_id')
                ->string('status', 16, false, 'offline')->dateTime('updated_at', true);
        });

        $m->createTable('support_newsletter', function ($b) {
            $b->id()->string('name', 120)->string('email', 190)->unique('email')
                ->string('status', 16, false, 'subscribed')->string('source', 32, false, 'ai_assistant')
                ->dateTime('created_at', true)->dateTime('updated_at', true);
        });

        // Seed operator knowledge once (guarded by the seed tag so re-runs
        // and upgrades never duplicate it).
        $seedTag = 'support-operator seed';
        if (Db::first('knowledge_sources', ['tags' => $seedTag]) !== null) {
            return;
        }
        $docs = [
            [
                'title' => 'AI Support Operator: what it can do',
                'body' => "The CloudHost247 AI Support Operator answers questions from the platform knowledge base and the public product catalog.\n\n"
                    . "It can explain services, outline how to use the client area, and collect newsletter subscriptions.\n\n"
                    . "It cannot see account-specific records through this chat, change anything on an account, quote prices that are not published on the website, or make account decisions.\n\n"
                    . "Whenever a question needs account access or a human judgment, the operator transfers the conversation to CloudHost247 Support with the full chat history attached.\n\n"
                    . "Asking for a human at any point starts a transfer immediately.",
            ],
            [
                'title' => 'Contacting CloudHost247 support',
                'body' => "CloudHost247 Support works from support tickets in the client area.\n\n"
                    . "The AI Support Operator can create a support request for you and attach the whole conversation, so you never have to repeat yourself.\n\n"
                    . "If a support agent is online, your conversation is transferred to them right away. If nobody is available, the operator files your request into the support queue and the team follows up.",
            ],
            [
                'title' => 'CloudHost247 services overview',
                'body' => "CloudHost247 offers web hosting, WordPress hosting, cloud hosting, VPS servers, dedicated servers, RDP services, domain registration and management, business email and SMTP, SSL certificates, and related platform services.\n\n"
                    . "Plans, current pricing, availability, and technical specifications are published on the CloudHost247 website and can change; the operator only repeats plan names and prices it can read from the live product catalog.\n\n"
                    . "For anything not listed there, the operator transfers you to CloudHost247 Support instead of guessing.",
            ],
        ];
        foreach ($docs as $doc) {
            KnowledgeService::upsert(0, 'kb', $doc['title'], $doc['body'], $seedTag, 'public');
        }
    },
];
