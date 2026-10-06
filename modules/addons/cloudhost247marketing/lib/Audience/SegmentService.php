<?php
/**
 * Dynamic segments.
 *
 * Two sources:
 *   subscribers   — filter the module's own subscriber table.
 *   whmcs_clients — filter live WHMCS data (clients, services, products,
 *                   domains, invoices, tickets). No CSV export, no stale copy:
 *                   the segment is re-resolved every time a campaign is built.
 *
 * Rule compilation is allowlist-driven. A field name that is not in FIELDS
 * never reaches SQL, and every value is bound. There is no string
 * interpolation of user input anywhere in this class.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Audience;

use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\NotFoundException;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;

class SegmentService
{
    public const SOURCE_SUBSCRIBERS = 'subscribers';
    public const SOURCE_WHMCS       = 'whmcs_clients';
    public const SOURCES = [self::SOURCE_SUBSCRIBERS, self::SOURCE_WHMCS];

    public const OPERATORS = ['is', 'is_not', 'contains', 'not_contains', 'in', 'not_in', 'gt', 'lt', 'before', 'after', 'is_set', 'is_empty'];

    /**
     * field => [label, type, sql, ops]
     *
     * `sql` is a trusted fragment written here, never built from input.
     * `exists`/`not_exists` fields compile to correlated subqueries instead.
     */
    public static function fields($source)
    {
        if ($source === self::SOURCE_SUBSCRIBERS) {
            return [
                'status'        => ['label' => 'Subscriber status', 'type' => 'enum', 'sql' => 's.status', 'ops' => ['is', 'is_not'], 'options' => SubscriberService::STATUSES],
                'email'         => ['label' => 'Email address', 'type' => 'string', 'sql' => 's.email', 'ops' => ['is', 'is_not', 'contains', 'not_contains']],
                'company'       => ['label' => 'Company', 'type' => 'string', 'sql' => 's.company', 'ops' => ['is', 'contains', 'is_set', 'is_empty']],
                'tag'           => ['label' => 'Tag', 'type' => 'string', 'sql' => 's.tags', 'ops' => ['contains', 'not_contains']],
                'client_id'     => ['label' => 'Linked WHMCS client', 'type' => 'int', 'sql' => 's.client_id', 'ops' => ['is', 'is_not', 'gt', 'is_set']],
                'created_at'    => ['label' => 'Date added', 'type' => 'date', 'sql' => 's.created_at', 'ops' => ['before', 'after']],
                'last_sent_at'  => ['label' => 'Last emailed', 'type' => 'date', 'sql' => 's.last_sent_at', 'ops' => ['before', 'after', 'is_empty']],
            ];
        }
        return [
            'client_status' => ['label' => 'Client status', 'type' => 'enum', 'sql' => 'c.status', 'ops' => ['is', 'is_not'], 'options' => ['Active', 'Inactive', 'Closed']],
            'country'       => ['label' => 'Country (ISO code)', 'type' => 'string', 'sql' => 'c.country', 'ops' => ['is', 'is_not', 'in', 'not_in']],
            'company'       => ['label' => 'Company', 'type' => 'string', 'sql' => 'c.companyname', 'ops' => ['contains', 'is_set', 'is_empty']],
            'email'         => ['label' => 'Email address', 'type' => 'string', 'sql' => 'c.email', 'ops' => ['contains', 'not_contains']],
            'signup_date'   => ['label' => 'Signup date', 'type' => 'date', 'sql' => 'c.datecreated', 'ops' => ['before', 'after']],
            'product'       => ['label' => 'Has product (ID)', 'type' => 'product', 'ops' => ['is', 'is_not'], 'exists' => 'product'],
            'product_group' => ['label' => 'Has product in group (ID)', 'type' => 'group', 'ops' => ['is', 'is_not'], 'exists' => 'product_group'],
            'service_status' => ['label' => 'Has service with status', 'type' => 'enum', 'ops' => ['is', 'is_not'], 'exists' => 'service_status', 'options' => ['Active', 'Pending', 'Suspended', 'Terminated', 'Cancelled']],
            'domain_tld'    => ['label' => 'Owns domain with TLD', 'type' => 'string', 'ops' => ['is', 'is_not'], 'exists' => 'domain_tld'],
            'unpaid_invoice' => ['label' => 'Has unpaid invoice', 'type' => 'bool', 'ops' => ['is'], 'exists' => 'unpaid_invoice'],
            'open_ticket'   => ['label' => 'Has open ticket', 'type' => 'bool', 'ops' => ['is'], 'exists' => 'open_ticket'],
        ];
    }

    /* ----------------------------------------------------------- CRUD --- */

    public static function create(array $data, $adminId = 0)
    {
        $name = Str::clip($data['name'] ?? '', 190);
        if ($name === '') {
            throw new ValidationException('A segment needs a name.', ['name' => 'required']);
        }
        $source = in_array($data['source'] ?? '', self::SOURCES, true) ? $data['source'] : self::SOURCE_SUBSCRIBERS;
        $definition = self::normalizeDefinition($data['definition'] ?? [], $source);
        $now = Clock::now();
        $id = Db::insert('segments', [
            'name'        => $name,
            'slug'        => self::uniqueSlug($data['slug'] ?? $name),
            'description' => Str::clip($data['description'] ?? '', 1000),
            'source'      => $source,
            'definition'  => json_encode($definition, JSON_UNESCAPED_SLASHES),
            'cached_count' => 0,
            'created_by'  => (int) $adminId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        Audit::admin((int) $adminId, 'segment.created', ['segment_id' => $id, 'source' => $source]);
        return self::find($id);
    }

    public static function update($id, array $data, $adminId = 0)
    {
        $segment = self::find($id);
        if ($segment === null) {
            throw new NotFoundException('Segment not found.');
        }
        $source = in_array($data['source'] ?? '', self::SOURCES, true) ? $data['source'] : $segment['source'];
        $set = ['source' => $source, 'updated_at' => Clock::now()];
        if (array_key_exists('name', $data)) {
            $set['name'] = Str::clip($data['name'], 190);
            if ($set['name'] === '') {
                throw new ValidationException('A segment needs a name.', ['name' => 'required']);
            }
        }
        if (array_key_exists('description', $data)) {
            $set['description'] = Str::clip($data['description'], 1000);
        }
        if (array_key_exists('definition', $data)) {
            $set['definition'] = json_encode(self::normalizeDefinition($data['definition'], $source), JSON_UNESCAPED_SLASHES);
        }
        Db::update('segments', ['id' => (int) $id], $set);
        Audit::admin((int) $adminId, 'segment.updated', ['segment_id' => (int) $id]);
        return self::find($id);
    }

    public static function delete($id, $adminId = 0)
    {
        if (self::find($id) === null) {
            throw new NotFoundException('Segment not found.');
        }
        Db::delete('segments', ['id' => (int) $id]);
        Audit::admin((int) $adminId, 'segment.deleted', ['segment_id' => (int) $id]);
        return true;
    }

    public static function find($id)
    {
        $row = Db::first('segments', ['id' => (int) $id]);
        return $row === null ? null : self::hydrate($row);
    }

    public static function all()
    {
        return array_map([self::class, 'hydrate'], Db::all('segments', [], 'name ASC'));
    }

    /* ------------------------------------------------------ resolution -- */

    /**
     * Resolve a segment to candidate recipients.
     *
     * @return array<int,array{email:string,first_name:string,last_name:string,company:string,client_id:int,subscriber_id:int}>
     */
    public static function resolve(array $segment, $limit = 0)
    {
        return $segment['source'] === self::SOURCE_WHMCS
            ? self::resolveWhmcs($segment['definition'], $limit)
            : self::resolveSubscribers($segment['definition'], $limit);
    }

    public static function count(array $segment)
    {
        return count(self::resolve($segment));
    }

    /** Resolve, store the count, return it. */
    public static function refreshCount($id)
    {
        $segment = self::find($id);
        if ($segment === null) {
            throw new NotFoundException('Segment not found.');
        }
        $count = self::count($segment);
        Db::update('segments', ['id' => (int) $id], ['cached_count' => $count, 'cached_at' => Clock::now()]);
        return $count;
    }

    /**
     * Refresh every segment's cached count (cron).
     *
     * One broken segment — a renamed WHMCS column, say — must not stop the
     * rest from updating, so each is isolated.
     *
     * @return int segments successfully refreshed
     */
    public static function refreshAll()
    {
        $done = 0;
        foreach (self::all() as $segment) {
            try {
                self::refreshCount((int) $segment['id']);
                $done++;
            } catch (\Throwable $e) {
                \Ch247Mkt\Core\Logger::warning('segment count refresh failed', [
                    'segment_id' => (int) $segment['id'],
                    'message'    => $e->getMessage(),
                ]);
            }
        }
        return $done;
    }

    protected static function resolveSubscribers(array $definition, $limit)
    {
        list($whereSql, $bind) = self::compile($definition, self::SOURCE_SUBSCRIBERS);
        $sql = 'SELECT s.id AS subscriber_id, s.email, s.first_name, s.last_name, s.company, s.client_id
                  FROM ' . Db::t('subscribers') . ' s'
            . ($whereSql !== '' ? ' WHERE ' . $whereSql : '')
            . ' ORDER BY s.id ASC'
            . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
        $out = [];
        foreach (Db::query($sql, $bind) as $row) {
            $out[] = [
                'subscriber_id' => (int) $row['subscriber_id'],
                'email'         => (string) $row['email'],
                'first_name'    => (string) $row['first_name'],
                'last_name'     => (string) $row['last_name'],
                'company'       => (string) $row['company'],
                'client_id'     => (int) $row['client_id'],
            ];
        }
        return $out;
    }

    protected static function resolveWhmcs(array $definition, $limit)
    {
        if (!Db::whmcsTableExists('tblclients')) {
            return [];
        }
        list($whereSql, $bind) = self::compile($definition, self::SOURCE_WHMCS);
        $sql = 'SELECT c.id, c.firstname, c.lastname, c.companyname, c.email
                  FROM tblclients c'
            . ($whereSql !== '' ? ' WHERE ' . $whereSql : '')
            . ' ORDER BY c.id ASC'
            . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
        $out = [];
        foreach (Db::query($sql, $bind) as $row) {
            $email = Str::normalizeEmail($row['email']);
            if (!Str::isEmail($email)) {
                continue;
            }
            $out[] = [
                'subscriber_id' => 0,
                'email'         => $email,
                'first_name'    => (string) $row['firstname'],
                'last_name'     => (string) $row['lastname'],
                'company'       => (string) $row['companyname'],
                'client_id'     => (int) $row['id'],
            ];
        }
        return $out;
    }

    /* ----------------------------------------------------- compilation -- */

    /** @return array{0:string,1:array} WHERE fragment + bindings */
    public static function compile(array $definition, $source)
    {
        $fields = self::fields($source);
        $match = (isset($definition['match']) && strtolower($definition['match']) === 'any') ? ' OR ' : ' AND ';
        $parts = [];
        $bind = [];

        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? 'is');
            if (!isset($fields[$field]) || !in_array($op, $fields[$field]['ops'], true)) {
                continue; // allowlist: anything else is dropped, not interpolated
            }
            $spec = $fields[$field];
            $value = $rule['value'] ?? '';

            if (isset($spec['exists'])) {
                list($sql, $subBind) = self::existsClause($spec['exists'], $op, $value);
                if ($sql !== '') {
                    $parts[] = $sql;
                    foreach ($subBind as $b) {
                        $bind[] = $b;
                    }
                }
                continue;
            }

            $column = $spec['sql']; // trusted literal from this file
            switch ($op) {
                case 'is':
                    $parts[] = $column . ' = ?';
                    $bind[] = (string) $value;
                    break;
                case 'is_not':
                    $parts[] = '(' . $column . ' <> ? OR ' . $column . ' IS NULL)';
                    $bind[] = (string) $value;
                    break;
                case 'contains':
                    $parts[] = $column . ' LIKE ?';
                    $bind[] = '%' . self::escapeLike((string) $value) . '%';
                    break;
                case 'not_contains':
                    $parts[] = '(' . $column . ' NOT LIKE ? OR ' . $column . ' IS NULL)';
                    $bind[] = '%' . self::escapeLike((string) $value) . '%';
                    break;
                case 'in':
                case 'not_in':
                    $values = is_array($value) ? array_values($value) : array_filter(array_map('trim', explode(',', (string) $value)), 'strlen');
                    if ($values === []) {
                        break;
                    }
                    $placeholders = implode(', ', array_fill(0, count($values), '?'));
                    $parts[] = $column . ($op === 'in' ? ' IN (' : ' NOT IN (') . $placeholders . ')';
                    foreach ($values as $v) {
                        $bind[] = (string) $v;
                    }
                    break;
                case 'gt':
                    $parts[] = $column . ' > ?';
                    $bind[] = (string) $value;
                    break;
                case 'lt':
                    $parts[] = $column . ' < ?';
                    $bind[] = (string) $value;
                    break;
                case 'before':
                    $parts[] = $column . ' < ?';
                    $bind[] = self::date($value);
                    break;
                case 'after':
                    $parts[] = $column . ' > ?';
                    $bind[] = self::date($value);
                    break;
                case 'is_set':
                    $parts[] = '(' . $column . ' IS NOT NULL AND ' . $column . " <> '')";
                    break;
                case 'is_empty':
                    $parts[] = '(' . $column . ' IS NULL OR ' . $column . " = '')";
                    break;
            }
        }

        if ($parts === []) {
            return ['', []];
        }
        return ['(' . implode($match, $parts) . ')', $bind];
    }

    /** Correlated EXISTS subqueries over WHMCS relations. */
    protected static function existsClause($kind, $op, $value)
    {
        $negate = $op === 'is_not';
        $prefix = $negate ? 'NOT EXISTS' : 'EXISTS';

        switch ($kind) {
            case 'product':
                if (!Db::whmcsTableExists('tblhosting')) {
                    return ['', []];
                }
                return [$prefix . ' (SELECT 1 FROM tblhosting h WHERE h.userid = c.id AND h.packageid = ? AND h.domainstatus = ?)', [(int) $value, 'Active']];

            case 'product_group':
                if (!Db::whmcsTableExists('tblhosting') || !Db::whmcsTableExists('tblproducts')) {
                    return ['', []];
                }
                return [$prefix . ' (SELECT 1 FROM tblhosting h INNER JOIN tblproducts p ON p.id = h.packageid WHERE h.userid = c.id AND p.gid = ? AND h.domainstatus = ?)', [(int) $value, 'Active']];

            case 'service_status':
                if (!Db::whmcsTableExists('tblhosting')) {
                    return ['', []];
                }
                return [$prefix . ' (SELECT 1 FROM tblhosting h WHERE h.userid = c.id AND h.domainstatus = ?)', [(string) $value]];

            case 'domain_tld':
                if (!Db::whmcsTableExists('tbldomains')) {
                    return ['', []];
                }
                $tld = ltrim(strtolower(trim((string) $value)), '.');
                return [$prefix . ' (SELECT 1 FROM tbldomains d WHERE d.userid = c.id AND d.domain LIKE ?)', ['%.' . self::escapeLike($tld)]];

            case 'unpaid_invoice':
                if (!Db::whmcsTableExists('tblinvoices')) {
                    return ['', []];
                }
                $want = self::truthy($value);
                return [($want ? 'EXISTS' : 'NOT EXISTS') . ' (SELECT 1 FROM tblinvoices i WHERE i.userid = c.id AND i.status = ?)', ['Unpaid']];

            case 'open_ticket':
                if (!Db::whmcsTableExists('tbltickets')) {
                    return ['', []];
                }
                $want = self::truthy($value);
                return [($want ? 'EXISTS' : 'NOT EXISTS') . ' (SELECT 1 FROM tbltickets t WHERE t.userid = c.id AND t.status IN (?, ?, ?))', ['Open', 'Answered', 'Customer-Reply']];
        }
        return ['', []];
    }

    /* --------------------------------------------------------- helpers -- */

    public static function normalizeDefinition($definition, $source)
    {
        if (is_string($definition)) {
            $decoded = json_decode($definition, true);
            $definition = is_array($decoded) ? $decoded : [];
        }
        $definition = (array) $definition;
        $fields = self::fields($source);
        $rules = [];
        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? 'is');
            if (!isset($fields[$field])) {
                throw new ValidationException('Unknown segment field: ' . Str::clip($field, 40), ['field' => 'unknown']);
            }
            if (!in_array($op, $fields[$field]['ops'], true)) {
                throw new ValidationException('Operator "' . Str::clip($op, 20) . '" is not valid for ' . $fields[$field]['label'] . '.', ['op' => 'invalid']);
            }
            $value = $rule['value'] ?? '';
            $rules[] = [
                'field' => $field,
                'op'    => $op,
                'value' => is_array($value) ? array_map(function ($v) {
                    return Str::clip((string) $v, 190);
                }, array_slice(array_values($value), 0, 200)) : Str::clip((string) $value, 190),
            ];
            if (count($rules) >= 40) {
                break;
            }
        }
        return [
            'match' => (isset($definition['match']) && strtolower($definition['match']) === 'any') ? 'any' : 'all',
            'rules' => $rules,
        ];
    }

    public static function hydrate(array $row)
    {
        $decoded = json_decode((string) $row['definition'], true);
        $row['definition'] = is_array($decoded) ? $decoded : ['match' => 'all', 'rules' => []];
        return $row;
    }

    /** Human-readable description of a segment, used in the audience summary. */
    public static function describe(array $segment)
    {
        $fields = self::fields($segment['source']);
        $parts = [];
        foreach ((array) ($segment['definition']['rules'] ?? []) as $rule) {
            $label = isset($fields[$rule['field']]) ? $fields[$rule['field']]['label'] : $rule['field'];
            $value = is_array($rule['value']) ? implode(', ', $rule['value']) : $rule['value'];
            $parts[] = $label . ' ' . str_replace('_', ' ', $rule['op']) . ($value === '' ? '' : ' "' . $value . '"');
        }
        if ($parts === []) {
            return 'Everyone in ' . ($segment['source'] === self::SOURCE_WHMCS ? 'WHMCS clients' : 'subscribers');
        }
        $joiner = ($segment['definition']['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ';
        return implode($joiner, $parts);
    }

    protected static function escapeLike($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $value);
    }

    protected static function date($value)
    {
        $ts = Clock::toTime($value);
        return $ts === null ? Clock::now() : gmdate('Y-m-d H:i:s', $ts);
    }

    protected static function truthy($value)
    {
        return in_array(strtolower((string) $value), ['1', 'yes', 'true', 'on'], true);
    }

    protected static function uniqueSlug($seed)
    {
        $base = Str::slug($seed, 150);
        $slug = $base;
        $i = 2;
        while (Db::count('segments', ['slug' => $slug]) > 0) {
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 500) {
                $slug = $base . '-' . substr(Str::token(4), 0, 6);
                break;
            }
        }
        return $slug;
    }
}
