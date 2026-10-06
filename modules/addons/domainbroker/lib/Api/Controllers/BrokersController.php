<?php
/**
 * Domain Broker — broker roster endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;

class BrokersController extends BaseController
{
    public function index(Actor $actor, ApiRequest $http)
    {
        $rows = $this->brokerService()->listAll($actor, $http->query);
        $out = [];
        foreach ($rows as $row) {
            $out[] = Presenter::broker($row, $actor);
        }
        return ApiResponse::ok($out, ['count' => count($out)]);
    }

    public function store(Actor $actor, ApiRequest $http)
    {
        $broker = $this->brokerService()->create($actor, $http->body);
        return ApiResponse::created(Presenter::broker($broker, $actor));
    }

    public function update(Actor $actor, ApiRequest $http)
    {
        $broker = $this->brokerService()->update($actor, $http->intParam('id'), $http->body);
        return ApiResponse::ok(Presenter::broker($broker, $actor));
    }

    public function deactivate(Actor $actor, ApiRequest $http)
    {
        $reason = $this->requireString($http, 'reason');
        $broker = $this->brokerService()->deactivate($actor, $http->intParam('id'), $reason);
        return ApiResponse::ok(Presenter::broker($broker, $actor));
    }
}
