<?php
/** Tag only new node-uptime samples so retention never touches legacy metrics. */
use Ch247Apps\Core\Migrator;

return [
    'id' => '0014_agent_uptime_source',
    'description' => 'Nullable source marker and age index for narrowly scoped node uptime retention.',
    'up' => function (Migrator $m) {
        $m->addColumn('metrics', 'source', 'VARCHAR(40) NULL');
        $m->addIndex('metrics', ['source', 'sampled_at', 'id'], false,
            'idx_ch247apps_metric_source_age');
    },
];
