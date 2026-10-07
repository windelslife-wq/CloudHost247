<?php
/**
 * App Cloud — 0003: infrastructure registry.
 *
 * Servers, their encrypted credentials, the agents that run on them, replay
 * protection for agent requests, and the metrics the agents report. Capacity
 * columns are what make scheduling honest: the engine refuses a deployment when
 * the requested resources do not fit the server's reported capacity, and reports
 * UNKNOWN rather than guessing when no agent has ever checked in.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0003_create_infrastructure_tables',
    'description' => 'Servers, encrypted credentials, agents, agent nonces and resource metrics.',
    'up' => function (Migrator $m) {

        /* ------------------------------------------------------------- servers -- */
        $m->create('servers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('hostname', 253);
            $t->string('ip_address', 45);
            $t->string('ipv6_address', 45, true);
            $t->string('server_type', 20, false, 'vps');   // vps|dedicated|cpanel|kubernetes|shared
            $t->string('provider', 80, true);              // ovh|hetzner|digitalocean|baremetal|…
            $t->string('region', 80, true);
            $t->string('datacenter', 120, true);
            $t->string('operating_system', 120, true);
            $t->string('status', 20, false, 'pending');    // pending|online|degraded|offline|maintenance|disabled

            $t->bigInteger('agent_id', true);
            $t->string('agent_version', 40, true);
            $t->datetime('last_heartbeat_at', true);
            $t->boolean('heartbeat_stale', 0);

            $t->integer('cpu_cores', false, 0);
            $t->integer('memory_mb', false, 0);
            $t->integer('storage_mb', false, 0);
            $t->integer('cpu_millicores_allocated', false, 0);
            $t->integer('memory_mb_allocated', false, 0);
            $t->integer('storage_mb_allocated', false, 0);
            $t->integer('max_installations', false, 0);    // 0 = unlimited within capacity

            $t->boolean('docker_enabled', 0);
            $t->boolean('kubernetes_enabled', 0);
            $t->boolean('cpanel_enabled', 0);
            $t->boolean('monitoring_enabled', 1);
            $t->boolean('accepts_new_installs', 1);
            $t->integer('weight', false, 100);             // scheduling preference
            $t->integer('ssh_port', false, 22);
            $t->text('tags');                              // JSON list
            $t->text('notes');
            $t->bigInteger('whmcs_server_id', true);       // tblservers.id, when linked

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['hostname']);
            $t->index(['status', 'accepts_new_installs']);
            $t->index(['server_type']);
            $t->index(['agent_id']);
        });

        /* ------------------------------------------------ server credentials -- */
        // Encrypted at rest, key-versioned, rotatable. The plaintext is used only
        // inside an adapter call and is never returned by an API or written to a
        // log (Logger::redact strips the key names as a second line of defence).
        $m->create('server_credentials', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('server_id', false, 0);
            $t->string('name', 120, false, 'primary');
            // ssh_key|ssh_password|agent_secret|whm_api_token|cpanel_uapi_token|
            // kube_token|kube_config|dns_api_token|registry_token|storage_key
            $t->string('credential_type', 40);
            $t->string('username', 190, true);
            $t->longText('encrypted_secret');
            $t->integer('key_version', false, 1);
            $t->char('secret_fingerprint', 64, true);      // blind index: find without decrypting
            $t->string('status', 20, false, 'active');     // active|rotating|revoked|expired
            $t->datetime('expires_at', true);
            $t->datetime('last_used_at', true);
            $t->datetime('last_verified_at', true);
            $t->boolean('verified', 0);
            $t->string('rotated_by', 120, true);
            $t->datetime('rotated_at', true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['server_id', 'credential_type', 'name']);
            $t->index(['server_id', 'status']);
            $t->index(['expires_at']);
            $t->foreign('server_id', 'servers', 'id', 'CASCADE');
        });

        /* --------------------------------------------------------------- agents -- */
        $m->create('agents', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('agent_uuid', 36);                  // the agent's stable identity
            $t->bigInteger('server_id', true);
            $t->string('version', 40, true);
            $t->string('endpoint', 255, true);             // https://agent-host:8443 (private)
            $t->longText('encrypted_shared_secret');       // HMAC key, AES-GCM sealed
            $t->integer('key_version', false, 1);
            $t->string('status', 20, false, 'pending');    // pending|active|suspended|revoked
            $t->text('capabilities');                      // JSON: docker, compose, backups, ssl, metrics…
            $t->datetime('last_seen_at', true);
            $t->string('last_seen_ip', 45, true);
            $t->bigInteger('request_count', false, 0);
            $t->bigInteger('rejected_count', false, 0);
            $t->datetime('secret_rotated_at', true);
            $t->string('rotated_by', 120, true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['agent_uuid']);
            $t->index(['server_id']);
            $t->index(['status']);
        });

        /* ---------------------------------------------- agent replay protection -- */
        // Nonces are remembered for the length of the request TTL window, so a
        // captured signed request cannot be replayed (specification §31).
        $m->create('agent_nonces', function (Blueprint $t) {
            $t->id();
            $t->char('nonce_hash', 64);
            $t->bigInteger('agent_id', false, 0);
            $t->string('direction', 10, false, 'inbound'); // inbound|outbound
            $t->datetime('expires_at', false);
            $t->datetime('created_at', false);
            $t->unique(['nonce_hash', 'direction']);
            $t->index(['expires_at']);
        });

        /* ------------------------------------------------------------- metrics -- */
        // Only real samples are stored. A server that has never reported has no
        // rows, and every consumer renders UNKNOWN (specification §65).
        $m->create('metrics', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('server_id', true);
            $t->bigInteger('installation_id', true);
            $t->string('scope', 20, false, 'server');      // server|application
            $t->decimal('cpu_percent', 6, 2, true);
            $t->integer('cpu_millicores', true);
            $t->integer('memory_used_mb', true);
            $t->decimal('memory_percent', 6, 2, true);
            $t->integer('storage_used_mb', true);
            $t->decimal('storage_percent', 6, 2, true);
            $t->integer('network_in_kb', true);
            $t->integer('network_out_kb', true);
            $t->decimal('load_average', 6, 2, true);
            $t->integer('uptime_seconds', true);
            $t->integer('container_count', true);
            $t->integer('restart_count', true);
            $t->string('health', 20, true);                // healthy|unhealthy|unknown
            $t->longText('extra');                         // JSON: per-container detail
            $t->datetime('sampled_at', false);
            $t->index(['server_id', 'sampled_at']);
            $t->index(['installation_id', 'sampled_at']);
            $t->index(['scope', 'sampled_at']);
        });
    },
];
