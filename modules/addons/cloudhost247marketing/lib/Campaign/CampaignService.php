<?php
/**
 * Campaign lifecycle: author -> audience -> compile -> preflight -> schedule
 * -> queue.
 *
 * State machine (anything else is rejected):
 *
 *   draft ──schedule──> scheduled ──due──> sending ──all queued──> sent
 *     │                     │                 │
 *     └──send now───────────┴─────────────────┤
 *                           │                 │
 *                      cancelled         paused ──resume──> sending
 *
 * A campaign that has started cannot have its content or audience edited.
 * That is deliberate: a half-sent campaign with two different bodies is
 * impossible to report on or defend.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SegmentService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\NotFoundException;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\QueueService;
use Ch247Mkt\Delivery\TrackingService;

class CampaignService
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED    = 'failed';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_SENDING,
        self::STATUS_PAUSED, self::STATUS_SENT, self::STATUS_CANCELLED, self::STATUS_FAILED,
    ];

    /** Statuses in which content and audience may still change. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_SCHEDULED];

    public const TYPES = ['campaign', 'automation', 'transactional'];

    /* ----------------------------------------------------------- CRUD -- */

    public static function create(array $data, $adminId = 0)
    {
        $name = Str::clip($data['name'] ?? '', 190);
        if ($name === '') {
            throw new ValidationException('Give the campaign a name so you can find it later.', ['name' => 'required']);
        }
        $type = (string) ($data['type'] ?? 'campaign');
        if ($type === '') {
            $type = 'campaign';
        }
        if (!in_array($type, self::TYPES, true)) {
            // Not defaulted silently: marketing and transactional mail follow
            // different compliance rules, so guessing which one was meant is
            // exactly the wrong thing to do.
            throw new ValidationException('Unknown campaign type "' . Str::clip($type, 40) . '".', ['type' => 'invalid']);
        }

        $design = Designer::blank();
        $templateId = (int) ($data['template_id'] ?? 0);
        if ($templateId > 0) {
            $template = TemplateService::find($templateId);
            if ($template === null) {
                throw new NotFoundException('That template no longer exists.');
            }
            $design = $template['design'];
        }

        $now = Clock::now();
        $id = Db::insert('campaigns', [
            'uid'              => self::uniqueUid(),
            'name'             => $name,
            'type'             => $type,
            'status'           => self::STATUS_DRAFT,
            'subject'          => Str::clip($data['subject'] ?? '', 255),
            'preheader'        => Str::clip($data['preheader'] ?? '', 255),
            'from_name'        => Str::clip($data['from_name'] ?? Settings::string('from_name', ''), 190),
            'from_email'       => Str::clip($data['from_email'] ?? Settings::string('from_email', ''), 190),
            'reply_to'         => Str::clip($data['reply_to'] ?? Settings::string('reply_to', ''), 190),
            'template_id'      => $templateId,
            'design'           => Designer::encode($design),
            'html'             => '',
            'text_body'        => '',
            'audience'         => self::encodeAudience($data['audience'] ?? []),
            'timezone'         => Str::clip($data['timezone'] ?? Settings::string('account_timezone', 'UTC'), 64),
            'created_by'       => (int) $adminId,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        self::recompile($id);
        Audit::admin((int) $adminId, 'campaign.created', ['campaign_id' => $id, 'name' => $name, 'type' => $type]);
        return self::find($id);
    }

    public static function update($id, array $data, $adminId = 0)
    {
        $campaign = self::requireEditable($id);
        $set = ['updated_at' => Clock::now()];

        foreach (['name' => 190, 'subject' => 255, 'preheader' => 255, 'from_name' => 190, 'from_email' => 190, 'reply_to' => 190, 'timezone' => 64] as $field => $len) {
            if (array_key_exists($field, $data)) {
                $set[$field] = Str::clip($data[$field], $len);
            }
        }
        if (isset($set['name']) && $set['name'] === '') {
            throw new ValidationException('A campaign needs a name.', ['name' => 'required']);
        }
        if (!empty($set['from_email']) && !Str::isEmail($set['from_email'])) {
            throw new ValidationException('The "from" address is not a valid email address.', ['from_email' => 'invalid']);
        }
        if (!empty($set['reply_to']) && !Str::isEmail($set['reply_to'])) {
            throw new ValidationException('The reply-to address is not a valid email address.', ['reply_to' => 'invalid']);
        }
        if (array_key_exists('audience', $data)) {
            $set['audience'] = self::encodeAudience($data['audience']);
        }
        if (array_key_exists('design', $data)) {
            $set['design'] = Designer::encode(Designer::decode($data['design']));
        }

        Db::update('campaigns', ['id' => (int) $campaign['id']], $set);
        if (array_key_exists('design', $data) || array_key_exists('subject', $data) || array_key_exists('preheader', $data)) {
            self::recompile((int) $campaign['id']);
        }
        Audit::admin((int) $adminId, 'campaign.updated', ['campaign_id' => (int) $campaign['id'], 'fields' => array_keys($set)]);
        return self::find($campaign['id']);
    }

    /**
     * Recompile design -> HTML (+ tracking) -> plain text.
     *
     * Click links are registered against the campaign here, so the click map
     * exists before the first send.
     */
    public static function recompile($id)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        $html = Renderer::render($campaign['design'], [
            'subject'   => $campaign['subject'],
            'preheader' => $campaign['preheader'],
        ]);
        $tracked = TrackingService::rewriteLinks($html, (int) $campaign['id']);
        $tracked = TrackingService::injectOpenPixel($tracked);
        // The text part is generated from the *untracked* HTML so the plain
        // text alternative shows real destinations, not redirector URLs.
        $text = Str::htmlToText($html);

        Db::update('campaigns', ['id' => (int) $campaign['id']], [
            'html'       => $tracked,
            'text_body'  => $text,
            'updated_at' => Clock::now(),
        ]);
        return self::find($id);
    }

    public static function duplicate($id, $adminId = 0, $name = '')
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        $copy = self::create([
            'name'       => $name !== '' ? $name : $campaign['name'] . ' (copy)',
            'type'       => $campaign['type'],
            'subject'    => $campaign['subject'],
            'preheader'  => $campaign['preheader'],
            'from_name'  => $campaign['from_name'],
            'from_email' => $campaign['from_email'],
            'reply_to'   => $campaign['reply_to'],
            'audience'   => $campaign['audience'],
            'timezone'   => $campaign['timezone'],
        ], $adminId);
        Db::update('campaigns', ['id' => (int) $copy['id']], ['design' => Designer::encode($campaign['design'])]);
        self::recompile((int) $copy['id']);
        Audit::admin((int) $adminId, 'campaign.duplicated', ['from' => (int) $campaign['id'], 'to' => (int) $copy['id']]);
        return self::find($copy['id']);
    }

    public static function delete($id, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        if (in_array($campaign['status'], [self::STATUS_SENDING, self::STATUS_SENT], true)) {
            throw new ValidationException('A campaign that has been sent cannot be deleted — its delivery record is part of your audit trail. Archive it instead.');
        }
        Db::delete('campaign_recipients', ['campaign_id' => (int) $id]);
        Db::delete('links', ['campaign_id' => (int) $id]);
        Db::exec('DELETE FROM ' . Db::t('email_queue') . ' WHERE campaign_id = ?', [(int) $id]);
        Db::delete('campaigns', ['id' => (int) $id]);
        Audit::admin((int) $adminId, 'campaign.deleted', ['campaign_id' => (int) $id]);
        return true;
    }

    public static function find($id)
    {
        $row = Db::first('campaigns', ['id' => (int) $id]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function findByUid($uid)
    {
        $row = Db::first('campaigns', ['uid' => (string) $uid]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function search(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(100, (int) $perPage));
        $where = [];
        $bind = [];
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = ?';
            $bind[] = $filters['status'];
        }
        if (!empty($filters['type']) && in_array($filters['type'], self::TYPES, true)) {
            $where[] = 'type = ?';
            $bind[] = $filters['type'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(name LIKE ? OR subject LIKE ?)';
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])) . '%';
            $bind[] = $q;
            $bind[] = $q;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $countRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('campaigns') . $whereSql, $bind);
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('campaigns') . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => array_map([self::class, 'hydrate'], $rows), 'total' => $countRows ? (int) $countRows[0]['c'] : 0];
    }

    /* ------------------------------------------------------- audience -- */

    /**
     * Resolve the audience to a deduplicated, suppression-filtered list.
     *
     * @return array{recipients:array[],stats:array{raw:int,deduplicated:int,suppressed:int,excluded:int,unmailable:int}}
     */
    public static function resolveAudience(array $campaign)
    {
        $audience = $campaign['audience'];
        $candidates = [];
        $stats = ['raw' => 0, 'deduplicated' => 0, 'suppressed' => 0, 'excluded' => 0, 'unmailable' => 0];

        foreach ((array) $audience['lists'] as $listId) {
            foreach (self::membersOfList((int) $listId) as $row) {
                $stats['raw']++;
                $email = Str::normalizeEmail($row['email']);
                if (!isset($candidates[$email])) {
                    $candidates[$email] = $row;
                }
            }
        }
        foreach ((array) $audience['segments'] as $segmentId) {
            $segment = SegmentService::find((int) $segmentId);
            if ($segment === null) {
                continue;
            }
            foreach (SegmentService::resolve($segment) as $row) {
                $stats['raw']++;
                $email = Str::normalizeEmail($row['email']);
                if (!isset($candidates[$email])) {
                    $candidates[$email] = $row;
                }
            }
        }

        $stats['deduplicated'] = $stats['raw'] - count($candidates);

        // Exclusions.
        $excluded = [];
        foreach ((array) $audience['exclude_lists'] as $listId) {
            foreach (self::membersOfList((int) $listId, true) as $row) {
                $excluded[Str::normalizeEmail($row['email'])] = true;
            }
        }
        foreach ((array) $audience['exclude_segments'] as $segmentId) {
            $segment = SegmentService::find((int) $segmentId);
            if ($segment === null) {
                continue;
            }
            foreach (SegmentService::resolve($segment) as $row) {
                $excluded[Str::normalizeEmail($row['email'])] = true;
            }
        }
        foreach (array_keys($excluded) as $email) {
            if (isset($candidates[$email])) {
                unset($candidates[$email]);
                $stats['excluded']++;
            }
        }

        // Suppression list — the final, non-negotiable filter.
        $suppressed = ComplianceService::suppressedAmong(array_keys($candidates));
        foreach (array_keys($suppressed) as $email) {
            if (isset($candidates[$email])) {
                unset($candidates[$email]);
                $stats['suppressed']++;
            }
        }

        // Invalid addresses that slipped in from WHMCS.
        foreach ($candidates as $email => $row) {
            if (!Str::isEmail($email)) {
                unset($candidates[$email]);
                $stats['unmailable']++;
            }
        }

        return ['recipients' => array_values($candidates), 'stats' => $stats];
    }

    /** @return array[] mailable members of a list */
    protected static function membersOfList($listId, $anyStatus = false)
    {
        $sql = 'SELECT s.id AS subscriber_id, s.email, s.first_name, s.last_name, s.company, s.client_id
                  FROM ' . Db::t('list_members') . ' lm
                  INNER JOIN ' . Db::t('subscribers') . ' s ON s.id = lm.subscriber_id
                 WHERE lm.list_id = ?';
        $bind = [(int) $listId];
        if (!$anyStatus) {
            $sql .= ' AND lm.status = ? AND s.status = ?';
            $bind[] = SubscriberService::STATUS_SUBSCRIBED;
            $bind[] = SubscriberService::STATUS_SUBSCRIBED;
        }
        $out = [];
        foreach (Db::query($sql, $bind) as $row) {
            $out[] = [
                'subscriber_id' => (int) $row['subscriber_id'],
                'email'         => (string) $row['email'],
                'first_name'    => (string) $row['first_name'],
                'last_name'     => (string) $row['last_name'],
                'company'       => (string) $row['company'],
                'client_id'     => (int) $row['client_id'],
            ];
        }
        return $out;
    }

    /**
     * Freeze the audience into campaign_recipients.
     *
     * WHMCS-sourced recipients get a subscriber record created on the fly
     * (consent source "whmcs_client") so unsubscribe, suppression and the
     * per-recipient ledger all work identically regardless of origin.
     */
    public static function buildRecipients($id, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        $resolved = self::resolveAudience($campaign);
        if ($resolved['recipients'] === []) {
            throw new ValidationException('This audience resolves to zero mailable recipients. Check your lists, segments and suppression list.');
        }

        Db::delete('campaign_recipients', ['campaign_id' => (int) $campaign['id']]);
        $now = Clock::now();
        $inserted = 0;

        foreach ($resolved['recipients'] as $row) {
            $email = Str::normalizeEmail($row['email']);
            $subscriberId = (int) $row['subscriber_id'];

            if ($subscriberId === 0) {
                $existing = SubscriberService::findByEmail($email);
                if ($existing !== null) {
                    $subscriberId = (int) $existing['id'];
                } else {
                    $created = SubscriberService::upsert([
                        'email'          => $email,
                        'first_name'     => $row['first_name'],
                        'last_name'      => $row['last_name'],
                        'company'        => $row['company'],
                        'client_id'      => (int) $row['client_id'],
                        'status'         => SubscriberService::STATUS_SUBSCRIBED,
                        'consent_source' => 'whmcs_client',
                    ], ['actor' => 'system', 'actor_id' => (int) $adminId]);
                    $subscriberId = (int) $created['id'];
                }
            }

            Db::insert('campaign_recipients', [
                'campaign_id'   => (int) $campaign['id'],
                'subscriber_id' => $subscriberId,
                'email'         => $email,
                'email_hash'    => hash('sha256', $email),
                'name'          => Str::clip(trim($row['first_name'] . ' ' . $row['last_name']), 190),
                'client_id'     => (int) $row['client_id'],
                'status'        => 'pending',
                'token'         => self::uniqueRecipientToken(),
                'created_at'    => $now,
            ]);
            $inserted++;
        }

        Db::update('campaigns', ['id' => (int) $campaign['id']], [
            'total_recipients' => $inserted,
            'updated_at'       => $now,
        ]);
        Audit::admin((int) $adminId, 'campaign.audience_built', [
            'campaign_id' => (int) $campaign['id'], 'recipients' => $inserted, 'stats' => $resolved['stats'],
        ]);
        return ['count' => $inserted, 'stats' => $resolved['stats']];
    }

    /* ------------------------------------------------------- preflight -- */

    /**
     * Everything the operator should see before sending.
     *
     * @return array{blockers:string[],warnings:string[],content:array,merge:array,audience:array}
     */
    public static function preflight($id)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        $compliance = ComplianceService::preflight($campaign);
        $content = ComplianceService::contentCheck($campaign['subject'], $campaign['html'], $campaign['text_body']);

        // Merge-tag audit against a real recipient when one exists.
        $sample = self::sampleContext($campaign);
        $merge = Personalizer::audit($campaign['subject'] . ' ' . $campaign['html'], $sample);

        $blockers = $compliance['blockers'];
        $warnings = $compliance['warnings'];
        if (!$merge['ok']) {
            $blockers[] = 'Unknown merge tags: {{' . implode('}}, {{', $merge['unknown']) . '}}. Remove them or use a supported tag.';
        }
        if ($merge['unresolved'] !== []) {
            $warnings[] = 'These tags have no value for your sample recipient and will render empty: {{' . implode('}}, {{', $merge['unresolved']) . '}}.';
        }

        $audience = self::resolveAudience($campaign);
        if ($audience['recipients'] === []) {
            $blockers[] = 'This audience resolves to zero mailable recipients.';
        }

        return [
            'ok'       => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'content'  => $content,
            'merge'    => $merge,
            'audience' => ['count' => count($audience['recipients'])] + $audience['stats'],
        ];
    }

    /** A representative merge context: first real recipient, else placeholders. */
    public static function sampleContext(array $campaign)
    {
        $recipient = Db::first('campaign_recipients', ['campaign_id' => (int) $campaign['id']], 'id ASC');
        if ($recipient !== null && (int) $recipient['subscriber_id'] > 0) {
            $subscriber = SubscriberService::find((int) $recipient['subscriber_id']);
            if ($subscriber !== null) {
                return self::contextFor($campaign, $recipient, $subscriber);
            }
        }
        $resolved = self::resolveAudience($campaign);
        if ($resolved['recipients'] !== []) {
            $row = $resolved['recipients'][0];
            $subscriber = SubscriberService::findByEmail($row['email']);
            if ($subscriber !== null) {
                return self::contextFor($campaign, ['token' => 'PREVIEW', 'email' => $row['email']], $subscriber);
            }
            return array_merge(self::systemContext($campaign, 'PREVIEW'), [
                'email' => $row['email'], 'first_name' => $row['first_name'],
                'last_name' => $row['last_name'], 'company' => $row['company'],
                'client_id' => (string) $row['client_id'],
            ]);
        }
        return array_merge(self::systemContext($campaign, 'PREVIEW'), [
            'email' => 'sample@example.com', 'first_name' => 'Sample', 'last_name' => 'Recipient',
            'company' => 'Example Ltd', 'client_id' => '0',
        ]);
    }

    /** Full merge context for one recipient. */
    public static function contextFor(array $campaign, array $recipient, array $subscriber)
    {
        return array_merge(
            SubscriberService::context($subscriber),
            self::systemContext($campaign, (string) $recipient['token'])
        );
    }

    public static function systemContext(array $campaign, $recipientToken)
    {
        return [
            'company_name'     => Settings::string('company_name', ''),
            'physical_address' => Settings::string('physical_address', ''),
            'current_year'     => gmdate('Y', Clock::time()),
            'unsubscribe_url'  => TrackingService::unsubscribeUrl($recipientToken),
            'preferences_url'  => TrackingService::preferencesUrl($recipientToken),
            'webview_url'      => TrackingService::webviewUrl($recipientToken),
        ];
    }

    /** Rendered HTML for the preview pane / webview. */
    public static function preview(array $campaign, array $context = [])
    {
        if ($context === []) {
            $context = self::sampleContext($campaign);
        }
        return Personalizer::apply($campaign['html'], $context, true);
    }

    /* ------------------------------------------------------ transitions -- */

    public static function schedule($id, $when, $timezone, $adminId = 0)
    {
        $campaign = self::requireEditable($id);
        $tz = self::safeTimezone($timezone);
        $at = self::toUtc($when, $tz);
        if ($at === null) {
            throw new ValidationException('That is not a valid date and time.', ['scheduled_at' => 'invalid']);
        }
        if (Clock::toTime($at) <= Clock::time()) {
            throw new ValidationException('Pick a time in the future (times are interpreted in ' . $tz . ').', ['scheduled_at' => 'past']);
        }
        $check = self::preflight($id);
        if ($check['blockers'] !== []) {
            throw new ValidationException('This campaign cannot be scheduled yet: ' . implode(' ', $check['blockers']));
        }
        Db::update('campaigns', ['id' => (int) $campaign['id']], [
            'status'       => self::STATUS_SCHEDULED,
            'scheduled_at' => $at,
            'timezone'     => $tz,
            'cancelled_at' => null,
            'updated_at'   => Clock::now(),
        ]);
        Audit::admin((int) $adminId, 'campaign.scheduled', ['campaign_id' => (int) $campaign['id'], 'at_utc' => $at, 'timezone' => $tz]);
        return self::find($id);
    }

    /**
     * Start sending now: freeze the audience, enqueue, flip to sending.
     *
     * The cron worker does the actual delivery — this never sends inside the
     * web request.
     */
    public static function send($id, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        if (!in_array($campaign['status'], [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true)) {
            throw new ValidationException('Only a draft or scheduled campaign can be sent (this one is "' . $campaign['status'] . '").');
        }
        $check = self::preflight($id);
        if ($check['blockers'] !== []) {
            throw new ValidationException('This campaign cannot be sent yet: ' . implode(' ', $check['blockers']));
        }

        self::buildRecipients($id, $adminId);
        $queued = QueueService::enqueueCampaign((int) $campaign['id']);

        Db::update('campaigns', ['id' => (int) $campaign['id']], [
            'status'       => self::STATUS_SENDING,
            'started_at'   => Clock::now(),
            'scheduled_at' => $campaign['scheduled_at'] ?: Clock::now(),
            'updated_at'   => Clock::now(),
        ]);
        Audit::admin((int) $adminId, 'campaign.send_started', ['campaign_id' => (int) $campaign['id'], 'queued' => $queued]);
        return ['queued' => $queued];
    }

    public static function pause($id, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        if (!in_array($campaign['status'], [self::STATUS_SENDING, self::STATUS_SCHEDULED], true)) {
            throw new ValidationException('Only a sending or scheduled campaign can be paused.');
        }
        Db::update('campaigns', ['id' => (int) $id], ['status' => self::STATUS_PAUSED, 'paused_at' => Clock::now(), 'updated_at' => Clock::now()]);
        Audit::admin((int) $adminId, 'campaign.paused', ['campaign_id' => (int) $id]);
        return self::find($id);
    }

    public static function resume($id, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        if ($campaign['status'] !== self::STATUS_PAUSED) {
            throw new ValidationException('Only a paused campaign can be resumed.');
        }
        // Resume into whichever state it was in: already-queued work means it
        // was sending, otherwise it goes back to scheduled.
        $pending = Db::count('email_queue', ['campaign_id' => (int) $id, 'status' => 'pending']);
        $status = $pending > 0 ? self::STATUS_SENDING : ($campaign['scheduled_at'] ? self::STATUS_SCHEDULED : self::STATUS_DRAFT);
        Db::update('campaigns', ['id' => (int) $id], ['status' => $status, 'paused_at' => null, 'updated_at' => Clock::now()]);
        Audit::admin((int) $adminId, 'campaign.resumed', ['campaign_id' => (int) $id, 'status' => $status]);
        return self::find($id);
    }

    public static function cancel($id, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        if (in_array($campaign['status'], [self::STATUS_SENT, self::STATUS_CANCELLED], true)) {
            throw new ValidationException('That campaign is already finished.');
        }
        $cancelled = QueueService::cancelCampaign((int) $id);
        Db::exec(
            'UPDATE ' . Db::t('campaign_recipients') . ' SET status = ?, skip_reason = ? WHERE campaign_id = ? AND status IN (?, ?)',
            ['cancelled', 'campaign_cancelled', (int) $id, 'pending', 'queued']
        );
        Db::update('campaigns', ['id' => (int) $id], [
            'status' => self::STATUS_CANCELLED, 'cancelled_at' => Clock::now(),
            'finished_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        Audit::admin((int) $adminId, 'campaign.cancelled', ['campaign_id' => (int) $id, 'cancelled_queue_rows' => $cancelled]);
        return ['cancelled' => $cancelled];
    }

    /** Called by the worker once the queue for a campaign is drained. */
    public static function finishIfDrained($id)
    {
        $campaign = self::find($id);
        if ($campaign === null || $campaign['status'] !== self::STATUS_SENDING) {
            return false;
        }
        $outstanding = Db::query(
            'SELECT COUNT(*) AS c FROM ' . Db::t('email_queue') . ' WHERE campaign_id = ? AND status IN (?, ?)',
            [(int) $id, 'pending', 'processing']
        );
        if ($outstanding && (int) $outstanding[0]['c'] > 0) {
            return false;
        }
        Db::update('campaigns', ['id' => (int) $id], [
            'status' => self::STATUS_SENT, 'finished_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        Audit::system('campaign.completed', ['campaign_id' => (int) $id]);
        return true;
    }

    /**
     * A scheduled campaign could not be started by the worker.
     *
     * It goes back to draft rather than silently retrying every five minutes:
     * whatever the blocker is (no sender address, empty audience, transport
     * misconfigured) needs a human, and a campaign that quietly keeps failing
     * in the background is worse than one that visibly stopped.
     */
    public static function markFailedToStart($id, $reason)
    {
        $campaign = self::find($id);
        if ($campaign === null || $campaign['status'] !== self::STATUS_SCHEDULED) {
            return false;
        }
        Db::update('campaigns', ['id' => (int) $id], [
            'status'       => self::STATUS_DRAFT,
            'scheduled_at' => null,
            'updated_at'   => Clock::now(),
        ]);
        Audit::system('campaign.schedule_failed', [
            'campaign_id' => (int) $id,
            'reason'      => Str::clip($reason, 300),
        ]);
        Logger::error('scheduled campaign could not start', ['campaign_id' => (int) $id, 'reason' => Str::clip($reason, 300)]);
        return true;
    }

    /**
     * "View this email in your browser".
     *
     * Rendered from the frozen HTML with that recipient's real merge data, so
     * it matches what landed in their inbox — but with tracking stripped,
     * because a web view is not another open.
     *
     * @return string|null
     */
    public static function webview($campaignId, array $recipient)
    {
        $campaign = self::find($campaignId);
        if ($campaign === null) {
            return null;
        }
        if (!in_array($campaign['status'], [self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_PAUSED], true)) {
            return null;
        }
        $subscriber = (int) $recipient['subscriber_id'] > 0
            ? SubscriberService::find((int) $recipient['subscriber_id'])
            : null;
        if ($subscriber === null) {
            $subscriber = SubscriberService::hydrate([
                'email'      => (string) $recipient['email'],
                'first_name' => (string) ($recipient['first_name'] ?? ''),
                'last_name'  => (string) ($recipient['last_name'] ?? ''),
            ]);
        }
        $context = self::contextFor($campaign, $recipient, $subscriber);

        // Rendered fresh from the frozen design rather than reusing the
        // stored body: the stored body is the *tracked* one, and opening a
        // web view in a browser must not add an open or a click to the
        // campaign's numbers.
        $html = Renderer::render($campaign['design'], [
            'subject'   => $campaign['subject'],
            'preheader' => $campaign['preheader'],
        ]);
        $html = str_replace(TrackingService::RECIPIENT_SENTINEL, (string) $recipient['token'], $html);
        return Personalizer::apply($html, $context, true);
    }

    /** Campaigns whose scheduled time has arrived. @return array[] */
    public static function dueForSending($limit = 10)
    {
        return array_map([self::class, 'hydrate'], Db::query(
            'SELECT * FROM ' . Db::t('campaigns') . '
              WHERE status = ? AND scheduled_at IS NOT NULL AND scheduled_at <= ?
           ORDER BY scheduled_at ASC LIMIT ' . (int) $limit,
            [self::STATUS_SCHEDULED, Clock::now()]
        ));
    }

    /* ----------------------------------------------------- test sends -- */

    /** Send a one-off copy to the given addresses. Never touches the audience. */
    public static function sendTest($id, array $emails, $adminId = 0)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        $clean = [];
        foreach ($emails as $email) {
            $email = Str::normalizeEmail($email);
            if (Str::isEmail($email)) {
                $clean[$email] = true;
            }
        }
        $clean = array_keys($clean);
        if ($clean === []) {
            throw new ValidationException('Enter at least one valid email address to test with.', ['test_emails' => 'required']);
        }
        if (count($clean) > 10) {
            throw new ValidationException('Test sends are limited to 10 addresses at a time.');
        }
        if (trim((string) $campaign['html']) === '') {
            throw new ValidationException('There is nothing to send — the campaign has no content.');
        }

        $context = self::sampleContext($campaign);
        $queued = 0;
        foreach ($clean as $email) {
            $subject = '[TEST] ' . Personalizer::apply($campaign['subject'], $context, false);
            $html = Personalizer::apply(
                str_replace(TrackingService::RECIPIENT_SENTINEL, 'TEST', $campaign['html']),
                $context,
                true
            );
            QueueService::enqueueOneOff([
                'to_email'  => $email,
                'subject'   => $subject,
                'html'      => $html,
                'text'      => Personalizer::apply($campaign['text_body'], $context, false),
                'from_name' => $campaign['from_name'],
                'from_email' => $campaign['from_email'],
                'reply_to'  => $campaign['reply_to'],
                'tag'       => 'test:' . $campaign['uid'],
            ]);
            $queued++;
        }
        Audit::admin((int) $adminId, 'campaign.test_sent', ['campaign_id' => (int) $id, 'addresses' => count($clean)]);
        return ['queued' => $queued];
    }

    /* --------------------------------------------------------- helpers -- */

    public static function hydrate(array $row)
    {
        $row['design'] = Designer::decode($row['design']);
        $row['audience'] = self::decodeAudience($row['audience']);
        return $row;
    }

    public static function encodeAudience($audience)
    {
        if (is_string($audience)) {
            $decoded = json_decode($audience, true);
            $audience = is_array($decoded) ? $decoded : [];
        }
        $audience = (array) $audience;
        $clean = [];
        foreach (['lists', 'segments', 'exclude_lists', 'exclude_segments'] as $key) {
            $values = array_values(array_unique(array_map('intval', (array) ($audience[$key] ?? []))));
            $clean[$key] = array_values(array_filter($values, function ($v) {
                return $v > 0;
            }));
        }
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"lists":[],"segments":[],"exclude_lists":[],"exclude_segments":[]}' : $json;
    }

    public static function decodeAudience($json)
    {
        $data = is_array($json) ? $json : json_decode((string) $json, true);
        $data = is_array($data) ? $data : [];
        foreach (['lists', 'segments', 'exclude_lists', 'exclude_segments'] as $key) {
            $data[$key] = array_map('intval', (array) ($data[$key] ?? []));
        }
        return $data;
    }

    /** Human summary of the audience selection. */
    public static function describeAudience(array $campaign)
    {
        $parts = [];
        foreach ($campaign['audience']['lists'] as $listId) {
            $list = ListService::find($listId);
            if ($list !== null) {
                $parts[] = 'List: ' . $list['name'];
            }
        }
        foreach ($campaign['audience']['segments'] as $segmentId) {
            $segment = SegmentService::find($segmentId);
            if ($segment !== null) {
                $parts[] = 'Segment: ' . $segment['name'];
            }
        }
        foreach ($campaign['audience']['exclude_lists'] as $listId) {
            $list = ListService::find($listId);
            if ($list !== null) {
                $parts[] = 'Excluding list: ' . $list['name'];
            }
        }
        foreach ($campaign['audience']['exclude_segments'] as $segmentId) {
            $segment = SegmentService::find($segmentId);
            if ($segment !== null) {
                $parts[] = 'Excluding segment: ' . $segment['name'];
            }
        }
        return $parts === [] ? 'No audience selected yet' : implode(' · ', $parts);
    }

    protected static function requireEditable($id)
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }
        if (!in_array($campaign['status'], self::EDITABLE, true)) {
            throw new ValidationException('A campaign that is "' . $campaign['status'] . '" can no longer be edited. Duplicate it to make changes.');
        }
        return $campaign;
    }

    public static function safeTimezone($timezone)
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return Settings::string('account_timezone', 'UTC');
        }
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /** Interpret an operator-entered local time and return UTC 'Y-m-d H:i:s'. */
    public static function toUtc($when, $timezone)
    {
        $when = trim((string) $when);
        if ($when === '') {
            return null;
        }
        try {
            $dt = new \DateTime(str_replace('T', ' ', $when), new \DateTimeZone($timezone));
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Convert a stored UTC timestamp back to the campaign's timezone. */
    public static function toLocal($utc, $timezone)
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        try {
            $dt = new \DateTime((string) $utc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone(self::safeTimezone($timezone)));
            return $dt->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return (string) $utc;
        }
    }

    protected static function uniqueUid()
    {
        for ($i = 0; $i < 10; $i++) {
            $uid = substr(hash('sha256', uniqid('ch247m', true) . random_bytes(8)), 0, 32);
            if (Db::count('campaigns', ['uid' => $uid]) === 0) {
                return $uid;
            }
        }
        return substr(hash('sha256', uniqid('ch247m', true)), 0, 32);
    }

    protected static function uniqueRecipientToken()
    {
        for ($i = 0; $i < 10; $i++) {
            $token = substr(hash('sha256', uniqid('r', true) . random_bytes(8)), 0, 32);
            if (Db::count('campaign_recipients', ['token' => $token]) === 0) {
                return $token;
            }
        }
        return substr(hash('sha256', uniqid('r', true)), 0, 32);
    }
}
