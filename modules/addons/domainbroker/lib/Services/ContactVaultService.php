<?php
/**
 * Domain Broker — registrant contact vault (anonymous negotiation).
 *
 * The whole point of a brokered acquisition is that the buyer and the seller
 * do not deal with each other directly. The registrant's identity is held
 * here, encrypted field-by-field at rest, and is only ever decrypted for a
 * principal holding OWNER_CONTACT_VIEW. Customers have no API path to it at
 * all — not a filtered one, none.
 *
 * Reads of decrypted data are audited individually, so "who looked at the
 * seller's details, and when" is answerable.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Core\Validator;

class ContactVaultService
{
    const ROLE_OWNER       = 'owner';
    const ROLE_OWNER_AGENT = 'owner_agent';
    const ROLE_REGISTRAR   = 'registrar';

    const ROLES = [self::ROLE_OWNER, self::ROLE_OWNER_AGENT, self::ROLE_REGISTRAR];

    /** Encrypted columns and their plaintext field names. */
    const FIELDS = [
        'name' => 'name_enc',
        'organisation' => 'organisation_enc',
        'email' => 'email_enc',
        'phone' => 'phone_enc',
        'address' => 'address_enc',
        'notes' => 'notes_enc',
    ];

    /** @var RequestService */
    protected $requests;

    public function __construct(RequestService $requests = null)
    {
        $this->requests = $requests ?: new RequestService();
    }

    /**
     * Store or update the registrant's details for a request.
     */
    public function store(Actor $actor, $requestId, array $input)
    {
        Rbac::assert($actor, Rbac::OWNER_CONTACT_VIEW);
        $request = $this->requests->findForActor($actor, $requestId);
        $this->assertBrokerOwnsRequest($actor, $request);

        $role = isset($input['role']) ? (string) $input['role'] : self::ROLE_OWNER;
        if (!in_array($role, self::ROLES, true)) {
            throw new ValidationException('Unknown contact role.', ['role' => 'Unknown role.']);
        }

        $data = Validator::make($input)
            ->text('name', 190, false)
            ->text('organisation', 190, false)
            ->email('email', false)
            ->phone('phone', false)
            ->text('address', 1000, false)
            ->text('notes', 2000, false)
            ->in('preferred_channel', ['email', 'phone', 'marketplace', 'registrar', 'postal'], false, 'email')
            ->in('source', ['rdap', 'registrar', 'marketplace', 'inbound', 'manual'], false, 'manual')
            ->validate();

        $hasSomething = false;
        foreach (array_keys(self::FIELDS) as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $hasSomething = true;
                break;
            }
        }
        if (!$hasSomething) {
            throw new ValidationException('Record at least one contact detail.', [
                'email' => 'Provide an email address, phone number or name.',
            ]);
        }

        $now = Clock::now();
        $row = [
            'request_id' => (int) $request['id'],
            'role' => $role,
            'preferred_channel' => $data['preferred_channel'],
            'source' => $data['source'],
            'updated_at' => $now,
        ];
        foreach (self::FIELDS as $field => $column) {
            $value = isset($data[$field]) ? (string) $data[$field] : '';
            $row[$column] = $value !== '' ? Crypto::encrypt($value, 'contact.' . $field) : null;
        }
        $row['email_index'] = !empty($data['email'])
            ? Crypto::blindIndex(strtolower((string) $data['email']), 'contact.email')
            : null;

        $existing = Db::first('contacts', [
            'request_id' => (int) $request['id'],
            'role' => $role,
            'deleted_at' => null,
        ]);

        if ($existing) {
            Db::update('contacts', $row, ['id' => (int) $existing['id']]);
            $contactId = (int) $existing['id'];
            $action = 'contact.updated';
        } else {
            $row['created_by_type'] = $actor->type;
            $row['created_by_id'] = (int) $actor->actorId();
            $row['created_at'] = $now;
            $contactId = Db::insert('contacts', $row);
            $action = 'contact.recorded';
        }

        // The audit entry records *that* details changed, never the values.
        Audit::record($actor, $action, [
            'request_id' => (int) $request['id'],
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'new' => [
                'role' => $role,
                'source' => $data['source'],
                'fields' => array_values(array_filter(array_keys(self::FIELDS), function ($f) use ($data) {
                    return isset($data[$f]) && $data[$f] !== '';
                })),
            ],
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $this->summary($contactId);
    }

    /**
     * Non-sensitive summary: proves a contact exists and shows masked hints,
     * without decrypting anything the caller does not need.
     */
    public function summary($contactId)
    {
        $row = Db::first('contacts', ['id' => (int) $contactId, 'deleted_at' => null]);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'request_id' => (int) $row['request_id'],
            'role' => $row['role'],
            'has_email' => !empty($row['email_enc']),
            'has_phone' => !empty($row['phone_enc']),
            'has_address' => !empty($row['address_enc']),
            'preferred_channel' => $row['preferred_channel'],
            'source' => $row['source'],
            'verified' => (int) $row['verified'] === 1,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** Masked summaries for every contact on a request. */
    public function summariesFor($requestId)
    {
        $out = [];
        foreach (Db::fetch('contacts', ['request_id' => (int) $requestId, 'deleted_at' => null], ['order' => 'id']) as $row) {
            $out[] = $this->summary($row['id']);
        }
        return $out;
    }

    /**
     * Decrypt and return the contact. Permissioned and audited on every call.
     */
    public function reveal(Actor $actor, $contactId, $reason = '')
    {
        Rbac::assert($actor, Rbac::OWNER_CONTACT_VIEW);
        $row = Db::first('contacts', ['id' => (int) $contactId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('Contact not found.');
        }
        $request = $this->requests->findForActor($actor, $row['request_id']);
        $this->assertBrokerOwnsRequest($actor, $request);

        $out = $this->summary($contactId);
        foreach (self::FIELDS as $field => $column) {
            $out[$field] = $row[$column] ? Crypto::tryDecrypt($row[$column], 'contact.' . $field) : null;
        }

        Audit::record($actor, 'contact.revealed', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'contact',
            'entity_id' => (int) $row['id'],
            'new' => ['role' => $row['role']],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $out;
    }

    /** Mark a contact as verified (e.g. the registrant replied from the address). */
    public function markVerified(Actor $actor, $contactId, $note = '')
    {
        Rbac::assert($actor, Rbac::VERIFICATION_RECORD);
        $row = Db::first('contacts', ['id' => (int) $contactId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('Contact not found.');
        }
        Db::update('contacts', [
            'verified' => 1,
            'verified_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        Audit::record($actor, 'contact.verified', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'contact',
            'entity_id' => (int) $row['id'],
            'new' => ['verified' => true],
            'reason' => $note,
            'visibility' => Audit::VIS_BROKER,
        ]);

        return $this->summary($contactId);
    }

    /**
     * Find other requests involving the same registrant email — used by the
     * risk engine to spot a seller who repeatedly fails to transfer. Takes a
     * plaintext email, matches on the blind index, never decrypts.
     *
     * @return int[] request ids
     */
    public function requestIdsForEmail($email)
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return [];
        }
        $index = Crypto::blindIndex($email, 'contact.email');
        $rows = Db::fetch('contacts', ['email_index' => $index, 'deleted_at' => null], ['order' => 'id']);
        return array_values(array_unique(array_map(function ($r) {
            return (int) $r['request_id'];
        }, $rows)));
    }

    /** Soft-delete (history retained). */
    public function forget(Actor $actor, $contactId, $reason)
    {
        Rbac::assert($actor, Rbac::PII_VIEW);
        $row = Db::first('contacts', ['id' => (int) $contactId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('Contact not found.');
        }
        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
        }

        // Crypto-shred: the row survives so the negotiation history still
        // shows that a registrant contact existed and when it was removed,
        // but the personal data itself is destroyed, not merely hidden.
        $updates = [
            'deleted_at' => Clock::now(),
            'updated_at' => Clock::now(),
            'email_index' => null,
        ];
        foreach (self::FIELDS as $column) {
            $updates[$column] = null;
        }
        Db::update('contacts', $updates, ['id' => (int) $row['id']]);

        Audit::record($actor, 'contact.forgotten', [
            'request_id' => (int) $row['request_id'],
            'entity_type' => 'contact',
            'entity_id' => (int) $row['id'],
            'new' => ['deleted' => true],
            'reason' => $reason,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return true;
    }

    protected function assertBrokerOwnsRequest(Actor $actor, array $request)
    {
        if (!$actor->isBroker()) {
            return;
        }
        if ((int) $request['assigned_broker_id'] !== (int) $actor->brokerId) {
            throw new AuthorizationException('This request is assigned to another broker.');
        }
    }
}
