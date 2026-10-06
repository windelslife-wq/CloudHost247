<?php
/**
 * Reference valuation engine: transparent, deterministic, explainable.
 *
 * Every estimate is produced from named factors recorded in the breakdown,
 * so a customer can see exactly why a domain scored the way it did and an
 * operator can audit any historical estimate factor-by-factor.
 *
 * @package Chs\Providers\Valuation
 */

namespace Chs\Providers\Valuation;

use Chs\Core\DomainName;

class RuleBasedValuationEngine implements ValuationEngineInterface
{
    /** Curated commercial-intent terms (lowercase). */
    const COMMERCIAL_TERMS = [
        'shop', 'store', 'buy', 'pay', 'bank', 'loan', 'insurance', 'credit',
        'hotel', 'travel', 'flight', 'casino', 'bet', 'poker', 'forex', 'trade',
        'crypto', 'bitcoin', 'nft', 'ai', 'cloud', 'hosting', 'vpn', 'app',
        'software', 'data', 'tech', 'health', 'doctor', 'dental', 'law', 'legal',
        'realty', 'homes', 'property', 'car', 'auto', 'energy', 'solar',
        'jobs', 'career', 'freelance', 'agency', 'marketing', 'seo', 'news',
        'sport', 'game', 'music', 'movie', 'food', 'recipe', 'fashion',
        'gold', 'invest', 'money', 'cash', 'tax', 'wine', 'coffee',
    ];

    /** Relative value of holding this name under each extension. */
    const TLD_WEIGHTS = [
        'com' => 1.00, 'net' => 0.45, 'org' => 0.42, 'co' => 0.50,
        'io' => 0.52, 'ai' => 0.68, 'app' => 0.40, 'dev' => 0.35,
        'shop' => 0.30, 'store' => 0.30, 'online' => 0.22, 'site' => 0.20,
        'xyz' => 0.22, 'tech' => 0.28, 'cloud' => 0.28, 'info' => 0.18,
        'biz' => 0.16, 'us' => 0.26, 'uk' => 0.30, 'co.uk' => 0.30,
        'de' => 0.30, 'fr' => 0.26, 'ca' => 0.28, 'in' => 0.24,
        'com.au' => 0.28, 'eu' => 0.24,
    ];

    const DEFAULT_TLD_WEIGHT = 0.15;

    /** Absolute floor and ceiling for any estimate, in USD minor units. */
    const FLOOR_MINOR = 500;        // $5
    const CEILING_MINOR = 80000000; // $800k

    public function engineId()
    {
        return 'rules';
    }

    public function engineVersion()
    {
        return '1.2';
    }

    public function evaluate(DomainName $domain, array $context = [])
    {
        $sld = $domain->sld();
        $len = $domain->labelLength();
        $breakdown = [];

        /* ------------------------------------------------ length & shape -- */
        $lengthScore = $this->lengthScore($len);
        $breakdown[] = $this->factor(
            'length', 'Length',
            $len . ' character' . ($len === 1 ? '' : 's') . ' — shorter names are scarcer and easier to type.',
            $lengthScore
        );

        $shapePenalty = 0;
        if ($domain->hasHyphen()) {
            $shapePenalty += 18;
        }
        if ($domain->hasDigit()) {
            $shapePenalty += 14;
        }
        $breakdown[] = $this->factor(
            'shape', 'Hyphens & digits',
            $shapePenalty === 0
                ? 'No hyphens or digits — cleaner to say, print and remember.'
                : 'Hyphens or digits reduce type-in traffic and spoken referral.',
            max(5, 100 - $shapePenalty * 4)
        );

        /* ---------------------------------------------------- brandability -- */
        $brand = $this->brandabilityScore($sld);
        $breakdown[] = $this->factor(
            'brandability', 'Brandability',
            $brand >= 70
                ? 'Pronounceable, vowel-balanced and distinctive — strong brand material.'
                : ($brand >= 40
                    ? 'Serviceable as a brand with some effort.'
                    : 'Hard to pronounce or recall as a brand name.'),
            $brand
        );

        /* ------------------------------------------------------- keywords -- */
        list($kwScore, $kwNote, $matched) = $this->keywordScore($sld);
        $breakdown[] = $this->factor('keywords', 'Commercial keywords', $kwNote, $kwScore);

        /* ------------------------------------------------------------- tld -- */
        $tldWeight = $this->tldWeight($domain->tld(), $context);
        $tldScore = (int) round($tldWeight * 100);
        $breakdown[] = $this->factor(
            'extension', 'Extension strength',
            '.' . $domain->tld() . ' carries ' . $tldScore . '/100 of the market weight of .com '
                . 'for resale and branding.',
            $tldScore
        );

        /* --------------------------------------------------------- composite -- */
        $scoreRaw = $lengthScore * 0.25
            + (100 - $shapePenalty * 4) * 0.10
            + $brand * 0.25
            + $kwScore * 0.25
            + $tldScore * 0.15;
        $score = (int) max(1, min(98, round($scoreRaw)));

        /* ---------------------------------------------------------- pricing -- */
        // Base value anchored on scarcity of the label itself.
        $base = 2500; // $25
        if ($len <= 4) {
            $base += 250000; // scarce 4-char territory
        } elseif ($len <= 6) {
            $base += 40000;
        } elseif ($len <= 8) {
            $base += 8000;
        }

        $value = $base;
        // Score multiplier: 0.5x at score 10 rising linearly to 8x at score 95.
        $multiplier = 0.5 + (($score - 10) / 85.0) * 7.5;
        $multiplier = max(0.5, min(8.0, $multiplier));
        $value = (int) round($value * $multiplier);
        // Extension weight applies to the whole.
        $value = (int) round($value * max(0.1, $tldWeight * 1.4));
        // Matched commercial terms add real buyer demand.
        if ($matched) {
            $value = (int) round($value * (1.6 + 0.2 * count($matched)));
        }
        if ($domain->hasHyphen()) {
            $value = (int) round($value * 0.6);
        }
        if ($domain->hasDigit()) {
            $value = (int) round($value * 0.7);
        }

        $value = (int) max(self::FLOOR_MINOR, min(self::CEILING_MINOR, $value));

        // Round to a presentable figure.
        $value = $this->roundPretty($value);

        $currency = isset($context['currency']) && $context['currency']
            ? $context['currency'] : 'USD';

        $confidence = $score >= 60 ? 'medium' : 'low';
        if ($matched && $score >= 55) {
            $confidence = 'medium';
        }

        return [
            'estimate_minor' => $value,
            'currency'       => $currency,
            'score'          => $score,
            'breakdown'      => $breakdown,
            'confidence'     => $confidence,
            'summary'        => $this->summary($domain, $score, $matched),
        ];
    }

    /* ------------------------------------------------------------ internals -- */

    protected function lengthScore($len)
    {
        if ($len <= 3) {
            return 98;
        }
        if ($len <= 5) {
            return 90;
        }
        if ($len <= 8) {
            return 72;
        }
        if ($len <= 12) {
            return 52;
        }
        if ($len <= 18) {
            return 30;
        }
        return 12;
    }

    protected function brandabilityScore($sld)
    {
        $letters = preg_replace('/[^a-z]/', '', $sld);
        if ($letters === '') {
            return 12;
        }
        $len = strlen($letters);
        $vowels = preg_match_all('/[aeiou]/', $letters);
        $ratio = $vowels / $len;

        $score = 50;
        // Vowel balance — pronounceable names sit near 0.35–0.55.
        if ($ratio >= 0.30 && $ratio <= 0.60) {
            $score += 25;
        } elseif ($ratio >= 0.20 && $ratio <= 0.70) {
            $score += 10;
        } else {
            $score -= 12;
        }
        // Consonant runs longer than 4 hurt pronunciation.
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/', $letters)) {
            $score -= 22;
        }
        // Repeated triple letters look odd.
        if (preg_match('/(.)\1\1/', $letters)) {
            $score -= 10;
        }
        // Common pattern bonus (cvc, cvcv).
        if (preg_match('/^([bcdfghjklmnpqrstvwxyz][aeiou])+[bcdfghjklmnpqrstvwxyz]?$/', $letters)) {
            $score += 14;
        }
        return (int) max(5, min(95, $score));
    }

    /** @return array{0:int,1:string,2:string[]} */
    protected function keywordScore($sld, array $extraTerms = [])
    {
        $terms = array_merge(self::COMMERCIAL_TERMS, array_map('strtolower', $extraTerms));
        $matched = [];
        foreach ($terms as $term) {
            if ($term !== '' && strpos($sld, $term) !== false && strlen($sld) <= strlen($term) + 8) {
                $matched[] = $term;
            }
        }
        if ($matched) {
            return [
                (int) min(92, 55 + 12 * count($matched)),
                'Contains commercial term' . (count($matched) > 1 ? 's' : '') . ' (' . implode(', ', $matched)
                    . ') buyers actively search for.',
                $matched,
            ];
        }
        return [
            28,
            'No high-intent commercial keywords detected.',
            [],
        ];
    }

    protected function tldWeight($tld, array $context)
    {
        if (isset($context['tld_weights']) && is_array($context['tld_weights'])
            && isset($context['tld_weights'][$tld])) {
            $w = (float) $context['tld_weights'][$tld];
            if ($w > 0 && $w <= 2) {
                return $w;
            }
        }
        return isset(self::TLD_WEIGHTS[$tld]) ? self::TLD_WEIGHTS[$tld] : self::DEFAULT_TLD_WEIGHT;
    }

    protected function factor($key, $label, $detail, $score)
    {
        $score = (int) max(1, min(99, $score));
        return [
            'key'       => $key,
            'label'     => $label,
            'detail'    => $detail,
            'score'     => $score,
            'direction' => $score >= 65 ? 'positive' : ($score >= 35 ? 'neutral' : 'negative'),
        ];
    }

    protected function roundPretty($minor)
    {
        if ($minor >= 10000000) { // ≥ $100k → nearest $1,000
            return (int) (round($minor / 100000) * 100000);
        }
        if ($minor >= 1000000) {  // ≥ $10k → nearest $100
            return (int) (round($minor / 10000) * 10000);
        }
        if ($minor >= 100000) {   // ≥ $1k → nearest $25
            return (int) (round($minor / 2500) * 2500);
        }
        return (int) (round($minor / 100) * 100); // nearest $1
    }

    protected function summary(DomainName $domain, $score, array $matched)
    {
        if ($score >= 80) {
            return $domain->fqdn() . ' shows strong resale characteristics'
                . ($matched ? ' and directly targets the ' . $matched[0] . ' market.' : '.');
        }
        if ($score >= 55) {
            return $domain->fqdn() . ' holds solid utility value for an end-user buyer'
                . ($matched ? ', especially in ' . $matched[0] . '.' : '.');
        }
        return $domain->fqdn() . ' is priced close to registration value — most of its '
            . 'worth would come from the project built on it.';
    }
}
