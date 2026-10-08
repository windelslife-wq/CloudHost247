<?php
/**
 * App Cloud — 0002: application catalog.
 *
 * The catalog is data, not code: categories, applications, versions and the
 * compatibility matrix are rows, so adding an application never means adding a
 * page, a controller or an installer. `kind` implements the specification's
 * APPLICATION / INFRASTRUCTURE / PLATFORM distinction, and `deployable` keeps an
 * untested registry entry from being offered to customers.
 *
 * @package Ch247Apps
 */

use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0002_create_catalog_tables',
    'description' => 'Application categories, applications, versions, compatibility matrix and hosting plans.',
    'up' => function (Migrator $m) {

        /* ------------------------------------------------------- categories -- */
        $m->create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 80);
            $t->text('description');
            $t->string('icon', 60, true);          // font-awesome / icon name
            $t->string('icon_url', 255, true);
            $t->integer('sort_order', false, 0);
            $t->boolean('active', 1);
            $t->timestamps();
            $t->unique(['slug']);
            $t->index(['active', 'sort_order']);
        });

        /* ------------------------------------------------------ applications -- */
        $m->create('applications', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('category_id', true);
            $t->string('name', 160);
            $t->string('slug', 100);
            $t->string('summary', 255, true);           // card text
            $t->text('description');
            $t->longText('long_description');
            $t->string('logo_url', 255, true);
            $t->string('website_url', 255, true);
            $t->string('repository_url', 255, true);
            $t->string('documentation_url', 255, true);
            $t->string('license', 80, true);
            $t->string('vendor', 120, true);

            // application | infrastructure | platform  (specification §60)
            $t->string('kind', 20, false, 'application');
            // docker-compose | docker | cpanel | kubernetes | external
            $t->string('deployment_type', 30, false, 'docker-compose');

            // Approval workflow (specification §47):
            // draft → validating → testing → approved → published (| suspended | deprecated)
            $t->string('status', 20, false, 'draft');
            $t->boolean('deployable', 0);                // has a validated, published version
            $t->boolean('featured', 0);
            $t->boolean('requires_admin_approval', 0);
            $t->boolean('requires_domain', 0);
            $t->boolean('requires_ssl', 0);
            $t->boolean('backup_supported', 1);
            $t->boolean('update_supported', 1);
            $t->boolean('gpu_required', 0);

            $t->text('tags');                            // JSON list
            $t->integer('install_count', false, 0);
            $t->integer('popularity', false, 0);
            $t->integer('sort_order', false, 0);
            $t->bigInteger('latest_version_id', true);
            $t->string('manifest_path', 255, true);
            $t->text('admin_notes');
            $t->string('suspension_reason', 255, true);

            $t->datetime('published_at', true);
            $t->datetime('deprecated_at', true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['slug']);
            $t->index(['status', 'deployable']);
            $t->index(['category_id']);
            $t->index(['kind']);
            $t->index(['featured']);
        });

        /* ---------------------------------------------------------- versions -- */
        $m->create('application_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id', false, 0);
            $t->string('version', 60);                   // 1.2.3, latest, 2024.11
            $t->string('channel', 20, false, 'stable');   // stable|lts|beta
            $t->string('docker_image', 255, true);        // repository:tag
            $t->longText('manifest');                     // the validated manifest snapshot (YAML)
            $t->char('manifest_hash', 64, true);
            $t->longText('manifest_errors');              // JSON: why validation failed, if it did

            $t->integer('minimum_cpu', false, 1);         // cores
            $t->integer('minimum_memory_mb', false, 512);
            $t->integer('minimum_storage_mb', false, 1024);
            $t->integer('recommended_cpu', false, 1);
            $t->integer('recommended_memory_mb', false, 1024);
            $t->integer('recommended_storage_mb', false, 5120);
            $t->integer('minimum_gpu', false, 0);

            $t->text('release_notes');
            // draft|validating|testing|approved|published|deprecated|rejected
            $t->string('status', 20, false, 'draft');
            $t->boolean('is_latest', 0);
            $t->boolean('auto_update_eligible', 1);
            $t->text('validation_report');                // JSON: manifest/security/deploy/health/backup/update/uninstall

            $t->bigInteger('published_by', true);
            $t->datetime('published_at', true);
            $t->datetime('created_at', false);
            $t->datetime('updated_at', true);

            $t->unique(['application_id', 'version', 'channel']);
            $t->index(['application_id', 'status']);
            $t->foreign('application_id', 'applications', 'id', 'CASCADE');
        });

        /* --------------------------------------------- compatibility matrix -- */
        // Which infrastructure an application may run on (specification §48).
        // The install wizard hides or disables anything not listed here.
        $m->create('application_compatibility', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id', false, 0);
            // shared|cpanel|vps|dedicated|docker|kubernetes
            $t->string('hosting_type', 30);
            $t->boolean('supported', 1);
            $t->boolean('recommended', 0);
            $t->string('notes', 255, true);
            $t->timestamps();
            $t->unique(['application_id', 'hosting_type']);
            $t->foreign('application_id', 'applications', 'id', 'CASCADE');
        });

        /* ------------------------------------------------- application stacks -- */
        // Dependency edges between catalog entries (specification §61): an
        // application may require PostgreSQL or Redis, which the deployment
        // engine resolves to a shared managed service or an isolated container.
        $m->create('application_dependencies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('application_id', false, 0);
            $t->bigInteger('depends_on_application_id', true);
            $t->string('dependency_type', 30, false, 'service'); // service|database|cache|storage
            $t->string('requirement', 20, false, 'required');    // required|optional
            $t->string('isolation', 20, false, 'isolated');      // isolated|shared
            $t->string('notes', 255, true);
            $t->timestamps();
            $t->unique(['application_id', 'depends_on_application_id', 'dependency_type']);
            $t->foreign('application_id', 'applications', 'id', 'CASCADE');
        });

        /* ------------------------------------------------------------- plans -- */
        // A plan is the resource envelope sold to a customer. Pricing lives in
        // WHMCS (tblproducts/tblpricing) and is referenced by whmcs_product_id;
        // this row adds the CPU/RAM/storage/bandwidth limits and the application
        // binding, which WHMCS products do not model.
        $m->create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('slug', 100);
            $t->bigInteger('whmcs_product_id', true);      // tblproducts.id — authoritative pricing
            $t->bigInteger('application_id', true);        // null = generic app-cloud plan
            $t->string('billing_interval', 20, false, 'monthly');
            $t->money('price_minor', true);                // informational mirror of WHMCS pricing
            $t->char('currency', 3, false, 'USD');

            $t->integer('cpu_millicores', false, 1000);
            $t->integer('memory_mb', false, 1024);
            $t->integer('storage_mb', false, 10240);
            $t->integer('bandwidth_mb', false, 102400);
            $t->integer('max_instances', false, 1);
            $t->integer('max_domains', false, 1);
            $t->integer('backup_retention_days', false, 14);
            $t->boolean('ssl_included', 1);
            $t->boolean('auto_backup', 1);
            $t->text('deployment_types');                  // JSON list of allowed adapters
            $t->text('server_types');                      // JSON list of allowed server types
            $t->text('description');
            $t->boolean('active', 1);
            $t->boolean('featured', 0);
            $t->integer('sort_order', false, 0);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['slug']);
            $t->index(['application_id', 'active']);
            $t->index(['whmcs_product_id']);
        });
    },
];
