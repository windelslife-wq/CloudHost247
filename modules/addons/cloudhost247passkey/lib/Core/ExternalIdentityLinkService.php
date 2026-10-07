<?php
/** Phase 7 external-identity linking boundary; it never creates a login session. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\ExternalIdentityClaims;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;
use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\ModelValidation;

class ExternalIdentityLinkService
{
    const LINK_ACTION = 'entra.link';
    const UNLINK_ACTION = 'entra.unlink';

    private $identityProvider;
    private $policyResolver;
    private $settings;
    private $identities;
    private $events;

    public function __construct(
        WhmcsIdentityProviderInterface $identityProvider,
        PasskeyPolicyResolver $policyResolver,
        SettingsRepository $settings = null,
        ExternalIdentityRepository $identities = null,
        SecurityEventRepository $events = null
    ) {
        $this->identityProvider = $identityProvider;
        $this->policyResolver = $policyResolver;
        $this->settings = $settings ?: new SettingsRepository();
        $this->identities = $identities ?: new ExternalIdentityRepository();
        $this->events = $events ?: new SecurityEventRepository();
    }

    /** Return masked metadata for links owned by the authenticated identity. */
    public function listLinks(WhmcsIdentity $identity, $includeRevoked = true)
    {
        $this->assertAuthenticatedIdentity($identity);
        $records = $this->identities->listForOwner(
            $identity->userType(),
            $identity->userId(),
            (bool) $includeRevoked
        );
        $result = [];
        foreach ($records as $record) {
            $result[] = self::summary($record);
        }
        return $result;
    }

    /**
     * Link claims that were already verified by a host-owned Entra adapter.
     * The action-confirmation challenge is consumed in the same transaction as
     * the link and audit event.
     */
    public function link(
        WhmcsIdentity $identity,
        ExternalIdentityClaims $claims,
        $challengeId,
        array $audit = []
    ) {
        $this->assertEntraEnabled();
        $this->assertAuthenticatedIdentity($identity);
        if (!$claims instanceof ExternalIdentityClaims
            || !$claims->isFreshAt(gmdate('Y-m-d H:i:s'))) {
            throw new \RuntimeException('The external identity assertion is invalid or expired.');
        }
        $challengeId = self::validateChallengeId($challengeId);
        $now = gmdate('Y-m-d H:i:s');

        return Db::transaction(function () use ($identity, $claims, $challengeId, $audit, $now) {
            $existing = $this->identities->findByHash($claims->identityHash());
            if ($existing !== null
                && ($existing->toArray()['user_type'] !== $identity->userType()
                    || $existing->toArray()['user_id'] !== $identity->userId())) {
                throw new \RuntimeException('The external identity is already linked to another local identity.');
            }
            $this->consumeChallenge($challengeId, $identity, self::LINK_ACTION, $now);

            if ($existing === null) {
                $record = $this->identities->create($claims, $identity->userType(), $identity->userId(), $now);
                $status = 'linked';
            } elseif ($existing->isActive()) {
                $record = $existing;
                $status = 'already_linked';
            } else {
                $record = $this->identities->restoreForOwner(
                    $identity->userType(),
                    $identity->userId(),
                    $existing->toArray()['id'],
                    $now
                );
                $status = 'relinked';
            }
            $this->appendEvent('entra.linked', $identity, $audit, self::LINK_ACTION);
            return [
                'status' => $status,
                'link' => self::summary($record),
            ];
        });
    }

    /** Revoke a link without deleting its historical record. */
    public function unlink(WhmcsIdentity $identity, $recordId, $challengeId, array $audit = [])
    {
        $this->assertEntraEnabled();
        $this->assertAuthenticatedIdentity($identity);
        $recordId = ModelValidation::positiveInt($recordId, 'recordId');
        $challengeId = self::validateChallengeId($challengeId);
        $now = gmdate('Y-m-d H:i:s');

        return Db::transaction(function () use ($identity, $recordId, $challengeId, $audit, $now) {
            $existing = $this->identities->findForOwner($identity->userType(), $identity->userId(), $recordId);
            if ($existing === null || !$existing->isActive()) {
                throw new \RuntimeException('The external identity link is missing, revoked, or outside the current identity scope.');
            }
            $this->consumeChallenge($challengeId, $identity, self::UNLINK_ACTION, $now);
            $record = $this->identities->revokeForOwner(
                $identity->userType(),
                $identity->userId(),
                $recordId,
                $now
            );
            $this->appendEvent('entra.unlinked', $identity, $audit, self::UNLINK_ACTION);
            return [
                'status' => 'unlinked',
                'link' => self::summary($record),
            ];
        });
    }

    private function assertEntraEnabled()
    {
        $serviceEnabled = $this->settings->value('entra_enabled', '0');
        if (!in_array((string) $serviceEnabled, ['1', 'true'], true)) {
            throw new \RuntimeException('Microsoft Entra identity linking is disabled.');
        }
    }

    private function assertAuthenticatedIdentity(WhmcsIdentity $identity)
    {
        if (!$identity instanceof WhmcsIdentity) {
            throw new \InvalidArgumentException('An existing WHMCS identity is required.');
        }
        SessionBinding::currentHash();
        $this->policyResolver->assertAllowed($identity->userType(), $identity->userId());
        $resolved = $this->identityProvider->resolve($identity->userType(), $identity->userId());
        if (!$resolved instanceof WhmcsIdentity
            || $resolved->userType() !== $identity->userType()
            || $resolved->userId() !== $identity->userId()) {
            throw new \RuntimeException('The WHMCS identity is missing, disabled, or outside the current session scope.');
        }
    }

    private function consumeChallenge($challengeId, WhmcsIdentity $identity, $actionCode, $utcNow)
    {
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('challenges') . '` WHERE `id` = ?',
            [$challengeId]
        );
        if (!$row) {
            throw new \RuntimeException('The external identity confirmation is invalid or expired.');
        }
        try {
            $challenge = new ChallengeRecord($row);
            $data = $challenge->toArray();
            $sessionHash = SessionBinding::currentHash();
            if (!in_array($data['challenge_type'], [ChallengeRecord::ENTRA_LINK, ChallengeRecord::ACTION_CONFIRMATION], true)
                || $data['action_code'] !== $actionCode
                || $data['user_type'] !== $identity->userType()
                || $data['user_id'] !== $identity->userId()
                || !hash_equals($data['session_binding_hash'], $sessionHash)
                || !$challenge->isUsableAt($utcNow)) {
                throw new \RuntimeException('The external identity confirmation is invalid or expired.');
            }
            $changed = Db::update(
                'challenges',
                ['id' => $data['id'], 'consumed_at' => null],
                ['consumed_at' => $utcNow]
            );
            if ($changed !== 1) {
                throw new \RuntimeException('The external identity confirmation is invalid or expired.');
            }
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException && $error->getMessage() === 'The external identity confirmation is invalid or expired.') {
                throw $error;
            }
            throw new \RuntimeException('The external identity confirmation is invalid or expired.');
        }
    }

    private function appendEvent($eventType, WhmcsIdentity $identity, array $audit, $actionCode)
    {
        $this->events->append([
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'event_type' => $eventType,
            'success' => 1,
            'ip_address' => $audit['ip_address'] ?? null,
            'user_agent' => $audit['user_agent'] ?? null,
            'metadata' => array_merge($audit['metadata'] ?? [], ['action_code' => $actionCode]),
        ]);
    }

    private static function validateChallengeId($challengeId)
    {
        $challengeId = filter_var($challengeId, FILTER_VALIDATE_INT);
        if ($challengeId === false || (int) $challengeId < 1) {
            throw new \InvalidArgumentException('An external identity confirmation challenge is required.');
        }
        return (int) $challengeId;
    }

    private static function summary($record)
    {
        $data = $record->toArray();
        return [
            'id' => $data['id'],
            'provider' => $data['provider'],
            'tenant_id' => $data['tenant_id'],
            'subject_fingerprint' => substr($data['identity_hash'], 0, 16),
            'active' => $record->isActive(),
            'linked_at' => $data['linked_at'],
            'last_authenticated_at' => $data['last_authenticated_at'],
        ];
    }
}
