<?php
/**
 * Template CRUD + seeding of the shipped library.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\NotFoundException;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;

class TemplateService
{
    public const CATEGORIES = ['lifecycle', 'newsletter', 'promotion', 'announcement', 'operational', 'custom'];

    /** Install or refresh the shipped templates. Safe to re-run. */
    public static function seed()
    {
        $installed = 0;
        $refreshed = 0;
        foreach (TemplateLibrary::all() as $slug => $definition) {
            $design = $definition['design'];
            $row = [
                'name'        => $definition['name'],
                'slug'        => $slug,
                'category'    => in_array($definition['category'], self::CATEGORIES, true) ? $definition['category'] : 'custom',
                'description' => $definition['description'],
                'design'      => Designer::encode($design),
                'html'        => Renderer::render($design, ['subject' => $definition['name']]),
                'is_system'   => 1,
                'updated_at'  => Clock::now(),
            ];
            $existing = Db::first('templates', ['slug' => $slug]);
            if ($existing === null) {
                $row['created_at'] = Clock::now();
                Db::insert('templates', $row);
                $installed++;
                continue;
            }
            // Only system templates are refreshed; a copy the operator edited
            // (is_system = 0) is never overwritten.
            if ((int) $existing['is_system'] === 1) {
                Db::update('templates', ['id' => (int) $existing['id']], $row);
                $refreshed++;
            }
        }
        return ['installed' => $installed, 'refreshed' => $refreshed];
    }

    public static function create(array $data, $adminId = 0)
    {
        $name = Str::clip($data['name'] ?? '', 190);
        if ($name === '') {
            throw new ValidationException('A template needs a name.', ['name' => 'required']);
        }
        $design = Designer::decode($data['design'] ?? []);
        $now = Clock::now();
        $id = Db::insert('templates', [
            'name'        => $name,
            'slug'        => self::uniqueSlug($data['slug'] ?? $name),
            'category'    => in_array($data['category'] ?? '', self::CATEGORIES, true) ? $data['category'] : 'custom',
            'description' => Str::clip($data['description'] ?? '', 1000),
            'design'      => Designer::encode($design),
            'html'        => Renderer::render($design, ['subject' => $name]),
            'is_system'   => 0,
            'created_by'  => (int) $adminId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        Audit::admin((int) $adminId, 'template.created', ['template_id' => $id, 'name' => $name]);
        return self::find($id);
    }

    public static function update($id, array $data, $adminId = 0)
    {
        $template = self::find($id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        if ((int) $template['is_system'] === 1) {
            throw new ValidationException('Shipped templates cannot be edited directly — duplicate it first.');
        }
        $set = ['updated_at' => Clock::now()];
        if (array_key_exists('name', $data)) {
            $set['name'] = Str::clip($data['name'], 190);
            if ($set['name'] === '') {
                throw new ValidationException('A template needs a name.', ['name' => 'required']);
            }
        }
        if (array_key_exists('description', $data)) {
            $set['description'] = Str::clip($data['description'], 1000);
        }
        if (array_key_exists('category', $data) && in_array($data['category'], self::CATEGORIES, true)) {
            $set['category'] = $data['category'];
        }
        if (array_key_exists('design', $data)) {
            $design = Designer::decode($data['design']);
            $set['design'] = Designer::encode($design);
            $set['html'] = Renderer::render($design, ['subject' => $set['name'] ?? $template['name']]);
        }
        Db::update('templates', ['id' => (int) $id], $set);
        Audit::admin((int) $adminId, 'template.updated', ['template_id' => (int) $id]);
        return self::find($id);
    }

    /** Duplicate any template (including shipped ones) into an editable copy. */
    public static function duplicate($id, $adminId = 0, $name = '')
    {
        $template = self::find($id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        return self::create([
            'name'        => $name !== '' ? $name : $template['name'] . ' (copy)',
            'category'    => $template['category'],
            'description' => $template['description'],
            'design'      => $template['design'],
        ], $adminId);
    }

    /** Save a campaign's current design as a reusable template. */
    public static function saveFromCampaign(array $campaign, $name, $adminId = 0)
    {
        return self::create([
            'name'        => $name,
            'category'    => 'custom',
            'description' => 'Saved from campaign "' . Str::clip($campaign['name'], 120) . '"',
            'design'      => $campaign['design'],
        ], $adminId);
    }

    public static function archive($id, $adminId = 0)
    {
        $template = self::find($id);
        if ($template === null) {
            throw new NotFoundException('Template not found.');
        }
        if ((int) $template['is_system'] === 1) {
            throw new ValidationException('Shipped templates cannot be archived.');
        }
        Db::update('templates', ['id' => (int) $id], ['archived_at' => Clock::now()]);
        Audit::admin((int) $adminId, 'template.archived', ['template_id' => (int) $id]);
        return true;
    }

    public static function find($id)
    {
        $row = Db::first('templates', ['id' => (int) $id]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function findBySlug($slug)
    {
        $row = Db::first('templates', ['slug' => (string) $slug]);
        return $row === null ? null : self::hydrate($row);
    }

    /** @return array[] grouped by category, archived excluded */
    public static function all($category = '')
    {
        $sql = 'SELECT * FROM ' . Db::t('templates') . ' WHERE archived_at IS NULL';
        $bind = [];
        if ($category !== '' && in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND category = ?';
            $bind[] = $category;
        }
        $sql .= ' ORDER BY is_system DESC, name ASC';
        return array_map([self::class, 'hydrate'], Db::query($sql, $bind));
    }

    public static function hydrate(array $row)
    {
        $row['design'] = Designer::decode($row['design']);
        return $row;
    }

    protected static function uniqueSlug($seed)
    {
        $base = Str::slug($seed, 150);
        $slug = $base;
        $i = 2;
        while (Db::count('templates', ['slug' => $slug]) > 0) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 500) {
                $slug = $base . '-' . substr(Str::token(4), 0, 6);
                break;
            }
        }
        return $slug;
    }
}
