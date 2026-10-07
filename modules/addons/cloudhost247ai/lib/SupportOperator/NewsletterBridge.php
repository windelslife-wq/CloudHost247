<?php
/**
 * Newsletter bridge: marketing-owned subscription, AI-owned fallback.
 *
 * When the CloudHost247 marketing module is installed, subscriptions are
 * mirrored into its audience store (single source of truth). The local
 * support_newsletter table guarantees the operator still works without it
 * and records every AI-collected subscription either way.
 */

namespace Ch247Ai\SupportOperator;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Validator;

class NewsletterBridge
{
    public static function marketingAvailable()
    {
        return class_exists('Ch247Mkt\\Audience\\SubscriberService')
            && method_exists('Ch247Mkt\\Audience\\SubscriberService', 'upsert');
    }

    /**
     * Subscribe; duplicates return status 'duplicate' instead of erroring.
     * Returns ['status','email','id'] or ['status' => 'invalid'].
     */
    public static function subscribe($name, $email)
    {
        $email = strtolower(trim((string) $email));
        if (!Validator::email($email)) {
            return ['status' => 'invalid', 'email' => $email];
        }
        $name = Validator::clip(trim((string) $name), 120);
        $existing = Db::first('support_newsletter', ['email' => $email]);
        if ($existing !== null) {
            return ['status' => 'duplicate', 'email' => $email, 'id' => (int) $existing['id']];
        }
        $now = Clock::now();
        $id = Db::insert('support_newsletter', [
            'name' => $name,
            'email' => $email,
            'status' => 'subscribed',
            'source' => 'ai_assistant',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::mirrorToMarketing($name, $email);
        return ['status' => 'subscribed', 'email' => $email, 'id' => (int) $id];
    }

    /** Best-effort mirror; marketing failures never break the operator. */
    public static function mirrorToMarketing($name, $email)
    {
        if (!self::marketingAvailable()) {
            return false;
        }
        try {
            call_user_func(
                ['Ch247Mkt\\Audience\\SubscriberService', 'upsert'],
                ['email' => $email, 'name' => $name, 'source' => 'ai_assistant', 'status' => 'subscribed']
            );
            return true;
        } catch (\Exception $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function unsubscribe($email)
    {
        $email = strtolower(trim((string) $email));
        $existing = Db::first('support_newsletter', ['email' => $email]);
        if ($existing === null) {
            return false;
        }
        Db::update('support_newsletter', ['id' => (int) $existing['id']], [
            'status' => 'unsubscribed',
            'updated_at' => Clock::now(),
        ]);
        return true;
    }

    public static function list($status = '', $page = 1, $perPage = 25)
    {
        $page = max(1, (int) $page);
        $perPage = min(100, max(1, (int) $perPage));
        $where = [];
        $bind = [];
        if (in_array($status, ['subscribed', 'unsubscribed'], true)) {
            $where[] = 'status = ?';
            $bind[] = $status;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $totalRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('support_newsletter') . $whereSql, $bind);
        $total = $totalRows ? (int) $totalRows[0]['c'] : 0;
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('support_newsletter') . $whereSql
            . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
