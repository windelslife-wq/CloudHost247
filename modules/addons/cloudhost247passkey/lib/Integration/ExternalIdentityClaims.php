<?php
/** Verified external-identity reference supplied by an explicit host OIDC adapter. */

namespace CloudHost247\Passkey\Integration;

use CloudHost247\Passkey\Model\ExternalIdentityRecord;
use CloudHost247\Passkey\Model\ModelValidation;

class ExternalIdentityClaims
{
    const PROVIDER_MICROSOFT_ENTRA = 'microsoft_entra';
    const MAX_ASSERTION_AGE_SECONDS = 300;

    private $row;

    /**
     * The host adapter must verify the provider response before constructing
     * this value object. Tokens, emails, profile fields and client secrets are
     * intentionally not accepted at this boundary.
     */
    public function __construct(array $claims)
    {
        ModelValidation::rejectKeys($claims, [
            'access_token', 'refresh_token', 'id_token', 'token', 'authorization_code',
            'client_secret', 'password', 'email', 'name', 'raw_response',
        ]);
        ModelValidation::allowKeys($claims, [
            'provider', 'tenant_id', 'subject_id', 'issued_at', 'expires_at',
        ], 'external identity claims');
        $provider = ModelValidation::text($claims['provider'] ?? '', 'provider', 40);
        if ($provider !== self::PROVIDER_MICROSOFT_ENTRA) {
            throw new \InvalidArgumentException('Only the explicitly supported Microsoft Entra provider may be linked.');
        }
        $tenantId = ModelValidation::text($claims['tenant_id'] ?? '', 'tenant_id', 191);
        $subjectId = ModelValidation::text($claims['subject_id'] ?? '', 'subject_id', 255);
        $issuedAt = ModelValidation::timestamp($claims['issued_at'] ?? null, 'issued_at');
        $expiresAt = ModelValidation::timestamp($claims['expires_at'] ?? null, 'expires_at');
        if ($expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('External identity claims must expire after issuance.');
        }
        $this->row = [
            'provider' => $provider,
            'tenant_id' => $tenantId,
            'subject_id' => $subjectId,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'identity_hash' => ExternalIdentityRecord::identityHash($provider, $tenantId, $subjectId),
        ];
    }

    public function isFreshAt($utcNow, $maxAgeSeconds = self::MAX_ASSERTION_AGE_SECONDS)
    {
        $utcNow = ModelValidation::timestamp($utcNow, 'utcNow');
        $maxAgeSeconds = filter_var($maxAgeSeconds, FILTER_VALIDATE_INT);
        if ($maxAgeSeconds === false || (int) $maxAgeSeconds < 1 || (int) $maxAgeSeconds > 3600) {
            throw new \InvalidArgumentException('External identity assertion age is outside the supported range.');
        }
        $nowEpoch = strtotime($utcNow . ' UTC');
        $issuedEpoch = strtotime($this->row['issued_at'] . ' UTC');
        $expiresEpoch = strtotime($this->row['expires_at'] . ' UTC');
        return $issuedEpoch <= $nowEpoch
            && $expiresEpoch > $nowEpoch
            && ($nowEpoch - $issuedEpoch) <= (int) $maxAgeSeconds;
    }

    public function provider()
    {
        return $this->row['provider'];
    }

    public function tenantId()
    {
        return $this->row['tenant_id'];
    }

    public function subjectId()
    {
        return $this->row['subject_id'];
    }

    public function identityHash()
    {
        return $this->row['identity_hash'];
    }

    public function toArray()
    {
        return $this->row;
    }
}
