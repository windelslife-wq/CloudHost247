<?php
/**
 * Subscriber CRUD, consent recording and status transitions.
 *
 * Consent is not optional metadata here: every write path records when, where
 * and how permission was obtained, because that record is the only defence
 * when a recipient complains.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Audience;

use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\NotFoundException;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;

class SubscriberService
{
    public const STATUS_SUBSCRIBED   = 'subscribed';
    public const STATUS_UNCONFIRMED  = 'unconfirmed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_BOUNCED      = 'bounced';
    public const STATUS_SUPPRESSED   = 'suppressed';

    public const STATUSES = [
        self::STATUS_SUBSCRIBED,
        self::STATUS_UNCONFIRMED,
        self::STATUS_UNSUBSCRIBED,
        self::STATUS_BOUNCED,
        self::STATUS_SUPPRESSED,
    ];

    /** Statuses that may receive marketing email. */
    public const MAILABLE = [self::STATUS_SUBSCRIBED];

    /**
     * Create or update a subscriber by email.
     *
     * @param array $data  email, first_name, last_name, company, client_id,
     *                     status, tags[], custom_fields[], consent_source,
     *                     consent_ip
     * @param array $options ['lists' => int[], 'actor' => 'admin'|'client'|'system', 'actor_id' => int]
     * @return array the stored row
     */
    public static function upsert(array $data, array $options = [])
    {
        $email = Str::normalizeEmail($data['email'] ?? '');
        if (!Str::isEmail($email)) {
            throw new ValidationException('"' . Str::clip($data['email'] ?? '', 60) . '" is not a valid email address.', ['email' => 'invalid']);
        }
        $hash = hash('sha256', $email);
        $existing = Db::first('subscribers', ['email_hash' => $hash]);
        $now = Clock::now();

        $doubleOptin = Settings::bool('double_optin', false);
        $requestedStatus = isset($data['status']) && in_array($data['status'], self::STATUSES, true)
            ? $data['status']
            : ($doubleOptin ? self::STATUS_UNCONFIRMED : self::STATUS_SUBSCRIBED);

        $row = [
            'email'          => $email,
            'email_hash'     => $hash,
            'first_name'     => Str::clip($data['first_name'] ?? '', 120),
            'last_name'      => Str::clip($data['last_name'] ?? '', 120),
            'company'        => Str::clip($data['company'] ?? '', 190),
            'client_id'      => (int) ($data['client_id'] ?? 0),
            'custom_fields'  => self::encode($data['custom_fields'] ?? []),
            'tags'           => self::encode(self::normalizeTags($data['tags'] ?? [])),
            'updated_at'     => $now,
        ];

        if ($existing === null) {
            $row['status']         = $requestedStatus;
            $row['consent_source'] = Str::clip($data['consent_source'] ?? ($options['actor'] ?? 'admin'), 60);
            $row['consent_at']     = $data['consent_at'] ?? $now;
            $row['consent_ip']     = Str::clip($data['consent_ip'] ?? self::requestIp(), 64);
            $row['confirm_token']  = $requestedStatus === self::STATUS_UNCONFIRMED ? Str::token(24) : null;
            $row['confirmed_at']   = $requestedStatus === self::STATUS_SUBSCRIBED ? $now : null;
            $row['created_at']     = $now;
            $id = Db::insert('subscribers', $row);
            Audit::record($options['actor'] ?? 'admin', (int) ($options['actor_id'] ?? 0), 'subscriber.created', [
                'entity_type' => 'subscriber', 'entity_id' => $id,
                'context' => ['email' => $email, 'status' => $row['status'], 'source' => $row['consent_source']],
            ]);
        } else {
            $id = (int) $existing['id'];
            // Never silently resurrect someone who opted out or hard-bounced;
            // that requires the explicit resubscribe() path.
            $locked = in_array($existing['status'], [self::STATUS_UNSUBSCRIBED, self::STATUS_BOUNCED, self::STATUS_SUPPRESSED], true);
            if (!$locked && isset($data['status']) && in_array($data['status'], self::STATUSES, true)) {
                $row['status'] = $data['status'];
            }
            // Keep the original consent record; only fill it if absent.
            if (empty($existing['consent_at'])) {
                $row['consent_source'] = Str::clip($data['consent_source'] ?? ($options['actor'] ?? 'admin'), 60);
                $row['consent_at'] = $now;
                $row['consent_ip'] = Str::clip($data['consent_ip'] ?? self::requestIp(), 64);
            }
            Db::update('subscribers', ['id' => $id], $row);
            Audit::record($options['actor'] ?? 'admin', (int) ($options['actor_id'] ?? 0), 'subscriber.updated', [
                'entity_type' => 'subscriber', 'entity_id' => $id, 'context' => ['email' => $email],
            ]);
        }

        foreach ((array) ($options['lists'] ?? []) as $listId) {
            ListService::addMember((int) $listId, $id);
        }

        return self::find($id);
    }

    public static function find($id)
    {
        $row = Db::first('subscribers', ['id' => (int) $id]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function findByEmail($email)
    {
        $row = Db::first('subscribers', ['email_hash' => hash('sha256', Str::normalizeEmail($email))]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function findByToken($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return null;
        }
        $row = Db::first('subscribers', ['confirm_token' => $token]);
        return $row === null ? null : self::hydrate($row);
    }

    /** Confirm a double opt-in subscriber. */
    public static function confirm($token)
    {
        $sub = self::findByToken($token);
        if ($sub === null) {
            throw new NotFoundException('That confirmation link is no longer valid.');
        }
        if ($sub['status'] === self::STATUS_SUBSCRIBED) {
            return $sub;
        }
        Db::update('subscribers', ['id' => (int) $sub['id']], [
            'status'        => self::STATUS_SUBSCRIBED,
            'confirmed_at'  => Clock::now(),
            'confirm_token' => null,
            'updated_at'    => Clock::now(),
        ]);
        Audit::record('client', (int) $sub['client_id'], 'subscriber.confirmed', [
            'entity_type' => 'subscriber', 'entity_id' => (int) $sub['id'], 'context' => ['email' => $sub['email']],
        ]);
        return self::find((int) $sub['id']);
    }

    /**
     * Global opt-out. Also writes the suppression entry so no future campaign,
     * list import or automation can reach them again.
     */
    public static function unsubscribe($subscriberId, array $options = [])
    {
        $sub = self::find($subscriberId);
        if ($sub === null) {
            throw new NotFoundException('Subscriber not found.');
        }
        $now = Clock::now();
        Db::update('subscribers', ['id' => (int) $sub['id']], [
            'status'          => self::STATUS_UNSUBSCRIBED,
            'unsubscribed_at' => $now,
            'updated_at'      => $now,
        ]);
        Db::exec(
            'UPDATE ' . Db::t('list_members') . ' SET status = ?, unsubscribed_at = ? WHERE subscriber_id = ?',
            [self::STATUS_UNSUBSCRIBED, $now, (int) $sub['id']]
        );
        ComplianceService::suppress($sub['email'], 'unsubscribe', [
            'source'      => $options['source'] ?? 'link',
            'campaign_id' => (int) ($options['campaign_id'] ?? 0),
        ]);
        ListService::recountAll();
        Audit::record($options['actor'] ?? 'client', (int) ($options['actor_id'] ?? 0), 'subscriber.unsubscribed', [
            'entity_type' => 'subscriber', 'entity_id' => (int) $sub['id'],
            'context' => ['email' => $sub['email'], 'source' => $options['source'] ?? 'link', 'campaign_id' => (int) ($options['campaign_id'] ?? 0)],
        ]);
        return self::find((int) $sub['id']);
    }

    /** Deliberate, audited re-subscribe — requires fresh consent evidence. */
    public static function resubscribe($subscriberId, array $consent)
    {
        $sub = self::find($subscriberId);
        if ($sub === null) {
            throw new NotFoundException('Subscriber not found.');
        }
        $source = trim((string) ($consent['consent_source'] ?? ''));
        if ($source === '') {
            throw new ValidationException('Re-subscribing requires a consent source (where this permission came from).', ['consent_source' => 'required']);
        }
        $now = Clock::now();
        Db::update('subscribers', ['id' => (int) $sub['id']], [
            'status'            => self::STATUS_SUBSCRIBED,
            'consent_source'    => Str::clip($source, 60),
            'consent_at'        => $now,
            'consent_ip'        => Str::clip($consent['consent_ip'] ?? self::requestIp(), 64),
            'unsubscribed_at'   => null,
            'bounce_count'      => 0,
            'soft_bounce_count' => 0,
            'confirmed_at'      => $now,
            'updated_at'        => $now,
        ]);
        ComplianceService::unsuppress($sub['email']);
        ListService::recountAll();
        Audit::record('admin', (int) ($consent['actor_id'] ?? 0), 'subscriber.resubscribed', [
            'entity_type' => 'subscriber', 'entity_id' => (int) $sub['id'],
            'context' => ['email' => $sub['email'], 'source' => $source],
        ]);
        return self::find((int) $sub['id']);
    }

    /** Record a bounce and suppress once the configured threshold is reached. */
    public static function recordBounce($subscriberId, $type = 'hard', array $options = [])
    {
        $sub = self::find($subscriberId);
        if ($sub === null) {
            return null;
        }
        $now = Clock::now();
        $hard = $type === 'hard';
        $set = ['last_bounce_at' => $now, 'updated_at' => $now];
        if ($hard) {
            $set['bounce_count'] = (int) $sub['bounce_count'] + 1;
        } else {
            $set['soft_bounce_count'] = (int) $sub['soft_bounce_count'] + 1;
        }
        Db::update('subscribers', ['id' => (int) $sub['id']], $set);

        $hardLimit = max(1, Settings::int('hard_bounce_threshold', 1));
        $softLimit = max(1, Settings::int('soft_bounce_threshold', 5));
        $hardCount = (int) ($set['bounce_count'] ?? $sub['bounce_count']);
        $softCount = (int) ($set['soft_bounce_count'] ?? $sub['soft_bounce_count']);

        if (Settings::bool('suppress_on_bounce', true) && ($hardCount >= $hardLimit || $softCount >= $softLimit)) {
            Db::update('subscribers', ['id' => (int) $sub['id']], ['status' => self::STATUS_BOUNCED, 'updated_at' => $now]);
            ComplianceService::suppress($sub['email'], $hard ? 'hard_bounce' : 'invalid', [
                'source'      => 'bounce',
                'campaign_id' => (int) ($options['campaign_id'] ?? 0),
                'notes'       => Str::clip($options['reason'] ?? '', 500),
            ]);
            ListService::recountAll();
        }
        return self::find((int) $sub['id']);
    }

    public static function recordComplaint($subscriberId, array $options = [])
    {
        $sub = self::find($subscriberId);
        if ($sub === null) {
            return null;
        }
        if (Settings::bool('suppress_on_complaint', true)) {
            Db::update('subscribers', ['id' => (int) $sub['id']], ['status' => self::STATUS_SUPPRESSED, 'updated_at' => Clock::now()]);
            ComplianceService::suppress($sub['email'], 'complaint', [
                'source'      => 'feedback_loop',
                'campaign_id' => (int) ($options['campaign_id'] ?? 0),
            ]);
            ListService::recountAll();
        }
        Audit::system('subscriber.complained', ['subscriber_id' => (int) $sub['id'], 'campaign_id' => (int) ($options['campaign_id'] ?? 0)]);
        return self::find((int) $sub['id']);
    }

    /** Hard delete (GDPR erasure). The suppression entry is kept by design. */
    public static function delete($subscriberId, array $options = [])
    {
        $sub = self::find($subscriberId);
        if ($sub === null) {
            throw new NotFoundException('Subscriber not found.');
        }
        Db::delete('list_members', ['subscriber_id' => (int) $sub['id']]);
        Db::delete('subscribers', ['id' => (int) $sub['id']]);
        ListService::recountAll();
        Audit::record('admin', (int) ($options['actor_id'] ?? 0), 'subscriber.deleted', [
            'entity_type' => 'subscriber', 'entity_id' => (int) $sub['id'], 'context' => ['email' => $sub['email']],
        ]);
        return true;
    }

    /* ------------------------------------------ WHMCS client lifecycle -- */

    /** Every subscriber row linked to a WHMCS client. */
    public static function forClient($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return [];
        }
        return Db::query('SELECT * FROM ' . Db::t('subscribers') . ' WHERE client_id = ?', [$clientId]);
    }

    /**
     * Mirror a WHMCS client edit onto the linked subscriber.
     *
     * Only ever updates an existing record — editing a client is not consent
     * to market to them, so this never creates one. An address change is
     * treated as a new address: the old one keeps whatever suppression it had
     * and the new one inherits the old record's status, never an upgrade.
     */
    public static function syncFromClient($clientId, array $fields)
    {
        $clientId = (int) $clientId;
        $rows = self::forClient($clientId);
        if ($rows === []) {
            return 0;
        }
        $now = Clock::now();
        $updated = 0;

        foreach ($rows as $row) {
            $set = ['updated_at' => $now];
            foreach (['first_name' => 'first_name', 'last_name' => 'last_name', 'company' => 'company'] as $in => $column) {
                if (isset($fields[$in]) && $fields[$in] !== null) {
                    $set[$column] = Str::clip((string) $fields[$in], 100);
                }
            }

            $newEmail = Str::normalizeEmail($fields['email'] ?? '');
            if ($newEmail !== '' && $newEmail !== $row['email'] && Str::isEmail($newEmail)) {
                $newHash = hash('sha256', $newEmail);
                $clash = Db::first('subscribers', ['email_hash' => $newHash]);
                if ($clash !== null) {
                    // The new address already exists as its own subscriber.
                    // Point the client link at it and retire the old row
                    // rather than merging histories we cannot reconcile.
                    Db::update('subscribers', ['id' => (int) $clash['id']], ['client_id' => $clientId, 'updated_at' => $now]);
                    Db::update('subscribers', ['id' => (int) $row['id']], ['client_id' => 0, 'updated_at' => $now]);
                    $updated++;
                    continue;
                }
                if (ComplianceService::isSuppressed($newEmail)) {
                    // They opted out under this address before. Respect that.
                    $set['status'] = self::STATUS_UNSUBSCRIBED;
                }
                $set['email'] = $newEmail;
                $set['email_hash'] = $newHash;
            }

            Db::update('subscribers', ['id' => (int) $row['id']], $set);
            $updated++;
        }

        if ($updated > 0) {
            Audit::system('subscriber.synced_from_client', ['client_id' => $clientId, 'rows' => $updated]);
        }
        return $updated;
    }

    /**
     * A closed client should stop receiving marketing, but this is not an
     * opt-out: no suppression entry is written, so if they come back their
     * original consent is still intact.
     */
    public static function deactivateForClient($clientId, $reason = '')
    {
        $now = Clock::now();
        $count = 0;
        foreach (self::forClient($clientId) as $row) {
            if (!in_array($row['status'], self::MAILABLE, true)) {
                continue;
            }
            Db::update('subscribers', ['id' => (int) $row['id']], [
                'status'     => self::STATUS_UNCONFIRMED,
                'updated_at' => $now,
            ]);
            $count++;
        }
        if ($count > 0) {
            ListService::recountAll();
            Audit::system('subscriber.deactivated', ['client_id' => (int) $clientId, 'rows' => $count, 'reason' => Str::clip($reason, 100)]);
        }
        return $count;
    }

    /**
     * Client deleted in WHMCS: erase the personal data but leave a suppression
     * entry behind, so a later CSV import cannot quietly resurrect somebody
     * who asked to be forgotten.
     */
    public static function forgetClient($clientId)
    {
        $count = 0;
        foreach (self::forClient($clientId) as $row) {
            ComplianceService::suppress($row['email'], 'manual', [
                'source' => 'client_deleted',
                'notes'  => 'WHMCS client #' . (int) $clientId . ' was deleted',
            ]);
            Db::delete('list_members', ['subscriber_id' => (int) $row['id']]);
            Db::delete('subscribers', ['id' => (int) $row['id']]);
            $count++;
        }
        if ($count > 0) {
            ListService::recountAll();
            Audit::system('subscriber.forgotten', ['client_id' => (int) $clientId, 'rows' => $count]);
        }
        return $count;
    }

    /** The client ticked "send me marketing email" in the client area. */
    public static function optInClient($clientId, $source = 'client area')
    {
        $clientId = (int) $clientId;
        $rows = self::forClient($clientId);

        if ($rows === []) {
            $client = \Ch247Mkt\Core\Whmcs::clientContext($clientId);
            if (empty($client['email'])) {
                return null;
            }
            return self::upsert([
                'email'          => $client['email'],
                'first_name'     => $client['first_name'] ?? '',
                'last_name'      => $client['last_name'] ?? '',
                'company'        => $client['company'] ?? '',
                'client_id'      => $clientId,
                'consent_source' => Str::clip($source, 60),
            ], ['actor' => 'client', 'actor_id' => $clientId]);
        }

        foreach ($rows as $row) {
            if (in_array($row['status'], self::MAILABLE, true)) {
                continue;
            }
            self::resubscribe((int) $row['id'], ['consent_source' => $source, 'actor_id' => 0]);
        }
        return self::find((int) $rows[0]['id']);
    }

    /** The client cleared the marketing checkbox — treat it as a full opt-out. */
    public static function optOutClient($clientId, $source = 'client area')
    {
        $count = 0;
        foreach (self::forClient($clientId) as $row) {
            if (!in_array($row['status'], self::MAILABLE, true)) {
                continue;
            }
            self::unsubscribe((int) $row['id'], ['source' => $source, 'actor' => 'client', 'actor_id' => (int) $clientId]);
            $count++;
        }
        return $count;
    }

    /**
     * Paginated search.
     *
     * @param array $filters status, list_id, tag, q
     * @return array{rows:array[],total:int}
     */
    public static function search(array $filters = [], $page = 1, $perPage = 50)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(200, (int) $perPage));
        $where = [];
        $bind = [];
        $joins = '';

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 's.status = ?';
            $bind[] = $filters['status'];
        }
        if (!empty($filters['list_id'])) {
            $joins .= ' INNER JOIN ' . Db::t('list_members') . ' lm ON lm.subscriber_id = s.id AND lm.list_id = ?';
            array_unshift($bind, (int) $filters['list_id']);
        }
        if (!empty($filters['tag'])) {
            $where[] = 's.tags LIKE ?';
            $bind[] = '%"' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['tag']) . '"%';
        }
        if (!empty($filters['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])) . '%';
            $where[] = '(s.email LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.company LIKE ?)';
            $bind[] = $q;
            $bind[] = $q;
            $bind[] = $q;
            $bind[] = $q;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('subscribers') . ' s' . $joins . $whereSql, $bind);
        $total = $countRows ? (int) $countRows[0]['c'] : 0;

        $rows = Db::query(
            'SELECT s.* FROM ' . Db::t('subscribers') . ' s' . $joins . $whereSql
            . ' ORDER BY s.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => array_map([self::class, 'hydrate'], $rows), 'total' => $total];
    }

    /** @return array<string,int> status => count */
    public static function statusCounts()
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach (Db::query('SELECT status, COUNT(*) AS c FROM ' . Db::t('subscribers') . ' GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /** Merge-tag context for one subscriber (WHMCS data wins when linked). */
    public static function context(array $subscriber)
    {
        $ctx = [
            'email'      => (string) $subscriber['email'],
            'first_name' => (string) $subscriber['first_name'],
            'last_name'  => (string) $subscriber['last_name'],
            'company'    => (string) $subscriber['company'],
            'client_id'  => (string) $subscriber['client_id'],
        ];
        foreach ((array) $subscriber['custom_fields'] as $key => $value) {
            $key = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $key));
            if ($key !== '' && !isset($ctx[$key]) && is_scalar($value)) {
                $ctx[$key] = (string) $value;
            }
        }
        if ((int) $subscriber['client_id'] > 0) {
            foreach (\Ch247Mkt\Core\Whmcs::clientContext((int) $subscriber['client_id']) as $key => $value) {
                if ($value !== '') {
                    $ctx[$key] = $value;
                }
            }
        }
        return $ctx;
    }

    /* --------------------------------------------------------- helpers -- */

    public static function hydrate(array $row)
    {
        $row['custom_fields'] = self::decode($row['custom_fields'] ?? '');
        $row['tags'] = array_values((array) self::decode($row['tags'] ?? ''));
        return $row;
    }

    public static function normalizeTags($tags)
    {
        if (is_string($tags)) {
            $tags = preg_split('/[,\n]/', $tags);
        }
        $out = [];
        foreach ((array) $tags as $tag) {
            $tag = Str::clip(strtolower((string) $tag), 40);
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        return $out;
    }

    protected static function encode($value)
    {
        $json = json_encode($value === null ? [] : $value, JSON_UNESCAPED_UNICODE);
        return $json === false ? '[]' : $json;
    }

    protected static function decode($json)
    {
        if (is_array($json)) {
            return $json;
        }
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : [];
    }

    protected static function requestIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }
}
