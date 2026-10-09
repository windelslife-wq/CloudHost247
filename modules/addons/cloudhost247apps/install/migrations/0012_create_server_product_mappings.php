<?php
/** WHMCS product → operator-approved provider account and fixed VM envelope. */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0012_create_server_product_mappings',
    'description' => 'Default-off self-service VPS product mappings and customer-server correlation.',
    'up' => function (Migrator $m) {
        $m->create('server_product_mappings', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('whmcs_product_id', false);
            $t->unsignedBigInteger('provider_account_id', false);
            $t->longText('spec_template');
            $t->boolean('enabled', 0);
            $t->timestamps();
            $t->unique(['whmcs_product_id']);
            $t->index(['provider_account_id']);
            $t->foreign('provider_account_id', 'provider_accounts', 'id', 'RESTRICT');
        });
        $m->addColumn('customer_servers', 'product_mapping_id', Db::isSqlite() ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL');
        $m->addIndex('customer_servers', ['product_mapping_id']);
    },
];
