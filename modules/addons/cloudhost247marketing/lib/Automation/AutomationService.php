<?php
/**
 * Automations — event-triggered, multi-step journeys.
 *
 * Shape: a WHMCS event fires a trigger, the trigger enrols a subscriber, and
 * the cron walks each enrollment one step at a time. Steps are small and
 * boring on purpose: wait, send, tag, list, end.
 *
 * Two rules the implementation is built around:
 *
 *  1. **The cron advances; the hook only enrols.** A WHMCS hook runs inside
 *     someone's checkout or admin save. It must never render an email or talk
 *     to an SMTP server. Enrolment is one INSERT; everything slow happens
 *     later, out of band.
 *
 *  2. **One step per tick, and waits are absolute.** `next_run_at` is a
 *     timestamp, not a countdown, so a cron that misses six hours resumes
 *     correctly instead of replaying or skipping steps.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Automation;

use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Campaign\Personalizer;
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

class AutomationService
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED    = 'failed';

    /** Events a hook may raise. Anything else is ignored. */
    public const TRIGGERS = [
        'client.created'           => 'A new client account is created (with marketing opt-in)',
        'order.placed'             => 'An order is placed through the cart',
        'invoice.created'          => 'An invoice is generated',
        'invoice.paid'             => 'An invoice is paid',
        'service.activated'        => 'A service finishes provisioning',
        'service.suspended'        => 'A service is suspended',
        'service.unsuspended'      => 'A service is unsuspended',
        'service.terminated'       => 'A service is terminated',
        'service.cancel_requested' => 'A client requests cancellation',
        'ticket.opened'            => 'A support ticket is opened',
        'affiliate.activated'      => 'A client joins the affiliate programme',
        'domain.transferred'       => 'A domain transfer completes',
        'cart.abandoned'           => 'A cart is left untouched past the idle window',
        'manual'                   => 'Enrolled by an administrator only',
    ];

    public const ACTIONS = [
        'wait'          => 'Wait',
        'send_email'    => 'Send a campaign',
        'add_tag'       => 'Add a tag',
        'remove_tag'    => 'Remove a tag',
        'add_to_list'   => 'Add to a list',
        'end'           => 'End the journey',
    ];

    /** Hard ceiling on enrollments advanced per cron run. */
    public const TICK_LIMIT = 200;

    /* ----------------------------------------------------------- CRUD -- */

    public static function create(array $data, $adminId = 0)
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Give the automation a name.', ['name' => 'required']);
        }
        $trigger = (string) ($data['trigger_event'] ?? 'manual');
        if (!isset(self::TRIGGERS[$trigger])) {
            throw new ValidationException('Unknown trigger event.', ['trigger_event' => 'invalid']);
        }
        $now = Clock::now();
        $id = Db::insert('automations', [
            'name'            => Str::clip($name, 190),
            'slug'            => self::uniqueSlug($name),
            'description'     => Str::clip($data['description'] ?? '', 500),
            'trigger_event'   => $trigger,
            'trigger_filter'  => json_encode((array) ($data['trigger_filter'] ?? []), JSON_UNESCAPED_SLASHES),
            'enabled'         => 0, // always starts off
            'reentry_allowed' => !empty($data['reentry_allowed']) ? 1 : 0,
            'created_by'      => (int) $adminId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        Audit::record('admin', (int) $adminId, 'automation.created', [
            'entity_type' => 'automation', 'entity_id' => (int) $id, 'context' => ['name' => $name, 'trigger' => $trigger],
        ]);
        return self::find($id);
    }

    public static function update($id, array $data, $adminId = 0)
    {
        $automation = self::find($id);
        if ($automation === null) {
            throw new NotFoundException('Automation not found.');
        }
        $set = ['updated_at' => Clock::now()];
        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $set['name'] = Str::clip($data['name'], 190);
        }
        if (array_key_exists('description', $data)) {
            $set['description'] = Str::clip($data['description'], 500);
        }
        if (isset($data['trigger_event']) && isset(self::TRIGGERS[$data['trigger_event']])) {
            $set['trigger_event'] = (string) $data['trigger_event'];
        }
        if (array_key_exists('trigger_filter', $data)) {
            $set['trigger_filter'] = json_encode((array) $data['trigger_filter'], JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('reentry_allowed', $data)) {
            $set['reentry_allowed'] = !empty($data['reentry_allowed']) ? 1 : 0;
        }
        if (array_key_exists('enabled', $data)) {
            $enabled = !empty($data['enabled']);
            if ($enabled && self::steps((int) $id) === []) {
                throw new ValidationException('Add at least one step before enabling this automation.');
            }
            $set['enabled'] = $enabled ? 1 : 0;
        }
        Db::update('automations', ['id' => (int) $id], $set);
        Audit::record('admin', (int) $adminId, 'automation.updated', [
            'entity_type' => 'automation', 'entity_id' => (int) $id, 'context' => array_keys($set),
        ]);
        return self::find($id);
    }

    public static function delete($id, $adminId = 0)
    {
        $automation = self::find($id);
        if ($automation === null) {
            throw new NotFoundException('Automation not found.');
        }
        Db::delete('automation_steps', ['automation_id' => (int) $id]);
        Db::delete('automation_enrollments', ['automation_id' => (int) $id]);
        Db::delete('automations', ['id' => (int) $id]);
        Audit::record('admin', (int) $adminId, 'automation.deleted', [
            'entity_type' => 'automation', 'entity_id' => (int) $id, 'context' => ['name' => $automation['name']],
        ]);
        return true;
    }

    public static function find($id)
    {
        $row = Db::first('automations', ['id' => (int) $id]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function all($enabledOnly = false)
    {
        $sql = 'SELECT * FROM ' . Db::t('automations');
        $bind = [];
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = ?';
            $bind[] = 1;
        }
        return array_map([self::class, 'hydrate'], Db::query($sql . ' ORDER BY name ASC', $bind));
    }

    /* ---------------------------------------------------------- steps -- */

    public static function addStep($automationId, array $step, $adminId = 0)
    {
        $automation = self::find($automationId);
        if ($automation === null) {
            throw new NotFoundException('Automation not found.');
        }
        $action = (string) ($step['action'] ?? '');
        if (!isset(self::ACTIONS[$action])) {
            throw new ValidationException('Unknown step action.', ['action' => 'invalid']);
        }
        if ($action === 'send_email') {
            $campaignId = (int) ($step['campaign_id'] ?? 0);
            $campaign = CampaignService::find($campaignId);
            if ($campaign === null) {
                throw new ValidationException('Choose the campaign this step should send.', ['campaign_id' => 'required']);
            }
        }
        $existing = self::steps((int) $automationId);
        $position = (int) ($step['position'] ?? (count($existing) + 1));

        $id = Db::insert('automation_steps', [
            'automation_id' => (int) $automationId,
            'position'      => $position,
            'action'        => $action,
            'wait_seconds'  => max(0, (int) ($step['wait_seconds'] ?? 0)),
            'campaign_id'   => (int) ($step['campaign_id'] ?? 0),
            'config'        => json_encode((array) ($step['config'] ?? []), JSON_UNESCAPED_SLASHES),
            'created_at'    => Clock::now(),
        ]);
        Audit::record('admin', (int) $adminId, 'automation.step_added', [
            'entity_type' => 'automation', 'entity_id' => (int) $automationId, 'context' => ['action' => $action, 'position' => $position],
        ]);
        return Db::first('automation_steps', ['id' => (int) $id]);
    }

    public static function removeStep($stepId, $adminId = 0)
    {
        $step = Db::first('automation_steps', ['id' => (int) $stepId]);
        if ($step === null) {
            return false;
        }
        Db::delete('automation_steps', ['id' => (int) $stepId]);
        Audit::record('admin', (int) $adminId, 'automation.step_removed', [
            'entity_type' => 'automation', 'entity_id' => (int) $step['automation_id'], 'context' => ['step_id' => (int) $stepId],
        ]);
        return true;
    }

    /** @return array[] ordered steps */
    public static function steps($automationId)
    {
        return Db::query(
            'SELECT * FROM ' . Db::t('automation_steps') . ' WHERE automation_id = ? ORDER BY position ASC, id ASC',
            [(int) $automationId]
        );
    }

    /* -------------------------------------------------------- triggers -- */

    /**
     * Raise an event. Called from hooks — must stay cheap and never throw.
     *
     * @return int enrollments created
     */
    public static function trigger($event, array $context = [])
    {
        if (!isset(self::TRIGGERS[$event]) || $event === 'manual') {
            return 0;
        }
        if (!Settings::bool('service_enabled', true) || !Settings::bool('automations_enabled', true)) {
            return 0;
        }

        $created = 0;
        try {
            $automations = Db::query(
                'SELECT * FROM ' . Db::t('automations') . ' WHERE enabled = ? AND trigger_event = ?',
                [1, (string) $event]
            );
            if ($automations === []) {
                return 0;
            }
            $subscriberId = self::resolveSubscriber($context);
            if ($subscriberId <= 0) {
                return 0;
            }
            foreach ($automations as $row) {
                if (!self::filterMatches(self::hydrate($row), $context)) {
                    continue;
                }
                if (self::enroll((int) $row['id'], $subscriberId, $context) !== null) {
                    $created++;
                }
            }
        } catch (\Throwable $e) {
            Logger::error('automation trigger failed', ['event' => $event, 'message' => $e->getMessage()]);
        }
        return $created;
    }

    /**
     * Enrol a subscriber. Returns null when they are already enrolled (and
     * re-entry is off), suppressed, or not mailable.
     */
    public static function enroll($automationId, $subscriberId, array $context = [])
    {
        $automation = self::find($automationId);
        if ($automation === null) {
            return null;
        }
        $subscriber = SubscriberService::find($subscriberId);
        if ($subscriber === null) {
            return null;
        }
        if (!in_array($subscriber['status'], SubscriberService::MAILABLE, true)) {
            return null;
        }
        if (ComplianceService::isSuppressed((string) $subscriber['email'])) {
            return null;
        }

        if (!$automation['reentry_allowed']) {
            $prior = Db::count('automation_enrollments', [
                'automation_id' => (int) $automationId,
                'subscriber_id' => (int) $subscriberId,
            ]);
            if ($prior > 0) {
                return null;
            }
        } else {
            // Even with re-entry on, never run two copies concurrently.
            $live = Db::query(
                'SELECT COUNT(*) AS c FROM ' . Db::t('automation_enrollments') . '
                  WHERE automation_id = ? AND subscriber_id = ? AND status IN (?, ?)',
                [(int) $automationId, (int) $subscriberId, self::STATUS_ACTIVE, self::STATUS_WAITING]
            );
            if ($live && (int) $live[0]['c'] > 0) {
                return null;
            }
        }

        $id = Db::insert('automation_enrollments', [
            'automation_id' => (int) $automationId,
            'subscriber_id' => (int) $subscriberId,
            'current_step'  => 0,
            'status'        => self::STATUS_ACTIVE,
            'next_run_at'   => Clock::now(),
            'context'       => json_encode(self::cleanContext($context), JSON_UNESCAPED_SLASHES),
            'enrolled_at'   => Clock::now(),
        ]);
        Db::update('automations', ['id' => (int) $automationId], [
            'enrolled_count' => (int) $automation['enrolled_count'] + 1,
        ]);
        return Db::first('automation_enrollments', ['id' => (int) $id]);
    }

    public static function cancelEnrollment($enrollmentId, $reason = '')
    {
        $row = Db::first('automation_enrollments', ['id' => (int) $enrollmentId]);
        if ($row === null) {
            return false;
        }
        Db::update('automation_enrollments', ['id' => (int) $enrollmentId], [
            'status'       => self::STATUS_CANCELLED,
            'completed_at' => Clock::now(),
        ]);
        Audit::system('automation.enrollment_cancelled', ['enrollment_id' => (int) $enrollmentId, 'reason' => Str::clip($reason, 200)]);
        return true;
    }

    /* ------------------------------------------------------------ tick -- */

    /**
     * Advance every due enrollment by one step.
     *
     * @return array{enrolled:int,advanced:int,completed:int,failed:int}
     */
    public static function tick($limit = self::TICK_LIMIT)
    {
        $summary = ['enrolled' => 0, 'advanced' => 0, 'completed' => 0, 'failed' => 0];
        if (!Settings::bool('automations_enabled', true)) {
            return $summary;
        }

        $summary['enrolled'] = self::sweepAbandonedCarts();

        $due = Db::query(
            'SELECT * FROM ' . Db::t('automation_enrollments') . '
              WHERE status IN (?, ?) AND (next_run_at IS NULL OR next_run_at <= ?)
           ORDER BY next_run_at ASC, id ASC LIMIT ' . max(1, (int) $limit),
            [self::STATUS_ACTIVE, self::STATUS_WAITING, Clock::now()]
        );

        foreach ($due as $enrollment) {
            try {
                $outcome = self::advance($enrollment);
                if ($outcome === 'completed') {
                    $summary['completed']++;
                } elseif ($outcome !== 'noop') {
                    $summary['advanced']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                Db::update('automation_enrollments', ['id' => (int) $enrollment['id']], [
                    'status'       => self::STATUS_FAILED,
                    'completed_at' => Clock::now(),
                ]);
                Logger::error('automation step failed', [
                    'enrollment_id' => (int) $enrollment['id'],
                    'message'       => $e->getMessage(),
                ]);
            }
        }
        return $summary;
    }

    /** @return string advanced|completed|noop */
    protected static function advance(array $enrollment)
    {
        $automation = self::find((int) $enrollment['automation_id']);
        if ($automation === null || !$automation['enabled']) {
            self::finish($enrollment, self::STATUS_CANCELLED);
            return 'completed';
        }

        $subscriber = SubscriberService::find((int) $enrollment['subscriber_id']);
        if ($subscriber === null
            || !in_array($subscriber['status'], SubscriberService::MAILABLE, true)
            || ComplianceService::isSuppressed((string) $subscriber['email'])) {
            // Somebody who unsubscribed mid-journey drops out immediately.
            self::finish($enrollment, self::STATUS_CANCELLED);
            return 'completed';
        }

        $steps = self::steps((int) $automation['id']);
        $index = (int) $enrollment['current_step'];
        if (!isset($steps[$index])) {
            self::finish($enrollment, self::STATUS_COMPLETED);
            Db::update('automations', ['id' => (int) $automation['id']], [
                'completed_count' => (int) $automation['completed_count'] + 1,
            ]);
            return 'completed';
        }

        $step = $steps[$index];
        $context = json_decode((string) $enrollment['context'], true);
        $context = is_array($context) ? $context : [];
        $action = (string) $step['action'];

        switch ($action) {
            case 'wait':
                $seconds = max(60, (int) $step['wait_seconds']);
                Db::update('automation_enrollments', ['id' => (int) $enrollment['id']], [
                    'current_step' => $index + 1,
                    'status'       => self::STATUS_WAITING,
                    'next_run_at'  => Clock::in($seconds),
                ]);
                return 'advanced';

            case 'send_email':
                self::sendStep($automation, $step, $subscriber, $context);
                break;

            case 'add_tag':
            case 'remove_tag':
                self::tagStep($action, $step, $subscriber);
                break;

            case 'add_to_list':
                $listId = (int) (self::config($step)['list_id'] ?? 0);
                if ($listId > 0) {
                    ListService::addMember($listId, (int) $subscriber['id']);
                }
                break;

            case 'end':
                self::finish($enrollment, self::STATUS_COMPLETED);
                return 'completed';
        }

        Db::update('automation_enrollments', ['id' => (int) $enrollment['id']], [
            'current_step' => $index + 1,
            'status'       => self::STATUS_ACTIVE,
            'next_run_at'  => Clock::now(),
        ]);
        return 'advanced';
    }

    /**
     * Queue one personalised copy of a campaign.
     *
     * Automation mail goes through the same queue, the same transport and the
     * same suppression checks as a broadcast — the only difference is that
     * the audience is a single person.
     */
    protected static function sendStep(array $automation, array $step, array $subscriber, array $context)
    {
        $campaign = CampaignService::find((int) $step['campaign_id']);
        if ($campaign === null || trim((string) $campaign['html']) === '') {
            throw new ValidationException('Automation step references a campaign with no content.');
        }

        // A per-send recipient row gives the message real tracking: its own
        // token, its own unsubscribe link, its own row in the report.
        $recipient = self::recipientFor($campaign, $subscriber);
        $merge = array_merge(
            CampaignService::contextFor($campaign, $recipient, $subscriber),
            self::mergeFromContext($context)
        );

        $html = str_replace(TrackingService::RECIPIENT_SENTINEL, (string) $recipient['token'], (string) $campaign['html']);
        $text = str_replace(TrackingService::RECIPIENT_SENTINEL, (string) $recipient['token'], (string) $campaign['text_body']);

        QueueService::enqueueOneOff([
            'to_email'      => $subscriber['email'],
            'to_name'       => trim($subscriber['first_name'] . ' ' . $subscriber['last_name']),
            'subscriber_id' => (int) $subscriber['id'],
            'recipient_id'  => (int) $recipient['id'],
            'subject'       => Personalizer::apply((string) $campaign['subject'], $merge, false),
            'html'          => Personalizer::apply($html, $merge, true),
            'text'          => Personalizer::apply($text, $merge, false),
            'from_name'     => (string) $campaign['from_name'],
            'from_email'    => (string) $campaign['from_email'],
            'reply_to'      => (string) $campaign['reply_to'],
            'headers'       => [
                'List-Unsubscribe'      => '<' . TrackingService::unsubscribeUrl((string) $recipient['token']) . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'X-CH247M-Automation'   => (string) $automation['slug'],
            ],
            'tag' => 'automation:' . $automation['slug'],
        ]);
    }

    /** Create (or reuse) the campaign_recipients row backing one automated send. */
    protected static function recipientFor(array $campaign, array $subscriber)
    {
        $hash = hash('sha256', (string) $subscriber['email']);
        $existing = Db::first('campaign_recipients', [
            'campaign_id' => (int) $campaign['id'],
            'email_hash'  => $hash,
        ]);
        if ($existing !== null) {
            return $existing;
        }
        $token = Str::token(16);
        Db::insert('campaign_recipients', [
            'campaign_id'   => (int) $campaign['id'],
            'subscriber_id' => (int) $subscriber['id'],
            'email'         => (string) $subscriber['email'],
            'email_hash'    => $hash,
            'name'          => Str::clip(trim($subscriber['first_name'] . ' ' . $subscriber['last_name']), 190),
            'client_id'     => (int) ($subscriber['client_id'] ?? 0),
            'token'         => $token,
            'status'        => 'queued',
            'created_at'    => Clock::now(),
        ]);
        return Db::first('campaign_recipients', ['token' => $token]);
    }

    protected static function tagStep($action, array $step, array $subscriber)
    {
        $tag = trim((string) (self::config($step)['tag'] ?? ''));
        if ($tag === '') {
            return;
        }
        $tags = SubscriberService::normalizeTags($subscriber['tags']);
        if ($action === 'add_tag') {
            $tags[] = $tag;
        } else {
            $tags = array_values(array_filter($tags, function ($existing) use ($tag) {
                return strcasecmp($existing, $tag) !== 0;
            }));
        }
        Db::update('subscribers', ['id' => (int) $subscriber['id']], [
            'tags'       => json_encode(array_values(array_unique($tags)), JSON_UNESCAPED_SLASHES),
            'updated_at' => Clock::now(),
        ]);
    }

    protected static function finish(array $enrollment, $status)
    {
        Db::update('automation_enrollments', ['id' => (int) $enrollment['id']], [
            'status'       => $status,
            'next_run_at'  => null,
            'completed_at' => Clock::now(),
        ]);
    }

    /* -------------------------------------------------- abandoned cart -- */

    /** A logged-in client has items in their cart right now. */
    public static function touchCart($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return false;
        }
        $now = Clock::now();
        $existing = Db::first('cart_activity', ['client_id' => $clientId]);
        if ($existing === null) {
            Db::insert('cart_activity', [
                'client_id'     => $clientId,
                'last_seen_at'  => $now,
                'converted_at'  => null,
                'notified_at'   => null,
                'created_at'    => $now,
            ]);
            return true;
        }
        Db::update('cart_activity', ['client_id' => $clientId], [
            'last_seen_at' => $now,
            'converted_at' => null,
            'notified_at'  => null,
        ]);
        return true;
    }

    /** They checked out — nothing was abandoned. */
    public static function clearCart($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return false;
        }
        if (Db::first('cart_activity', ['client_id' => $clientId]) === null) {
            return false;
        }
        Db::update('cart_activity', ['client_id' => $clientId], ['converted_at' => Clock::now()]);
        return true;
    }

    /**
     * Raise cart.abandoned for carts idle longer than the configured window.
     *
     * Runs from the cron, never from a web request, and marks each cart as
     * notified so a long-idle cart only ever fires once.
     *
     * @return int triggers raised
     */
    public static function sweepAbandonedCarts()
    {
        $minutes = max(15, Settings::int('abandoned_cart_minutes', 120));
        $cutoff = Clock::ago($minutes * 60);
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('cart_activity') . '
              WHERE converted_at IS NULL AND notified_at IS NULL AND last_seen_at <= ?
           ORDER BY last_seen_at ASC LIMIT 100',
            [$cutoff]
        );
        $count = 0;
        foreach ($rows as $row) {
            Db::update('cart_activity', ['id' => (int) $row['id']], ['notified_at' => Clock::now()]);
            $count += self::trigger('cart.abandoned', ['userid' => (int) $row['client_id']]);
        }
        return $count;
    }

    /* --------------------------------------------------------- helpers -- */

    /**
     * Map a hook payload to a subscriber.
     *
     * A WHMCS client is only reachable if they already have a subscriber
     * record — automations never manufacture consent.
     */
    protected static function resolveSubscriber(array $context)
    {
        $clientId = (int) ($context['userid'] ?? $context['client_id'] ?? 0);
        if ($clientId > 0) {
            $rows = SubscriberService::forClient($clientId);
            if ($rows !== []) {
                return (int) $rows[0]['id'];
            }
            return 0;
        }
        if (!empty($context['email'])) {
            $row = SubscriberService::findByEmail((string) $context['email']);
            return $row === null ? 0 : (int) $row['id'];
        }
        return 0;
    }

    /**
     * Optional equality filter on the trigger payload, e.g. only fire for
     * product 14: {"packageid": "14"}.
     */
    protected static function filterMatches(array $automation, array $context)
    {
        $filter = $automation['trigger_filter'];
        if ($filter === []) {
            return true;
        }
        foreach ($filter as $key => $expected) {
            if (!array_key_exists($key, $context)) {
                return false;
            }
            $actual = (string) $context[$key];
            $wanted = array_map('trim', explode(',', (string) $expected));
            if (!in_array($actual, $wanted, true)) {
                return false;
            }
        }
        return true;
    }

    /** Trigger payload values exposed as merge tags inside the email. */
    protected static function mergeFromContext(array $context)
    {
        $out = [];
        foreach (['invoiceid' => 'invoice_number', 'domain' => 'domain', 'serviceid' => 'service_id'] as $key => $tag) {
            if (isset($context[$key]) && $context[$key] !== '') {
                $out[$tag] = (string) $context[$key];
            }
        }
        return $out;
    }

    /** Scalars only — an enrollment context must stay small and printable. */
    protected static function cleanContext(array $context)
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $out[Str::clip((string) $key, 40)] = Str::clip((string) $value, 190);
            }
        }
        return $out;
    }

    protected static function config(array $step)
    {
        $config = json_decode((string) ($step['config'] ?? ''), true);
        return is_array($config) ? $config : [];
    }

    protected static function uniqueSlug($name)
    {
        $base = Str::slug($name) ?: 'automation';
        $slug = $base;
        $i = 2;
        while (Db::count('automations', ['slug' => $slug]) > 0) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 500) {
                $slug = $base . '-' . Str::token(4);
                break;
            }
        }
        return $slug;
    }

    public static function hydrate(array $row)
    {
        $filter = json_decode((string) ($row['trigger_filter'] ?? ''), true);
        $row['trigger_filter'] = is_array($filter) ? $filter : [];
        $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
        $row['reentry_allowed'] = (int) ($row['reentry_allowed'] ?? 0) === 1;
        return $row;
    }
}
