<?php
/**
 * Discount Domain Club.
 *
 * Memberships are billed through a normal host invoice (so every payment
 * method, tax rule and refund flow WHMCS already has applies), activated by
 * the InvoicePaid hook, and surface as live per-TLD pricing overrides in the
 * order form through OrderDomainPricingOverride — the discount the member
 * sees is the discount the order actually charges.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\ForbiddenException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;

class ClubService
{
    /* ------------------------------------------------------------- plans -- */

    /** @return array[] active plans with their TLD rows attached */
    public function publicPlans()
    {
        $plans = Db::all('club_plans', ['status' => 'active'], 'sort_order ASC, id ASC');
        foreach ($plans as &$plan) {
            $plan['tlds'] = Db::all('club_plan_tlds', ['plan_id' => (int) $plan['id']], 'tld ASC');
        }
        unset($plan);
        return $plans;
    }

    public function allPlans()
    {
        return Db::all('club_plans', [], 'sort_order ASC, id ASC');
    }

    public function plan($planId)
    {
        $plan = Db::first('club_plans', ['id' => (int) $planId]);
        if (!$plan) {
            throw new NotFoundException('That membership plan does not exist.');
        }
        $plan['tlds'] = Db::all('club_plan_tlds', ['plan_id' => (int) $plan['id']], 'tld ASC');
        return $plan;
    }

    public function savePlan($planId, array $data, $adminId)
    {
        $errors = [];
        $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
        if ($name === '' || strlen($name) > 96) {
            $errors['name'] = 'Plan name is required (max 96 characters).';
        }
        $priceMinor = isset($data['price_minor']) ? (int) $data['price_minor'] : -1;
        if ($priceMinor < 0) {
            $errors['price'] = 'Price must be zero or more.';
        }
        $period = isset($data['period_months']) ? (int) $data['period_months'] : 12;
        if (!in_array($period, [1, 3, 6, 12, 24], true)) {
            $errors['period'] = 'Period must be 1, 3, 6, 12 or 24 months.';
        }
        $discount = isset($data['discount_percent']) ? (float) $data['discount_percent'] : -1;
        if ($discount < 0 || $discount > 100) {
            $errors['discount'] = 'Discount must be between 0 and 100%.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $row = [
            'name'             => $name,
            'description'      => (string) (isset($data['description']) ? $data['description'] : ''),
            'price_minor'      => $priceMinor,
            'currency'         => strtoupper(substr((string) (isset($data['currency']) && $data['currency'] ? $data['currency'] : Platform::gateway()->defaultCurrency()), 0, 3)),
            'period_months'    => $period,
            'discount_percent' => $discount,
            'applies_register' => !empty($data['applies_register']) ? 1 : 0,
            'applies_renew'    => !empty($data['applies_renew']) ? 1 : 0,
            'applies_transfer' => !empty($data['applies_transfer']) ? 1 : 0,
            'max_domains'      => isset($data['max_domains']) ? max(0, (int) $data['max_domains']) : 0,
            'status'           => in_array(isset($data['status']) ? $data['status'] : 'active', ['active', 'retired'], true)
                ? (isset($data['status']) ? $data['status'] : 'active') : 'active',
            'sort_order'       => isset($data['sort_order']) ? (int) $data['sort_order'] : 100,
            'updated_at'       => Clock::now(),
        ];

        if ($planId) {
            Db::update('club_plans', ['id' => (int) $planId], $row);
            $id = (int) $planId;
        } else {
            $row['slug'] = Str::slug($name);
            // ensure unique slug
            if (Db::first('club_plans', ['slug' => $row['slug']])) {
                $row['slug'] .= '-' . substr(sha1($name . microtime(true)), 0, 4);
            }
            $row['created_at'] = Clock::now();
            $id = Db::insert('club_plans', $row);
        }

        // Replace the eligible TLD set when provided.
        if (isset($data['tlds']) && is_array($data['tlds'])) {
            Db::exec('DELETE FROM ' . Db::t('club_plan_tlds') . ' WHERE plan_id = ?', [$id]);
            foreach ($data['tlds'] as $tld => $percent) {
                $tld = ltrim(strtolower(trim((string) $tld)), '.');
                if (!preg_match('/^[a-z0-9.-]{1,63}$/', $tld)) {
                    continue;
                }
                Db::insert('club_plan_tlds', [
                    'plan_id'          => $id,
                    'tld'              => $tld,
                    'discount_percent' => $percent === null || $percent === '' ? null : (float) $percent,
                ]);
            }
        }

        Audit::admin($adminId, $planId ? 'club.plan_updated' : 'club.plan_created', ['plan' => $id]);
        return $id;
    }

    /* ------------------------------------------------------- memberships -- */

    /** The client's current usable membership row, or null. */
    public function activeMembership($clientId)
    {
        $row = Db::query(
            'SELECT * FROM ' . Db::t('club_memberships')
            . " WHERE client_id = ? AND status = 'active'"
            . ' AND (expires_at IS NULL OR expires_at > ?)'
            . ' ORDER BY id DESC LIMIT 1',
            [(int) $clientId, Clock::now()]
        );
        if (!$row) {
            return null;
        }
        $membership = $row[0];
        $membership['plan'] = $this->plan((int) $membership['plan_id']);
        return $membership;
    }

    /** @return array[] all of the client's memberships, newest first */
    public function membershipsFor($clientId)
    {
        $rows = Db::all('club_memberships', ['client_id' => (int) $clientId], 'id DESC');
        foreach ($rows as &$row) {
            $row['plan'] = Db::first('club_plans', ['id' => (int) $row['plan_id']]);
        }
        unset($row);
        return $rows;
    }

    /**
     * Join: create an invoice for the plan price and park the membership as
     * pending until the InvoicePaid hook activates it.
     *
     * @return array{membership_id:int, invoice_id:int, url:string}
     */
    public function join($clientId, $planId)
    {
        if (!Settings::bool('club_enabled', true)) {
            throw new ChsException('The Discount Domain Club is not open right now.');
        }
        $clientId = (int) $clientId;
        if (!Platform::gateway()->clientExists($clientId)) {
            throw new ForbiddenException('Please sign in to join.');
        }
        if ($this->activeMembership($clientId)) {
            throw new ChsException('You already hold an active membership.');
        }

        // A pending membership with an unpaid invoice? Re-use it rather than duplicating.
        $existing = Db::query(
            'SELECT * FROM ' . Db::t('club_memberships')
            . " WHERE client_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$clientId]
        );
        if ($existing) {
            $link = $existing[0];
            $status = Platform::gateway()->invoiceStatus((int) $link['invoice_id']);
            if ($status === 'Paid') {
                $this->invoicePaid((int) $link['invoice_id']);
                return $this->joinResult((int) $link['id'], (int) $link['invoice_id']);
            }
            if ($status === 'Unpaid') {
                return $this->joinResult((int) $link['id'], (int) $link['invoice_id']);
            }
            // Anything else (cancelled/refunded): expire the pending row and continue fresh.
            Db::update('club_memberships', ['id' => (int) $link['id']], ['status' => 'expired', 'updated_at' => Clock::now()]);
        }

        $plan = $this->plan($planId);
        if ($plan['status'] !== 'active') {
            throw new NotFoundException('That membership plan is not available.');
        }

        $invoiceId = Platform::gateway()->createInvoice(
            $clientId,
            [[
                'description'  => $plan['name'] . ' — ' . $plan['period_months'] . ' month membership',
                'amount_minor' => (int) $plan['price_minor'],
                'taxed'        => true,
            ]],
            $plan['currency'],
            Settings::int('club_invoice_due_days', 2),
            'Activates automatically on payment.'
        );

        $membershipId = Db::insert('club_memberships', [
            'client_id'   => $clientId,
            'plan_id'     => (int) $plan['id'],
            'status'      => 'pending',
            'invoice_id'  => $invoiceId,
            'starts_at'   => null,
            'expires_at'  => null,
            'created_at'  => Clock::now(),
            'updated_at'  => Clock::now(),
        ]);

        Audit::client($clientId, 'club.joined', ['plan' => (int) $plan['id'], 'invoice' => $invoiceId]);
        return $this->joinResult($membershipId, $invoiceId);
    }

    protected function joinResult($membershipId, $invoiceId)
    {
        return [
            'membership_id' => $membershipId,
            'invoice_id'    => $invoiceId,
            'url'           => Platform::gateway()->invoiceUrl($invoiceId),
        ];
    }

    /**
     * InvoicePaid hook entry point. Activates any pending membership tied to
     * the invoice.
     */
    public function invoicePaid($invoiceId)
    {
        $row = Db::first('club_memberships', ['invoice_id' => (int) $invoiceId]);
        if (!$row || $row['status'] !== 'pending') {
            return false;
        }

        $plan = $this->plan((int) $row['plan_id']);
        Db::transaction(function () use ($row, $plan) {
            Db::update('club_memberships', ['id' => (int) $row['id']], [
                'status'     => 'active',
                'starts_at'  => Clock::now(),
                'expires_at' => Clock::in((int) $plan['period_months'] * 30 * 86400),
                'updated_at' => Clock::now(),
            ]);
            (new NotificationService())->notify((int) $row['client_id'], 'club_active',
                $plan['name'] . ' is now active',
                'Your membership is live: ' . rtrim(rtrim(number_format((float) $plan['discount_percent'], 2), '0'), '.')
                    . '% member pricing now applies automatically across eligible extensions.',
                'index.php?m=cloudhost247services&action=club');
        });

        Audit::system('club.activated', ['membership' => (int) $row['id'], 'client' => (int) $row['client_id']]);
        return true;
    }

    /** Cancel at the end of the paid period; discounts stop when it expires. */
    public function cancel($clientId, $membershipId)
    {
        $membership = Db::first('club_memberships', ['id' => (int) $membershipId, 'client_id' => (int) $clientId]);
        if (!$membership) {
            throw new NotFoundException('Membership not found.');
        }
        if ($membership['status'] === 'active') {
            Db::update('club_memberships', ['id' => (int) $membershipId], [
                'cancelled_at' => Clock::now(),
                'updated_at'   => Clock::now(),
            ]);
            Audit::client((int) $clientId, 'club.cancel_requested', ['membership' => (int) $membershipId]);
        }
        return true;
    }

    /** Scheduled task: expire memberships whose paid period has elapsed. */
    public function expireDue()
    {
        $rows = Db::query(
            'SELECT id, client_id FROM ' . Db::t('club_memberships')
            . " WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= ?",
            [Clock::now()]
        );
        foreach ($rows as $row) {
            Db::update('club_memberships', ['id' => (int) $row['id']], [
                'status'     => 'expired',
                'updated_at' => Clock::now(),
            ]);
            (new NotificationService())->notify((int) $row['client_id'], 'club_expired',
                'Your Discount Domain Club membership has expired',
                'Member pricing has ended. Rejoin any time from the club page to restore it.',
                'discount-domain-club.php');
            Audit::system('club.expired', ['membership' => (int) $row['id']]);
        }
        return count($rows);
    }

    /* --------------------------------------------------------- price hook -- */

    /**
     * Entry point for the OrderDomainPricingOverride hook. $type is one of
     * register|renew|transfer, as WHMCS passes it.
     *
     * @return float|string discounted price for the order form, '' for no change
     */
    public function orderPriceOverride($type, $tld, $clientId, $price)
    {
        if (!Settings::bool('club_enabled', true)) {
            return '';
        }
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return '';
        }
        $tld = ltrim(strtolower((string) $tld), '.');
        $membership = $this->activeMembership($clientId);
        if (!$membership) {
            return '';
        }

        $plan = $membership['plan'];
        $flag = [
            'register' => 'applies_register',
            'renew'    => 'applies_renew',
            'transfer' => 'applies_transfer',
        ][$type];
        $settingFlag = [
            'register' => 'club_allow_registrations',
            'renew'    => 'club_allow_renewals',
            'transfer' => 'club_allow_transfers',
        ][$type];
        if (empty($plan[$flag]) || !Settings::bool($settingFlag, false)) {
            return '';
        }

        $percent = null;
        foreach ($plan['tlds'] as $row) {
            if ($row['tld'] === $tld) {
                $percent = $row['discount_percent'] !== null
                    ? (float) $row['discount_percent']
                    : (float) $plan['discount_percent'];
                break;
            }
        }
        if ($percent === null || $percent <= 0) {
            return '';
        }

        $priceMinor = Money::fromDecimal($price, $plan['currency']);
        if ($priceMinor <= 0) {
            return '';
        }
        $discounted = Money::discount($priceMinor, $percent);

        Audit::system('club.discount_applied', [
            'client' => $clientId, 'type' => $type, 'tld' => $tld,
            'from' => $priceMinor, 'to' => $discounted,
        ]);
        return number_format($discounted / 100, 2, '.', '');
    }

    /** Percent this member would get on a TLD — used by the directory UI. */
    public function memberPercentFor($clientId, $tld, $type = 'register')
    {
        $membership = $this->activeMembership($clientId);
        if (!$membership) {
            return null;
        }
        $plan = $membership['plan'];
        $tld = ltrim(strtolower((string) $tld), '.');
        foreach ($plan['tlds'] as $row) {
            if ($row['tld'] === $tld) {
                $pct = $row['discount_percent'] !== null
                    ? (float) $row['discount_percent']
                    : (float) $plan['discount_percent'];
                return $pct > 0 ? $pct : null;
            }
        }
        return null;
    }
}
