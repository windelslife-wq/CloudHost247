<?php
/**
 * Domain Broker — dispute endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;

class DisputesController extends BaseController
{
    public function index(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        $rows = $this->disputeService()->forRequest($request['id']);
        $out = [];
        foreach ($rows as $row) {
            $out[] = Presenter::dispute($row, $actor);
        }
        return ApiResponse::ok($out);
    }

    public function store(Actor $actor, ApiRequest $http)
    {
        $dispute = $this->disputeService()->open($actor, $http->intParam('id'), $http->body);
        return ApiResponse::created(Presenter::dispute($dispute, $actor));
    }

    public function setStatus(Actor $actor, ApiRequest $http)
    {
        $status = $this->requireString($http, 'status');
        $dispute = $this->disputeService()->setStatus($actor, $http->intParam('id'), $status, (string) $http->input('note', ''));
        return ApiResponse::ok(Presenter::dispute($dispute, $actor));
    }

    public function resolve(Actor $actor, ApiRequest $http)
    {
        $dispute = $this->disputeService()->resolve($actor, $http->intParam('id'), $http->body);
        return ApiResponse::ok(Presenter::dispute($dispute, $actor));
    }

    public function reject(Actor $actor, ApiRequest $http)
    {
        $reason = $this->requireString($http, 'reason');
        $dispute = $this->disputeService()->reject($actor, $http->intParam('id'), $reason);
        return ApiResponse::ok(Presenter::dispute($dispute, $actor));
    }
}
