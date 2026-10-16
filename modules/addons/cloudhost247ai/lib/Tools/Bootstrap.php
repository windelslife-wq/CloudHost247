<?php
/** Register all Phase-1 tools (READ only). Called once from the module bootstrap. */

namespace Ch247Ai\Tools;

use Ch247Ai\Core\Db;

class Bootstrap
{
    const DISABLE_PREFIX = 'tool_disabled_';

    public static function tools()
    {
        self::loadReaders();
        return array_merge(
            Readers\ch247ai_billing_readers(),
            Readers\ch247ai_diagnostics_readers(),
            [Readers\ch247ai_knowledge_search_tool()],
            [Readers\ch247ai_metrics_tool()],
            [Readers\ch247ai_server_inventory_tool()],
            [Readers\ch247ai_provisioning_queue_tool()],
            [Readers\ch247ai_infrastructure_status_tool()],
            [Readers\ch247ai_server_health_events_tool()],
            [Readers\ch247ai_deployment_status_tool()],
            [Readers\ch247ai_deployment_timeline_tool()],
            [Readers\ch247ai_infrastructure_cost_tool()],
            [Readers\ch247ai_security_events_tool()],
            [Readers\ch247ai_fraud_review_tool()],
            [Readers\ch247ai_dependency_inventory_tool()],
            [Readers\ch247ai_incident_signals_tool()],
            Writers\ch247ai_ticket_writers(),
            Writers\ch247ai_billing_writers()
        );
    }

    /** Reader definitions are plain functions in these files. */
    protected static function loadReaders()
    {
        require_once __DIR__ . '/Readers/BillingReaders.php';
        require_once __DIR__ . '/Readers/DiagnosticsReaders.php';
        require_once __DIR__ . '/Readers/KnowledgeReaders.php';
        require_once __DIR__ . '/Readers/MetricsReaders.php';
        require_once __DIR__ . '/Readers/ServerInventoryReader.php';
        require_once __DIR__ . '/Readers/ProvisioningQueueReader.php';
        require_once __DIR__ . '/Readers/InfrastructureStatusReader.php';
        require_once __DIR__ . '/Readers/ServerHealthEventsReader.php';
        require_once __DIR__ . '/Readers/DeploymentStatusReader.php';
        require_once __DIR__ . '/Readers/DeploymentTimelineReader.php';
        require_once __DIR__ . '/Readers/InfrastructureCostReader.php';
        require_once __DIR__ . '/Readers/SecurityEventsReader.php';
        require_once __DIR__ . '/Readers/FraudReviewReader.php';
        require_once __DIR__ . '/Readers/DependencyInventoryReader.php';
        require_once __DIR__ . '/Readers/IncidentSignalsReader.php';
        require_once __DIR__ . '/Writers/TicketWriters.php';
        require_once __DIR__ . '/Writers/BillingWriters.php';
    }

    public static function register()
    {
        if (!ToolRegistry::all()) {
            ToolRegistry::registerAll(self::tools());
        }
        self::applyDisabledFlags();
    }

    /** Honor per-tool disable flags stored in settings rows (tool_disabled_<name>). */
    protected static function applyDisabledFlags()
    {
        try {
            if (Db::tableExists('settings')) {
                foreach (Db::all('settings') as $row) {
                    if (strpos((string) $row['setting'], self::DISABLE_PREFIX) === 0) {
                        ToolRegistry::setDisabled(substr((string) $row['setting'], strlen(self::DISABLE_PREFIX)), $row['value'] === '1');
                    }
                }
            }
        } catch (\Throwable $e) {
            // settings not readable — nothing disabled
        }
    }
}
