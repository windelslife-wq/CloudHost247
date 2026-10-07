<?php
/**
 * CloudHost247 App Cloud — catalog services.
 *
 * CategoryService, ApplicationService and VersionService are the write/read path
 * for the catalog. Everything here is database-driven: the marketplace, the
 * install wizard and the admin application manager all read the same rows, so
 * adding an application never means adding a page or a controller.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Catalog;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class CategoryService
{
    /** The catalog taxonomy required by the platform specification (§7). */
    const TAXONOMY = [
        'ai' => ['AI', 'Machine learning, inference servers and AI tooling', 'fa-magic'],
        'analytics' => ['Analytics', 'Product, web and business analytics', 'fa-bar-chart'],
        'automation' => ['Automation', 'Workflow automation and integration', 'fa-bolt'],
        'business' => ['Business', 'ERP, invoicing and business operations', 'fa-briefcase'],
        'cms' => ['CMS', 'Content management and website publishing', 'fa-file-text-o'],
        'communication' => ['Communication', 'Chat, mail, voice and collaboration', 'fa-comments'],
        'crm' => ['CRM', 'Customer relationship management', 'fa-address-book'],
        'database' => ['Database', 'Relational, document and cache data stores', 'fa-database'],
        'developer-tools' => ['Developer Tools', 'Git, CI/CD, API and code tooling', 'fa-code'],
        'documents' => ['Documents', 'Document management and paperless workflows', 'fa-folder-open-o'],
        'e-commerce' => ['E-commerce', 'Stores, carts and payments', 'fa-shopping-cart'],
        'education' => ['Education', 'Learning management and courses', 'fa-graduation-cap'],
        'finance' => ['Finance', 'Personal and business finance', 'fa-money'],
        'home-automation' => ['Home Automation', 'Smart home control', 'fa-home'],
        'media' => ['Media', 'Photo, video and audio libraries', 'fa-photo'],
        'monitoring' => ['Monitoring', 'Uptime, metrics, logs and alerting', 'fa-heartbeat'],
        'networking' => ['Networking', 'DNS, VPN, proxies and network services', 'fa-sitemap'],
        'productivity' => ['Productivity', 'Notes, tasks and personal tools', 'fa-check-square-o'],
        'project-management' => ['Project Management', 'Boards, issues and planning', 'fa-tasks'],
        'security' => ['Security', 'Secrets, passwords, scanning and identity', 'fa-shield'],
        'storage' => ['Storage', 'File sync, share and object storage', 'fa-hdd-o'],
        'system-administration' => ['System Administration', 'Server and fleet administration', 'fa-terminal'],
        'web-hosting' => ['Web Hosting', 'Web servers, panels and site tooling', 'fa-globe'],
        'infrastructure' => ['Infrastructure', 'Platform infrastructure and proxies', 'fa-cubes'],
        'other' => ['Other', 'Unclassified applications', 'fa-cube'],
    ];

    /** @var Actor */
    private $actor;

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('CategoryService');
    }

    /** @return array[] active categories with published application counts */
    public function listing($includeInactive = false)
    {
        $where = $includeInactive ? [] : ['active' => 1];
        $rows = Db::fetch('categories', $where, ['order' => 'sort_order', 'dir' => 'asc', 'order2' => 'name']);

        $counts = [];
        foreach (Db::select(
            'SELECT category_id, COUNT(*) AS total FROM ' . Db::quoteIdentifier(Db::t('applications'))
            . ' WHERE deleted_at IS NULL AND status = ? GROUP BY category_id',
            ['published']
        ) as $row) {
            $counts[(int) $row['category_id']] = (int) $row['total'];
        }

        $out = [];
        foreach ($rows as $row) {
            $row['application_count'] = isset($counts[(int) $row['id']]) ? $counts[(int) $row['id']] : 0;
            $out[] = $row;
        }
        return $out;
    }

    public function find($slugOrId)
    {
        if (is_int($slugOrId) || ctype_digit((string) $slugOrId)) {
            return Db::first('categories', ['id' => (int) $slugOrId]);
        }
        return Db::first('categories', ['slug' => Str::slug((string) $slugOrId, 80)]);
    }

    /** Seed the taxonomy. Idempotent; never overwrites an operator's edits. */
    public function seedTaxonomy()
    {
        $created = 0;
        $order = 0;
        foreach (self::TAXONOMY as $slug => $definition) {
            $order += 10;
            if (Db::count('categories', ['slug' => $slug]) > 0) {
                continue;
            }
            Db::insert('categories', [
                'name' => $definition[0],
                'slug' => $slug,
                'description' => $definition[1],
                'icon' => isset($definition[2]) ? $definition[2] : 'fa-cube',
                'sort_order' => $order,
                'active' => 1,
                'created_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ]);
            $created++;
        }
        return $created;
    }

    public function create(array $input)
    {
        Rbac::assert($this->actor, Rbac::APP_MANAGE);

        $name = trim((string) (isset($input['name']) ? $input['name'] : ''));
        if ($name === '') {
            throw new ValidationException('A category name is required.', ['errors' => ['name' => 'Required']]);
        }
        $slug = Str::slug(isset($input['slug']) && $input['slug'] !== '' ? $input['slug'] : $name, 80);
        if (Db::count('categories', ['slug' => $slug]) > 0) {
            throw new ValidationException('That category slug already exists.', ['errors' => ['slug' => 'Taken']]);
        }

        $id = Db::insert('categories', [
            'name' => Str::clip($name, 120),
            'slug' => $slug,
            'description' => isset($input['description']) ? Str::cleanText($input['description'], 1000) : null,
            'icon' => isset($input['icon']) ? Str::clip($input['icon'], 60) : 'fa-cube',
            'icon_url' => isset($input['icon_url']) ? Str::clip($input['icon_url'], 255) : null,
            'sort_order' => isset($input['sort_order']) ? (int) $input['sort_order'] : 100,
            'active' => !isset($input['active']) || !empty($input['active']) ? 1 : 0,
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);

        Audit::record($this->actor, 'CATEGORY_CREATED', [
            'resource_type' => 'category', 'resource_id' => $id,
            'metadata' => ['slug' => $slug, 'name' => $name],
        ]);
        return Db::first('categories', ['id' => $id]);
    }

    public function update($categoryId, array $input)
    {
        Rbac::assert($this->actor, Rbac::APP_MANAGE);
        $row = Db::first('categories', ['id' => (int) $categoryId]);
        if (!$row) {
            throw new NotFoundException('That category does not exist.');
        }

        $changes = ['updated_at' => Clock::now()];
        foreach (['name' => 120, 'description' => 1000, 'icon' => 60, 'icon_url' => 255] as $field => $length) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = Str::clip((string) $input[$field], $length);
            }
        }
        foreach (['sort_order' => 'int', 'active' => 'bool'] as $field => $cast) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $cast === 'int' ? (int) $input[$field] : (!empty($input[$field]) ? 1 : 0);
            }
        }
        Db::update('categories', $changes, ['id' => (int) $row['id']]);

        Audit::record($this->actor, 'CATEGORY_UPDATED', [
            'resource_type' => 'category', 'resource_id' => (int) $row['id'],
            'metadata' => ['changed' => array_keys($changes)],
        ]);
        return Db::first('categories', ['id' => (int) $row['id']]);
    }
}
