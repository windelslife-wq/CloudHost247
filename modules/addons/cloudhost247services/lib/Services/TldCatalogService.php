<?php
/**
 * Database-driven TLD catalogue.
 *
 * Prices, registration periods, transfers and availability always come live
 * from WHMCS' own pricing tables (tbldomainpricing + tblpricing) — this
 * service merges that canonical data with the module's admin-managed
 * merchandising layer (categories, regions, badges, copy) so nothing about a
 * TLD is hard-coded into a template.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Db;
use Chs\Core\Platform;

class TldCatalogService
{
    const SORTS = ['featured', 'name', 'price_asc', 'price_desc'];

    /**
     * @param array $filters category, region, search, sort, only_featured
     * @return array{rows:array[], categories:array[], regions:array[]}
     */
    public function catalog(array $filters = [], $currency = null)
    {
        $currency = $currency ?: Platform::gateway()->defaultCurrency();

        $where = [];
        $bind = [];
        $params = [];

        if (!empty($filters['search'])) {
            $like = '%' . strtolower(trim($filters['search'])) . '%';
            $where[] = '(dp.extension LIKE ? OR m.tagline LIKE ?)';
            $bind[] = $like;
            $bind[] = $like;
        }
        if (!empty($filters['category'])) {
            $where[] = 'm.category = ?';
            $bind[] = (string) $filters['category'];
        }
        if (!empty($filters['region'])) {
            $where[] = 'm.region = ?';
            $bind[] = (string) $filters['region'];
        }
        if (!empty($filters['only_featured'])) {
            $where[] = 'm.is_featured = 1';
        }
        if (!empty($filters['badge'])) {
            $where[] = 'm.badge = ?';
            $bind[] = (string) $filters['badge'];
        }
        if (empty($filters['include_hidden'])) {
            $where[] = '(m.visible IS NULL OR m.visible = 1)';
        }

        // One row per TLD, prices left-joined in its own currency slots.
        // tblpricing rows are per (type, relid, currency); the register row's
        // msetupfee is the 1-year price, transfer/renew the flat fee.
        $sql = 'SELECT dp.id AS pricing_id, dp.extension AS tld,
                       dp.autoreg, dp.dnsmanagement, dp.emailforwarding, dp.idprotection, dp.eppcode,
                       reg.msetupfee  AS register_price,
                       ren.msetupfee  AS renew_price,
                       tra.msetupfee  AS transfer_price,
                       reg.currency   AS price_currency_id,
                       m.category, m.region, m.is_featured, m.is_popular, m.is_new, m.is_trending,
                       m.badge, m.tagline, m.sort_order, m.visible
                FROM tbldomainpricing dp
                LEFT JOIN ' . Db::t('tld_meta') . ' m ON m.tld = LTRIM(dp.extension, \'.\')
                LEFT JOIN tblpricing reg
                       ON reg.type = \'domainregister\' AND reg.relid = dp.id
                       AND reg.currency = (SELECT id FROM tblcurrencies WHERE code = ?)
                LEFT JOIN tblpricing ren
                       ON ren.type = \'domainrenew\' AND ren.relid = dp.id
                       AND ren.currency = reg.currency
                LEFT JOIN tblpricing tra
                       ON tra.type = \'domaintransfer\' AND tra.relid = dp.id
                       AND tra.currency = reg.currency';

        $bind = array_merge([$currency], $bind);
        $sql .= ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $sql .= $this->orderSql(isset($filters['sort']) ? $filters['sort'] : 'featured');

        $rows = Db::query($sql, $bind);

        $currencyId = null;
        $catalogue = [];
        foreach ($rows as $row) {
            $tld = ltrim($row['tld'], '.');
            $hasPrice = $row['register_price'] !== null && (float) $row['register_price'] >= 0;
            if ($hasPrice && $currencyId === null && !empty($row['price_currency_id'])) {
                $currencyId = $row['price_currency_id'];
            }
            $catalogue[] = [
                'tld'            => $tld,
                'category'       => $row['category'] ?: 'generic',
                'region'         => (string) $row['region'],
                'is_featured'    => (int) $row['is_featured'] === 1,
                'is_popular'     => (int) $row['is_popular'] === 1,
                'is_new'         => (int) $row['is_new'] === 1,
                'is_trending'    => (int) $row['is_trending'] === 1,
                'badge'          => (string) $row['badge'],
                'tagline'        => (string) $row['tagline'],
                'sort_order'     => (int) ($row['sort_order'] === null ? 999 : $row['sort_order']),
                'listed'         => $row['visible'] !== null, // merchandised entry exists
                'price_available' => $hasPrice,
                'register_minor' => $hasPrice ? (int) round(((float) $row['register_price']) * 100) : null,
                'renew_minor'    => $row['renew_price'] !== null && (float) $row['renew_price'] >= 0
                    ? (int) round(((float) $row['renew_price']) * 100) : null,
                'transfer_minor' => $row['transfer_price'] !== null && (float) $row['transfer_price'] >= 0
                    ? (int) round(((float) $row['transfer_price']) * 100) : null,
                'features' => [
                    'dns'       => (int) $row['dnsmanagement'] === 1,
                    'forwarding' => (int) $row['emailforwarding'] === 1,
                    'id_protection' => (int) $row['idprotection'] === 1,
                    'epp'       => (int) $row['eppcode'] === 1,
                ],
            ];
        }

        return [
            'rows'       => $catalogue,
            'categories' => $this->facets('category'),
            'regions'    => $this->facets('region'),
            'currency'   => $currency,
        ];
    }

    /** Featured extensions with pricing for hero/menu spotlight blocks. */
    public function spotlight($limit = 4)
    {
        $result = $this->catalog(['only_featured' => true, 'sort' => 'featured']);
        return array_slice($result['rows'], 0, (int) $limit);
    }

    /** Full detail for one extension, or null when WHMCS does not sell it. */
    public function detail($tld)
    {
        $tld = ltrim(strtolower(trim((string) $tld)), '.');
        $result = $this->catalog(['search' => '.' . $tld]);
        foreach ($result['rows'] as $row) {
            if ($row['tld'] === $tld) {
                return $row;
            }
        }
        return null;
    }

    /* ------------------------------------------------------- admin CRUD -- */

    /** @return array[] one row per WHMCS TLD plus its meta (for the admin grid) */
    public function adminList()
    {
        // Admins must see hidden entries too — otherwise a hidden TLD could
        // never be brought back from the admin grid.
        return $this->catalog(['sort' => 'name', 'include_hidden' => true])['rows'];
    }

    /** Upsert merchandising data for one TLD. */
    public function saveMeta($tld, array $data)
    {
        $tld = ltrim(strtolower(trim($tld)), '.');
        if (!preg_match('/^[a-z0-9.-]{1,63}$/', $tld)) {
            throw new \Chs\Core\ValidationException(['tld' => 'Invalid TLD.']);
        }
        $existing = Db::first('tld_meta', ['tld' => $tld]);
        $row = [
            'category'    => isset($data['category']) ? substr((string) $data['category'], 0, 48) : 'generic',
            'region'      => isset($data['region']) ? substr((string) $data['region'], 0, 64) : '',
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'is_popular'  => !empty($data['is_popular']) ? 1 : 0,
            'is_new'      => !empty($data['is_new']) ? 1 : 0,
            'is_trending' => !empty($data['is_trending']) ? 1 : 0,
            'badge'       => isset($data['badge']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $data['badge']), 0, 8)) : '',
            'tagline'     => isset($data['tagline']) ? substr((string) $data['tagline'], 0, 190) : '',
            'sort_order'  => isset($data['sort_order']) ? (int) $data['sort_order'] : 100,
            'visible'     => isset($data['visible']) ? (int) $data['visible'] : 1,
            'updated_at'  => \Chs\Core\Clock::now(),
        ];
        if ($existing) {
            Db::update('tld_meta', ['tld' => $tld], $row);
            return (int) $existing['id'];
        }
        $row['tld'] = $tld;
        $row['created_at'] = \Chs\Core\Clock::now();
        return Db::insert('tld_meta', $row);
    }

    /* ------------------------------------------------------------ helpers -- */

    protected function orderSql($sort)
    {
        switch ($sort) {
            case 'name':
                return ' ORDER BY tld ASC';
            case 'price_asc':
                return ' ORDER BY reg.msetupfee IS NULL, reg.msetupfee ASC, tld ASC';
            case 'price_desc':
                return ' ORDER BY reg.msetupfee IS NULL, reg.msetupfee DESC, tld ASC';
            case 'featured':
            default:
                return ' ORDER BY m.is_featured DESC, m.sort_order ASC, tld ASC';
        }
    }

    /** Distinct facet values with counts, honouring created meta first. */
    protected function facets($column)
    {
        $rows = Db::query(
            'SELECT ' . $column . ' AS v, COUNT(*) AS c FROM ' . Db::t('tld_meta')
            . ' WHERE visible = 1 AND ' . $column . " <> '' GROUP BY " . $column . ' ORDER BY c DESC, v ASC'
        );
        return array_map(function ($r) {
            return ['value' => $r['v'], 'count' => (int) $r['c']];
        }, $rows);
    }
}
