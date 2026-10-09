<?php
/** Separate read-only adoption registry: not a provider-created customer_servers VM. */
use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0013_contabo_adoptions',
    'description' => 'Operator-only verified bindings of existing paid WHMCS services to Contabo instances.',
    'up' => function (Migrator $m) {
        $m->create('contabo_adoptions', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('whmcs_service_id');
            $t->bigInteger('whmcs_order_id');
            $t->bigInteger('whmcs_invoice_id');
            $t->bigInteger('client_id');
            $t->unsignedBigInteger('provider_account_id');
            $t->string('provider_instance_id', 19);
            $t->longText('expected_spec');
            $t->longText('verified_spec', true);
            $t->string('expected_product_id', 40);
            $t->string('provider_status', 40, true);
            $t->string('ipv4', 45, true);
            $t->string('ipv6', 45, true);
            $t->string('status', 20, false, 'pending');
            $t->string('error_code', 60, true);
            $t->string('requested_by', 120);
            $t->timestamps();
            $t->unique(['whmcs_service_id']);
            $t->unique(['provider_account_id', 'provider_instance_id']);
            $t->foreign('provider_account_id', 'provider_accounts', 'id', 'RESTRICT');
        });
    },
];
