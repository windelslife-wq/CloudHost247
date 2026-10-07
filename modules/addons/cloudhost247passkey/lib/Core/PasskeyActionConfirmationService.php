<?php
/**
 * Reusable Passkey confirmation for sensitive operations.
 *
 * A short-lived action ticket binds the authenticated WHMCS identity, the
 * intended action, the PHP session, the RP ID, and the origin. The ticket is
 * consumed only after a fresh WebAuthn assertion for the same identity is
 * verified by the injected ceremony implementation. Tickets are one-use and
 * expire quickly, which prevents replay.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;
use CloudHost247\Passkey\Model\ChallengeRecord;
use CloudHost247\Passkey\Model\IdentityScope;
use RuntimeException;

class PasskeyActionConfirmationService
{
    const TTL_SECONDS = 120;

    const ACTIONS = [
        'account.security',
        'payment.config',
        'admin.privileges',
        'config.delete',
        'auth.policy',
        'security.disable',
        'credential.revoke',
        'credential.disable',
        'entra.link',
        'entra.unlink',
        'password.reset',
    ];

    private $identityProvider;
    private $policyResolver;
    private $events;

    public function __construct(
        WhmcsIdentityProviderInterface $identityProvider,
        PasskeyPolicyResolver $policyResolver,
        SecurityEventRepository $events = null
    ) {
        $this->identityProvider = $identityProvider;
        $this->policyResolver = $policyResolver;
        $this->events = $events ?: new SecurityEventRepository();
    }

    public static function isSupportedAction($actionCode)
    {
        return is_string($actionCode) && in_array($actionCode, self::ACTIONS, true);
    }

    /**
     * Issue an action ticket plus fresh WebAuthn authentication options.
     *
     * The caller performs the browser ceremony with the returned options and
     * then calls confirm() with the ticket ID and the assertion response.
     */
    public function beginConfirmation(
        WebAuthnConfig $config,
        PasskeyAuthenticationCeremonyInterface $ceremonies,
        WhmcsIdentity $identity,
        $actionCode
    ) {
        $this->assertAuthenticatedIdentity($identity);
        $actionCode = $this->validateAction($actionCode);

        $nowEpoch = time();
        $now = gmdate('Y-m-d H:i:s', $nowEpoch);
        $expires = gmdate('Y-m-d H:i:s', $nowEpoch + self::TTL_SECONDS);
        $rawChallenge = random_bytes(32);
        $row = [
            'challenge_hash' => hash('sha256', $rawChallenge),
            'user_type' => $identity->userType(),
            'user_id' => $identity->userId(),
            'challenge_type' => ChallengeRecord::ACTION_CONFIRMATION,
            'action_code' => $actionCode,
            'session_binding_hash' => SessionBinding::currentHash(),
            'rp_id' => $config->rpId(),
            'origin' => $config->origin(),
            'expires_at' => $expires,
            'consumed_at' => null,
            'created_at' => $now,
        ];
        $ticketId = Db::insert('challenges', $row);
        // Validate the persisted shape before it can be consumed.
        $row['id'] = $ticketId;
        new ChallengeRecord($row);

        try {
            $options = $ceremonies->beginAuthentication(
                $config,
                $identity->userType(),
                $identity->userId()
            );
        } catch (\Throwable $error) {
            Db::execute(
                'DELETE FROM `' . Db::table('challenges') . '` WHERE `id` = ? AND `consumed_at` IS NULL',
                [$ticketId]
            );
            throw $error;
        }

        return [
            'ticket_id' => (int) $ticketId,
            'action_code' => $actionCode,
            'expires_at' => $expires,
            'expires_in' => self::TTL_SECONDS,
            'publicKey' => $options['publicKey'],
        ];
    }

    /**
     * Verify a fresh assertion and consume the action ticket atomically.
     *
     * Returns the confirmation receipt; the sensitive operation must only run
     * after this method returns successfully.
     */
    public function confirm(
        WebAuthnConfig $config,
        PasskeyAuthenticationCeremonyInterface $ceremonies,
        WhmcsIdentity $identity,
        $ticketId,
        $credentialResponseJson,
        array $audit = []
    ) {
        $this->assertAuthenticatedIdentity($identity);
        $ticketId = $this->validateTicketId($ticketId);
        if (!is_string($credentialResponseJson) || $credentialResponseJson === ''
            || strlen($credentialResponseJson) > 262144) {
            throw new \InvalidArgumentException('Passkey confirmation response is missing or too large.');
        }
        $now = gmdate('Y-m-d H:i:s');

        return Db::transaction(function () use (
            $config,
            $ceremonies,
            $identity,
            $ticketId,
            $credentialResponseJson,
            $audit,
            $now
        ) {
            $ticket = $this->loadUsableTicket($ticketId, $identity, $config, $now);
            $verified = $ceremonies->finishAuthentication(
                $config,
                $identity->userType(),
                $identity->userId(),
                $credentialResponseJson
            );
            if (!is_array($verified) || !isset($verified['user_type'], $verified['user_id'])) {
                throw new RuntimeException('WebAuthn verification returned an incomplete identity.');
            }
            list($verifiedType, $verifiedId) = IdentityScope::validate(
                $verified['user_type'],
                $verified['user_id']
            );
            if ($verifiedType !== $identity->userType() || $verifiedId !== $identity->userId()) {
                throw new RuntimeException('Verified Passkey identity does not match the confirmation ticket.');
            }
            $changed = Db::update(
                'challenges',
                ['id' => $ticket['id'], 'consumed_at' => null],
                ['consumed_at' => $now]
            );
            if ($changed !== 1) {
                throw new RuntimeException('Passkey confirmation ticket has already been consumed.');
            }
            $eventId = $this->events->append([
                'user_type' => $identity->userType(),
                'user_id' => $identity->userId(),
                'event_type' => 'action_confirmation.succeeded',
                'success' => 1,
                'ip_address' => isset($audit['ip_address']) ? $audit['ip_address'] : null,
                'user_agent' => isset($audit['user_agent']) ? $audit['user_agent'] : null,
                'metadata' => ['action_code' => $ticket['action_code']],
            ]);
            return [
                'confirmed' => true,
                'action_code' => $ticket['action_code'],
                'confirmed_at' => $now,
                'event_id' => (int) $eventId,
            ];
        });
    }

    private function loadUsableTicket($ticketId, WhmcsIdentity $identity, WebAuthnConfig $config, $utcNow)
    {
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('challenges') . '` WHERE `id` = ?',
            [$ticketId]
        );
        if (!$row) {
            throw new RuntimeException('Passkey confirmation ticket is unknown or expired.');
        }
        try {
            $record = new ChallengeRecord($row);
            $ticket = $record->toArray();
            $sessionHash = SessionBinding::currentHash();
            if ($ticket['challenge_type'] !== ChallengeRecord::ACTION_CONFIRMATION
                || $ticket['user_type'] !== $identity->userType()
                || $ticket['user_id'] !== $identity->userId()
                || !hash_equals($ticket['session_binding_hash'], $sessionHash)
                || !hash_equals($ticket['rp_id'], $config->rpId())
                || !hash_equals($ticket['origin'], $config->origin())
                || !$record->isUsableAt($utcNow)) {
                throw new RuntimeException('Passkey confirmation ticket binding failed.');
            }
            return $ticket;
        } catch (\Throwable $error) {
            if ($error instanceof RuntimeException
                && in_array($error->getMessage(), [
                    'Passkey confirmation ticket binding failed.',
                    'Passkey confirmation ticket is unknown or expired.',
                ], true)) {
                throw $error;
            }
            throw new RuntimeException('Passkey confirmation ticket is unknown or expired.');
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
            throw new RuntimeException('The WHMCS identity is missing, disabled, or outside the current session scope.');
        }
    }

    private function validateAction($actionCode)
    {
        if (!self::isSupportedAction($actionCode)) {
            throw new \InvalidArgumentException('Unsupported Passkey confirmation action.');
        }
        return (string) $actionCode;
    }

    private function validateTicketId($ticketId)
    {
        $ticketId = filter_var($ticketId, FILTER_VALIDATE_INT);
        if ($ticketId === false || (int) $ticketId < 1) {
            throw new \InvalidArgumentException('Passkey confirmation ticket ID is invalid.');
        }
        return (int) $ticketId;
    }
}
