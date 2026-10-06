<?php
/** Register all Phase-1 tools (READ only). Called once from the module bootstrap. */

namespace Ch247Ai\Tools;

use Ch247Ai\Core\Db;

class Bootstrap
{
    public static function tools()
    {
        self::loadReaders();
        return array_merge(
            Readers\ch247ai_billing_readers(),
            Readers\ch247ai_diagnostics_readers(),
            [Readers\ch247ai_knowledge_search_tool()],
            [Readers\ch247ai_metrics_tool()]
        );
    }

    /** Reader definitions are plain functions in these files. */
    protected static function loadReaders()
    {
        require_once __DIR__ . '/Readers/BillingReaders.php';
        require_once __DIR__ . '/Readers/DiagnosticsReaders.php';
        require_once __DIR__ . '/Readers/KnowledgeReaders.php';
        require_once __DIR__ . '/Readers/MetricsReaders.php';
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
                    if (strpos((string) $row['setting'], 'tool_disabled_') === 0) {
                        ToolRegistry::setDisabled(substr((string) $row['setting'], 15), $row['value'] === '1');
                    }
                }
            }
        } catch (\Throwable $e) {
            // settings not readable — nothing disabled
        }
    }
}
