<?php
/**
 * Domain Broker — 0007: record how a transfer was confirmed complete.
 *
 * completion_basis   'registry_rdap'  — the registry (RDAP) showed the gaining
 *                                       registrar as sponsor; registry_check
 *                                       holds that evidence.
 *                    'attested_finance' — registry checking was off; a finance
 *                                       administrator attested completion with
 *                                       a registrar_reference.
 *                    NULL             — completed before this migration, or not
 *                                       yet completed. Fund release refuses NULL.
 *
 * @package DomainBroker
 */

use DomainBroker\Core\Migrator;

return [
    'id' => '0007_transfer_completion_basis',
    'description' => 'Record the evidence basis for a completed domain transfer.',
    'up' => function (Migrator $m) {
        $m->addColumn('transfers', 'completion_basis', 'VARCHAR(20) NULL');
        $m->addColumn('transfers', 'registrar_reference', 'VARCHAR(190) NULL');
        $m->addColumn('transfers', 'registry_check', 'TEXT NULL');
    },
];
