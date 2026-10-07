<?php
/** Local WHMCS identity categories; IDs are references, not duplicated users. */

namespace CloudHost247\Passkey\Model;

class IdentityScope
{
    const CLIENT = 'client';
    const CLIENT_USER = 'client_user';
    const ADMIN = 'admin';

    public static function validate($userType, $userId, $allowUnknownId = false)
    {
        return ModelValidation::identity($userType, $userId, $allowUnknownId);
    }
}
