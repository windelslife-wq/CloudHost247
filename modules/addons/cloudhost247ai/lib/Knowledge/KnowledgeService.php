<?php
/**
 * Knowledge curation: create/update sources, auto-chunk content, checksum,
 * re-index. Content lives in the DB (MySQL RAG) — no external vector store.
 */

namespace Ch247Ai\Knowledge;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Core\ValidationException;
use Ch247Ai\Core\Validator;

class KnowledgeService
{
    const TYPES = ['doc', 'kb', 'policy', 'resolution'];
    const VISIBILITIES = ['admin', 'public'];

    public static function upsert($id, $type, $title, $body, $tags, $visibility)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Unknown knowledge type.');
        }
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new ValidationException('Visibility must be admin or public.');
        }
        $title = Validator::clip(trim((string) $title), 190);
        $body = trim((string) $body);
        if ($title === '' || $body === '') {
            throw new ValidationException('Title and body are required.');
        }
        if (strlen($body) > 100000) {
            throw new ValidationException('Body too large (100 KB max).');
        }
        $tags = Validator::clip(trim((string) $tags), 190);
        $checksum = Redaction::digest($title . "\n" . $body);
        $now = Clock::now();

        if ($id > 0) {
            $existing = Db::first('knowledge_sources', ['id' => (int) $id]);
            if ($existing === null) {
                throw new NotFoundException('Knowledge source not found.');
            }
            if ($existing['checksum'] === $checksum) {
                return (int) $id; // unchanged — no reindex churn
            }
            Db::update('knowledge_sources', ['id' => (int) $id], [
                'type' => $type, 'title' => $title, 'tags' => $tags, 'visibility' => $visibility,
                'checksum' => $checksum, 'version' => (int) $existing['version'] + 1, 'indexed_at' => $now, 'updated_at' => $now,
            ]);
            $sourceId = (int) $id;
            Db::delete('knowledge_chunks', ['source_id' => $sourceId]);
        } else {
            $sourceId = Db::insert('knowledge_sources', [
                'type' => $type, 'title' => $title, 'tags' => $tags, 'visibility' => $visibility,
                'version' => 1, 'checksum' => $checksum, 'status' => 'active', 'indexed_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::chunk($sourceId, $body);
        return $sourceId;
    }

    /** Split into ~900-char chunks on paragraph/sentence boundaries. */
    protected static function chunk($sourceId, $body)
    {
        $body = (string) $body;
        $paragraphs = preg_split('/\n\s*\n/', $body);
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (strlen($current) + strlen($paragraph) + 2 <= 900) {
                $current .= ($current === '' ? '' : "\n\n") . $paragraph;
            } else {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                if (strlen($paragraph) <= 900) {
                    $current = $paragraph;
                } else {
                    foreach (self::splitLong($paragraph) as $piece) {
                        $chunks[] = $piece;
                    }
                    $current = '';
                }
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        if ($chunks === []) {
            $chunks = [substr($body, 0, 900)];
        }
        $seq = 0;
        foreach ($chunks as $chunk) {
            $seq++;
            Db::insert('knowledge_chunks', [
                'source_id' => (int) $sourceId,
                'seq' => $seq,
                'content' => $chunk,
                'content_hash' => Redaction::digest($chunk),
                'embedding' => null,
                'token_count' => (int) ceil(strlen($chunk) / 4),
            ]);
        }
        return $seq;
    }

    protected static function splitLong($text)
    {
        $out = [];
        $sentences = preg_split('/(?<=[.!?])\s+/', (string) $text);
        $current = '';
        foreach ($sentences as $sentence) {
            if (strlen($current) + strlen($sentence) + 1 <= 900) {
                $current .= ($current === '' ? '' : ' ') . $sentence;
            } else {
                if ($current !== '') {
                    $out[] = $current;
                }
                $current = strlen($sentence) <= 900 ? $sentence : substr($sentence, 0, 900);
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }
        return $out;
    }

    public static function setStatus($id, $status)
    {
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new ValidationException('Status must be active or archived.');
        }
        if (Db::first('knowledge_sources', ['id' => (int) $id]) === null) {
            throw new NotFoundException('Knowledge source not found.');
        }
        Db::update('knowledge_sources', ['id' => (int) $id], ['status' => $status, 'updated_at' => Clock::now()]);
        return true;
    }

    public static function delete($id)
    {
        // Knowledge content is operator-curated documentation, not customer
        // history: deletion is allowed, chunks cascade.
        Db::delete('knowledge_chunks', ['source_id' => (int) $id]);
        return Db::delete('knowledge_sources', ['id' => (int) $id]);
    }

    public static function get($id)
    {
        $row = Db::first('knowledge_sources', ['id' => (int) $id]);
        if ($row === null) {
            throw new NotFoundException('Knowledge source not found.');
        }
        $chunks = Db::all('knowledge_chunks', ['source_id' => (int) $id], 'seq ASC');
        $row['body'] = implode("\n\n", array_map(function ($c) {
            return $c['content'];
        }, $chunks));
        return $row;
    }

    public static function search($query, $limit = 20)
    {
        $like = '%' . trim((string) $query) . '%';
        if ($query === '') {
            return Db::all('knowledge_sources', [], 'updated_at DESC', (int) $limit);
        }
        return Db::query('SELECT * FROM ' . Db::t('knowledge_sources') . ' WHERE title LIKE ? OR tags LIKE ? ORDER BY updated_at DESC LIMIT ' . (int) $limit, [$like, $like]);
    }
}
