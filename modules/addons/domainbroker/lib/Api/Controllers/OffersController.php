<?php
/**
 * Domain Broker — negotiation endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\NotFoundException;

class OffersController extends BaseController
{
    public function index(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        $offers = $this->negotiationService()->offersFor($request['id'], Presenter::audience($actor));
        return ApiResponse::ok(Presenter::offers($offers, $actor), [
            'rounds' => (int) $request['negotiation_rounds'],
        ]);
    }

    public function store(Actor $actor, ApiRequest $http)
    {
        $offer = $this->negotiationService()->createOffer($actor, $http->intParam('id'), $http->body);
        return ApiResponse::created(Presenter::offer($offer, $actor));
    }

    public function show(Actor $actor, ApiRequest $http)
    {
        $offer = $this->loadOffer($actor, $http);
        return ApiResponse::ok(Presenter::offer($offer, $actor));
    }

    public function accept(Actor $actor, ApiRequest $http)
    {
        $offer = $this->negotiationService()->acceptOffer($actor, $http->intParam('id'), $http->body);
        return ApiResponse::ok(Presenter::offer($offer, $actor));
    }

    public function reject(Actor $actor, ApiRequest $http)
    {
        $offer = $this->negotiationService()->rejectOffer($actor, $http->intParam('id'), (string) $http->input('reason', ''));
        return ApiResponse::ok(Presenter::offer($offer, $actor));
    }

    public function counter(Actor $actor, ApiRequest $http)
    {
        $offer = $this->negotiationService()->counterOffer($actor, $http->intParam('id'), $http->body);
        return ApiResponse::created(Presenter::offer($offer, $actor));
    }

    public function withdraw(Actor $actor, ApiRequest $http)
    {
        $offer = $this->negotiationService()->withdrawOffer($actor, $http->intParam('id'), (string) $http->input('reason', ''));
        return ApiResponse::ok(Presenter::offer($offer, $actor));
    }

    public function ownerContact(Actor $actor, ApiRequest $http)
    {
        $negotiation = $this->negotiationService()->recordOwnerContact($actor, $http->intParam('id'), $http->body);
        return ApiResponse::created($negotiation);
    }

    public function ownerResponse(Actor $actor, ApiRequest $http)
    {
        $negotiation = $this->negotiationService()->recordOwnerResponse($actor, $http->intParam('id'), $http->body);
        return ApiResponse::ok($negotiation);
    }

    /** Load an offer and prove the caller may see the request behind it. */
    protected function loadOffer(Actor $actor, ApiRequest $http)
    {
        $offer = $this->negotiationService()->findOffer($http->intParam('id'));
        if (!$offer) {
            throw new NotFoundException('Offer not found.');
        }
        // findForActor throws for anyone who is not party to the request.
        $this->requestService()->findForActor($actor, $offer['request_id']);

        if ($actor->isCustomer() && $offer['direction'] !== \DomainBroker\Workflow\OfferStatus::DIR_TO_CUSTOMER) {
            // Offers the broker put to the registrant are not the client's to read.
            throw new AuthorizationException('That offer is not visible on your account.');
        }
        return $offer;
    }
}
