<?php
/** Add public credential-source storage and opaque WebAuthn user handles. */

use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\Schema;

return [
    'id' => '0002_webauthn_core',
    'description' => 'Validated WebAuthn credential-source payloads and opaque local identity handles',
    'up' => function () {
        if (!Db::columnExists('credentials', 'credential_source_json')) {
            try {
                Db::execute(Schema::credentialSourceColumnStatement(Db::driver()));
            } catch (Throwable $error) {
                // If a concurrent activation won the race, only treat the now-present column as success.
                if (!Db::columnExists('credentials', 'credential_source_json')) {
                    throw $error;
                }
            }
        }
        foreach (Schema::userHandleStatements(Db::driver()) as $sql) {
            Db::execute($sql);
        }
    },
];
