<?php
/**
 * Campaign reporting.
 *
 * Rates are computed from the recipient ledger rather than the denormalised
 * counters, so a counter drifting (a crashed worker, a replayed webhook) can
 * never silently misreport. The counters stay for cheap list views; these
 * numbers are the authoritative ones.
 *
 * Open rate is deliberately unique-opens / delivered, not / sent — and the
 * UI says so, because the difference is where most ESP comparisons go wrong.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Delivery;

use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;

class AnalyticsService
{
    /**
     * @return array{
     *   recipients:int, sent:int, delivered:int, bounced:int, failed:int,
     *   unique_opens:int, total_opens:int, unique_clicks:int, total_clicks:int,
     *   unsubscribed:int, complained:int, skipped:int,
     *   delivery_rate:float, open_rate:float, click_rate:float,
     *   click_to_open_rate:float, bounce_rate:float,
     *   unsubscribe_rate:float, complaint_rate:float
     * }
     */
    public static function campaignStats($campaignId)
    {
        $campaignId = (int) $campaignId;
        $t = Db::t('campaign_recipients');

        $rows = Db::query(
            'SELECT
                COUNT(*)                                                   AS recipients,
                SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END)       AS sent,
                SUM(CASE WHEN delivered_at IS NOT NULL AND bounced_at IS NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END)    AS bounced,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                AS failed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                AS skipped,
                SUM(CASE WHEN first_opened_at IS NOT NULL THEN 1 ELSE 0 END)  AS unique_opens,
                SUM(open_count)                                            AS total_opens,
                SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS unique_clicks,
                SUM(click_count)                                           AS total_clicks,
                SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END)  AS unsubscribed,
                SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END)    AS complained
             FROM ' . $t . ' WHERE campaign_id = ?',
            ['failed', 'skipped', $campaignId]
        );

        $r = $rows ? $rows[0] : [];
        $stats = [
            'recipients'    => (int) ($r['recipients'] ?? 0),
            'sent'          => (int) ($r['sent'] ?? 0),
            'delivered'     => (int) ($r['delivered'] ?? 0),
            'bounced'       => (int) ($r['bounced'] ?? 0),
            'failed'        => (int) ($r['failed'] ?? 0),
            'skipped'       => (int) ($r['skipped'] ?? 0),
            'unique_opens'  => (int) ($r['unique_opens'] ?? 0),
            'total_opens'   => (int) ($r['total_opens'] ?? 0),
            'unique_clicks' => (int) ($r['unique_clicks'] ?? 0),
            'total_clicks'  => (int) ($r['total_clicks'] ?? 0),
            'unsubscribed'  => (int) ($r['unsubscribed'] ?? 0),
            'complained'    => (int) ($r['complained'] ?? 0),
        ];

        $stats['delivery_rate']      = self::rate($stats['delivered'], $stats['sent']);
        $stats['open_rate']          = self::rate($stats['unique_opens'], $stats['delivered']);
        $stats['click_rate']         = self::rate($stats['unique_clicks'], $stats['delivered']);
        $stats['click_to_open_rate'] = self::rate($stats['unique_clicks'], $stats['unique_opens']);
        $stats['bounce_rate']        = self::rate($stats['bounced'], $stats['sent']);
        $stats['unsubscribe_rate']   = self::rate($stats['unsubscribed'], $stats['delivered']);
        $stats['complaint_rate']     = self::rate($stats['complained'], $stats['delivered']);

        return $stats;
    }

    /** Which links got clicked — the click map. @return array[] */
    public static function clickMap($campaignId)
    {
        $rows = Db::query(
            'SELECT id, url, token, position, click_count, unique_click_count
               FROM ' . Db::t('links') . ' WHERE campaign_id = ? ORDER BY unique_click_count DESC, click_count DESC, position ASC',
            [(int) $campaignId]
        );
        $totalUnique = 0;
        foreach ($rows as $row) {
            $totalUnique += (int) $row['unique_click_count'];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'                 => (int) $row['id'],
                'url'                => (string) $row['url'],
                'position'           => (int) $row['position'],
                'click_count'        => (int) $row['click_count'],
                'unique_click_count' => (int) $row['unique_click_count'],
                'share'              => $totalUnique > 0 ? round(((int) $row['unique_click_count'] / $totalUnique) * 100, 1) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Per-recipient report.
     *
     * @param string $filter all|opened|clicked|bounced|unsubscribed|not_opened|failed
     */
    public static function recipients($campaignId, $filter = 'all', $page = 1, $perPage = 50)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(200, (int) $perPage));
        $where = ['campaign_id = ?'];
        $bind = [(int) $campaignId];

        switch ($filter) {
            case 'opened':       $where[] = 'first_opened_at IS NOT NULL'; break;
            case 'not_opened':   $where[] = 'first_opened_at IS NULL AND sent_at IS NOT NULL'; break;
            case 'clicked':      $where[] = 'first_clicked_at IS NOT NULL'; break;
            case 'bounced':      $where[] = 'bounced_at IS NOT NULL'; break;
            case 'unsubscribed': $where[] = 'unsubscribed_at IS NOT NULL'; break;
            case 'failed':       $where[] = 'status = ?'; $bind[] = 'failed'; break;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $countRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('campaign_recipients') . $whereSql, $bind);
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('campaign_recipients') . $whereSql . ' ORDER BY id ASC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => $rows, 'total' => $countRows ? (int) $countRows[0]['c'] : 0];
    }

    /** Event counts per day for the activity chart. @return array<string,array<string,int>> */
    public static function timeline($campaignId, $days = 14)
    {
        $since = Clock::ago(max(1, (int) $days) * 86400);
        $out = [];
        foreach (Db::query(
            'SELECT substr(created_at, 1, 10) AS day, event, COUNT(*) AS c
               FROM ' . Db::t('email_events') . '
              WHERE campaign_id = ? AND created_at >= ?
           GROUP BY day, event ORDER BY day ASC',
            [(int) $campaignId, $since]
        ) as $row) {
            $day = (string) $row['day'];
            if (!isset($out[$day])) {
                $out[$day] = [];
            }
            $out[$day][(string) $row['event']] = (int) $row['c'];
        }
        return $out;
    }

    /** Raw event feed for one campaign. */
    public static function events($campaignId, $limit = 100)
    {
        return Db::query(
            'SELECT e.*, r.email FROM ' . Db::t('email_events') . ' e
               LEFT JOIN ' . Db::t('campaign_recipients') . ' r ON r.id = e.recipient_id
              WHERE e.campaign_id = ? ORDER BY e.id DESC LIMIT ' . (int) $limit,
            [(int) $campaignId]
        );
    }

    /* ------------------------------------------------------- dashboard -- */

    /** Module-wide figures for the landing page. */
    public static function dashboard()
    {
        $statusCounts = SubscriberService::statusCounts();
        $campaignCounts = array_fill_keys(CampaignService::STATUSES, 0);
        foreach (Db::query('SELECT status, COUNT(*) AS c FROM ' . Db::t('campaigns') . ' GROUP BY status') as $row) {
            $campaignCounts[(string) $row['status']] = (int) $row['c'];
        }

        // Aggregate performance across everything that has actually been sent.
        $rows = Db::query(
            'SELECT
                SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END)      AS sent,
                SUM(CASE WHEN delivered_at IS NOT NULL AND bounced_at IS NULL THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END)   AS bounced,
                SUM(CASE WHEN first_opened_at IS NOT NULL THEN 1 ELSE 0 END)  AS opens,
                SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicks,
                SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END)  AS unsubscribed,
                SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END)    AS complained
             FROM ' . Db::t('campaign_recipients')
        );
        $r = $rows ? $rows[0] : [];
        $sent = (int) ($r['sent'] ?? 0);
        $delivered = (int) ($r['delivered'] ?? 0);

        return [
            'subscribers' => [
                'total'        => array_sum($statusCounts),
                'subscribed'   => $statusCounts[SubscriberService::STATUS_SUBSCRIBED],
                'unconfirmed'  => $statusCounts[SubscriberService::STATUS_UNCONFIRMED],
                'unsubscribed' => $statusCounts[SubscriberService::STATUS_UNSUBSCRIBED],
                'bounced'      => $statusCounts[SubscriberService::STATUS_BOUNCED],
                'suppressed'   => $statusCounts[SubscriberService::STATUS_SUPPRESSED],
            ],
            'lists'     => Db::count('lists'),
            'segments'  => Db::count('segments'),
            'campaigns' => $campaignCounts,
            'queue'     => QueueService::stats(),
            'suppressions' => ComplianceService::reasonCounts(),
            'performance' => [
                'sent'             => $sent,
                'delivered'        => $delivered,
                'bounced'          => (int) ($r['bounced'] ?? 0),
                'unique_opens'     => (int) ($r['opens'] ?? 0),
                'unique_clicks'    => (int) ($r['clicks'] ?? 0),
                'unsubscribed'     => (int) ($r['unsubscribed'] ?? 0),
                'complained'       => (int) ($r['complained'] ?? 0),
                'delivery_rate'    => self::rate($delivered, $sent),
                'open_rate'        => self::rate((int) ($r['opens'] ?? 0), $delivered),
                'click_rate'       => self::rate((int) ($r['clicks'] ?? 0), $delivered),
                'bounce_rate'      => self::rate((int) ($r['bounced'] ?? 0), $sent),
                'unsubscribe_rate' => self::rate((int) ($r['unsubscribed'] ?? 0), $delivered),
                'complaint_rate'   => self::rate((int) ($r['complained'] ?? 0), $delivered),
            ],
        ];
    }

    /** Most recent campaign activity for the dashboard feed. */
    public static function recentActivity($limit = 10)
    {
        return array_map(
            [CampaignService::class, 'hydrate'],
            Db::query('SELECT * FROM ' . Db::t('campaigns') . ' ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $limit)
        );
    }

    public static function rate($numerator, $denominator)
    {
        $denominator = (float) $denominator;
        if ($denominator <= 0) {
            return 0.0;
        }
        return round(((float) $numerator / $denominator) * 100, 2);
    }

    /** Housekeeping: trim the event stream. */
    public static function purgeEvents($days = null)
    {
        $days = $days === null ? \Ch247Mkt\Core\Settings::int('retention_days_events', 365) : (int) $days;
        if ($days <= 0) {
            return 0;
        }
        return Db::exec('DELETE FROM ' . Db::t('email_events') . ' WHERE created_at < ?', [Clock::ago($days * 86400)]);
    }
}
