<?php
/**
 * PDO-backed governance store. Used by the test harness (sqlite) and as a
 * fallback anywhere WHMCS Capsule is unavailable.
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

class PdoStore implements GovernanceStoreInterface
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function ensureSchema()
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS mod_blockonomics_governance (
                skey VARCHAR(64) PRIMARY KEY,
                svalue TEXT,
                updated_at INTEGER NOT NULL DEFAULT 0
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS mod_blockonomics_governance_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER NOT NULL DEFAULT 0,
                action VARCHAR(64) NOT NULL,
                setting VARCHAR(64) NOT NULL DEFAULT '',
                old_value TEXT,
                new_value TEXT,
                ip_hash VARCHAR(64) NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL
            )"
        );
    }

    public function get($key)
    {
        $stmt = $this->pdo->prepare('SELECT svalue FROM mod_blockonomics_governance WHERE skey = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function set($key, $value)
    {
        $now = time();
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mod_blockonomics_governance (skey, svalue, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue, updated_at = excluded.updated_at'
            );
            $stmt->execute([$key, $value, $now]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mod_blockonomics_governance (skey, svalue, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$key, $value, $now]);
    }

    public function all()
    {
        $rows = $this->pdo->query('SELECT skey, svalue FROM mod_blockonomics_governance')->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['skey']] = $row['svalue'];
        }
        return $out;
    }

    public function audit(array $row)
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mod_blockonomics_governance_audit
                (actor_id, action, setting, old_value, new_value, ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) (isset($row['actor_id']) ? $row['actor_id'] : 0),
            (string) $row['action'],
            (string) (isset($row['setting']) ? $row['setting'] : ''),
            isset($row['old_value']) ? (string) $row['old_value'] : null,
            isset($row['new_value']) ? (string) $row['new_value'] : null,
            (string) (isset($row['ip_hash']) ? $row['ip_hash'] : ''),
            (int) (isset($row['created_at']) ? $row['created_at'] : time()),
        ]);
    }

    public function auditList($limit = 50)
    {
        $stmt = $this->pdo->prepare(
            'SELECT actor_id, action, setting, old_value, new_value, ip_hash, created_at
             FROM mod_blockonomics_governance_audit ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, (int) $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
