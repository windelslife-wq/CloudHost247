<?php
/**
 * App Cloud — 0010: dedicated control-panel catalog and plan metadata.
 *
 * These tables are intentionally distinct from applications/categories/plans.
 * A panel catalog entry is descriptive only in this phase: install/licensing
 * adapters do not exist, so deployable defaults to 0 and status values are
 * limited by the service to non-operational metadata. WHMCS product ids are
 * references only; no local price or parallel order record is stored.
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0010_create_panel_catalog_tables',
    'description' => 'Add dedicated control-panel categories, catalog metadata, and WHMCS-linked plan references.',
    'up' => function (Migrator $m) {
        $m->create('panel_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 80);
            $t->text('description', true);
            $t->integer('sort_order', false, 100);
            $t->boolean('active', 1);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['slug']);
            $t->index(['active', 'sort_order']);
        });

        $m->create('control_panels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('category_id', true);
            $t->string('name', 160);
            $t->string('slug', 100);
            $t->string('vendor', 160);
            $t->string('summary', 255, true);
            $t->text('description', true);
            $t->string('official_source_url', 255, true);
            $t->string('documentation_url', 255, true);
            $t->string('support_url', 255, true);
            $t->string('license_model', 40, false, 'unknown');
            $t->string('license_terms_url', 255, true);
            $t->text('license_terms_summary', true);
            $t->longText('supported_os');
            $t->integer('minimum_cpu_cores', false, 0);
            $t->integer('minimum_memory_mb', false, 0);
            $t->integer('minimum_storage_gb', false, 0);
            $t->text('resource_notes', true);
            $t->longText('capabilities');
            $t->string('catalog_status', 24, false, 'draft');
            $t->string('integration_status', 32, false, 'not_implemented');
            $t->string('installation_status', 32, false, 'not_implemented');
            $t->boolean('deployable', 0);
            $t->integer('sort_order', false, 100);
            $t->datetime('published_at', true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['slug']);
            $t->index(['category_id', 'catalog_status', 'sort_order']);
            $t->foreign('category_id', 'panel_categories', 'id', 'RESTRICT');
        });

        $m->create('control_panel_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('control_panel_id');
            $t->string('name', 160);
            $t->string('slug', 100);
            $t->string('summary', 255, true);
            $t->text('description', true);
            // This points to WHMCS' own product/pricing. It is deliberately not
            // constrained to a WHMCS table and no amount is duplicated here.
            $t->bigInteger('whmcs_product_id', true);
            $t->string('billing_cycle', 24, false, 'monthly');
            $t->integer('cpu_cores', false, 0);
            $t->integer('memory_mb', false, 0);
            $t->integer('storage_gb', false, 0);
            $t->integer('bandwidth_gb', false, 0);
            $t->integer('max_accounts', false, 0);
            $t->string('catalog_status', 24, false, 'draft');
            $t->boolean('deployable', 0);
            $t->integer('sort_order', false, 100);
            $t->datetime('published_at', true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['control_panel_id', 'slug']);
            $t->unique(['whmcs_product_id']);
            $t->index(['control_panel_id', 'catalog_status', 'sort_order']);
            $t->foreign('control_panel_id', 'control_panels', 'id', 'CASCADE');
        });
    },
];
