<?php
use CloudHost247\Cloudflare\Core\Migrator;

return [
    'id' => '0001_cloudflare_core',
    'description' => 'Cloudflare accounts, package mappings, service links, DNS cache, jobs, logs and audit records',
    'up' => function () {
        Migrator::create('accounts', [
            'id' => 'id', 'label' => 'VARCHAR(120) NOT NULL', 'account_id' => 'VARCHAR(64) NOT NULL',
            'api_base_url' => 'VARCHAR(255) NOT NULL', 'encrypted_api_token' => 'LONGTEXT NOT NULL',
            'enabled' => 'TINYINT NOT NULL DEFAULT 0', 'zone_mode' => 'VARCHAR(16) NOT NULL DEFAULT \'full\'',
            'default_nameservers' => 'TEXT NULL', 'ssl_mode' => 'VARCHAR(20) NOT NULL DEFAULT \'full\'',
            'proxy_default' => 'TINYINT NOT NULL DEFAULT 1', 'cache_level' => 'VARCHAR(20) NOT NULL DEFAULT \'standard\'',
            'browser_cache_ttl' => 'INT NOT NULL DEFAULT 14400', 'delete_zone_on_terminate' => 'TINYINT NOT NULL DEFAULT 0',
            'connection_status' => 'VARCHAR(24) NOT NULL DEFAULT \'not_tested\'', 'connection_tested_at' => 'DATETIME NULL',
            'last_success_at' => 'DATETIME NULL', 'last_failure_at' => 'DATETIME NULL',
            'last_error_code' => 'VARCHAR(80) NULL', 'last_error_message' => 'VARCHAR(500) NULL',
            'consecutive_failures' => 'INT NOT NULL DEFAULT 0', 'circuit_open_until' => 'DATETIME NULL', 'rate_limit_until' => 'DATETIME NULL',
            'created_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_accounts_account_id` (`account_id`)', 'KEY `ix_cf_accounts_enabled` (`enabled`)']);

        Migrator::create('packages', [
            'id' => 'id', 'whmcs_product_id' => 'BIGINT NULL', 'whmcs_addon_id' => 'BIGINT NULL',
            'account_id' => 'BIGINT NULL', 'provider_plan_id' => 'VARCHAR(120) NOT NULL', 'plan_label' => 'VARCHAR(100) NOT NULL',
            'max_domains' => 'INT NOT NULL DEFAULT 1', 'features_json' => 'LONGTEXT NOT NULL',
            'zone_mode' => 'VARCHAR(16) NULL', 'ssl_mode' => 'VARCHAR(20) NULL', 'proxy_default' => 'TINYINT NULL',
            'cache_level' => 'VARCHAR(20) NULL', 'browser_cache_ttl' => 'INT NULL', 'dns_discovery' => 'TINYINT NOT NULL DEFAULT 1',
            'enabled' => 'TINYINT NOT NULL DEFAULT 1', 'created_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_package_product` (`whmcs_product_id`)', 'UNIQUE KEY `uq_cf_package_addon` (`whmcs_addon_id`)', 'KEY `ix_cf_package_account` (`account_id`)']);

        Migrator::create('services', [
            'id' => 'id', 'whmcs_service_id' => 'BIGINT NULL', 'whmcs_addon_id' => 'BIGINT NULL',
            'origin_hosting_id' => 'BIGINT NULL', 'customer_id' => 'BIGINT NOT NULL', 'whmcs_product_id' => 'BIGINT NULL',
            'package_mapping_id' => 'BIGINT NOT NULL', 'domain_id' => 'BIGINT NULL', 'account_id' => 'BIGINT NOT NULL',
            'zone_id' => 'VARCHAR(64) NULL', 'zone_name' => 'VARCHAR(253) NOT NULL', 'provider_plan_id' => 'VARCHAR(120) NOT NULL',
            'plan_label' => 'VARCHAR(100) NOT NULL', 'status' => 'VARCHAR(40) NOT NULL', 'activation_status' => 'VARCHAR(40) NOT NULL DEFAULT \'PENDING\'',
            'provisioning_status' => 'VARCHAR(40) NOT NULL DEFAULT \'PENDING\'', 'nameservers_json' => 'TEXT NULL',
            'features_json' => 'LONGTEXT NOT NULL', 'proxy_default' => 'TINYINT NOT NULL DEFAULT 1', 'ssl_mode' => 'VARCHAR(20) NOT NULL DEFAULT \'full\'',
            'development_mode' => 'TINYINT NOT NULL DEFAULT 0', 'development_mode_changed_at' => 'DATETIME NULL',
            'last_synced_at' => 'DATETIME NULL', 'last_error_code' => 'VARCHAR(80) NULL', 'last_error_message' => 'VARCHAR(500) NULL',
            'suspended_at' => 'DATETIME NULL', 'terminated_at' => 'DATETIME NULL', 'created_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_service_hosting` (`whmcs_service_id`)', 'UNIQUE KEY `uq_cf_service_addon` (`whmcs_addon_id`)', 'UNIQUE KEY `uq_cf_service_zone` (`account_id`,`zone_id`)', 'KEY `ix_cf_service_customer` (`customer_id`)', 'KEY `ix_cf_service_zone_name` (`zone_name`)']);

        Migrator::create('dns_records', [
            'id' => 'id', 'service_id' => 'BIGINT NOT NULL', 'cloudflare_record_id' => 'VARCHAR(64) NOT NULL',
            'type' => 'VARCHAR(12) NOT NULL', 'name' => 'VARCHAR(253) NOT NULL', 'content' => 'TEXT NOT NULL',
            'ttl' => 'INT NOT NULL DEFAULT 1', 'proxied' => 'TINYINT NULL', 'priority' => 'INT NULL',
            'comment' => 'VARCHAR(512) NULL', 'ownership' => 'VARCHAR(24) NOT NULL DEFAULT \'CUSTOMER_MANAGED\'',
            'metadata_json' => 'TEXT NULL', 'provider_created_at' => 'DATETIME NULL', 'provider_updated_at' => 'DATETIME NULL',
            'last_synced_at' => 'DATETIME NULL', 'created_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_dns_record_id` (`cloudflare_record_id`)', 'KEY `ix_cf_dns_service_name` (`service_id`,`name`)']);

        Migrator::create('security_rules', [
            'id' => 'id', 'service_id' => 'BIGINT NOT NULL', 'cloudflare_rule_id' => 'VARCHAR(64) NOT NULL',
            'ruleset_id' => 'VARCHAR(64) NOT NULL', 'address' => 'VARCHAR(255) NOT NULL', 'action' => 'VARCHAR(32) NOT NULL',
            'description' => 'VARCHAR(255) NOT NULL', 'expression' => 'TEXT NOT NULL', 'status' => 'VARCHAR(20) NOT NULL DEFAULT \'active\'',
            'created_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_rule_service_id` (`service_id`,`cloudflare_rule_id`)', 'KEY `ix_cf_rule_service` (`service_id`)']);

        Migrator::create('jobs', [
            'id' => 'id', 'service_id' => 'BIGINT NULL', 'kind' => 'VARCHAR(40) NOT NULL', 'idempotency_key' => 'VARCHAR(191) NOT NULL',
            'payload_json' => 'LONGTEXT NULL', 'status' => 'VARCHAR(20) NOT NULL DEFAULT \'pending\'',
            'attempts' => 'INT NOT NULL DEFAULT 0', 'max_attempts' => 'INT NOT NULL DEFAULT 8', 'next_attempt_at' => 'DATETIME NOT NULL',
            'locked_at' => 'DATETIME NULL', 'last_error_code' => 'VARCHAR(80) NULL', 'last_error_message' => 'VARCHAR(500) NULL',
            'created_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_job_key` (`idempotency_key`)', 'KEY `ix_cf_job_due` (`status`,`next_attempt_at`)']);

        Migrator::create('api_logs', [
            'id' => 'id', 'request_id' => 'VARCHAR(64) NOT NULL', 'account_id' => 'BIGINT NULL', 'service_id' => 'BIGINT NULL',
            'customer_id' => 'BIGINT NULL', 'operation' => 'VARCHAR(80) NOT NULL', 'method' => 'VARCHAR(8) NOT NULL',
            'path' => 'VARCHAR(255) NOT NULL', 'http_status' => 'INT NOT NULL DEFAULT 0', 'duration_ms' => 'INT NOT NULL DEFAULT 0',
            'outcome' => 'VARCHAR(24) NOT NULL', 'error_code' => 'VARCHAR(80) NULL', 'created_at' => 'DATETIME NOT NULL',
        ], ['KEY `ix_cf_api_created` (`created_at`)', 'KEY `ix_cf_api_service` (`service_id`)']);

        Migrator::create('audit_logs', [
            'id' => 'id', 'actor_type' => 'VARCHAR(16) NOT NULL', 'actor_id' => 'BIGINT NOT NULL DEFAULT 0',
            'customer_id' => 'BIGINT NULL', 'service_id' => 'BIGINT NULL', 'action' => 'VARCHAR(100) NOT NULL',
            'entity_type' => 'VARCHAR(40) NOT NULL', 'entity_id' => 'VARCHAR(80) NULL', 'success' => 'TINYINT NOT NULL DEFAULT 1',
            'details_json' => 'LONGTEXT NULL', 'ip_address' => 'VARCHAR(45) NULL', 'user_agent' => 'VARCHAR(255) NULL',
            'error_code' => 'VARCHAR(80) NULL', 'created_at' => 'DATETIME NOT NULL',
        ], ['KEY `ix_cf_audit_customer` (`customer_id`,`created_at`)', 'KEY `ix_cf_audit_service` (`service_id`,`created_at`)', 'KEY `ix_cf_audit_action` (`action`)']);

        Migrator::create('analytics_cache', [
            'id' => 'id', 'service_id' => 'BIGINT NOT NULL', 'cache_key' => 'VARCHAR(100) NOT NULL',
            'payload_json' => 'LONGTEXT NOT NULL', 'expires_at' => 'DATETIME NOT NULL', 'updated_at' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_analytics_service_key` (`service_id`,`cache_key`)', 'KEY `ix_cf_analytics_expiry` (`expires_at`)']);

        Migrator::create('rate_limits', [
            'id' => 'id', 'bucket' => 'VARCHAR(120) NOT NULL', 'action' => 'VARCHAR(80) NOT NULL',
            'hits' => 'INT NOT NULL DEFAULT 0', 'window_start' => 'DATETIME NOT NULL',
        ], ['UNIQUE KEY `uq_cf_rate_bucket_action` (`bucket`,`action`)']);
    },
];
