<?php
/**
 * Mailing lists and membership.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Audience;

use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\NotFoundException;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;

class ListService
{
    public static function create(array $data, $adminId = 0)
    {
        $name = Str::clip($data['name'] ?? '', 190);
        if ($name === '') {
            throw new ValidationException('A list needs a name.', ['name' => 'required']);
        }
        $slug = self::uniqueSlug($data['slug'] ?? $name);
        $now = Clock::now();
        $id = Db::insert('lists', [
            'name'             => $name,
            'slug'             => $slug,
            'description'      => Str::clip($data['description'] ?? '', 2000),
            'from_name'        => Str::clip($data['from_name'] ?? '', 190),
            'from_email'       => Str::clip($data['from_email'] ?? '', 190),
            'reply_to'         => Str::clip($data['reply_to'] ?? '', 190),
            'double_optin'     => !empty($data['double_optin']) ? 1 : 0,
            'subscriber_count' => 0,
            'created_by'       => (int) $adminId,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        Audit::admin((int) $adminId, 'list.created', ['list_id' => $id, 'name' => $name]);
        return self::find($id);
    }

    public static function update($id, array $data, $adminId = 0)
    {
        $list = self::find($id);
        if ($list === null) {
            throw new NotFoundException('List not found.');
        }
        $set = ['updated_at' => Clock::now()];
        foreach (['name' => 190, 'description' => 2000, 'from_name' => 190, 'from_email' => 190, 'reply_to' => 190] as $field => $len) {
            if (array_key_exists($field, $data)) {
                $set[$field] = Str::clip($data[$field], $len);
            }
        }
        if (array_key_exists('double_optin', $data)) {
            $set['double_optin'] = !empty($data['double_optin']) ? 1 : 0;
        }
        if (isset($set['name']) && $set['name'] === '') {
            throw new ValidationException('A list needs a name.', ['name' => 'required']);
        }
        Db::update('lists', ['id' => (int) $id], $set);
        Audit::admin((int) $adminId, 'list.updated', ['list_id' => (int) $id]);
        return self::find($id);
    }

    public static function archive($id, $adminId = 0)
    {
        if (self::find($id) === null) {
            throw new NotFoundException('List not found.');
        }
        Db::update('lists', ['id' => (int) $id], ['archived_at' => Clock::now(), 'updated_at' => Clock::now()]);
        Audit::admin((int) $adminId, 'list.archived', ['list_id' => (int) $id]);
        return true;
    }

    public static function restore($id, $adminId = 0)
    {
        Db::update('lists', ['id' => (int) $id], ['archived_at' => null, 'updated_at' => Clock::now()]);
        Audit::admin((int) $adminId, 'list.restored', ['list_id' => (int) $id]);
        return self::find($id);
    }

    public static function find($id)
    {
        return Db::first('lists', ['id' => (int) $id]);
    }

    public static function findBySlug($slug)
    {
        return Db::first('lists', ['slug' => (string) $slug]);
    }

    /** @return array[] active lists, newest first */
    public static function all($includeArchived = false)
    {
        if ($includeArchived) {
            return Db::all('lists', [], 'name ASC');
        }
        return Db::query('SELECT * FROM ' . Db::t('lists') . ' WHERE archived_at IS NULL ORDER BY name ASC');
    }

    public static function addMember($listId, $subscriberId, $status = SubscriberService::STATUS_SUBSCRIBED)
    {
        $listId = (int) $listId;
        $subscriberId = (int) $subscriberId;
        if ($listId <= 0 || $subscriberId <= 0) {
            return false;
        }
        if (self::find($listId) === null) {
            throw new NotFoundException('List #' . $listId . ' does not exist.');
        }
        $existing = Db::first('list_members', ['list_id' => $listId, 'subscriber_id' => $subscriberId]);
        if ($existing !== null) {
            if ($existing['status'] !== $status && $status === SubscriberService::STATUS_SUBSCRIBED) {
                Db::update('list_members', ['id' => (int) $existing['id']], [
                    'status' => $status, 'subscribed_at' => Clock::now(), 'unsubscribed_at' => null,
                ]);
                self::recount($listId);
            }
            return true;
        }
        Db::insert('list_members', [
            'list_id'       => $listId,
            'subscriber_id' => $subscriberId,
            'status'        => $status,
            'subscribed_at' => Clock::now(),
        ]);
        self::recount($listId);
        return true;
    }

    public static function removeMember($listId, $subscriberId)
    {
        Db::delete('list_members', ['list_id' => (int) $listId, 'subscriber_id' => (int) $subscriberId]);
        self::recount((int) $listId);
        return true;
    }

    /** @return int[] list ids a subscriber belongs to */
    public static function listsFor($subscriberId)
    {
        $out = [];
        foreach (Db::all('list_members', ['subscriber_id' => (int) $subscriberId]) as $row) {
            $out[] = (int) $row['list_id'];
        }
        return $out;
    }

    /** Recompute the denormalised mailable count for one list. */
    public static function recount($listId)
    {
        $rows = Db::query(
            'SELECT COUNT(*) AS c FROM ' . Db::t('list_members') . ' lm
               INNER JOIN ' . Db::t('subscribers') . ' s ON s.id = lm.subscriber_id
              WHERE lm.list_id = ? AND lm.status = ? AND s.status = ?',
            [(int) $listId, SubscriberService::STATUS_SUBSCRIBED, SubscriberService::STATUS_SUBSCRIBED]
        );
        $count = $rows ? (int) $rows[0]['c'] : 0;
        Db::update('lists', ['id' => (int) $listId], ['subscriber_count' => $count]);
        return $count;
    }

    public static function recountAll()
    {
        foreach (Db::all('lists') as $list) {
            self::recount((int) $list['id']);
        }
    }

    protected static function uniqueSlug($seed)
    {
        $base = Str::slug($seed, 150);
        $slug = $base;
        $i = 2;
        while (Db::count('lists', ['slug' => $slug]) > 0) {
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
