<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\ForbiddenException;
use Chs\Core\InvalidTransitionException;
use Chs\Core\NotFoundException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\ServiceRequestService;
use Chs\Workflow\RequestStatus;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_freeze();

T::section('Types + budget catalogue is real');
$types = ServiceRequestService::types();
T::ok('at least 9 service types', count($types) >= 9);
T::ok('every type labelled + described', (function () use ($types) {
    foreach ($types as $t) {
        if (empty($t['label']) || empty($t['desc'])) { return false; }
    }
    return true;
})());
$svc = new ServiceRequestService();
T::ok('budget ranges offered', count($svc->budgetRanges()) >= 4);

T::section('Create — validation first');
T::throws('unknown type rejected', function () use ($svc) {
    $svc->create(11, ['type' => 'make-me-rich', 'title' => 'X', 'brief' => str_repeat('b', 40)]);
}, ValidationException::class);
T::throws('empty title rejected', function () use ($svc) {
    $svc->create(11, ['type' => 'website_design', 'title' => '', 'brief' => str_repeat('b', 40)]);
}, ValidationException::class);
T::throws('short brief rejected', function () use ($svc) {
    $svc->create(11, ['type' => 'website_design', 'title' => 'My site', 'brief' => 'too short']);
}, ValidationException::class);
T::throws('freestyle budget rejected', function () use ($svc) {
    $svc->create(11, ['type' => 'website_design', 'title' => 'My site',
        'brief' => str_repeat('b', 40), 'budget_range' => 'whatever I feel like']);
}, ValidationException::class);
T::throws('bad optional domain rejected', function () use ($svc) {
    $svc->create(11, ['type' => 'migration', 'title' => 'Move us',
        'brief' => str_repeat('b', 40), 'target_domain' => 'not a domain!!']);
}, ValidationException::class);

$req = $svc->create(11, [
    'type' => 'website_design',
    'title' => 'Bakery storefront rebuild',
    'brief' => 'Our bakery needs a fresh site with online ordering and shop photography that matches our rebrand.',
    'budget_range' => $svc->budgetRanges()[1],
    'target_domain' => 'bakery.example.com',
]);
$rid = (int) $req['id'];
T::eq('status requested', RequestStatus::REQUESTED, $req['status']);
T::eq('client stored', 11, (int) $req['client_id']);
T::eq('currency from client', 'USD', $req['currency']);
T::ok('opening brief in timeline', (function () use ($req) {
    return count($req['updates']) >= 1 && $req['updates'][0]['body'] !== '';
})());
T::ok('type label rendered on detail', $req['type_label'] !== '');
T::ok('owner sees own request', (function () use ($svc, $rid) { return (int) $svc->detailFor(11, $rid)['id'] === $rid; })());
T::throws('stranger cannot view request', function () use ($svc, $rid) {
    $svc->detailFor(22, $rid);
}, NotFoundException::class);
T::eq('list returns own requests only', 1, count($svc->listFor(11)));

T::section('Timeline + transitions — state machine enforced');
T::throws('client cannot self-promote to reviewing', function () use ($svc, $rid) {
    $svc->post($rid, 'client', 11, 'please hurry', RequestStatus::REVIEWING);
}, \Chs\Core\ChsException::class);
$svc->post($rid, 'admin', 1, 'Picked this up, scoping it now.', RequestStatus::REVIEWING);
T::eq('admin moved to reviewing', RequestStatus::REVIEWING,
    Db::first('service_requests', ['id' => $rid])['status']);
T::ok('client notified of own progress', Db::count('notifications', ['client_id' => 11, 'type' => 'request_update']) >= 1);
T::ok('plain client reply works', (function () use ($svc, $rid) {
    $svc->post($rid, 'client', 11, 'Happy to share brand assets whenever useful.');
    return true;
})());
T::throws('client cannot post internal note', function () use ($svc, $rid) {
    $svc->post($rid, 'client', 11, 'secret', '', true);
}, ForbiddenException::class);
T::throws('client cannot move to in_progress', function () use ($svc, $rid) {
    $svc->post($rid, 'client', 11, 'go go go', RequestStatus::IN_PROGRESS);
}, InvalidTransitionException::class);
T::throws('empty message rejected', function () use ($svc, $rid) {
    $svc->post($rid, 'client', 11, '   ');
}, ValidationException::class);
$svc->post($rid, 'admin', 2, 'Scope depends on photo rights; cleared internally.', '', true);
$clientView = $svc->detailFor(11, $rid);
T::ok('internal note hidden from client', (function () use ($clientView) {
    foreach ($clientView['updates'] as $m) {
        if (strpos($m['body'], 'Scope depends') !== false) { return false; }
    }
    return true;
})());
$adminView = $svc->detailForAdmin($rid);
T::ok('internal note visible to staff', (function () use ($adminView) {
    foreach ($adminView['updates'] as $m) {
        if (strpos($m['body'], 'Scope depends') !== false) { return true; }
    }
    return false;
})());
T::ok('internal flagged in admin view', (function () use ($adminView) {
    foreach ($adminView['updates'] as $m) {
        if (strpos($m['body'], 'Scope depends') !== false) { return (int) $m['is_internal'] === 1; }
    }
    return false;
})());

T::section('Quote → accept → invoice');
$svc->quote($rid, 1, 25000, 'Fixed-fee design + build as discussed.');
$row = Db::first('service_requests', ['id' => $rid]);
T::eq('status quoted', RequestStatus::QUOTED, $row['status']);
T::eq('quote amount stored', 25000, (int) $row['quote_minor']);
T::ok('client told quote is ready', Db::count('notifications', ['client_id' => 11, 'type' => 'request_update']) >= 1);
T::throws('accept other persons quote refused', function () use ($svc, $rid) {
    $svc->acceptQuote(22, $rid);
}, NotFoundException::class);
$before = count($gateway->callsTo('createInvoice'));
$svc->acceptQuote(11, $rid);
T::eq('invoice created once on accept', $before + 1, count($gateway->callsTo('createInvoice')));
$last = $gateway->callsTo('createInvoice')[0];
T::eq('invoice billed to requester', 11, $last['client']);
T::eq('invoice equals quote', 25000, (int) $last['items'][0]['amount_minor']);
T::eq('invoice currency USD', 'USD', $gateway->invoiceMeta[(int) $last['invoice']]['currency']);
T::eq('status accepted', RequestStatus::ACCEPTED, Db::first('service_requests', ['id' => $rid])['status']);
T::ok('invoice id stored on request', (function () use ($rid, $last) {
    return (int) Db::first('service_requests', ['id' => $rid])['invoice_id'] === (int) $last['invoice'];
})());
T::throws('accepting twice refused', function () use ($svc, $rid) {
    $svc->acceptQuote(11, $rid);
}, InvalidTransitionException::class);

T::section('Progress through to completion');
$svc->post($rid, 'admin', 1, 'Kicking off.', RequestStatus::IN_PROGRESS);
$svc->post($rid, 'admin', 1, 'Preview ready for feedback.', RequestStatus::DELIVERED);
$svc->post($rid, 'client', 11, 'Looks great, ship it.', RequestStatus::COMPLETED);
T::eq('completed ok', RequestStatus::COMPLETED, Db::first('service_requests', ['id' => $rid])['status']);
T::throws('closed request locked', function () use ($svc, $rid) {
    $svc->post($rid, 'client', 11, 'one more tweak?');
}, InvalidTransitionException::class);

T::section('Client cancellation path');
$r2 = $svc->create(22, [
    'type' => 'seo', 'title' => 'SEO sprint', 'brief' => str_repeat('Quarterly SEO for our niche store. ', 2),
]);
$svc->post((int) $r2['id'], 'client', 22, 'Actually — postponed.', RequestStatus::CANCELLED);
T::eq('cancelled by owner', RequestStatus::CANCELLED, Db::first('service_requests', ['id' => (int) $r2['id']])['status']);

T::section('Admin list + assignment');
$list = $svc->adminList();
T::ok('admin sees all', count($list) >= 2);
T::ok('open filter works', (function () use ($svc) {
    $open = $svc->adminList(RequestStatus::COMPLETED);
    return count($open) === 1 && $open[0]['status'] === 'completed';
})());
T::ok('assignment records assignee', (function () use ($svc, $rid) {
    $svc->adminAssign($rid, 1, 2);
    return (int) Db::first('service_requests', ['id' => $rid])['assignee_admin_id'] === 2;
})());

T::section('Kill switch');
T::throws('requests switch-off blocks new work', function () {
    Settings::override('requests_enabled', '0');
    try {
        (new ServiceRequestService())->create(33, [
            'type' => 'website_design', 'title' => 'T', 'brief' => str_repeat('b', 40)]);
    } finally {
        Settings::override('requests_enabled', null);
    }
}, \Chs\Core\ChsException::class);

T::finish();
