<?php
/**
 * Copilot XHR endpoint (admin area only in Phase 1).
 *
 * POST JSON {question, csrf}
 * Responses: {status: ok|refused|error, output, citations[], run_id, tool_calls}
 *
 * Fail-closed rules: WHMCS admin session required, ai.read permission group
 * required, CSRF verified, rate limited, no model call without a configured
 * provider (CONFIGURATION_REQUIRED), no answer without collected evidence.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Csrf;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\RateLimiter;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\Settings;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$respond = function (array $payload, $httpStatus = 200) {
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(['status' => 'error', 'error' => 'POST required'], 405);
}

// Session + authority (fail closed).
$adminId = Identity::adminId();
if (!$adminId) {
    $respond(['status' => 'error', 'error' => 'Admin session required.'], 401);
}
if (Identity::isMasquerading()) {
    $respond(['status' => 'error', 'error' => 'AI tools are unavailable while masquerading as a client.'], 403);
}
if (!Settings::bool('service_enabled', true) || Settings::bool('kill_switch', false)) {
    $respond(['status' => 'refused', 'output' => 'The AI service is currently disabled by the operator.']);
}
if (!Settings::bool('copilot_enabled', true)) {
    $respond(['status' => 'refused', 'output' => 'The admin copilot is disabled in settings.']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $respond(['status' => 'error', 'error' => 'Invalid JSON body.'], 400);
}
$question = trim((string) ($body['question'] ?? ''));
if ($question === '' || strlen($question) > 4000) {
    $respond(['status' => 'error', 'error' => 'A question between 1 and 4000 characters is required.'], 422);
}

// CSRF: module token (the admin UI embeds it in the request body/header).
$token = (string) ($body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!Csrf::verify($token)) {
    $respond(['status' => 'error', 'error' => 'Invalid or expired security token. Reload the page.'], 403);
}

// Authority: the acting admin must hold the AI read group.
if (!Rbac::adminCan(Rbac::AI_READ)) {
    Audit::admin((int) $adminId, 'ai.copilot.denied', ['reason' => 'missing ai.read group']);
    $respond(['status' => 'error', 'error' => 'You do not have permission to use the AI copilot.'], 403);
}

// Rate limit: 20 questions / 5 minutes per admin.
try {
    RateLimiter::hitOrFail('copilot', RateLimiter::bucketForAdmin((int) $adminId), 20, 300);
} catch (\Ch247Ai\Core\RateLimitException $e) {
    $respond(['status' => 'error', 'error' => 'Too many questions. Wait a few minutes and try again.'], 429);
}

ToolBootstrap::register();

try {
    $result = AgentRuntime::run('admin_copilot', $question, [
        'source' => AgentRuntime::SOURCE_INTERACTIVE,
        'actor_type' => 'admin',
        'actor_id' => (int) $adminId,
        'profile' => 'fast',
    ]);
    $respond([
        'status' => $result['status'],
        'output' => $result['output'],
        'citations' => array_slice($result['citations'], 0, 10),
        'run_id' => $result['run_id'],
        'tool_calls' => $result['tool_calls'],
    ]);
} catch (\Ch247Ai\Core\ProviderNotConfiguredException $e) {
    $respond([
        'status' => 'configuration_required',
        'output' => $e->getMessage(),
        'citations' => [],
        'run_id' => 0,
        'tool_calls' => 0,
    ]);
} catch (\Ch247Ai\Core\RateLimitException $e) {
    $respond(['status' => 'error', 'error' => 'Budget or rate limit reached. ' . $e->getMessage()], 429);
} catch (\Ch247Ai\Core\BudgetExceededException $e) {
    $respond(['status' => 'budget_exceeded', 'output' => $e->getMessage()], 200);
} catch (\Throwable $e) {
    Audit::admin((int) $adminId, 'ai.copilot.error', ['reason' => get_class($e)]);
    $respond(['status' => 'error', 'error' => 'The copilot could not complete this request. It has been logged.'], 500);
}
