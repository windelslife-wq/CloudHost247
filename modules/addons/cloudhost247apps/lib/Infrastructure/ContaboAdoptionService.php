<?php
/** Staff-approved binding of an already paid WHMCS service to an existing Contabo instance. */
namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Integration\Gateway;
use Ch247Apps\Integration\GatewayInterface;

class ContaboAdoptionService
{
    private $actor;
    private $gateway;
    private $queue;
    private $accounts;

    public function __construct(Actor $actor, GatewayInterface $gateway = null, JobQueue $queue = null)
    {
        $this->actor = $actor;
        $this->gateway = $gateway ?: Gateway::get();
        $this->queue = $queue ?: new JobQueue();
        $this->accounts = new ProviderAccountService($actor, $this->queue);
    }

    public function request(array $input, $idempotencyKey)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_MANAGE);
        Rbac::assert($this->actor, Rbac::PROVIDER_ACCOUNT_MANAGE);
        $this->assertEnabled();
        foreach ($input as $field => $value) {
            if (!in_array($field, ['service_id', 'provider_account_id', 'provider_instance_id',
                'contabo_product_id', 'spec', 'acknowledge_existing_contract'], true)) {
                throw new ValidationException('Unsupported Contabo adoption field.', ['field' => (string) $field]);
            }
        }
        if (!isset($input['acknowledge_existing_contract']) || $input['acknowledge_existing_contract'] !== true) {
            throw new ValidationException('Explicitly confirm this Contabo instance already has an external contract.');
        }
        $serviceId = $this->positiveId(isset($input['service_id']) ? $input['service_id'] : null);
        $accountId = $this->positiveId(isset($input['provider_account_id']) ? $input['provider_account_id'] : null);
        $instanceId = (string) $this->positiveId(isset($input['provider_instance_id']) ? $input['provider_instance_id'] : null);
        $productId = isset($input['contabo_product_id']) ? $input['contabo_product_id'] : null;
        if (!is_string($productId) || !preg_match('/^V[1-9][0-9]{0,8}$/D', $productId)) {
            throw new ValidationException('A Contabo product ID is required.');
        }
        if (!isset($input['spec']) || !is_array($input['spec'])) {
            throw new ValidationException('An approved instance specification is required.');
        }
        foreach ($input['spec'] as $field => $value) {
            if (!in_array($field, ['region', 'image', 'cpu_cores', 'memory_mb', 'storage_gb'], true)) {
                throw new ValidationException('Only the approved instance envelope is accepted.');
            }
        }
        $spec = ServerSpec::normalise(array_merge(['name' => 'vm-service-' . $serviceId], $input['spec']));
        unset($spec['name'], $spec['hostname']);
        $key = trim((string) $idempotencyKey);
        if ($key === '' || strlen($key) > 120 || preg_match('/[\x00-\x20\x7F]/', $key)) {
            throw new ValidationException('A valid Idempotency-Key is required.');
        }
        $payload = compact('serviceId', 'accountId', 'instanceId', 'productId', 'spec');
        $run = Idempotency::run('contabo.adopt-existing', $key, $payload, function () use ($payload) {
            return $this->reserve($payload);
        });
        $ids = $run['result'];
        return ['adoption' => $this->get((int) $ids['adoption_id']),
            'job_id' => (int) $ids['job_id'], 'replayed' => !empty($run['replayed'])];
    }

    private function reserve(array $p)
    {
        $this->assertEnabled();
        $billing = $this->billingAndProduct($p['serviceId']);
        $this->assertAccount($p['accountId']);
        return Db::transaction(function () use ($p, $billing) {
            $this->assertEnabled();
            $billing = $this->billingAndProduct($p['serviceId']);
            $this->assertAccount($p['accountId']);
            $existing = Db::first('contabo_adoptions', ['whmcs_service_id' => $p['serviceId']]);
            if ($existing) {
                if ((int) $existing['provider_account_id'] !== $p['accountId']
                    || (string) $existing['provider_instance_id'] !== $p['instanceId']
                    || (string) $existing['expected_product_id'] !== $p['productId']
                    || Str::jsonDecode($existing['expected_spec'], null) !== $p['spec']) {
                    throw new ConflictException('This WHMCS service has a different Contabo adoption.');
                }
                if ($existing['status'] !== 'failed') {
                    return ['adoption_id' => (int) $existing['id'], 'job_id' => 0];
                }
                Db::update('contabo_adoptions', ['status' => 'pending', 'error_code' => null,
                    'updated_at' => Clock::now()], ['id' => $existing['id']]);
                $adoptionId = (int) $existing['id'];
            } else {
                if (Db::first('customer_servers', ['whmcs_service_id' => $p['serviceId']])
                    || Db::first('contabo_adoptions', ['provider_account_id' => $p['accountId'],
                        'provider_instance_id' => $p['instanceId']])) {
                    throw new ConflictException('The service or Contabo instance is already bound elsewhere.');
                }
                $adoptionId = Db::insert('contabo_adoptions', [
                    'whmcs_service_id' => $p['serviceId'], 'client_id' => $billing['client_id'],
                    'whmcs_order_id' => $billing['order_id'], 'whmcs_invoice_id' => $billing['invoice_id'],
                    'provider_account_id' => $p['accountId'], 'provider_instance_id' => $p['instanceId'],
                    'expected_spec' => Str::jsonEncode($p['spec']), 'verified_spec' => null,
                    'expected_product_id' => $p['productId'], 'provider_status' => null,
                    'ipv4' => null, 'ipv6' => null, 'status' => 'pending', 'error_code' => null,
                    'requested_by' => Str::clip($this->actor->identity(), 120),
                    'created_at' => Clock::now(), 'updated_at' => Clock::now(),
                ]);
            }
            $job = $this->queue->enqueue(JobQueue::TYPE_CONTABO_ADOPT, ['adoption_id' => $adoptionId], [
                'queue' => JobQueue::QUEUE_PROVISIONING,
                'idempotency_key' => 'contabo-adopt:' . $adoptionId,
                'provider_account_id' => $p['accountId'],
                'whmcs_service_id' => $p['serviceId'], 'client_id' => $billing['client_id'],
                'requested_by' => $this->actor->identity(),
            ]);
            Audit::record($this->actor, 'CONTABO_ADOPTION_REQUESTED', [
                'resource_type' => 'contabo_adoption', 'resource_id' => $adoptionId,
                'metadata' => ['whmcs_service_id' => $p['serviceId'], 'provider_account_id' => $p['accountId']],
            ]);
            return ['adoption_id' => $adoptionId, 'job_id' => (int) $job['id']];
        });
    }

    public function billingAndProduct($serviceId)
    {
        $billing = (new CustomerServerService($this->actor, $this->gateway))->billingContextForExisting($serviceId);
        $service = $this->gateway->getService($serviceId);
        if (!$service || strtolower(trim((string) (isset($service['domainstatus']) ? $service['domainstatus'] : ''))) !== 'active') {
            throw new ProviderConfigurationException('Only an active, already-existing WHMCS service can be adopted.');
        }
        $productId = isset($service['packageid']) ? (int) $service['packageid'] : 0;
        $product = $productId > 0 ? $this->gateway->getProduct($productId) : null;
        if (!$product || (string) $product['type'] !== 'server'
            || (string) $product['paytype'] !== 'recurring'
            || !in_array(trim((string) (isset($product['servermodule']) ? $product['servermodule'] : '')),
                ['', 'contabo'], true)
            || Db::first('server_product_mappings', ['whmcs_product_id' => $productId])) {
            throw new ProviderConfigurationException('This WHMCS product is not eligible for Contabo adoption.');
        }
        return $billing;
    }

    public function assertAccount($accountId)
    {
        $account = $this->accounts->assertOperational($accountId, ['server.get']);
        if ($account['provider_code'] !== 'contabo') {
            throw new ProviderConfigurationException('Only a verified Contabo account can adopt Contabo instances.');
        }
    }

    public function assertEnabled()
    {
        if (!Settings::bool('contabo_adoption_enabled', false)) {
            throw new ProviderConfigurationException('Read-only Contabo adoption is disabled until staging approval.');
        }
    }

    public function internalRow($id)
    {
        $row = Db::first('contabo_adoptions', ['id' => (int) $id]);
        if (!$row) { throw new NotFoundException('Contabo adoption not found.'); }
        return $row;
    }

    public function get($id)
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_ALL);
        $row = $this->internalRow($id);
        return self::present($row);
    }

    public function listing()
    {
        Rbac::assert($this->actor, Rbac::CUSTOMER_SERVER_VIEW_ALL);
        return array_map([self::class, 'present'], Db::fetch('contabo_adoptions', [], ['order' => 'id']));
    }

    private static function present(array $row)
    {
        return [
            'id' => (int) $row['id'], 'service_id' => (int) $row['whmcs_service_id'],
            'provider_account_id' => (int) $row['provider_account_id'],
            'provider_instance_id' => (string) $row['provider_instance_id'],
            'client_id' => (int) $row['client_id'], 'status' => (string) $row['status'],
            'provider_status' => $row['provider_status'], 'ipv4' => $row['ipv4'], 'ipv6' => $row['ipv6'],
            'expected_spec' => Str::jsonDecode($row['expected_spec'], []),
            'verified_spec' => Str::jsonDecode($row['verified_spec'], null),
            'error_code' => $row['error_code'], 'updated_at' => $row['updated_at'],
        ];
    }

    private function positiveId($value)
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value)
            || (string) (int) $value !== (string) $value) {
            throw new ValidationException('A positive numeric identifier is required.');
        }
        return (int) $value;
    }
}
