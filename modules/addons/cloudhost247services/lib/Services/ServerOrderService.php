<?php
/**
 * Server ordering flow.
 *
 *   Choose product → region → OS → version → architecture → SSH key →
 *   hostname → review → real WHMCS order + invoice → payment → provisioning
 *
 * Billing is 100% WHMCS: the module places a real order through the platform
 * gateway (AddOrder → order + invoice + pending service) and never provisions
 * anything until the invoice is actually Paid — verified at payment-hook time
 * AND again at worker-execution time. The module's own tables only track the
 * infrastructure selection (OS version, architecture, region, provider image)
 * and link the order to the provisioning pipeline.
 *
 * Every customer input is validated server-side; the frontend selection is
 * never trusted.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\ForbiddenException;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Infrastructure\InfraProviderRegistry;
use Chs\Workflow\InfraJobTypes;
use Chs\Workflow\JobQueue;

class ServerOrderService
{
    /** @var InfraProviderRegistry|null */
    private $registry;
    /** @var OsCatalogService|null */
    private $catalog;
    /** @var ImageResolverService|null */
    private $resolver;
    /** @var JobQueue|null */
    private $queue;

    public function __construct(
        InfraProviderRegistry $registry = null,
        OsCatalogService $catalog = null,
        ImageResolverService $resolver = null,
        JobQueue $queue = null
    ) {
        $this->registry = $registry ?: new InfraProviderRegistry();
        $this->catalog = $catalog ?: new OsCatalogService($this->registry);
        $this->resolver = $resolver ?: new ImageResolverService();
        $this->queue = $queue ?: new JobQueue();
    }

    /* ------------------------------------------------------------- listing -- */

    /**
     * Orderable server products with their availability summary.
     *
     * @return array[] products: id, name, description, paytype, price_minor,
     *                 currency, regions, os_count, architectures
     */
    public function products()
    {
        $out = [];
        foreach (Platform::gateway()->serverProducts() as $product) {
            $productId = (int) $product['id'];
            $rule = $this->catalog->ruleForProduct($productId);
            $serverType = $rule ? (string) $rule['server_type'] : 'vps';
            $matrix = $this->catalog->catalogFor($serverType, $productId);
            $architectures = [];
            $osCount = 0;
            foreach ($matrix as $os) {
                $osCount++;
                foreach ($os['versions'] as $version) {
                    foreach ($version['architectures'] as $arch => $providers) {
                        $architectures[$arch] = true;
                    }
                }
            }
            $out[] = [
                'id'              => $productId,
                'name'            => (string) $product['name'],
                'description'     => (string) $product['description'],
                'group_name'      => isset($product['group_name']) ? (string) $product['group_name'] : '',
                'paytype'         => (string) $product['paytype'],
                'price_minor'     => isset($product['price_minor']) ? $product['price_minor'] : null,
                'currency'        => isset($product['currency']) ? (string) $product['currency'] : Platform::gateway()->defaultCurrency(),
                'server_type'     => $serverType,
                'regions'         => count($this->catalog->regions()),
                'os_count'        => $osCount,
                'architectures'   => array_keys($architectures),
            ];
        }
        return $out;
    }

    /** Full configuration payload for the order form (also the JSON API). */
    public function configuration($productId, $regionId = 0, $architecture = '')
    {
        if (!Settings::bool('server_order_enabled', true)) {
            throw new ValidationException(['order' => 'Server ordering is currently disabled.']);
        }
        $config = $this->catalog->configurationFor($productId, (int) $regionId, (string) $architecture);
        $config['pricing'] = Platform::gateway()->productPricing((int) $productId, Platform::gateway()->defaultCurrency());
        return $config;
    }

    /* ------------------------------------------------------------ SSH keys -- */

    /** @return array[] */
    public function sshKeys($clientId)
    {
        return Db::all('customer_ssh_keys', ['client_id' => (int) $clientId], 'id ASC');
    }

    /**
     * Store a customer SSH public key. Only the public half is ever stored;
     * the format is validated so provisioning never ships a malformed key.
     *
     * @return int key id
     */
    public function addSshKey($clientId, $name, $publicKey)
    {
        $clientId = (int) $clientId;
        $errors = [];
        $name = trim((string) $name);
        if ($name === '' || strlen($name) > 96) {
            $errors['name'] = 'Key name is required (max 96 characters).';
        }
        $key = trim((string) $publicKey);
        if (!$this->isValidPublicKey($key)) {
            $errors['public_key'] = 'Not a valid OpenSSH public key (expected "ssh-ed25519|ssh-rsa|ecdsa-sha2-* AAAA… [comment]").';
        }
        if (Db::first('customer_ssh_keys', ['client_id' => $clientId, 'name' => $name])) {
            $errors['name'] = 'You already have a key with that name.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        Audit::client($clientId, 'server.ssh_key_added', ['name' => $name]);
        return Db::insert('customer_ssh_keys', [
            'client_id'   => $clientId,
            'name'        => $name,
            'public_key'  => $key,
            'fingerprint' => $this->fingerprint($key),
            'created_at'  => Clock::now(),
        ]);
    }

    /** Ownership-checked delete. */
    public function deleteSshKey($clientId, $keyId)
    {
        $key = Db::first('customer_ssh_keys', ['id' => (int) $keyId, 'client_id' => (int) $clientId]);
        if (!$key) {
            throw new NotFoundException('SSH key not found.');
        }
        if (Db::count('provisioning_jobs', ['ssh_key_id' => (int) $keyId]) > 0) {
            throw new DuplicateOperationException('This key is referenced by provisioning jobs — it cannot be deleted.');
        }
        Audit::client($clientId, 'server.ssh_key_deleted', ['key_id' => (int) $keyId]);
        return Db::delete('customer_ssh_keys', ['id' => (int) $keyId]) > 0;
    }

    /* --------------------------------------------------------- order create -- */

    /**
     * Create a real WHMCS order for a server with a fully validated OS
     * selection, plus the module's provisioning record (QUEUED, unpaid —
     * nothing provisions until the invoice is Paid).
     *
     * @return array{order_id:int, invoice_id:int, invoice_url:string, module_server_id:int, provisioning_job_id:int}
     */
    public function createOrder($clientId, $productId, $billingCycle, $hostname, $osVersionId, $architecture, $sshKeyId = 0, $regionId = 0)
    {
        $clientId = (int) $clientId;
        if (!Settings::bool('server_order_enabled', true)) {
            throw new ValidationException(['order' => 'Server ordering is currently disabled.']);
        }
        if (!Platform::gateway()->clientExists($clientId)) {
            throw new NotFoundException('Client not found.');
        }

        // Product: must be a visible server product.
        $product = null;
        foreach (Platform::gateway()->serverProducts() as $p) {
            if ((int) $p['id'] === (int) $productId) {
                $product = $p;
                break;
            }
        }
        if (!$product) {
            throw new ValidationException(['product_id' => 'Server product not found.']);
        }

        // Billing cycle must exist in the product's pricing.
        $currency = Platform::gateway()->clientCurrency($clientId);
        $pricing = Platform::gateway()->productPricing((int) $productId, $currency);
        $cycle = strtolower((string) $billingCycle);
        if (!isset($pricing['cycles'][$cycle])) {
            throw new ValidationException(['billing_cycle' => 'Billing cycle is not available for this product.']);
        }

        // Hostname: normalized + validated server-side.
        $hostname = strtolower(trim((string) $hostname));
        if (!$this->isValidHostname($hostname)) {
            throw new ValidationException(['hostname' => 'Enter a valid hostname, e.g. vps-01.example.com.']);
        }

        // Duplicate guard: one pending/provisioning server per hostname+client.
        $dupe = Db::first('module_servers', ['client_id' => $clientId, 'hostname' => $hostname]);
        if ($dupe && in_array($dupe['status'], ['pending_payment', 'provisioning'], true)) {
            throw new DuplicateOperationException('You already have a server with that hostname awaiting payment or provisioning.');
        }

        // OS selection: fully re-validated against the catalog + images.
        $rule = $this->catalog->ruleForProduct((int) $productId);
        $serverType = $rule ? (string) $rule['server_type'] : 'vps';
        $version = $this->catalog->versionRow((int) $osVersionId);
        if (!$version) {
            throw new ValidationException(['os_version_id' => 'OS version not found.']);
        }
        $os = $this->catalog->findOs((int) $version['operating_system_id']);
        if (!$os || $os['status'] !== 'ACTIVE') {
            throw new ValidationException(['os_version_id' => 'Operating system is not available.']);
        }
        $typeFlag = 'is_' . $serverType . '_supported';
        if (empty($os[$typeFlag])) {
            throw new ValidationException(['os_version_id' => 'This OS is not offered for ' . $serverType . ' servers.']);
        }
        if (!in_array($version['status'], OsCatalogService::SELECTABLE_VERSION_STATUSES, true)) {
            throw new ValidationException(['os_version_id' => 'This OS version is retired or end-of-life and cannot be selected for new servers.']);
        }
        $architecture = strtolower(trim((string) $architecture));
        if (!in_array($architecture, $version['architectures'], true)) {
            throw new ValidationException(['architecture' => 'Architecture is not supported by this OS version.']);
        }

        // Region: must be an active region of an enabled provider.
        $regionId = (int) $regionId;
        if ($rule && !empty($rule['region_id'])) {
            $regionId = (int) $rule['region_id'];
        }
        if ($regionId) {
            $region = Db::first('infrastructure_regions', ['id' => $regionId, 'is_active' => 1]);
            if (!$region) {
                throw new ValidationException(['region_id' => 'Region is not available.']);
            }
            $regionProvider = Db::first('infrastructure_providers', ['id' => (int) $region['provider_id'], 'is_enabled' => 1]);
            if (!$regionProvider) {
                throw new ValidationException(['region_id' => 'Region provider is disabled.']);
            }
        }

        // Image: resolved honestly — unavailable combinations are refused.
        $providerId = $rule && !empty($rule['provider_id']) ? (int) $rule['provider_id'] : 0;
        $image = $this->resolver->resolve((int) $version['id'], $architecture, $providerId, $regionId);
        $resolvedProviderId = (int) $image['provider_id'];

        // SSH key: optional, but must be owned by the client.
        $sshKeyId = (int) $sshKeyId;
        $sshKey = '';
        if ($sshKeyId) {
            $keyRow = Db::first('customer_ssh_keys', ['id' => $sshKeyId, 'client_id' => $clientId]);
            if (!$keyRow) {
                throw new ForbiddenException('That SSH key does not belong to you.');
            }
            $sshKey = (string) $keyRow['public_key'];
        }

        // Real WHMCS order + invoice through the platform gateway.
        $order = Platform::gateway()->createOrder($clientId, (int) $productId, $cycle, $hostname);
        if (empty($order['invoice_id'])) {
            throw new ValidationException(['order' => 'The platform did not return an invoice for this order.']);
        }

        $now = Clock::now();
        $correlation = Str::random(12);

        $moduleServerId = Db::insert('module_servers', [
            'client_id'                     => $clientId,
            'hosting_id'                    => 0,
            'order_id'                      => (int) $order['order_id'],
            'invoice_id'                    => (int) $order['invoice_id'],
            'provisioning_job_id'           => 0,
            'provider_id'                   => $resolvedProviderId,
            'provider_server_id'            => '',
            'ip_address'                    => '',
            'hostname'                      => $hostname,
            'operating_system_version_id'   => (int) $version['id'],
            'architecture'                  => $architecture,
            'region_id'                     => $regionId ?: null,
            'status'                        => 'pending_payment',
            'created_at'                    => $now,
            'updated_at'                    => $now,
        ]);

        $jobKey = 'provision:' . (int) $order['invoice_id'];
        $jobId = Db::insert('provisioning_jobs', [
            'job_key'                       => $jobKey,
            'type'                          => 'PROVISION',
            'client_id'                     => $clientId,
            'module_server_id'              => $moduleServerId,
            'hosting_id'                    => 0,
            'invoice_id'                    => (int) $order['invoice_id'],
            'provider_id'                   => $resolvedProviderId,
            'os_image_id'                   => (int) $image['id'],
            'operating_system_version_id'   => (int) $version['id'],
            'architecture'                  => $architecture,
            'region_id'                     => $regionId ?: null,
            'hostname'                      => $hostname,
            'ssh_key_id'                    => $sshKeyId ?: null,
            'status'                        => 'QUEUED',
            'stage'                         => '',
            'attempts'                      => 0,
            'max_attempts'                  => max(1, Settings::int('provisioning_max_attempts', 5)),
            'logs'                          => json_encode([]),
            'correlation_id'                => $correlation,
            'created_at'                    => $now,
            'updated_at'                    => $now,
        ]);
        Db::update('module_servers', ['id' => $moduleServerId], [
            'provisioning_job_id' => $jobId,
            'updated_at'          => $now,
        ]);

        Audit::client($clientId, 'server.order_created', [
            'order_id'     => (int) $order['order_id'],
            'invoice_id'   => (int) $order['invoice_id'],
            'product_id'   => (int) $productId,
            'os_version'   => (int) $version['id'],
            'architecture' => $architecture,
            'region_id'    => $regionId,
            'provider_id'  => $resolvedProviderId,
            'correlation'  => $correlation,
        ]);

        return [
            'order_id'            => (int) $order['order_id'],
            'invoice_id'          => (int) $order['invoice_id'],
            'invoice_url'         => Platform::gateway()->invoiceUrl((int) $order['invoice_id']),
            'module_server_id'    => $moduleServerId,
            'provisioning_job_id' => $jobId,
        ];
    }

    /* ------------------------------------------------------- InvoicePaid -- */

    /**
     * Payment hook: verify the invoice is REALLY paid through the gateway
     * (never trust the hook payload alone), link the WHMCS service, and only
     * then enqueue the provisioning job. Unpaid or unknown invoices are a
     * no-op — a server is never provisioned because an order exists.
     *
     * @return bool whether a job was enqueued
     */
    public function onInvoicePaid($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        $job = Db::first('provisioning_jobs', [
            'invoice_id' => $invoiceId,
            'type'       => 'PROVISION',
            'status'     => 'QUEUED',
        ]);
        if (!$job) {
            return false;
        }
        if (Platform::gateway()->invoiceStatus($invoiceId) !== 'Paid') {
            return false; // hook fired but payment not confirmed — do NOT provision
        }
        $service = Platform::gateway()->serviceForInvoice($invoiceId);
        $hostingId = $service ? (int) $service['id'] : 0;
        if ($service && (int) $service['userid'] !== (int) $job['client_id']) {
            // Invoice/service mismatch — refuse rather than provision the wrong account.
            \Chs\Core\Logger::error('Provisioning invoice/service client mismatch', [
                'invoice' => $invoiceId, 'job' => (int) $job['id'],
            ]);
            return false;
        }

        $this->enqueueJobRun($job);

        Db::update('provisioning_jobs', ['id' => (int) $job['id']], [
            'hosting_id' => $hostingId,
            'updated_at' => Clock::now(),
        ]);
        if (!empty($job['module_server_id'])) {
            Db::update('module_servers', ['id' => (int) $job['module_server_id']], [
                'hosting_id' => $hostingId,
                'status'     => 'provisioning',
                'updated_at' => Clock::now(),
            ]);
        }
        Audit::system('server.provisioning_started', [
            'provisioning_job_id' => (int) $job['id'],
            'invoice_id'          => $invoiceId,
            'correlation'         => (string) $job['correlation_id'],
        ]);
        return true;
    }

    /**
     * Enqueue one worker run for a provisioning job. The queue idempotency
     * key is derived from the job key + run sequence, so a duplicate delivery
     * is a no-op but an admin retry is a fresh run.
     */
    public function enqueueJobRun(array $job)
    {
        $run = (int) $job['attempts'] + 1;
        Db::update('provisioning_jobs', ['id' => (int) $job['id']], [
            'attempts'   => $run,
            'updated_at' => Clock::now(),
        ]);
        return $this->queue->enqueue(InfraJobTypes::PROVISION, [
            'provisioning_job_id' => (int) $job['id'],
        ], [
            'idempotency_key' => $job['job_key'] . ':run:' . $run,
            'correlation_id'  => (string) $job['correlation_id'],
            'entity_type'     => 'module_server',
            'entity_id'       => (int) $job['module_server_id'],
            'max_attempts'    => max(1, (int) $job['max_attempts']),
        ]);
    }

    /* --------------------------------------------------------- server rows -- */

    /** @return array[] the client's servers (module registry + WHMCS service) */
    public function serversForClient($clientId)
    {
        $rows = Db::all('module_servers', ['client_id' => (int) $clientId], 'id DESC');
        foreach ($rows as &$row) {
            $row = $this->decorateServer($row);
        }
        unset($row);
        return $rows;
    }

    /** Ownership-checked single server. */
    public function serverForClient($clientId, $moduleServerId)
    {
        $row = Db::first('module_servers', ['id' => (int) $moduleServerId]);
        if (!$row || (int) $row['client_id'] !== (int) $clientId) {
            throw new NotFoundException('Server not found.');
        }
        return $this->decorateServer($row);
    }

    /** @return array|null raw module server row */
    public function serverRow($moduleServerId)
    {
        $row = Db::first('module_servers', ['id' => (int) $moduleServerId]);
        return $row ?: null;
    }

    /** Enrich a module server row with OS/version/product/region names. */
    public function decorateServer(array $row)
    {
        $version = $row['operating_system_version_id']
            ? $this->catalog->versionRow((int) $row['operating_system_version_id'])
            : null;
        $row['os_display'] = $version ? $version['display_name'] : 'UNKNOWN';
        $row['os_slug'] = '';
        if ($version) {
            $os = $this->catalog->findOs((int) $version['operating_system_id']);
            $row['os_slug'] = $os ? (string) $os['slug'] : '';
            $row['os_logo'] = $os ? (string) $os['logo_url'] : '';
        } else {
            $row['os_logo'] = '';
        }
        $row['region_name'] = '';
        if (!empty($row['region_id'])) {
            $region = Db::first('infrastructure_regions', ['id' => (int) $row['region_id']]);
            $row['region_name'] = $region ? (string) $region['name'] : '';
        }
        $row['provider_name'] = '';
        if (!empty($row['provider_id'])) {
            $provider = $this->registry->find((int) $row['provider_id']);
            $row['provider_name'] = $provider ? (string) $provider['name'] : '';
        }
        $row['product_name'] = '';
        $row['product_id'] = 0;
        if (!empty($row['hosting_id'])) {
            $hosting = Platform::gateway()->hostingDetail((int) $row['hosting_id']);
            if ($hosting) {
                $row['product_name'] = (string) $hosting['product_name'];
                $row['product_id'] = (int) $hosting['packageid'];
                $row['next_due'] = isset($hosting['nextduedate']) ? $hosting['nextduedate'] : null;
            }
        }
        return $row;
    }

    /* ------------------------------------------------------------ internals -- */

    /** Hostname validation (RFC-1123 labels, dotted form allowed). */
    public function isValidHostname($hostname)
    {
        if (strlen($hostname) > 253 || $hostname === '') {
            return false;
        }
        foreach (explode('.', $hostname) as $label) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return true;
    }

    /** OpenSSH public key format validation (public half only). */
    public function isValidPublicKey($key)
    {
        $parts = preg_split('/\s+/', trim((string) $key));
        if (count($parts) < 2) {
            return false;
        }
        $type = $parts[0];
        if (!in_array($type, ['ssh-rsa', 'ssh-ed25519', 'ssh-dss', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com'], true)) {
            return false;
        }
        $decoded = base64_decode($parts[1], true);
        return $decoded !== false && strlen($decoded) > 16;
    }

    /** SHA-256 fingerprint of a public key (display only). */
    public function fingerprint($publicKey)
    {
        $parts = preg_split('/\s+/', trim((string) $publicKey));
        $decoded = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if ($decoded === false) {
            return '';
        }
        return 'SHA256:' . rtrim(strtr(base64_encode(hash('sha256', $decoded, true)), '+/', '-_'), '=');
    }
}
