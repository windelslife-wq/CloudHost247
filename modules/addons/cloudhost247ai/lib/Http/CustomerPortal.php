<?php
/**
 * The customer-facing AI surface (brief §17 + §33).
 *
 * Two pages, reached through the normal WHMCS client area at
 * index.php?m=cloudhost247ai:
 *
 *   assistant  ask a question about your own account
 *   activity   see every AI run that touched your account, and what it read
 *
 * Design decisions worth stating, because they are the security model:
 *
 *  1. NO NEW PUBLIC ENDPOINT. The question is posted to this page and
 *     handled server-side. The admin copilot's XHR endpoint (api.php) is
 *     left strictly admin-only — widening it to also accept customer
 *     sessions would put two different authority models behind one door,
 *     which is how privilege bugs happen.
 *
 *  2. ISOLATION IS NOT THE PROMPT'S JOB. The assistant runs under a client
 *     session, so ToolExecutor restricts it to client-bound READ tools and
 *     the readers force `userid = <this client>` into the SQL. A customer
 *     who asks "show me invoice 999" gets their own rows or nothing,
 *     regardless of what the model decides to call. The system prompt tells
 *     the model it is scoped to one account; the gate is what enforces it.
 *
 *  3. NO WRITES, STRUCTURALLY. ToolExecutor::actorMay() refuses any non-READ
 *     tool in client scope, so a customer cannot reply to their own ticket,
 *     pay an invoice or change a service through the assistant even if an
 *     operator granted the agent a write tool by mistake.
 *
 *  4. FAIL CLOSED AND SAY SO. Not signed in, feature off, kill switch,
 *     no model configured, rate limited — each produces a plain explanation,
 *     never an invented answer.
 *
 *  5. §33 TRANSPARENCY. The activity page shows the customer what the AI did
 *     on their account, including the tool calls behind each answer. It
 *     reads only their own runs.
 */

namespace Ch247Ai\Http;

use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Core\Audit;
use Ch247Ai\Core\BudgetExceededException;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Csrf;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\ProviderNotConfiguredException;
use Ch247Ai\Core\RateLimiter;
use Ch247Ai\Core\RateLimitException;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Validator;
use Ch247Ai\SupportOperator\ConversationService;
use Ch247Ai\SupportOperator\OperatorEngine;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;

class CustomerPortal
{
    const AGENT = 'customer_assistant';
    const MAX_QUESTION = 2000;

    /** Questions per client per window. */
    const RATE_MAX = 10;
    const RATE_WINDOW = 300;

    /** @return array WHMCS clientarea response */
    public function dispatch($vars)
    {
        $action = isset($_GET['action']) ? (string) $_GET['action'] : 'assistant';
        if (!preg_match('/^[a-z_]+$/', $action)) {
            $action = 'assistant';
        }

        // The AI Support Operator serves guests as well as clients, so it
        // is routed before the login gate. It never shows account records:
        // guests and clients alike get public knowledge, live catalog
        // prices, and human escalation.
        if ($action === 'support') {
            return $this->dispatchSupport();
        }

        $clientId = Identity::clientId();
        if (!$clientId) {
            return $this->page('Sign in required', 'login_required', []);
        }

        // Operator switches, checked before anything touches the model.
        if (!Settings::bool('service_enabled', true) || Settings::bool('kill_switch', false)) {
            return $this->page('Assistant unavailable', 'unavailable', [
                'reason' => 'The AI assistant is currently switched off by the provider.',
            ]);
        }
        if (!Settings::bool('client_assistant_enabled', false)) {
            return $this->page('Assistant unavailable', 'unavailable', [
                'reason' => 'The account assistant is not enabled on this platform.',
            ]);
        }

        try {
            if ($action === 'activity') {
                return $this->pageActivity((int) $clientId);
            }
            return $this->pageAssistant((int) $clientId);
        } catch (\Throwable $e) {
            // Never leak an exception message to a customer.
            return $this->page('Something went wrong', 'unavailable', [
                'reason' => 'The assistant could not load. The problem has been logged.',
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Assistant
    // -----------------------------------------------------------------

    protected function pageAssistant($clientId)
    {
        $answer = null;
        $error = null;
        $citations = [];
        $question = '';
        $runId = 0;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ch247ai_question'])) {
            $question = trim((string) $_POST['ch247ai_question']);
            $result = $this->ask($clientId, $question);
            $answer = $result['answer'];
            $error = $result['error'];
            $citations = $result['citations'];
            $runId = $result['run_id'];
        }

        return $this->page('Account assistant', 'assistant', [
            'question' => $question,
            'answer' => $answer,
            'error' => $error,
            'citations' => $citations,
            'run_id' => $runId,
            'rate_max' => self::RATE_MAX,
            'rate_minutes' => (int) (self::RATE_WINDOW / 60),
            'max_question' => self::MAX_QUESTION,
            'recent' => $this->recentRuns($clientId, 5),
        ]);
    }

    /**
     * Run one question for this customer. Returns a display-ready array and
     * never throws: every failure mode becomes an honest message.
     *
     * @return array{answer:?string,error:?string,citations:array,run_id:int}
     */
    protected function ask($clientId, $question)
    {
        $fail = function ($message) {
            return ['answer' => null, 'error' => $message, 'citations' => [], 'run_id' => 0];
        };

        if (!Csrf::verify((string) ($_POST['ch247ai_csrf'] ?? ''))) {
            return $fail('Your session expired. Reload the page and try again.');
        }
        if ($question === '') {
            return $fail('Type a question first.');
        }
        if (strlen($question) > self::MAX_QUESTION) {
            return $fail('That question is too long. Keep it under ' . self::MAX_QUESTION . ' characters.');
        }

        try {
            RateLimiter::hitOrFail('client_assistant', RateLimiter::bucketForClient((int) $clientId), self::RATE_MAX, self::RATE_WINDOW);
        } catch (RateLimitException $e) {
            return $fail('You have asked a lot of questions just now. Please wait a few minutes and try again.');
        }

        ToolBootstrap::register();

        try {
            $result = AgentRuntime::run(self::AGENT, $question, [
                'source' => AgentRuntime::SOURCE_INTERACTIVE,
                'actor_type' => 'client',
                'actor_id' => (int) $clientId,
                'profile' => 'fast',
            ]);
            return [
                'answer' => (string) $result['output'],
                'error' => null,
                'citations' => array_slice((array) $result['citations'], 0, 10),
                'run_id' => (int) $result['run_id'],
            ];
        } catch (ProviderNotConfiguredException $e) {
            return $fail('The assistant is not configured on this platform yet, so there is nothing to answer with. Please contact support.');
        } catch (BudgetExceededException $e) {
            return $fail('The assistant has reached its usage limit for now. Please try again later.');
        } catch (RateLimitException $e) {
            return $fail('The assistant is busy. Please try again in a few minutes.');
        } catch (\Throwable $e) {
            Audit::system('ai.client_assistant.error', ['client_id' => (int) $clientId, 'reason' => get_class($e)]);
            return $fail('The assistant could not answer that. The problem has been logged.');
        }
    }

    // -----------------------------------------------------------------
    // AI Support Operator (guests + clients, server-posted like the rest)
    // -----------------------------------------------------------------

    protected function dispatchSupport()
    {
        if (!Settings::bool('service_enabled', true) || Settings::bool('kill_switch', false)) {
            return $this->supportPage('Support unavailable', [
                'reason' => 'The AI support chat is currently switched off by the provider.',
            ]);
        }
        if (!OperatorEngine::enabled()) {
            return $this->supportPage('Support unavailable', [
                'reason' => 'The AI support chat is not enabled on this platform.',
            ]);
        }
        try {
            return $this->pageSupport();
        } catch (\Throwable $e) {
            return $this->supportPage('Something went wrong', [
                'reason' => 'The support chat could not load. The problem has been logged.',
            ]);
        }
    }

    protected function pageSupport()
    {
        $clientId = Identity::clientId() ?: 0;
        $client = null;
        if ($clientId > 0) {
            $rows = Db::query('SELECT * FROM tblclients WHERE id = ?', [$clientId]);
            $client = $rows ? $rows[0] : null;
            if ($client === null) {
                $clientId = 0;
            }
        }
        $ctx = [
            'client_id' => $clientId,
            'actor' => $clientId > 0 ? 'client' : 'guest',
            'client' => $client,
            'session_convs' => ConversationService::sessionClaims(),
        ];
        $rate = OperatorEngine::rateConfig();
        $vars = [
            'conversation' => null,
            'messages' => [],
            'error' => null,
            'message' => '',
            'guest_name' => '',
            'guest_email' => '',
            'client_name' => $client !== null ? trim($client['firstname'] . ' ' . $client['lastname']) : '',
            'max_message' => max(200, Settings::int('support_max_message', 2000)),
            'rate_max' => $rate['max'],
            'rate_minutes' => (int) ($rate['window'] / 60),
            'availability' => 'offline',
        ];
        try {
            $vars['availability'] = \Ch247Ai\SupportOperator\PresenceService::availability()['status'];
        } catch (\Throwable $e) {
            // The chat works without presence; it only colours the label.
        }

        $publicId = (string) ($_POST['c'] ?? $_GET['c'] ?? '');
        $conv = null;
        if ($publicId !== '') {
            $conv = ConversationService::findByPublic($publicId);
            if ($conv === null || !ConversationService::visibleTo($conv, $ctx)) {
                $vars['error'] = 'That conversation link is invalid or belongs to another session. Start a new conversation below.';
                return $this->supportPage('AI Support', $vars);
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify((string) ($_POST['ch247ai_csrf'] ?? ''))) {
                $vars['error'] = 'Your session expired. Reload the page and try again.';
            } else {
                $op = (string) ($_POST['ch247ai_support'] ?? '');
                if ($op === 'start') {
                    $vars['guest_name'] = trim((string) ($_POST['name'] ?? ''));
                    $vars['guest_email'] = trim((string) ($_POST['email'] ?? ''));
                    $vars['message'] = trim((string) ($_POST['message'] ?? ''));
                    $conv = $this->supportStart($client, $ctx, $vars);
                } elseif ($op === 'message' || $op === 'human') {
                    if ($conv === null) {
                        $vars['error'] = 'Start a conversation first.';
                    } else {
                        $text = $op === 'human'
                            ? 'I would like to talk to a human, please.'
                            : trim((string) ($_POST['message'] ?? ''));
                        if ($text === '') {
                            $vars['error'] = 'Type a message first.';
                        } else {
                            OperatorEngine::handle($conv, $text, $ctx);
                            $conv = ConversationService::find((int) $conv['id']);
                        }
                    }
                }
            }
        }

        if ($conv !== null) {
            $vars['conversation'] = $conv;
            $messages = [];
            foreach (ConversationService::messages((int) $conv['id']) as $message) {
                $message['citations'] = isset($message['meta_decoded']['citations'])
                    ? (array) $message['meta_decoded']['citations'] : [];
                $messages[] = $message;
            }
            $vars['messages'] = $messages;
        }
        return $this->supportPage('AI Support', $vars);
    }

    /** Start a conversation and run the first message through the engine. */
    protected function supportStart($client, array $ctx, array &$vars)
    {
        if ($client !== null) {
            $name = trim($client['firstname'] . ' ' . $client['lastname']);
            $email = (string) $client['email'];
        } else {
            $name = $vars['guest_name'];
            $email = $vars['guest_email'];
            if (!Validator::email($email)) {
                $vars['error'] = 'Enter a valid email address so Support can reach you if a human is needed.';
                return null;
            }
        }
        if ($vars['message'] === '') {
            $vars['error'] = 'Tell us how we can help, then start chatting.';
            return null;
        }
        $conv = ConversationService::create($client !== null ? (int) $client['id'] : 0, $name, $email);
        $ctx['session_convs'] = ConversationService::sessionClaims();
        OperatorEngine::handle($conv, $vars['message'], $ctx);
        $vars['message'] = '';
        return ConversationService::find((int) $conv['id']);
    }

    /** Guest-capable page variant: the support chat needs no login. */
    protected function supportPage($title, array $vars)
    {
        $vars += [
            'conversation' => null, 'messages' => [], 'error' => null, 'message' => '',
            'guest_name' => '', 'guest_email' => '', 'client_name' => '',
            'max_message' => 2000, 'rate_max' => 20, 'rate_minutes' => 5, 'availability' => 'offline',
        ];
        $vars['modulelink'] = 'index.php?m=cloudhost247ai';
        $vars['csrf_token'] = Csrf::token();
        $vars['generated_at'] = Clock::now();

        return [
            'pagetitle' => $title,
            'breadcrumb' => ['index.php?m=cloudhost247ai&action=support' => 'AI Support'],
            'templatefile' => isset($vars['reason']) ? 'templates/client/unavailable' : 'templates/client/support',
            'requirelogin' => false,
            'templatevariables' => $vars,
        ];
    }

    // -----------------------------------------------------------------
    // Activity (§33)
    // -----------------------------------------------------------------

    protected function pageActivity($clientId)
    {
        $runs = $this->recentRuns($clientId, 25);
        foreach ($runs as $i => $run) {
            $runs[$i]['tools'] = $this->toolsForRun((int) $run['id']);
        }
        return $this->page('AI activity on your account', 'activity', [
            'runs' => $runs,
            'has_runs' => $runs !== [],
        ]);
    }

    /**
     * This client's own AI runs. The client id filter is applied in SQL
     * alongside actor_type, so another customer's run can never appear here.
     */
    protected function recentRuns($clientId, $limit)
    {
        try {
            $rows = Db::query(
                'SELECT id, agent, status, input, output, started_at, finished_at FROM ' . Db::t('runs')
                . " WHERE actor_type = 'client' AND actor_id = ? ORDER BY id DESC LIMIT " . max(1, (int) $limit),
                [(int) $clientId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'agent' => (string) $row['agent'],
                'status' => (string) $row['status'],
                'question' => Validator::clip((string) $row['input'], 300),
                'answer' => Validator::clip((string) $row['output'], 2000),
                'started_at' => (string) $row['started_at'],
                'finished_at' => (string) $row['finished_at'],
            ];
        }
        return $out;
    }

    /** Which tools a run used — the evidence trail shown to the customer. */
    protected function toolsForRun($runId)
    {
        try {
            $rows = Db::query(
                'SELECT tool, status, created_at FROM ' . Db::t('tool_calls') . ' WHERE run_id = ? ORDER BY id ASC LIMIT 25',
                [(int) $runId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'tool' => (string) $row['tool'],
                'status' => (string) $row['status'],
                'at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------

    protected function page($title, $template, array $vars)
    {
        $vars['modulelink'] = 'index.php?m=cloudhost247ai';
        $vars['csrf_token'] = Csrf::token();
        $vars['generated_at'] = Clock::now();

        return [
            'pagetitle' => $title,
            'breadcrumb' => ['index.php?m=cloudhost247ai' => 'Account assistant'],
            'templatefile' => 'templates/client/' . $template,
            'requirelogin' => true,
            'templatevariables' => $vars,
        ];
    }
}
