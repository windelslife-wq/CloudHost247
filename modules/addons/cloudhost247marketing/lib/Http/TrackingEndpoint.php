<?php
/**
 * Public tracking endpoint — opens, clicks, unsubscribe, preferences, webview.
 *
 * Reached by track.php in the module root. No WHMCS session, no admin auth:
 * the only credential is an unguessable per-recipient token, so every handler
 * is written to fail closed and leak nothing about whether a token exists.
 *
 * Nothing here is allowed to throw a visible error. A broken pixel must never
 * show a stack trace inside someone's inbox.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Http;

use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\RateLimiter;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\TrackingService;

class TrackingEndpoint
{
    /** 1x1 transparent GIF. */
    const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function handle()
    {
        $type = (string) ($_GET['t'] ?? '');
        $token = (string) ($_GET['r'] ?? '');
        $link = (string) ($_GET['l'] ?? '');

        try {
            switch ($type) {
                case 'o': $this->open($token); return;
                case 'c': $this->click($token, $link); return;
                case 'u': $this->unsubscribe($token); return;
                case 'p': $this->preferences($token); return;
                case 'v': $this->webview($token); return;
            }
        } catch (\Throwable $e) {
            Logger::error('tracking endpoint failed', ['type' => $type, 'reason' => get_class($e), 'message' => $e->getMessage()]);
            if ($type === 'o') {
                $this->pixel();
                return;
            }
            $this->page('Something went wrong', '<p>We could not complete that request. Please contact support and we will sort it out manually.</p>');
            return;
        }

        $this->pixel();
    }

    /* ------------------------------------------------------------ opens */

    protected function open($token)
    {
        // The pixel goes out regardless of whether the token resolves — an
        // image that 404s tells a scraper the token was wrong.
        if ($token !== '' && !TrackingService::looksAutomated($this->userAgent())) {
            TrackingService::recordOpen($token, [
                'ip'         => $this->ip(),
                'user_agent' => $this->userAgent(),
            ]);
        }
        $this->pixel();
    }

    protected function pixel()
    {
        $bytes = base64_decode(self::PIXEL);
        if (!headers_sent()) {
            header('Content-Type: image/gif');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        }
        echo $bytes;
    }

    /* ----------------------------------------------------------- clicks */

    protected function click($token, $linkToken)
    {
        if ($token !== '' && !TrackingService::looksAutomated($this->userAgent())) {
            $destination = TrackingService::recordClick($linkToken, $token, [
                'ip'         => $this->ip(),
                'user_agent' => $this->userAgent(),
            ]);
        } else {
            // Scanner or missing recipient token: resolve but do not count.
            $destination = TrackingService::linkUrl($linkToken);
        }
        $this->redirect($destination === null ? $this->homeUrl() : $destination);
    }

    protected function redirect($url)
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $url, true, 302);
        }
        echo '<!doctype html><meta charset="utf-8"><title>Redirecting</title>'
            . '<p>Redirecting to <a href="' . ch247m_h($url) . '">' . ch247m_h($url) . '</a>&hellip;</p>';
    }

    /* ------------------------------------------------------ unsubscribe */

    protected function unsubscribe($token)
    {
        $recipient = TrackingService::recipientByToken($token);

        // RFC 8058 one-click: the mail client POSTs without ever showing a page.
        $isOneClick = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        if ($recipient === null) {
            if ($isOneClick) {
                $this->plain('OK');
                return;
            }
            $this->page(
                'Link not recognised',
                '<p>This unsubscribe link is not valid — it may have already been used, or the campaign may have been deleted.</p>'
                . '<p>If you are still receiving email you do not want, reply to any message and we will remove you by hand.</p>'
            );
            return;
        }

        if (!RateLimiter::hit('unsubscribe', $this->bucket(), 60, 300)) {
            $this->page('Too many requests', '<p>Please wait a moment and try again.</p>');
            return;
        }

        $already = ComplianceService::isSuppressed((string) $recipient['email']);
        if (!$already) {
            TrackingService::recordUnsubscribe($token, [
                'ip'         => $this->ip(),
                'user_agent' => $this->userAgent(),
                'one_click'  => $isOneClick,
            ]);
        }

        if ($isOneClick) {
            $this->plain('OK');
            return;
        }

        $company = Settings::string('company_name', '') ?: 'us';
        $this->page(
            $already ? 'You were already unsubscribed' : 'You have been unsubscribed',
            '<p><strong>' . ch247m_h(Str::maskEmail((string) $recipient['email'])) . '</strong> will no longer receive marketing email from '
            . ch247m_h($company) . '.</p>'
            . '<p class="muted">This took effect immediately. You may still receive transactional messages about services you hold with us — '
            . 'invoices, renewal notices and support replies — because those are not marketing.</p>'
            . '<form method="post" action="' . ch247m_h($this->selfUrl(['t' => 'p', 'r' => $recipient['token']])) . '">'
            . '<button type="submit" name="resubscribe" value="1" class="link">Changed your mind? Re-subscribe</button></form>'
        );
    }

    /* ------------------------------------------------------ preferences */

    protected function preferences($token)
    {
        $recipient = TrackingService::recipientByToken($token);
        if ($recipient === null) {
            $this->page('Link not recognised', '<p>This preferences link is not valid.</p>');
            return;
        }

        $subscriberId = (int) $recipient['subscriber_id'];
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $subscriberId > 0) {
            if (!RateLimiter::hit('preferences', $this->bucket(), 30, 300)) {
                $this->page('Too many requests', '<p>Please wait a moment and try again.</p>');
                return;
            }
            if (isset($_POST['resubscribe'])) {
                ComplianceService::unsuppress((string) $recipient['email'], 0, 'self-service re-subscribe');
                \Ch247Mkt\Audience\SubscriberService::resubscribe($subscriberId, [
                    'consent_source' => 'preference centre',
                    'ip'             => $this->ip(),
                    'actor'          => 'subscriber',
                ]);
                $message = '<p class="ok">You are subscribed again. Welcome back.</p>';
            } else {
                $keep = array_map('intval', (array) ($_POST['lists'] ?? []));
                foreach (\Ch247Mkt\Audience\ListService::all() as $list) {
                    $listId = (int) $list['id'];
                    if (in_array($listId, $keep, true)) {
                        \Ch247Mkt\Audience\ListService::addMember($listId, $subscriberId);
                    } else {
                        \Ch247Mkt\Audience\ListService::removeMember($listId, $subscriberId);
                    }
                }
                if ($keep === []) {
                    TrackingService::recordUnsubscribe($token, ['ip' => $this->ip(), 'user_agent' => $this->userAgent(), 'reason' => 'all lists removed']);
                    $this->page('You have been unsubscribed', '<p>You removed yourself from every list, so we have unsubscribed you completely.</p>');
                    return;
                }
                $message = '<p class="ok">Your preferences have been saved.</p>';
            }
        }

        $suppressed = ComplianceService::isSuppressed((string) $recipient['email']);
        $memberOf = $subscriberId > 0 ? \Ch247Mkt\Audience\ListService::listsFor($subscriberId) : [];

        $body = $message . '<p>Email preferences for <strong>' . ch247m_h(Str::maskEmail((string) $recipient['email'])) . '</strong>.</p>';

        if ($suppressed) {
            $body .= '<p class="muted">You are currently unsubscribed from all marketing email.</p>'
                . '<form method="post"><button type="submit" name="resubscribe" value="1">Re-subscribe me</button></form>';
        } else {
            $body .= '<form method="post"><fieldset><legend>Choose what you would like to receive</legend>';
            $lists = \Ch247Mkt\Audience\ListService::all();
            if ($lists === []) {
                $body .= '<p class="muted">There is nothing to choose from right now.</p>';
            }
            foreach ($lists as $list) {
                $checked = in_array((int) $list['id'], $memberOf, true) ? ' checked' : '';
                $body .= '<label><input type="checkbox" name="lists[]" value="' . (int) $list['id'] . '"' . $checked . '> '
                    . ch247m_h($list['name'])
                    . ($list['description'] !== '' ? '<span class="muted"> — ' . ch247m_h(Str::clip($list['description'], 110)) . '</span>' : '')
                    . '</label>';
            }
            $body .= '</fieldset><button type="submit">Save preferences</button></form>'
                . '<p class="muted">Unticking everything unsubscribes you completely.</p>';
        }

        $this->page('Email preferences', $body);
    }

    /* ---------------------------------------------------------- webview */

    protected function webview($token)
    {
        $recipient = TrackingService::recipientByToken($token);
        if ($recipient === null) {
            $this->page('Message not available', '<p>This link is not valid, or the message is no longer available online.</p>');
            return;
        }
        $html = CampaignService::webview((int) $recipient['campaign_id'], $recipient);
        if ($html === null) {
            $this->page('Message not available', '<p>This message is no longer available online.</p>');
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer');
        }
        echo $html;
    }

    /* ----------------------------------------------------------- output */

    protected function plain($text)
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $text;
    }

    /** Minimal self-contained page — no WHMCS template, no external assets. */
    protected function page($title, $bodyHtml)
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
        }
        $company = Settings::string('company_name', '');
        $address = Settings::string('physical_address', '');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . ch247m_h($title) . '</title><style>'
            . ':root{color-scheme:light}'
            . 'body{margin:0;background:#f4f6f8;color:#1f2933;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
            . '.wrap{max-width:560px;margin:8vh auto;padding:0 20px;}'
            . '.card{background:#fff;border:1px solid #e3e8ee;border-radius:10px;padding:32px;box-shadow:0 1px 3px rgba(16,24,40,.06);}'
            . 'h1{font-size:22px;margin:0 0 16px;}'
            . 'p{margin:0 0 14px;}'
            . '.muted{color:#6b7684;font-size:14px;}'
            . '.ok{background:#e8f6ee;border:1px solid #bfe3cd;color:#1d6f42;padding:10px 12px;border-radius:6px;}'
            . 'fieldset{border:1px solid #e3e8ee;border-radius:8px;padding:14px 16px;margin:0 0 16px;}'
            . 'legend{font-size:14px;font-weight:600;padding:0 6px;}'
            . 'label{display:block;margin:0 0 10px;font-size:15px;}'
            . 'button{background:#0b63ce;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-size:15px;cursor:pointer;}'
            . 'button.link{background:none;color:#0b63ce;padding:0;text-decoration:underline;font-size:14px;}'
            . 'footer{margin-top:22px;color:#8b95a1;font-size:12px;text-align:center;}'
            . '</style></head><body><div class="wrap"><div class="card">'
            . '<h1>' . ch247m_h($title) . '</h1>' . $bodyHtml
            . '</div><footer>' . ch247m_h($company)
            . ($address !== '' ? '<br>' . nl2br(ch247m_h($address)) : '')
            . '</footer></div></body></html>';
    }

    /* ----------------------------------------------------------- helpers */

    protected function selfUrl(array $params)
    {
        $base = Settings::string('tracking_base_url', '');
        if ($base === '') {
            $base = rtrim(\Ch247Mkt\Core\Whmcs::systemUrl(), '/') . '/modules/addons/' . CH247M_MODULE_NAME . '/track.php';
        }
        return $base . '?' . http_build_query($params);
    }

    protected function homeUrl()
    {
        return \Ch247Mkt\Core\Whmcs::systemUrl() ?: '/';
    }

    /** Rate-limit bucket: hashed IP, so the limiter table holds no raw addresses. */
    protected function bucket()
    {
        return 'ip:' . hash('sha256', $this->ip() ?: 'unknown');
    }

    protected function ip()
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    protected function userAgent()
    {
        return Str::clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    }
}
