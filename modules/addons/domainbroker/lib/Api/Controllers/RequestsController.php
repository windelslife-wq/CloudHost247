<?php
/**
 * Domain Broker — acquisition request endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\DisputeService;

class RequestsController extends BaseController
{
    public function store(Actor $actor, ApiRequest $http)
    {
        $input = $http->body;
        // A customer may only ever file for themselves. Staff filing on behalf
        // of a client must say so explicitly and hold the permission.
        if ($actor->isCustomer()) {
            unset($input['client_id']);
        } elseif (!empty($input['client_id'])) {
            Rbac::assert($actor, Rbac::REQUEST_VIEW_ALL);
        }
        $input['source'] = 'api';

        $request = $this->requestService()->create($actor, $input);
        return ApiResponse::created(Presenter::request($this->requestService()->findRow($request['id']), $actor));
    }

    public function index(Actor $actor, ApiRequest $http)
    {
        $filters = $this->filters($http, [
            'status', 'payment_status', 'transfer_status', 'domain', 'search',
            'from', 'to', 'scope', 'client_id', 'broker_id', 'manual_review', 'sort', 'dir',
        ]);
        $rows = $this->requestService()->listForActor($actor, $filters);

        return ApiResponse::ok(Presenter::requests($rows, $actor), [
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'count' => count($rows),
        ]);
    }

    public function summary(Actor $actor, ApiRequest $http)
    {
        if ($actor->isCustomer()) {
            return ApiResponse::ok($this->requestService()->customerSummary($actor));
        }
        Rbac::assert($actor, Rbac::REPORT_VIEW);
        return ApiResponse::ok($this->reportService()->overview($actor, $http->query));
    }

    public function show(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        $audience = Presenter::audience($actor);

        $payload = Presenter::request($request, $actor);
        $payload['offers'] = Presenter::offers($this->negotiationService()->offersFor($request['id'], $audience), $actor);
        $payload['payment'] = Presenter::payment($this->paymentService()->activePayment($request['id']), $actor);
        $payload['transfer'] = Presenter::transfer($this->transferService()->forRequest($request['id']), $actor);
        $payload['documents'] = Presenter::documents($this->documentService()->listFor($actor, $request['id']));
        $payload['timeline'] = Presenter::timeline(Audit::timeline($request['id'], $audience));
        $payload['verification'] = $this->verificationSummary($actor, $request);

        if ($request['assigned_broker_id']) {
            $broker = $this->brokerService()->find($request['assigned_broker_id']);
            $payload['broker'] = $broker ? Presenter::broker($broker, $actor) : null;
        } else {
            $payload['broker'] = null;
        }

        if ($audience !== 'customer') {
            $payload['assignments'] = $this->assignmentService()->history($request['id']);
            $payload['risk_flags'] = $this->riskService()->allFlags($request['id']);
        }

        return ApiResponse::ok($payload);
    }

    public function update(Actor $actor, ApiRequest $http)
    {
        $updated = $this->requestService()->updateByCustomer($actor, $http->intParam('id'), $http->body);
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function cancel(Actor $actor, ApiRequest $http)
    {
        $id = $http->intParam('id');
        $reason = (string) $http->input('reason', '');

        if ($actor->isCustomer()) {
            $updated = $this->requestService()->cancelByCustomer($actor, $id, $reason);
        } else {
            if (trim($reason) === '') {
                throw new ValidationException('A reason is required.', ['reason' => 'Required.']);
            }
            $updated = $this->requestService()->cancelByAdmin($actor, $id, $reason);
        }
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function timeline(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        return ApiResponse::ok(Presenter::timeline(
            Audit::timeline($request['id'], Presenter::audience($actor))
        ));
    }

    public function assign(Actor $actor, ApiRequest $http)
    {
        $brokerId = (int) $http->input('broker_id', 0);
        if ($brokerId <= 0) {
            throw new ValidationException('Choose a broker.', ['broker_id' => 'Required.']);
        }
        $updated = $this->assignmentService()->assign($actor, $http->intParam('id'), $brokerId, [
            'reason' => (string) $http->input('reason', ''),
        ]);
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function claim(Actor $actor, ApiRequest $http)
    {
        $updated = $this->assignmentService()->claim($actor, $http->intParam('id'));
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function approve(Actor $actor, ApiRequest $http)
    {
        $updated = $this->requestService()->approve($actor, $http->intParam('id'), (string) $http->input('note', ''));
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function reject(Actor $actor, ApiRequest $http)
    {
        $reason = $this->requireString($http, 'reason');
        $updated = $this->requestService()->reject($actor, $http->intParam('id'), $reason);
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function override(Actor $actor, ApiRequest $http)
    {
        $status = $this->requireString($http, 'status');
        $reason = $this->requireString($http, 'reason');
        $updated = $this->requestService()->overrideStatus($actor, $http->intParam('id'), $status, $reason);
        return ApiResponse::ok(Presenter::request($updated, $actor));
    }

    public function escalate(Actor $actor, ApiRequest $http)
    {
        $summary = $this->requireString($http, 'summary');
        $dispute = (new DisputeService())->escalate($actor, $http->intParam('id'), $summary);
        return ApiResponse::created(Presenter::dispute($dispute, $actor));
    }

    protected function verificationSummary(Actor $actor, array $request)
    {
        if (Presenter::audience($actor) === 'customer') {
            return [
                'status' => $request['verification_status'],
                'outstanding' => count($this->verificationService()->outstandingRequirements($request)),
            ];
        }
        return [
            'status' => $request['verification_status'],
            'outstanding' => $this->verificationService()->outstandingRequirements($request),
            'items' => array_map(function ($item) {
                unset($item['reference_enc']);
                return $item;
            }, $this->verificationService()->forRequest($request['id'])),
        ];
    }
}
