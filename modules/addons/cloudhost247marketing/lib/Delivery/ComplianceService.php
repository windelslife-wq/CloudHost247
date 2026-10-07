<?php
/**
 * Suppression list and pre-send compliance gates.
 *
 * The suppression list is the highest authority in the module: if an address
 * is on it, nothing — not an import, not a segment, not an automation, not a
 * manual "send anyway" — puts a marketing email in front of it. Entries are
 * matched on sha256 of the lower-cased address so they survive the subscriber
 * record being deleted for GDPR erasure.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Delivery;

use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;

class ComplianceService
{
    public const REASONS = ['hard_bounce', 'complaint', 'unsubscribe', 'manual', 'invalid'];

    public static function suppress($email, $reason, array $options = [])
    {
        $email = Str::normalizeEmail($email);
        if ($email === '') {
            return false;
        }
        if (!in_array($reason, self::REASONS, true)) {
            $reason = 'manual';
        }
        $hash = hash('sha256', $email);
        if (Db::count('suppressions', ['email_hash' => $hash]) > 0) {
            return true; // already suppressed; never downgrade the reason
        }
        Db::insert('suppressions', [
            'email_hash'  => $hash,
            'email'       => $email,
            'reason'      => $reason,
            'source'      => Str::clip($options['source'] ?? '', 60),
            'campaign_id' => (int) ($options['campaign_id'] ?? 0),
            'notes'       => Str::clip($options['notes'] ?? '', 1000),
            'created_at'  => Clock::now(),
        ]);
        Audit::system('suppression.added', ['email' => $email, 'reason' => $reason, 'campaign_id' => (int) ($options['campaign_id'] ?? 0)]);
        return true;
    }

    /** Remove a suppression. Deliberate, audited, and never automatic. */
    public static function unsuppress($email, $adminId = 0, $note = '')
    {
        $email = Str::normalizeEmail($email);
        $hash = hash('sha256', $email);
        $existing = Db::first('suppressions', ['email_hash' => $hash]);
        if ($existing === null) {
            return false;
        }
        Db::delete('suppressions', ['email_hash' => $hash]);
        Audit::record($adminId > 0 ? 'admin' : 'system', (int) $adminId, 'suppression.removed', [
            'entity_type' => 'suppression', 'entity_id' => (int) $existing['id'],
            'context' => array_filter(['email' => $email, 'was' => $existing['reason'], 'note' => (string) $note]),
        ]);
        return true;
    }

    public static function isSuppressed($email)
    {
        $email = Str::normalizeEmail($email);
        if ($email === '') {
            return true;
        }
        return Db::count('suppressions', ['email_hash' => hash('sha256', $email)]) > 0;
    }

    /** Bulk check — one query for a whole campaign build. @return array<string,string> email => reason */
    public static function suppressedAmong(array $emails)
    {
        $hashes = [];
        $byHash = [];
        foreach ($emails as $email) {
            $email = Str::normalizeEmail($email);
            if ($email === '') {
                continue;
            }
            $hash = hash('sha256', $email);
            $hashes[] = $hash;
            $byHash[$hash] = $email;
        }
        if ($hashes === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            foreach (Db::query('SELECT email_hash, reason FROM ' . Db::t('suppressions') . ' WHERE email_hash IN (' . $placeholders . ')', $chunk) as $row) {
                $hash = (string) $row['email_hash'];
                if (isset($byHash[$hash])) {
                    $out[$byHash[$hash]] = (string) $row['reason'];
                }
            }
        }
        return $out;
    }

    public static function search($query = '', $reason = '', $page = 1, $perPage = 50)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(200, (int) $perPage));
        $where = [];
        $bind = [];
        if (trim((string) $query) !== '') {
            $where[] = 'email LIKE ?';
            $bind[] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $query)) . '%';
        }
        if (in_array($reason, self::REASONS, true)) {
            $where[] = 'reason = ?';
            $bind[] = $reason;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $countRows = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('suppressions') . $whereSql, $bind);
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('suppressions') . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => $rows, 'total' => $countRows ? (int) $countRows[0]['c'] : 0];
    }

    /** @return array<string,int> reason => count */
    public static function reasonCounts()
    {
        $out = array_fill_keys(self::REASONS, 0);
        foreach (Db::query('SELECT reason, COUNT(*) AS c FROM ' . Db::t('suppressions') . ' GROUP BY reason') as $row) {
            $out[(string) $row['reason']] = (int) $row['c'];
        }
        return $out;
    }

    /* ----------------------------------------------------- send gates -- */

    /**
     * Everything that must be true before a marketing campaign may be sent.
     *
     * Returns a list of blocking problems (empty = clear to send) and a list
     * of non-blocking warnings. The UI shows both; the sender enforces only
     * the blockers.
     *
     * @return array{blockers:string[],warnings:string[]}
     */
    public static function preflight(array $campaign)
    {
        $blockers = [];
        $warnings = [];

        if (!Settings::bool('sending_enabled', false)) {
            $blockers[] = 'Sending is disabled in module settings. Enable it once your sender identity and transport are configured.';
        }
        if (Settings::bool('kill_switch', false)) {
            $blockers[] = 'The emergency kill switch is on. No mail will leave the queue until it is turned off.';
        }

        $fromEmail = trim((string) ($campaign['from_email'] ?? '')) ?: Settings::string('from_email', '');
        $fromName = trim((string) ($campaign['from_name'] ?? '')) ?: Settings::string('from_name', '');
        if (!Str::isEmail($fromEmail)) {
            $blockers[] = 'A valid "from" address is required. Set one on the campaign or in module settings.';
        }
        if ($fromName === '') {
            $warnings[] = 'No sender name is set — recipients will only see the email address.';
        }

        $replyTo = trim((string) ($campaign['reply_to'] ?? '')) ?: Settings::string('reply_to', '');
        if ($replyTo !== '' && !Str::isEmail($replyTo)) {
            $blockers[] = 'The reply-to address is not a valid email address.';
        }

        if (trim((string) ($campaign['subject'] ?? '')) === '') {
            $blockers[] = 'The campaign has no subject line.';
        }

        $html = (string) ($campaign['html'] ?? '');
        if (trim($html) === '') {
            $blockers[] = 'The campaign has no content.';
        }

        if (($campaign['type'] ?? 'campaign') !== 'transactional') {
            if (Settings::bool('require_unsubscribe', true) && strpos($html, '{{unsubscribe_url}}') === false) {
                $blockers[] = 'Marketing email must contain an unsubscribe link. Add a Footer block, or insert {{unsubscribe_url}}.';
            }
            if (trim(Settings::string('physical_address', '')) === '') {
                $blockers[] = 'A physical postal address is required on marketing email (CAN-SPAM / most ESP policies). Add one in module settings.';
            } elseif (strpos($html, Str::clip(explode("\n", trim(Settings::string('physical_address', '')))[0], 40)) === false
                && strpos($html, '{{physical_address}}') === false) {
                $warnings[] = 'Your postal address does not appear in the email body. Add a Footer block so it is visible to recipients.';
            }
        }

        return ['ok' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * Lightweight spam/deliverability heuristics shown before sending.
     *
     * Deliberately advisory only — a real spam score needs the receiving
     * server. These are the checks that catch genuine mistakes.
     *
     * @return array{score:int,notes:string[]}
     */
    public static function contentCheck($subject, $html, $text = '')
    {
        $notes = [];
        $score = 0;
        $subject = (string) $subject;
        $html = (string) $html;
        $text = $text !== '' ? (string) $text : Str::htmlToText($html);

        if ($subject !== '' && $subject === strtoupper($subject) && preg_match('/[A-Z]{4,}/', $subject) === 1) {
            $notes[] = 'The subject line is in all capitals, which filters treat as shouting.';
            $score += 2;
        }
        if (substr_count($subject, '!') > 1) {
            $notes[] = 'Multiple exclamation marks in the subject line raise spam scores.';
            $score += 1;
        }
        if (strlen($subject) > 70) {
            $notes[] = 'Subject lines over ~70 characters get truncated on mobile.';
        }
        if (preg_match('/\b(free|winner|guarantee|act now|risk[- ]free|no obligation|click here now|100% free)\b/i', $subject) === 1) {
            $notes[] = 'The subject contains wording commonly associated with spam.';
            $score += 2;
        }

        $textLength = strlen(trim($text));
        $imageCount = preg_match_all('/<img\b/i', $html);
        if ($imageCount > 0 && $textLength < 200) {
            $notes[] = 'This email is mostly images with little text — a classic spam signature. Add body copy.';
            $score += 3;
        }
        if ($imageCount > 0 && preg_match_all('/<img\b(?![^>]*\balt=)/i', $html) > 0) {
            $notes[] = 'Some images have no alt text. They will be invisible when images are blocked.';
            $score += 1;
        }
        if ($textLength === 0) {
            $notes[] = 'There is no readable text in this email.';
            $score += 4;
        }
        if (preg_match('#https?://\d{1,3}(\.\d{1,3}){3}#', $html) === 1) {
            $notes[] = 'Links point at raw IP addresses, which filters penalise heavily.';
            $score += 3;
        }
        if (preg_match_all('/<a\b/i', $html) > 40) {
            $notes[] = 'This email contains a very large number of links.';
            $score += 1;
        }
        if (stripos($html, '<script') !== false) {
            $notes[] = 'Script tags are stripped by every mail client and will get the message filtered.';
            $score += 4;
        }

        if ($notes === []) {
            $notes[] = 'No obvious deliverability problems found.';
        }
        return ['score' => $score, 'notes' => $notes];
    }

    /** Guard used by the admin portal before accepting a manual suppression. */
    public static function assertValidEmail($email)
    {
        if (!Str::isEmail(Str::normalizeEmail($email))) {
            throw new ValidationException('"' . Str::clip($email, 60) . '" is not a valid email address.', ['email' => 'invalid']);
        }
    }
}
