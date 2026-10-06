<?php
/**
 * Production governance store backed by WHMCS Capsule. Only loaded inside a
 * WHMCS runtime (gateway execution paths).
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

use WHMCS\Database\Capsule;

class CapsuleStore implements GovernanceStoreInterface
{
    public function ensureSchema()
    {
        if (!Capsule::schema()->hasTable('mod_blockonomics_governance')) {
            Capsule::schema()->create('mod_blockonomics_governance', function ($table) {
                $table->string('skey', 64);
                $table->text('svalue')->nullable();
                $table->integer('updated_at')->default(0);
                $table->primary('skey');
            });
        }
        if (!Capsule::schema()->hasTable('mod_blockonomics_governance_audit')) {
            Capsule::schema()->create('mod_blockonomics_governance_audit', function ($table) {
                $table->increments('id');
                $table->integer('actor_id')->default(0)->index();
                $table->string('action', 64);
                $table->string('setting', 64)->default('');
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('ip_hash', 64)->default('');
                $table->integer('created_at');
                $table->index('created_at');
            });
        }
    }

    public function get($key)
    {
        $value = Capsule::table('mod_blockonomics_governance')->where('skey', $key)->value('svalue');
        return $value === null ? null : (string) $value;
    }

    public function set($key, $value)
    {
        $now = time();
        $exists = Capsule::table('mod_blockonomics_governance')->where('skey', $key)->count() > 0;
        if ($exists) {
            Capsule::table('mod_blockonomics_governance')->where('skey', $key)
                ->update(['svalue' => $value, 'updated_at' => $now]);
        } else {
            Capsule::table('mod_blockonomics_governance')
                ->insert(['skey' => $key, 'svalue' => $value, 'updated_at' => $now]);
        }
    }

    public function all()
    {
        $out = [];
        foreach (Capsule::table('mod_blockonomics_governance')->get() as $row) {
            $out[$row->skey] = $row->svalue;
        }
        return $out;
    }

    public function audit(array $row)
    {
        Capsule::table('mod_blockonomics_governance_audit')->insert([
            'actor_id'   => (int) (isset($row['actor_id']) ? $row['actor_id'] : 0),
            'action'     => (string) $row['action'],
            'setting'    => (string) (isset($row['setting']) ? $row['setting'] : ''),
            'old_value'  => isset($row['old_value']) ? (string) $row['old_value'] : null,
            'new_value'  => isset($row['new_value']) ? (string) $row['new_value'] : null,
            'ip_hash'    => (string) (isset($row['ip_hash']) ? $row['ip_hash'] : ''),
            'created_at' => (int) (isset($row['created_at']) ? $row['created_at'] : time()),
        ]);
    }

    public function auditList($limit = 50)
    {
        $rows = Capsule::table('mod_blockonomics_governance_audit')
            ->orderBy('id', 'desc')->limit((int) $limit)->get();
        $out = [];
        foreach ($rows as $row) {
            $out[] = (array) $row;
        }
        return $out;
    }
}
