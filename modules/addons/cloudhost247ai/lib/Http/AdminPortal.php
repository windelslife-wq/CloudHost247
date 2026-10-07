<?php
/**
 * Admin portal (WHMCS addon output). Follows the digitalproducts Admin
 * pattern: ob_start/echo with e() escaping, ?action= routing, POST actions
 * gated by CSRF + Rbac groups.
 *
 * Pages: dashboard, copilot, agents, tools, knowledge, approvals, runs,
 * audit, events, settings. Every page renders a clear state when no model
 * provider is configured (fail-closed UX, never fake data).
 */

namespace Ch247Ai\Http;

use Ch247Ai\Agents\AgentRegistry;
use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Approval\ApprovalEngine;
use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Csrf;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Logger;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\RateLimiter;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Validator;
use Ch247Ai\Knowledge\KnowledgeService;
use Ch247Ai\Model\ModelRouter;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;
use Ch247Ai\Tools\ToolRegistry;

class AdminPortal
{
    protected $vars;
    protected $moduleLink;
    protected $action;
    protected $error = '';
    protected $success = '';

    public function __construct(array $vars)
    {
        $this->vars = $vars;
        $this->moduleLink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=cloudhost247ai';
        $this->action = preg_replace('/[^a-z_-]/', '', (string) ($_GET['action'] ?? 'dashboard'));
        ToolBootstrap::register();
    }

    public function render()
    {
        if (!Identity::adminId()) {
            return '<div class="alert alert-danger">Admin session required.</div>';
        }
        try {
            $this->handlePost();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
        ob_start();
        echo '<div class="ch247ai-admin">';
        $this->notices();
        try {
            switch ($this->action) {
                case 'copilot': $this->copilot(); break;
                case 'agents': $this->agents(); break;
                case 'tools': $this->tools(); break;
                case 'knowledge': $this->knowledge(); break;
                case 'knowledge-edit': $this->knowledgeEdit(); break;
                case 'board': $this->board(); break;
                case 'approvals': $this->approvals(); break;
                case 'evaluations': $this->evaluations(); break;
                case 'runs': $this->runs(); break;
                case 'run': $this->runDetail(); break;
                case 'audit': $this->audit(); break;
                case 'events': $this->events(); break;
                case 'settings': $this->settings(); break;
                default: $this->dashboard(); break;
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">Unable to load this section. Check the activity log.</div>';
            Logger::error('admin portal error', ['action' => $this->action, 'reason' => get_class($e)]);
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ------------------------------------------------------------- posts -- */

    protected function handlePost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        Csrf::verifyRequest();
        $action = (string) ($_POST['ch247ai_action'] ?? '');
        $manage = function () {
            if (!Rbac::adminCan(Rbac::AI_MANAGE)) {
                throw new \Ch247Ai\Core\ForbiddenException('You need the AI manage permission for this.');
            }
        };

        switch ($action) {
            case 'save_settings':
                $manage();
                $this->saveSettings();
                Audit::admin((int) Identity::adminId(), 'ai.settings.saved', []);
                $this->success = 'Settings saved.';
                return;
            case 'toggle_agent':
                $manage();
                $slug = (string) ($_POST['agent'] ?? '');
                if (AgentRegistry::find($slug) === null) {
                    throw new \Ch247Ai\Core\ValidationException('Unknown agent.');
                }
                $row = Db::first('agents', ['agent' => $slug]);
                $new = ($row === null || (int) $row['enabled'] === 0) ? 1 : 0;
                if ($row === null) {
                    AgentRegistry::sync();
                }
                Db::update('agents', ['agent' => $slug], ['enabled' => $new, 'updated_at' => Clock::now()]);
                Audit::admin((int) Identity::adminId(), 'ai.agent.toggled', ['entity_type' => 'agent', 'entity_id' => 0, 'context' => ['agent' => $slug, 'enabled' => $new]]);
                $this->success = $new ? 'Agent enabled.' : 'Agent disabled.';
                return;
            case 'toggle_tool':
                $manage();
                $tool = (string) ($_POST['tool'] ?? '');
                if (ToolRegistry::find($tool) === null) {
                    throw new \Ch247Ai\Core\ValidationException('Unknown tool.');
                }
                $row = Db::first('settings', ['setting' => 'tool_disabled_' . $tool]);
                $disable = ($row === null || $row['value'] !== '1');
                if ($row === null) {
                    Db::insert('settings', ['setting' => 'tool_disabled_' . $tool, 'value' => $disable ? '1' : '0', 'updated_at' => Clock::now()]);
                } else {
                    Db::update('settings', ['setting' => 'tool_disabled_' . $tool], ['value' => $disable ? '1' : '0', 'updated_at' => Clock::now()]);
                }
                ToolRegistry::setDisabled($tool, $disable);
                Audit::admin((int) Identity::adminId(), 'ai.tool.toggled', ['entity_type' => 'tool', 'entity_id' => 0, 'context' => ['tool' => $tool, 'disabled' => $disable]]);
                $this->success = $disable ? 'Tool disabled (existing runs keep their history).' : 'Tool enabled.';
                return;
            case 'run_agent':
                $manage();
                $slug = (string) ($_POST['agent'] ?? '');
                $this->runAgentNow($slug);
                return;
            case 'compose_board':
                $manage();
                $res = \Ch247Ai\Board\ExecutiveBoard::compose(isset($_POST['period']) ? (string) $_POST['period'] : 'daily');
                if (empty($res['report_id'])) {
                    $this->success = 'Executive board is disabled in settings.';
                } else {
                    $this->success = 'Executive board composed (' . $res['period'] . '): '
                        . (int) $res['seats_ok'] . ' seat(s) reporting, '
                        . (int) $res['seats_unavailable'] . ' awaiting a data source'
                        . ($res['metrics_only'] ? ', metrics only (no model configured)' : ', narrated') . '.';
                }
                return;

            case 'compose_briefing':
                $manage();
                \Ch247Ai\Briefing\BriefingComposer::compose();
                $this->success = 'Briefing composed. See it on the dashboard.';
                return;
            case 'run_evaluation':
                $manage();
                $ev = \Ch247Ai\Eval\Evaluator::run(7);
                $this->success = $ev['enabled']
                    ? ('Evaluation complete: ' . (int) $ev['metrics'] . ' metrics stored, probes '
                        . (int) $ev['probes_passed'] . ' pass / ' . (int) $ev['probes_failed'] . ' fail / '
                        . (int) $ev['probes_skipped'] . ' skipped.')
                    : 'Evaluations are disabled in settings.';
                break;

            case 'execute_approval':
                if (!Rbac::adminCan(Rbac::AI_APPROVE)) {
                    throw new \Ch247Ai\Core\ForbiddenException('You need the AI approve permission.');
                }
                $outcome = \Ch247Ai\Approval\ApprovalExecutor::run((int) ($_POST['approval_id'] ?? 0));
                $this->success = ($outcome['ok']
                    ? 'Executed and verified. '
                    : 'NOT APPLIED — the action did not complete and nothing is being claimed. ')
                    . $outcome['note'];
                break;

            case 'decide_approval':
                if (!Rbac::adminCan(Rbac::AI_APPROVE)) {
                    throw new \Ch247Ai\Core\ForbiddenException('You need the AI approve permission.');
                }
                ApprovalEngine::decide((int) ($_POST['approval_id'] ?? 0), ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected', (string) ($_POST['note'] ?? ''));
                $this->success = 'Decision recorded.';
                return;
            case 'kb_save':
                if (!Rbac::adminCan(Rbac::AI_MANAGE)) {
                    throw new \Ch247Ai\Core\ForbiddenException('You need the AI manage permission for the knowledge base.');
                }
                $id = KnowledgeService::upsert(
                    (int) ($_POST['id'] ?? 0),
                    (string) ($_POST['type'] ?? 'doc'),
                    (string) ($_POST['title'] ?? ''),
                    (string) ($_POST['body'] ?? ''),
                    (string) ($_POST['tags'] ?? ''),
                    (string) ($_POST['visibility'] ?? 'admin')
                );
                Audit::admin((int) Identity::adminId(), 'ai.knowledge.saved', ['entity_type' => 'knowledge_source', 'entity_id' => $id]);
                $this->success = 'Knowledge source saved and indexed.';
                $_GET['action'] = 'knowledge';
                $this->action = 'knowledge';
                return;
            case 'kb_archive':
            case 'kb_delete':
                if (!Rbac::adminCan(Rbac::AI_MANAGE)) {
                    throw new \Ch247Ai\Core\ForbiddenException('You need the AI manage permission for the knowledge base.');
                }
                $id = (int) ($_POST['id'] ?? 0);
                if ($action === 'kb_archive') {
                    KnowledgeService::setStatus($id, 'archived');
                    $this->success = 'Source archived (search no longer returns it).';
                } else {
                    KnowledgeService::delete($id);
                    $this->success = 'Source deleted.';
                }
                Audit::admin((int) Identity::adminId(), 'ai.knowledge.' . ($action === 'kb_archive' ? 'archived' : 'deleted'), ['entity_type' => 'knowledge_source', 'entity_id' => $id]);
                return;
            case 'kill_switch':
                $manage();
                $on = !empty($_POST['on']);
                Settings::put('kill_switch', $on ? '1' : '0');
                Audit::admin((int) Identity::adminId(), $on ? 'ai.killswitch.on' : 'ai.killswitch.off', []);
                $this->success = $on ? 'Kill switch ENGAGED: all model calls and tool execution stop immediately.' : 'Kill switch released.';
                return;
            default:
                return;
        }
    }

    protected function saveSettings()
    {
        $ints = ['max_tool_calls_per_run', 'run_wall_clock_seconds', 'daily_tokens_per_agent', 'monthly_platform_cost_micros', 'event_max_attempts', 'approval_expiry_hours', 'retention_days_runs', 'retention_days_events', 'model_timeout_seconds', 'model_price_per_mtok_in_micros', 'model_price_per_mtok_out_micros', 'briefing_hour'];
        foreach ($ints as $key) {
            if (isset($_POST[$key])) {
                Settings::put($key, (string) max(0, (int) $_POST[$key]));
            }
        }
        $strings = ['model_fast_endpoint', 'model_fast_model', 'model_reasoning_endpoint', 'model_reasoning_model'];
        foreach ($strings as $key) {
            if (isset($_POST[$key])) {
                Settings::put($key, Validator::clip(trim((string) $_POST[$key]), 250));
            }
        }
        $bools = ['copilot_enabled', 'knowledge_enabled', 'briefings_enabled', 'redact_pii', 'writes_enabled', 'client_assistant_enabled'];
        foreach ($bools as $key) {
            Settings::put($key, !empty($_POST[$key]) ? '1' : '0');
        }
        // Role grants.
        $roles = \Ch247Ai\Core\Whmcs::roles();
        foreach (Rbac::ALL_GROUPS as $group) {
            $posted = isset($_POST['grant']) && is_array($_POST['grant']) ? $_POST['grant'] : [];
            foreach ($roles as $role) {
                $roleId = (int) ($role['id'] ?? 0);
                if (!$roleId) {
                    continue;
                }
                $has = Db::count('role_permissions', ['role_id' => $roleId, 'permission' => $group]) > 0;
                $want = !empty($posted[$roleId][$group]);
                if ($want && !$has) {
                    Rbac::grant($roleId, $group);
                } elseif (!$want && $has) {
                    Rbac::revoke($roleId, $group);
                }
            }
        }
    }

    protected function runAgentNow($slug)
    {
        $def = AgentRegistry::find($slug);
        if ($def === null) {
            throw new \Ch247Ai\Core\ValidationException('Unknown agent.');
        }
        $question = trim((string) ($_POST['question'] ?? ''));
        $defaults = [
            'ssl_guardian' => 'Check TLS certificates for domains expiring soon (read_domains, then ssl_checker).',
            'dns_domain_agent' => 'Review the nearest-expiry domains (read_domains, dns_health).',
            'billing_reconciliation' => 'Summarise current unpaid billing exposure (read_metrics, read_invoices).',
            'collections_agent' => 'List the oldest unpaid invoices with amounts (read_invoices).',
            'revenue_analyst' => 'Summarise revenue for the last 30 days (read_metrics, read_payments).',
            'ledger_agent' => 'Produce today\'s ledger pack (read_metrics).',
            'customer_intelligence' => $question !== '' ? $question : 'Summarise client #1 footprint (read_client_details, read_services, read_tickets).',
            'resolution_pro' => $question !== '' ? $question : 'Summarise the newest open tickets (read_tickets, read_knowledge).',
        ];
        $input = $question !== '' ? $question : (isset($defaults[$slug]) ? $defaults[$slug] : 'Collect evidence with your tools and report findings.');
        try {
            $result = AgentRuntime::run($slug, $input, [
                'source' => $def->runMode === 'interactive' ? AgentRuntime::SOURCE_INTERACTIVE : AgentRuntime::SOURCE_CRON,
                'actor_type' => 'admin',
                'actor_id' => (int) Identity::adminId(),
                'profile' => 'fast',
            ]);
            $this->success = 'Run #' . $result['run_id'] . ' finished: ' . $result['status'] . ' (' . $result['tool_calls'] . ' tool call' . ($result['tool_calls'] === 1 ? '' : 's') . '). See Runs.';
        } catch (\Ch247Ai\Core\ProviderNotConfiguredException $e) {
            $this->error = 'CONFIGURATION_REQUIRED: ' . $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = 'Run failed: ' . $e->getMessage();
        }
    }

    /* -------------------------------------------------------------- view -- */

    protected function notices()
    {
        if ($this->error !== '') {
            echo '<div class="alert alert-danger">' . $this->e($this->error) . '</div>';
        }
        if ($this->success !== '') {
            echo '<div class="alert alert-success">' . $this->e($this->success) . '</div>';
        }
        if (Settings::bool('kill_switch', false)) {
            echo '<div class="alert alert-danger"><strong>Kill switch engaged.</strong> Every model call and tool execution is stopped until it is released in Settings.</div>';
        }
        if (!ModelRouter::copilotConfigured()) {
            echo '<div class="alert alert-warning"><strong>No model provider configured.</strong> The AI layer is installed and its pages work, but answers fail closed with CONFIGURATION_REQUIRED instead of guessing. Configure endpoints in Settings (and CH247AI_API_KEY in the environment if needed).</div>';
        }
    }

    protected function nav()
    {
        $items = [
            'dashboard' => 'Dashboard',
            'copilot' => 'Copilot',
            'agents' => 'Agents',
            'board' => 'Executive board',
            'tools' => 'Tools',
            'knowledge' => 'Knowledge',
            'approvals' => 'Approvals' . ($this->pendingApprovals() ? ' <span class="badge">' . $this->pendingApprovals() . '</span>' : ''),
            'runs' => 'Runs &amp; usage',
            'audit' => 'Audit chain',
            'events' => 'Events',
            'settings' => 'Settings',
        ];
        echo '<ul class="nav nav-pills" style="margin-bottom:20px">';
        foreach ($items as $action => $label) {
            $active = $this->action === $action || ($action === 'knowledge' && $this->action === 'knowledge-edit') || ($action === 'runs' && $this->action === 'run') ? 'active' : '';
            echo '<li class="' . $active . '"><a href="' . $this->u($action) . '">' . $label . '</a></li>';
        }
        echo '</ul>';
    }

    protected function pendingApprovals()
    {
        try {
            return ApprovalEngine::pending();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function dashboard()
    {
        $this->nav();
        $configured = ModelRouter::copilotConfigured();
        $stats = [
            'agents' => Db::count('agents', []),
            'agents_enabled' => Db::count('agents', ['enabled' => 1]),
            'runs' => Db::count('runs', []),
            'tool_calls' => Db::count('tool_calls', []),
            'events_pending' => Db::count('events', ['status' => 'pending']),
            'approvals_pending' => $this->pendingApprovals(),
        ];
        echo '<h2>AI control plane</h2><div class="row">';
        foreach ([
            'agents' => 'Registered agents', 'agents_enabled' => 'Enabled agents', 'runs' => 'Total runs',
            'tool_calls' => 'Tool calls (all audited)', 'events_pending' => 'Events awaiting drain', 'approvals_pending' => 'Pending decisions',
        ] as $key => $label) {
            echo '<div class="col-sm-2"><div class="ch247ai-stat"><div class="number">' . (int) $stats[$key] . '</div><div class="text-muted small">' . $this->e($label) . '</div></div></div>';
        }
        echo '</div>';

        // Latest briefing.
        $briefing = Db::first('reports', ['type' => 'daily'], 'id DESC');
        echo '<div class="panel panel-default"><div class="panel-heading clearfix"><strong>Latest daily briefing</strong>'
            . '<form method="post" class="pull-right">' . Csrf::field()
            . '<input type="hidden" name="ch247ai_action" value="compose_briefing">'
            . '<button class="btn btn-default btn-xs" type="submit">Compose now</button></form></div><div class="panel-body">';
        if ($briefing === null) {
            echo '<p class="text-muted">No briefing yet. The cron composes one daily at hour ' . (int) Settings::int('briefing_hour', 6) . ' UTC, or press “Compose now”.</p>';
        } else {
            echo '<p class="small text-muted">' . ch247ai_dt($briefing['created_at']) . ' UTC — ' . ((int) $briefing['metrics_only'] === 1 ? 'metrics only (no model)' : 'narrated') . '</p>';
            foreach (json_decode((string) $briefing['sections'], true) ?: [] as $section) {
                echo '<h4>' . $this->e($section['title'] ?? '') . '</h4><ul>';
                foreach ((array) ($section['lines'] ?? []) as $line) {
                    echo '<li>' . $this->e($line) . '</li>';
                }
                echo '</ul>';
            }
            if (!empty($briefing['narrative'])) {
                echo '<h4>Narrative</h4><p>' . nl2br($this->e($briefing['narrative'])) . '</p>';
            }
        }
        echo '</div></div>';

        // Ground rules card.
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Operating guarantees</strong></div><div class="panel-body small">'
            . '<ul class="ch247ai-tight"><li>Answers are built from registered tool calls only — missing data fails closed, never invented.</li>'
            . '<li>Writes execute only from an approved decision, bound to the exact arguments approved, and are re-read afterwards to confirm they landed.</li>'
            . '<li>Web-request hooks only record events; agents run from cron or explicit user action.</li>'
            . '<li>Every tool call and run lands in the hash-chained audit log; secrets are redacted before storage.</li>'
            . '<li>Model provider: ' . ($configured ? '<span class="label label-success">configured</span>' : '<span class="label label-warning">not configured (fail closed)</span>') . '</li></ul></div></div>';
    }

    protected function copilot()
    {
        $this->nav();
        $canRead = Rbac::adminCan(Rbac::AI_READ);
        echo '<h2>Admin Copilot</h2>';
        if (!$canRead) {
            echo '<div class="alert alert-danger">You do not have the AI read permission group.</div>';
            return;
        }
        if (!Settings::bool('copilot_enabled', true)) {
            echo '<div class="alert alert-warning">The copilot is disabled in settings.</div>';
            return;
        }
        echo '<div class="panel panel-default"><div class="panel-body">'
            . '<div id="ch247ai-chat" class="ch247ai-chat"><div class="ch247ai-msg system">Ask about live platform data: “show unpaid invoices over 90 days”, “is dns healthy for example.com”, “summarise open tickets”. Answers cite the tool calls they used.</div></div>'
            . '<div class="input-group" style="margin-top:10px"><input id="ch247ai-q" class="form-control" maxlength="4000" placeholder="Ask a question grounded in live WHMCS data…">'
            . '<span class="input-group-btn"><button id="ch247ai-send" class="btn btn-primary">Ask</button></span></div>'
            . '<p class="small text-muted" style="margin-top:8px">Rate limited to 20 questions per 5 minutes. No write actions exist in this phase.</p>'
            . '</div></div>';
        $this->chatScript();
    }

    protected function chatScript()
    {
        $token = ch247ai_h(Csrf::token());
        $api = ch247ai_h(\Ch247Ai\Core\Whmcs::adminBasePath() . 'modules/addons/cloudhost247ai/api.php');
        $js = "(function(){\n"
            . "var box=document.getElementById('ch247ai-chat'),input=document.getElementById('ch247ai-q'),btn=document.getElementById('ch247ai-send'),busy=false;\n"
            . "var API=" . json_encode($api) . ",TOKEN=" . json_encode($token) . ";\n"
            . "function msg(cls,text){var d=document.createElement('div');d.className='ch247ai-msg '+cls;d.textContent=text;box.appendChild(d);box.scrollTop=box.scrollHeight;return d;}\n"
            . "function ask(){\n"
            . "  if(busy)return;var q=input.value.trim();if(!q)return;busy=true;btn.disabled=true;\n"
            . "  msg('user',q);input.value='';var wait=msg('system','thinking…');\n"
            . "  fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':TOKEN},body:JSON.stringify({question:q,csrf:TOKEN})})\n"
            . "    .then(function(r){return r.json().then(function(j){return j;},function(){return {status:'error',error:'Bad response'};});})\n"
            . "    .then(function(data){\n"
            . "      wait.remove();\n"
            . "      if(data.status==='error'){msg('system','Error: '+(data.error||'unknown'));}\n"
            . "      else{\n"
            . "        msg('assistant',data.output||'(empty answer)');\n"
            . "        if(data.citations&&data.citations.length){msg('system','Evidence:\\n- '+data.citations.join('\\n- '));}\n"
            . "        if(data.run_id){msg('system','Run #'+data.run_id+' — '+(data.tool_calls||0)+' tool call(s), status: '+data.status);}\n"
            . "      }\n"
            . "    })\n"
            . "    .catch(function(){wait.remove();msg('system','Network error — the request did not complete.');});\n"
            . "}\n"
            . "btn.addEventListener('click',ask);\n"
            . "input.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();ask();}});\n"
            . "})();";
        echo '<script>' . $js . '</script>';
    }

    protected function agents()
    {
        $this->nav();
        $manage = Rbac::adminCan(Rbac::AI_MANAGE);
        echo '<h2>Agents</h2><p class="text-muted small">The registry defines what each agent may ever call. Prompt text never grants permissions — grants live here and in the tool executor.</p>';
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Agent</th><th>Mode</th><th>Tools allowed</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach (AgentRegistry::all() as $def) {
            $row = Db::first('agents', ['agent' => $def->slug]);
            $enabled = $row === null ? $def->defaultEnabled : (int) $row['enabled'] === 1;
            echo '<tr><td><strong>' . $this->e($def->name) . '</strong><br><small class="text-muted">' . $this->e($def->description) . '</small></td>'
                . '<td>' . $this->e($def->runMode) . '</td>'
                . '<td><span class="small">' . count($def->tools) . ' tools</span></td>'
                . '<td>' . ($enabled ? '<span class="label label-success">enabled</span>' : '<span class="label label-default">disabled</span>') . '</td>'
                . '<td>';
            if ($manage) {
                echo '<form method="post" style="display:inline">' . Csrf::field()
                    . '<input type="hidden" name="ch247ai_action" value="toggle_agent"><input type="hidden" name="agent" value="' . ch247ai_h($def->slug) . '">'
                    . '<button class="btn btn-xs btn-default">' . ($enabled ? 'Disable' : 'Enable') . '</button></form> ';
                echo '<form method="post" style="display:inline">' . Csrf::field()
                    . '<input type="hidden" name="ch247ai_action" value="run_agent"><input type="hidden" name="agent" value="' . ch247ai_h($def->slug) . '">'
                    . '<button class="btn btn-xs btn-primary">Run now</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="alert alert-info small">Tier B agents (Infrastructure Guardian, Provisioning, Security Sentinel, Fraud) are deliberately not registered: their data collectors (server telemetry, deployment events) do not exist yet. They will be added by extending this registry, not by prompt changes.</div>';
    }

    protected function tools()
    {
        $this->nav();
        $manage = Rbac::adminCan(Rbac::AI_MANAGE);
        echo '<h2>Tool registry</h2><p class="text-muted small">Every tool call crosses: kill switch → tool enabled → agent allowlist → admin permission group → client-scope SQL filter → redaction → audit.</p>';
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Tool</th><th>Permission</th><th>Risk</th><th>Source</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach (ToolRegistry::all() as $tool) {
            $disabled = ToolRegistry::isDisabled($tool->name);
            echo '<tr><td><code>' . ch247ai_h($tool->name) . '</code><br><small class="text-muted">' . $this->e($tool->description) . '</small></td>'
                . '<td><code>' . ch247ai_h($tool->permission) . '</code></td>'
                . '<td>' . ch247ai_pill(strtolower($tool->risk === 'READ' ? 'ok' : $tool->risk)) . '</td>'
                . '<td><small>' . ch247ai_h(implode(',', array_keys($tool->dataSource ?: [])) . ':' . implode(',', array_values($tool->dataSource ?: []))) . '</small></td>'
                . '<td>' . ($disabled ? '<span class="label label-default">disabled</span>' : '<span class="label label-success">enabled</span>') . '</td><td>';
            if ($manage) {
                echo '<form method="post" style="display:inline">' . Csrf::field()
                    . '<input type="hidden" name="ch247ai_action" value="toggle_tool"><input type="hidden" name="tool" value="' . ch247ai_h($tool->name) . '">'
                    . '<button class="btn btn-xs btn-default">' . ($disabled ? 'Enable' : 'Disable') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        $writes = 0;
        foreach (ToolRegistry::all() as $tool) {
            if ($tool->risk !== 'READ') {
                $writes++;
            }
        }
        $writesOn = Settings::bool('writes_enabled', false);
        echo '<div class="alert alert-' . ($writes === 0 ? 'success' : ($writesOn ? 'warning' : 'info')) . ' small">Write tools registered: <strong>' . (int) $writes . '</strong>'
            . ($writes === 0
                ? ' — this installation is read-only.'
                : ' — each one requires an approved decision, is bound to the approved arguments, and is verified by re-reading the database afterwards. Execution master switch is <strong>'
                  . ($writesOn ? 'ON' : 'OFF (nothing can execute)') . '</strong>.')
            . '</div>';
    }

    protected function knowledge()
    {
        $this->nav();
        $manage = Rbac::adminCan(Rbac::AI_MANAGE);
        echo '<h2>Knowledge base</h2>';
        if ($manage) {
            echo '<a class="btn btn-primary btn-sm" href="' . $this->u('knowledge-edit') . '">New source</a> ';
        }
        $query = trim((string) ($_GET['q'] ?? ''));
        echo '<form method="get" class="form-inline" style="display:inline"><input type="hidden" name="module" value="cloudhost247ai"><input type="hidden" name="action" value="knowledge">'
            . '<input class="form-control input-sm" name="q" value="' . ch247ai_h($query) . '" placeholder="Search title/tags"> <button class="btn btn-default btn-sm">Search</button></form>';
        $rows = KnowledgeService::search($query, 50);
        echo '<div class="table-responsive" style="margin-top:12px"><table class="table table-striped"><thead><tr><th>Title</th><th>Type</th><th>Visibility</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><strong>' . $this->e($row['title']) . '</strong><br><small class="text-muted">' . $this->e((string) $row['tags']) . '</small></td>'
                . '<td>' . $this->e($row['type']) . '</td><td>' . $this->e($row['visibility']) . '</td><td>v' . (int) $row['version'] . '</td><td>' . ch247ai_pill($row['status'] === 'active' ? 'active' : 'draft') . '</td><td>';
            if ($manage) {
                echo '<a class="btn btn-xs btn-default" href="' . $this->u('knowledge-edit', ['id' => (int) $row['id']]) . '">Edit</a> ';
                echo '<form method="post" style="display:inline">' . Csrf::field() . '<input type="hidden" name="ch247ai_action" value="kb_archive"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn btn-xs btn-default">Archive</button></form> ';
                echo '<form method="post" style="display:inline" onclick="return confirm(\'Delete this knowledge source?\')">' . Csrf::field() . '<input type="hidden" name="ch247ai_action" value="kb_delete"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="btn btn-xs btn-danger">Delete</button></form>';
            }
            echo '</td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="6" class="text-muted">No knowledge sources yet. Add product notes, runbooks and policies so agents can ground platform answers.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function knowledgeEdit()
    {
        $this->nav();
        if (!Rbac::adminCan(Rbac::AI_MANAGE)) {
            echo '<div class="alert alert-danger">You need the AI manage permission.</div>';
            return;
        }
        $id = (int) ($_GET['id'] ?? 0);
        $row = ['id' => 0, 'type' => 'doc', 'title' => '', 'tags' => '', 'visibility' => 'admin', 'body' => ''];
        if ($id > 0) {
            try {
                $row = KnowledgeService::get($id);
            } catch (\Throwable $e) {
                echo '<div class="alert alert-danger">Not found.</div>';
                return;
            }
        }
        echo '<h2>' . ($id ? 'Edit' : 'New') . ' knowledge source</h2>'
            . '<form method="post" class="form-horizontal">' . Csrf::field()
            . '<input type="hidden" name="ch247ai_action" value="kb_save"><input type="hidden" name="id" value="' . (int) $row['id'] . '">'
            . '<div class="form-group"><label class="col-sm-2 control-label">Title</label><div class="col-sm-8"><input class="form-control" name="title" required value="' . ch247ai_h($row['title']) . '"></div></div>'
            . '<div class="form-group"><label class="col-sm-2 control-label">Type</label><div class="col-sm-8"><select class="form-control" name="type">';
        foreach (KnowledgeService::TYPES as $type) {
            echo '<option value="' . ch247ai_h($type) . '"' . ($row['type'] === $type ? ' selected' : '') . '>' . ch247ai_h($type) . '</option>';
        }
        echo '</select></div></div>'
            . '<div class="form-group"><label class="col-sm-2 control-label">Visibility</label><div class="col-sm-8"><select class="form-control" name="visibility">';
        foreach (KnowledgeService::VISIBILITIES as $visibility) {
            echo '<option value="' . ch247ai_h($visibility) . '"' . ($row['visibility'] === $visibility ? ' selected' : '') . '>' . ch247ai_h($visibility) . '</option>';
        }
        echo '</select><span class="help-block">Admin-only sources are never returned to client-scoped sessions.</span></div></div>'
            . '<div class="form-group"><label class="col-sm-2 control-label">Tags</label><div class="col-sm-8"><input class="form-control" name="tags" value="' . ch247ai_h((string) $row['tags']) . '" placeholder="comma,separated,tags"></div></div>'
            . '<div class="form-group"><label class="col-sm-2 control-label">Body</label><div class="col-sm-8"><textarea class="form-control" name="body" rows="14" required>' . ch247ai_h((string) $row['body']) . '</textarea>'
            . '<span class="help-block">Plain text. Split with blank lines — chunks are created automatically and indexed for search.</span></div></div>'
            . '<div class="form-group"><div class="col-sm-offset-2 col-sm-8"><button class="btn btn-primary">Save &amp; index</button> <a class="btn btn-default" href="' . $this->u('knowledge') . '">Back</a></div></div></form>';
    }


    /**
     * Executive war room. Renders the deterministic board report: which seats
     * reported, which are waiting on a data source (shown, never hidden),
     * cross-department findings, and the optional narration clearly labelled
     * as narration rather than as a finding.
     */
    protected function board()
    {
        $this->nav();
        // The board surfaces revenue, receivables and customer counts — it is
        // not a public admin page. Same read permission as every other AI view.
        if (!Rbac::adminCan(Rbac::AI_READ)) {
            echo '<div class="alert alert-warning">You need the AI read permission to view the executive board.</div>';
            return;
        }
        $canCompose = Rbac::adminCan(Rbac::AI_MANAGE);
        $period = isset($_GET['period']) ? \Ch247Ai\Board\ExecutiveBoard::normalizePeriod($_GET['period']) : 'daily';

        echo '<h2>Executive board</h2>';
        echo '<div class="alert alert-info small">Seats are deterministic SQL packs over live WHMCS data. '
            . 'Cross-department findings are single SQL joins, not agent-to-agent conversation — no seat can state '
            . 'another seat\'s conclusion. A seat with no data feed says so instead of estimating.</div>';

        echo '<ul class="nav nav-tabs" style="margin-bottom:15px">';
        foreach (\Ch247Ai\Board\ExecutiveBoard::TYPES as $p) {
            $cls = $p === $period ? 'active' : '';
            echo '<li class="' . $cls . '"><a href="' . $this->u('board', ['period' => $p]) . '">' . ucfirst($p) . '</a></li>';
        }
        echo '</ul>';

        if ($canCompose) {
            echo '<form method="post" style="margin-bottom:15px">' . Csrf::field()
                . '<input type="hidden" name="ch247ai_action" value="compose_board">'
                . '<input type="hidden" name="period" value="' . $this->e($period) . '">'
                . '<button class="btn btn-primary btn-sm" type="submit">Compose ' . $this->e($period) . ' board now</button></form>';
        }

        $row = \Ch247Ai\Board\ExecutiveBoard::latest($period);
        if ($row === null) {
            echo '<div class="panel panel-default"><div class="panel-body text-muted">No ' . $this->e($period)
                . ' board report yet. The cron composes one on schedule, or press the button above.</div></div>';
            return;
        }

        $pack = json_decode((string) $row['metric_pack'], true) ?: [];
        $summary = isset($pack['summary']) ? $pack['summary'] : [];
        $seats = isset($pack['seats']) ? $pack['seats'] : [];
        $cross = isset($pack['cross']['findings']) ? $pack['cross']['findings'] : [];

        echo '<p class="small text-muted">Composed ' . ch247ai_dt($row['created_at']) . ' UTC — period '
            . $this->e($row['period_start']) . ' to ' . $this->e($row['period_end']) . ' — '
            . ((int) $row['metrics_only'] === 1 ? 'metrics only (no model configured)' : 'narrated') . '</p>';

        // Headline counters.
        if ($summary) {
            $sev = isset($summary['severity_counts']) ? $summary['severity_counts'] : [];
            echo '<div class="row">';
            foreach ([
                'Seats reporting' => (int) $summary['seats_ok'] . ' / ' . (int) $summary['seats_total'],
                'Critical' => (int) (isset($sev['critical']) ? $sev['critical'] : 0),
                'Warnings' => (int) (isset($sev['warn']) ? $sev['warn'] : 0),
                'Cross-department' => (int) $summary['cross_findings'],
                'Awaiting data' => (int) $summary['seats_unavailable'],
            ] as $label => $value) {
                echo '<div class="col-sm-2"><div class="ch247ai-stat"><div class="number">' . $this->e((string) $value)
                    . '</div><div class="text-muted small">' . $this->e($label) . '</div></div></div>';
            }
            echo '</div>';
        }

        // Requires attention.
        if (!empty($summary['attention'])) {
            echo '<div class="panel panel-warning"><div class="panel-heading"><strong>Requires attention</strong></div>'
                . '<table class="table table-condensed"><tbody>';
            foreach ($summary['attention'] as $a) {
                $badge = $a['severity'] === 'critical' ? 'label-danger' : 'label-warning';
                echo '<tr><td style="width:90px"><span class="label ' . $badge . '">' . $this->e(strtoupper($a['severity']))
                    . '</span></td><td style="width:110px"><code>' . $this->e($a['origin']) . '</code></td><td>'
                    . $this->e($a['text']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Cross-department findings with their SQL.
        if ($cross) {
            echo '<div class="panel panel-default"><div class="panel-heading"><strong>Cross-department findings</strong> '
                . '<span class="text-muted small">— one SQL join each, spanning two seats</span></div>'
                . '<table class="table table-striped"><thead><tr><th>Seats</th><th>Finding</th><th>Evidence</th></tr></thead><tbody>';
            foreach ($cross as $f) {
                echo '<tr><td><code>' . $this->e(implode(' + ', $f['seats'])) . '</code></td>'
                    . '<td><strong>' . $this->e($f['title']) . '</strong><br>' . $this->e($f['text']) . '</td>'
                    . '<td><code class="small">' . ch247ai_h($f['sql']) . '</code></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Seats.
        foreach ($seats as $seat) {
            $unavailable = $seat['status'] !== 'ok';
            echo '<div class="panel ' . ($unavailable ? 'panel-default' : 'panel-success') . '">'
                . '<div class="panel-heading"><strong>' . $this->e($seat['title']) . '</strong> '
                . ($unavailable ? '<span class="label label-default">NO DATA SOURCE</span>' : '') . '</div>';
            if ($unavailable) {
                echo '<div class="panel-body"><p class="text-muted"><strong>CONFIGURATION_REQUIRED</strong> — '
                    . $this->e($seat['reason']) . '</p></div></div>';
                continue;
            }
            echo '<div class="panel-body">';
            if (!empty($seat['findings'])) {
                echo '<ul class="list-unstyled" style="margin-bottom:12px">';
                foreach ($seat['findings'] as $f) {
                    $badge = $f['severity'] === 'critical' ? 'label-danger' : ($f['severity'] === 'warn' ? 'label-warning' : 'label-info');
                    echo '<li><span class="label ' . $badge . '">' . $this->e(strtoupper($f['severity'])) . '</span> '
                        . $this->e($f['text']) . '</li>';
                }
                echo '</ul>';
            }
            echo '<table class="table table-condensed"><thead><tr><th>Metric</th><th>Value</th><th>Query</th></tr></thead><tbody>';
            foreach ($seat['metrics'] as $m) {
                $value = strpos($m['metric'], 'amount') !== false
                    ? number_format((float) $m['value'], 2, '.', '')
                    : (string) (int) $m['value'];
                echo '<tr><td><code>' . $this->e($m['metric']) . '</code></td><td><strong>' . $this->e($value)
                    . '</strong></td><td><code class="small">' . ch247ai_h($m['sql']) . '</code></td></tr>';
            }
            echo '</tbody></table></div></div>';
        }

        // Narration last, explicitly labelled.
        if (!empty($row['narrative'])) {
            echo '<div class="panel panel-info"><div class="panel-heading"><strong>Narration</strong> '
                . '<span class="text-muted small">— model-written summary of the verified figures above. '
                . 'Not a source of facts.</span></div><div class="panel-body">'
                . nl2br($this->e((string) $row['narrative'])) . '</div></div>';
        }
    }

    protected function approvals()
    {
        $this->nav();
        $canApprove = Rbac::adminCan(Rbac::AI_APPROVE);
        echo '<h2>Decision inbox</h2>';
        $pending = Db::all('approvals', ['status' => 'pending'], 'id ASC', 100);
        $approved = Db::all('approvals', ['status' => 'approved'], 'id ASC', 100);

        if (!$canApprove) {
            echo '<div class="alert alert-warning small">You can see decisions here, but you need the AI approve permission to decide or execute one.</div>';
        }
        if (!Settings::bool('writes_enabled', false)) {
            echo '<div class="alert alert-info small"><strong>Execution is switched off.</strong> Decisions can be recorded, but no approved action will run until <code>writes_enabled</code> is turned on in Settings.</div>';
        }
        echo '<div class="alert alert-warning small">Approving does not execute. An approved action waits here until someone presses <strong>Execute now</strong>, then the result is verified by re-reading the database — an action that cannot be confirmed is reported as failed, never as done.</div>';

        if ($approved) {
            echo '<h4>Approved — awaiting execution</h4>';
            foreach ($approved as $row) {
                echo '<div class="panel panel-success"><div class="panel-heading"><strong>' . ch247ai_pill('approved') . ' ' . $this->e($row['agent']) . ' → <code>' . ch247ai_h($row['tool']) . '</code> · risk ' . $this->e($row['risk']) . '</strong></div><div class="panel-body">'
                    . '<p>' . $this->e((string) $row['reason']) . '</p>'
                    . ch247ai_pre(json_decode((string) $row['arguments'], true) ?: [])
                    . '<p class="small text-muted">Approved ' . ch247ai_dt($row['decided_at']) . ' UTC by admin #' . (int) $row['decided_by']
                    . ' · arguments locked to digest <code>' . ch247ai_h(substr((string) $row['args_digest'], 0, 16)) . '…</code></p>';
                if ($canApprove) {
                    echo '<form method="post" class="form-inline">' . Csrf::field()
                        . '<input type="hidden" name="ch247ai_action" value="execute_approval"><input type="hidden" name="approval_id" value="' . (int) $row['id'] . '">'
                        . '<button class="btn btn-sm btn-primary">Execute now</button></form>';
                }
                echo '</div></div>';
            }
        }

        if ($pending === []) {
            echo '<div class="panel panel-default"><div class="panel-body text-muted">No pending decisions.</div></div>';
            $this->approvalHistory();
            return;
        }
        echo '<h4>Pending decision</h4>';
        foreach ($pending as $row) {
            echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . ch247ai_pill('awaiting_approval') . ' ' . $this->e($row['agent']) . ' → <code>' . ch247ai_h($row['tool']) . '</code> · risk ' . $this->e($row['risk']) . '</strong></div><div class="panel-body">'
                . '<p>' . $this->e((string) $row['reason']) . '</p>'
                . ch247ai_pre(json_decode((string) $row['arguments'], true) ?: [])
                . '<p class="small text-muted">Requested ' . ch247ai_dt($row['created_at']) . ' UTC by ' . $this->e($row['requested_by_type'] . ' #' . $row['requested_by_id']) . '</p>';
            if ($canApprove) {
                echo '<form method="post" class="form-inline">' . Csrf::field()
                    . '<input type="hidden" name="ch247ai_action" value="decide_approval"><input type="hidden" name="approval_id" value="' . (int) $row['id'] . '">'
                    . '<input class="form-control input-sm" name="note" placeholder="Decision note (audited)"> '
                    . '<button class="btn btn-sm btn-success" name="decision" value="approved">Approve</button> '
                    . '<button class="btn btn-sm btn-danger" name="decision" value="rejected">Reject</button></form>';
            } else {
                echo '<p class="text-muted small">You need the AI approve permission to decide.</p>';
            }
            echo '</div></div>';
        }
        $this->approvalHistory();
    }

    /** Decision history, including what actually happened on execution. */
    protected function approvalHistory()
    {
        $recent = Db::query('SELECT * FROM ' . Db::t('approvals') . " WHERE status NOT IN ('pending','approved') ORDER BY id DESC LIMIT 20");
        if (!$recent) {
            return;
        }
        echo '<h4>Recent decisions</h4><div class="table-responsive"><table class="table table-striped"><thead><tr>'
            . '<th>#</th><th>Agent / tool</th><th>Risk</th><th>Status</th><th>Verified</th><th>What was confirmed</th><th>Decided</th><th>By admin</th></tr></thead><tbody>';
        foreach ($recent as $row) {
            $status = (string) $row['status'];
            $verified = isset($row['verified']) ? (int) $row['verified'] : 0;
            if ($status === 'executed' && $verified === 1) {
                $badge = '<span class="label label-success">verified</span>';
            } elseif ($status === 'failed') {
                $badge = '<span class="label label-danger">not applied</span>';
            } else {
                $badge = '<span class="text-muted">—</span>';
            }
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . $this->e($row['agent']) . ' / <code>' . ch247ai_h($row['tool']) . '</code></td><td>'
                . $this->e($row['risk']) . '</td><td>' . ch247ai_pill($status) . '</td><td>' . $badge . '</td><td class="small">'
                . $this->e((string) (isset($row['verification_note']) ? $row['verification_note'] : '')) . '</td><td>'
                . ch247ai_dt($row['decided_at']) . '</td><td>' . (int) $row['decided_by'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function runs()
    {
        $this->nav();
        if (!Rbac::adminCan(Rbac::AI_READ)) {
            echo '<div class="alert alert-danger">You need the AI read permission.</div>';
            return;
        }
        $filter = (string) ($_GET['agent'] ?? '');
        $where = $filter !== '' ? ['agent' => $filter] : [];
        $rows = Db::all('runs', $where, 'id DESC', 30);
        // Usage summary (last 7 days).
        $usage = Db::query('SELECT agent, SUM(runs) runs, SUM(tokens_in + tokens_out) tokens, SUM(cost_micros) micros FROM ' . Db::t('usage_daily') . ' WHERE day >= ? GROUP BY agent ORDER BY tokens DESC', [gmdate('Y-m-d', Clock::time() - 7 * 86400)]);
        echo '<h2>Runs &amp; usage</h2>';
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>#</th><th>Agent</th><th>Status</th><th>Source</th><th>Tools</th><th>Tokens</th><th>Started</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $tools = Db::count('tool_calls', ['run_id' => (int) $row['id']]);
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . $this->e($row['agent']) . '</td><td>' . ch247ai_pill($row['status']) . '</td><td>' . $this->e($row['source']) . '</td><td>' . (int) $tools . '</td><td>' . (int) $row['tokens_in'] . '/' . (int) $row['tokens_out'] . '</td><td>' . ch247ai_dt($row['started_at']) . '</td>'
                . '<td><a class="btn btn-xs btn-default" href="' . $this->u('run', ['id' => (int) $row['id']]) . '">Open</a></td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="8" class="text-muted">No runs yet. Ask the copilot a question or trigger an agent from the Agents page.</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<h4>Last 7 days by agent</h4><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Agent</th><th>Runs</th><th>Tokens (in+out)</th><th>Cost (micros)</th></tr></thead><tbody>';
        foreach ($usage as $row) {
            echo '<tr><td>' . $this->e($row['agent']) . '</td><td>' . (int) $row['runs'] . '</td><td>' . (int) $row['tokens'] . '</td><td>' . (int) $row['micros'] . '</td></tr>';
        }
        if ($usage === []) {
            echo '<tr><td colspan="4" class="text-muted">No usage recorded yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function runDetail()
    {
        $this->nav();
        if (!Rbac::adminCan(Rbac::AI_READ)) {
            echo '<div class="alert alert-danger">You need the AI read permission.</div>';
            return;
        }
        $id = (int) ($_GET['id'] ?? 0);
        $run = Db::first('runs', ['id' => $id]);
        if ($run === null) {
            echo '<div class="alert alert-danger">Run not found.</div>';
            return;
        }
        echo '<h2>Run #' . (int) $run['id'] . ' — ' . $this->e($run['agent']) . '</h2>'
            . '<div class="panel panel-default"><div class="panel-body">'
            . '<p><strong>Status:</strong> ' . ch247ai_pill($run['status']) . ' <strong>Source:</strong> ' . $this->e($run['source']) . ' <strong>Actor:</strong> ' . $this->e($run['actor_type'] . ' #' . $run['actor_id']) . '</p>'
            . '<p><strong>Started:</strong> ' . ch247ai_dt($run['started_at']) . ' UTC · <strong>Finished:</strong> ' . ch247ai_dt($run['finished_at']) . ' UTC · <strong>Tokens:</strong> ' . (int) $run['tokens_in'] . ' in / ' . (int) $run['tokens_out'] . ' out</p>'
            . '<h4>Input (redacted)</h4>' . ch247ai_pre(json_decode((string) $run['input'], true) ?: (string) $run['input'])
            . '<h4>Output</h4><pre class="ch247ai-pre">' . $this->e((string) $run['output']) . '</pre>'
            . '<h4>Citations</h4>';
        $citations = json_decode((string) $run['citations'], true) ?: [];
        if ($citations === []) {
            echo '<p class="text-muted">None recorded.</p>';
        } else {
            echo '<ul class="small">';
            foreach ($citations as $citation) {
                echo '<li><code>' . ch247ai_h($citation) . '</code></li>';
            }
            echo '</ul>';
        }
        echo '</div></div>';
        $calls = Db::all('tool_calls', ['run_id' => $id], 'id ASC', 100);
        echo '<h4>Tool calls</h4><div class="table-responsive"><table class="table table-striped"><thead><tr><th>#</th><th>Tool</th><th>Status</th><th>Args (redacted)</th><th>Digest</th><th>ms</th></tr></thead><tbody>';
        foreach ($calls as $call) {
            echo '<tr><td>' . (int) $call['id'] . '</td><td><code>' . ch247ai_h($call['tool']) . '</code></td><td>' . ch247ai_pill($call['status']) . '</td>'
                . '<td class="small"><code>' . ch247ai_h(mb_substr((string) $call['arguments'], 0, 200)) . '</code></td>'
                . '<td class="small"><code>' . ch247ai_h(substr((string) $call['result_digest'], 0, 12)) . '…</code></td><td>' . (int) $call['duration_ms'] . '</td></tr>';
        }
        if ($calls === []) {
            echo '<tr><td colspan="6" class="text-muted">No tool calls — this run produced no evidence (that is why its answer was refused).</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function audit()
    {
        $this->nav();
        if (!Rbac::adminCan(Rbac::AI_AUDIT)) {
            echo '<div class="alert alert-danger">You need the AI audit permission.</div>';
            return;
        }
        $chain = Audit::verifyChain(5000);
        echo '<h2>Audit chain</h2>';
        echo '<div class="alert alert-' . ($chain['valid'] ? 'success' : 'danger') . '">' . ($chain['valid']
            ? 'Hash chain intact across ' . (int) $chain['checked'] . ' records.'
            : 'CHAIN BROKEN at row #' . (int) $chain['broken_at'] . ' — investigate immediately.') . '</div>';
        $rows = Db::all('audit_log', [], 'id DESC', 50);
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Context (redacted)</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . ch247ai_dt($row['created_at']) . '</td><td>' . $this->e($row['actor_type'] . ' #' . $row['actor_id'] . ($row['actor_label'] ? ' (' . $row['actor_label'] . ')' : '')) . '</td>'
                . '<td><code>' . ch247ai_h($row['action']) . '</code></td><td>' . $this->e($row['entity_type'] . ':' . $row['entity_id']) . '</td>'
                . '<td class="small"><code style="word-break:break-all">' . ch247ai_h(substr((string) $row['context'], 0, 220)) . '</code></td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="6" class="text-muted">No audit records yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function events()
    {
        $this->nav();
        if (!Rbac::adminCan(Rbac::AI_AUDIT)) {
            echo '<div class="alert alert-danger">You need the AI audit permission.</div>';
            return;
        }
        echo '<h2>Event bus</h2><p class="text-muted small">Web hooks insert rows here only. The cron drains them into agent memory — no agent ever runs inside a customer request.</p>';
        $rows = Db::all('events', [], 'id DESC', 50);
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>#</th><th>Type</th><th>Entity</th><th>Client</th><th>Status</th><th>Attempts</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row['id'] . '</td><td><code>' . ch247ai_h($row['event_type']) . '</code></td><td>' . $this->e($row['entity_type'] . ':' . $row['entity_id']) . '</td><td>' . ((int) $row['client_id'] ?: '—') . '</td>'
                . '<td>' . ch247ai_pill($row['status']) . '</td><td>' . (int) $row['attempts'] . '</td><td>' . ch247ai_dt($row['created_at']) . '</td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="7" class="text-muted">No events captured yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    protected function settings()
    {
        $this->nav();
        if (!Rbac::adminCan(Rbac::AI_MANAGE)) {
            echo '<div class="alert alert-danger">You need the AI manage permission.</div>';
            return;
        }
        echo '<h2>Settings</h2>';
        // Kill switch — its own form (never nested inside the settings form).
        echo '<div class="panel panel-danger"><div class="panel-heading"><strong>Kill switch</strong></div><div class="panel-body">'
            . '<p>Kill switch is ' . (Settings::bool('kill_switch', false) ? '<span class="label label-danger">ENGAGED</span>' : '<span class="label label-success">off</span>')
            . ' <span class="text-muted small">— stops every model call and tool execution immediately.</span></p>'
            . '<form method="post">' . Csrf::field() . '<input type="hidden" name="ch247ai_action" value="kill_switch"><input type="hidden" name="on" value="' . (Settings::bool('kill_switch', false) ? '0' : '1') . '">'
            . '<button class="btn btn-' . (Settings::bool('kill_switch', false) ? 'success' : 'danger') . ' btn-sm">' . (Settings::bool('kill_switch', false) ? 'Release kill switch' : 'Engage kill switch') . '</button></form>'
            . '</div></div>';
        echo '<form method="post">' . Csrf::field() . '<input type="hidden" name="ch247ai_action" value="save_settings">';
        // Model provider.
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Model provider (OpenAI-compatible)</strong></div><div class="panel-body">'
            . '<div class="form-group"><label>Fast profile endpoint URL</label><input class="form-control" name="model_fast_endpoint" value="' . ch247ai_h(Settings::string('model_fast_endpoint')) . '" placeholder="https://api.openai.com/v1/chat/completions or self-hosted vLLM/Ollama gateway">'
            . '<p class="help-block">Any OpenAI-compatible /chat/completions endpoint. Self-hosted keeps data on-premise.</p></div>'
            . '<div class="form-group"><label>Fast profile model</label><input class="form-control" name="model_fast_model" value="' . ch247ai_h(Settings::string('model_fast_model')) . '" placeholder="e.g. gpt-4o-mini, llama-3.1-8b-instruct"></div>'
            . '<div class="form-group"><label>Reasoning profile endpoint (optional)</label><input class="form-control" name="model_reasoning_endpoint" value="' . ch247ai_h(Settings::string('model_reasoning_endpoint')) . '"></div>'
            . '<div class="form-group"><label>Reasoning profile model (optional)</label><input class="form-control" name="model_reasoning_model" value="' . ch247ai_h(Settings::string('model_reasoning_model')) . '"></div>'
            . '<div class="form-group"><label>Timeout seconds</label><input class="form-control" name="model_timeout_seconds" value="' . (int) Settings::int('model_timeout_seconds', 60) . '"></div>'
            . '<div class="alert alert-warning small">API keys are never stored in the database. Set <code>CH247AI_API_KEY</code> in the server environment (and per-profile <code>CH247AI_MODEL_FAST_*</code> overrides). Without a provider the module fails closed with CONFIGURATION_REQUIRED.</div>'
            . '</div></div>';
        // Budgets & limits.
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Budgets &amp; limits</strong></div><div class="panel-body">'
            . '<div class="form-group"><label>Max tool calls per run</label><input class="form-control" name="max_tool_calls_per_run" value="' . (int) Settings::int('max_tool_calls_per_run', 5) . '"></div>'
            . '<div class="form-group"><label>Run wall clock (seconds)</label><input class="form-control" name="run_wall_clock_seconds" value="' . (int) Settings::int('run_wall_clock_seconds', 45) . '"></div>'
            . '<div class="form-group"><label>Daily tokens per agent</label><input class="form-control" name="daily_tokens_per_agent" value="' . (int) Settings::int('daily_tokens_per_agent', 200000) . '"></div>'
            . '<div class="form-group"><label>Monthly platform cost cap (micros)</label><input class="form-control" name="monthly_platform_cost_micros" value="' . (int) Settings::int('monthly_platform_cost_micros', 50000000) . '"></div>'
            . '<div class="form-group"><label>Price per 1M tokens in (micros)</label><input class="form-control" name="model_price_per_mtok_in_micros" value="' . (int) Settings::int('model_price_per_mtok_in_micros', 150) . '"></div>'
            . '<div class="form-group"><label>Price per 1M tokens out (micros)</label><input class="form-control" name="model_price_per_mtok_out_micros" value="' . (int) Settings::int('model_price_per_mtok_out_micros', 600) . '"></div>'
            . '</div></div>';
        // Behaviour.
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Behaviour</strong></div><div class="panel-body">'
            . '<label class="checkbox-inline"><input type="checkbox" name="copilot_enabled" ' . (Settings::bool('copilot_enabled', true) ? 'checked' : '') . '> Copilot enabled</label> '
            . '<label class="checkbox-inline"><input type="checkbox" name="knowledge_enabled" ' . (Settings::bool('knowledge_enabled', true) ? 'checked' : '') . '> Knowledge search enabled</label> '
            . '<label class="checkbox-inline"><input type="checkbox" name="briefings_enabled" ' . (Settings::bool('briefings_enabled', true) ? 'checked' : '') . '> Daily briefings enabled</label> '
            . '<label class="checkbox-inline" title="Master switch for executing approved write actions"><input type="checkbox" name="writes_enabled" ' . (Settings::bool('writes_enabled', false) ? 'checked' : '') . '> <strong>Allow approved actions to execute</strong></label> '
            . '<label class="checkbox-inline" title="Read-only account assistant in the customer client area"><input type="checkbox" name="client_assistant_enabled" ' . (Settings::bool('client_assistant_enabled', false) ? 'checked' : '') . '> Customer account assistant</label> '
            . '<label class="checkbox-inline"><input type="checkbox" name="redact_pii" ' . (Settings::bool('redact_pii', true) ? 'checked' : '') . '> Redact PII in stored context</label>'
            . '<div class="form-group" style="margin-top:10px"><label>Briefing hour (UTC)</label><input class="form-control" name="briefing_hour" value="' . (int) Settings::int('briefing_hour', 6) . '"></div>'
            . '<div class="form-group"><label>Event max attempts</label><input class="form-control" name="event_max_attempts" value="' . (int) Settings::int('event_max_attempts', 5) . '"></div>'
            . '<div class="form-group"><label>Approval expiry (hours)</label><input class="form-control" name="approval_expiry_hours" value="' . (int) Settings::int('approval_expiry_hours', 72) . '"></div>'
            . '<div class="form-group"><label>Run retention (days)</label><input class="form-control" name="retention_days_runs" value="' . (int) Settings::int('retention_days_runs', 180) . '"></div>'
            . '<div class="form-group"><label>Event retention (days)</label><input class="form-control" name="retention_days_events" value="' . (int) Settings::int('retention_days_events', 60) . '"></div>'
            . '</div></div>';
        // Role permissions matrix.
        $roles = \Ch247Ai\Core\Whmcs::roles();
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Admin permission groups</strong></div><div class="panel-body">'
            . '<p class="small text-muted">Super admins (role #1) always have everything. Grant groups to other roles here — the tool executor checks these before every call.</p>'
            . '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Role</th>';
        foreach (Rbac::ALL_GROUPS as $group) {
            echo '<th><code>' . ch247ai_h($group) . '</code></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($roles as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            if ($roleId === Rbac::ROLE_SUPER) {
                continue;
            }
            $grants = Rbac::grantsFor($roleId);
            echo '<tr><td>' . $this->e((string) ($role['name'] ?? ('role ' . $roleId))) . '</td>';
            foreach (Rbac::ALL_GROUPS as $group) {
                echo '<td><input type="checkbox" name="grant[' . (int) $roleId . '][' . ch247ai_h($group) . ']" ' . (!empty($grants[$group]) ? 'checked' : '') . '></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div></div>';
        echo '<button class="btn btn-primary" type="submit">Save settings</button></form>';
    }

    /* ------------------------------------------------------------ helpers -- */

    protected function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
    protected function u($action, array $params = [])
    {
        $url = $this->moduleLink . '&action=' . rawurlencode($action);
        foreach ($params as $key => $value) {
            $url .= '&' . rawurlencode($key) . '=' . rawurlencode($value);
        }
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Observability + evaluation (§30/§31).
     *
     * Two things an operator needs: are the safety guarantees still holding
     * right now, and what do the numbers say about quality. A metric with no
     * samples is shown as "no data", never as a reassuring percentage.
     */
    protected function evaluations()
    {
        $this->nav();
        echo '<h2>Evaluation &amp; observability</h2>';

        if (!Rbac::adminCan(Rbac::AI_AUDIT) && !Rbac::adminCan(Rbac::AI_READ)) {
            echo '<div class="alert alert-danger">You need the AI audit or AI read permission group to view evaluation results.</div>';
            return;
        }

        if (Rbac::adminCan(Rbac::AI_MANAGE)) {
            echo '<form method="post" style="margin-bottom:14px">' . Csrf::field()
                . '<input type="hidden" name="ch247ai_action" value="run_evaluation">'
                . '<button class="btn btn-primary btn-sm">Run evaluation now</button> '
                . '<span class="small text-muted">Deterministic: no model is called.</span></form>';
        }

        // --- live safety probes -------------------------------------
        echo '<h4>Safety probes <span class="small text-muted">(run live, against this database)</span></h4>';
        $probes = \Ch247Ai\Eval\SafetyProbes::runAll();
        $failed = 0;
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Probe</th><th>Result</th><th>Detail</th></tr></thead><tbody>';
        foreach ($probes as $probe) {
            if ($probe['status'] === 'fail') {
                $failed++;
                $label = '<span class="label label-danger">FAIL</span>';
            } elseif ($probe['status'] === 'pass') {
                $label = '<span class="label label-success">pass</span>';
            } else {
                $label = '<span class="label label-default">skipped</span>';
            }
            echo '<tr><td><code>' . ch247ai_h($probe['probe']) . '</code></td><td>' . $label . '</td><td class="small">' . $this->e($probe['detail']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($failed > 0) {
            echo '<div class="alert alert-danger"><strong>' . (int) $failed . ' safety probe(s) are failing.</strong> A guarantee this platform depends on is not holding. Treat as an incident.</div>';
        }

        // --- stored metrics -----------------------------------------
        $latest = \Ch247Ai\Eval\Evaluator::latest();
        if ($latest['rows'] === []) {
            echo '<div class="alert alert-info">No evaluation has been stored yet. It runs daily from cron, or press the button above.</div>';
            return;
        }
        echo '<h4>Quality metrics <span class="small text-muted">window ' . $this->e((string) $latest['window_start']) . ' to ' . $this->e((string) $latest['window_end']) . ' (UTC)</span></h4>';
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Scope</th><th>Metric</th><th>Value</th><th>Samples</th></tr></thead><tbody>';
        foreach ($latest['rows'] as $row) {
            $agent = (string) $row['agent'];
            if ($agent === \Ch247Ai\Eval\Evaluator::PROBE_AGENT) {
                continue; // probes are shown live above
            }
            $value = (string) $row['value'];
            $samples = (int) $row['sample_size'];
            // The honest rendering: no samples means no number.
            $display = ($value === '' || $samples === 0)
                ? '<span class="text-muted">no data in this window</span>'
                : '<strong>' . ch247ai_h($value) . '</strong>';
            echo '<tr><td>' . ($agent === '*' ? '<em>platform</em>' : $this->e($agent)) . '</td><td><code>' . ch247ai_h((string) $row['metric']) . '</code></td><td>' . $display . '</td><td>' . $samples . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="small text-muted">Every figure is a SQL aggregate over recorded runs, tool calls, decisions and usage. No model grades this platform: the headline quality signal is <code>human_override_rate</code> — how often a reviewer rejected what an agent proposed.</p>';
    }
}
