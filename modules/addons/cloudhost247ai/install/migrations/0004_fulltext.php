<?php
/** FULLTEXT index for MySQL knowledge search (SQLite tests use LIKE fallback). */

use Ch247Ai\Core\Db;

return [
    'id' => '0004_fulltext',
    'description' => 'FULLTEXT index on knowledge chunks (MySQL only)',
    'up' => function () {
        if (Db::driver() === 'mysql') {
            $exists = Db::query("SELECT index_name AS name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_type = 'FULLTEXT'", [Db::t('knowledge_chunks')]);
            if ($exists === []) {
                Db::exec('ALTER TABLE ' . Db::t('knowledge_chunks') . ' ADD FULLTEXT INDEX ft_ch247ai_chunk_content (content)');
            }
        }
    },
];
