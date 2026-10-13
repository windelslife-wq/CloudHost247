<?php
/**
 * SMM Panel Integration - Order quantity resolution (finding M-5)
 *
 * The provider order quantity determines what the reseller pays the SMM
 * panel, so it must be derived from what the client PAID for (the product
 * config options). Client-editable custom fields are only honoured as a
 * fallback, and only when the service publishes a positive maximum
 * (smm_max) - and in that case the quantity is still clamped to the paid
 * quantity. The paid-quantity cap is applied last, so even when
 * smm_max = 0 (provider "unlimited") a client can never inflate the order
 * beyond what they paid for.
 *
 * Pure class: no DB or WHMCS dependencies, so it is exercised offline.
 */

namespace SmmAddon;

class OrderQuantity
{
    /**
     * Resolve the provider order quantity.
     *
     * @param int $paidQuantity   Quantity derived from the paid product config options (0 = none found).
     * @param int $clientQuantity Quantity from a client-editable custom field (legacy source).
     * @param int $smmMin         Provider/service minimum.
     * @param int $smmMax         Provider/service maximum (0 = provider reports unlimited).
     *
     * @return array{quantity: int, source: string, refused: bool, reason: string}
     */
    public static function resolve(int $paidQuantity, int $clientQuantity, int $smmMin, int $smmMax): array
    {
        if ($paidQuantity >= 1) {
            $quantity = $paidQuantity;
            $source = 'config_option';
        } elseif ($clientQuantity >= 1) {
            // Client-editable fallback: only accepted when a positive cap
            // exists; the clamps below still apply.
            if ($smmMax > 0) {
                $quantity = $clientQuantity;
                $source = 'custom_field';
            } else {
                return [
                    'quantity' => 0,
                    'source' => '',
                    'refused' => true,
                    'reason' => 'client-editable quantity with no paid config option and no smm_max cap',
                ];
            }
        } else {
            return [
                'quantity' => 0,
                'source' => '',
                'refused' => true,
                'reason' => 'no quantity available from paid config options or custom fields',
            ];
        }

        // Provider minimum floor, then provider maximum clamp.
        if ($smmMin > 0 && $quantity < $smmMin) {
            $quantity = $smmMin;
        }
        if ($smmMax > 0 && $quantity > $smmMax) {
            $quantity = $smmMax;
        }
        // Paid-quantity cap, applied last: the order must never exceed what
        // the client paid for, even when smm_max = 0 (M-5).
        if ($paidQuantity >= 1 && $quantity > $paidQuantity) {
            $quantity = $paidQuantity;
        }

        return ['quantity' => $quantity, 'source' => $source, 'refused' => false, 'reason' => ''];
    }

    /**
     * Extract a numeric quantity from a config option value
     * (e.g. "1000", "1,000", "500 followers").
     */
    public static function parseOptionValue($value): int
    {
        if (!is_scalar($value)) {
            return 0;
        }
        if (preg_match('/\d[\d,]*/', (string) $value, $m)) {
            return (int) str_replace(',', '', $m[0]);
        }
        return 0;
    }

    /**
     * Whether a product config option name denotes an order quantity for
     * SMM-style products.
     */
    public static function isQuantityOptionName(string $name): bool
    {
        $name = strtolower(trim($name));
        return (bool) preg_match(
            '/quantity|count|amount|package|size|follow|like|view|share|subscriber|watch|download|hit|play|boost/',
            $name
        );
    }
}
