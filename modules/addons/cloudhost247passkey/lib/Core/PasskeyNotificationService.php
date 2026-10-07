<?php
/** Phase 8 preference and host-delivery orchestration; no mail or UI is created. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\SecurityNotification;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;

class PasskeyNotificationService
{
    private $identityProvider;
    private $policyResolver;
    private $preferences;

    public function __construct(
        WhmcsIdentityProviderInterface $identityProvider,
        PasskeyPolicyResolver $policyResolver,
        UserPreferencesRepository $preferences = null
    ) {
        $this->identityProvider = $identityProvider;
        $this->policyResolver = $policyResolver;
        $this->preferences = $preferences ?: new UserPreferencesRepository();
    }

    /** Return only the two boolean preferences; no contact data is loaded. */
    public function preferences(WhmcsIdentity $identity)
    {
        $this->assertAuthenticatedIdentity($identity);
        return $this->preferences->values($identity->userType(), $identity->userId());
    }

    /** Update only the existing addon-owned preference flags. */
    public function updatePreferences(WhmcsIdentity $identity, array $preferences)
    {
        $this->assertAuthenticatedIdentity($identity);
        $record = $this->preferences->upsert(
            $identity->userType(),
            $identity->userId(),
            $preferences
        );
        $data = $record->toArray();
        return [
            'login_notification_enabled' => $data['login_notification_enabled'],
            'security_event_notification_enabled' => $data['security_event_notification_enabled'],
        ];
    }

    /**
     * Deliver through an explicit host sink only when the identity opted in.
     * The callback receives a redacted envelope, never email/session/token data.
     */
    public function dispatch(
        WhmcsIdentity $identity,
        $notificationType,
        $eventType,
        callable $deliver,
        array $metadata = []
    ) {
        $this->assertAuthenticatedIdentity($identity);
        if (!$deliver) {
            throw new \InvalidArgumentException('An explicit host notification sink is required.');
        }
        if (!in_array($notificationType, [SecurityNotification::LOGIN, SecurityNotification::SECURITY_EVENT], true)) {
            throw new \InvalidArgumentException('Unsupported Passkey notification type.');
        }
        $enabledKey = $notificationType === SecurityNotification::LOGIN
            ? 'login_notification_enabled'
            : 'security_event_notification_enabled';
        $values = $this->preferences->values($identity->userType(), $identity->userId());
        if ((int) $values[$enabledKey] !== 1) {
            return ['status' => 'suppressed', 'reason' => 'preference_disabled'];
        }
        $notification = new SecurityNotification($identity, $notificationType, $eventType, $metadata);
        call_user_func($deliver, $notification);
        return ['status' => 'delivered'];
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
}
