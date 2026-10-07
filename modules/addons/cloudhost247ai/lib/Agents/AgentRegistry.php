<?php
/**
 * Agent registry — the single source of truth for what agents exist, which
 * tools they may call (tool_allowlist JSON, synced into the agents table),
 * and how they run. Permissions live HERE, never in prompts.
 *
 * Tier A (this phase): 9 agents. Tier B (infrastructure, provisioning,
 * security, fraud) is intentionally absent until their data collectors exist.
 */

namespace Ch247Ai\Agents;

class AgentDefinition
{
    public $slug;
    public $name;
    public $description;
    public $runMode;      // interactive | scheduled | on_demand
    public $tools;        // tool names the agent may ever call
    public $promptRole;   // one-paragraph role charter for the system prompt
    public $defaultEnabled;

    /**
     * Collector this agent needs before it can ever run. '' means the data
     * already exists. Anything else makes the agent a declared-but-inert
     * roadmap entry: the runtime refuses it with CONFIGURATION_REQUIRED even
     * if an operator flips `enabled` in the database, because the alternative
     * is an agent whose entire job is reading data the platform does not have.
     */
    public $missingCollector;

    public function __construct($slug, $name, $description, $runMode, array $tools, $promptRole, $defaultEnabled = true, $missingCollector = '')
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->description = $description;
        $this->runMode = $runMode;
        $this->tools = $tools;
        $this->promptRole = $promptRole;
        $this->defaultEnabled = $defaultEnabled;
        $this->missingCollector = (string) $missingCollector;
    }

    /** True when the platform has the data this agent would read. */
    public function isAvailable()
    {
        return $this->missingCollector === '';
    }
}

class AgentRegistry
{
    const READ_TOOLS_ADMIN = ['read_clients', 'read_client_details', 'read_invoices', 'read_payments', 'read_orders', 'read_services', 'read_tickets', 'read_domains', 'read_products', 'read_credit', 'read_metrics', 'read_knowledge'];
    const READ_TOOLS_CLIENT = ['read_client_details', 'read_invoices', 'read_services', 'read_domains', 'read_tickets', 'read_orders', 'read_knowledge'];
    const DIAG_TOOLS = ['read_diag_dns_lookup', 'read_diag_dns_health', 'read_diag_dns_propagation', 'read_diag_domain_dns_validation', 'read_diag_ssl_checker', 'read_diag_spf_checker', 'read_diag_dmarc_checker', 'read_diag_dkim_checker', 'read_diag_mx_checker', 'read_diag_ns_checker', 'read_diag_cname_lookup', 'read_diag_reverse_ip', 'read_diag_domain_whois', 'read_diag_website_status', 'read_diag_port_checker', 'read_diag_asn_lookup', 'read_diag_ip_blacklist'];

    /** @return AgentDefinition[] */
    /**
     * The only tools a signed-in customer's assistant may ever call.
     *
     * Every one is marked client-bound, which makes ToolExecutor force the
     * session's own client id into the SQL WHERE clause. Deliberately
     * excluded: read_clients (lists every customer), read_metrics
     * (platform-wide figures) and all diagnostics.
     */
    const READ_TOOLS_CUSTOMER = [
        'read_client_details',
        'read_invoices',
        'read_payments',
        'read_orders',
        'read_services',
        'read_tickets',
        'read_domains',
        'read_credit',
        'read_knowledge',
    ];

    public static function all()
    {
        return array_merge([
            new AgentDefinition(
                'admin_copilot',
                'Admin Copilot',
                'Interactive assistant for administrators in the WHMCS admin area. Answers grounded in live WHMCS data via tools; never acts, only reads.',
                'interactive',
                array_merge(self::READ_TOOLS_ADMIN, self::DIAG_TOOLS),
                'You are the Admin Copilot for a WHMCS hosting platform. You answer administrators\' questions strictly from tool results. You never perform actions.'
            ),
            new AgentDefinition(
                'customer_assistant',
                'Customer Assistant',
                'Answers a signed-in customer\'s questions about their own account: their invoices, services, domains, orders and tickets. Read-only, and every query is forced to their own client id.',
                'interactive',
                self::READ_TOOLS_CUSTOMER,
                'You are the account assistant for ONE signed-in customer of a hosting provider. You may only discuss that customer\'s own account, and every fact must come from a tool result. You cannot see other customers and must never speculate about them. You cannot change anything: no payments, no cancellations, no settings. If you are asked to act, say plainly that you can only look things up and point the customer to the right page or to support. If a tool returns nothing, say you could not find it rather than guessing.',
                false
            ),
            new AgentDefinition(
                'billing_reconciliation',
                'Billing Reconciliation Agent',
                'Cross-checks orders, invoices and payments; drafts discrepancy findings for human review. Read-only.',
                'scheduled',
                ['read_invoices', 'read_payments', 'read_orders', 'read_credit', 'read_metrics', 'read_knowledge'],
                'You reconcile billing records. You report discrepancies with exact record ids. You never adjust anything.'
            ),
            new AgentDefinition(
                'ledger_agent',
                'Ledger Agent',
                'Maintains the daily operations ledger: deterministic metric packs appended to the briefing, no free-form conclusions.',
                'scheduled',
                ['read_metrics', 'read_invoices', 'read_payments'],
                'You produce precise numeric ledgers. Every number must come from a tool result.'
            ),
            new AgentDefinition(
                'resolution_pro',
                'Resolution Pro',
                'Drafts support ticket replies and proposes them for approval. Nothing reaches a customer until an administrator approves it; the approved text is then posted verbatim.',
                'on_demand',
                ['read_tickets', 'read_clients', 'read_client_details', 'read_services', 'read_knowledge', 'write_ticket_reply', 'write_ticket_note', 'write_ticket_status'],
                'You draft support replies. Every factual claim about the customer must cite a tool result. You never post directly: you propose, a human approves, and the approved text is posted unchanged.'
            ),
            new AgentDefinition(
                'dns_domain_agent',
                'DNS & Domain Agent',
                'Monitors domains: DNS health, propagation, nameservers, WHOIS changes — via the platform diagnostics tools.',
                'scheduled',
                array_merge(['read_domains'], array_intersect(self::DIAG_TOOLS, ['read_diag_dns_lookup', 'read_diag_dns_health', 'read_diag_dns_propagation', 'read_diag_domain_dns_validation', 'read_diag_ns_checker', 'read_diag_mx_checker', 'read_diag_cname_lookup', 'read_diag_reverse_ip', 'read_diag_domain_whois'])),
                'You monitor DNS and domain health. You report only what the diagnostics tools returned.'
            ),
            new AgentDefinition(
                'ssl_guardian',
                'SSL/TLS Guardian',
                'Daily TLS certificate expiry and validity checks for hosted domains via the ssl_checker diagnostic.',
                'scheduled',
                ['read_domains', 'read_diag_ssl_checker', 'read_diag_website_status'],
                'You track certificate expiry. You report days-remaining exactly as measured.'
            ),
            new AgentDefinition(
                'revenue_analyst',
                'Revenue Analyst',
                'Daily revenue packs: payments, unpaid exposure, renewals due. Numbers only, from tools.',
                'scheduled',
                ['read_metrics', 'read_invoices', 'read_payments', 'read_orders', 'read_products'],
                'You analyse revenue. Every figure must come from a tool result with its SQL citation.'
            ),
            new AgentDefinition(
                'collections_agent',
                'Collections Agent',
                'Identifies overdue invoices and proposes reminders for approval. It never moves money and never marks anything paid.',
                'scheduled',
                ['read_invoices', 'read_clients', 'read_client_details', 'read_payments', 'write_invoice_reminder'],
                'You identify overdue balances and propose reminder sends. Sending is always a human decision. You have no ability to record a payment, apply credit or alter an invoice.'
            ),
            new AgentDefinition(
                'customer_intelligence',
                'Customer Intelligence Agent',
                'Summarises a single customer\'s footprint (services, domains, tickets, invoices) for staff. Read-only.',
                'on_demand',
                ['read_clients', 'read_client_details', 'read_services', 'read_domains', 'read_tickets', 'read_invoices'],
                'You summarise one customer from their records. No speculation beyond the data.'
            ),
            new AgentDefinition(
                'briefing_composer',
                'Briefing Composer',
                'Deterministic daily briefing: assembles metric packs; optionally narrates them with one model call. Never decides anything.',
                'scheduled',
                ['read_metrics', 'read_knowledge'],
                'You narrate the daily metric pack. You may not add facts beyond the pack.'
            ),
            new AgentDefinition(
                'executive_board',
                'Executive Board',
                'Narrates the deterministic executive board report (department packs + cross-department findings). One model call, no decisions, no facts beyond the packs.',
                'scheduled',
                ['read_metrics', 'read_knowledge'],
                'You narrate an executive board report for a hosting operator. Every department figure and finding is supplied to you already verified. You may not add facts, invent a department\'s conclusion, or speculate about data marked unavailable.'
            ),
        ], self::roadmap());
    }

    /**
     * Tier C — declared, permanently inert roadmap seats.
     *
     * These appear in the registry and the admin UI so the roadmap is honest
     * and visible, but each names the collector it needs and the runtime
     * refuses to run it (CONFIGURATION_REQUIRED). An agent whose entire job is
     * to read data this platform does not collect would have nothing to do but
     * fabricate, which the safety rules forbid outright.
     *
     * @return AgentDefinition[]
     */
    public static function roadmap()
    {
        $stub = function ($slug, $name, $description, $collector) {
            return new AgentDefinition($slug, $name, $description, 'scheduled', [], '', false, $collector);
        };
        return [
            $stub('infrastructure_guardian', 'Infrastructure Guardian',
                'Server health and resource exhaustion monitoring across VPS, dedicated and shared hosting.',
                'Server telemetry collector: a cron polling provisioning-module APIs and tblservers reachability into a metrics table with retention. No CPU/RAM/disk/load feed exists in this platform.'),
            $stub('server_health_agent', 'Server Health Agent',
                'Per-server uptime, service failures, disk and memory pressure reporting.',
                'The same server telemetry collector as Infrastructure Guardian.'),
            $stub('provisioning_agent', 'Provisioning Agent',
                'Watches provisioning and activation jobs, detects stuck or failed provisioning.',
                'Normalised provisioning outcomes: provisioning state is scattered across module logs and tblhosting.domainstatus and must be collected into a structured events table first.'),
            $stub('deployment_agent', 'Deployment Agent',
                'Deployment, build and rollback health for applications.',
                'A deployment record source. This platform performs no application deployments and stores no build or release events.'),
            $stub('security_sentinel', 'Security Sentinel',
                'Suspicious authentication, account takeover indicators and security event triage.',
                'A derived, indexed auth-event table. tblactivitylog holds login records but is unindexed and too noisy for detection work.'),
            $stub('fraud_abuse_guardian', 'Fraud & Abuse Guardian',
                'Suspicious signups, abnormal ordering, payment anomalies and resource abuse.',
                'A feature-extraction layer over orders, payments and gateway responses. Highest false-positive cost of any agent; must be approval-gated when built.'),
            $stub('vulnerability_analyst', 'Vulnerability Analyst',
                'Dependency and server software vulnerability analysis.',
                'A software inventory: no record of server software versions or installed dependencies exists.'),
            $stub('incident_commander', 'Incident Commander',
                'Correlates events into incidents, coordinates response and verifies recovery.',
                'An incident system plus the monitoring feed that would create incidents. Neither exists yet.'),
            $stub('root_cause_analyst', 'Root Cause Analyst',
                'Post-incident timeline and probable-cause analysis.',
                'Logs, metrics, deployment records and an incident history. None are collected.'),
            $stub('cloud_cost_guardian', 'Cloud Cost Guardian',
                'Infrastructure cost, utilisation and hosting margin optimisation.',
                'A cost and utilisation feed from the infrastructure providers. No cost data is ingested.'),
            $stub('pricing_analyst', 'Pricing Analyst',
                'Plan utilisation, discount and pricing recommendations.',
                'The usage-metering layer. Product demand exists in orders, but plan utilisation does not.'),
            $stub('account_expansion_agent', 'Account Expansion Agent',
                'Detects customers outgrowing their plan and genuine upgrade opportunities.',
                'Per-service resource usage metering. Upgrade signals without usage data would be guesswork.'),
            $stub('internal_it_agent', 'Internal IT Agent',
                'Internal staff support, access requests and equipment workflow.',
                'An internal IT request system. No system of record exists.'),
            $stub('hr_assistant', 'HR Assistant',
                'Internal HR policy lookup and employee documentation.',
                'An HR system of record. None exists in this platform.'),
            $stub('recruitment_assistant', 'Recruitment Assistant',
                'Candidate pipeline organisation and interview scheduling.',
                'An applicant tracking system. None exists in this platform.'),
            $stub('asset_inventory_agent', 'Asset & Inventory Agent',
                'Hardware, licence and datacenter asset tracking with renewal detection.',
                'An asset register. Servers exist in tblservers but hardware, licences and datacenter resources are not inventoried.'),
        ];
    }

    /** @return AgentDefinition|null */
    public static function find($slug)
    {
        foreach (self::all() as $agent) {
            if ($agent->slug === $slug) {
                return $agent;
            }
        }
        return null;
    }

    public static function slugs()
    {
        $out = [];
        foreach (self::all() as $agent) {
            $out[] = $agent->slug;
        }
        return $out;
    }

    /** Agents whose data actually exists (the operable set). @return AgentDefinition[] */
    public static function available()
    {
        return array_values(array_filter(self::all(), function ($a) { return $a->isAvailable(); }));
    }

    /** Declared-but-inert roadmap seats. @return AgentDefinition[] */
    public static function unavailable()
    {
        return array_values(array_filter(self::all(), function ($a) { return !$a->isAvailable(); }));
    }

    /** Is the agent enabled in the DB (default: registry default)? */
    public static function isEnabled($slug)
    {
        try {
            $row = \Ch247Ai\Core\Db::first('agents', ['agent' => $slug]);
            if ($row !== null) {
                return (int) $row['enabled'] === 1;
            }
        } catch (\Throwable $e) {
            // fall through to registry default
        }
        $def = self::find($slug);
        return $def === null ? false : $def->defaultEnabled;
    }

    /** Sync registry definitions into the agents table (allowlist as JSON, per plan §7). */
    public static function sync()
    {
        $db = \Ch247Ai\Core\Db::class;
        foreach (self::all() as $def) {
            $allowlist = json_encode(array_values($def->tools), JSON_UNESCAPED_SLASHES);
            $existing = $db::first('agents', ['agent' => $def->slug]);
            if ($existing === null) {
                $db::insert('agents', [
                    'agent' => $def->slug,
                    'name' => $def->name,
                    'description' => $def->description,
                    'run_mode' => $def->runMode,
                    'tool_allowlist' => $allowlist,
                    'enabled' => $def->defaultEnabled ? 1 : 0,
                    'created_at' => \Ch247Ai\Core\Clock::now(),
                ]);
            } else {
                // Registry wins for charter metadata + allowlist; enabled is operator-controlled.
                $db::update('agents', ['agent' => $def->slug], [
                    'name' => $def->name,
                    'description' => $def->description,
                    'run_mode' => $def->runMode,
                    'tool_allowlist' => $allowlist,
                ]);
            }
        }
    }
}
