<?php
/**
 * CloudHost247 App Cloud — hosting plans.
 *
 * A plan is the resource envelope a customer buys: CPU, memory, storage,
 * bandwidth, how many installations and domains it covers, whether backups and
 * SSL are included, and which deployment engines and server types it may run on.
 *
 * Pricing is mirrored from WHMCS, never invented here: when a plan is linked to a
 * WHMCS product the product's price for the billing interval wins, and the mirror
 * can be refreshed with syncPricing(). The platform's own tables only ever hold
 * minor units (integer cents), so no floating point money is stored.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Billing;

use Ch247Apps\Catalog\Manifest;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Validator;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class PlanService
{
    const INTERVALS = [
        'free', 'hourly', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially',
    ];

    /** WHMCS billing cycle names, for pricing lookups and order creation. */
    const CYCLES = [
        'free' => 'Free Account',
        'hourly' => 'Hourly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semiannually' => 'Semi-Annually',
        'annually' => 'Annually',
        'biennially' => 'Biennially',
        'triennially' => 'Triennially',
    ];

    const DEPLOYMENT_TYPES = ['docker-compose', 'cpanel', 'kubernetes'];

    const SERVER_TYPES = ['vps', 'dedicated', 'cpanel', 'kubernetes', 'shared'];

    /** @var Actor */
    private $actor;

    /** @var GatewayInterface */
    private $gateway;

    public function __construct(Actor $actor = null, GatewayInterface $gateway = null)
    {
        $this->actor = $actor ?: Actor::system('PlanService');
        $this->gateway = $gateway ?: Gateway::get();
    }

    /* ------------------------------------------------------------------ CRUD */

    /**
     * Create a plan.
     *
     * @param array $input name, slug, billing_interval, cpu_millicores, memory_mb,
     *                     storage_mb, bandwidth_mb, price_minor|price, currency,
     *                     whmcs_product_id, application_id, max_instances,
     *                     max_domains, backup_retention_days, ssl_included,
     *                     auto_backup, deployment_types[], server_types[],
     *                     description, featured, sort_order, active
     * @return array the plan in presentation form
     */
    public function create(array $input)
    {
        Rbac::assert($this->actor, Rbac::PLAN_MANAGE);

        $values = $this->validated($input);
        $slug = Str::slug((string) $values['slug'], 100);
        if (Db::first('plans', ['slug' => $slug])) {
            throw new StateException('A plan with that slug already exists.', [
                'error_code' => 'PLAN_SLUG_TAKEN', 'slug' => $slug,
            ]);
        }

        $productId = !empty($values['whmcs_product_id']) ? (int) $values['whmcs_product_id'] : null;
        if ($productId !== null) {
            $product = $this->gateway->getProduct($productId);
            if (!$product) {
                throw new ValidationException('That WHMCS product does not exist.', [
                    'errors' => ['whmcs_product_id' => 'Unknown product ' . $productId],
                ]);
            }
        }

        $priceMinor = $this->resolvePrice($values, $productId);

        $now = Clock::now();
        $id = Db::insert('plans', [
            'name' => Str::clip((string) $values['name'], 160),
            'slug' => $slug,
            'whmcs_product_id' => $productId,
            'application_id' => !empty($values['application_id']) ? (int) $values['application_id'] : null,
            'billing_interval' => (string) $values['billing_interval'],
            'price_minor' => $priceMinor,
            'currency' => Str::clip((string) $values['currency'], 3),
            'cpu_millicores' => (int) $values['cpu_millicores'],
            'memory_mb' => (int) $values['memory_mb'],
            'storage_mb' => (int) $values['storage_mb'],
            'bandwidth_mb' => (int) $values['bandwidth_mb'],
            'max_instances' => (int) $values['max_instances'],
            'max_domains' => (int) $values['max_domains'],
            'backup_retention_days' => (int) $values['backup_retention_days'],
            'ssl_included' => $values['ssl_included'] ? 1 : 0,
            'auto_backup' => $values['auto_backup'] ? 1 : 0,
            'deployment_types' => Str::jsonEncode($values['deployment_types']),
            'server_types' => Str::jsonEncode($values['server_types']),
            'description' => isset($values['description']) ? Str::cleanText($values['description'], 2000) : null,
            'active' => $values['active'] ? 1 : 0,
            'featured' => $values['featured'] ? 1 : 0,
            'sort_order' => (int) $values['sort_order'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($this->actor, Audit::BILLING_CHANGED, [
            'resource_type' => 'plan', 'resource_id' => $id,
            'metadata' => ['action' => 'created', 'slug' => $slug, 'price_minor' => $priceMinor,
                'interval' => $values['billing_interval'], 'whmcs_product_id' => $productId],
        ]);
        Logger::info('Hosting plan created.', ['plan_id' => $id, 'slug' => $slug, 'price_minor' => $priceMinor,
            'source' => 'billing']);

        return $this->present($this->row($id));
    }

    /** Update a plan. Changing limits never retro-fits running installations. */
    public function update($planId, array $input)
    {
        Rbac::assert($this->actor, Rbac::PLAN_MANAGE);
        $row = $this->row($planId);

        $values = $this->validated(array_merge($this->rawFrom($row), $input), true);
        $fields = [];
        foreach (['name', 'billing_interval', 'currency', 'cpu_millicores', 'memory_mb', 'storage_mb',
            'bandwidth_mb', 'max_instances', 'max_domains', 'backup_retention_days', 'sort_order'] as $key) {
            if (isset($values[$key])) {
                $fields[$key] = in_array($key, ['name', 'billing_interval', 'currency'], true)
                    ? Str::clip((string) $values[$key], $key === 'currency' ? 3 : 160)
                    : (int) $values[$key];
            }
        }
        foreach (['ssl_included', 'auto_backup', 'active', 'featured'] as $key) {
            if (isset($values[$key])) {
                $fields[$key] = $values[$key] ? 1 : 0;
            }
        }
        if (isset($values['deployment_types'])) {
            $fields['deployment_types'] = Str::jsonEncode($values['deployment_types']);
        }
        if (isset($values['server_types'])) {
            $fields['server_types'] = Str::jsonEncode($values['server_types']);
        }
        if (isset($values['description'])) {
            $fields['description'] = Str::cleanText((string) $values['description'], 2000);
        }
        if (!empty($values['whmcs_product_id'])) {
            $fields['whmcs_product_id'] = (int) $values['whmcs_product_id'];
        }
        if (isset($values['price_minor']) || isset($values['price']) || !empty($values['whmcs_product_id'])) {
            $fields['price_minor'] = $this->resolvePrice(
                array_merge($values, ['billing_interval' => isset($fields['billing_interval'])
                    ? $fields['billing_interval'] : $row['billing_interval']]),
                isset($fields['whmcs_product_id']) ? (int) $fields['whmcs_product_id']
                    : ($row['whmcs_product_id'] ? (int) $row['whmcs_product_id'] : null)
            );
        }

        if (isset($values['slug'])) {
            $slug = Str::slug((string) $values['slug'], 100);
            $taken = Db::first('plans', ['slug' => $slug]);
            if ($taken && (int) $taken['id'] !== (int) $row['id']) {
                throw new StateException('A plan with that slug already exists.', [
                    'error_code' => 'PLAN_SLUG_TAKEN', 'slug' => $slug,
                ]);
            }
            $fields['slug'] = $slug;
        }

        if ($fields === []) {
            return $this->present($row);
        }

        // Shrinking a plan below what customers already run is allowed (existing
        // installations keep their envelope) but is worth an audit trail.
        $fields['updated_at'] = Clock::now();
        Db::update('plans', $fields, ['id' => (int) $row['id']]);

        Audit::record($this->actor, Audit::BILLING_CHANGED, [
            'resource_type' => 'plan', 'resource_id' => (int) $row['id'],
            'metadata' => ['action' => 'updated', 'changed' => array_keys($fields)],
        ]);
        return $this->present($this->row((int) $row['id']));
    }

    /** Stop offering a plan. Existing subscriptions keep running. */
    public function deactivate($planId)
    {
        Rbac::assert($this->actor, Rbac::PLAN_MANAGE);
        $row = $this->row($planId);
        Db::update('plans', ['active' => 0, 'updated_at' => Clock::now()], ['id' => (int) $row['id']]);
        Audit::record($this->actor, Audit::BILLING_CHANGED, [
            'resource_type' => 'plan', 'resource_id' => (int) $row['id'],
            'metadata' => ['action' => 'deactivated', 'slug' => $row['slug']],
        ]);
        return $this->present($this->row((int) $row['id']));
    }

    public function activate($planId)
    {
        Rbac::assert($this->actor, Rbac::PLAN_MANAGE);
        $row = $this->row($planId);
        Db::update('plans', ['active' => 1, 'updated_at' => Clock::now()], ['id' => (int) $row['id']]);
        Audit::record($this->actor, Audit::BILLING_CHANGED, [
            'resource_type' => 'plan', 'resource_id' => (int) $row['id'],
            'metadata' => ['action' => 'activated', 'slug' => $row['slug']],
        ]);
        return $this->present($this->row((int) $row['id']));
    }

    /** Refresh the mirrored price from WHMCS, which stays authoritative. */
    public function syncPricing($planId = null)
    {
        Rbac::assert($this->actor, Rbac::PLAN_MANAGE);
        $rows = $planId === null
            ? Db::fetch('plans', ['deleted_at' => null], ['order' => 'id'])
            : [$this->row($planId)];

        $synced = [];
        foreach ($rows as $row) {
            if (empty($row['whmcs_product_id'])) {
                continue;
            }
            $price = $this->priceFromProduct((int) $row['whmcs_product_id'], (string) $row['billing_interval'],
                (string) $row['currency']);
            if ($price === null) {
                continue;
            }
            if ((int) $row['price_minor'] === $price) {
                continue;
            }
            Db::update('plans', ['price_minor' => $price, 'updated_at' => Clock::now()],
                ['id' => (int) $row['id']]);
            $synced[] = ['plan_id' => (int) $row['id'], 'slug' => $row['slug'],
                'from' => (int) $row['price_minor'], 'to' => $price];
        }
        if ($synced !== []) {
            Logger::info('Plan pricing refreshed from WHMCS.', ['plans' => count($synced), 'source' => 'billing']);
            Audit::record($this->actor, Audit::BILLING_CHANGED, [
                'resource_type' => 'plan', 'metadata' => ['action' => 'pricing_synced', 'changes' => $synced],
            ]);
        }
        return $synced;
    }

    /* ------------------------------------------------------------------ read */

    /** @throws NotFoundException */
    public function row($planId)
    {
        $row = Db::first('plans', ['id' => (int) $planId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That plan does not exist.');
        }
        return $row;
    }

    public function bySlug($slug)
    {
        $row = Db::first('plans', ['slug' => Str::slug((string) $slug, 100), 'deleted_at' => null]);
        return $row ? $this->present($row) : null;
    }

    /**
     * Plans a customer may buy.
     *
     * @param array $filters application_id, active, featured, interval, q
     */
    public function listing(array $filters = [], $limit = 100)
    {
        $isAdmin = $this->actor->can(Rbac::PLAN_MANAGE);
        $where = ['deleted_at' => null];
        if (!$isAdmin || (isset($filters['active']) && $filters['active'] !== null)) {
            $where['active'] = $isAdmin && isset($filters['active']) ? (int) $this->truthy($filters['active']) : 1;
        }
        if (!empty($filters['application_id'])) {
            // A plan is either generic (no application) or app-specific.
            $where['application_id'] = ['in', [0, (int) $filters['application_id']]];
        }
        if (!empty($filters['featured'])) {
            $where['featured'] = 1;
        }
        if (!empty($filters['interval'])) {
            $where['billing_interval'] = (string) $filters['interval'];
        }
        if (!empty($filters['q'])) {
            $where['name'] = ['like', '%' . strtolower(trim((string) $filters['q'])) . '%'];
        }

        $out = [];
        foreach (Db::fetch('plans', $where, ['order' => 'sort_order', 'dir' => 'asc', 'order2' => 'price_minor',
            'limit' => max(1, min(200, (int) $limit))]) as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function present(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'billing_interval' => $row['billing_interval'],
            'billing_cycle' => isset(self::CYCLES[$row['billing_interval']])
                ? self::CYCLES[$row['billing_interval']] : ucfirst((string) $row['billing_interval']),
            'price_minor' => $row['price_minor'] === null ? null : (int) $row['price_minor'],
            'price' => $row['price_minor'] === null ? null : number_format(((int) $row['price_minor']) / 100, 2, '.', ''),
            'currency' => $row['currency'],
            'whmcs_product_id' => $row['whmcs_product_id'] ? (int) $row['whmcs_product_id'] : null,
            'application_id' => $row['application_id'] ? (int) $row['application_id'] : null,
            'cpu_millicores' => (int) $row['cpu_millicores'],
            'cpu_cores' => round(((int) $row['cpu_millicores']) / 1000, 2),
            'memory_mb' => (int) $row['memory_mb'],
            'storage_mb' => (int) $row['storage_mb'],
            'bandwidth_mb' => (int) $row['bandwidth_mb'],
            'max_instances' => (int) $row['max_instances'],
            'max_domains' => (int) $row['max_domains'],
            'backup_retention_days' => (int) $row['backup_retention_days'],
            'ssl_included' => (bool) $row['ssl_included'],
            'auto_backup' => (bool) $row['auto_backup'],
            'deployment_types' => Str::jsonDecode(isset($row['deployment_types']) ? $row['deployment_types'] : null, []),
            'server_types' => Str::jsonDecode(isset($row['server_types']) ? $row['server_types'] : null, []),
            'description' => isset($row['description']) ? $row['description'] : null,
            'active' => (bool) $row['active'],
            'featured' => (bool) $row['featured'],
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Plans that can actually host an application — the wizard's plan step.
     *
     * A plan that cannot meet the manifest's minimums, or whose deployment types
     * exclude the manifest's engine, is not offered at all: showing it and failing
     * at checkout would be a lie.
     *
     * @return array[] each with `plan` and `reasons` when it is excluded
     */
    public function forApplication($applicationId, Manifest $manifest = null, $includeIncompatible = false)
    {
        $application = Db::first('applications', ['id' => (int) $applicationId, 'deleted_at' => null]);
        if (!$application) {
            throw new NotFoundException('That application is not in the catalog.');
        }
        $requirements = $manifest ? $manifest->requirements() : [
            'cpu_min_millicores' => 0, 'memory_min_mb' => 0, 'storage_min_mb' => 0,
        ];
        $engine = $manifest ? $manifest->engine() : 'docker-compose';

        $out = [];
        foreach ($this->listing(['application_id' => (int) $applicationId], 200) as $plan) {
            $reasons = [];
            $types = $plan['deployment_types'];
            if ($types !== [] && !in_array($engine, $types, true)) {
                $reasons[] = 'DEPLOYMENT_TYPE_UNSUPPORTED';
            }
            if ($plan['cpu_millicores'] < (int) $requirements['cpu_min_millicores']) {
                $reasons[] = 'CPU_INSUFFICIENT';
            }
            if ($plan['memory_mb'] < (int) $requirements['memory_min_mb']) {
                $reasons[] = 'MEMORY_INSUFFICIENT';
            }
            if ($plan['storage_mb'] < (int) $requirements['storage_min_mb']) {
                $reasons[] = 'STORAGE_INSUFFICIENT';
            }
            if ($reasons !== [] && !$includeIncompatible) {
                continue;
            }
            $out[] = ['plan' => $plan, 'eligible' => $reasons === [], 'reasons' => $reasons];
        }
        return $out;
    }

    /* ------------------------------------------------------------- internals */

    private function validated(array $input, $partial = false)
    {
        $validator = Validator::make($input);
        if ($partial) {
            $validator->optional('name')->string('name', 160)
                ->optional('slug')->slug('slug');
        } else {
            $validator->required('name')->string('name', 160)
                ->required('slug')->slug('slug');
        }

        $validator
            ->optional('billing_interval', 'monthly')->in('billing_interval', self::INTERVALS)
            ->optional('currency', 'USD')->string('currency', 3)
            ->optional('cpu_millicores', 1000)->integer('cpu_millicores', 100)
            ->optional('memory_mb', 1024)->integer('memory_mb', 128)
            ->optional('storage_mb', 10240)->integer('storage_mb', 1024)
            ->optional('bandwidth_mb', 102400)->integer('bandwidth_mb', 0)
            ->optional('max_instances', 1)->integer('max_instances', 1)
            ->optional('max_domains', 1)->integer('max_domains', 0)
            ->optional('backup_retention_days', 14)->integer('backup_retention_days', 0)
            ->optional('ssl_included', true)->boolean('ssl_included')
            ->optional('auto_backup', true)->boolean('auto_backup')
            ->optional('active', true)->boolean('active')
            ->optional('featured', false)->boolean('featured')
            ->optional('sort_order', 0)->integer('sort_order', 0)
            ->optional('application_id')->integer('application_id', 0)
            ->optional('whmcs_product_id')->integer('whmcs_product_id', 0)
            ->optional('price_minor')->integer('price_minor', 0)
            ->optional('price')->string('price', 20)
            ->optional('description')->string('description', 2000)
            ->optional('deployment_types', ['docker-compose'])
            ->optional('server_types', ['vps', 'dedicated']);

        $values = $validator->validate();

        foreach (['deployment_types', 'server_types'] as $key) {
            $list = $values[$key];
            if (!is_array($list)) {
                $list = array_filter(array_map('trim', explode(',', (string) $list)));
            }
            $allowed = $key === 'deployment_types' ? self::DEPLOYMENT_TYPES : self::SERVER_TYPES;
            $clean = [];
            foreach ($list as $item) {
                $item = strtolower(trim((string) $item));
                if ($item === '') {
                    continue;
                }
                if (!in_array($item, $allowed, true)) {
                    throw new ValidationException('Unknown ' . str_replace('_', ' ', $key) . ' value "' . $item . '".', [
                        'errors' => [$key => 'Must be one of: ' . implode(', ', $allowed)],
                    ]);
                }
                $clean[] = $item;
            }
            if ($clean === [] && !$partial) {
                throw new ValidationException('At least one ' . str_replace('_', ' ', $key) . ' is required.', [
                    'errors' => [$key => 'Required'],
                ]);
            }
            $values[$key] = array_values(array_unique($clean));
        }

        if (!$partial && (string) $values['billing_interval'] === 'free') {
            // A free interval cannot carry a price: the two would contradict each
            // other at checkout.
            $values['price_minor'] = 0;
            $values['price'] = null;
        }

        return $values;
    }

    /** The plan's price in minor units: WHMCS wins when it has an opinion. */
    private function resolvePrice(array $values, $productId = null)
    {
        if ($productId !== null) {
            $fromWhmcs = $this->priceFromProduct((int) $productId, (string) $values['billing_interval'],
                isset($values['currency']) ? (string) $values['currency'] : 'USD');
            if ($fromWhmcs !== null) {
                return $fromWhmcs;
            }
        }
        if (isset($values['price_minor']) && $values['price_minor'] !== '' && $values['price_minor'] !== null) {
            return max(0, (int) $values['price_minor']);
        }
        if (isset($values['price']) && $values['price'] !== '' && $values['price'] !== null) {
            return $this->toMinor($values['price']);
        }
        if ((string) $values['billing_interval'] === 'free') {
            return 0;
        }
        throw new ValidationException('A plan needs a price (or a linked WHMCS product with pricing).', [
            'errors' => ['price' => 'Required'],
        ]);
    }

    /** Read WHMCS pricing for one interval; null when it has none. */
    private function priceFromProduct($productId, $interval, $currency)
    {
        try {
            $pricing = $this->gateway->getProductPricing($productId, $currency);
        } catch (\Throwable $e) {
            Logger::warning('Could not read WHMCS pricing for a product.', [
                'product_id' => (int) $productId, 'error' => $e->getMessage(), 'source' => 'billing',
            ]);
            return null;
        }
        if (!is_array($pricing) || $pricing === []) {
            return null;
        }
        // GetProductPricing shape: pricing[CUR][cycle] = ['price' => '10.00', ...]
        $cycle = isset(self::CYCLES[strtolower((string) $interval)])
            ? self::CYCLES[strtolower((string) $interval)] : ucfirst((string) $interval);
        $buckets = isset($pricing[$currency]) && is_array($pricing[$currency]) ? $pricing[$currency] : $pricing;
        foreach ([$cycle, strtolower((string) $interval), (string) $interval] as $key) {
            if (!isset($buckets[$key])) {
                continue;
            }
            $entry = $buckets[$key];
            if (is_array($entry)) {
                foreach (['price', 'msetup', 'setup', 'cost'] as $field) {
                    if (isset($entry[$field]) && is_numeric($entry[$field])) {
                        return $this->toMinor($entry[$field]);
                    }
                }
                continue;
            }
            if (is_numeric($entry)) {
                return $this->toMinor($entry);
            }
        }
        return null;
    }

    /** Decimal money → integer minor units, without floating point drift. */
    private function toMinor($amount)
    {
        $amount = trim((string) $amount);
        if ($amount === '') {
            return 0;
        }
        $negative = strpos($amount, '-') === 0;
        $amount = ltrim($amount, '-+');
        $parts = explode('.', $amount, 2);
        $whole = (int) $parts[0];
        $fraction = isset($parts[1]) ? substr($parts[1] . '00', 0, 2) : '00';
        $minor = ($whole * 100) + (int) $fraction;
        return $negative ? -$minor : $minor;
    }

    /** Row → the input shape validated() expects (for partial updates). */
    private function rawFrom(array $row)
    {
        return [
            'name' => $row['name'],
            'slug' => $row['slug'],
            'billing_interval' => $row['billing_interval'],
            'currency' => $row['currency'],
            'cpu_millicores' => (int) $row['cpu_millicores'],
            'memory_mb' => (int) $row['memory_mb'],
            'storage_mb' => (int) $row['storage_mb'],
            'bandwidth_mb' => (int) $row['bandwidth_mb'],
            'max_instances' => (int) $row['max_instances'],
            'max_domains' => (int) $row['max_domains'],
            'backup_retention_days' => (int) $row['backup_retention_days'],
            'ssl_included' => (bool) $row['ssl_included'],
            'auto_backup' => (bool) $row['auto_backup'],
            'active' => (bool) $row['active'],
            'featured' => (bool) $row['featured'],
            'sort_order' => (int) $row['sort_order'],
            'price_minor' => $row['price_minor'] === null ? null : (int) $row['price_minor'],
            'deployment_types' => Str::jsonDecode(isset($row['deployment_types']) ? $row['deployment_types'] : null, []),
            'server_types' => Str::jsonDecode(isset($row['server_types']) ? $row['server_types'] : null, []),
            'description' => isset($row['description']) ? $row['description'] : null,
            'application_id' => $row['application_id'] ? (int) $row['application_id'] : null,
            'whmcs_product_id' => $row['whmcs_product_id'] ? (int) $row['whmcs_product_id'] : null,
        ];
    }

    private function truthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
