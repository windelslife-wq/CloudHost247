<?php
/**
 * Domain Broker — issuing and revoking API tokens.
 *
 * The plaintext token exists exactly once, in the response to issue(). From
 * then on only its SHA-256 hash is held, so the credential cannot be read back
 * out of the database, a backup, or the admin UI.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Core\Validator;

class ApiTokenService
{
    const PREFIX = 'dbk_';

    /**
     * Issue a token.
     *
     * @return array{token:string, record:array} the plaintext is returned once
     */
    public function issue(Actor $actor, array $input)
    {
        Rbac::assert($actor, Rbac::SETTINGS_MANAGE);

        $data = Validator::make($input)
            ->text('name', 190, true, 3)
            ->in('actor_type', [Actor::TYPE_CUSTOMER, Actor::TYPE_BROKER, Actor::TYPE_ADMIN], true)
            ->integer('actor_id', 1, null, true)
            ->validate();

        $role = isset($input['actor_role']) ? (string) $input['actor_role'] : '';
        if ($data['actor_type'] === Actor::TYPE_CUSTOMER) {
            $role = 'customer';
        } elseif ($data['actor_type'] === Actor::TYPE_BROKER) {
            $broker = Db::first('brokers', ['id' => (int) $data['actor_id'], 'deleted_at' => null]);
            if (!$broker) {
                throw new ValidationException('Unknown broker.', ['actor_id' => 'Unknown broker.']);
            }
            $role = $broker['role'];
        } else {
            if (!in_array($role, Rbac::roles(), true) || strpos($role, 'admin_') !== 0) {
                throw new ValidationException('Choose an administrative role for this token.', [
                    'actor_role' => 'Unknown role.',
                ]);
            }
            // A token can never be more privileged than the person issuing it.
            foreach (Rbac::grants($role) as $permission) {
                if (!Rbac::allows($actor, $permission)) {
                    throw new ValidationException(
                        'You cannot issue a token with more authority than your own account.',
                        ['actor_role' => 'Exceeds your own permissions.']
                    );
                }
            }
        }

        $scopes = isset($input['scopes']) && is_array($input['scopes'])
            ? array_values(array_filter(array_map('strval', $input['scopes'])))
            : [];

        $plaintext = self::PREFIX . Crypto::randomToken(32);
        $now = Clock::now();

        $id = Db::insert('tokens', [
            'name' => $data['name'],
            'token_hash' => hash('sha256', $plaintext),
            'token_hint' => substr($plaintext, -4),
            'actor_type' => $data['actor_type'],
            'actor_id' => (int) $data['actor_id'],
            'actor_role' => $role,
            'actor_label' => isset($input['actor_label']) ? Str::clip((string) $input['actor_label'], 190) : $data['name'],
            'scopes' => $scopes ? Str::jsonEncode($scopes) : null,
            'ip_allowlist' => isset($input['ip_allowlist']) && $input['ip_allowlist'] !== ''
                ? Str::clip((string) $input['ip_allowlist'], 500) : null,
            'active' => 1,
            'request_count' => 0,
            'expires_at' => !empty($input['expires_at']) ? (string) $input['expires_at'] : null,
            'created_by' => $actor->identity(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($actor, 'api.token.issued', [
            'entity_type' => 'api_token',
            'entity_id' => $id,
            // The token itself is deliberately absent from the audit record.
            'new' => [
                'name' => $data['name'],
                'actor_type' => $data['actor_type'],
                'actor_id' => (int) $data['actor_id'],
                'role' => $role,
                'hint' => substr($plaintext, -4),
            ],
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return ['token' => $plaintext, 'record' => $this->find($id)];
    }

    public function find($tokenId)
    {
        return Db::first('tokens', ['id' => (int) $tokenId, 'deleted_at' => null]);
    }

    public function listTokens(Actor $actor)
    {
        Rbac::assert($actor, Rbac::SETTINGS_MANAGE);
        $rows = Db::fetch('tokens', ['deleted_at' => null], ['order' => 'id', 'dir' => 'desc']);
        foreach ($rows as &$row) {
            unset($row['token_hash']);
        }
        return $rows;
    }

    public function revoke(Actor $actor, $tokenId, $reason)
    {
        Rbac::assert($actor, Rbac::SETTINGS_MANAGE);
        $row = $this->find($tokenId);
        if (!$row) {
            throw new NotFoundException('API token not found.');
        }
        $reason = Str::cleanText($reason, 500);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }

        Db::update('tokens', [
            'active' => 0,
            'revoked_at' => Clock::now(),
            'revoked_reason' => $reason,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($actor, 'api.token.revoked', [
            'entity_type' => 'api_token',
            'entity_id' => (int) $row['id'],
            'previous' => ['active' => 1],
            'new' => ['active' => 0],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $this->find($row['id']);
    }
}
