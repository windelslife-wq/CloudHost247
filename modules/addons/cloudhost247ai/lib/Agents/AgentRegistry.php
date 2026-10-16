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
    const READ_TOOLS_ADMIN = ['read_clients', 'read_client_details', 'read_invoices', 'read_payments', 'read_orders', 'read_services', 'read_tickets', 'read_domains', 'read_products', 'read_credit', 'read_metrics', 'read_server_inventory', 'read_provisioning_queue', 'read_infrastructure_status', 'read_server_health_checks', 'read_deployment_status', 'read_deployment_timeline', 'read_infrastructure_costs', 'read_security_auth_events', 'read_fraud_review_queue', 'read_dependency_inventory', 'read_incident_failure_signals', 'read_knowledge'];
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
            new AgentDefinition(
                'infrastructure_guardian',
                'Infrastructure Status Guardian',
                'Reports the latest stored provider status for servers managed by CloudHost247 Services. It does not report independent uptime or resource utilization.',
                'on_demand',
                ['read_infrastructure_status'],
                'You report only the latest stored provider status and timestamps returned by the infrastructure reader. It represents CloudHost247 Services provider synchronization, not an independent uptime probe and not CPU, memory, disk, or network telemetry. Explicitly state these limitations and do not infer incidents from stale or missing data.',
                false
            ),
            new AgentDefinition(
                'server_health_agent',
                'Server Health Check Reporter',
                'Reports explicit passed and failed provider health-check events recorded by CloudHost247 Services. It is not continuous monitoring.',
                'on_demand',
                ['read_server_health_checks'],
                'You report only the explicit passed/failed provider health-check events returned by the tool and their timestamps. A missing event is not evidence of good health. Do not claim continuous uptime, CPU, memory, disk, or network metrics. State clearly that the source covers only servers managed by CloudHost247 Services.',
                false
            ),
            new AgentDefinition(
                'provisioning_agent',
                'Provisioning Status Agent',
                'Reports WHMCS services currently in Pending status with their age since registration. Does not claim a provisioning job failed or is stuck.',
                'on_demand',
                ['read_provisioning_queue'],
                'You report the WHMCS services currently marked Pending and their age since regdate. This is a status snapshot, not a provisioning event log. Never call a service failed or stuck based only on age; state that a human should verify the service in WHMCS and its provisioning module. Do not invent outcomes.',
                false
            ),
            new AgentDefinition(
                'deployment_agent',
                'Deployment Status Agent',
                'Reports recorded App Cloud deployment status, progress, timing, and machine error codes. It does not infer application availability.',
                'on_demand',
                ['read_deployment_status'],
                'You summarize recorded deployment status and progress from the App Cloud deployment reader. You may report status, action, progress, steps and timestamps only when returned by the tool. Do not expose or infer deployment payloads, logs, customer identities, or free-text errors. Recorded deployment success does not prove the deployed application is reachable or healthy.',
                false
            ),
            new AgentDefinition(
                'security_sentinel',
                'Security Login Monitor',
                'Reports conservatively classified login-event counts and recent successful/failed login records from the indexed security event projection.',
                'on_demand',
                ['read_security_auth_events'],
                'You report only counts and records from the derived WHMCS login event table. Activity descriptions are classified only when they match explicit supported success/failure phrases; unrecognized lines are omitted. Counts do not prove an attack or compromise. Never claim an account takeover, identify a threat actor, or expose IP hashes.',
                false
            ),
            new AgentDefinition(
                'fraud_abuse_guardian',
                'Fraud Review Reporter',
                'Lists WHMCS orders already marked Fraud for administrative review. It does not score orders or infer abuse.',
                'on_demand',
                ['read_fraud_review_queue'],
                'You report only WHMCS orders returned with status Fraud. This is an existing WHMCS decision, not your own risk assessment. Do not assign new fraud scores, infer abuse, recommend account sanctions, or claim a status is correct. Escalate decisions to an authorized human reviewer.',
                false
            ),
            new AgentDefinition(
                'vulnerability_analyst',
                'Dependency Inventory Reporter',
                'Reports declared package versions from local Composer/npm lock files. It does not perform CVE matching or establish whether deployed software is safe.',
                'on_demand',
                ['read_dependency_inventory'],
                'You report only package names and declared versions present in local lock files. These files do not prove what is deployed and this installation has no vulnerability advisory feed. Never label a package vulnerable or safe, invent CVEs, or imply the server itself was scanned. State the inventory limitations clearly.',
                false
            ),
            new AgentDefinition(
                'incident_commander',
                'Operational Failure Signal Reporter',
                'Lists recent failed App Cloud deployments, infrastructure provisioning jobs, and provider health checks. It does not correlate or manage incidents.',
                'on_demand',
                ['read_incident_failure_signals'],
                'You report only the separate operational failures returned by the reader. These events are not confirmed or correlated incidents. Do not infer root causes, customer impact, severity, blast radius, or recovery. Recommend that an authorized human review the source systems; do not claim to coordinate or execute response.',
                false
            ),
            new AgentDefinition(
                'root_cause_analyst',
                'Deployment Timeline Analyst',
                'Reports the ordered structured steps and recorded failure point for one App Cloud deployment; it does not claim an underlying root cause.',
                'on_demand',
                ['read_deployment_timeline'],
                'You summarize only the recorded deployment state and ordered structured steps returned by the tool. A failed step/error code is where execution stopped, not proof of the underlying cause. Do not invent causes, expose logs, payloads, context or customer data, or claim application availability. Ask for a deployment id if none is provided.',
                false
            ),
            new AgentDefinition(
                'cloud_cost_guardian',
                'Cloud Cost Guardian',
                'Reports manually imported provider invoice costs, grouped by provider, currency, and billing period. Does not claim provider verification or utilization.',
                'on_demand',
                ['read_infrastructure_costs'],
                'You report only values from the manually imported cost ledger. State that records are manually supplied and not independently verified by provider APIs. Compare only matching provider, currency, and exact billing periods. Never convert currencies, infer utilization/margins, or make cost-saving recommendations without verified cost and usage data.',
                false
            ),
            new AgentDefinition(
                'pricing_analyst',
                'Product Pricing Reporter',
                'Reports current WHMCS product prices and recent order activity. It does not recommend price changes or infer utilization.',
                'on_demand',
                ['read_products', 'read_orders'],
                'You report only current WHMCS product catalog prices and order records returned by tools. Order volume is not utilization, cost, margin, or willingness-to-pay. Do not recommend price changes without approved pricing policy and usage/cost evidence. Clearly state when pricing history or utilization data is unavailable.',
                false
            ),
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
            new AgentDefinition(
                'asset_inventory_agent',
                'WHMCS Server Inventory Agent',
                'Reports configured WHMCS server records. It does not claim live health, utilization, hardware, licensing, or datacenter inventory.',
                'on_demand',
                ['read_server_inventory'],
                'You report only non-secret fields returned by the WHMCS server inventory tool. This is configuration data, not live reachability, health, utilization, hardware, licenses, or datacenter status. Clearly state when asked for any of those unavailable details. Never infer or invent them.',
                false
            ),
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
