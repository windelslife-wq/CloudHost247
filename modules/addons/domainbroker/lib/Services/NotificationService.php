<?php
/**
 * Domain Broker — notifications.
 *
 * Every notification is persisted first (so the in-app bell and the audit
 * trail always agree) and then dispatched to the enabled channels. Email goes
 * out through WHMCS' own mail pipeline via the gateway, so it is logged
 * against the client and respects the operator's SMTP/brand configuration
 * rather than calling mail() behind WHMCS' back.
 *
 * A dispatch failure never aborts the business operation that triggered it:
 * the row is marked failed and the cron retries.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\Http;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Integration\Gateway;

class NotificationService
{
    /* ------------------------------------------------------------ events */

    const REQUEST_SUBMITTED    = 'request.submitted';
    const BROKER_ASSIGNED      = 'broker.assigned';
    const OWNER_CONTACTED      = 'owner.contacted';
    const OWNER_RESPONDED      = 'owner.responded';
    const OFFER_RECEIVED       = 'offer.received';
    const COUNTEROFFER_RECEIVED = 'counteroffer.received';
    const OFFER_ACCEPTED       = 'offer.accepted';
    const OFFER_REJECTED       = 'offer.rejected';
    const OFFER_EXPIRING       = 'offer.expiring';
    const OFFER_EXPIRED        = 'offer.expired';
    const PAYMENT_REQUIRED     = 'payment.required';
    const PAYMENT_RECEIVED     = 'payment.received';
    const PAYMENT_FAILED       = 'payment.failed';
    const PAYMENT_EXPIRED      = 'payment.expired';
    const FUNDS_SECURED        = 'payment.secured';
    const REFUND_ISSUED        = 'payment.refunded';
    const TRANSFER_STARTED     = 'transfer.started';
    const TRANSFER_ACTION      = 'transfer.action_required';
    const TRANSFER_COMPLETED   = 'transfer.completed';
    const TRANSFER_FAILED      = 'transfer.failed';
    const REQUEST_COMPLETED    = 'request.completed';
    const REQUEST_EXPIRED      = 'request.expired';
    const REQUEST_CANCELLED    = 'request.cancelled';
    const REQUEST_REJECTED     = 'request.rejected';
    const DISPUTE_OPENED       = 'dispute.opened';
    const DISPUTE_RESOLVED     = 'dispute.resolved';
    const MESSAGE_RECEIVED     = 'message.received';
    const RISK_FLAGGED         = 'risk.flagged';

    const AUDIENCE_CUSTOMER = 'customer';
    const AUDIENCE_BROKER   = 'broker';
    const AUDIENCE_ADMIN    = 'admin';

    /** Default subject lines. Operators can override per event in settings. */
    const SUBJECTS = [
        self::REQUEST_SUBMITTED     => 'Your domain acquisition request has been received',
        self::BROKER_ASSIGNED       => 'A broker has been assigned to your acquisition',
        self::OWNER_CONTACTED       => 'We have contacted the current owner of {domain}',
        self::OWNER_RESPONDED       => 'The current owner of {domain} has responded',
        self::OFFER_RECEIVED        => 'New offer received for {domain}',
        self::COUNTEROFFER_RECEIVED => 'Counteroffer received for {domain}',
        self::OFFER_ACCEPTED        => 'Your offer for {domain} has been accepted',
        self::OFFER_REJECTED        => 'An offer for {domain} was declined',
        self::OFFER_EXPIRING        => 'An offer for {domain} expires soon',
        self::OFFER_EXPIRED         => 'An offer for {domain} has expired',
        self::PAYMENT_REQUIRED      => 'Payment required to secure {domain}',
        self::PAYMENT_RECEIVED      => 'Payment received for {domain}',
        self::PAYMENT_FAILED        => 'Payment failed for {domain}',
        self::PAYMENT_EXPIRED       => 'The payment window for {domain} has expired',
        self::FUNDS_SECURED         => 'Your funds for {domain} are secured',
        self::REFUND_ISSUED         => 'A refund has been issued for {domain}',
        self::TRANSFER_STARTED      => 'The transfer of {domain} has started',
        self::TRANSFER_ACTION       => 'Action required to complete the transfer of {domain}',
        self::TRANSFER_COMPLETED    => 'The transfer of {domain} is complete',
        self::TRANSFER_FAILED       => 'The transfer of {domain} could not be completed',
        self::REQUEST_COMPLETED     => '{domain} is now yours',
        self::REQUEST_EXPIRED       => 'Your acquisition request for {domain} has expired',
        self::REQUEST_CANCELLED     => 'Your acquisition request for {domain} was cancelled',
        self::REQUEST_REJECTED      => 'Your acquisition request for {domain} could not be accepted',
        self::DISPUTE_OPENED        => 'A dispute has been opened for {domain}',
        self::DISPUTE_RESOLVED      => 'The dispute for {domain} has been resolved',
        self::MESSAGE_RECEIVED      => 'New message about your acquisition of {domain}',
        self::RISK_FLAGGED          => 'Domain Broker request flagged for review',
    ];

    /** @var bool set by tests to assert without dispatching. */
    protected static $suppressDispatch = false;

    public static function suppressDispatch($suppress = true)
    {
        self::$suppressDispatch = (bool) $suppress;
    }

    /**
     * Queue (and immediately attempt) a notification.
     *
     * @param string $event     one of the constants above
     * @param array  $request   the request row (may be empty for global events)
     * @param string $audience  customer|broker|admin
     * @param array  $options   subject, body, url, payload, client_id, broker_id, admin_id
     * @return int[] ids of created notification rows
     */
    public function notify($event, array $request = [], $audience = self::AUDIENCE_CUSTOMER, array $options = [])
    {
        $domain = isset($request['domain']) ? $request['domain'] : (isset($options['domain']) ? $options['domain'] : '');
        $subject = isset($options['subject'])
            ? $options['subject']
            : str_replace('{domain}', $domain, isset(self::SUBJECTS[$event]) ? self::SUBJECTS[$event] : Str::label($event));

        $body = isset($options['body']) ? $options['body'] : $this->defaultBody($event, $request, $options);
        $url = isset($options['url']) ? $options['url'] : $this->defaultUrl($audience, $request);

        $base = [
            'request_id' => isset($request['id']) ? (int) $request['id'] : null,
            'event'      => $event,
            'audience'   => $audience,
            'client_id'  => isset($options['client_id'])
                ? (int) $options['client_id']
                : (isset($request['client_id']) ? (int) $request['client_id'] : null),
            'broker_id'  => isset($options['broker_id'])
                ? (int) $options['broker_id']
                : (isset($request['assigned_broker_id']) ? (int) $request['assigned_broker_id'] : null),
            'admin_id'   => isset($options['admin_id']) ? (int) $options['admin_id'] : null,
            'subject'    => Str::clip($subject, 255),
            'body'       => $body,
            'template'   => isset($options['template']) ? $options['template'] : null,
            'payload'    => Str::jsonEncode(isset($options['payload']) ? $options['payload'] : []),
            'url'        => Str::clip($url, 255),
            'attempts'   => 0,
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ];

        if ($audience !== self::AUDIENCE_CUSTOMER) {
            $base['client_id'] = isset($options['client_id']) ? (int) $options['client_id'] : null;
        }

        $created = [];

        if (Settings::bool('notifications_inapp', true)) {
            $created[] = Db::insert('notifications', array_merge($base, [
                'channel' => 'inapp',
                'status'  => 'sent',
                'sent_at' => Clock::now(),
            ]));
        }

        if (Settings::bool('notifications_email', true) && empty($options['no_email'])) {
            $id = Db::insert('notifications', array_merge($base, [
                'channel' => 'email',
                'status'  => 'queued',
            ]));
            $created[] = $id;
            $this->dispatchEmail($id);
        }

        return $created;
    }

    /** Attempt delivery of a queued email notification. */
    public function dispatchEmail($notificationId)
    {
        $row = Db::first('notifications', ['id' => (int) $notificationId]);
        if (!$row || $row['channel'] !== 'email' || $row['status'] === 'sent') {
            return false;
        }
        if (self::$suppressDispatch) {
            Db::update('notifications', [
                'status' => 'suppressed', 'updated_at' => Clock::now(),
            ], ['id' => $row['id']]);
            return false;
        }

        $sent = false;
        try {
            $gateway = Gateway::get();
            if ($row['audience'] === self::AUDIENCE_CUSTOMER && $row['client_id']) {
                $sent = $gateway->sendClientEmail(
                    (int) $row['client_id'],
                    $row['subject'],
                    $this->wrapHtml($row['subject'], $row['body'], $row['url']),
                    ['template' => $row['template']]
                );
            } elseif ($row['audience'] === self::AUDIENCE_BROKER && $row['broker_id']) {
                $broker = Db::first('brokers', ['id' => (int) $row['broker_id']]);
                if ($broker && !empty($broker['whmcs_admin_id'])) {
                    $sent = $gateway->sendAdminEmail(
                        (int) $broker['whmcs_admin_id'],
                        $row['subject'],
                        $this->wrapHtml($row['subject'], $row['body'], $row['url'])
                    );
                } elseif ($broker && !empty($broker['whmcs_client_id'])) {
                    $sent = $gateway->sendClientEmail(
                        (int) $broker['whmcs_client_id'],
                        $row['subject'],
                        $this->wrapHtml($row['subject'], $row['body'], $row['url'])
                    );
                }
            } else {
                $sent = $gateway->sendAdminEmail(
                    (int) $row['admin_id'],
                    $row['subject'],
                    $this->wrapHtml($row['subject'], $row['body'], $row['url'])
                );
            }
        } catch (\Throwable $e) {
            Logger::error('Notification dispatch failed', ['id' => $row['id'], 'error' => $e->getMessage()]);
            Db::update('notifications', [
                'status'   => 'failed',
                'attempts' => (int) $row['attempts'] + 1,
                'error'    => Str::clip($e->getMessage(), 500),
                'updated_at' => Clock::now(),
            ], ['id' => $row['id']]);
            return false;
        }

        Db::update('notifications', [
            'status'   => $sent ? 'sent' : 'failed',
            'attempts' => (int) $row['attempts'] + 1,
            'sent_at'  => $sent ? Clock::now() : null,
            'updated_at' => Clock::now(),
        ], ['id' => $row['id']]);

        return $sent;
    }

    /** Retry failed emails (module cron). */
    public function retryFailed($limit = 50)
    {
        $rows = Db::fetch('notifications', [
            'channel' => 'email',
            'status' => 'failed',
            'attempts' => ['<', 5],
        ], ['order' => 'id', 'limit' => $limit]);

        $sent = 0;
        foreach ($rows as $row) {
            if ($this->dispatchEmail($row['id'])) {
                $sent++;
            }
        }
        return $sent;
    }

    /* ------------------------------------------------------- in-app feed */

    public function inboxForClient($clientId, $unreadOnly = false, $limit = 50)
    {
        $where = ['client_id' => (int) $clientId, 'channel' => 'inapp', 'audience' => self::AUDIENCE_CUSTOMER];
        if ($unreadOnly) {
            $where['read_at'] = null;
        }
        return Db::fetch('notifications', $where, ['order' => 'id', 'dir' => 'desc', 'limit' => $limit]);
    }

    public function unreadCountForClient($clientId)
    {
        return Db::count('notifications', [
            'client_id' => (int) $clientId, 'channel' => 'inapp',
            'audience' => self::AUDIENCE_CUSTOMER, 'read_at' => null,
        ]);
    }

    public function inboxForBroker($brokerId, $unreadOnly = false, $limit = 50)
    {
        $where = ['broker_id' => (int) $brokerId, 'channel' => 'inapp', 'audience' => self::AUDIENCE_BROKER];
        if ($unreadOnly) {
            $where['read_at'] = null;
        }
        return Db::fetch('notifications', $where, ['order' => 'id', 'dir' => 'desc', 'limit' => $limit]);
    }

    /** Mark read, scoped so one principal cannot mark another's notification. */
    public function markRead($notificationId, $audience, $principalId)
    {
        $column = $audience === self::AUDIENCE_BROKER ? 'broker_id'
            : ($audience === self::AUDIENCE_ADMIN ? 'admin_id' : 'client_id');
        return Db::update('notifications', [
            'read_at' => Clock::now(), 'updated_at' => Clock::now(),
        ], ['id' => (int) $notificationId, $column => (int) $principalId, 'read_at' => null]);
    }

    public function markAllRead($audience, $principalId)
    {
        $column = $audience === self::AUDIENCE_BROKER ? 'broker_id'
            : ($audience === self::AUDIENCE_ADMIN ? 'admin_id' : 'client_id');
        return Db::update('notifications', [
            'read_at' => Clock::now(), 'updated_at' => Clock::now(),
        ], [$column => (int) $principalId, 'channel' => 'inapp', 'read_at' => null]);
    }

    /* ------------------------------------------------------------ bodies */

    protected function defaultBody($event, array $request, array $options)
    {
        $domain = isset($request['domain']) ? $request['domain'] : '';
        $reference = isset($request['reference']) ? $request['reference'] : '';
        $lines = [];

        switch ($event) {
            case self::REQUEST_SUBMITTED:
                $lines[] = 'Thank you — we have received your request to acquire ' . $domain . '.';
                $lines[] = 'Your reference is ' . $reference . '. A broker will review it shortly and we will be in touch at every step.';
                break;
            case self::BROKER_ASSIGNED:
                $lines[] = 'Your acquisition of ' . $domain . ' has been assigned to a dedicated broker.';
                $lines[] = 'They will approach the current registrant on your behalf and keep your identity confidential where you asked for anonymity.';
                break;
            case self::OWNER_CONTACTED:
                $lines[] = 'Your broker has made contact with the current registrant of ' . $domain . '.';
                break;
            case self::OWNER_RESPONDED:
                $lines[] = 'The current registrant of ' . $domain . ' has replied. Open your request to read the update.';
                break;
            case self::OFFER_RECEIVED:
            case self::COUNTEROFFER_RECEIVED:
                $lines[] = 'There is a new offer waiting for your decision on ' . $domain . '.';
                $lines[] = 'You can accept it, decline it, or make a counteroffer from your Domain Broker dashboard.';
                break;
            case self::OFFER_ACCEPTED:
                $lines[] = 'Terms have been agreed for ' . $domain . '. We are preparing your invoice now.';
                break;
            case self::PAYMENT_REQUIRED:
                $lines[] = 'To move forward with ' . $domain . ', please settle the invoice linked below.';
                $lines[] = 'Your funds are held securely and are only released to the seller once the transfer is verified.';
                break;
            case self::PAYMENT_RECEIVED:
            case self::FUNDS_SECURED:
                $lines[] = 'We have received your payment for ' . $domain . ' and the funds are now secured.';
                $lines[] = 'The transfer will be arranged next — this is a separate step and we will keep you updated.';
                break;
            case self::TRANSFER_STARTED:
                $lines[] = 'The transfer of ' . $domain . ' has been initiated with the registrars.';
                break;
            case self::TRANSFER_ACTION:
                $lines[] = 'The transfer of ' . $domain . ' needs something from you before it can continue.';
                break;
            case self::TRANSFER_COMPLETED:
            case self::REQUEST_COMPLETED:
                $lines[] = $domain . ' is now under your control. Thank you for using our brokerage service.';
                break;
            case self::TRANSFER_FAILED:
                $lines[] = 'The transfer of ' . $domain . ' did not complete. Your broker is already looking into it.';
                break;
            case self::REFUND_ISSUED:
                $lines[] = 'A refund has been issued for your ' . $domain . ' acquisition.';
                break;
            case self::DISPUTE_OPENED:
                $lines[] = 'A dispute has been opened on acquisition ' . $reference . ' (' . $domain . ').';
                break;
            case self::DISPUTE_RESOLVED:
                $lines[] = 'The dispute on acquisition ' . $reference . ' has been resolved.';
                break;
            case self::MESSAGE_RECEIVED:
                $lines[] = 'You have a new message about your acquisition of ' . $domain . '.';
                break;
            default:
                $lines[] = Str::label($event) . ' — ' . $domain;
        }

        if (!empty($options['extra'])) {
            $lines[] = (string) $options['extra'];
        }

        return implode("\n\n", $lines);
    }

    protected function defaultUrl($audience, array $request)
    {
        if (empty($request['id'])) {
            return Http::clientUrl(['action' => 'dashboard']);
        }
        if ($audience === self::AUDIENCE_BROKER) {
            return Http::clientUrl(['action' => 'broker-request', 'id' => (int) $request['id']]);
        }
        return Http::clientUrl(['action' => 'request', 'id' => (int) $request['id']]);
    }

    protected function wrapHtml($subject, $body, $url)
    {
        $paragraphs = '';
        foreach (preg_split("/\n\n+/", (string) $body) as $para) {
            $paragraphs .= '<p style="margin:0 0 16px;line-height:1.6;color:#334155;">'
                . nl2br(Str::e($para)) . '</p>';
        }
        $button = '';
        if ($url) {
            $absolute = Http::systemUrl($url);
            $button = '<p style="margin:24px 0 0;"><a href="' . Str::e($absolute)
                . '" style="background:#1d4ed8;color:#ffffff;padding:12px 22px;border-radius:6px;'
                . 'text-decoration:none;display:inline-block;font-weight:600;">View your request</a></p>';
        }

        return '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;">'
            . '<h2 style="margin:0 0 18px;color:#0f172a;font-size:20px;">' . Str::e($subject) . '</h2>'
            . $paragraphs . $button . '</div>';
    }
}
