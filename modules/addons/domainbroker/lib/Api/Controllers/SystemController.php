<?php
/**
 * Domain Broker — service discovery and the caller's own identity.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Core\Actor;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Workflow\RequestStatus;

class SystemController extends BaseController
{
    const API_VERSION = '1.0';

    public function ping(Actor $actor, ApiRequest $http)
    {
        return ApiResponse::ok([
            'service' => 'domain-broker',
            'version' => self::API_VERSION,
            'time' => Clock::now(),
        ]);
    }

    public function me(Actor $actor, ApiRequest $http)
    {
        $permissions = Rbac::grants($actor->role);
        sort($permissions);

        return ApiResponse::ok([
            'type' => $actor->type,
            'role' => $actor->role,
            'name' => $actor->name,
            'client_id' => $actor->clientId ?: null,
            'broker_id' => $actor->brokerId ?: null,
            'admin_id' => $actor->adminId ?: null,
            'auth_method' => $actor->authMethod,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Public-facing configuration a UI needs: currencies, limits, statuses.
     * Deliberately contains no credentials and no internal thresholds.
     */
    public function settings(Actor $actor, ApiRequest $http)
    {
        $statuses = [];
        foreach (RequestStatus::all() as $status) {
            $statuses[$status] = [
                'label' => RequestStatus::label($status),
                'tone' => RequestStatus::tone($status),
                'terminal' => RequestStatus::isTerminal($status),
            ];
        }

        return ApiResponse::ok([
            'currencies' => $this->requestService()->allowedCurrencies($actor->clientId ?: null),
            'default_currency' => Settings::string('default_currency', 'USD'),
            'minimum_budget' => Settings::int('minimum_budget_minor', 0),
            'maximum_budget' => Settings::int('maximum_budget_minor', 0),
            'offer_validity_hours' => Settings::int('offer_validity_hours', 72),
            'request_expiry_days' => Settings::int('request_expiry_days', 90),
            'document_max_bytes' => Settings::int('document_max_bytes', 15728640),
            'statuses' => $statuses,
        ]);
    }
}
