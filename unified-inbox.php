<?php
/**
 * CLOUDHOST247 — Unified Inbox landing.
 */

use Chs\Http\Landing;
use Chs\Services\InboxService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-unified-inbox', 'Unified Inbox', 'unified-inbox.php');

Landing::seo([
    'title'       => 'Unified Inbox — every support conversation in one thread view | CLOUDHOST247',
    'description' => 'Unread badges computed against what you last saw, search, reply without leaving the '
        . 'dashboard, and every channel listed with its live/offline status shown honestly.',
    'canonical'   => 'unified-inbox.php',
    'og_title'    => 'Unified Inbox — CLOUDHOST247',
    'og_desc'     => 'Your support threads, searchable and unread-aware.',
]);

$vars = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-unified-inbox', Landing::unavailableVars('The unified inbox'));
    exit;
}

$channels = [];
try {
    $channels = (new InboxService())->channels();
} catch (\Throwable $e) {
    $channels = [];
}

$vars['chsChannels'] = $channels;
$vars['chsInboxUrl'] = 'index.php?m=cloudhost247services&action=inbox';
$vars['chsLoggedIn'] = \Chs\Core\Identity::clientId() !== null;
$vars['chsDashboardUrl'] = 'index.php?m=cloudhost247services';
Landing::render($ca, 'chs-unified-inbox', $vars);
