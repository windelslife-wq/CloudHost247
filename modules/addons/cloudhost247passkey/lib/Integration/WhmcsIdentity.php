<?php
/** Existing WHMCS identity reference passed across the Phase 4 handoff boundary. */

namespace CloudHost247\Passkey\Integration;

use CloudHost247\Passkey\Model\IdentityScope;

class WhmcsIdentity
{
    private $userType;
    private $userId;

    public function __construct($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $this->userType = $userType;
        $this->userId = $userId;
    }

    public function userType()
    {
        return $this->userType;
    }

    public function userId()
    {
        return $this->userId;
    }

    /** Internal identity tuple; no email, password, token, or profile data is carried. */
    public function toArray()
    {
        return [
            'user_type' => $this->userType,
            'user_id' => $this->userId,
        ];
    }
}
