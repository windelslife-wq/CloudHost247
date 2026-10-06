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

    public function __construct($slug, $name, $description, $runMode, array $tools, $promptRole, $defaultEnabled = true)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->description = $description;
        $this->runMode = $runMode;
        $this->tools = $tools;
        $this->promptRole = $promptRole;
        $this->defaultEnabled = $defaultEnabled;
    }
}

class AgentRegistry
{
    const READ_TOOLS_ADMIN = ['read_clients', 'read_client_details', 'read_invoices', 'read_payments', 'read_orders', 'read_services', 'read_tickets', 'read_domains', 'read_products', 'read_credit', 'read_metrics', 'read_knowledge'];
    const READ_TOOLS_CLIENT = ['read_client_details', 'read_invoices', 'read_services', 'read_domains', 'read_tickets', 'read_orders', 'read_knowledge'];
    const DIAG_TOOLS = ['read_diag_dns_lookup', 'read_diag_dns_health', 'read_diag_dns_propagation', 'read_diag_domain_dns_validation', 'read_diag_ssl_checker', 'read_diag_spf_checker', 'read_diag_dmarc_checker', 'read_diag_dkim_checker', 'read_diag_mx_checker', 'read_diag_ns_checker', 'read_diag_cname_lookup', 'read_diag_reverse_ip', 'read_diag_domain_whois', 'read_diag_website_status', 'read_diag_port_checker', 'read_diag_asn_lookup', 'read_diag_ip_blacklist'];

    /** @return AgentDefinition[] */
    public static function all()
    {
        return [
            new AgentDefinition(
                'admin_copilot',
                'Admin Copilot',
                'Interactive assistant for administrators in the WHMCS admin area. Answers grounded in live WHMCS data via tools; never acts, only reads.',
                'interactive',
                array_merge(self::READ_TOOLS_ADMIN, self::DIAG_TOOLS),
                'You are the Admin Copilot for a WHMCS hosting platform. You answer administrators\' questions strictly from tool results. You never perform actions.'
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
                'Resolution Pro (draft-only)',
                'Drafts support ticket replies for human approval. Drafts are stored, never sent; a human sends them.',
                'on_demand',
                ['read_tickets', 'read_clients', 'read_client_details', 'read_services', 'read_knowledge'],
                'You draft support replies. Every factual claim about the customer must cite a tool result. Drafts are only saved for review.'
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
                'Identifies overdue invoices and drafts dunning communications for approval. Never sends anything itself.',
                'scheduled',
                ['read_invoices', 'read_clients', 'read_client_details', 'read_payments'],
                'You identify overdue balances and draft reminder texts. Sending is always a human decision.'
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
