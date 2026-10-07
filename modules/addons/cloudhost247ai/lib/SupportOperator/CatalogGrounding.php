<?php
/**
 * Price/plan grounding against the live WHMCS product catalog.
 *
 * The operator may ONLY repeat plan names and prices it can read from
 * visible tblproducts/tblpricing rows at answer time. Anything else —
 * hidden products, missing prices, specs that live outside the database —
 * returns null and the engine escalates instead of guessing.
 */

namespace Ch247Ai\SupportOperator;

use Ch247Ai\Core\Db;

class CatalogGrounding
{
    const STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had',
        'her', 'was', 'one', 'our', 'out', 'has', 'have', 'with', 'your', 'what',
        'how', 'much', 'does', 'cost', 'costs', 'price', 'pricing', 'per', 'month',
        'monthly', 'year', 'yearly', 'plan', 'plans', 'package', 'that', 'this',
        'any', 'get', 'buy', 'order', 'want', 'need', 'like', 'from', 'about',
        'there', 'here', 'please', 'tell', 'show', 'give', 'quote', 'cheap', 'fee',
        'rate', 'rates', 'expensive',
    ];

    /**
     * Answer a pricing question from the live catalog, or null when the
     * catalog cannot support an answer. Returns ['text','products'].
     */
    public static function answer($text)
    {
        $terms = self::terms($text);
        $products = self::visibleProducts();
        $hits = [];
        foreach ($products as $product) {
            $hay = strtolower($product['name'] . ' ' . $product['group_name']);
            $matched = 0;
            foreach ($terms as $term) {
                if (strpos($hay, $term) !== false) {
                    $matched++;
                }
            }
            // AND semantics: a product is cited only if it matches EVERY
            // significant term. A bare "how much?" (no terms) or a question
            // naming something the catalog does not carry answers nothing —
            // the engine escalates instead of quoting the wrong product.
            if (count($terms) > 0 && $matched === count($terms)) {
                $hits[] = ['product' => $product, 'matched' => $matched];
            }
        }
        if (!$hits) {
            return null;
        }
        $hits = array_slice($hits, 0, 3);
        $lines = [];
        $cited = [];
        foreach ($hits as $hit) {
            $price = self::formatPrice($hit['product']);
            if ($price === null) {
                continue;
            }
            $cited[] = $hit['product']['name'];
            $line = '• ' . $hit['product']['name'] . ' — ' . $price;
            if ($hit['product']['group_name'] !== '') {
                $line .= ' (' . $hit['product']['group_name'] . ')';
            }
            $lines[] = $line;
        }
        if (!$lines) {
            return null;
        }
        $text = "Here is what the live CloudHost247 catalog shows right now:\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Prices and availability can change — the website checkout always has the final word. "
            . "Want me to connect you with Support for anything this doesn't cover?";
        return ['text' => $text, 'products' => $cited];
    }

    /** Significant lowercase terms (len>=3, stopwords removed). */
    public static function terms($text)
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($words as $word) {
            if (strlen($word) >= 3 && !in_array($word, self::STOPWORDS, true)) {
                $out[] = $word;
            }
        }
        return array_values(array_unique($out));
    }

    /** Visible (non-hidden) products with their default-currency pricing. */
    public static function visibleProducts()
    {
        try {
            $rows = Db::query(
                "SELECT p.id, p.name, COALESCE(g.name, '') AS group_name,"
                . " COALESCE(pr.monthly, -1) AS monthly, COALESCE(pr.annually, -1) AS annually,"
                . ' 0 AS setupfee, COALESCE(pr.currency, 0) AS currency'
                . ' FROM tblproducts p'
                . ' LEFT JOIN tblproductgroups g ON g.id = p.gid'
                . ' LEFT JOIN tblpricing pr ON pr.type = ? AND pr.relid = p.id AND pr.currency = (SELECT MIN(currency) FROM tblpricing WHERE type = ? AND relid = p.id)'
                . ' WHERE p.hidden = ? OR p.hidden IS NULL'
                . ' ORDER BY p.id ASC',
                ['product', 'product', 0]
            );
        } catch (\Exception $e) {
            return [];
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    private static function formatPrice(array $product)
    {
        $parts = [];
        if ((float) $product['monthly'] >= 0) {
            $parts[] = self::money($product['monthly']) . '/mo';
        }
        if ((float) $product['annually'] >= 0) {
            $parts[] = self::money($product['annually']) . '/yr';
        }
        if (!$parts) {
            return null;
        }
        $label = implode(' or ', $parts);
        if ((float) $product['setupfee'] > 0) {
            $label .= ' + ' . self::money($product['setupfee']) . ' setup';
        }
        return $label;
    }

    private static function money($amount)
    {
        return '$' . number_format((float) $amount, 2);
    }
}
