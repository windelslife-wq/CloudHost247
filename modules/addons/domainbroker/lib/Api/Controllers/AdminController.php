<?php
/**
 * Domain Broker — fees, reporting, risk and audit endpoints.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Api\Controllers;

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Presenter;
use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\ValidationException;

class AdminController extends BaseController
{
    /* -------------------------------------------------------------- fees */

    public function fees(Actor $actor, ApiRequest $http)
    {
        return ApiResponse::ok($this->feeService()->listRules($actor, true));
    }

    public function createFee(Actor $actor, ApiRequest $http)
    {
        return ApiResponse::created($this->feeService()->createRule($actor, $http->body));
    }

    public function updateFee(Actor $actor, ApiRequest $http)
    {
        return ApiResponse::ok($this->feeService()->updateRule($actor, $http->intParam('id'), $http->body));
    }

    public function deleteFee(Actor $actor, ApiRequest $http)
    {
        $reason = $this->requireString($http, 'reason');
        $this->feeService()->deleteRule($actor, $http->intParam('id'), $reason);
        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * Quote the total cost of an acquisition. Available to customers so the
     * request form can show a live breakdown — it reads the same schedule the
     * invoice will use, so the figure quoted is the figure charged.
     */
    public function quote(Actor $actor, ApiRequest $http)
    {
        $currency = strtoupper((string) $http->input('currency', Settings::string('default_currency', 'USD')));
        $amount = (string) $http->input('amount', '');
        if ($amount === '' && $http->input('amount_minor', null) === null) {
            throw new ValidationException('An amount is required.', ['amount' => 'Required.']);
        }
        $minor = $http->input('amount_minor', null) !== null
            ? (int) $http->input('amount_minor')
            : Money::toMinor($amount, $currency);

        $quote = $this->feeService()->quote(
            $minor,
            $currency,
            (string) $http->input('domain', ''),
            (string) $http->input('promo_code', '') ?: null
        );

        // A customer is told what they will pay, not which internal rule said so.
        if (Presenter::audience($actor) === 'customer') {
            unset($quote['rule_id'], $quote['rule_code'], $quote['gross_fee_minor']);
        }
        $quote['formatted'] = [
            'acquisition' => Money::format($quote['acquisition_minor'], $currency),
            'fee' => Money::format($quote['fee_minor'], $currency),
            'tax' => Money::format($quote['tax_minor'], $currency),
            'total' => Money::format($quote['total_minor'], $currency),
        ];
        return ApiResponse::ok($quote);
    }

    /* --------------------------------------------------------- reporting */

    public function overview(Actor $actor, ApiRequest $http)
    {
        return ApiResponse::ok($this->reportService()->overview($actor, $http->query));
    }

    public function brokerReport(Actor $actor, ApiRequest $http)
    {
        return ApiResponse::ok($this->reportService()->brokerPerformance($actor, $http->query));
    }

    public function export(Actor $actor, ApiRequest $http)
    {
        $dataset = (string) $http->input('dataset', 'requests');
        $export = $this->reportService()->export($actor, $dataset, $http->query);
        return ApiResponse::download($export['body'], $export['filename'], $export['mime']);
    }

    /* -------------------------------------------------------------- risk */

    public function riskFlags(Actor $actor, ApiRequest $http)
    {
        $requestId = (int) $http->input('request_id', 0);
        if ($requestId > 0) {
            return ApiResponse::ok($this->riskService()->allFlags($requestId));
        }
        return ApiResponse::ok($this->riskService()->statistics($http->query));
    }

    public function reviewRisk(Actor $actor, ApiRequest $http)
    {
        $outcome = (string) $http->input('outcome', 'cleared');
        $this->riskService()->review($actor, $http->intParam('id'), $outcome, (string) $http->input('notes', ''));
        return ApiResponse::ok(['reviewed' => true, 'outcome' => $outcome]);
    }

    /* ------------------------------------------------------------- audit */

    public function audit(Actor $actor, ApiRequest $http)
    {
        Rbac::assert($actor, Rbac::AUDIT_VIEW);
        $requestId = $http->intParam('id');
        // Prove the caller may see the request before handing over its history.
        $this->requestService()->findForActor($actor, $requestId);

        $entries = array_map(
            ['\\DomainBroker\\Api\\Presenter', 'auditEntry'],
            Audit::forRequest($requestId)
        );

        return ApiResponse::ok($entries, [
            'chain' => Audit::verifyChain($requestId),
            'count' => count($entries),
        ]);
    }
}
