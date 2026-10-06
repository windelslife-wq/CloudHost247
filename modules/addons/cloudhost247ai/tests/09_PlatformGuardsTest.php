<?php
/** Platform guards: settings layering, secrets policy, rate limits, CSRF, redaction, providers. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Csrf;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\RateLimiter;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Core\Settings;
use Ch247Ai\Model\ModelRouter;
use Ch247Ai\Model\NullProvider;

ch247ai_boot();
ch247ai_freeze();

T::section('Settings: defaults, layering, env override');
T::eq('default max tool calls', '5', Settings::string('max_tool_calls_per_run'));
T::eq('default kill switch off', '0', Settings::string('kill_switch'));
T::eq('default pii redaction on', '1', Settings::string('redact_pii'));
Settings::put('max_tool_calls_per_run', '9');
T::eq('db value wins over default', '9', Settings::string('max_tool_calls_per_run'));
putenv('CH247AI_MAX_TOOL_CALLS_PER_RUN=3');
T::eq('env beats db', '3', Settings::string('max_tool_calls_per_run'));
putenv('CH247AI_MAX_TOOL_CALLS_PER_RUN');
T::eq('unknown keys rejected', true, (function () {
    try {
        Settings::put('made_up_key', '1');
        return false;
    } catch (\Throwable $e) {
        return true;
    }
})());

T::section('Secrets are env-only');
T::throws('api_key cannot be stored in db', function () {
    Settings::put('api_key', 'sk-123');
}, \Ch247Ai\Core\Ch247AiException::class);
T::eq('no secret columns exist in the settings table', 0, Db::count('settings', ['setting' => 'api_key']));

T::section('WHMCS addon-module config is honoured (master toggle)');
Db::exec("INSERT INTO tbladdonmodules (module, setting, value) VALUES ('cloudhost247ai', 'service_enabled', '')");
Settings::resetOverrides(); // drop the per-request cache so the row is re-read
T::ok('WHMCS checkbox off disables the service', !Settings::bool('service_enabled', true));
Db::exec("UPDATE tbladdonmodules SET value = 'on' WHERE module = 'cloudhost247ai' AND setting = 'service_enabled'");
Settings::resetOverrides();
T::ok('WHMCS checkbox on enables the service', Settings::bool('service_enabled', true));
Settings::put('service_enabled', '0');
T::ok('module settings page overrides the WHMCS checkbox', !Settings::bool('service_enabled', true));
Settings::put('service_enabled', '1');

T::section('Provider fail-closed');
$provider = ModelRouter::forProfile('fast');
T::ok('no provider configured returns NullProvider', $provider instanceof NullProvider);
T::ok('not configured', !$provider->isConfigured());
T::throws('completion names CONFIGURATION_REQUIRED', function () use ($provider) {
    $provider->complete([['role' => 'user', 'content' => 'hi']]);
}, \Ch247Ai\Core\ProviderNotConfiguredException::class);
T::ok('copilotConfigured false', !ModelRouter::copilotConfigured());
T::throws('unknown profile rejected', function () {
    ModelRouter::forProfile('turbo');
}, \Ch247Ai\Core\Ch247AiException::class);
Settings::put('model_fast_endpoint', 'https://models.internal.test/v1');
Settings::put('model_fast_model', 'llama-3.1-8b');
$provider = ModelRouter::forProfile('fast');
T::ok('configured provider returned', $provider->isConfigured());
// reasoning falls back to fast when only fast is set
$provider = ModelRouter::forProfile('reasoning');
T::ok('reasoning falls back to fast profile', $provider->isConfigured());
Settings::put('kill_switch', '1');
T::ok('kill switch forces NullProvider', ModelRouter::forProfile('fast') instanceof NullProvider);
Settings::put('kill_switch', '0');

T::section('OpenAI-compatible provider');
$posted = null;
$provider = new \Ch247Ai\Model\OpenAiCompatibleProvider('https://models.internal.test/v1', 'test-model', function ($url, $headers, $body, $timeout) use (&$posted) {
    $posted = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => ' grounded answer ']]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]])];
});
putenv('CH247AI_API_KEY=sk-test-key-material');
$reply = $provider->complete([['role' => 'user', 'content' => 'q']]);
T::eq('content trimmed and returned', 'grounded answer', $reply['content']);
T::eq('tokens parsed', 11, $reply['tokens_in']);
T::eq('url targets chat/completions', 'https://models.internal.test/v1/chat/completions', $posted['url']);
T::ok('bearer key sent from env', in_array('Authorization: Bearer sk-test-key-material', $posted['headers'], true));
T::eq('model in payload', 'test-model', $posted['body']['model']);
T::ok('low temperature default', isset($posted['body']['temperature']) && $posted['body']['temperature'] < 0.5);
putenv('CH247AI_API_KEY');
$error = new \Ch247Ai\Model\OpenAiCompatibleProvider('https://models.internal.test/v1', 'test-model', function () {
    return ['code' => 500, 'body' => 'oops'];
});
T::throws('HTTP 500 becomes ProviderException', function () use ($error) {
    $error->complete([['role' => 'user', 'content' => 'q']]);
}, \Ch247Ai\Core\ProviderException::class);
$empty = new \Ch247Ai\Model\OpenAiCompatibleProvider('https://models.internal.test/v1', 'test-model', function () {
    return ['code' => 200, 'body' => json_encode(['choices' => []])];
});
T::throws('empty completion becomes ProviderException', function () use ($empty) {
    $empty->complete([['role' => 'user', 'content' => 'q']]);
}, \Ch247Ai\Core\ProviderException::class);

T::section('Rate limiter (sliding window)');
$bucket = 'admin:1';
for ($i = 0; $i < 3; $i++) {
    T::ok("hit {$i} allowed", RateLimiter::hit('copilot', $bucket, 3, 60));
}
T::ok('fourth hit blocked', !RateLimiter::hit('copilot', $bucket, 3, 60));
T::throws('hitOrFail throws on limit', function () use ($bucket) {
    RateLimiter::hitOrFail('copilot', $bucket, 3, 60);
}, \Ch247Ai\Core\RateLimitException::class);
Clock::freeze(Clock::time() + 61);
T::ok('window reset allows again', RateLimiter::hit('copilot', $bucket, 3, 60));

T::section('CSRF');
$_SESSION = [];
$token = Csrf::token();
T::ok('token issued', strlen($token) === 32);
T::ok('valid token accepted', Csrf::verify($token));
T::ok('wrong token rejected', !Csrf::verify('nope'));
$_POST = [Csrf::KEY => $token];
T::ok('verifyRequest passes with token', (function () {
    try {
        Csrf::verifyRequest();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
})());
$_POST = [];
T::throws('verifyRequest fails without token', function () {
    Csrf::verifyRequest();
}, \Ch247Ai\Core\ForbiddenException::class);
$field = Csrf::field();
T::ok('field escapes the token', strpos($field, 'value="' . $token . '"') !== false);

T::section('Redaction');
$dirty = ['password' => 'hunter2', 'api_key' => 'sk-1', 'note' => 'Bearer abcdef123456789012', 'email' => 'person@example.test', 'card' => '4111 1111 1111 1111', 'keep' => 'plain'];
$clean = Redaction::clean($dirty);
T::eq('password redacted', '[redacted]', $clean['password']);
T::eq('api_key redacted', '[redacted]', $clean['api_key']);
T::ok('bearer token redacted', strpos($clean['note'], 'abcdef123456789012') === false);
T::ok('card number redacted', strpos($clean['card'], '4111') === false);
T::ok('email masked by default', strpos($clean['email'], '***@') !== false && strpos($clean['email'], 'person') === false);
T::ok('plain values kept', $clean['keep'] === 'plain');
Settings::put('expose_pii', '1');
$clean = Redaction::clean(['email' => 'person@example.test']);
T::eq('pii exposure is an explicit operator decision', 'person@example.test', $clean['email']);
Settings::put('expose_pii', '0');
T::ok('digest stable', Redaction::digest('x') === Redaction::digest('x'));

T::section('Db layer guards');
T::throws('WHERE-less UPDATE refused', function () {
    Db::update('settings', [], ['value' => 'x']);
}, \Ch247Ai\Core\Ch247AiException::class);
T::throws('WHERE-less DELETE refused', function () {
    Db::delete('settings', []);
}, \Ch247Ai\Core\Ch247AiException::class);
T::throws('bad identifier refused', function () {
    Db::t('agents; DROP TABLE x');
}, \Ch247Ai\Core\Ch247AiException::class);
T::throws('unsafe ORDER BY refused', function () {
    Db::all('settings', [], 'id DESC; DROP TABLE y', 1);
}, \Ch247Ai\Core\Ch247AiException::class);

T::section('WHMCS seam fails closed outside WHMCS');
\Ch247Ai\Core\Whmcs::setApiFake(null);
T::throws('localAPI unavailable throws ServiceUnavailable', function () {
    \Ch247Ai\Core\Whmcs::api('GetClients', []);
}, \Ch247Ai\Core\ServiceUnavailableException::class);

T::finish();
