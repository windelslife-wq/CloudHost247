<?php
use WHMCS\Database\Capsule;
use DigitalProducts\Core\Clock;

return [
    'id' => '0003_entitlements',
    'description' => 'Backfill active service ownership into idempotent entitlements.',
    'up' => function ($m) {
        if (!Capsule::schema()->hasTable('tblhosting')) return;
        $rows = Capsule::table('tblhosting as h')->join('mod_digitalproducts_products as p', 'p.whmcs_product_id', '=', 'h.packageid')->whereIn('h.domainstatus', ['Active', 'Completed'])->where('p.status', 'active')->select('h.id as service_id', 'h.userid as client_id', 'h.packageid as whmcs_product_id', 'h.orderid as order_id', 'h.regdate', 'p.id as product_id', 'p.current_version_id', 'p.access_mode', 'p.download_limit')->get();
        foreach ($rows as $row) {
            $exists = Capsule::table('mod_digitalproducts_entitlements')->where('client_id', (int) $row->client_id)->where('service_id', (int) $row->service_id)->where('product_id', (int) $row->product_id)->exists();
            if ($exists) continue;
            Capsule::table('mod_digitalproducts_entitlements')->insert([
                'product_id' => (int) $row->product_id, 'whmcs_product_id' => (int) $row->whmcs_product_id, 'order_id' => (int) $row->order_id ?: null, 'service_id' => (int) $row->service_id, 'client_id' => (int) $row->client_id, 'purchase_version_id' => $row->current_version_id ?: null, 'access_mode' => $row->access_mode ?: 'current_version', 'status' => 'active', 'download_limit' => (int) $row->download_limit, 'downloads_used' => 0, 'purchased_at' => $row->regdate ?: Clock::now(), 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
            ]);
        }
    },
];
