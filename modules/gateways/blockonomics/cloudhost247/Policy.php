<?php
/**
 * Pure decision layer for the CloudHost247 Blockonomics governance.
 *
 * Zero I/O, zero WHMCS dependencies — every rule in the spec that can be a
 * pure function lives here so it is unit-testable under php-wasm:
 *   - availability matrix (gateway ∧ currency)
 *   - amount classification (exact / under-slack / under / over)
 *   - status normalisation (Pending/Confirming/Paid/Failed/Expired/…)
 *   - expiry, confirmations, network validation and display naming
 *
 * Provider reality check: the existing integration supports btc / bch / usdt
 * and networks ethereum / sepolia — anything else must never be offered.
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

class PaymentUnavailableException extends \RuntimeException
{
}

class Policy
{
    /** Networks the existing integration actually supports (do not invent). */
    const NETWORKS = [
        'ethereum' => [
            'label'   => 'Ethereum',
            'display' => 'USDT — Ethereum',
            'is_test' => false,
        ],
        'sepolia' => [
            'label'   => 'Sepolia Test Network',
            'display' => 'USDT — Sepolia (Test Network)',
            'is_test' => true,
        ],
    ];

    /** Customer-facing statuses. */
    const ST_PENDING    = 'Pending';
    const ST_CONFIRMING = 'Confirming';
    const ST_PAID       = 'Paid';
    const ST_FAILED     = 'Failed';
    const ST_EXPIRED    = 'Expired';
    const ST_CANCELLED  = 'Cancelled';
    const ST_REFUNDED   = 'Refunded';

    /** Order rows use these numeric states inside blockonomics_orders.status. */
    const ORD_WAITING  = -1; // awaiting payment
    const ORD_EXPIRED  = -2; // expired without full payment (new, additive)
    const ORD_CANCELLED = -3;

    /** Amount classifications. */
    const AMT_EXACT       = 'exact';
    const AMT_UNDER_SLACK = 'under_within_slack';
    const AMT_UNDER       = 'underpaid';
    const AMT_OVER        = 'overpaid';
    const AMT_INVALID     = 'invalid';

    /* --------------------------------------------------- availability -- */

    /** Every currency the governance layer controls. */
    const CURRENCIES = ['btc', 'bch', 'usdt'];

    /**
     * Effective per-currency availability: master switch takes precedence,
     * then the individual currency flag (spec §15).
     *
     * @param array $state ['gateway_enabled'=>bool,'btc_enabled'=>bool,'bch_enabled'=>bool,'usdt_enabled'=>bool]
     * @return array ['btc'=>bool,'bch'=>bool,'usdt'=>bool]
     */
    public static function availabilityMatrix(array $state)
    {
        $master = !empty($state['gateway_enabled']);
        $matrix = [];
        foreach (self::CURRENCIES as $code) {
            $matrix[$code] = $master && !empty($state[$code . '_enabled']);
        }
        return $matrix;
    }

    /** Fail-closed matrix used when governance cannot be resolved at all. */
    public static function deniedMatrix()
    {
        return array_fill_keys(self::CURRENCIES, false);
    }

    /**
     * True when at least one currency is actually payable right now.
     *
     * The master switch alone is not enough to offer checkout: a gateway
     * that is "on" with every currency off must present as unavailable
     * rather than as an empty currency picker.
     */
    public static function anyCurrencyAvailable(array $state)
    {
        foreach (self::availabilityMatrix($state) as $allowed) {
            if ($allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Throw unless the currency may be offered. Callers convert the
     * exception into HTTP 403 / safe customer copy.
     *
     * @throws PaymentUnavailableException
     */
    public static function assertPaymentAllowed(array $state, $currency)
    {
        $currency = strtolower((string) $currency);
        $matrix = self::availabilityMatrix($state);
        if (!isset($matrix[$currency]) || !$matrix[$currency]) {
            throw new PaymentUnavailableException('This payment method is currently unavailable.');
        }
    }

    /* --------------------------------------------------------- amounts -- */

    /**
     * Classify a received amount against expectation using the configured
     * underpayment slack (spec §23). Returns enough data for crediting:
     * - $creditBits: how many bits the customer pays for (full expectation
     *   when within slack, otherwise the proportional received amount)
     * - $percentPaid: percentage of the expected value covered
     *
     * Never silently upgrades a true underpayment to a full payment —
     * under-slack is full-credit by *configured* policy, under/slack beyond
     * it stays proportional.
     */
    public static function classifyAmount($expectedBits, $received, $slackPercent)
    {
        $expectedBits = (int) $expectedBits;
        // keep int-ness: callbacks deliver integers (satoshi); floats survive
        $received = max(0, is_numeric($received) ? $received + 0 : 0);
        $slackPercent = max(0.0, min(100.0, (float) $slackPercent));

        if ($expectedBits <= 0) {
            return ['class' => self::AMT_INVALID, 'credit_bits' => 0, 'percent_paid' => 0.0];
        }

        $slack = $slackPercent / 100 * $expectedBits;

        if ($received >= $expectedBits - $slack && $received <= $expectedBits) {
            if (abs($received - $expectedBits) < 1e-9) {
                $class = self::AMT_EXACT;
            } else {
                $class = self::AMT_UNDER_SLACK;
            }
            return ['class' => $class, 'credit_bits' => $expectedBits, 'percent_paid' => 100.0];
        }
        if ($received > $expectedBits) {
            return [
                'class'        => self::AMT_OVER,
                'credit_bits'  => $received,
                'percent_paid' => $received / $expectedBits * 100,
            ];
        }
        // true underpayment: proportional credit, invoice remains partially paid
        return [
            'class'        => self::AMT_UNDER,
            'credit_bits'  => $received,
            'percent_paid' => $expectedBits > 0 ? $received / $expectedBits * 100 : 0.0,
        ];
    }

    /* ---------------------------------------------------------- status -- */

    /**
     * Normalise an order row into a customer-facing status name.
     *
     * @param int         $orderStatus   row status (-1 waiting, 0..n confirmations, negatives per ORD_*)
     * @param int         $required      configured confirmation requirement
     * @param bool        $expired       expiry flag computed by caller (time-based)
     * @param int|null    $bitsReceived  optional paid bits to distinguish failed
     */
    public static function mapStatus($orderStatus, $required, $expired = false, $bitsReceived = null)
    {
        $orderStatus = (int) $orderStatus;
        $required = max(0, (int) $required);

        if ($orderStatus === self::ORD_CANCELLED) {
            return self::ST_CANCELLED;
        }
        if ($orderStatus === self::ORD_EXPIRED) {
            return self::ST_EXPIRED;
        }
        if ($expired && $orderStatus < $required) {
            return ($bitsReceived !== null && $bitsReceived > 0) ? self::ST_FAILED : self::ST_EXPIRED;
        }
        if ($orderStatus >= $required) {
            return self::ST_PAID;
        }
        if ($orderStatus === self::ORD_WAITING) {
            return self::ST_PENDING;
        }
        return self::ST_CONFIRMING;
    }

    /**
     * Is a fresh order row past its payment window?
     *
     * @param int $timestamp  creation epoch
     * @param int $periodMin  configured time period in minutes
     * @param int $now        current epoch
     */
    public static function isExpired($timestamp, $periodMin, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $periodMin = max(1, (int) $periodMin);
        return $now > (int) $timestamp + $periodMin * 60;
    }

    /* -------------------------------------------------------- network -- */

    /** @return bool whitelist check — never offer unsupported networks */
    public static function isSupportedNetwork($network)
    {
        return isset(self::NETWORKS[(string) $network]);
    }

    /**
     * Customer display for the USDT network (spec §9): always label it.
     *
     * @throws \InvalidArgumentException for unknown networks
     */
    public static function networkDisplay($network)
    {
        $network = (string) $network;
        if (!self::isSupportedNetwork($network)) {
            throw new \InvalidArgumentException('Unsupported USDT network: ' . $network);
        }
        return self::NETWORKS[$network]['display'];
    }

    public static function networkIsTest($network)
    {
        $n = (string) $network;
        return isset(self::NETWORKS[$n]) ? (bool) self::NETWORKS[$n]['is_test'] : false;
    }

    /* ---------------------------------------------------- validation -- */

    /**
     * Validate + normalise the confirmation requirement: integer 0..2 as
     * supported by the existing gateway dropdown.
     */
    public static function normalizeConfirmations($value)
    {
        $v = (int) $value;
        if ($v < 0) {
            $v = 0;
        }
        if ($v > 2) {
            $v = 2;
        }
        return $v;
    }

    /**
     * Can USDT be enabled with this configuration? (spec §34)
     *
     * @return array{0:bool,1:string} ok + reason when not ok
     */
    public static function usdtReadiness(array $cfg)
    {
        $address = isset($cfg['usdt_address']) ? trim((string) $cfg['usdt_address']) : '';
        $network = isset($cfg['usdt_network']) ? (string) $cfg['usdt_network'] : '';
        $etherscan = isset($cfg['etherscan_api_key']) ? trim((string) $cfg['etherscan_api_key']) : '';

        if ($address === '' || !preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return [false, 'USDT receiving address is missing or not a valid 0x… address.'];
        }
        if (!self::isSupportedNetwork($network)) {
            return [false, 'USDT network is missing or not supported by the gateway.'];
        }
        if ($etherscan === '') {
            return [false, 'Etherscan API key is required for server-side USDT transaction verification.'];
        }
        return [true, ''];
    }

    /**
     * BTC readiness: API key must exist somewhere the resolver can reach.
     */
    public static function btcReadiness(array $cfg)
    {
        $key = isset($cfg['api_key']) ? trim((string) $cfg['api_key']) : '';
        if ($key === '') {
            return [false, 'Blockonomics API key is not configured.'];
        }
        return [true, ''];
    }

    /**
     * BCH readiness. Blockonomics serves BCH from the same account and the
     * same API key as BTC (bch.blockonomics.co), so the requirement is
     * identical — there is no separate BCH credential to invent.
     */
    public static function bchReadiness(array $cfg)
    {
        $key = isset($cfg['api_key']) ? trim((string) $cfg['api_key']) : '';
        if ($key === '') {
            return [false, 'Blockonomics API key is not configured.'];
        }
        return [true, ''];
    }

    /**
     * Readiness for any governed currency, dispatched by code.
     *
     * @return array{0:bool,1:string}
     */
    public static function currencyReadiness($currency, array $cfg)
    {
        switch (strtolower((string) $currency)) {
            case 'btc':
                return self::btcReadiness($cfg);
            case 'bch':
                return self::bchReadiness($cfg);
            case 'usdt':
                return self::usdtReadiness($cfg);
        }
        return [false, 'Unsupported currency.'];
    }

    /* ---------------------------------------------------- idempotency -- */

    /**
     * May this notification credit the invoice? Pure replay/duplicate guard
     * (spec §20/§22): caller supplies lookups it already performed.
     *
     * @param bool $transactionExists   txid already in tblaccounts for this gateway
     * @param bool $invoiceAlreadyPaid  invoice settled (partial credit only allowed via explicit path)
     * @param bool $orderTerminal       order row already in Paid/Expired terminal state
     */
    public static function creditAllowed($transactionExists, $invoiceAlreadyPaid, $orderTerminal)
    {
        return !$transactionExists && !$invoiceAlreadyPaid && !$orderTerminal;
    }
}
