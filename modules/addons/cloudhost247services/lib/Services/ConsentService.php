<?php
/**
 * Pseudonymous consent decision recorder.
 *
 * Consent is a public browser action, not an authenticated privilege. The
 * endpoint accepts only a strict POST payload, rate-limits by the existing
 * module bucket, and stores no raw IP, user agent or browser identifier.
 *
 * @package Chs\Services
 */
namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Identity;
use Chs\Core\RateLimiter;

class ConsentService
{
    /** @return bool whether a valid decision was persisted */
    public function record($status, $consentId, $categories, $policyVersion, $language)
    {
        if (!Db::tableExists('consent_records')) {
            return false;
        }
        $status = strtolower(trim((string) $status));
        if (!in_array($status, ['allow', 'deny', 'revoke'], true)) {
            return false;
        }
        $consentId = trim((string) $consentId);
        if (!preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $consentId)) {
            return false;
        }
        $policyVersion = trim((string) $policyVersion);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $policyVersion)) {
            return false;
        }
        $allowed = ['necessary', 'analytics', 'marketing'];
        $received = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $categories)))));
        $received = array_values(array_intersect($received, $allowed));
        if ($status !== 'allow') {
            $received = ['necessary'];
        } elseif (!in_array('necessary', $received, true)) {
            array_unshift($received, 'necessary');
        }
        $language = strtolower(trim((string) $language));
        if (!preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})?$/', $language)) {
            $language = '';
        }

        // The existing rate-limit table is shared with all module endpoints.
        if (!RateLimiter::hit('consent_record', RateLimiter::bucketForCurrentRequest(), 30, 3600)) {
            return false;
        }

        Db::insert('consent_records', [
            'consent_hash' => hash('sha256', $consentId),
            'status' => $status,
            'categories' => implode(',', $received),
            'policy_version' => $policyVersion,
            'language' => $language,
            'client_id' => (int) (Identity::clientId() ?: 0),
            'recorded_at' => Clock::now(),
        ]);
        return true;
    }
}
