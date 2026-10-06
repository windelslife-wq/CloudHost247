<?php
/**
 * Domain Broker — role based access control.
 *
 * Permissions are granted to roles, roles are granted to principals. The
 * shipped grant matrix below is the fallback; it is seeded into
 * domain_broker_role_permissions at install time so an operator can tighten or
 * loosen it per deployment without touching code. No role receives a wildcard
 * except `admin_super`, and even that cannot bypass the workflow guards
 * (verification before completion, transfer separate from payment) — those are
 * invariants, not permissions.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Rbac
{
    /* ------------------------------------------------------- permissions -- */

    // Customer surface
    const REQUEST_CREATE            = 'request.create';
    const REQUEST_VIEW_OWN          = 'request.view.own';
    const REQUEST_UPDATE_OWN        = 'request.update.own';
    const REQUEST_CANCEL_OWN        = 'request.cancel.own';
    const OFFER_RESPOND             = 'offer.respond';          // accept / reject
    const OFFER_COUNTER             = 'offer.counter';
    const PAYMENT_PAY               = 'payment.pay';
    const MESSAGE_SEND              = 'message.send';
    const DOCUMENT_UPLOAD           = 'document.upload';
    const DISPUTE_OPEN              = 'dispute.open';

    // Broker surface
    const REQUEST_VIEW_ASSIGNED     = 'request.view.assigned';
    const REQUEST_VIEW_QUEUE        = 'request.view.queue';
    const REQUEST_CLAIM             = 'request.claim';
    const OWNER_CONTACT             = 'owner.contact';
    const OWNER_CONTACT_VIEW        = 'owner.contact.view';     // private contact data
    const OFFER_CREATE              = 'offer.create';
    const OFFER_RECORD_OWNER        = 'offer.record.owner';
    const NEGOTIATION_UPDATE        = 'negotiation.update';
    const NOTE_INTERNAL_WRITE       = 'note.internal.write';
    const NOTE_INTERNAL_READ        = 'note.internal.read';
    const MILESTONE_MARK            = 'milestone.mark';
    const TRANSFER_START            = 'transfer.start';
    const TRANSFER_UPDATE           = 'transfer.update';
    const TRANSFER_CREDENTIAL_VIEW  = 'transfer.credential.view';
    const VERIFICATION_RECORD       = 'verification.record';
    const ESCALATE                  = 'request.escalate';

    // Administrative surface
    const REQUEST_VIEW_ALL          = 'request.view.all';
    const BROKER_ASSIGN             = 'broker.assign';
    const BROKER_MANAGE             = 'broker.manage';
    const REQUEST_APPROVE           = 'request.approve';
    const REQUEST_CANCEL_ANY        = 'request.cancel.any';
    const STATUS_OVERRIDE           = 'status.override';
    const PAYMENT_VIEW              = 'payment.view';
    const PAYMENT_REFUND            = 'payment.refund';
    const PAYMENT_RELEASE           = 'payment.release';        // release escrowed funds
    const COMMISSION_VIEW           = 'commission.view';
    const FEE_MANAGE                = 'fee.manage';
    const SETTINGS_MANAGE           = 'settings.manage';
    const DISPUTE_RESOLVE           = 'dispute.resolve';
    const VERIFICATION_APPROVE      = 'verification.approve';
    const REPORT_VIEW               = 'report.view';
    const REPORT_EXPORT             = 'report.export';
    const AUDIT_VIEW                = 'audit.view';
    const RISK_REVIEW               = 'risk.review';
    const PII_VIEW                  = 'pii.view';

    /** Shipped role → permission matrix. */
    const MATRIX = [
        'customer' => [
            self::REQUEST_CREATE, self::REQUEST_VIEW_OWN, self::REQUEST_UPDATE_OWN,
            self::REQUEST_CANCEL_OWN, self::OFFER_RESPOND, self::OFFER_COUNTER,
            self::PAYMENT_PAY, self::MESSAGE_SEND, self::DOCUMENT_UPLOAD, self::DISPUTE_OPEN,
        ],
        'broker' => [
            self::REQUEST_VIEW_ASSIGNED, self::REQUEST_VIEW_QUEUE, self::REQUEST_CLAIM,
            self::OWNER_CONTACT, self::OWNER_CONTACT_VIEW, self::OFFER_CREATE,
            self::OFFER_RECORD_OWNER, self::NEGOTIATION_UPDATE, self::NOTE_INTERNAL_WRITE,
            self::NOTE_INTERNAL_READ, self::MILESTONE_MARK, self::MESSAGE_SEND,
            self::DOCUMENT_UPLOAD, self::TRANSFER_START, self::TRANSFER_UPDATE,
            self::VERIFICATION_RECORD, self::ESCALATE,
        ],
        'broker_lead' => [
            self::REQUEST_VIEW_ASSIGNED, self::REQUEST_VIEW_QUEUE, self::REQUEST_CLAIM,
            self::OWNER_CONTACT, self::OWNER_CONTACT_VIEW, self::OFFER_CREATE,
            self::OFFER_RECORD_OWNER, self::NEGOTIATION_UPDATE, self::NOTE_INTERNAL_WRITE,
            self::NOTE_INTERNAL_READ, self::MILESTONE_MARK, self::MESSAGE_SEND,
            self::DOCUMENT_UPLOAD, self::TRANSFER_START, self::TRANSFER_UPDATE,
            self::TRANSFER_CREDENTIAL_VIEW, self::VERIFICATION_RECORD, self::ESCALATE,
            self::BROKER_ASSIGN, self::REQUEST_VIEW_ALL,
        ],
        // Read-only staff: can look, cannot touch money or state.
        'admin_viewer' => [
            self::REQUEST_VIEW_ALL, self::PAYMENT_VIEW, self::REPORT_VIEW, self::AUDIT_VIEW,
        ],
        // Day-to-day operations: assignment, approvals, disputes — no refunds.
        'admin_manager' => [
            self::REQUEST_VIEW_ALL, self::REQUEST_VIEW_QUEUE, self::BROKER_ASSIGN,
            self::REQUEST_APPROVE, self::REQUEST_CANCEL_ANY, self::PAYMENT_VIEW,
            self::DISPUTE_RESOLVE, self::VERIFICATION_APPROVE, self::REPORT_VIEW,
            self::REPORT_EXPORT, self::AUDIT_VIEW, self::RISK_REVIEW, self::MESSAGE_SEND,
            self::NOTE_INTERNAL_READ, self::NOTE_INTERNAL_WRITE, self::DOCUMENT_UPLOAD,
            self::OWNER_CONTACT_VIEW, self::MILESTONE_MARK, self::TRANSFER_UPDATE,
        ],
        // Finance: money movement, no negotiation authority.
        'admin_finance' => [
            self::REQUEST_VIEW_ALL, self::PAYMENT_VIEW, self::PAYMENT_REFUND,
            self::PAYMENT_RELEASE, self::COMMISSION_VIEW, self::FEE_MANAGE,
            self::REPORT_VIEW, self::REPORT_EXPORT, self::AUDIT_VIEW, self::RISK_REVIEW,
        ],
        // Service owner: everything, including configuration and overrides.
        'admin_super' => [
            self::REQUEST_VIEW_ALL, self::REQUEST_VIEW_QUEUE, self::BROKER_ASSIGN,
            self::BROKER_MANAGE, self::REQUEST_APPROVE, self::REQUEST_CANCEL_ANY,
            self::STATUS_OVERRIDE, self::PAYMENT_VIEW, self::PAYMENT_REFUND,
            self::PAYMENT_RELEASE, self::COMMISSION_VIEW, self::FEE_MANAGE,
            self::SETTINGS_MANAGE, self::DISPUTE_RESOLVE, self::VERIFICATION_APPROVE,
            self::REPORT_VIEW, self::REPORT_EXPORT, self::AUDIT_VIEW, self::RISK_REVIEW,
            self::PII_VIEW, self::OWNER_CONTACT_VIEW, self::TRANSFER_CREDENTIAL_VIEW,
            self::NOTE_INTERNAL_READ, self::NOTE_INTERNAL_WRITE, self::MESSAGE_SEND,
            self::DOCUMENT_UPLOAD, self::MILESTONE_MARK, self::TRANSFER_START,
            self::TRANSFER_UPDATE, self::VERIFICATION_RECORD,
        ],
        // Cron / internal workflow. Explicitly minimal.
        'system' => [],
        'guest' => [],
    ];

    /** @var array|null role => [permission => bool] loaded from the database */
    protected static $cache;

    public static function flush()
    {
        self::$cache = null;
    }

    /** Every permission constant the module defines. */
    public static function allPermissions()
    {
        $out = [];
        $ref = new \ReflectionClass(__CLASS__);
        foreach ($ref->getConstants() as $name => $value) {
            if ($name === 'MATRIX' || !is_string($value)) {
                continue;
            }
            $out[] = $value;
        }
        sort($out);
        return array_values(array_unique($out));
    }

    public static function roles()
    {
        return array_keys(self::MATRIX);
    }

    /**
     * Grants for a role, preferring the database table when it is populated.
     *
     * @return string[]
     */
    public static function grants($role)
    {
        $role = (string) $role;
        if (self::$cache === null) {
            self::$cache = [];
            try {
                if (Db::tableExists('roles')) {
                    foreach (Db::fetch('roles') as $row) {
                        if ((int) $row['granted'] === 1) {
                            self::$cache[$row['role']][] = $row['permission'];
                        } elseif (!isset(self::$cache[$row['role']])) {
                            self::$cache[$row['role']] = [];
                        }
                    }
                }
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }
        if (array_key_exists($role, self::$cache)) {
            return self::$cache[$role];
        }
        return isset(self::MATRIX[$role]) ? self::MATRIX[$role] : [];
    }

    public static function allows(Actor $actor, $permission)
    {
        if (!$actor->isAuthenticated()) {
            return false;
        }
        // The system actor performs scheduled workflow steps. It is permitted
        // to drive automatic transitions but never anything a human must sign
        // off (refunds, verification approval, status overrides).
        if ($actor->isSystem()) {
            return in_array($permission, self::systemGrants(), true);
        }
        return in_array($permission, self::grants($actor->role), true);
    }

    /** @throws AuthorizationException */
    public static function assert(Actor $actor, $permission)
    {
        if (!self::allows($actor, $permission)) {
            throw new AuthorizationException(
                'You do not have permission to perform this action.',
                ['permission' => $permission, 'role' => $actor->role, 'actor' => $actor->identity()]
            );
        }
    }

    /**
     * Permissions the scheduled runner may exercise. Deliberately excludes
     * money movement and any human sign-off.
     */
    public static function systemGrants()
    {
        return [
            self::NEGOTIATION_UPDATE,
            self::MILESTONE_MARK,
            self::TRANSFER_UPDATE,
            self::RISK_REVIEW,
        ];
    }

    /**
     * Grant/revoke at runtime (admin UI). Writes to the database table so the
     * change is auditable and survives upgrades.
     */
    public static function setGrant($role, $permission, $granted)
    {
        if (!in_array($permission, self::allPermissions(), true)) {
            throw new ValidationException('Unknown permission.', ['permission' => 'Unknown permission.']);
        }
        if (!in_array($role, self::roles(), true)) {
            throw new ValidationException('Unknown role.', ['role' => 'Unknown role.']);
        }
        $now = Clock::now();
        $existing = Db::first('roles', ['role' => $role, 'permission' => $permission]);
        if ($existing) {
            Db::update('roles', ['granted' => $granted ? 1 : 0, 'updated_at' => $now], ['id' => $existing['id']]);
        } else {
            Db::insert('roles', [
                'role' => $role, 'permission' => $permission, 'granted' => $granted ? 1 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::flush();
    }

    /** Seed the shipped matrix (install / upgrade). Never overwrites edits. */
    public static function seedMatrix()
    {
        $now = Clock::now();
        foreach (self::MATRIX as $role => $permissions) {
            foreach (self::allPermissions() as $permission) {
                if (Db::count('roles', ['role' => $role, 'permission' => $permission]) > 0) {
                    continue;
                }
                Db::insert('roles', [
                    'role'       => $role,
                    'permission' => $permission,
                    'granted'    => in_array($permission, $permissions, true) ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        self::flush();
    }
}
