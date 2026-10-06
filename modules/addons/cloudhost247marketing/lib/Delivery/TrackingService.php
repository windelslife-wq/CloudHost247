<?php
/**
 * Open/click/unsubscribe tracking.
 *
 * Every recipient row carries a unique opaque token, so a tracking URL
 * identifies campaign + recipient without exposing an email address or a
 * guessable sequential id. The token is the only identifier that ever leaves
 * the system.
 *
 * Link rewriting happens once per campaign at build time (not per recipient):
 * the compiled HTML carries a RECIPIENT sentinel which DeliveryService swaps
 * for the real token as each message is rendered. That keeps one HTML body in
 * memory for thousands of recipients.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Delivery;

use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\Whmcs;

class TrackingService
{
    /** Replaced per recipient at send time. Deliberately not a merge tag. */
    public const RECIPIENT_SENTINEL = '%%CH247M_RCPT%%';

    public const EVENTS = ['sent', 'delivered', 'open', 'click', 'bounce', 'complaint', 'unsubscribe', 'failed'];

    /* ------------------------------------------------------------ urls -- */

    public static function baseUrl()
    {
        $base = Whmcs::systemUrl();
        return ($base === '' ? '' : $base) . '/modules/addons/' . CH247M_MODULE_NAME . '/track.php';
    }

    public static function openUrl($recipientToken = self::RECIPIENT_SENTINEL)
    {
        return self::baseUrl() . '?t=o&r=' . $recipientToken;
    }

    public static function clickUrl($linkToken, $recipientToken = self::RECIPIENT_SENTINEL)
    {
        return self::baseUrl() . '?t=c&l=' . rawurlencode((string) $linkToken) . '&r=' . $recipientToken;
    }

    public static function unsubscribeUrl($recipientToken = self::RECIPIENT_SENTINEL)
    {
        return self::baseUrl() . '?t=u&r=' . $recipientToken;
    }

    public static function preferencesUrl($recipientToken = self::RECIPIENT_SENTINEL)
    {
        return self::baseUrl() . '?t=p&r=' . $recipientToken;
    }

    public static function webviewUrl($recipientToken = self::RECIPIENT_SENTINEL)
    {
        return self::baseUrl() . '?t=v&r=' . $recipientToken;
    }

    /* -------------------------------------------------- link rewriting -- */

    /**
     * Register every outbound link of a campaign and rewrite the HTML to point
     * at the click tracker.
     *
     * Skips mailto:, anchors, the tracker itself and anything already carrying
     * a merge tag in the host position.
     *
     * @return string rewritten HTML
     */
    public static function rewriteLinks($html, $campaignId)
    {
        $campaignId = (int) $campaignId;
        if (!Settings::bool('track_clicks', true) || $campaignId <= 0) {
            return (string) $html;
        }
        $position = 0;
        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            function ($m) use ($campaignId, &$position) {
                $url = trim(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'));
                if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                    return $m[0]; // mailto:, #anchor, {{merge_tag}} — leave alone
                }
                if (strpos($url, '/modules/addons/' . CH247M_MODULE_NAME . '/track.php') !== false) {
                    return $m[0]; // already a tracking link
                }
                $position++;
                $link = self::registerLink($campaignId, $url, $position);
                $tracked = self::clickUrl($link['token']);
                return $m[1] . $m[2] . htmlspecialchars($tracked, ENT_QUOTES, 'UTF-8') . $m[2];
            },
            (string) $html
        );
    }

    /** Append the 1x1 open pixel just before </body>. */
    public static function injectOpenPixel($html)
    {
        if (!Settings::bool('track_opens', true)) {
            return (string) $html;
        }
        $pixel = '<img src="' . htmlspecialchars(self::openUrl(), ENT_QUOTES, 'UTF-8')
            . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
        $html = (string) $html;
        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $pixel . '</body>', $html, 1);
        }
        return $html . $pixel;
    }

    public static function registerLink($campaignId, $url, $position = 0)
    {
        $campaignId = (int) $campaignId;
        $url = (string) $url;
        $hash = hash('sha256', $url);
        $existing = Db::first('links', ['campaign_id' => $campaignId, 'url_hash' => $hash]);
        if ($existing !== null) {
            return $existing;
        }
        $token = self::uniqueLinkToken();
        Db::insert('links', [
            'campaign_id' => $campaignId,
            'url'         => $url,
            'url_hash'    => $hash,
            'token'       => $token,
            'position'    => (int) $position,
            'created_at'  => Clock::now(),
        ]);
        return Db::first('links', ['token' => $token]);
    }

    /**
     * Destination for a click token, without recording anything.
     *
     * Used when the request looks like a link scanner: the redirect must
     * still work (otherwise the human behind the scanner gets a dead link)
     * but it must not inflate the click count.
     *
     * @return string|null
     */
    public static function linkUrl($linkToken)
    {
        $linkToken = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $linkToken);
        if ($linkToken === '') {
            return null;
        }
        $link = Db::first('links', ['token' => $linkToken]);
        return $link === null ? null : self::safeDestination((string) $link['url']);
    }

    /* ------------------------------------------------------- recording -- */

    /** @return array|null the recipient row */
    public static function recipientByToken($token)
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
        if ($token === '') {
            return null;
        }
        return Db::first('campaign_recipients', ['token' => $token]);
    }

    public static function recordOpen($recipientToken, array $meta = [])
    {
        $recipient = self::recipientByToken($recipientToken);
        if ($recipient === null) {
            return false;
        }
        $now = Clock::now();
        $first = empty($recipient['first_opened_at']);
        Db::update('campaign_recipients', ['id' => (int) $recipient['id']], array_filter([
            'first_opened_at' => $first ? $now : null,
            'open_count'      => (int) $recipient['open_count'] + 1,
        ], function ($v) {
            return $v !== null;
        }));
        self::event('open', $recipient, $meta);
        if ($first) {
            self::bumpCampaign((int) $recipient['campaign_id'], 'count_opened');
        }
        return true;
    }

    /** @return string|null the destination URL to redirect to */
    public static function recordClick($linkToken, $recipientToken, array $meta = [])
    {
        $link = Db::first('links', ['token' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $linkToken)]);
        if ($link === null) {
            return null;
        }
        $recipient = self::recipientByToken($recipientToken);
        if ($recipient !== null) {
            $now = Clock::now();
            $firstForRecipient = empty($recipient['first_clicked_at']);
            Db::update('campaign_recipients', ['id' => (int) $recipient['id']], array_filter([
                'first_clicked_at' => $firstForRecipient ? $now : null,
                'click_count'      => (int) $recipient['click_count'] + 1,
            ], function ($v) {
                return $v !== null;
            }));

            $alreadyClickedThisLink = Db::count('email_events', [
                'recipient_id' => (int) $recipient['id'], 'event' => 'click', 'link_id' => (int) $link['id'],
            ]) > 0;

            self::event('click', $recipient, $meta, (int) $link['id']);

            Db::update('links', ['id' => (int) $link['id']], [
                'click_count'        => (int) $link['click_count'] + 1,
                'unique_click_count' => (int) $link['unique_click_count'] + ($alreadyClickedThisLink ? 0 : 1),
            ]);
            if ($firstForRecipient) {
                self::bumpCampaign((int) $recipient['campaign_id'], 'count_clicked');
                // A click proves the message was delivered and opened, even if
                // the pixel was blocked.
                if (empty($recipient['first_opened_at'])) {
                    Db::update('campaign_recipients', ['id' => (int) $recipient['id']], ['first_opened_at' => $now, 'open_count' => max(1, (int) $recipient['open_count'])]);
                    self::bumpCampaign((int) $recipient['campaign_id'], 'count_opened');
                }
            }
        } else {
            Db::update('links', ['id' => (int) $link['id']], ['click_count' => (int) $link['click_count'] + 1]);
        }
        return self::safeDestination((string) $link['url']);
    }

    /**
     * Last line of defence on the redirector.
     *
     * The stored URL was validated when the campaign was compiled, but this
     * endpoint is public and sends a Location: header, so the scheme is
     * re-checked on the way out. Anything that is not plain http(s) — a
     * javascript: or data: URL smuggled in by a database edit — becomes null
     * and the caller falls back to the site home page.
     *
     * @return string|null
     */
    protected static function safeDestination($url)
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/[\x00-\x1f]/', $url) === 1) {
            return null;
        }
        return preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : null;
    }

    public static function recordUnsubscribe($recipientToken, array $meta = [])
    {
        $recipient = self::recipientByToken($recipientToken);
        if ($recipient === null) {
            return false;
        }
        if (!empty($recipient['unsubscribed_at'])) {
            return true; // idempotent
        }
        Db::update('campaign_recipients', ['id' => (int) $recipient['id']], ['unsubscribed_at' => Clock::now()]);
        self::event('unsubscribe', $recipient, $meta);
        self::bumpCampaign((int) $recipient['campaign_id'], 'count_unsubscribed');

        if ((int) $recipient['subscriber_id'] > 0) {
            \Ch247Mkt\Audience\SubscriberService::unsubscribe((int) $recipient['subscriber_id'], [
                'source'      => 'email_link',
                'campaign_id' => (int) $recipient['campaign_id'],
                'actor'       => 'client',
            ]);
        } else {
            ComplianceService::suppress((string) $recipient['email'], 'unsubscribe', [
                'source' => 'email_link', 'campaign_id' => (int) $recipient['campaign_id'],
            ]);
        }
        return true;
    }

    public static function recordComplaint($recipientToken, array $meta = [])
    {
        $recipient = self::recipientByToken($recipientToken);
        if ($recipient === null) {
            return false;
        }
        Db::update('campaign_recipients', ['id' => (int) $recipient['id']], ['complained_at' => Clock::now()]);
        self::event('complaint', $recipient, $meta);
        self::bumpCampaign((int) $recipient['campaign_id'], 'count_complained');
        if ((int) $recipient['subscriber_id'] > 0) {
            \Ch247Mkt\Audience\SubscriberService::recordComplaint((int) $recipient['subscriber_id'], ['campaign_id' => (int) $recipient['campaign_id']]);
        } else {
            ComplianceService::suppress((string) $recipient['email'], 'complaint', ['source' => 'feedback_loop', 'campaign_id' => (int) $recipient['campaign_id']]);
        }
        return true;
    }

    /** Append a raw event row. */
    public static function event($event, array $recipient, array $meta = [], $linkId = 0)
    {
        if (!in_array($event, self::EVENTS, true)) {
            return 0;
        }
        return Db::insert('email_events', [
            'campaign_id'   => (int) $recipient['campaign_id'],
            'recipient_id'  => (int) $recipient['id'],
            'subscriber_id' => (int) $recipient['subscriber_id'],
            'event'         => $event,
            'link_id'       => (int) $linkId,
            'ip_hash'       => isset($meta['ip']) && $meta['ip'] !== '' ? hash('sha256', (string) $meta['ip']) : null,
            'user_agent'    => isset($meta['user_agent']) ? Str::clip($meta['user_agent'], 255) : null,
            'meta'          => $meta === [] ? null : json_encode(array_diff_key($meta, ['ip' => 1]), JSON_UNESCAPED_SLASHES),
            'created_at'    => Clock::now(),
        ]);
    }

    /** @return string 43-byte transparent GIF */
    public static function pixelBytes()
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    /** Is this request an automated scanner rather than a human? */
    public static function looksAutomated($userAgent)
    {
        $ua = strtolower((string) $userAgent);
        if ($ua === '') {
            return false;
        }
        $needles = [
            // Mail-client and provider image proxies.
            'googleimageproxy', 'yahoomailproxy', 'bingpreview', 'yandexmail',
            // Security gateways that pre-fetch every link in a message.
            'proofpoint', 'barracuda', 'mimecast', 'symantec', 'forcepoint',
            'microsoft office', 'safelinks', 'urldefense', 'skype',
            // Scripted clients.
            'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client',
            'java/', 'libwww-perl', 'okhttp', 'axios/', 'headlesschrome', 'phantomjs',
        ];
        foreach ($needles as $needle) {
            if (strpos($ua, $needle) !== false) {
                return true;
            }
        }
        // Generic crawler signatures, e.g. "compatible; bingbot/2.0".
        return preg_match('/\b(bot|crawler|spider|scanner|preview|monitor|fetcher)\b|bot\//', $ua) === 1;
    }

    protected static function bumpCampaign($campaignId, $column)
    {
        $allowed = ['count_opened', 'count_clicked', 'count_unsubscribed', 'count_complained', 'count_bounced', 'count_delivered', 'count_failed', 'count_sent'];
        if (!in_array($column, $allowed, true) || (int) $campaignId <= 0) {
            return;
        }
        Db::exec('UPDATE ' . Db::t('campaigns') . ' SET ' . $column . ' = ' . $column . ' + 1 WHERE id = ?', [(int) $campaignId]);
    }

    protected static function uniqueLinkToken()
    {
        for ($i = 0; $i < 10; $i++) {
            $token = substr(Str::token(18), 0, 24);
            if (Db::count('links', ['token' => $token]) === 0) {
                return $token;
            }
        }
        return substr(hash('sha256', uniqid('', true)), 0, 24);
    }
}
