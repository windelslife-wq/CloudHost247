<?php
/**
 * Presents GetProducts' configured per-currency matrix on HostX landing pages.
 * All arithmetic is delegated to PriceResolver; real WHMCS still owns checkout,
 * setup fees, tax, billing and invoices. Reference numbers never enter billing.
 */
declare(strict_types=1);
namespace CloudHost247\I18n\Whmcs;

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\I18n;
use CloudHost247\I18n\Money;

final class ProductPricePresenter
{
    const CYCLES = array('monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially');
    private $i18n;

    public function __construct(I18n $i18n)
    {
        $this->i18n = $i18n;
    }

    /** Return formatted display values, availability and the actual charge currency per cycle. */
    public function present(array $product, Currency $billing = null): array
    {
        $target = $this->i18n->preferences()->getCurrency();
        $formatter = $this->i18n->formatter();
        $matrix = isset($product['pricing']) && is_array($product['pricing']) ? $product['pricing'] : array();
        $raw = $formatted = $chargeIds = $details = array();
        // Formatter already includes symbols: never append a second prefix.
        $formatted['prefix'] = $formatted['suffix'] = '';
        foreach (self::CYCLES as $cycle) {
            $prices = array();
            foreach ($matrix as $code => $row) {
                if (is_array($row) && isset($row[$cycle])) {
                    $prices[$code] = $row[$cycle];
                }
            }
            $resolved = $this->i18n->prices()->resolve($prices, $target, $billing);
            $raw[$cycle] = $resolved->isAvailable() ? $resolved->getDisplayAmount() : -1;
            $formatted[$cycle] = '';
            $chargeIds[$cycle] = null;
            $details[$cycle] = $resolved->toArray();
            if (!$resolved->isAvailable()) {
                continue;
            }
            $formatted[$cycle] = htmlspecialchars($formatter->money($resolved->getMoney(), $target), ENT_QUOTES, 'UTF-8');
            $chargedCurrency = $this->i18n->currencies()->findByCode($resolved->getTransactionCurrency());
            if ($chargedCurrency !== null) {
                $chargeIds[$cycle] = $chargedCurrency->getId();
            }
            if ($resolved->isIndicative() && $chargedCurrency !== null && $resolved->getTransactionAmount() !== null) {
                $label = $this->i18n->trans('price_reference_only');
                $charge = $this->i18n->trans('price_charged_amount', array(
                    'amount' => $formatter->money($resolved->getTransactionAmount(), $chargedCurrency),
                    'code' => $chargedCurrency->getCode(),
                ));
                $formatted[$cycle] .= '<small class="ch247-price-note">' . $label . ' · ' . $charge . '</small>';
            }
        }
        if (isset($product['paytype']) && $product['paytype'] === 'free') {
            $raw['free'] = 0;
            $formatted['free'] = htmlspecialchars($formatter->money(Money::fromMajor(0, $target->getCode()), $target), ENT_QUOTES, 'UTF-8');
            $chargeIds['free'] = $target->getId();
        }
        $chargeIds['onetime'] = $chargeIds['monthly'];
        return array('raw' => $raw, 'formatted' => $formatted, 'charge_ids' => $chargeIds, 'details' => $details);
    }
}
