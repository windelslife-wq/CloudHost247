-- 1.0.1 — Per-client API keys (finding P-5)
-- The legacy shared "api_key" setting granted all-tenant access and is retired.
-- Keys are issued per client and stored only as SHA-256 hashes.
CREATE TABLE IF NOT EXISTS `mod_phoneservices_apikeys` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `clientid` INT(10) UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL DEFAULT 'Default',
    `key_hash` CHAR(64) NOT NULL,
    `key_prefix` VARCHAR(12) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    `revoked_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `clientid` (`clientid`),
    KEY `key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
