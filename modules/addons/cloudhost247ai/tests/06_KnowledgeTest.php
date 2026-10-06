<?php
/** Knowledge base: chunking, hashing, search, visibility, archiving. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Knowledge\KnowledgeService;
use Ch247Ai\Core\Db;

ch247ai_boot();
ch247ai_freeze();

T::section('Short sources stay whole; long sources split at ~900 chars');
$p = function ($word) {
    return str_repeat($word . ' ', 100); // ~500-600 chars per paragraph
};
$long = $p('refund') . "\n\n" . $p('invoice') . "\n\n" . $p('credit');
$id = KnowledgeService::upsert(0, 'kb', 'Long policy', $long, 'policy', 'public');
T::ok('source created', (int) $id > 0);
$chunks = Db::all('knowledge_chunks', ['source_id' => $id], 'seq ASC');
T::eq('three ~500-char paragraphs become three chunks', 3, count($chunks));
T::eq('seq numbers are 1..n', [1, 2, 3], array_map(function ($c) {
    return (int) $c['seq'];
}, $chunks));
T::ok('content hash per chunk', strlen((string) $chunks[0]['content_hash']) === 64);
T::ok('token estimate stored', (int) $chunks[0]['token_count'] > 0);
$source = Db::first('knowledge_sources', ['id' => $id]);
T::eq('version 1', 1, (int) $source['version']);
T::ok('checksum stored', strlen((string) $source['checksum']) === 64);

$shortId = KnowledgeService::upsert(0, 'kb', 'Short note', "One small paragraph.", 'note', 'admin');
T::eq('short content stays a single chunk', 1, Db::count('knowledge_chunks', ['source_id' => $shortId]));

T::section('Re-saving unchanged content does not churn');
KnowledgeService::upsert($id, 'kb', 'Long policy', $long, 'policy', 'public');
T::eq('version unchanged', 1, (int) Db::first('knowledge_sources', ['id' => $id])['version']);

T::section('Editing re-chunks and bumps the version');
KnowledgeService::upsert($id, 'kb', 'Long policy v2', "Refunds are issued within 14 days of purchase.\n\nEverything else unchanged.", 'policy', 'public');
T::eq('version bumped', 2, (int) Db::first('knowledge_sources', ['id' => $id])['version']);
T::eq('old chunks replaced by the new set', 1, Db::count('knowledge_chunks', ['source_id' => $id]));

T::section('Search finds content (SQLite LIKE path = MySQL FULLTEXT fallback equivalent)');
$id2 = KnowledgeService::upsert(0, 'doc', 'Runbook: DNS outage', "When DNS resolution fails platform-wide, first check the resolvers, then upstream registrar status.", 'runbook,dns', 'admin');
$hits = \Ch247Ai\Tools\Readers\ch247ai_knowledge_search('refund', 5);
T::ok('refund query hits the policy', count($hits['matches']) >= 1);
$hitTitles = [];
foreach ($hits['matches'] as $match) {
    $hitTitles[] = $match['source_title'];
}
T::ok('matched the edited source', in_array('Long policy v2', $hitTitles, true));
$miss = \Ch247Ai\Tools\Readers\ch247ai_knowledge_search('quantum-unicorn-widget', 5);
T::eq('no match returns empty set', [], $miss['matches']);

T::section('Visibility: admin-only sources never reach client scope');
$publicOnly = \Ch247Ai\Tools\Readers\ch247ai_knowledge_search('refund', 5, true);
foreach ($publicOnly['matches'] as $match) {
    T::ok('client scope sees only public sources', $match['source_type'] !== 'doc');
}
$adminScope = \Ch247Ai\Tools\Readers\ch247ai_knowledge_search('DNS', 5, false);
T::ok('admin scope sees the runbook', count($adminScope['matches']) >= 1);

T::section('Validation');
T::throws('bad type rejected', function () {
    KnowledgeService::upsert(0, 'nonsense', 'T', 'B', '', 'admin');
}, \Ch247Ai\Core\ValidationException::class);
T::throws('bad visibility rejected', function () {
    KnowledgeService::upsert(0, 'kb', 'T', 'B', '', 'everyone');
}, \Ch247Ai\Core\ValidationException::class);
T::throws('empty title rejected', function () {
    KnowledgeService::upsert(0, 'kb', '  ', 'B', '', 'admin');
}, \Ch247Ai\Core\ValidationException::class);

T::section('Archive removes from search, delete removes entirely');
KnowledgeService::setStatus($id2, 'archived');
$after = \Ch247Ai\Tools\Readers\ch247ai_knowledge_search('DNS', 5, false);
foreach ($after['matches'] as $match) {
    T::ok('archived source not returned', $match['source_title'] !== 'Runbook: DNS outage');
}
KnowledgeService::delete($id2);
T::eq('deleted source gone', 0, Db::count('knowledge_sources', ['id' => $id2]));
T::eq('chunks cascade', 0, Db::count('knowledge_chunks', ['source_id' => $id2]));

T::section('Tool wraps search with citations');
ch247ai_as_super_admin();
$result = \Ch247Ai\Tools\ToolExecutor::execute('admin_copilot', 'read_knowledge', ['query' => 'refund']);
T::ok('tool returns matches', count($result->data['matches']) >= 1);
T::ok('citation names the chunk table', strpos($result->citations[0], 'knowledge_chunks') !== false);

T::finish();
