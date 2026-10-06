<?php
/**
 * Domain Broker — per-request messaging endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Services\MessageService;

class MessagesController extends BaseController
{
    public function index(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        $threads = $this->messageService()->threadsFor($actor, $request['id']);

        $out = [];
        foreach ($threads as $name => $rows) {
            $out[$name] = Presenter::messages($rows);
        }

        return ApiResponse::ok($out, [
            'unread' => $this->messageService()->unreadCount($actor, $request['id']),
        ]);
    }

    public function store(Actor $actor, ApiRequest $http)
    {
        $input = $http->body;
        if (!isset($input['thread'])) {
            $input['thread'] = MessageService::THREAD_CUSTOMER;
        }
        $message = $this->messageService()->send($actor, $http->intParam('id'), $input);
        return ApiResponse::created(Presenter::message($message));
    }

    public function markRead(Actor $actor, ApiRequest $http)
    {
        $count = $this->messageService()->markThreadRead($actor, $http->intParam('id'));
        return ApiResponse::ok(['marked_read' => (int) $count]);
    }
}
