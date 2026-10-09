<?php
/**
 * Bulk domain search.
 *
 * Accepts a pasted list of domains and/or bare keywords (expanded across the
 * configured TLD catalogue), dedupes, caps at the configured maximum, and
 * records a search. Lists larger than the sync threshold are processed
 * asynchronously by the DOMAIN_BULK_SEARCH worker job — an HTTP request never
 * blocks on a registrar flood. Results are paginated, exportable to CSV, and
 * every bulk run is audited once (not per row).
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\Http;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\RateLimiter;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Core\Validator;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

class BulkSearchService
{
    /** @var JobQueue|null test seam */
    private $queue;
    /** @var DomainSearchService|null test seam */
    private $search;

    public function __construct(JobQueue $queue = null, DomainSearchService $search = null)
    {
        $this->queue = $queue;
        $this->search = $search;
    }

    protected function queue()
    {
        if ($this->queue === null) {
            $this->queue = new JobQueue();
        }
        return $this->queue;
    }

    protected function search()
    {
        if ($this->search === null) {
            $this->search = new DomainSearchService();
        }
        return $this->search;
    }

    /**
     * Submit a bulk search.
     *
     * @param string   $input    newline/comma/space separated domains or keywords
     * @param int|null $clientId
     * @return array{search:array, inline:bool} the search row; inline=true when
     *         it was already processed synchronously (small lists)
     */
    public function submit($input, $clientId = null)
    {
        if (!Settings::bool('bulk_search_enabled', true)) {
            throw new ServiceUnavailableException('Bulk domain search is temporarily unavailable.');
        }
        $clientId = $clientId === null ? null : (int) $clientId;
        $limit = $clientId
            ? Settings::int('bulk_daily_limit_per_client', 10)
            : Settings::int('bulk_daily_limit_per_ip', 3);
        RateLimiter::hitOrFail('bulk_domain_search', RateLimiter::bucketForCurrentRequest($clientId), $limit, 86400);

        $domains = $this->expand($input);
        if ($domains === []) {
            throw new ValidationException(['domains' => 'Enter at least one domain or keyword.']);
        }

        $currency = $clientId
            ? Platform::gateway()->clientCurrency($clientId)
            : Platform::gateway()->defaultCurrency();

        $searchId = Db::insert('domain_searches', [
            'client_id'      => $clientId,
            'ip_hash'        => Str::pseudonym(Http::clientIp() ?: 'cli'),
            'mode'           => 'bulk',
            'status'         => 'queued',
            'total'          => count($domains),
            'completed'      => 0,
            'currency'       => strtoupper(substr((string) $currency, 0, 3)),
            'correlation_id' => Str::random(12),
            'created_at'     => Clock::now(),
        ]);

        foreach ($domains as $fqdn) {
            Db::insert('domain_search_results', [
                'search_id'  => $searchId,
                'domain'     => $fqdn,
                'tld'        => substr($fqdn, (int) strrpos($fqdn, '.') + 1),
                'available'  => null,
                'status'     => 'pending',
                'currency'   => strtoupper(substr((string) $currency, 0, 3)),
                'created_at' => Clock::now(),
            ]);
        }

        // Small lists answer inline (snappy UX); large lists go to the worker.
        $threshold = max(1, Settings::int('bulk_sync_threshold', 10));
        $inline = count($domains) <= $threshold;
        if ($inline) {
            $this->processJob($searchId);
        } else {
            $this->queue()->enqueue(DomainJobTypes::BULK_SEARCH, [
                'search_id' => $searchId,
            ], [
                'idempotency_key' => 'bulk-search:' . $searchId,
                'correlation_id'  => Str::random(12),
                'entity_type'     => 'domain_search',
                'entity_id'       => $searchId,
            ]);
        }

        Audit::log($clientId ? Audit::ACTOR_CLIENT : Audit::ACTOR_SYSTEM, (int) $clientId, 'domain.bulk_search', [
            'search_id' => $searchId,
            'total'     => count($domains),
            'inline'    => $inline,
        ]);

        return ['search' => $this->getSearch($searchId), 'inline' => $inline];
    }

    /**
     * Worker entry point: process one bulk search job. Processes pending
     * result rows in configured chunks; each row gets a live provider check
     * and a server-side quote. The search row tracks progress.
     *
     * @return array{search_id:int, completed:int}
     */
    public function processJob($searchId)
    {
        $searchId = (int) $searchId;
        $search = Db::first('domain_searches', ['id' => $searchId]);
        if (!$search) {
            throw new NotFoundException('Bulk search not found.');
        }
        if ($search['status'] === 'completed') {
            return ['search_id' => $searchId, 'completed' => (int) $search['completed']];
        }

        Db::update('domain_searches', ['id' => $searchId], [
            'status' => 'running',
        ]);

        $catalog = new TldCatalogService();
        $chunkSize = max(1, Settings::int('bulk_chunk_size', 25));
        $clientId = $search['client_id'] !== null ? (int) $search['client_id'] : null;
        $completed = 0;

        // Pending rows for this search, oldest first.
        $pending = Db::all('domain_search_results', [
            'search_id' => $searchId,
            'status'    => 'pending',
        ], 'id ASC');

        foreach (array_chunk($pending, $chunkSize) as $chunk) {
            foreach ($chunk as $row) {
                $domain = DomainName::tryParse($row['domain']);
                if (!$domain) {
                    Db::update('domain_search_results', ['id' => (int) $row['id']], [
                        'status'    => 'invalid',
                        'available' => null,
                    ]);
                    $completed++;
                    continue;
                }
                $tldRow = $catalog->detail($domain->tld());
                $result = $this->search()->checkOne($domain, $tldRow, $clientId, $search['currency']);
                Db::update('domain_search_results', ['id' => (int) $row['id']], [
                    'available'                 => $result['available'] === null ? null : ($result['available'] ? 1 : 0),
                    'status'                    => $result['status'],
                    'register_minor'            => $result['register_minor'],
                    'renew_minor'               => $result['renew_minor'],
                    'transfer_minor'            => $result['transfer_minor'],
                    'register_discounted_minor' => $result['register_final_minor'],
                ]);
                $completed++;
            }
            Db::update('domain_searches', ['id' => $searchId], ['completed' => $completed]);
        }

        Db::update('domain_searches', ['id' => $searchId], [
            'status'       => 'completed',
            'completed'    => $completed,
            'completed_at' => Clock::now(),
        ]);

        Audit::system('domain.bulk_search_completed', ['search_id' => $searchId, 'completed' => $completed]);
        return ['search_id' => $searchId, 'completed' => $completed];
    }

    /* ------------------------------------------------------------- queries -- */

    /** @return array|null */
    public function getSearch($searchId)
    {
        return Db::first('domain_searches', ['id' => (int) $searchId]);
    }

    /**
     * Ownership-checked search fetch. Guests may read searches created from
     * their (pseudonymised) bucket; clients only their own.
     */
    public function getSearchFor($searchId, $clientId = null, $isAdmin = false)
    {
        $search = $this->getSearch($searchId);
        if (!$search) {
            throw new NotFoundException('Search not found.');
        }
        if ($isAdmin) {
            return $search;
        }
        $clientId = $clientId === null ? null : (int) $clientId;
        if ($clientId && (int) $search['client_id'] === $clientId) {
            return $search;
        }
        if (!$clientId && $search['client_id'] === null
            && hash_equals((string) $search['ip_hash'], Str::pseudonym(Http::clientIp() ?: 'cli'))) {
            return $search;
        }
        throw new NotFoundException('Search not found.');
    }

    /**
     * @return array{rows:array[], total:int, page:int, per_page:int, available:int}
     */
    public function results($searchId, $page = 1, $perPage = 25, $onlyAvailable = false)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(200, (int) $perPage));
        $where = ['search_id = ?'];
        $bind = [(int) $searchId];
        if ($onlyAvailable) {
            $where[] = 'available = 1';
        }
        $whereSql = implode(' AND ', $where);
        $countRow = Db::query(
            'SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN available = 1 THEN 1 ELSE 0 END), 0) AS a FROM '
            . Db::t('domain_search_results') . ' WHERE ' . $whereSql,
            $bind
        );
        $total = $countRow ? (int) $countRow[0]['c'] : 0;
        $available = $countRow ? (int) $countRow[0]['a'] : 0;
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('domain_search_results') . ' WHERE ' . $whereSql
            . ' ORDER BY available DESC, domain ASC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'available' => $available];
    }

    /** @return array[] the client's bulk searches, newest first */
    public function mySearches($clientId, $limit = 20)
    {
        return Db::all('domain_searches', [
            'client_id' => (int) $clientId,
            'mode'      => 'bulk',
        ], 'id DESC', (int) $limit);
    }

    /**
     * CSV export of one search's results. Streamed by the caller with the
     * ownership check already applied.
     *
     * @return string CSV document
     */
    public function exportCsv($searchId)
    {
        $rows = Db::all('domain_search_results', ['search_id' => (int) $searchId], 'domain ASC');
        $currency = '';
        $search = $this->getSearch($searchId);
        if ($search) {
            $currency = (string) $search['currency'];
        }
        $out = "domain,available,status,register,renew,transfer,currency\n";
        foreach ($rows as $row) {
            $available = $row['available'] === null ? 'unknown' : ($row['available'] ? 'yes' : 'no');
            $money = function ($minor) use ($currency) {
                return $minor === null ? '' : \Chs\Core\Money::toDecimal((int) $minor, $currency);
            };
            $line = [
                $row['domain'],
                $available,
                $row['status'],
                $money($row['register_minor']),
                $money($row['renew_minor']),
                $money($row['transfer_minor']),
                $currency,
            ];
            $out .= implode(',', array_map(function ($cell) {
                return (string) (preg_match('/[",\n]/', (string) $cell) ? '"' . str_replace('"', '""', (string) $cell) . '"' : $cell);
            }, $line)) . "\n";
        }
        return $out;
    }

    /* ------------------------------------------------------------ internals -- */

    /**
     * Parse the raw input into a deduped, capped list of FQDNs. Tokens with a
     * dot are treated as domains; bare tokens are keywords expanded across the
     * configured TLD catalogue.
     *
     * @return array<int,string>
     */
    public function expand($input)
    {
        $raw = (string) $input;
        $tokens = preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $max = max(1, Settings::int('bulk_max_domains', 500));

        $domains = [];
        $keywords = [];
        foreach ($tokens as $token) {
            $token = trim($token, " \t\n\r.");
            if ($token === '') {
                continue;
            }
            if (strpos($token, '.') !== false) {
                $parsed = DomainName::tryParse($token);
                if ($parsed) {
                    $domains[$parsed->fqdn()] = true;
                }
            } elseif ($this->isKeyword($token)) {
                $keywords[$token] = true;
            }
        }

        if ($keywords) {
            $tlds = $this->catalogTlds();
            foreach (array_keys($keywords) as $keyword) {
                foreach ($tlds as $tld) {
                    $candidate = $keyword . '.' . $tld;
                    if (!isset($domains[$candidate])) {
                        $domains[$candidate] = true;
                    }
                }
            }
        }

        $list = array_keys($domains);
        sort($list);
        if (count($list) > $max) {
            throw new ValidationException([
                'domains' => 'That list expands to more than the maximum of ' . $max . ' domains. Trim it and try again.',
            ]);
        }
        return $list;
    }

    /**
     * A bare keyword must be a registrable label: letters/digits/hyphens,
     * starting and ending with a letter or digit (LDH). Stricter than
     * Validator::isSld on purpose — bulk expansion must not turn punctuation
     * into "domains".
     */
    protected function isKeyword($token)
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', (string) $token) === 1;
    }

    /** @return array<int,string> extensions the catalogue sells (for keyword expansion) */
    protected function catalogTlds()
    {
        $rows = Db::query(
            'SELECT extension FROM tbldomainpricing ORDER BY extension ASC'
        );
        $tlds = [];
        foreach ($rows as $row) {
            $tld = ltrim(strtolower((string) $row['extension']), '.');
            if ($tld !== '') {
                $tlds[] = $tld;
            }
        }
        return $tlds;
    }
}
