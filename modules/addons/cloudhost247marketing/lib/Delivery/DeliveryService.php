<?php
/**
 * The worker: turns queue rows into delivered mail.
 *
 * Renders per recipient at dispatch time (one campaign HTML body in memory,
 * personalised per message), hands each Message to the configured transport,
 * and records the outcome against the recipient ledger and the event stream.
 *
 * Stops early on: kill switch, sending disabled, rate budget exhausted, wall
 * clock exceeded. That last one matters — a cron run that overruns its
 * interval is how you end up with two workers fighting.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Delivery;

use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Campaign\Personalizer;
use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Transport\Message;
use Ch247Mkt\Transport\TransportFactory;

class DeliveryService
{
    /** @var array<int,array> campaign cache for the current batch */
    protected static $campaignCache = [];

    /**
     * Process one batch.
     *
     * @return array{claimed:int,sent:int,failed:int,retried:int,skipped:int,stopped:string}
     */
    public static function processBatch($limit = null, $workerId = '')
    {
        $summary = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0, 'skipped' => 0, 'stopped' => ''];

        if (Settings::bool('kill_switch', false)) {
            $summary['stopped'] = 'kill_switch';
            return $summary;
        }
        if (!Settings::bool('sending_enabled', false)) {
            $summary['stopped'] = 'sending_disabled';
            return $summary;
        }

        $limit = $limit === null ? Settings::int('queue_batch_size', 200) : (int) $limit;
        $budget = QueueService::rateBudget();
        if ($budget <= 0) {
            $summary['stopped'] = 'rate_limit';
            return $summary;
        }
        $limit = (int) min($limit, $budget);
        $workerId = $workerId !== '' ? $workerId : self::workerId();

        QueueService::releaseStaleLocks();
        $rows = QueueService::claimBatch($limit, $workerId);
        $summary['claimed'] = count($rows);
        if ($rows === []) {
            return $summary;
        }

        $transport = TransportFactory::current();
        if (!$transport->isConfigured()) {
            // Release the claims rather than burning attempts on a
            // misconfiguration the operator has to fix anyway.
            foreach ($rows as $row) {
                Db::update('email_queue', ['id' => (int) $row['id']], [
                    'status' => QueueService::STATUS_PENDING, 'locked_by' => null, 'locked_until' => null,
                    'last_error' => Str::clip('Transport not configured: ' . $transport->configurationProblem(), 1000),
                ]);
            }
            $summary['stopped'] = 'transport_unconfigured';
            Logger::error('Delivery halted: transport not configured', ['problem' => $transport->configurationProblem()]);
            return $summary;
        }

        $deadline = Clock::time() + max(10, Settings::int('queue_wall_clock_seconds', 50));
        self::$campaignCache = [];
        $touchedCampaigns = [];

        foreach ($rows as $row) {
            if (Clock::time() >= $deadline) {
                // Put the rest back untouched for the next run.
                Db::update('email_queue', ['id' => (int) $row['id']], [
                    'status' => QueueService::STATUS_PENDING, 'locked_by' => null, 'locked_until' => null,
                ]);
                $summary['stopped'] = 'wall_clock';
                continue;
            }

            try {
                $message = self::buildMessage($row);
            } catch (SkipDelivery $e) {
                QueueService::markFailed((int) $row['id'], $e->getMessage(), true);
                self::recordFailure($row, $e->getMessage(), 'skipped');
                $summary['skipped']++;
                continue;
            } catch (\Throwable $e) {
                QueueService::markFailed((int) $row['id'], 'Render failed: ' . $e->getMessage(), true);
                self::recordFailure($row, 'Render failed: ' . $e->getMessage(), 'failed');
                $summary['failed']++;
                Logger::error('Message render failed', ['queue_id' => (int) $row['id'], 'reason' => $e->getMessage()]);
                continue;
            }

            if ((int) $row['campaign_id'] > 0) {
                $touchedCampaigns[(int) $row['campaign_id']] = true;
            }

            $result = $transport->send($message);

            if ($result->ok) {
                QueueService::markSent((int) $row['id'], $result->messageId);
                self::recordSent($row, $result->messageId);
                $summary['sent']++;
                continue;
            }

            $outcome = QueueService::markFailed((int) $row['id'], $result->error, $result->permanent);
            if ($outcome['dead']) {
                self::recordFailure($row, $result->error, $result->permanent ? 'bounced' : 'failed', $result->bounceType);
                $summary['failed']++;
            } else {
                $summary['retried']++;
            }
        }

        TransportFactory::release();

        foreach (array_keys($touchedCampaigns) as $campaignId) {
            CampaignService::finishIfDrained($campaignId);
        }

        return $summary;
    }

    /* ---------------------------------------------------------- render -- */

    /** @throws SkipDelivery when the recipient must not be mailed */
    public static function buildMessage(array $row)
    {
        $campaignId = (int) $row['campaign_id'];

        // One-off message with an inline body.
        if ($campaignId === 0) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                throw new SkipDelivery('This queued message has no payload.');
            }
            return Message::make([
                'to_email'   => $row['to_email'],
                'to_name'    => $row['to_name'],
                'from_email' => $payload['from_email'] ?: Settings::string('from_email', ''),
                'from_name'  => $payload['from_name'] ?: Settings::string('from_name', ''),
                'reply_to'   => $payload['reply_to'] ?: Settings::string('reply_to', ''),
                'subject'    => $payload['subject'] ?? '',
                'html'       => $payload['html'] ?? '',
                'text'       => $payload['text'] ?? '',
                'headers'    => array_merge(['X-CH247M-Type' => 'oneoff'], (array) ($payload['headers'] ?? [])),
            ]);
        }

        $campaign = self::campaign($campaignId);
        if ($campaign === null) {
            throw new SkipDelivery('The campaign no longer exists.');
        }

        $recipient = Db::first('campaign_recipients', ['id' => (int) $row['recipient_id']]);
        if ($recipient === null) {
            throw new SkipDelivery('The recipient record no longer exists.');
        }

        // Last-moment suppression check: someone may have unsubscribed between
        // the audience being frozen and this message being dispatched.
        if (ComplianceService::isSuppressed((string) $recipient['email'])) {
            Db::update('campaign_recipients', ['id' => (int) $recipient['id']], [
                'status' => 'skipped', 'skip_reason' => 'suppressed',
            ]);
            throw new SkipDelivery('Recipient was suppressed before this message was sent.');
        }

        $subscriber = (int) $recipient['subscriber_id'] > 0
            ? SubscriberService::find((int) $recipient['subscriber_id'])
            : null;
        $context = $subscriber !== null
            ? CampaignService::contextFor($campaign, $recipient, $subscriber)
            : array_merge(
                CampaignService::systemContext($campaign, (string) $recipient['token']),
                ['email' => (string) $recipient['email']]
            );

        // Swap the tracking sentinel for this recipient's token, then merge.
        $html = str_replace(TrackingService::RECIPIENT_SENTINEL, (string) $recipient['token'], (string) $campaign['html']);
        $html = Personalizer::apply($html, $context, true);
        $text = Personalizer::apply((string) $campaign['text_body'], $context, false);
        $subject = Personalizer::apply((string) $campaign['subject'], $context, false);

        $headers = [
            'X-CH247M-Campaign'  => (string) $campaign['uid'],
            'X-CH247M-Recipient' => (string) $recipient['token'],
            'Precedence'         => 'bulk',
            'Auto-Submitted'     => 'auto-generated',
        ];

        // RFC 2369 / RFC 8058 — the header Gmail and Outlook use to show a
        // native "Unsubscribe" control next to the sender name.
        if ($campaign['type'] !== 'transactional' && Settings::bool('list_unsubscribe_header', true)) {
            $unsubscribeUrl = TrackingService::unsubscribeUrl((string) $recipient['token']);
            if (strpos($unsubscribeUrl, 'http') === 0) {
                $headers['List-Unsubscribe'] = '<' . $unsubscribeUrl . '>';
                $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
            }
        }

        return Message::make([
            'to_email'   => $recipient['email'],
            'to_name'    => $recipient['name'],
            'from_email' => $campaign['from_email'] ?: Settings::string('from_email', ''),
            'from_name'  => $campaign['from_name'] ?: Settings::string('from_name', ''),
            'reply_to'   => $campaign['reply_to'] ?: Settings::string('reply_to', ''),
            'subject'    => $subject,
            'html'       => $html,
            'text'       => $text,
            'headers'    => $headers,
        ]);
    }

    /* --------------------------------------------------------- outcome -- */

    protected static function recordSent(array $row, $messageId = '')
    {
        $recipientId = (int) $row['recipient_id'];
        if ($recipientId === 0) {
            return;
        }
        $campaignId = self::campaignIdFor($row);
        $now = Clock::now();
        Db::update('campaign_recipients', ['id' => $recipientId], [
            'status'  => 'sent',
            'sent_at' => $now,
            // Without provider webhooks, accepted-by-transport is the best
            // delivery signal we have. A later bounce corrects it.
            'delivered_at' => $now,
        ]);
        Db::exec(
            'UPDATE ' . Db::t('campaigns') . ' SET count_sent = count_sent + 1, count_delivered = count_delivered + 1 WHERE id = ?',
            [$campaignId]
        );
        if ((int) $row['subscriber_id'] > 0) {
            Db::update('subscribers', ['id' => (int) $row['subscriber_id']], ['last_sent_at' => $now]);
        }
        TrackingService::event('sent', [
            'campaign_id' => $campaignId, 'id' => $recipientId, 'subscriber_id' => (int) $row['subscriber_id'],
        ], $messageId !== '' ? ['message_id' => $messageId] : []);
    }

    protected static function recordFailure(array $row, $error, $status = 'failed', $bounceType = '')
    {
        $recipientId = (int) $row['recipient_id'];
        if ($recipientId === 0) {
            Logger::warning('One-off message failed', ['to' => $row['to_email'], 'error' => Str::clip($error, 200)]);
            return;
        }
        $campaignId = self::campaignIdFor($row);
        $now = Clock::now();
        $set = ['status' => $status, 'failed_reason' => Str::clip($error, 1000)];
        if ($status === 'bounced') {
            $set['bounced_at'] = $now;
            $set['bounce_type'] = $bounceType !== '' ? $bounceType : 'hard';
        }
        Db::update('campaign_recipients', ['id' => $recipientId], $set);

        $column = $status === 'bounced' ? 'count_bounced' : 'count_failed';
        Db::exec('UPDATE ' . Db::t('campaigns') . ' SET ' . $column . ' = ' . $column . ' + 1 WHERE id = ?', [$campaignId]);

        TrackingService::event($status === 'bounced' ? 'bounce' : 'failed', [
            'campaign_id' => $campaignId, 'id' => $recipientId, 'subscriber_id' => (int) $row['subscriber_id'],
        ], ['error' => Str::clip($error, 300), 'bounce_type' => $bounceType]);

        if ($status === 'bounced' && (int) $row['subscriber_id'] > 0) {
            SubscriberService::recordBounce((int) $row['subscriber_id'], $bounceType === 'soft' ? 'soft' : 'hard', [
                'campaign_id' => $campaignId, 'reason' => $error,
            ]);
        }
    }

    /**
     * Which campaign an outcome belongs to.
     *
     * Broadcast rows carry the campaign on the queue row. Automation sends are
     * queued as one-offs (campaign_id 0) because they ship a pre-rendered body,
     * so the campaign is read back off the recipient ledger instead — otherwise
     * their sends, bounces and events would be attributed to nothing.
     */
    protected static function campaignIdFor(array $row)
    {
        if ((int) $row['campaign_id'] > 0) {
            return (int) $row['campaign_id'];
        }
        $recipient = Db::first('campaign_recipients', ['id' => (int) $row['recipient_id']]);
        return $recipient === null ? 0 : (int) $recipient['campaign_id'];
    }

    /* --------------------------------------------------------- helpers -- */

    protected static function campaign($id)
    {
        $id = (int) $id;
        if (!array_key_exists($id, self::$campaignCache)) {
            self::$campaignCache[$id] = CampaignService::find($id);
        }
        return self::$campaignCache[$id];
    }

    public static function workerId()
    {
        return substr(gethostname() ?: 'worker', 0, 24) . ':' . getmypid() . ':' . substr(Str::token(4), 0, 6);
    }

    /** Send a single ad-hoc message immediately (used by "verify transport"). */
    public static function sendNow(array $data)
    {
        $transport = TransportFactory::current();
        if (!$transport->isConfigured()) {
            return \Ch247Mkt\Transport\Result::permanent($transport->configurationProblem());
        }
        $message = Message::make(array_merge([
            'from_email' => Settings::string('from_email', ''),
            'from_name'  => Settings::string('from_name', ''),
            'reply_to'   => Settings::string('reply_to', ''),
        ], $data));
        $result = $transport->send($message);
        TransportFactory::release();
        Audit::system('transport.direct_send', [
            'to' => $message->toEmail, 'ok' => $result->ok, 'error' => Str::clip($result->error, 200),
        ]);
        return $result;
    }
}

/** Internal control-flow signal: do not send, do not retry. */
class SkipDelivery extends \RuntimeException
{
}
