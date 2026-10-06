<?php
/**
 * Domain Broker — fraud and risk scoring.
 *
 * Runs a set of deterministic rules against a request and its history,
 * records every hit as a flag, and returns an aggregate score. Scores at or
 * above the configured review threshold force the request into manual review;
 * scores above the block threshold stop the workflow until a human clears it.
 *
 * The rules intentionally look at behaviour the module can observe itself
 * (value, velocity, payment failures, disputes, identity mismatches) rather
 * than guessing at third-party signals, and every threshold is configurable.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Integration\Gateway;
use DomainBroker\Workflow\RequestStatus;

class RiskService
{
    const RULE_HIGH_VALUE         = 'high_value_acquisition';
    const RULE_FAILED_PAYMENTS    = 'repeated_payment_failures';
    const RULE_RAPID_OFFERS       = 'rapid_repeated_offers';
    const RULE_RAPID_REQUESTS     = 'rapid_repeated_requests';
    const RULE_NEW_ACCOUNT        = 'new_account_high_value';
    const RULE_IDENTITY_MISMATCH  = 'identity_mismatch';
    const RULE_REPEATED_DISPUTES  = 'repeated_disputes';
    const RULE_UNUSUAL_TRANSFER   = 'unusual_transfer_behaviour';
    const RULE_CANCEL_VELOCITY    = 'cancellation_velocity';
    const RULE_CLIENT_INACTIVE    = 'client_account_not_active';

    const SEVERITY_SCORE = [
        'low' => 10,
        'medium' => 25,
        'high' => 40,
        'critical' => 60,
    ];

    /**
     * Evaluate a request and persist any new flags.
     *
     * @return array{score:int, level:string, flags:array, review:bool, block:bool}
     */
    public function evaluate(array $request, array $context = [])
    {
        $flags = [];

        $flags = array_merge($flags, $this->checkValue($request));
        $flags = array_merge($flags, $this->checkClientStanding($request));
        $flags = array_merge($flags, $this->checkRequestVelocity($request));
        $flags = array_merge($flags, $this->checkPaymentFailures($request));
        $flags = array_merge($flags, $this->checkOfferVelocity($request));
        $flags = array_merge($flags, $this->checkDisputeHistory($request));
        $flags = array_merge($flags, $this->checkTransferBehaviour($request));
        $flags = array_merge($flags, $this->checkIdentity($request, $context));

        $score = 0;
        foreach ($flags as $flag) {
            $score += (int) $flag['score'];
        }
        $score = min(100, $score);

        $reviewAt = Settings::int('risk_review_score', 50);
        $blockAt = Settings::int('risk_block_score', 80);

        $level = 'low';
        if ($score >= $blockAt) {
            $level = 'critical';
        } elseif ($score >= $reviewAt) {
            $level = 'high';
        } elseif ($score >= (int) round($reviewAt / 2)) {
            $level = 'medium';
        }

        foreach ($flags as $flag) {
            $this->persistFlag($request, $flag);
        }

        return [
            'score'  => $score,
            'level'  => $level,
            'flags'  => $flags,
            'review' => $score >= $reviewAt,
            'block'  => $score >= $blockAt,
        ];
    }

    /* ------------------------------------------------------------- rules */

    protected function checkValue(array $request)
    {
        $threshold = Settings::int('high_value_review_minor', 0);
        if ($threshold <= 0) {
            return [];
        }
        $amount = max((int) $request['budget_minor'], (int) $request['agreed_amount_minor']);
        if ($amount < $threshold) {
            return [];
        }
        $severity = $amount >= $threshold * 4 ? 'high' : 'medium';
        return [[
            'rule' => self::RULE_HIGH_VALUE,
            'severity' => $severity,
            'score' => self::SEVERITY_SCORE[$severity],
            'description' => 'Acquisition value of '
                . Money::toDecimalString($amount, $request['currency']) . ' ' . $request['currency']
                . ' exceeds the configured review threshold.',
            'evidence' => ['amount_minor' => $amount, 'threshold_minor' => $threshold],
        ]];
    }

    protected function checkClientStanding(array $request)
    {
        $client = Gateway::get()->getClient($request['client_id']);
        if (!$client) {
            return [];
        }
        $status = isset($client['status']) ? (string) $client['status'] : 'Active';
        if (strcasecmp($status, 'Active') === 0) {
            return [];
        }
        return [[
            'rule' => self::RULE_CLIENT_INACTIVE,
            'severity' => strcasecmp($status, 'Closed') === 0 ? 'critical' : 'high',
            'score' => strcasecmp($status, 'Closed') === 0 ? self::SEVERITY_SCORE['critical'] : self::SEVERITY_SCORE['high'],
            'description' => 'The WHMCS client account status is "' . $status . '".',
            'evidence' => ['client_status' => $status],
        ]];
    }

    protected function checkRequestVelocity(array $request)
    {
        $since = Clock::at(-86400);
        $recent = Db::count('requests', [
            'client_id' => (int) $request['client_id'],
            'created_at' => ['>=', $since],
        ]);
        if ($recent < 5) {
            return [];
        }
        $severity = $recent >= 10 ? 'high' : 'medium';
        return [[
            'rule' => self::RULE_RAPID_REQUESTS,
            'severity' => $severity,
            'score' => self::SEVERITY_SCORE[$severity],
            'description' => $recent . ' acquisition requests from this client in the last 24 hours.',
            'evidence' => ['count_24h' => $recent],
        ]];
    }

    protected function checkPaymentFailures(array $request)
    {
        $max = Settings::int('max_failed_payments', 3);
        $failures = (int) Db::scalar(
            'SELECT COALESCE(SUM(failed_attempts), 0) FROM ' . Db::quoteIdentifier(Db::table('payments'))
            . ' WHERE request_id = ?',
            [(int) $request['id']]
        );
        // Also consider failures across the client's other brokerage payments.
        $clientFailures = (int) Db::scalar(
            'SELECT COALESCE(SUM(p.failed_attempts), 0) FROM ' . Db::quoteIdentifier(Db::table('payments')) . ' p '
            . 'INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = p.request_id '
            . 'WHERE r.client_id = ?',
            [(int) $request['client_id']]
        );
        $worst = max($failures, $clientFailures);
        if ($worst < $max) {
            return [];
        }
        $severity = $worst >= $max * 2 ? 'high' : 'medium';
        return [[
            'rule' => self::RULE_FAILED_PAYMENTS,
            'severity' => $severity,
            'score' => self::SEVERITY_SCORE[$severity],
            'description' => $worst . ' failed payment attempts recorded (threshold ' . $max . ').',
            'evidence' => ['request_failures' => $failures, 'client_failures' => $clientFailures],
        ]];
    }

    protected function checkOfferVelocity(array $request)
    {
        $window = Settings::int('rapid_offer_seconds', 30);
        $threshold = Settings::int('rapid_offer_threshold', 5);
        $since = Clock::at(-$window);

        $count = Db::count('offers', [
            'request_id' => (int) $request['id'],
            'created_at' => ['>=', $since],
        ]);
        if ($count < $threshold) {
            return [];
        }
        return [[
            'rule' => self::RULE_RAPID_OFFERS,
            'severity' => 'high',
            'score' => self::SEVERITY_SCORE['high'],
            'description' => $count . ' offers created within ' . $window . ' seconds.',
            'evidence' => ['count' => $count, 'window_seconds' => $window],
        ]];
    }

    protected function checkDisputeHistory(array $request)
    {
        $disputes = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . Db::quoteIdentifier(Db::table('disputes')) . ' d '
            . 'INNER JOIN ' . Db::quoteIdentifier(Db::table('requests')) . ' r ON r.id = d.request_id '
            . 'WHERE r.client_id = ?',
            [(int) $request['client_id']]
        );
        if ($disputes < 2) {
            return [];
        }
        $severity = $disputes >= 4 ? 'high' : 'medium';
        return [[
            'rule' => self::RULE_REPEATED_DISPUTES,
            'severity' => $severity,
            'score' => self::SEVERITY_SCORE[$severity],
            'description' => $disputes . ' disputes raised by this client across brokerage requests.',
            'evidence' => ['dispute_count' => $disputes],
        ]];
    }

    protected function checkTransferBehaviour(array $request)
    {
        $transfer = Db::first('transfers', ['request_id' => (int) $request['id']], ['order' => 'id', 'dir' => 'desc']);
        if (!$transfer) {
            return [];
        }
        if ((int) $transfer['attempts'] < 3) {
            return [];
        }
        return [[
            'rule' => self::RULE_UNUSUAL_TRANSFER,
            'severity' => 'medium',
            'score' => self::SEVERITY_SCORE['medium'],
            'description' => 'The domain transfer has been attempted ' . (int) $transfer['attempts'] . ' times.',
            'evidence' => ['attempts' => (int) $transfer['attempts'], 'status' => $transfer['status']],
        ]];
    }

    /**
     * Identity mismatch: the account country and the billing country of the
     * paying client disagree, or the submitting IP country differs from the
     * registered country when the operator supplies that data.
     */
    protected function checkIdentity(array $request, array $context)
    {
        $client = Gateway::get()->getClient($request['client_id']);
        if (!$client) {
            return [];
        }
        $flags = [];

        $accountCountry = isset($client['country']) ? strtoupper((string) $client['country']) : '';
        $paymentCountry = isset($context['payment_country']) ? strtoupper((string) $context['payment_country']) : '';
        if ($accountCountry !== '' && $paymentCountry !== '' && $accountCountry !== $paymentCountry) {
            $flags[] = [
                'rule' => self::RULE_IDENTITY_MISMATCH,
                'severity' => 'medium',
                'score' => self::SEVERITY_SCORE['medium'],
                'description' => 'Payment country (' . $paymentCountry . ') differs from the account country ('
                    . $accountCountry . ').',
                'evidence' => ['account_country' => $accountCountry, 'payment_country' => $paymentCountry],
            ];
        }

        // A brand new account placing a very large request.
        $created = isset($client['datecreated']) ? Clock::toTimestamp($client['datecreated']) : null;
        $threshold = Settings::int('high_value_review_minor', 0);
        if ($created !== null && $threshold > 0
            && (Clock::timestamp() - $created) < 7 * 86400
            && (int) $request['budget_minor'] >= $threshold) {
            $flags[] = [
                'rule' => self::RULE_NEW_ACCOUNT,
                'severity' => 'high',
                'score' => self::SEVERITY_SCORE['high'],
                'description' => 'High-value request from an account created in the last 7 days.',
                'evidence' => ['account_age_days' => (int) floor((Clock::timestamp() - $created) / 86400)],
            ];
        }

        return $flags;
    }

    /* -------------------------------------------------------- persistence */

    protected function persistFlag(array $request, array $flag)
    {
        // One open flag per rule per request: re-evaluating does not spam.
        $existing = Db::first('risk', [
            'request_id' => (int) $request['id'],
            'rule_code' => $flag['rule'],
            'status' => 'open',
        ]);
        if ($existing) {
            Db::update('risk', [
                'score' => (int) $flag['score'],
                'severity' => $flag['severity'],
                'description' => Str::clip($flag['description'], 1000),
                'evidence' => Str::jsonEncode($flag['evidence']),
                'updated_at' => Clock::now(),
            ], ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        return Db::insert('risk', [
            'request_id' => (int) $request['id'],
            'client_id' => (int) $request['client_id'],
            'rule_code' => $flag['rule'],
            'severity' => $flag['severity'],
            'score' => (int) $flag['score'],
            'description' => Str::clip($flag['description'], 1000),
            'evidence' => Str::jsonEncode($flag['evidence']),
            'status' => 'open',
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);
    }

    public function openFlags($requestId)
    {
        return Db::fetch('risk', ['request_id' => (int) $requestId, 'status' => 'open'], ['order' => 'id']);
    }

    public function allFlags($requestId)
    {
        return Db::fetch('risk', ['request_id' => (int) $requestId], ['order' => 'id']);
    }

    /** Clear or confirm a flag after human review. */
    public function review(Actor $actor, $flagId, $outcome, $notes = '')
    {
        Rbac::assert($actor, Rbac::RISK_REVIEW);
        $outcome = in_array($outcome, ['cleared', 'confirmed'], true) ? $outcome : 'cleared';
        $flag = Db::first('risk', ['id' => (int) $flagId]);
        if (!$flag) {
            throw new \DomainBroker\Core\NotFoundException('Risk flag not found.');
        }

        Db::update('risk', [
            'status' => $outcome,
            'reviewed_by_type' => $actor->type,
            'reviewed_by_id' => (int) $actor->actorId(),
            'reviewed_at' => Clock::now(),
            'review_notes' => Str::clip($notes, 1000),
            'updated_at' => Clock::now(),
        ], ['id' => $flag['id']]);

        Audit::record($actor, 'risk.flag.' . $outcome, [
            'request_id' => (int) $flag['request_id'],
            'entity_type' => 'risk_flag',
            'entity_id' => (int) $flag['id'],
            'previous' => ['status' => $flag['status']],
            'new' => ['status' => $outcome],
            'reason' => $notes,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return true;
    }

    /** Lift manual review once no open flag remains. */
    public function clearManualReviewIfResolved(Actor $actor, array $request)
    {
        if (empty($request['manual_review'])) {
            return false;
        }
        if (Db::count('risk', ['request_id' => (int) $request['id'], 'status' => 'open']) > 0) {
            return false;
        }
        Db::update('requests', [
            'manual_review' => 0,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'risk.manual_review.cleared', [
            'request_id' => (int) $request['id'],
            'previous' => ['manual_review' => 1],
            'new' => ['manual_review' => 0],
            'visibility' => Audit::VIS_INTERNAL,
        ]);
        return true;
    }

    public function statistics(array $filters = [])
    {
        $where = [];
        if (!empty($filters['from'])) {
            $where['created_at'] = ['>=', $filters['from']];
        }
        return [
            'open'      => Db::count('risk', array_merge($where, ['status' => 'open'])),
            'confirmed' => Db::count('risk', array_merge($where, ['status' => 'confirmed'])),
            'cleared'   => Db::count('risk', array_merge($where, ['status' => 'cleared'])),
            'in_review' => Db::count('requests', ['manual_review' => 1, 'deleted_at' => null,
                'status' => ['notin', RequestStatus::TERMINAL]]),
        ];
    }
}
