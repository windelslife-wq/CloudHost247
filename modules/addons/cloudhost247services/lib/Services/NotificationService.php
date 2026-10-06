<?php
/**
 * In-app notification feed plus optional email mirroring through the host
 * gateway. Every service event (bid updates, auction closes, club changes,
 * request updates) lands here so customers have one authoritative bell icon.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Logger;
use Chs\Core\Platform;
use Chs\Core\Settings;

class NotificationService
{
    public function notify($clientId, $type, $subject, $body, $link = '')
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return;
        }

        if (Settings::bool('notifications_inapp', true)) {
            Db::insert('notifications', [
                'client_id'  => $clientId,
                'type'       => substr($type, 0, 48),
                'subject'    => substr($subject, 0, 190),
                'body'       => $body,
                'link'       => substr($link, 0, 255),
                'read_at'    => null,
                'created_at' => Clock::now(),
            ]);
        }

        if (Settings::bool('notifications_email', true)) {
            try {
                Platform::gateway()->sendEmail($clientId, $subject, $body . ($link !== '' ? "\n\n" . $link : ''));
            } catch (\Throwable $e) {
                Logger::warning('Notification email failed', ['client' => $clientId, 'type' => $type, 'message' => $e->getMessage()]);
            }
        }
    }

    /** @return array[] newest first */
    public function listFor($clientId, $limit = 50)
    {
        return Db::all('notifications', ['client_id' => (int) $clientId], 'id DESC', (int) $limit);
    }

    public function unreadCount($clientId)
    {
        $rows = Db::query(
            'SELECT COUNT(*) AS c FROM ' . Db::t('notifications') . ' WHERE client_id = ? AND read_at IS NULL',
            [(int) $clientId]
        );
        return $rows ? (int) $rows[0]['c'] : 0;
    }

    public function markRead($notificationId, $clientId)
    {
        return Db::update('notifications', ['id' => (int) $notificationId, 'client_id' => (int) $clientId], [
            'read_at' => Clock::now(),
        ]);
    }

    public function markAllRead($clientId)
    {
        return Db::exec(
            'UPDATE ' . Db::t('notifications') . ' SET read_at = ? WHERE client_id = ? AND read_at IS NULL',
            [Clock::now(), (int) $clientId]
        );
    }
}
