<?php
/**
 * Domain Broker — broker roster.
 *
 * Brokers are records an administrator creates and links to an existing WHMCS
 * staff account (or, for contracted brokers, a WHMCS client account). Nothing
 * is hardcoded: there is no seeded broker, no default credential and no
 * special-cased username anywhere in the module. Removing a broker is a soft
 * delete so their historical assignments and commissions stay intact.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Core\Validator;

class BrokerDirectoryService
{
    const STATUS_ACTIVE    = 'active';
    const STATUS_INACTIVE  = 'inactive';
    const STATUS_SUSPENDED = 'suspended';

    /** Roles a broker record may hold. */
    const ROLES = ['broker', 'broker_lead'];

    public function create(Actor $actor, array $input)
    {
        Rbac::assert($actor, Rbac::BROKER_MANAGE);

        $data = Validator::make($input)
            ->text('display_name', 190, true, 2)
            ->email('email', false)
            ->phone('phone', false)
            ->in('role', self::ROLES, false, 'broker')
            ->in('status', [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED], false, self::STATUS_ACTIVE)
            ->integer('whmcs_admin_id', 0, null, false)
            ->integer('whmcs_client_id', 0, null, false)
            ->integer('max_active_requests', 1, 500, false, 25)
            ->text('specialities', 500, false)
            ->text('biography', 2000, false)
            ->text('timezone', 64, false)
            ->text('languages', 190, false)
            ->validate();

        $adminId = isset($data['whmcs_admin_id']) && $data['whmcs_admin_id'] > 0 ? (int) $data['whmcs_admin_id'] : null;
        $clientId = isset($data['whmcs_client_id']) && $data['whmcs_client_id'] > 0 ? (int) $data['whmcs_client_id'] : null;

        if ($adminId === null && $clientId === null) {
            throw new \DomainBroker\Core\ValidationException(
                'A broker must be linked to a WHMCS staff account or client account.',
                ['whmcs_admin_id' => 'Link this broker to a WHMCS account.']
            );
        }
        if ($adminId !== null && Db::count('brokers', ['whmcs_admin_id' => $adminId, 'deleted_at' => null]) > 0) {
            throw new ConflictException('That staff account is already registered as a broker.');
        }
        if ($clientId !== null && Db::count('brokers', ['whmcs_client_id' => $clientId, 'deleted_at' => null]) > 0) {
            throw new ConflictException('That client account is already registered as a broker.');
        }

        $commission = isset($input['commission_percentage']) && $input['commission_percentage'] !== ''
            ? min(100, max(0, (float) $input['commission_percentage']))
            : null;

        $now = Clock::now();
        $id = Db::insert('brokers', [
            'reference'       => $this->uniqueReference(),
            'whmcs_admin_id'  => $adminId,
            'whmcs_client_id' => $clientId,
            'display_name'    => $data['display_name'],
            'email'           => isset($data['email']) ? $data['email'] : null,
            'phone'           => isset($data['phone']) ? $data['phone'] : null,
            'role'            => $data['role'],
            'status'          => $data['status'],
            'max_active_requests' => $data['max_active_requests'],
            'commission_percentage' => $commission,
            'timezone'        => isset($data['timezone']) && $data['timezone'] !== '' ? $data['timezone'] : null,
            'languages'       => isset($data['languages']) && $data['languages'] !== '' ? $data['languages'] : null,
            'specialities'    => isset($data['specialities']) ? $data['specialities'] : null,
            'biography'       => isset($data['biography']) ? $data['biography'] : null,
            'avatar_url'      => null,
            'completed_count' => 0,
            'active_count'    => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        Audit::record($actor, 'broker.created', [
            'entity_type' => 'broker',
            'entity_id'   => $id,
            'new'         => ['display_name' => $data['display_name'], 'role' => $data['role']],
            'visibility'  => Audit::VIS_INTERNAL,
        ]);

        return $this->find($id);
    }

    public function update(Actor $actor, $brokerId, array $input)
    {
        Rbac::assert($actor, Rbac::BROKER_MANAGE);
        $broker = $this->findOrFail($brokerId);

        $validator = Validator::make($input);
        $changes = [];

        if (array_key_exists('display_name', $input)) {
            $validator->text('display_name', 190, true, 2);
        }
        if (array_key_exists('email', $input)) {
            $validator->email('email', false);
        }
        if (array_key_exists('role', $input)) {
            $validator->in('role', self::ROLES, true);
        }
        if (array_key_exists('status', $input)) {
            $validator->in('status', [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED], true);
        }
        if (array_key_exists('max_active_requests', $input)) {
            $validator->integer('max_active_requests', 1, 500, true);
        }
        if (array_key_exists('specialities', $input)) {
            $validator->text('specialities', 500, false);
        }
        if (array_key_exists('biography', $input)) {
            $validator->text('biography', 2000, false);
        }
        $data = $validator->validate();

        foreach (['display_name', 'email', 'role', 'status', 'max_active_requests', 'specialities', 'biography'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (array_key_exists('commission_percentage', $input)) {
            $changes['commission_percentage'] = $input['commission_percentage'] === ''
                ? null : min(100, max(0, (float) $input['commission_percentage']));
        }

        if (!$changes) {
            return $broker;
        }
        $changes['updated_at'] = Clock::now();
        Db::update('brokers', $changes, ['id' => $broker['id']]);

        Audit::record($actor, 'broker.updated', [
            'entity_type' => 'broker',
            'entity_id'   => (int) $broker['id'],
            'previous'    => array_intersect_key($broker, $changes),
            'new'         => $changes,
            'visibility'  => Audit::VIS_INTERNAL,
        ]);

        return $this->find($broker['id']);
    }

    /** Soft delete: assignments, offers and commissions remain intact. */
    public function deactivate(Actor $actor, $brokerId, $reason = '')
    {
        Rbac::assert($actor, Rbac::BROKER_MANAGE);
        $broker = $this->findOrFail($brokerId);

        $open = Db::count('requests', [
            'assigned_broker_id' => (int) $broker['id'],
            'status' => ['notin', \DomainBroker\Workflow\RequestStatus::TERMINAL],
            'deleted_at' => null,
        ]);
        if ($open > 0) {
            throw new ConflictException(
                'This broker still has ' . $open . ' open request(s). Reassign them before deactivating.'
            );
        }

        Db::update('brokers', [
            'status' => self::STATUS_INACTIVE,
            'deleted_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => $broker['id']]);

        Audit::record($actor, 'broker.deactivated', [
            'entity_type' => 'broker',
            'entity_id'   => (int) $broker['id'],
            'reason'      => $reason,
            'visibility'  => Audit::VIS_INTERNAL,
        ]);

        return true;
    }

    public function find($brokerId)
    {
        return Db::first('brokers', ['id' => (int) $brokerId]);
    }

    public function findOrFail($brokerId)
    {
        $broker = $this->find($brokerId);
        if (!$broker || $broker['deleted_at'] !== null) {
            throw new NotFoundException('Broker not found.');
        }
        return $broker;
    }

    public function findByAdminId($adminId)
    {
        if (!$adminId) {
            return null;
        }
        return Db::first('brokers', ['whmcs_admin_id' => (int) $adminId, 'deleted_at' => null]);
    }

    public function findByClientId($clientId)
    {
        if (!$clientId) {
            return null;
        }
        return Db::first('brokers', ['whmcs_client_id' => (int) $clientId, 'deleted_at' => null]);
    }

    /** @return array[] */
    public function listAll(Actor $actor, array $filters = [])
    {
        Rbac::assert($actor, Rbac::REQUEST_VIEW_ALL);
        $where = ['deleted_at' => null];
        if (!empty($filters['status'])) {
            $where['status'] = $filters['status'];
        }
        if (!empty($filters['role'])) {
            $where['role'] = $filters['role'];
        }
        return Db::fetch('brokers', $where, ['order' => 'display_name']);
    }

    /** Active brokers available to take new work, least loaded first. */
    public function availableBrokers()
    {
        $brokers = Db::fetch('brokers', ['status' => self::STATUS_ACTIVE, 'deleted_at' => null]);
        foreach ($brokers as &$broker) {
            $broker['open_requests'] = $this->openRequestCount($broker['id']);
            $broker['has_capacity'] = $broker['open_requests'] < (int) $broker['max_active_requests'];
        }
        unset($broker);

        usort($brokers, function ($a, $b) {
            return $a['open_requests'] <=> $b['open_requests'];
        });

        return $brokers;
    }

    public function openRequestCount($brokerId)
    {
        return Db::count('requests', [
            'assigned_broker_id' => (int) $brokerId,
            'status' => ['notin', \DomainBroker\Workflow\RequestStatus::TERMINAL],
            'deleted_at' => null,
        ]);
    }

    /** Recompute the cached counters shown on the roster. */
    public function refreshCounters($brokerId)
    {
        $active = $this->openRequestCount($brokerId);
        $completed = Db::count('requests', [
            'assigned_broker_id' => (int) $brokerId,
            'status' => \DomainBroker\Workflow\RequestStatus::COMPLETED,
        ]);
        Db::update('brokers', [
            'active_count' => $active,
            'completed_count' => $completed,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $brokerId]);
    }

    /**
     * Public-facing broker card. Deliberately excludes email, phone and the
     * WHMCS linkage so an anonymous-brokerage customer sees a name and nothing
     * that would let them route around the broker.
     */
    public function publicProfile(array $broker)
    {
        return [
            'id'           => (int) $broker['id'],
            'reference'    => $broker['reference'],
            'display_name' => $broker['display_name'],
            'specialities' => $broker['specialities'],
            'biography'    => $broker['biography'],
            'languages'    => $broker['languages'],
            'completed'    => (int) $broker['completed_count'],
        ];
    }

    protected function uniqueReference()
    {
        for ($i = 0; $i < 20; $i++) {
            $ref = Str::reference('BRK', 6);
            if (Db::count('brokers', ['reference' => $ref]) === 0) {
                return $ref;
            }
        }
        throw new ConflictException('Unable to allocate a broker reference.');
    }
}
