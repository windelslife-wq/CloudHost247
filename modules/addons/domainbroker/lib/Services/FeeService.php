<?php
/**
 * Domain Broker — brokerage fee engine.
 *
 * Nothing here is hardcoded. Every number comes from an administrator-managed
 * rule in domain_broker_fees: fixed amounts, percentages, progressive tiers,
 * minimum and maximum fees, currency-specific schedules, TLD scoping, value
 * bands, promotional discounts and tax. The first active rule whose scope
 * matches (lowest `priority` wins) is used, and the rule id plus the resolved
 * breakdown are snapshotted onto the offer at acceptance time so a later rule
 * change can never alter an agreed price.
 *
 * All arithmetic is in integer minor units.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\Db;
use DomainBroker\Core\DomainName;
use DomainBroker\Core\Money;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;

class FeeService
{
    const CALC_FIXED      = 'fixed';
    const CALC_PERCENTAGE = 'percentage';
    const CALC_TIERED     = 'tiered';

    /**
     * Resolve the rule that applies to an acquisition.
     *
     * @param int    $amountMinor agreed acquisition price
     * @param string $currency    ISO code
     * @param string $domain      used for TLD scoping
     * @param string $promoCode   optional promotional code
     * @return array|null
     */
    public function resolveRule($amountMinor, $currency, $domain = null, $promoCode = null)
    {
        $currency = strtoupper((string) $currency);
        $tld = $domain ? DomainName::tld($domain) : null;
        $now = Clock::now();

        $candidates = Db::fetch('fees', ['active' => 1, 'deleted_at' => null], [
            'order' => 'priority', 'dir' => 'asc', 'order2' => 'id', 'dir2' => 'asc',
        ]);

        $promoMatch = null;
        $generalMatch = null;

        foreach ($candidates as $rule) {
            if (!$this->ruleMatches($rule, $amountMinor, $currency, $tld, $now)) {
                continue;
            }
            $rulePromo = isset($rule['promo_code']) ? (string) $rule['promo_code'] : '';
            if ($rulePromo !== '') {
                if ($promoCode !== null && strcasecmp($rulePromo, (string) $promoCode) === 0 && $promoMatch === null) {
                    $promoMatch = $rule;
                }
                continue; // promo rules never apply without their code
            }
            if ($generalMatch === null) {
                $generalMatch = $rule;
            }
        }

        return $promoMatch ?: $generalMatch;
    }

    protected function ruleMatches(array $rule, $amountMinor, $currency, $tld, $now)
    {
        if (!empty($rule['currency']) && strtoupper($rule['currency']) !== $currency) {
            return false;
        }
        if (!empty($rule['valid_from']) && $rule['valid_from'] > $now) {
            return false;
        }
        if (!empty($rule['valid_to']) && $rule['valid_to'] < $now) {
            return false;
        }
        $min = (int) $rule['applies_min_minor'];
        $max = (int) $rule['applies_max_minor'];
        if ($min > 0 && $amountMinor < $min) {
            return false;
        }
        if ($max > 0 && $amountMinor > $max) {
            return false;
        }
        if (!empty($rule['tld_scope'])) {
            $scopes = array_filter(array_map(function ($s) {
                return strtolower(ltrim(trim($s), '.'));
            }, explode(',', $rule['tld_scope'])));
            if ($scopes && (!$tld || !in_array(strtolower($tld), $scopes, true))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compute the full cost breakdown for an acquisition price.
     *
     * @return array{
     *   acquisition_minor:int, fee_minor:int, discount_minor:int, taxable_minor:int,
     *   tax_minor:int, total_minor:int, currency:string, rule_id:int|null,
     *   rule_code:string|null, rule_name:string|null, calculation:string|null,
     *   use_whmcs_tax:bool, lines:array
     * }
     */
    public function quote($amountMinor, $currency, $domain = null, $promoCode = null, array $ruleOverride = null)
    {
        $amountMinor = max(0, (int) $amountMinor);
        $currency = strtoupper((string) $currency);
        if (!Money::isValidCurrency($currency)) {
            throw new ValidationException('A valid currency is required to quote fees.', ['currency' => 'Invalid currency.']);
        }

        $rule = $ruleOverride ?: $this->resolveRule($amountMinor, $currency, $domain, $promoCode);

        if (!$rule) {
            // No schedule configured for this band: no fee is charged. The
            // module never invents a price.
            return $this->emptyQuote($amountMinor, $currency);
        }

        $grossFee = $this->rawFee($rule, $amountMinor);

        // Promotional adjustment on the computed fee.
        $discount = 0;
        if ($rule['discount_percentage'] !== null && (float) $rule['discount_percentage'] > 0) {
            $discount += Money::percentOf($grossFee, (float) $rule['discount_percentage']);
        }
        if ((int) $rule['discount_fixed_minor'] > 0) {
            $discount += (int) $rule['discount_fixed_minor'];
        }
        $discount = min($discount, $grossFee);
        $fee = $grossFee - $discount;

        // Floor and ceiling are applied after the discount so a promotion can
        // never push the fee below the configured minimum.
        $fee = Money::clamp(
            $fee,
            (int) $rule['min_fee_minor'] > 0 ? (int) $rule['min_fee_minor'] : null,
            (int) $rule['max_fee_minor'] > 0 ? (int) $rule['max_fee_minor'] : null
        );

        $useWhmcsTax = !empty($rule['use_whmcs_tax']);
        $taxRate = $rule['tax_rate'] !== null ? (float) $rule['tax_rate'] : 0.0;
        $taxInclusive = !empty($rule['tax_inclusive']);

        $tax = 0;
        $taxable = $fee; // commission is the taxable element; the acquisition
                         // price itself is a pass-through to the registrant.
        if (!$useWhmcsTax && $taxRate > 0) {
            if ($taxInclusive) {
                // Fee already contains the tax: extract it.
                $tax = $fee - (int) round($fee / (1 + ($taxRate / 100)));
            } else {
                $tax = Money::percentOf($fee, $taxRate);
            }
        }

        $total = $amountMinor + $fee + ($taxInclusive ? 0 : $tax);

        return [
            'acquisition_minor' => $amountMinor,
            'gross_fee_minor'   => $grossFee,
            'discount_minor'    => $discount,
            'fee_minor'         => $fee,
            'taxable_minor'     => $taxable,
            'tax_minor'         => $tax,
            'tax_rate'          => $taxRate,
            'tax_inclusive'     => $taxInclusive,
            'total_minor'       => $total,
            'currency'          => $currency,
            'rule_id'           => (int) $rule['id'],
            'rule_code'         => $rule['code'],
            'rule_name'         => $rule['name'],
            'calculation'       => $rule['calculation'],
            'use_whmcs_tax'     => $useWhmcsTax,
            'lines'             => $this->lines($amountMinor, $fee, $tax, $currency, $rule, $taxInclusive),
        ];
    }

    protected function emptyQuote($amountMinor, $currency)
    {
        return [
            'acquisition_minor' => $amountMinor,
            'gross_fee_minor'   => 0,
            'discount_minor'    => 0,
            'fee_minor'         => 0,
            'taxable_minor'     => 0,
            'tax_minor'         => 0,
            'tax_rate'          => 0.0,
            'tax_inclusive'     => false,
            'total_minor'       => $amountMinor,
            'currency'          => $currency,
            'rule_id'           => null,
            'rule_code'         => null,
            'rule_name'         => null,
            'calculation'       => null,
            'use_whmcs_tax'     => true,
            'lines'             => [[
                'label' => 'Domain acquisition price',
                'amount_minor' => $amountMinor,
                'taxed' => false,
            ]],
        ];
    }

    protected function lines($amountMinor, $fee, $tax, $currency, array $rule, $taxInclusive)
    {
        $lines = [[
            'label' => 'Domain acquisition price',
            'amount_minor' => $amountMinor,
            'taxed' => false,
        ]];
        if ($fee > 0) {
            $lines[] = [
                'label' => $rule['name'] ?: 'Brokerage fee',
                'amount_minor' => $fee,
                'taxed' => !empty($rule['use_whmcs_tax']),
            ];
        }
        if ($tax > 0 && !$taxInclusive && empty($rule['use_whmcs_tax'])) {
            $lines[] = [
                'label' => 'Tax (' . rtrim(rtrim(number_format((float) $rule['tax_rate'], 2, '.', ''), '0'), '.') . '%)',
                'amount_minor' => $tax,
                'taxed' => false,
            ];
        }
        return $lines;
    }

    /** The fee before discount / min / max are applied. */
    protected function rawFee(array $rule, $amountMinor)
    {
        switch ($rule['calculation']) {
            case self::CALC_FIXED:
                return (int) $rule['fixed_minor'];

            case self::CALC_PERCENTAGE:
                $percentage = $rule['percentage'] !== null ? (float) $rule['percentage'] : 0.0;
                return Money::percentOf($amountMinor, $percentage) + (int) $rule['fixed_minor'];

            case self::CALC_TIERED:
                return $this->tieredFee($rule, $amountMinor);
        }
        return 0;
    }

    /**
     * Tiered fee.
     *
     * tiers JSON is {"mode":"marginal|flat","bands":[{"from_minor":0,
     * "to_minor":1000000,"percentage":"15","fixed_minor":0}, …]}.
     *
     * marginal (default) — each band's rate applies only to the slice of the
     * amount that falls inside it, the way income tax works.
     * flat — the single band containing the amount determines the whole fee.
     */
    protected function tieredFee(array $rule, $amountMinor)
    {
        $config = Str::jsonDecode($rule['tiers'], []);
        $bands = isset($config['bands']) && is_array($config['bands']) ? $config['bands'] : [];
        if (!$bands) {
            return (int) $rule['fixed_minor'];
        }
        $mode = isset($config['mode']) && $config['mode'] === 'flat' ? 'flat' : 'marginal';

        usort($bands, function ($a, $b) {
            return ((int) (isset($a['from_minor']) ? $a['from_minor'] : 0))
                <=> ((int) (isset($b['from_minor']) ? $b['from_minor'] : 0));
        });

        if ($mode === 'flat') {
            foreach ($bands as $band) {
                $from = (int) (isset($band['from_minor']) ? $band['from_minor'] : 0);
                $to = (int) (isset($band['to_minor']) ? $band['to_minor'] : 0);
                if ($amountMinor >= $from && ($to <= 0 || $amountMinor <= $to)) {
                    return Money::percentOf($amountMinor, isset($band['percentage']) ? (float) $band['percentage'] : 0)
                        + (int) (isset($band['fixed_minor']) ? $band['fixed_minor'] : 0);
                }
            }
            return (int) $rule['fixed_minor'];
        }

        $fee = (int) $rule['fixed_minor'];
        foreach ($bands as $band) {
            $from = (int) (isset($band['from_minor']) ? $band['from_minor'] : 0);
            $to = (int) (isset($band['to_minor']) ? $band['to_minor'] : 0);
            if ($amountMinor <= $from) {
                continue;
            }
            $upper = ($to > 0) ? min($amountMinor, $to) : $amountMinor;
            $slice = $upper - $from;
            if ($slice <= 0) {
                continue;
            }
            $fee += Money::percentOf($slice, isset($band['percentage']) ? (float) $band['percentage'] : 0);
            $fee += (int) (isset($band['fixed_minor']) ? $band['fixed_minor'] : 0);
        }
        return $fee;
    }

    /**
     * When the customer said their budget is inclusive of fees, the amount we
     * may offer the registrant is the largest acquisition price whose total
     * cost still fits inside the budget.
     */
    public function maxAcquisitionWithinBudget($budgetMinor, $currency, $domain = null, $promoCode = null)
    {
        $budgetMinor = max(0, (int) $budgetMinor);
        if ($budgetMinor === 0) {
            return 0;
        }
        // Monotonic in the acquisition price → binary search is exact and cheap.
        $low = 0;
        $high = $budgetMinor;
        $best = 0;
        for ($i = 0; $i < 48 && $low <= $high; $i++) {
            $mid = intdiv($low + $high, 2);
            $quote = $this->quote($mid, $currency, $domain, $promoCode);
            if ($quote['total_minor'] <= $budgetMinor) {
                $best = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        return $best;
    }

    /* ------------------------------------------------------- management -- */

    public function listRules(Actor $actor, $includeInactive = true)
    {
        Rbac::assert($actor, Rbac::REPORT_VIEW);
        $where = ['deleted_at' => null];
        if (!$includeInactive) {
            $where['active'] = 1;
        }
        return Db::fetch('fees', $where, ['order' => 'priority', 'order2' => 'id']);
    }

    public function findRule($id)
    {
        return Db::first('fees', ['id' => (int) $id, 'deleted_at' => null]);
    }

    public function createRule(Actor $actor, array $input)
    {
        Rbac::assert($actor, Rbac::FEE_MANAGE);
        $data = $this->validateRule($input);
        $now = Clock::now();

        if (Db::count('fees', ['code' => $data['code'], 'deleted_at' => null]) > 0) {
            throw new ValidationException('That rule code is already in use.', ['code' => 'Already in use.']);
        }

        $data['created_by'] = $actor->identity();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $id = Db::insert('fees', $data);

        Audit::record($actor, 'fee.rule.created', [
            'entity_type' => 'fee_rule', 'entity_id' => $id,
            'new' => $data, 'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $this->findRule($id);
    }

    public function updateRule(Actor $actor, $ruleId, array $input)
    {
        Rbac::assert($actor, Rbac::FEE_MANAGE);
        $rule = $this->findRule($ruleId);
        if (!$rule) {
            throw new NotFoundException('Fee rule not found.');
        }
        $data = $this->validateRule(array_merge($rule, $input), (int) $rule['id']);
        $data['updated_at'] = Clock::now();
        unset($data['created_at'], $data['created_by']);

        Db::update('fees', $data, ['id' => $rule['id']]);

        Audit::record($actor, 'fee.rule.updated', [
            'entity_type' => 'fee_rule', 'entity_id' => (int) $rule['id'],
            'previous' => $rule, 'new' => $data, 'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $this->findRule($rule['id']);
    }

    public function deleteRule(Actor $actor, $ruleId, $reason = '')
    {
        Rbac::assert($actor, Rbac::FEE_MANAGE);
        $rule = $this->findRule($ruleId);
        if (!$rule) {
            throw new NotFoundException('Fee rule not found.');
        }
        // Soft delete only: historical offers reference this rule id.
        Db::update('fees', [
            'active' => 0, 'deleted_at' => Clock::now(), 'updated_at' => Clock::now(),
        ], ['id' => $rule['id']]);

        Audit::record($actor, 'fee.rule.deleted', [
            'entity_type' => 'fee_rule', 'entity_id' => (int) $rule['id'],
            'previous' => $rule, 'reason' => $reason, 'visibility' => Audit::VIS_INTERNAL,
        ]);
        return true;
    }

    protected function validateRule(array $input, $existingId = null)
    {
        $v = \DomainBroker\Core\Validator::make($input)
            ->text('code', 60, true, 2)
            ->text('name', 190, true, 2)
            ->text('description', 1000, false)
            ->in('calculation', [self::CALC_FIXED, self::CALC_PERCENTAGE, self::CALC_TIERED], true)
            ->boolean('active', true)
            ->integer('priority', 0, 10000, false, 100);
        $data = $v->validate();

        $currency = isset($input['currency']) && $input['currency'] !== ''
            ? strtoupper(substr((string) $input['currency'], 0, 3)) : null;
        if ($currency !== null && !Money::isValidCurrency($currency)) {
            throw new ValidationException('Invalid currency for fee rule.', ['currency' => 'Invalid currency.']);
        }

        $percentage = isset($input['percentage']) && $input['percentage'] !== ''
            ? max(0, min(100, (float) $input['percentage'])) : null;

        if ($data['calculation'] === self::CALC_PERCENTAGE && ($percentage === null || $percentage <= 0)
            && (int) $this->intOr($input, 'fixed_minor') === 0) {
            throw new ValidationException(
                'A percentage rule needs a percentage above zero.',
                ['percentage' => 'Enter a percentage above zero.']
            );
        }

        $tiers = null;
        if ($data['calculation'] === self::CALC_TIERED) {
            $tiers = is_array($input['tiers'] ?? null)
                ? $input['tiers']
                : Str::jsonDecode($input['tiers'] ?? null, []);
            if (empty($tiers['bands'])) {
                throw new ValidationException(
                    'A tiered rule needs at least one band.',
                    ['tiers' => 'Define at least one band.']
                );
            }
            $tiers = Str::jsonEncode($tiers);
        }

        return [
            'code'        => Str::slug($data['code'], 60),
            'name'        => $data['name'],
            'description' => isset($data['description']) ? $data['description'] : null,
            'active'      => $data['active'] ? 1 : 0,
            'priority'    => $data['priority'],
            'currency'    => $currency,
            'applies_min_minor' => $this->intOr($input, 'applies_min_minor'),
            'applies_max_minor' => $this->intOr($input, 'applies_max_minor'),
            'tld_scope'   => isset($input['tld_scope']) && $input['tld_scope'] !== '' ? Str::clip($input['tld_scope'], 255) : null,
            'calculation' => $data['calculation'],
            'fixed_minor' => $this->intOr($input, 'fixed_minor'),
            'percentage'  => $percentage,
            'tiers'       => $tiers,
            'min_fee_minor' => $this->intOr($input, 'min_fee_minor'),
            'max_fee_minor' => $this->intOr($input, 'max_fee_minor'),
            'tax_rate'    => isset($input['tax_rate']) && $input['tax_rate'] !== '' ? max(0, (float) $input['tax_rate']) : null,
            'tax_inclusive' => !empty($input['tax_inclusive']) ? 1 : 0,
            'use_whmcs_tax' => !empty($input['use_whmcs_tax']) ? 1 : 0,
            'promo_code'  => isset($input['promo_code']) && $input['promo_code'] !== '' ? Str::clip($input['promo_code'], 60) : null,
            'discount_percentage' => isset($input['discount_percentage']) && $input['discount_percentage'] !== ''
                ? max(0, min(100, (float) $input['discount_percentage'])) : null,
            'discount_fixed_minor' => $this->intOr($input, 'discount_fixed_minor'),
            'valid_from'  => !empty($input['valid_from']) ? (string) $input['valid_from'] : null,
            'valid_to'    => !empty($input['valid_to']) ? (string) $input['valid_to'] : null,
        ];
    }

    protected function intOr(array $input, $key, $default = 0)
    {
        if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === null) {
            return $default;
        }
        return max(0, (int) $input[$key]);
    }
}
