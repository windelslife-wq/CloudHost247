<?php
/**
 * Phase 4 — embedded AI Support Operator.
 *
 * The operator answers from public knowledge plus the live product catalog
 * and escalates everything else into real WHMCS tickets. The suite is an
 * isolation matrix:
 *
 *   - guests get a session-bound conversation they own, and nothing else;
 *   - one customer cannot read another customer's thread;
 *   - unknown questions escalate (AI_UNABLE_TO_ANSWER) instead of answered;
 *   - prices come only from visible catalog rows at answer time;
 *   - hidden products and unlisted items are never quoted;
 *   - newsletter works with and without the marketing module;
 *   - presence TTL is honoured; offline escalations still file tickets;
 *   - admin grants: ai.client.read to view, ai.manage to act;
 *   - rate limits and CSRF hold on the guest-capable page.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Csrf;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Migrator;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Whmcs;
use Ch247Ai\Http\AdminPortal;
use Ch247Ai\Http\CustomerPortal;
use Ch247Ai\SupportOperator\CatalogGrounding;
use Ch247Ai\SupportOperator\ConversationService;
use Ch247Ai\SupportOperator\EscalationService;
use Ch247Ai\SupportOperator\NewsletterBridge;
use Ch247Ai\SupportOperator\OperatorEngine;
use Ch247Ai\SupportOperator\PresenceService;

if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}

ch247ai_boot();
ch247ai_freeze();

Settings::override('support_operator_enabled', '1');
Settings::override('support_rate_max', '100');
Settings::override('support_rate_window', '300');
Settings::override('support_max_message', '2000');

$apiCalls = [];
Whmcs::setApiFake(function ($command, $args) use (&$apiCalls) {
    $apiCalls[] = ['cmd' => $command, 'args' => $args];
    if ($command === 'OpenTicket') {
        return ['result' => 'success', 'id' => 777];
    }
    return ['result' => 'success'];
});

function ch247ai_support_api_calls($cmd)
{
    global $apiCalls;
    $out = [];
    foreach ($apiCalls as $call) {
        if ($call['cmd'] === $cmd) {
            $out[] = $call['args'];
        }
    }
    return $out;
}

function ch247ai_audit_count($action, $entityId = null)
{
    $sql = 'SELECT COUNT(*) AS c FROM ' . Db::t('audit_log') . ' WHERE action = ?';
    $bind = [$action];
    if ($entityId !== null) {
        $sql .= ' AND entity_id = ?';
        $bind[] = $entityId;
    }
    $rows = Db::query($sql, $bind);
    return $rows ? (int) $rows[0]['c'] : 0;
}

// ---------------------------------------------------------------------------
T::section('Migration 0006: tables, seed knowledge, re-run safety');

foreach (['support_conversations', 'support_messages', 'support_presence', 'support_newsletter'] as $table) {
    T::ok("table {$table} exists", Db::tableExists($table));
}
$seeds = Db::query('SELECT * FROM ' . Db::t('knowledge_sources') . " WHERE tags = 'support-operator seed'");
T::eq('three seed docs installed', 3, count($seeds));
$allPublic = true;
foreach ($seeds as $seed) {
    if (($seed['visibility'] ?? '') !== 'public') {
        $allPublic = false;
    }
}
T::ok('every seed doc is public visibility', $allPublic);
$rerun = (new Migrator())->migrate();
T::ok('re-running migrations applies nothing', $rerun['applied'] === []);
$seedsAgain = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('knowledge_sources') . " WHERE tags = 'support-operator seed'");
T::eq('seed guard prevents duplicates on re-run', 3, (int) $seedsAgain[0]['c']);

// ---------------------------------------------------------------------------
T::section('Intent detection');

$intentCases = [
    'I want to talk to a human' => 'HUMAN_REQUEST',
    'please subscribe me to the newsletter' => 'NEWSLETTER_REQUEST',
    'I think my account was hacked' => 'SECURITY_REQUEST',
    'give me a refund right now' => 'REFUND_REQUEST',
    'this service is terrible, I want your manager' => 'COMPLAINT',
    'my server is down, the site is unreachable' => 'SERVER_INCIDENT',
    'my invoice is wrong' => 'BILLING_REQUEST',
    'change my hosting plan' => 'ACCOUNT_ACTION',
    'what is wrong with my domain' => 'ACCOUNT_REQUEST',
    'how much does Cloud Pro cost' => 'PRICE_REQUEST',
    'what are your support hours' => 'CONTACT_REQUEST',
    'hello there' => 'GREETING',
    'what can you do' => 'CAPABILITY',
    'quantum entanglement' => 'UNKNOWN',
];
foreach ($intentCases as $text => $expected) {
    T::eq("intent: {$text}", $expected, OperatorEngine::detectIntent($text));
}

// ---------------------------------------------------------------------------
T::section('Conversation ownership');

Db::query("INSERT INTO tblclients (id, firstname, lastname, email, status, datecreated) VALUES (900, 'Test', 'User', 'test900@example.com', 'Active', '2026-01-01')");
Db::query("INSERT INTO tblclients (id, firstname, lastname, email, status, datecreated) VALUES (901, 'Other', 'User', 'other901@example.com', 'Active', '2026-01-01')");

$guest = ConversationService::create(0, 'Guest One', 'guest1@example.com');
T::ok('guest conversation gets a 128-bit hex public id', (bool) preg_match('/^[a-f0-9]{32}$/', $guest['public_id']));
T::ok('create() claims the conversation in this session', in_array($guest['public_id'], ConversationService::sessionClaims(), true));
T::ok('claimed guest may read it', ConversationService::visibleTo($guest, ['client_id' => 0, 'session_convs' => [$guest['public_id']]]));
T::ok('unclaimed guest may not', !ConversationService::visibleTo($guest, ['client_id' => 0, 'session_convs' => []]));
T::ok('admin may read anything', ConversationService::visibleTo($guest, ['admin_id' => 1, 'session_convs' => []]));
T::ok('bad public id lookup returns null', ConversationService::findByPublic('not-a-real-id') === null);
T::ok('malformed public id lookup returns null', ConversationService::findByPublic('../support_conversations') === null);

$owned = ConversationService::create(900, 'Test User', 'test900@example.com');
T::ok('owning client may read it', ConversationService::visibleTo($owned, ['client_id' => 900, 'session_convs' => []]));
T::ok('another client may not', !ConversationService::visibleTo($owned, ['client_id' => 901, 'session_convs' => []]));
T::ok('guest holding someone else\'s id may not', !ConversationService::visibleTo($owned, ['client_id' => 0, 'session_convs' => [$guest['public_id']]]));

ConversationService::addMessage((int) $guest['id'], 'customer', 'hello there');
ConversationService::addMessage((int) $guest['id'], 'ai', 'Hi! How can I help?');
$guestFresh = ConversationService::find((int) $guest['id']);
T::eq('message count bumps', 2, (int) $guestFresh['message_count']);
$transcript = ConversationService::transcript((int) $guest['id']);
T::ok('transcript labels the customer', strpos($transcript, 'Customer: hello there') !== false);
T::ok('transcript labels the AI', strpos($transcript, 'AI Operator: Hi!') !== false);
T::ok('conversation start is audited', ch247ai_audit_count('ai.support.started', (int) $guest['id']) === 1);

// ---------------------------------------------------------------------------
T::section('Engine answers: greeting, catalog, contact, capabilities');

$clientCtx = ['client_id' => 900, 'actor' => 'client'];

$convGreet = ConversationService::create(900, 'Test User', 'test900@example.com');
$greet = OperatorEngine::handle($convGreet, 'hello', $clientCtx);
T::eq('greeting is answered', 'answered', $greet['action']);
T::ok('greeting identifies the operator', stripos($greet['reply'], 'AI Support Operator') !== false);
$convGreetFresh = ConversationService::find((int) $convGreet['id']);
T::eq('greeting invites newsletter once', 'newsletter_invited', $convGreetFresh['flow']);

$convPrice = ConversationService::create(900, 'Test User', 'test900@example.com');
$price = OperatorEngine::handle($convPrice, 'how much is Cloud Pro', $clientCtx);
T::eq('price question is answered', 'answered', $price['action']);
T::ok('live monthly price is quoted', strpos($price['reply'], '$18.00/mo') !== false);
T::ok('live annual price is quoted', strpos($price['reply'], '$180.00/yr') !== false);
T::ok('catalog citation attached', in_array('catalog:Cloud Pro', $price['citations'], true));
T::ok('the other plan is not quoted for a specific question', strpos($price['reply'], 'Cloud Starter') === false);

// Hidden products are never quoted, whatever the question names.
Db::query("INSERT INTO tblproducts (id, gid, type, name, hidden) VALUES (99, 1, 'hostingaccount', 'Cloud Secret', 1)");
Db::query("INSERT INTO tblpricing (id, type, currency, relid, monthly, annually) VALUES (99, 'product', 1, 99, '9.99', '99.99')");
$convHidden = ConversationService::create(900, 'Test User', 'test900@example.com');
$hidden = OperatorEngine::handle($convHidden, 'how much is Cloud Secret', $clientCtx);
T::eq('hidden product is never quoted', 'escalated', $hidden['action']);
T::ok('hidden price never leaks into the reply', strpos($hidden['reply'], '9.99') === false);

$convBare = ConversationService::create(900, 'Test User', 'test900@example.com');
$bare = OperatorEngine::handle($convBare, 'how much?', $clientCtx);
T::eq('bare price question escalates, never guesses', 'escalated', $bare['action']);

$convContact = ConversationService::create(900, 'Test User', 'test900@example.com');
$contact = OperatorEngine::handle($convContact, 'what are your support hours', $clientCtx);
T::eq('contact question is answered from knowledge', 'answered', $contact['action']);
T::ok('contact answer cites knowledge', (bool) preg_grep('/^knowledge:/', $contact['citations']));

$convCap = ConversationService::create(900, 'Test User', 'test900@example.com');
$cap = OperatorEngine::handle($convCap, 'what can you do', $clientCtx);
T::eq('capability question is answered', 'answered', $cap['action']);
T::ok('capabilities are honest about limits', stripos($cap['reply'], 'cannot see') !== false);

// ---------------------------------------------------------------------------
T::section('No fabrication: unknown questions escalate with a transcript');

$convUnknown = ConversationService::create(0, 'No Fab', 'nofab@example.com');
$ticketsBefore = count(ch247ai_support_api_calls('OpenTicket'));
$unknown = OperatorEngine::handle($convUnknown, 'do you offer teleportation hosting on mars', ['client_id' => 0, 'actor' => 'guest']);
T::eq('unknown question escalates', 'escalated', $unknown['action']);
T::eq('escalation reason is AI_UNABLE_TO_ANSWER', 'AI_UNABLE_TO_ANSWER', $unknown['escalation_reason']);
$convUnknownFresh = ConversationService::find((int) $convUnknown['id']);
T::eq('status flips to waiting_for_human', 'waiting_for_human', $convUnknownFresh['status']);
T::eq('offline escalation files a ticket', $ticketsBefore + 1, count(ch247ai_support_api_calls('OpenTicket')));
$tickets = ch247ai_support_api_calls('OpenTicket');
$ticketArgs = end($tickets);
T::ok('ticket carries the transcript', strpos($ticketArgs['message'], 'teleportation') !== false && strpos($ticketArgs['message'], 'Transcript') !== false);
T::ok('guest ticket uses name+email, not a userid', !isset($ticketArgs['userid']) && $ticketArgs['email'] === 'nofab@example.com');
T::ok('visitor is told the ticket number', strpos($unknown['reply'], 'ticket #777') !== false);
T::ok('escalation is audited', ch247ai_audit_count('ai.support.escalated', (int) $convUnknown['id']) === 1);

// ---------------------------------------------------------------------------
T::section('Newsletter flow with local fallback');

$convNews = ConversationService::create(0, 'News Guest', 'news@example.com');
$step1 = OperatorEngine::handle($convNews, 'I want to subscribe to the newsletter', ['client_id' => 0, 'actor' => 'guest']);
T::eq('subscribe request starts the flow', 'newsletter', $step1['action']);
$convNews = ConversationService::find((int) $convNews['id']);
T::ok('known guest skips the name step', $convNews['flow'] === 'newsletter_email');
$stepBad = OperatorEngine::handle($convNews, 'not-an-email', ['client_id' => 0, 'actor' => 'guest']);
T::ok('bad email is rejected, flow stays', strpos($stepBad['reply'], 'valid email') !== false);
$convNews = ConversationService::find((int) $convNews['id']);
T::eq('still collecting email', 'newsletter_email', $convNews['flow']);
OperatorEngine::handle($convNews, 'ada@example.com', ['client_id' => 0, 'actor' => 'guest']);
$convNews = ConversationService::find((int) $convNews['id']);
T::eq('email step moves to confirm', 'newsletter_confirm', $convNews['flow']);
$confirmed = OperatorEngine::handle($convNews, 'yes', ['client_id' => 0, 'actor' => 'guest']);
T::eq('confirm subscribes', 'newsletter', $confirmed['action']);
T::ok('welcome reply sent', stripos($confirmed['reply'], 'subscribed') !== false);
$sub = Db::first('support_newsletter', ['email' => 'ada@example.com']);
T::ok('local fallback row stored', $sub !== null && $sub['source'] === 'ai_assistant');
T::ok('subscription is audited', ch247ai_audit_count('ai.support.newsletter_subscribed', (int) $convNews['id']) === 1);

// Duplicates do not error and do not double-store.
$convDup = ConversationService::create(0, 'Ada Clone', 'ada@example.com');
OperatorEngine::handle($convDup, 'subscribe me to the newsletter', ['client_id' => 0, 'actor' => 'guest']);
$convDup = ConversationService::find((int) $convDup['id']);
OperatorEngine::handle($convDup, 'ada@example.com', ['client_id' => 0, 'actor' => 'guest']);
$convDup = ConversationService::find((int) $convDup['id']);
OperatorEngine::handle($convDup, 'yes please', ['client_id' => 0, 'actor' => 'guest']);
T::eq('duplicate email stored once', 1, Db::count('support_newsletter', ['email' => 'ada@example.com']));
$dupMessages = ConversationService::messages((int) $convDup['id']);
$dupLast = end($dupMessages);
T::eq('duplicate flagged in message meta', 'duplicate', $dupLast['meta_decoded']['newsletter']);

// Marketing delegation when the module exists.
T::ok('marketing bridge starts unavailable', !NewsletterBridge::marketingAvailable());
require_once __DIR__ . '/stubs/MarketingStub.php';
T::ok('marketing bridge detects the module', NewsletterBridge::marketingAvailable());
\Ch247Mkt\Audience\SubscriberService::reset();
NewsletterBridge::subscribe('Bob', 'bob@example.com');
T::ok('subscription mirrored to marketing', count(\Ch247Mkt\Audience\SubscriberService::$calls) === 1
    && \Ch247Mkt\Audience\SubscriberService::$calls[0]['data']['email'] === 'bob@example.com'
    && \Ch247Mkt\Audience\SubscriberService::$calls[0]['data']['source'] === 'ai_assistant');
T::ok('local record still kept alongside the mirror', Db::first('support_newsletter', ['email' => 'bob@example.com']) !== null);
T::eq('invalid email rejected', 'invalid', NewsletterBridge::subscribe('Nope', 'not-an-email')['status']);
T::ok('unsubscribe flips status', NewsletterBridge::unsubscribe('bob@example.com')
    && Db::first('support_newsletter', ['email' => 'bob@example.com'])['status'] === 'unsubscribed');

// ---------------------------------------------------------------------------
T::section('Escalation: contact capture, online handoff, agent replies');

$guestCtx = ['client_id' => 0, 'actor' => 'guest'];
$convContact0 = ConversationService::create(0, '', '');
$needContact = OperatorEngine::handle($convContact0, 'my invoice is wrong', $guestCtx);
T::eq('unknown guest is asked for contact first', 'escalated', $needContact['action']);
$convContact0 = ConversationService::find((int) $convContact0['id']);
T::eq('escalation waits for contact', 'offline_contact', $convContact0['flow']);
T::eq('status stays ai_active until contact lands', 'ai_active', $convContact0['status']);
$ticketsBefore2 = count(ch247ai_support_api_calls('OpenTicket'));
$filed = OperatorEngine::handle($convContact0, 'Jane jane@example.com', $guestCtx);
$convContact0 = ConversationService::find((int) $convContact0['id']);
T::eq('contact capture completes the escalation', $ticketsBefore2 + 1, count(ch247ai_support_api_calls('OpenTicket')));
T::eq('ticket linked on the conversation', 777, (int) $convContact0['ticket_id']);
T::eq('contact stored', 'jane@example.com', $convContact0['guest_email']);
T::ok('visitor hears the ticket number', strpos($filed['reply'], 'ticket #777') !== false);

PresenceService::heartbeat(2, 'online');
$avail = PresenceService::availability();
T::eq('heartbeat makes support online', 'online', $avail['status']);
T::ok('online agent listed', $avail['agents'] === [2]);

$convHuman = ConversationService::create(0, 'On Line', 'online@example.com');
$human = OperatorEngine::handle($convHuman, 'let me talk to a human please', $guestCtx);
T::eq('human request escalates', 'escalated', $human['action']);
T::eq('reason is USER_REQUESTED_HUMAN', 'USER_REQUESTED_HUMAN', $human['escalation_reason']);
$convHuman = ConversationService::find((int) $convHuman['id']);
T::eq('online escalation assigns the agent', 2, (int) $convHuman['assigned_admin_id']);
T::ok('online visitor is told an agent is coming', strpos($human['reply'], 'reply here shortly') !== false);
T::ok('online escalation notifies admins', count(ch247ai_support_api_calls('SendAdminEmail')) >= 1);

T::ok('agent reply stored', EscalationService::agentReply((int) $convHuman['id'], 2, 'Hello from support, looking into it.'));
$convHuman = ConversationService::find((int) $convHuman['id']);
T::eq('agent reply marks human_active', 'human_active', $convHuman['status']);
$notes = ch247ai_support_api_calls('AddTicketNote');
T::ok('agent reply mirrored to the ticket', count($notes) >= 1 && (int) $notes[0]['ticketid'] === 777);
T::ok('agent reply audited', ch247ai_audit_count('ai.support.agent_reply', (int) $convHuman['id']) === 1);

$whileHuman = OperatorEngine::handle($convHuman, 'thanks, still here', $guestCtx);
T::eq('messages during handoff are queued, not AI-answered', 'notice', $whileHuman['action']);

// ---------------------------------------------------------------------------
T::section('Presence TTL');

Db::query("UPDATE " . Db::t('support_presence') . " SET updated_at = '2020-01-01 00:00:00'");
T::eq('stale heartbeats read offline', 'offline', PresenceService::availability()['status']);
T::eq('no online admin when all heartbeats are stale', 0, PresenceService::recentOnlineAdmin());

// ---------------------------------------------------------------------------
T::section('Rate limiting');

Settings::override('support_rate_max', '2');
$rateCtx = ['client_id' => 901, 'actor' => 'client'];
$convR1 = ConversationService::create(901, 'Other User', 'other901@example.com');
$convR2 = ConversationService::create(901, 'Other User', 'other901@example.com');
$convR3 = ConversationService::create(901, 'Other User', 'other901@example.com');
T::eq('first message allowed', 'answered', OperatorEngine::handle($convR1, 'hello', $rateCtx)['action']);
T::eq('second message allowed', 'answered', OperatorEngine::handle($convR2, 'hello', $rateCtx)['action']);
$blocked = OperatorEngine::handle($convR3, 'hello', $rateCtx);
T::eq('third message blocked', 'notice', $blocked['action']);
T::ok('block message is honest', strpos($blocked['reply'], 'too quickly') !== false);
T::eq('blocked message is not stored', 0, (int) ConversationService::find((int) $convR3['id'])['message_count']);
Settings::override('support_rate_max', '100');

// ---------------------------------------------------------------------------
T::section('Customer page: guests, CSRF, isolation, reopen');

function ch247ai_support_dispatch(array $get, array $post, $method = 'GET')
{
    $_GET = $get;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    $out = (new CustomerPortal())->dispatch(['modulelink' => 'index.php?m=cloudhost247ai']);
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    return $out;
}

Settings::override('support_operator_enabled', '0');
$off = ch247ai_support_dispatch(['action' => 'support'], []);
T::ok('disabled operator shows the unavailable page', strpos($off['templatefile'], 'unavailable') !== false);
Settings::override('support_operator_enabled', '1');

$_SESSION = [];
$start = ch247ai_support_dispatch(['action' => 'support'], [
    'ch247ai_csrf' => Csrf::token(),
    'ch247ai_support' => 'start',
    'name' => 'Portal Guest',
    'email' => 'portal@example.com',
    'message' => 'hello',
], 'POST');
T::eq('guest start renders the chat', 'templates/client/support', $start['templatefile']);
T::ok('guest chat needs no login', $start['requirelogin'] === false);
$portalConv = $start['templatevariables']['conversation'];
T::ok('conversation created for the guest', is_array($portalConv) && (bool) preg_match('/^[a-f0-9]{32}$/', $portalConv['public_id']));
T::ok('first exchange rendered', count($start['templatevariables']['messages']) >= 2);
$portalPub = $portalConv['public_id'];

$csrfFail = ch247ai_support_dispatch(['action' => 'support'], [
    'ch247ai_csrf' => 'bogus',
    'ch247ai_support' => 'message',
    'c' => $portalPub,
    'message' => 'hi again',
], 'POST');
T::ok('bad CSRF token rejected', strpos((string) $csrfFail['templatevariables']['error'], 'session expired') !== false);

$_SESSION[ConversationService::SESSION_KEY] = [];
$cleared = ch247ai_support_dispatch(['action' => 'support', 'c' => $portalPub], []);
T::ok('guest without the claim is turned away', strpos((string) $cleared['templatevariables']['error'], 'another session') !== false);

Identity::setClient(901);
$otherClient = ch247ai_support_dispatch(['action' => 'support', 'c' => $portalPub], []);
T::ok('another client cannot open the thread', strpos((string) $otherClient['templatevariables']['error'], 'another session') !== false);
Identity::setClient(null);

$convReopen = ConversationService::create(0, 'Re Open', 'reopen@example.com');
OperatorEngine::handle($convReopen, 'hello', $guestCtx);
ConversationService::setStatus((int) $convReopen['id'], 'resolved');
$convReopen = ConversationService::find((int) $convReopen['id']);
OperatorEngine::handle($convReopen, 'hello again', $guestCtx);
T::eq('visitor message reopens a resolved thread', 'ai_active', ConversationService::find((int) $convReopen['id'])['status']);
T::ok('reopen is audited', ch247ai_audit_count('ai.support.reopened', (int) $convReopen['id']) === 1);

// ---------------------------------------------------------------------------
T::section('Admin: conversations, replies, presence, grants');

function ch247ai_support_admin(array $get, array $post, $method = 'GET')
{
    $_GET = $get;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    $html = (new AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    return $html;
}

Identity::setAdmin(1);
$_SESSION['adminid'] = 1;

$adminList = ch247ai_support_admin(['action' => 'support'], []);
T::ok('admin conversation list renders', strpos($adminList, 'AI Support Operator') !== false && strpos($adminList, 'portal@example.com') !== false);
$adminView = ch247ai_support_admin(['action' => 'support-view', 'id' => (int) $convHuman['id']], []);
T::ok('admin thread view renders with reply form', strpos($adminView, 'Reply as support agent') !== false && strpos($adminView, 'On Line') !== false);
$adminNews = ch247ai_support_admin(['action' => 'support-newsletter'], []);
T::ok('admin newsletter list renders', strpos($adminNews, 'Newsletter subscriptions') !== false && strpos($adminNews, 'ada@example.com') !== false);

$replyPost = ch247ai_support_admin(['action' => 'support-view', 'id' => (int) $convHuman['id']], [
    'Ch247AiCsrf' => Csrf::token(),
    'ch247ai_action' => 'support_reply',
    'id' => (int) $convHuman['id'],
    'reply' => 'A second reply from the test.',
], 'POST');
T::ok('admin reply succeeds', strpos($replyPost, 'Reply sent to the visitor') !== false);
$threadAfter = ConversationService::messages((int) $convHuman['id']);
$lastMsg = end($threadAfter);
T::ok('reply appended as agent', $lastMsg['author'] === 'agent' && strpos($lastMsg['body'], 'second reply') !== false);

$presencePost = ch247ai_support_admin(['action' => 'support'], [
    'Ch247AiCsrf' => Csrf::token(),
    'ch247ai_action' => 'support_presence',
    'status' => 'busy',
], 'POST');
T::ok('presence heartbeat succeeds', strpos($presencePost, 'Presence set to busy') !== false);
T::eq('availability follows the heartbeat', 'busy', PresenceService::availability()['status']);

$statusPost = ch247ai_support_admin(['action' => 'support-view', 'id' => (int) $convHuman['id']], [
    'Ch247AiCsrf' => Csrf::token(),
    'ch247ai_action' => 'support_status',
    'id' => (int) $convHuman['id'],
    'to' => 'resolved',
], 'POST');
T::ok('resolve succeeds', strpos($statusPost, 'Conversation updated') !== false);
T::eq('status persisted', 'resolved', ConversationService::find((int) $convHuman['id'])['status']);

// Least privilege: support role without grants sees nothing and changes nothing.
Identity::setAdmin(2);
$_SESSION['adminid'] = 2;
Rbac::setForcedRole(2, 2);
$deniedView = ch247ai_support_admin(['action' => 'support'], []);
T::ok('viewing needs ai.client.read', strpos($deniedView, 'ai.client.read') !== false);
$deniedPost = ch247ai_support_admin(['action' => 'support-view', 'id' => (int) $convHuman['id']], [
    'Ch247AiCsrf' => Csrf::token(),
    'ch247ai_action' => 'support_reply',
    'id' => (int) $convHuman['id'],
    'reply' => 'should not land',
], 'POST');
T::ok('replying needs ai.manage', strpos($deniedPost, 'AI manage permission') !== false);
Rbac::setForcedRole(2, null);
Identity::setAdmin(null);
unset($_SESSION['adminid']);

// ---------------------------------------------------------------------------
T::section('Settings keys and audit chain');

foreach (['support_operator_enabled', 'support_ticket_dept', 'support_presence_ttl', 'support_rate_max', 'support_rate_window', 'support_max_message', 'support_widget_enabled'] as $key) {
    T::ok("setting default exists: {$key}", array_key_exists($key, Settings::DEFAULTS));
}
$chain = Audit::verifyChain();
T::ok('audit hash chain verifies after the operator suite', $chain['valid'] === true);

Identity::setClient(null);
Identity::setAdmin(null);
T::finish();
