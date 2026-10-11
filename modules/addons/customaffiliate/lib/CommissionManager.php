<?php

/**
 * Custom Affiliate Commission Manager
 *
 * Core business logic for:
 * - Determining commission eligibility
 * - Calculating first vs recurring commissions
 * - Handling refunds and upgrades/downgrades
 * - Data persistence
 */

namespace CustomAffiliate;

use WHMCS\Database\Capsule;
use Exception;

class CommissionManager
{
    /** @var int Product group ID configured for web hosting */
    private $productGroupId;

    /** @var float First commission percentage */
    private $firstPercent;

    /** @var float Recurring commission percentage */
    private $recurringPercent;

    /** @var bool Debug logging enabled */
    private $debugLogging;

    /** @var array Cached module settings */
    private static $settings = null;

    /**
     * Constructor - load module settings
     */
    public function __construct()
    {
        $settings = $this->getModuleSettings();
        $this->productGroupId     = (int) ($settings['product_group_id'] ?? 0);
        $this->firstPercent       = (float) ($settings['first_commission_percent'] ?? 50);
        $this->recurringPercent   = (float) ($settings['recurring_commission_percent'] ?? 20);
        $this->debugLogging       = (bool) ($settings['enable_logging'] ?? true);
    }

    /**
     * Get module settings from database
     *
     * @return array
     */
    private function getModuleSettings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        try {
            $result = Capsule::table('tbladdonmodules')
                ->where('module', 'customaffiliate')
                ->pluck('value', 'setting');

            self::$settings = $result ? $result->toArray() : [];
        } catch (Exception $e) {
            self::$settings = [];
        }

        return self::$settings;
    }

    /**
     * Log debug message to WHMCS Activity Log if enabled
     *
     * @param string $message
     * @param array $data
     * @return void
     */
    public function logDebug($message, array $data = [])
    {
        if (!$this->debugLogging) {
            return;
        }

        $description = '[CustomAffiliate] ' . $message;
        if (!empty($data)) {
            $description .= ' | Data: ' . json_encode($data);
        }

        try {
            Capsule::table('tblactivitylog')->insert([
                'date'        => date('Y-m-d H:i:s'),
                'description' => $description,
                'user'        => 'System',
                'ipaddr'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Exception $e) {
            // Silently fail to avoid disrupting payment flow
        }
    }

    /**
     * Main entry point: Process invoice payment for affiliate commission
     *
     * @param int $invoiceId
     * @return bool|array Returns commission details or false if not applicable
     */
    public function processInvoicePaid($invoiceId)
    {
        $this->logDebug('Processing invoice payment', ['invoice_id' => $invoiceId]);

        // Validate basic requirements
        if (!$this->productGroupId) {
            $this->logDebug('Product group not configured - skipping');
            return false;
        }

        // Load invoice data
        $invoice = $this->getInvoiceData($invoiceId);
        if (!$invoice) {
            $this->logDebug('Invoice not found', ['invoice_id' => $invoiceId]);
            return false;
        }

        // Only process paid invoices
        if ($invoice['status'] !== 'Paid') {
            $this->logDebug('Invoice not paid - skipping', ['status' => $invoice['status']]);
            return false;
        }

        // Check if client has an affiliate referrer
        $affiliateId = $this->getClientAffiliate($invoice['userid']);
        if (!$affiliateId) {
            $this->logDebug('No affiliate referrer found', ['client_id' => $invoice['userid']]);
            return false;
        }

        $this->logDebug('Affiliate found', ['affiliate_id' => $affiliateId, 'client_id' => $invoice['userid']]);

        // Process each invoice item
        $commissions = [];
        foreach ($invoice['items'] as $item) {
            $commission = $this->processInvoiceItem($item, $invoice, $affiliateId);
            if ($commission) {
                $commissions[] = $commission;
            }
        }

        return !empty($commissions) ? $commissions : false;
    }

    /**
     * Process a single invoice item for commission
     *
     * @param array $item
     * @param array $invoice
     * @param int $affiliateId
     * @return array|false
     */
    private function processInvoiceItem(array $item, array $invoice, $affiliateId)
    {
        $serviceId = $this->extractServiceId($item);
        if (!$serviceId) {
            return false;
        }

        // Get service details
        $service = $this->getServiceData($serviceId);
        if (!$service) {
            $this->logDebug('Service not found', ['service_id' => $serviceId]);
            return false;
        }

        // Check if product is in the configured Web Hosting group
        $productGroupId = $this->getProductGroupId($service['packageid']);
        if ((int)$productGroupId !== (int)$this->productGroupId) {
            $this->logDebug('Product not in configured group - skipping', [
                'service_id' => $serviceId,
                'product_id' => $service['packageid'],
                'product_group' => $productGroupId,
                'configured_group' => $this->productGroupId,
            ]);
            return false;
        }

        // Determine if this is first payment or recurring
        $isFirstPayment = $this->isFirstPayment($serviceId, $affiliateId);
        $commissionPercent = $isFirstPayment ? $this->firstPercent : $this->recurringPercent;

        // Calculate commission amount
        $itemAmount = (float) $item['amount'];
        $commissionAmount = $this->calculateCommissionAmount($itemAmount, $commissionPercent);

        if ($commissionAmount <= 0) {
            $this->logDebug('Commission amount is zero - skipping', [
                'item_amount' => $itemAmount,
                'percent' => $commissionPercent,
            ]);
            return false;
        }

        $commissionType = $isFirstPayment ? 'first' : 'recurring';

        $this->logDebug('Commission calculated', [
            'service_id' => $serviceId,
            'type' => $commissionType,
            'percent' => $commissionPercent,
            'amount' => $commissionAmount,
            'item_amount' => $itemAmount,
        ]);

        return [
            'service_id'         => $serviceId,
            'affiliate_id'       => $affiliateId,
            'client_id'          => $invoice['userid'],
            'product_id'         => $service['packageid'],
            'invoice_id'         => $invoice['id'],
            'invoice_item_id'    => $item['id'],
            'commission_type'    => $commissionType,
            'commission_percent' => $commissionPercent,
            'commission_amount'  => $commissionAmount,
            'item_amount'        => $itemAmount,
            'is_first_payment'   => $isFirstPayment,
        ];
    }

    /**
     * Check if this is the first successful payment for this service/affiliate combo
     *
     * @param int $serviceId
     * @param int $affiliateId
     * @return bool True if first payment, false if recurring
     */
    public function isFirstPayment($serviceId, $affiliateId)
    {
        try {
            $record = Capsule::table('mod_customaffiliate_commissions')
                ->where('service_id', $serviceId)
                ->where('affiliate_id', $affiliateId)
                ->first();

            // If no record exists, this is the first payment
            if (!$record) {
                return true;
            }

            // If record exists but first commission not paid yet, this is first payment
            if (!$record->first_commission_paid) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->logDebug('Error checking first payment status', [
                'error' => $e->getMessage(),
                'service_id' => $serviceId,
                'affiliate_id' => $affiliateId,
            ]);
            // Conservative approach: assume first payment to avoid underpaying
            return true;
        }
    }

    /**
     * Record commission payment in custom table
     *
     * @param array $commissionData
     * @return bool
     */
    public function recordCommission(array $commissionData)
    {
        $serviceId   = $commissionData['service_id'];
        $affiliateId = $commissionData['affiliate_id'];

        try {
            $record = Capsule::table('mod_customaffiliate_commissions')
                ->where('service_id', $serviceId)
                ->where('affiliate_id', $affiliateId)
                ->first();

            if (!$record) {
                // Create new record
                Capsule::table('mod_customaffiliate_commissions')->insert([
                    'service_id'                => $serviceId,
                    'affiliate_id'              => $affiliateId,
                    'client_id'                 => $commissionData['client_id'],
                    'product_id'                => $commissionData['product_id'],
                    'first_commission_amount'   => $commissionData['commission_amount'],
                    'first_commission_paid'     => true,
                    'first_commission_invoice_id' => $commissionData['invoice_id'],
                    'first_commission_paid_at'  => date('Y-m-d H:i:s'),
                    'total_recurring_commission' => 0.00,
                    'recurring_count'           => 0,
                    'last_commission_at'        => date('Y-m-d H:i:s'),
                    'notes'                     => 'First commission recorded via InvoicePaid hook',
                ]);
            } else {
                // Update existing record for recurring
                $amount = round((float) $commissionData['commission_amount'], 2);
                Capsule::table('mod_customaffiliate_commissions')
                    ->where('id', $record->id)
                    ->update([
                        'total_recurring_commission' => round((float) $record->total_recurring_commission + $amount, 2),
                        'recurring_count'            => (int) $record->recurring_count + 1,
                        'last_commission_at'         => date('Y-m-d H:i:s'),
                        'notes'                      => $this->appendNote($record->notes, 'Recurring commission recorded: ' . number_format($amount, 2, '.', '')),
                    ]);
            }

            // Log to activity table
            $this->logCommissionActivity($commissionData);

            return true;
        } catch (Exception $e) {
            $this->logDebug('Error recording commission', [
                'error' => $e->getMessage(),
                'data'  => $commissionData,
            ]);
            return false;
        }
    }

    /**
     * Apply affiliate commission override during AffiliateCommission hook
     *
     * @param array $params WHMCS hook parameters
     * @return float|false New commission amount or false to skip
     */
    public function handleAffiliateCommissionHook(array $params)
    {
        $this->logDebug('AffiliateCommission hook triggered', $params);

        // Get required parameters
        $affiliateId = $params['affiliateid'] ?? 0;
        $clientId    = $params['clientid'] ?? 0;
        $serviceId   = $params['serviceid'] ?? 0;
        $productId   = $params['productid'] ?? 0;
        $amount      = $params['amount'] ?? 0;
        $invoiceId   = $params['invoiceid'] ?? 0;

        if (!$serviceId || !$affiliateId) {
            $this->logDebug('Missing service_id or affiliate_id - using default commission');
            return false; // Use default WHMCS calculation
        }

        // Check if product is in configured group
        $productGroupId = $this->getProductGroupId($productId);
        if ((int)$productGroupId !== (int)$this->productGroupId) {
            $this->logDebug('Product not in web hosting group - zeroing commission', [
                'product_id' => $productId,
                'product_group' => $productGroupId,
                'configured_group' => $this->productGroupId,
            ]);
            return 0; // Zero commission for non-hosting products
        }

        // Determine commission type
        $isFirstPayment = $this->isFirstPayment($serviceId, $affiliateId);
        $commissionPercent = $isFirstPayment ? $this->firstPercent : $this->recurringPercent;
        $commissionAmount = $this->calculateCommissionAmount($amount, $commissionPercent);

        $this->logDebug('Commission hook override applied', [
            'service_id' => $serviceId,
            'is_first' => $isFirstPayment,
            'percent' => $commissionPercent,
            'original_amount' => $amount,
            'commission_amount' => $commissionAmount,
        ]);

        // Record in our tracking system
        $commissionData = [
            'service_id'         => $serviceId,
            'affiliate_id'       => $affiliateId,
            'client_id'          => $clientId,
            'product_id'         => $productId,
            'invoice_id'         => $invoiceId,
            'commission_type'    => $isFirstPayment ? 'first' : 'recurring',
            'commission_percent' => $commissionPercent,
            'commission_amount'  => $commissionAmount,
            'item_amount'        => $amount,
            'is_first_payment'   => $isFirstPayment,
        ];

        $this->recordCommission($commissionData);

        return $commissionAmount;
    }

    /**
     * Handle invoice refund - reverse commission proportionally.
     *
     * A full refund reverses the whole commission and resets the first-paid
     * flag (as before); a partial refund reverses only the refunded share and
     * leaves the flag set. Reversals are cumulative per invoice, so repeated
     * or split refunds can never reverse more than was paid.
     *
     * @param int $invoiceId
     * @param float|null $refundAmount Refunded amount, when the hook provides it.
     * @return bool
     */
    public function handleInvoiceRefund($invoiceId, $refundAmount = null)
    {
        $this->logDebug('Processing refund for invoice', ['invoice_id' => $invoiceId]);

        try {
            $fraction = $this->refundFraction($invoiceId, $refundAmount);

            // Find commission records linked to this invoice
            $records = Capsule::table('mod_customaffiliate_commissions')
                ->where('first_commission_invoice_id', $invoiceId)
                ->get();

            foreach ($records as $record) {
                $original = round((float) $record->first_commission_amount, 2);
                $already = 0.0;
                foreach (Capsule::table('mod_customaffiliate_log')
                    ->where('service_id', $record->service_id)
                    ->where('affiliate_id', $record->affiliate_id)
                    ->where('invoice_id', $invoiceId)
                    ->where('action', 'refund')
                    ->get() as $row) {
                    $already += abs((float) $row->amount);
                }
                $already = round($already, 2);
                $remaining = round($original - $already, 2);
                if ($remaining <= 0) {
                    continue;
                }
                $reverse = min(round($fraction * $original, 2), $remaining);
                if ($reverse <= 0) {
                    continue;
                }
                $fullyReversed = round($remaining - $reverse, 2) <= 0;

                if ($fullyReversed) {
                    // Mark first commission as refunded by resetting flag
                    // Note: We reset first_commission_paid so if client repays, commission applies again
                    Capsule::table('mod_customaffiliate_commissions')
                        ->where('id', $record->id)
                        ->update([
                            'first_commission_paid'    => false,
                            'first_commission_paid_at' => null,
                            'first_commission_invoice_id' => null,
                            'notes'                    => $this->appendNote($record->notes, 'First commission refunded on ' . date('Y-m-d H:i:s')),
                        ]);
                } else {
                    Capsule::table('mod_customaffiliate_commissions')
                        ->where('id', $record->id)
                        ->update([
                            'notes' => $this->appendNote($record->notes, 'First commission partially reversed ($' . number_format($reverse, 2, '.', '') . ' of $' . number_format($original, 2, '.', '') . ') on ' . date('Y-m-d H:i:s')),
                        ]);
                }

                // Log the refund action
                Capsule::table('mod_customaffiliate_log')->insert([
                    'service_id'   => $record->service_id,
                    'affiliate_id' => $record->affiliate_id,
                    'invoice_id'   => $invoiceId,
                    'action'       => 'refund',
                    'amount'       => -$reverse,
                    'percentage'   => 0,
                    'description'  => $fullyReversed && $already == 0
                        ? 'First commission reversed due to refund'
                        : 'First commission partially reversed ($' . number_format($reverse, 2, '.', '') . ' of $' . number_format($original, 2, '.', '') . ')',
                ]);

                $this->logDebug('First commission reversed for refund', [
                    'service_id' => $record->service_id,
                    'affiliate_id' => $record->affiliate_id,
                    'amount' => $reverse,
                ]);
            }

            $this->reverseRecurringForInvoice($invoiceId, $fraction);

            return true;
        } catch (Exception $e) {
            $this->logDebug('Error processing refund', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
            ]);
            return false;
        }
    }

    /**
     * Refunded share of an invoice, 0..1.
     *
     * Uses the hook-supplied amount when present, else the money-out
     * transactions booked against the invoice, else 1.0 (full reversal, the
     * historical behaviour when the refunded amount is unknown).
     *
     * @param int $invoiceId
     * @param float|null $refundAmount
     * @return float
     */
    private function refundFraction($invoiceId, $refundAmount)
    {
        $refunded = null;
        if ($refundAmount !== null && (float) $refundAmount > 0) {
            $refunded = (float) $refundAmount;
        } else {
            try {
                $sum = 0.0;
                foreach (Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->get() as $txn) {
                    if ((float) ($txn->amountout ?? 0) > 0) {
                        $sum += (float) $txn->amountout;
                    }
                }
                if ($sum > 0) {
                    $refunded = $sum;
                }
            } catch (Exception $e) {
                $refunded = null;
            }
        }
        if ($refunded === null) {
            return 1.0;
        }
        $total = 0.0;
        $invoice = $this->getInvoiceData($invoiceId);
        if ($invoice) {
            $total = (float) $invoice['total'];
        }
        if ($total <= 0) {
            return 1.0;
        }
        return min(1.0, max(0.0, $refunded / $total));
    }

    /**
     * Append a note to existing notes. NULL or empty notes are replaced, not
     * concatenated (SQL CONCAT(NULL, ...) silently drops the new note).
     *
     * @param string|null $existing
     * @param string $text
     * @return string
     */
    public static function appendNote($existing, $text)
    {
        $current = trim((string) $existing);
        return $current === '' ? $text : $current . ' | ' . $text;
    }

    /** Append a note to every commission row for a service. */
    private function appendNoteForService($serviceId, $text)
    {
        foreach (Capsule::table('mod_customaffiliate_commissions')->where('service_id', $serviceId)->get() as $row) {
            Capsule::table('mod_customaffiliate_commissions')
                ->where('id', $row->id)
                ->update(['notes' => $this->appendNote($row->notes, $text)]);
        }
    }

    /**
     * Reverse recurring commissions recorded against an invoice, proportional
     * to the refunded share. Idempotent and cumulative: each entry is reversed
     * at most to its original amount across any number of refund events, keyed
     * by prior 'refund_recurring' rows that name the original log entry.
     *
     * @param int $invoiceId
     * @param float $fraction Refunded share of the invoice, 0..1.
     * @return void
     */
    private function reverseRecurringForInvoice($invoiceId, $fraction = 1.0)
    {
        $entries = Capsule::table('mod_customaffiliate_log')
            ->where('invoice_id', $invoiceId)
            ->where('action', 'recurring_commission')
            ->get();

        foreach ($entries as $entry) {
            $original = round((float) $entry->amount, 2);
            $tag = '(log #' . $entry->id . ')';
            $already = 0.0;
            foreach (Capsule::table('mod_customaffiliate_log')
                ->where('invoice_id', $invoiceId)
                ->where('action', 'refund_recurring')
                ->get() as $row) {
                if (strpos((string) $row->description, $tag) !== false) {
                    $already += abs((float) $row->amount);
                }
            }
            $already = round($already, 2);
            $remaining = round($original - $already, 2);
            if ($remaining <= 0) {
                continue;
            }
            $reverse = min(round($fraction * $original, 2), $remaining);
            if ($reverse <= 0) {
                continue;
            }
            $fullyReversed = round($remaining - $reverse, 2) <= 0;

            $record = Capsule::table('mod_customaffiliate_commissions')
                ->where('service_id', $entry->service_id)
                ->where('affiliate_id', $entry->affiliate_id)
                ->first();

            Capsule::table('mod_customaffiliate_log')->insert([
                'service_id'   => $entry->service_id,
                'affiliate_id' => $entry->affiliate_id,
                'invoice_id'   => $invoiceId,
                'action'       => 'refund_recurring',
                'amount'       => -$reverse,
                'percentage'   => 0,
                'description'  => 'Recurring commission reversed ' . $tag . ': $' . number_format($reverse, 2, '.', '') . ' of $' . number_format($original, 2, '.', ''),
            ]);

            if ($record) {
                $update = [
                    'total_recurring_commission' => max(0, round((float) $record->total_recurring_commission - $reverse, 2)),
                    'notes'                      => $this->appendNote($record->notes, 'Recurring commission reversed ($' . number_format($reverse, 2, '.', '') . ' of $' . number_format($original, 2, '.', '') . ') on refund of invoice ' . $invoiceId),
                ];
                if ($fullyReversed) {
                    $update['recurring_count'] = max(0, (int) $record->recurring_count - 1);
                }
                Capsule::table('mod_customaffiliate_commissions')
                    ->where('id', $record->id)
                    ->update($update);
            }

            $this->logDebug('Recurring commission reversed for refund', [
                'invoice_id' => $invoiceId, 'service_id' => $entry->service_id, 'amount' => $reverse,
            ]);
        }
    }

    /**
     * Handle service upgrade/downgrade.
     *
     * Runs the package-change logic for every commission record on the
     * service: the stored product is regrouped to the live one, and a move
     * into the hosting group resets first-commission tracking so the next
     * payment earns the first rate again.
     *
     * @param int $serviceId
     * @param string $type 'upgrade' or 'downgrade'
     * @return void
     */
    public function handleServiceChange($serviceId, $type)
    {
        $this->logDebug('Processing service ' . $type, ['service_id' => $serviceId]);

        try {
            $service = $this->getServiceData($serviceId);
            if (!$service) {
                return;
            }

            $newProductId = (int) $service['packageid'];
            $records = Capsule::table('mod_customaffiliate_commissions')
                ->where('service_id', $serviceId)
                ->get();
            if (!$records) {
                $this->logDebug('No commission records for service - nothing to regroup', ['service_id' => $serviceId]);
                return;
            }

            $olds = [];
            foreach ($records as $record) {
                $olds[(int) $record->product_id] = true;
            }
            foreach (array_keys($olds) as $oldProductId) {
                $this->processPackageChange($serviceId, $oldProductId, $newProductId);
            }
        } catch (Exception $e) {
            $this->logDebug('Error processing service change', [
                'error' => $e->getMessage(),
                'service_id' => $serviceId,
                'type' => $type,
            ]);
        }
    }

    /**
     * Process a package change for an existing service.
     *
     * The single home of the upgrade/downgrade rules (previously duplicated
     * between here and UpgradeHandler, which now delegates to this method).
     * Every branch regroups the stored product to the live one; only a move
     * into the hosting group resets first-commission tracking.
     *
     * @param int $serviceId
     * @param int $oldProductId
     * @param int $newProductId
     * @return array Result details
     */
    public function processPackageChange($serviceId, $oldProductId, $newProductId)
    {
        $oldProductId = (int) $oldProductId;
        $newProductId = (int) $newProductId;
        $oldIsHosting = $this->isWebHostingProduct($oldProductId);
        $newIsHosting = $this->isWebHostingProduct($newProductId);

        $result = [
            'service_id' => $serviceId,
            'old_product_id' => $oldProductId,
            'new_product_id' => $newProductId,
            'old_is_hosting' => $oldIsHosting,
            'new_is_hosting' => $newIsHosting,
            'action_taken' => 'none',
            'records_updated' => 0,
        ];

        if ($oldProductId === $newProductId) {
            // Config-only change: grouping already correct, nothing to reset.
            $this->appendNoteForService($serviceId, "Service change recorded (product unchanged: {$newProductId})");
            $result['action_taken'] = 'unchanged';
            $result['message'] = 'Product unchanged. Commission tracking untouched.';
        } elseif ($oldIsHosting && $newIsHosting) {
            // Stayed in hosting group - preserve first commission status
            $result['records_updated'] = $this->updateServiceProduct($serviceId, $newProductId);
            $this->appendNoteForService($serviceId, "Product changed within hosting group: {$oldProductId} -> {$newProductId}");
            $result['action_taken'] = 'preserved';
            $result['message'] = 'First commission status preserved. Recurring commissions continue at configured rate.';
        } elseif ($oldIsHosting && !$newIsHosting) {
            // Moved out of hosting group
            $result['records_updated'] = $this->updateServiceProduct($serviceId, $newProductId);
            $this->appendNoteForService($serviceId, "Product moved OUT of hosting group: {$oldProductId} -> {$newProductId}. Future commissions blocked.");
            $result['action_taken'] = 'blocked';
            $result['message'] = 'Product moved out of hosting group. Future commissions will not apply.';
        } elseif (!$oldIsHosting && $newIsHosting) {
            // Moved INTO hosting group - treat as new service for commission purposes
            $result['records_updated'] = $this->updateServiceProduct($serviceId, $newProductId);
            $this->resetFirstCommissionForService($serviceId);
            $this->appendNoteForService($serviceId, "Product moved INTO hosting group: {$oldProductId} -> {$newProductId}. Commission tracking reset.");
            $result['action_taken'] = 'reset';
            $result['message'] = 'Product moved into hosting group. Next payment will be treated as first payment (50% commission).';
        } else {
            // Was not hosting, still not hosting - regroup only, no payout impact
            $result['records_updated'] = $this->updateServiceProduct($serviceId, $newProductId);
            $this->appendNoteForService($serviceId, "Product changed outside hosting group: {$oldProductId} -> {$newProductId}. No commission impact.");
            $result['action_taken'] = 'ignored';
            $result['message'] = 'Neither old nor new product is in hosting group. No commission impact.';
        }

        $this->logDebug('Processed package change', $result);
        return $result;
    }

    /**
     * Regroup every commission row for a service onto a new product.
     *
     * @param int $serviceId
     * @param int $newProductId
     * @return int Rows updated.
     */
    private function updateServiceProduct($serviceId, $newProductId)
    {
        $updated = 0;
        foreach (Capsule::table('mod_customaffiliate_commissions')->where('service_id', $serviceId)->get() as $row) {
            if ((int) $row->product_id === (int) $newProductId) {
                continue;
            }
            $updated += Capsule::table('mod_customaffiliate_commissions')
                ->where('id', $row->id)
                ->update(['product_id' => (int) $newProductId]);
        }
        return $updated;
    }

    /**
     * Reset first-commission tracking for a service (recurring totals kept).
     *
     * @param int $serviceId
     * @return void
     */
    private function resetFirstCommissionForService($serviceId)
    {
        try {
            // Recurring totals are left as they are; only the first-payment flag is reset.
            foreach (Capsule::table('mod_customaffiliate_commissions')->where('service_id', $serviceId)->get() as $row) {
                Capsule::table('mod_customaffiliate_commissions')
                    ->where('id', $row->id)
                    ->update([
                        'first_commission_paid' => false,
                        'first_commission_paid_at' => null,
                        'first_commission_invoice_id' => null,
                        'notes' => self::appendNote($row->notes, 'Commission tracking reset on ' . date('Y-m-d H:i:s')),
                    ]);
            }
        } catch (Exception $e) {
            $this->logDebug('Error resetting commission status', [
                'error' => $e->getMessage(),
                'service_id' => $serviceId,
            ]);
        }
    }

    /**
     * Check if product ID belongs to configured web hosting group
     *
     * @param int $productId
     * @return bool
     */
    public function isWebHostingProduct($productId)
    {
        if (!$this->productGroupId) {
            return false;
        }

        $groupId = $this->getProductGroupId($productId);
        return (int)$groupId === (int)$this->productGroupId;
    }

    /**
     * Get product group ID for a product
     *
     * @param int $productId
     * @return int
     */
    public function getProductGroupId($productId)
    {
        try {
            $product = Capsule::table('tblproducts')
                ->where('id', $productId)
                ->first();

            return $product ? (int) $product->gid : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get service ID from invoice item description
     *
     * @param array $item
     * @return int|false
     */
    private function extractServiceId(array $item)
    {
        // WHMCS stores service ID in the 'relid' field for product items
        if ($item['type'] === 'Hosting' && !empty($item['relid'])) {
            return (int) $item['relid'];
        }

        // Fallback: try to parse from description
        if (preg_match('/Service ID:\s*(\d+)/i', $item['description'] ?? '', $matches)) {
            return (int) $matches[1];
        }

        return false;
    }

    /**
     * Get full invoice data with items
     *
     * @param int $invoiceId
     * @return array|false
     */
    private function getInvoiceData($invoiceId)
    {
        try {
            $invoice = Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->first();

            if (!$invoice) {
                return false;
            }

            $items = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->get();

            return [
                'id'       => $invoice->id,
                'userid'   => $invoice->userid,
                'status'   => $invoice->status,
                'total'    => $invoice->total,
                'items'    => json_decode(json_encode($items), true),
            ];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get service data
     *
     * @param int $serviceId
     * @return array|false
     */
    private function getServiceData($serviceId)
    {
        try {
            $service = Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->first();

            if (!$service) {
                return false;
            }

            return [
                'id'         => $service->id,
                'userid'     => $service->userid,
                'packageid'  => $service->packageid,
                'domain'     => $service->domain,
                'status'     => $service->domainstatus,
            ];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get affiliate ID for a client (the referring affiliate)
     *
     * @param int $clientId
     * @return int|false
     */
    private function getClientAffiliate($clientId)
    {
        try {
            // Check if client was referred by an affiliate via affiliate ID stored on client record
            $client = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->first();

            // Only the referrer counts. tblaffiliates.clientid is the affiliate
            // account the client OWNS, so using it here would pay the client
            // commission on their own orders (self-referral).
            if ($client && !empty($client->affiliateid)) {
                return (int) $client->affiliateid;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Calculate commission amount from percentage
     *
     * @param float $amount
     * @param float $percent
     * @return float
     */
    private function calculateCommissionAmount($amount, $percent)
    {
        if ($amount <= 0 || $percent <= 0) {
            return 0.00;
        }

        return round(($amount * $percent) / 100, 2);
    }

    /**
     * Log commission activity to audit table
     *
     * @param array $commissionData
     * @return void
     */
    private function logCommissionActivity(array $commissionData)
    {
        try {
            Capsule::table('mod_customaffiliate_log')->insert([
                'service_id'   => $commissionData['service_id'],
                'affiliate_id' => $commissionData['affiliate_id'],
                'invoice_id'   => $commissionData['invoice_id'] ?? null,
                'action'       => $commissionData['commission_type'] . '_commission',
                'amount'       => $commissionData['commission_amount'],
                'percentage'   => $commissionData['commission_percent'],
                'description'  => sprintf(
                    '%s commission of $%s (%s%%) on $%s for service %s',
                    ucfirst($commissionData['commission_type']),
                    number_format($commissionData['commission_amount'], 2),
                    $commissionData['commission_percent'],
                    number_format($commissionData['item_amount'], 2),
                    $commissionData['service_id']
                ),
            ]);
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Check for duplicate commission payment (prevention)
     *
     * @param int $invoiceId
     * @param int $serviceId
     * @param int $affiliateId
     * @return bool True if duplicate detected
     */
    public function isDuplicateCommission($invoiceId, $serviceId, $affiliateId)
    {
        try {
            $exists = Capsule::table('mod_customaffiliate_log')
                ->where('invoice_id', $invoiceId)
                ->where('service_id', $serviceId)
                ->where('affiliate_id', $affiliateId)
                ->where('action', 'like', '%_commission')
                ->exists();

            return $exists;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get configured product group ID
     *
     * @return int
     */
    public function getConfiguredGroupId()
    {
        return (int) $this->productGroupId;
    }

    /**
     * Get first commission percentage
     *
     * @return float
     */
    public function getFirstPercent()
    {
        return (float) $this->firstPercent;
    }

    /**
     * Get recurring commission percentage
     *
     * @return float
     */
    public function getRecurringPercent()
    {
        return (float) $this->recurringPercent;
    }
}
