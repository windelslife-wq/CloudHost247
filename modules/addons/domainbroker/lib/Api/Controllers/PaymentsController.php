<?php
/**
 * Domain Broker — invoicing, escrow and refund endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\ValidationException;

class PaymentsController extends BaseController
{
    public function invoice(Actor $actor, ApiRequest $http)
    {
        $payment = $this->paymentService()->generateInvoice($actor, $http->intParam('id'), $http->body);
        return ApiResponse::created(Presenter::payment($payment, $actor));
    }

    public function show(Actor $actor, ApiRequest $http)
    {
        $request = $this->requestFor($actor, $http);
        $active = $this->paymentService()->activePayment($request['id']);

        return ApiResponse::ok([
            'payment' => Presenter::payment($active, $actor),
            'history' => Presenter::payments($this->paymentService()->historyFor($request['id']), $actor),
            'invoice_url' => $request['whmcs_invoice_id']
                ? \DomainBroker\Core\Http::clientUrl(['viewinvoice' => (int) $request['whmcs_invoice_id']])
                : null,
        ]);
    }

    public function sync(Actor $actor, ApiRequest $http)
    {
        // Reconciling with billing is a read of WHMCS' own state; the caller
        // must still be party to the request.
        $payment = $this->paymentService()->findOrFail($http->intParam('id'));
        $this->requestService()->findForActor($actor, $payment['request_id']);

        $synced = $this->paymentService()->syncWithBilling($actor, $payment['id']);
        return ApiResponse::ok(Presenter::payment($synced, $actor));
    }

    public function refund(Actor $actor, ApiRequest $http)
    {
        $reason = $this->requireString($http, 'reason');
        $payment = $this->paymentService()->findOrFail($http->intParam('id'));

        $amountMinor = null;
        if ($http->input('amount_minor', null) !== null && $http->input('amount_minor') !== '') {
            $amountMinor = (int) $http->input('amount_minor');
        } elseif ($http->input('amount', null) !== null && $http->input('amount') !== '') {
            $amountMinor = Money::toMinor((string) $http->input('amount'), $payment['currency']);
        }

        $refunded = $this->paymentService()->refund($actor, $payment['id'], $amountMinor, $reason, [
            'idempotency_key' => $http->idempotencyKey(),
        ]);
        return ApiResponse::ok(Presenter::payment($refunded, $actor));
    }

    public function release(Actor $actor, ApiRequest $http)
    {
        $released = $this->paymentService()->releaseFunds($actor, $http->intParam('id'), (string) $http->input('note', ''));
        return ApiResponse::ok(Presenter::payment($released, $actor));
    }

    public function custody(Actor $actor, ApiRequest $http)
    {
        $reference = $this->requireString($http, 'reference', 'An escrow provider reference');
        $confirmed = $this->paymentService()->confirmManualCustody(
            $actor,
            $http->intParam('id'),
            $reference,
            (string) $http->input('note', '')
        );
        return ApiResponse::ok(Presenter::payment($confirmed, $actor));
    }

    public function transactions(Actor $actor, ApiRequest $http)
    {
        if ($actor->isCustomer()) {
            $clientId = (int) $actor->clientId;
        } else {
            Rbac::assert($actor, Rbac::PAYMENT_VIEW);
            $clientId = (int) $http->input('client_id', 0);
            if ($clientId <= 0) {
                throw new ValidationException('Specify the client.', ['client_id' => 'Required.']);
            }
        }

        $rows = $this->paymentService()->transactionHistory($clientId, $http->query);
        return ApiResponse::ok(Presenter::payments($rows, $actor), ['count' => count($rows)]);
    }
}
