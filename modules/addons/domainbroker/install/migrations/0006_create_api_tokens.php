<?php
/**
 * Domain Broker — 0006: API access tokens.
 *
 * Tokens are never stored in the clear: only a SHA-256 hash is persisted, so
 * a database disclosure does not yield usable credentials. The plaintext is
 * shown once, at issue time, and cannot be recovered afterwards.
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Blueprint;
use DomainBroker\Core\Migrator;

return [
    'id' => '0006_create_api_tokens',
    'description' => 'API access tokens for the Domain Broker REST API.',
    'up' => function (Migrator $m) {
        $m->create('tokens', function (Blueprint $t) {
            $t->id();
            $t->string('name', 190);
            $t->char('token_hash', 64);
            $t->string('token_hint', 20);            // last four characters, for the UI
            $t->string('actor_type', 20, false);     // customer|broker|admin
            $t->integer('actor_id', false, 0);       // client id / broker id / admin id
            $t->string('actor_role', 40, false, 'customer');
            $t->string('actor_label', 190, true);
            $t->text('scopes');                      // JSON array, null = the role's full surface
            $t->string('ip_allowlist', 500, true);   // comma separated CIDRs / addresses
            $t->boolean('active', 1);
            $t->integer('request_count', false, 0);
            $t->string('last_used_ip', 45, true);
            $t->datetime('last_used_at', true);
            $t->datetime('expires_at', true);
            $t->string('created_by', 190, true);
            $t->text('revoked_reason');
            $t->datetime('revoked_at', true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['token_hash']);
            $t->index(['actor_type', 'actor_id']);
            $t->index(['active']);
        });
    },
];
