<?php
/**
 * Domain Broker — domain intelligence endpoints.
 *
 * Only data the registry or registrar permits us to republish is returned;
 * see DomainIntelService for the policy that governs it.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Core\Actor;

class DomainsController extends BaseController
{
    public function check(Actor $actor, ApiRequest $http)
    {
        $domain = $this->requireString($http, 'domain');
        return ApiResponse::ok($this->domainService()->checkAvailability($domain));
    }

    public function lookup(Actor $actor, ApiRequest $http)
    {
        $domain = $this->requireString($http, 'domain');
        return ApiResponse::ok($this->domainService()->lookup($domain, [
            'audience' => \DomainBroker\Api\Presenter::audience($actor),
        ]));
    }
}
