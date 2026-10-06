<?php
/**
 * WHOIS lookup cache — keeps registry load down and answers instant.
 */

use Chs\Core\Migrator;

return [
    'id' => '0008_whois_cache',
    'description' => 'WHOIS response cache',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('whois_cache', function ($t) {
            $t->id()
                ->string('domain', 255)->unique('domain')
                ->string('tld', 63)->index('tld')
                ->string('server', 120, false, '')
                ->longText('raw')
                ->longText('parsed')
                ->dateTime('fetched_at')
                ->dateTime('expires_at')->index('expires_at');
        });
    },
];
