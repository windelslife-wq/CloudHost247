<?php
/**
 * Knowledge base READ tool — RAG on MySQL (plan §8).
 *
 * Operators curate knowledge sources (product notes, runbooks, policies) in
 * the admin UI. Each source is split into chunks; search runs FULLTEXT on
 * MySQL (LIKE fallback elsewhere), joined back to the source for title/type.
 * Deterministic, dependency-free; embeddings are a later phase — the chunk
 * table already has the column so the swap needs no schema change.
 */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Db;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Validator;
use Ch247Ai\Tools\ToolDefinition;

function ch247ai_knowledge_search($query, $limit, $onlyPublic = false)
{
    if (!Db::tableExists('knowledge_chunks')) {
        return ['matches' => [], 'reason' => 'DATA_UNAVAILABLE: the AI knowledge base is empty or not installed yet.'];
    }
    $limit = Validator::clampInt($limit, 1, 10, 5);
    $query = trim((string) $query);
    if ($query === '') {
        return ['matches' => [], 'reason' => 'Empty query.'];
    }
    $visibility = $onlyPublic ? " AND ks.visibility = 'public'" : '';
    $from = 'FROM ' . Db::t('knowledge_chunks') . ' kc JOIN ' . Db::t('knowledge_sources') . ' ks ON ks.id = kc.source_id';
    $where = " WHERE ks.status = 'active'" . $visibility;
    $driver = Db::driver();
    if ($driver !== 'sqlite') {
        $sql = 'SELECT kc.id, ks.title, ks.type, kc.seq, kc.content, kc.token_count,
                    MATCH (kc.content) AGAINST (? IN NATURAL LANGUAGE MODE) AS score '
            . $from . $where . ' ORDER BY score DESC, kc.id ASC LIMIT ' . (int) $limit;
        $rows = Db::query($sql, [$query]);
        if ($rows === []) {
            $sql = 'SELECT kc.id, ks.title, ks.type, kc.seq, kc.content, kc.token_count, 0 AS score '
                . $from . $where . ' AND (kc.content LIKE ? OR ks.title LIKE ?) ORDER BY kc.id ASC LIMIT ' . (int) $limit;
            $like = '%' . $query . '%';
            $rows = Db::query($sql, [$like, $like]);
        }
    } else {
        $sql = 'SELECT kc.id, ks.title, ks.type, kc.seq, kc.content, kc.token_count, 0 AS score '
            . $from . $where . ' AND (kc.content LIKE ? OR ks.title LIKE ?) ORDER BY kc.id ASC LIMIT ' . (int) $limit;
        $like = '%' . $query . '%';
        $rows = Db::query($sql, [$like, $like]);
    }
    $matches = [];
    foreach ($rows as $row) {
        $matches[] = [
            'source_title' => (string) $row['title'],
            'source_type' => (string) $row['type'],
            'chunk' => (int) $row['seq'],
            'content' => (string) $row['content'],
        ];
    }
    return ['matches' => $matches, 'query' => $query];
}

function ch247ai_knowledge_search_tool()
{
    return new ToolDefinition(
        'read_knowledge',
        'read.knowledge',
        'READ',
        'Search the platform knowledge base (curated product notes, runbooks, policies). Use it to ground answers about how THIS platform works.',
        [
            'query' => ['type' => 'string', 'description' => 'Search terms'],
            'limit' => ['type' => 'integer', 'description' => 'Max matches (1-10)', 'required' => false],
        ],
        function (array $args, array $ctx) {
            if (!Settings::bool('knowledge_enabled', true)) {
                return ['_error' => 'Knowledge search is disabled.'];
            }
            $result = ch247ai_knowledge_search($args['query'], isset($args['limit']) ? $args['limit'] : 5, $ctx['scope'] === 'client');
            return $result + ['_citations' => ['mod_ch247ai_knowledge_chunks search("' . $args['query'] . '")']];
        },
        ['entity' => 'mod_ch247ai_knowledge_chunks'],
        true // client-bound: client scope only sees visibility=public sources
    );
}
