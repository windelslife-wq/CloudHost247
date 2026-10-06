<?php
/**
 * Hire-an-expert / professional-services intake.
 *
 * Real workflow: customers submit scoped projects (design, development,
 * ecommerce, migration, SEO...), staff review, quote (optionally invoicing
 * the quote through the platform), both parties converse on a permanent
 * timeline and the customer tracks status end-to-end.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\ForbiddenException;
use Chs\Core\InvalidTransitionException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\RateLimiter;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Workflow\RequestStatus;

class ServiceRequestService
{
    /** Catalogue of requestable service lines. */
    public static function types()
    {
        return [
            'website_design'       => ['label' => 'Website design',         'desc' => 'A bespoke, on-brand site designed around your content and goals.'],
            'website_development'  => ['label' => 'Website development',    'desc' => 'Custom functionality, applications and integrations built to spec.'],
            'ecommerce'            => ['label' => 'Ecommerce development',  'desc' => 'Storefronts, catalogues, payments, shipping and order management.'],
            'redesign'             => ['label' => 'Website redesign',       'desc' => 'Modernise an existing site without losing your content or SEO equity.'],
            'seo'                  => ['label' => 'SEO engagement',         'desc' => 'Technical audit, on-page optimisation and ongoing search strategy.'],
            'migration'            => ['label' => 'Website migration',      'desc' => 'Move sites, stores and mailboxes to CloudHost247 with zero downtime planning.'],
            'maintenance'          => ['label' => 'Maintenance & care',     'desc' => 'Updates, backups, monitoring and small ongoing changes on retainer.'],
            'custom_integration'   => ['label' => 'Custom integration',     'desc' => 'Connect your site to CRMs, ERPs, payment, shipping or internal systems.'],
            'digital_marketing'    => ['label' => 'Digital marketing',      'desc' => 'Campaign setup and management across search, social and email.'],
            'ai_website'           => ['label' => 'AI-assisted website',    'desc' => 'A fast AI-drafted site, reviewed and finished by a human expert.'],
        ];
    }

    /** Allowed forward transitions, by role. */
    private static $transitions = [
        RequestStatus::REQUESTED   => ['admin' => [RequestStatus::REVIEWING, RequestStatus::REJECTED], 'client' => [RequestStatus::CANCELLED]],
        RequestStatus::REVIEWING   => ['admin' => [RequestStatus::QUOTED, RequestStatus::REJECTED, RequestStatus::IN_PROGRESS], 'client' => [RequestStatus::CANCELLED]],
        RequestStatus::QUOTED      => ['admin' => [RequestStatus::REVIEWING, RequestStatus::IN_PROGRESS, RequestStatus::REJECTED], 'client' => [RequestStatus::ACCEPTED, RequestStatus::CANCELLED]],
        RequestStatus::ACCEPTED    => ['admin' => [RequestStatus::IN_PROGRESS], 'client' => []],
        RequestStatus::IN_PROGRESS => ['admin' => [RequestStatus::DELIVERED, RequestStatus::REVIEWING], 'client' => []],
        RequestStatus::DELIVERED   => ['admin' => [RequestStatus::COMPLETED, RequestStatus::IN_PROGRESS], 'client' => [RequestStatus::COMPLETED]],
        RequestStatus::COMPLETED   => ['admin' => [], 'client' => []],
        RequestStatus::CANCELLED   => ['admin' => [], 'client' => []],
        RequestStatus::REJECTED    => ['admin' => [], 'client' => []],
    ];

    /* ------------------------------------------------------------ create -- */

    public function create($clientId, array $input)
    {
        if (!Settings::bool('requests_enabled', true)) {
            throw new ChsException('New service requests are paused right now.');
        }
        RateLimiter::hitOrFail('service_request', 'client:' . (int) $clientId,
            Settings::int('requests_daily_limit', 10), 86400);

        $errors = [];
        $type = isset($input['type']) ? (string) $input['type'] : '';
        if (!isset(self::types()[$type])) {
            $errors['type'] = 'Choose the service you need.';
        }
        $title = trim((string) (isset($input['title']) ? $input['title'] : ''));
        if ($title === '' || strlen($title) > 190) {
            $errors['title'] = 'Give the project a short title (up to 190 characters).';
        }
        $brief = trim((string) (isset($input['brief']) ? $input['brief'] : ''));
        if (strlen($brief) < 30) {
            $errors['brief'] = 'Tell us a little more — aim for at least a couple of sentences so we can scope properly.';
        }
        $budget = isset($input['budget_range']) ? trim((string) $input['budget_range']) : '';
        if ($budget !== '' && !in_array($budget, $this->budgetRanges(), true)) {
            $errors['budget_range'] = 'Choose a budget range from the list.';
        }
        $targetDomain = isset($input['target_domain']) ? trim((string) $input['target_domain']) : '';
        if ($targetDomain !== '' && \Chs\Core\DomainName::tryParse($targetDomain) === null) {
            $errors['target_domain'] = 'That does not look like a valid domain. Leave it blank if you are unsure.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $id = Db::insert('service_requests', [
            'client_id'          => (int) $clientId,
            'type'               => $type,
            'status'             => RequestStatus::REQUESTED,
            'title'              => $title,
            'brief'              => $brief,
            'budget_range'       => $budget,
            'target_domain'      => strtolower($targetDomain),
            'quote_minor'        => null,
            'currency'           => Platform::gateway()->clientCurrency((int) $clientId),
            'assignee_admin_id'  => null,
            'invoice_id'         => null,
            'created_at'         => Clock::now(),
            'updated_at'         => Clock::now(),
        ]);

        $this->post($id, 'client', (int) $clientId, $brief, RequestStatus::REQUESTED, false);

        Audit::client((int) $clientId, 'request.created', ['request' => $id, 'type' => $type]);
        return $this->detailFor($clientId, $id);
    }

    /** @return string[] */
    public function budgetRanges()
    {
        return ['', 'under_500', '500_1500', '1500_5000', '5000_15000', '15000_plus'];
    }

    /* ------------------------------------------------------------- read -- */

    /** @return array[] */
    public function listFor($clientId)
    {
        return Db::all('service_requests', ['client_id' => (int) $clientId], 'id DESC', 200);
    }

    public function detailFor($clientId, $requestId)
    {
        $row = Db::first('service_requests', ['id' => (int) $requestId, 'client_id' => (int) $clientId]);
        if (!$row) {
            throw new NotFoundException('That request was not found on your account.');
        }
        return $this->hydrate($row, false);
    }

    public function detailForAdmin($requestId)
    {
        $row = Db::first('service_requests', ['id' => (int) $requestId]);
        if (!$row) {
            throw new NotFoundException('Request not found.');
        }
        $row['client'] = Platform::gateway()->clientSummary((int) $row['client_id']);
        return $this->hydrate($row, true);
    }

    protected function hydrate(array $row, $forAdmin)
    {
        $updates = Db::all('service_updates', ['request_id' => (int) $row['id']], 'id ASC', 500);
        $visible = [];
        foreach ($updates as $update) {
            if (!$forAdmin && (int) $update['is_internal'] === 1) {
                continue;
            }
            $visible[] = $update;
        }
        $row['updates'] = $visible;
        $row['type_label'] = isset(self::types()[$row['type']])
            ? self::types()[$row['type']]['label'] : $row['type'];
        return $row;
    }

    /* ----------------------------------------------------------- discuss -- */

    /**
     * Append to the timeline, optionally driving a status transition.
     *
     * @return array fresh detail row
     */
    public function post($requestId, $authorType, $authorId, $body, $statusTo = '', $internal = false)
    {
        $request = Db::first('service_requests', ['id' => (int) $requestId]);
        if (!$request) {
            throw new NotFoundException('Request not found.');
        }

        $isAdmin = $authorType === 'admin';
        if (!$isAdmin && (int) $request['client_id'] !== (int) $authorId) {
            throw new ForbiddenException('You can only reply to your own requests.');
        }
        if (!$isAdmin && !in_array($request['status'], RequestStatus::open(), true)) {
            throw new InvalidTransitionException('This request is closed; open a new one if you need more help.');
        }

        $body = trim((string) $body);
        if ($body === '' && $statusTo === '') {
            throw new ValidationException(['body' => 'Write a message first.']);
        }
        if (strlen($body) > 8000) {
            throw new ValidationException(['body' => 'Messages are limited to 8,000 characters.']);
        }
        if ($internal && !$isAdmin) {
            throw new ForbiddenException('Internal notes are staff-only.');
        }

        if ($statusTo !== '' && $statusTo !== $request['status']) {
            $allowed = isset(self::$transitions[$request['status']][$authorType])
                ? self::$transitions[$request['status']][$authorType] : [];
            if (!in_array($statusTo, $allowed, true)) {
                throw new InvalidTransitionException(
                    'Cannot move from ' . $request['status'] . ' to ' . $statusTo . '.');
            }
            Db::update('service_requests', ['id' => (int) $requestId], [
                'status'     => $statusTo,
                'updated_at' => Clock::now(),
            ]);
        }

        Db::insert('service_updates', [
            'request_id'  => (int) $requestId,
            'author_type' => $isAdmin ? 'admin' : 'client',
            'author_id'   => (int) $authorId,
            'status_to'   => $statusTo,
            'is_internal' => $internal ? 1 : 0,
            'body'        => $body,
            'created_at'  => Clock::now(),
        ]);

        if (!$internal) {
            $notify = new NotificationService();
            if ($isAdmin) {
                $notify->notify((int) $request['client_id'], 'request_update',
                    'Update on: ' . $request['title'],
                    ($statusTo !== '' ? 'Status is now ' . str_replace('_', ' ', $statusTo) . '. ' : '')
                        . ($body !== '' ? \Chs\Core\Str::truncate($body, 180) : ''),
                    'index.php?m=cloudhost247services&action=request&id=' . (int) $requestId);
            }
        }

        Audit::log($isAdmin ? Audit::ACTOR_ADMIN : Audit::ACTOR_CLIENT, (int) $authorId,
            'request.message', ['request' => (int) $requestId, 'status_to' => $statusTo, 'internal' => $internal]);
        return $isAdmin ? $this->detailForAdmin($requestId) : $this->detailFor((int) $request['client_id'], $requestId);
    }

    /* -------------------------------------------------------------- quote -- */

    /**
     * Staff publish a fixed quote; the customer accepts it from the portal,
     * which raises a platform invoice for the quoted amount.
     */
    public function quote($requestId, $adminId, $quoteMinor, $note = '')
    {
        $quoteMinor = (int) $quoteMinor;
        if ($quoteMinor < 0) {
            throw new ValidationException(['quote' => 'A quote cannot be negative.']);
        }
        Db::update('service_requests', ['id' => (int) $requestId], [
            'quote_minor' => $quoteMinor,
            'updated_at'  => Clock::now(),
        ]);
        Audit::admin($adminId, 'request.quoted', ['request' => $requestId, 'amount' => $quoteMinor]);
        return $this->post($requestId, 'admin', $adminId,
            $note !== '' ? $note : 'Your quote is ready.',
            RequestStatus::QUOTED);
    }

    /**
     * Customer accepts the current quote: status → accepted, invoice raised.
     */
    public function acceptQuote($clientId, $requestId)
    {
        $request = $this->detailFor($clientId, $requestId);
        if ($request['status'] !== RequestStatus::QUOTED || $request['quote_minor'] === null) {
            throw new InvalidTransitionException('There is no quote waiting for your acceptance.');
        }
        if ($request['invoice_id'] !== null) {
            throw new InvalidTransitionException('An invoice for this quote already exists.');
        }

        $invoiceId = Platform::gateway()->createInvoice(
            (int) $clientId,
            [[
                'description'  => 'Professional services — ' . $request['title'] . ' (request #' . $requestId . ')',
                'amount_minor' => (int) $request['quote_minor'],
                'taxed'        => true,
            ]],
            $request['currency'],
            5,
            'Issued on acceptance of the quoted price. Work begins on payment.'
        );

        Db::update('service_requests', ['id' => (int) $requestId], [
            'invoice_id' => $invoiceId,
            'updated_at' => Clock::now(),
        ]);
        $this->post($requestId, 'client', (int) $clientId, 'Quote accepted — proceeding.', RequestStatus::ACCEPTED);
        Audit::client((int) $clientId, 'request.quote_accepted', ['request' => $requestId, 'invoice' => $invoiceId]);
        return ['invoice_id' => $invoiceId, 'url' => Platform::gateway()->invoiceUrl($invoiceId)];
    }

    /* -------------------------------------------------------------- admin -- */

    /** @return array[] */
    public function adminList($status = '', $limit = 100)
    {
        $where = [];
        $bind = [];
        if ($status !== '') {
            $where[] = 'status = ?';
            $bind[] = $status;
        }
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('service_requests')
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY CASE status
                  WHEN 'requested' THEN 0 WHEN 'reviewing' THEN 1 WHEN 'quoted' THEN 2
                  WHEN 'accepted' THEN 3 WHEN 'in_progress' THEN 4 ELSE 9 END, id DESC
               LIMIT " . (int) $limit,
            $bind
        );
        return $rows;
    }

    public function adminAssign($requestId, $adminId, $assigneeId)
    {
        Db::update('service_requests', ['id' => (int) $requestId], [
            'assignee_admin_id' => (int) $assigneeId,
            'updated_at'        => Clock::now(),
        ]);
        Audit::admin($adminId, 'request.assigned', ['request' => $requestId, 'assignee' => (int) $assigneeId]);
    }
}
