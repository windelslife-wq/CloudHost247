<?php
/** Add the staged VM spec used while rebuild/resize operations are asynchronous. */

use Ch247Apps\Core\Db;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0008_add_customer_server_pending_spec',
    'description' => 'Track desired rebuild/resize spec until provider completion is confirmed.',
    'up' => function (Migrator $m) {
        $m->addColumn('customer_servers', 'pending_spec', Db::isSqlite() ? 'TEXT NULL' : 'LONGTEXT NULL');
    },
];
