<?php
/**
 * Logo Studio projects and AI Website Builder generations.
 */

use Chs\Core\Migrator;

return [
    'id' => '0007_logo_ai',
    'description' => 'Logo studio projects + AI builder generations',
    'up' => function () {
        $m = new Migrator();

        $m->createTable('logo_projects', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->string('company_name', 120)
                ->string('industry', 48, false, 'general')
                ->string('style', 24, false, 'modern')
                ->string('palette', 24, false, 'ocean')
                ->string('icon', 32, false, 'none')
                ->string('layout', 24, false, 'wordmark')
                ->string('concept_key', 32, false, 'a')
                ->longText('svg')
                ->string('status', 16, false, 'saved')->index('status')
                ->timestamps();
        });

        $m->createTable('ai_generations', function ($t) {
            $t->id()
                ->bigInteger('client_id')->index('client_id')
                ->string('prompt', 500)
                ->string('industry', 64, false, '')
                ->string('status', 16, false, 'complete')->index('status')
                ->longText('result')
                ->string('provider', 32, false, '')
                ->string('model', 64, false, '')
                ->integer('duration_ms', false, 0)
                ->dateTime('created_at')->index('created_at');
        });
    },
];
