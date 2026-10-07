<?php
/** Read-only loader for module-owned Passkey settings. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\SettingsRecord;

class SettingsRepository
{
    /** Return only non-secret setting values; encrypted values are never loaded into policy code. */
    public function values()
    {
        $rows = Db::query(
            'SELECT `setting_key`, `setting_value`, `encrypted_value`, `is_secret`, `updated_at` '
                . 'FROM `' . Db::table('settings') . '` ORDER BY `setting_key` ASC'
        );
        $values = [];
        foreach ($rows as $row) {
            $record = new SettingsRecord($row);
            $safe = $record->safeArray();
            if (!$record->isSecret()) {
                $values[$safe['setting_key']] = $safe['value'];
            }
        }
        return $values;
    }

    public function value($key, $fallback = null)
    {
        $values = $this->values();
        return array_key_exists($key, $values) ? $values[$key] : $fallback;
    }
}
