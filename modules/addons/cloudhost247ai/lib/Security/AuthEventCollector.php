<?php
/** Incremental, conservative auth-event projection from WHMCS activity logs. */

namespace Ch247Ai\Security;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ServiceUnavailableException;

class AuthEventCollector
{
    const COLLECTOR = 'activitylog_auth';
    const MAX_BATCH = 1000;
    const RETENTION_DAYS = 90;

    /** Read one bounded batch by the activity log primary key and advance cursor transactionally. */
    public static function collect($limit = 500)
    {
        if (!Db::whmcsTableExists('tblactivitylog')) {
            throw new ServiceUnavailableException('DATA_UNAVAILABLE: WHMCS activity log table tblactivitylog is not present.');
        }
        $limit = max(1, min(self::MAX_BATCH, (int) $limit));
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $state = Db::first('security_collector_state', ['collector' => self::COLLECTOR]);
            $cursor = $state === null ? 0 : max(0, (int) $state['last_activity_id']);
            $sql = 'SELECT id, `date` AS activity_date, description, userid, ipaddr FROM tblactivitylog WHERE id > ? ORDER BY id ASC LIMIT ' . $limit;
            $sourceRows = Db::query($sql, [$cursor]);
            $added = 0;
            $lastId = $cursor;
            foreach ($sourceRows as $source) {
                $sourceId = isset($source['id']) ? (int) $source['id'] : 0;
                if ($sourceId <= $lastId) {
                    continue;
                }
                $lastId = $sourceId;
                $type = self::classify(isset($source['description']) ? (string) $source['description'] : '');
                if ($type === null) {
                    continue;
                }
                $occurred = trim((string) (isset($source['activity_date']) ? $source['activity_date'] : ''));
                if ($occurred === '' || \Ch247Ai\Core\Clock::toTime($occurred) === null) {
                    continue;
                }
                Db::insert('security_auth_events', [
                    'source_activity_id' => $sourceId,
                    'event_type' => $type,
                    'actor_id' => isset($source['userid']) && (int) $source['userid'] > 0 ? (int) $source['userid'] : null,
                    'ip_hash' => self::ipHash(isset($source['ipaddr']) ? (string) $source['ipaddr'] : ''),
                    'occurred_at' => $occurred,
                    'collected_at' => Clock::now(),
                ]);
                $added++;
            }
            if ($state === null) {
                Db::insert('security_collector_state', [
                    'collector' => self::COLLECTOR,
                    'last_activity_id' => $lastId,
                    'updated_at' => Clock::now(),
                ]);
            } else {
                Db::update('security_collector_state', ['collector' => self::COLLECTOR], [
                    'last_activity_id' => $lastId,
                    'updated_at' => Clock::now(),
                ]);
            }
            Db::exec('DELETE FROM ' . Db::t('security_auth_events') . ' WHERE occurred_at < ?', [Clock::ago(self::RETENTION_DAYS * 86400)]);
            $pdo->commit();
            return ['rows_scanned' => count($sourceRows), 'events_added' => $added, 'last_activity_id' => $lastId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Recognize only explicit WHMCS login success/failure phrases; ignore ambiguous descriptions. */
    public static function classify($description)
    {
        $text = strtolower(trim((string) $description));
        if ($text === '') {
            return null;
        }
        if (preg_match('/(?:failed|failure|unsuccessful|invalid)\s+(?:client\s+|admin\s+)?(?:login|log\s*in|authentication)|(?:login|log\s*in|authentication)\s+(?:failed|failure|unsuccessful|invalid)/', $text)) {
            return 'failed_login';
        }
        if (preg_match('/(?:successful\s+(?:client\s+|admin\s+)?login|login\s+successful|logged\s+in\s+successfully)/', $text)) {
            return 'successful_login';
        }
        return null;
    }

    /** Store only a keyed pseudonym; never persist or return the raw IP. */
    private static function ipHash($ip)
    {
        $ip = trim((string) $ip);
        $secret = isset($GLOBALS['cc_encryption_hash']) && is_string($GLOBALS['cc_encryption_hash'])
            ? $GLOBALS['cc_encryption_hash'] : '';
        if ($secret === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        return hash_hmac('sha256', $ip, $secret);
    }
}
