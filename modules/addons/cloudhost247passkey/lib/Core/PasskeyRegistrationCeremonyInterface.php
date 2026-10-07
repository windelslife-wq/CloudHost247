<?php
/** Registration ceremony boundary used by authenticated credential management. */

namespace CloudHost247\Passkey\Core;

interface PasskeyRegistrationCeremonyInterface
{
    public function beginRegistration(WebAuthnConfig $config, $userType, $userId, $userName, $displayName);

    public function finishRegistration(
        WebAuthnConfig $config,
        $userType,
        $userId,
        $credentialResponseJson,
        $deviceName = 'Passkey',
        $registrationIp = null,
        $registrationUserAgent = null
    );
}
