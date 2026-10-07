<?php
/** Bounded host-invoked cleanup for expired ephemeral Passkey state. */

namespace CloudHost247\Passkey\Core;

class PasskeyMaintenanceService
{
    const DEFAULT_BATCH_LIMIT = 500;
    const MAX_BATCH_LIMIT = 1000;
    const MIN_EVENT_RETENTION_DAYS = 1;
    const MAX_EVENT_RETENTION_DAYS = 3650;
    const MIN_CHALLENGE_RETENTION_HOURS = 1;
    const MAX_CHALLENGE_RETENTION_HOURS = 720;

    private $settings;

    public function __construct(SettingsRepository $settings = null)
    {
        $this->settings = $settings ?: new SettingsRepository();
    }

    /**
     * Purge only expired ephemeral rows and events older than the configured
     * retention. The host may call this from its own trusted scheduler; this
     * addon does not register a scheduler, endpoint, or background process.
     */
    public function run($nowEpoch = null, $batchLimit = self::DEFAULT_BATCH_LIMIT)
    {
        $nowEpoch = $this->validateEpoch($nowEpoch === null ? time() : $nowEpoch);
        $batchLimit = $this->validateBatchLimit($batchLimit);
        $eventRetentionDays = $this->settingInt(
            'event_retention_days',
            self::MIN_EVENT_RETENTION_DAYS,
            self::MAX_EVENT_RETENTION_DAYS
        );
        $challengeRetentionHours = $this->settingInt(
            'challenge_retention_hours',
            self::MIN_CHALLENGE_RETENTION_HOURS,
            self::MAX_CHALLENGE_RETENTION_HOURS
        );
        $now = gmdate('Y-m-d H:i:s', $nowEpoch);
        $challengeCutoff = gmdate('Y-m-d H:i:s', $nowEpoch - ($challengeRetentionHours * 3600));
        $eventCutoff = gmdate('Y-m-d H:i:s', $nowEpoch - ($eventRetentionDays * 86400));

        return Db::transaction(function () use (
            $now,
            $challengeCutoff,
            $eventCutoff,
            $eventRetentionDays,
            $challengeRetentionHours,
            $batchLimit
        ) {
            $deleted = [
                'challenges' => $this->deleteIds(
                    'challenges',
                    '(`expires_at` <= ? OR (`consumed_at` IS NOT NULL AND `consumed_at` <= ?))',
                    [$challengeCutoff, $challengeCutoff],
                    $batchLimit
                ),
                'reset_grants' => $this->deleteIds(
                    'reset_grants',
                    '(`expires_at` <= ? OR (`consumed_at` IS NOT NULL AND `consumed_at` <= ?))',
                    [$challengeCutoff, $challengeCutoff],
                    $batchLimit
                ),
                'rate_limits' => $this->deleteIds(
                    'rate_limits',
                    '`expires_at` <= ?',
                    [$now],
                    $batchLimit
                ),
                'events' => $this->deleteIds(
                    'events',
                    '`created_at` <= ?',
                    [$eventCutoff],
                    $batchLimit
                ),
            ];
            return [
                'run_at' => $now,
                'event_retention_days' => $eventRetentionDays,
                'challenge_retention_hours' => $challengeRetentionHours,
                'batch_limit' => $batchLimit,
                'deleted' => $deleted,
                'total_deleted' => array_sum($deleted),
            ];
        });
    }

    private function deleteIds($logicalTable, $condition, array $bindings, $limit)
    {
        $rows = Db::query(
            'SELECT `id` FROM `' . Db::table($logicalTable) . '` WHERE ' . $condition
                . ' ORDER BY `id` ASC LIMIT ' . (int) $limit,
            $bindings
        );
        if (!$rows) {
            return 0;
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!isset($row['id']) || filter_var($row['id'], FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('Passkey maintenance found an invalid row identifier.');
            }
            $ids[] = (int) $row['id'];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return Db::execute(
            'DELETE FROM `' . Db::table($logicalTable) . '` WHERE `id` IN (' . $placeholders . ')',
            $ids
        );
    }

    private function settingInt($key, $minimum, $maximum)
    {
        $value = $this->settings->value($key, null);
        if (!is_string($value) || !preg_match('/^[0-9]+$/', $value)) {
            throw new \RuntimeException('Passkey maintenance setting is invalid: ' . $key . '.');
        }
        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new \RuntimeException('Passkey maintenance setting is outside its safe range: ' . $key . '.');
        }
        return $value;
    }

    private function validateBatchLimit($value)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || (int) $value < 1 || (int) $value > self::MAX_BATCH_LIMIT) {
            throw new \InvalidArgumentException('Passkey maintenance batch limit is outside the supported range.');
        }
        return (int) $value;
    }

    private function validateEpoch($value)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || (int) $value < 0) {
            throw new \InvalidArgumentException('Passkey maintenance time must be a non-negative Unix timestamp.');
        }
        return (int) $value;
    }
}
