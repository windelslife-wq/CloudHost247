<?php
/**
 * Domain Broker — transfer and ownership-verification endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Core\NotFoundException;

class TransfersController extends BaseController
{
    public function show(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        $transfer = $this->transferService()->forRequest($request['id']);

        return ApiResponse::ok([
            'transfer' => Presenter::transfer($transfer, $actor),
            'verification' => $this->verificationPayload($actor, $request),
        ]);
    }

    public function start(Actor $actor, ApiRequest $http)
    {
        $transfer = $this->transferService()->start($actor, $http->intParam('id'), $http->body);
        return ApiResponse::created(Presenter::transfer($transfer, $actor));
    }

    public function updateStatus(Actor $actor, ApiRequest $http)
    {
        $status = $this->requireString($http, 'status');
        $transfer = $this->transferService()->updateStatus($actor, $http->intParam('id'), $status, $http->body);
        return ApiResponse::ok(Presenter::transfer($transfer, $actor));
    }

    public function recordAuthCode(Actor $actor, ApiRequest $http)
    {
        $code = $this->requireString($http, 'auth_code', 'The authorisation code');
        $transfer = $this->transferService()->recordAuthCode($actor, $http->intParam('id'), $code);
        // The response carries the masked hint only — never the code itself.
        return ApiResponse::ok(Presenter::transfer($transfer, $actor));
    }

    public function complete(Actor $actor, ApiRequest $http)
    {
        $transfer = $this->transferService()->markCompleted($actor, $http->intParam('id'), $http->body);
        return ApiResponse::ok(Presenter::transfer($transfer, $actor));
    }

    public function completeAcquisition(Actor $actor, ApiRequest $http)
    {
        $request = $this->transferService()->completeAcquisition(
            $actor,
            $http->intParam('id'),
            (string) $http->input('note', '')
        );
        return ApiResponse::ok(Presenter::request($request, $actor));
    }

    public function verification(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        return ApiResponse::ok($this->verificationPayload($actor, $request));
    }

    public function submitEvidence(Actor $actor, ApiRequest $http)
    {
        $type = $this->requireString($http, 'type');
        $item = $this->verificationService()->submitEvidence($actor, $http->intParam('id'), $type, $http->body);
        unset($item['reference_enc']);
        return ApiResponse::ok($item);
    }

    public function approveVerification(Actor $actor, ApiRequest $http)
    {
        $id = $http->intParam('id');
        $decision = strtolower((string) $http->input('decision', 'approve'));

        if ($decision === 'reject') {
            $item = $this->verificationService()->reject($actor, $id, (string) $http->input('reason', ''));
        } elseif ($decision === 'waive') {
            $item = $this->verificationService()->waive($actor, $id, (string) $http->input('reason', ''));
        } else {
            $item = $this->verificationService()->approve($actor, $id, (string) $http->input('notes', ''));
        }
        if (!$item) {
            throw new NotFoundException('Verification item not found.');
        }
        unset($item['reference_enc']);
        return ApiResponse::ok($item);
    }

    protected function verificationPayload(Actor $actor, array $request)
    {
        $outstanding = $this->verificationService()->outstandingRequirements($request);
        if (Presenter::audience($actor) === 'customer') {
            return [
                'status' => $request['verification_status'],
                'outstanding_count' => count($outstanding),
                'complete' => $outstanding === [],
            ];
        }
        return [
            'status' => $request['verification_status'],
            'outstanding' => $outstanding,
            'complete' => $outstanding === [],
            'items' => array_map(function ($item) {
                unset($item['reference_enc']);
                return $item;
            }, $this->verificationService()->forRequest($request['id'])),
        ];
    }
}
