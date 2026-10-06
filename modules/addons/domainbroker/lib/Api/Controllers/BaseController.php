<?php
/**
 * Domain Broker — shared controller plumbing.
 *
 * Controllers are thin: they translate HTTP into a service call and a service
 * result back into a presented payload. All authorisation, validation and
 * state logic lives in the services, so the same rules apply whether a call
 * arrives through the API, the client area or the admin interface.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Core\Actor;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\AssignmentService;
use DomainBroker\Services\BrokerDirectoryService;
use DomainBroker\Services\DisputeService;
use DomainBroker\Services\DocumentService;
use DomainBroker\Services\DomainIntelService;
use DomainBroker\Services\FeeService;
use DomainBroker\Services\MessageService;
use DomainBroker\Services\NegotiationService;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\ReportService;
use DomainBroker\Services\RequestService;
use DomainBroker\Services\RiskService;
use DomainBroker\Services\TransferService;
use DomainBroker\Services\VerificationService;

abstract class BaseController
{
    /** @var array lazily constructed services */
    protected $services = [];

    protected function requestService()
    {
        return $this->service('requests', RequestService::class);
    }

    protected function assignmentService()
    {
        return $this->service('assignments', AssignmentService::class);
    }

    protected function negotiationService()
    {
        return $this->service('negotiation', NegotiationService::class);
    }

    protected function paymentService()
    {
        return $this->service('payments', PaymentService::class);
    }

    protected function transferService()
    {
        return $this->service('transfers', TransferService::class);
    }

    protected function verificationService()
    {
        return $this->service('verification', VerificationService::class);
    }

    protected function messageService()
    {
        return $this->service('messages', MessageService::class);
    }

    protected function documentService()
    {
        return $this->service('documents', DocumentService::class);
    }

    protected function disputeService()
    {
        return $this->service('disputes', DisputeService::class);
    }

    protected function brokerService()
    {
        return $this->service('brokers', BrokerDirectoryService::class);
    }

    protected function feeService()
    {
        return $this->service('fees', FeeService::class);
    }

    protected function reportService()
    {
        return $this->service('reports', ReportService::class);
    }

    protected function riskService()
    {
        return $this->service('risk', RiskService::class);
    }

    protected function domainService()
    {
        return $this->service('domains', DomainIntelService::class);
    }

    protected function service($key, $class)
    {
        if (!isset($this->services[$key])) {
            $this->services[$key] = new $class();
        }
        return $this->services[$key];
    }

    /**
     * Load a request the actor is allowed to see. Authorisation lives in the
     * service, so a controller cannot accidentally skip it.
     */
    protected function requestFor(Actor $actor, ApiRequest $http)
    {
        return $this->requestService()->findForActor($actor, $http->intParam('id'));
    }

    protected function requireString(ApiRequest $http, $field, $label = null)
    {
        $value = trim((string) $http->input($field, ''));
        if ($value === '') {
            throw new ValidationException(
                ($label ?: ucfirst(str_replace('_', ' ', $field))) . ' is required.',
                [$field => 'Required.']
            );
        }
        return $value;
    }

    /** Standard list filters, drawn from the query string. */
    protected function filters(ApiRequest $http, array $allowed)
    {
        $filters = [];
        foreach ($allowed as $key) {
            $value = $http->input($key, null);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        return array_merge($filters, $http->pagination());
    }
}
