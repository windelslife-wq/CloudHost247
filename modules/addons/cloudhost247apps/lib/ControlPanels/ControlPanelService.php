<?php
/** Worker-only, audited entry point for registered control-panel adapters. */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Servers\AgentClient;
use Ch247Apps\Servers\CredentialVault;

class ControlPanelService
{
    /** @var Actor */
    private $actor;

    /** @var ControlPanelConnectionFactory */
    private $connections;

    /** @var ControlPanelAdapterInterface */
    private $adapter;

    public function __construct(Actor $actor = null, ControlPanelConnectionFactory $connections = null,
        ControlPanelAdapterInterface $adapter = null)
    {
        $this->actor = $actor ?: Actor::system('ControlPanelService');
        $this->connections = $connections ?: new ControlPanelConnectionFactory($this->actor);
        $this->adapter = $adapter ?: ControlPanelAdapterRegistry::forPanel('cpanel_whm');
    }

    public function verifyServer($serverId)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_VERIFY);
        $connection = $this->connections->forServer($serverId);
        $credential = $this->credentialRow($serverId);
        try {
            $result = $this->adapter->verify($connection);
        } catch (\Throwable $e) {
            if ($credential) {
                (new CredentialVault($this->actor))->markVerified((int) $credential['id'], false,
                    $e instanceof AppsException ? $e->errorCode() : 'CPANEL_VERIFICATION_FAILED');
            }
            try {
                $this->record($serverId, 'CPANEL_SERVER_VERIFICATION_FAILED', [
                    'error_code' => $e instanceof AppsException ? $e->errorCode() : 'CPANEL_VERIFICATION_FAILED',
                ], 'error');
            } catch (\Throwable $auditError) {
                // Preserve the original verification failure; the audit subsystem logs its own failure.
            }
            throw $e;
        }
        if ($credential) {
            (new CredentialVault($this->actor))->markVerified((int) $credential['id'], true);
        }
        $this->record($serverId, 'CPANEL_SERVER_VERIFIED', [
            'version' => isset($result['version']) ? (string) $result['version'] : null,
        ]);
        return $result;
    }

    public function getAccount($serverId, $username)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_VIEW);
        return $this->adapter->getAccount($this->connections->forServer($serverId), $username);
    }

    /** Read-only cPanel UAPI domain inventory through the fixed WHM proxy operation. */
    public function listDomains($serverId, $username, $expectedMainDomain)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_VIEW);
        ControlPanelAdapterRegistry::assertSupports($this->adapter, ['account.domains.list']);
        $serverId = (int) $serverId;
        $username = strtolower(trim((string) $username));
        $expectedMainDomain = strtolower(trim((string) $expectedMainDomain));
        $metadata = [
            'username' => $username,
            'expected_main_domain' => $expectedMainDomain,
            'module' => 'DomainInfo',
            'function' => 'list_domains',
        ];
        $this->record($serverId, 'CPANEL_UAPI_DOMAINS_REQUESTED', $metadata);
        try {
            $result = $this->adapter->listDomains(
                $this->connections->forServer($serverId), $username, $expectedMainDomain
            );
            if (!is_array($result) || !isset($result['domains']) || !is_array($result['domains'])
                || !isset($result['count']) || (int) $result['count'] !== count($result['domains'])
                || !isset($result['main_domain']) || $result['main_domain'] !== $expectedMainDomain) {
                throw new ConfigurationException('The cPanel adapter returned an invalid domain inventory.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
        } catch (\Throwable $e) {
            $failure = $metadata;
            $failure['error_code'] = $e instanceof AppsException ? $e->errorCode() : 'CPANEL_UAPI_DOMAINS_FAILED';
            try {
                $this->record($serverId, 'CPANEL_UAPI_DOMAINS_FAILED', $failure, 'error');
            } catch (\Throwable $auditError) {
                // Preserve the original provider error and job retry semantics.
            }
            throw $e;
        }
        $confirmed = $metadata;
        $confirmed['domain_count'] = (int) $result['count'];
        $this->record($serverId, 'CPANEL_UAPI_DOMAINS_CONFIRMED', $confirmed);
        return $result;
    }

    /** Read only the documented DomainInfo built-in alias values for one account. */
    public function listBuiltinDomainAliases($serverId, $username, $expectedMainDomain)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_VIEW);
        ControlPanelAdapterRegistry::assertSupports($this->adapter, ['account.domains.aliases.list']);
        $serverId = (int) $serverId;
        $username = strtolower(trim((string) $username));
        $expectedMainDomain = strtolower(trim((string) $expectedMainDomain));
        $metadata = [
            'username' => $username,
            'expected_main_domain' => $expectedMainDomain,
            'module' => 'DomainInfo',
            'function' => 'main_domain_builtin_subdomain_aliases',
        ];
        $this->record($serverId, 'CPANEL_UAPI_ALIASES_REQUESTED', $metadata);
        try {
            $result = $this->adapter->listBuiltinDomainAliases(
                $this->connections->forServer($serverId), $username, $expectedMainDomain
            );
            if (!is_array($result) || !isset($result['aliases']) || !is_array($result['aliases'])
                || !isset($result['count']) || (int) $result['count'] !== count($result['aliases'])
                || !isset($result['main_domain']) || $result['main_domain'] !== $expectedMainDomain
                || !isset($result['completeness']) || $result['completeness'] !== 'vendor_reported') {
                throw new ConfigurationException('The cPanel adapter returned an invalid built-in alias list.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
        } catch (\Throwable $e) {
            $failure = $metadata;
            $failure['error_code'] = $e instanceof AppsException ? $e->errorCode() : 'CPANEL_UAPI_ALIASES_FAILED';
            try {
                $this->record($serverId, 'CPANEL_UAPI_ALIASES_FAILED', $failure, 'error');
            } catch (\Throwable $auditError) {
                // Preserve the original provider error and job retry semantics.
            }
            throw $e;
        }
        $confirmed = $metadata;
        $confirmed['alias_count'] = (int) $result['count'];
        $confirmed['completeness'] = 'vendor_reported';
        $this->record($serverId, 'CPANEL_UAPI_ALIASES_CONFIRMED', $confirmed);
        return $result;
    }

    /** Read quota counters through the single fixed, allowlisted UAPI operation. */
    public function getQuotaUsage($serverId, $username, $expectedMainDomain)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_VIEW);
        ControlPanelAdapterRegistry::assertSupports($this->adapter, ['account.usage.quota.read']);
        $serverId = (int) $serverId;
        $username = strtolower(trim((string) $username));
        $expectedMainDomain = strtolower(trim((string) $expectedMainDomain));
        $metadata = [
            'username' => $username,
            'expected_main_domain' => $expectedMainDomain,
            'module' => 'Quota',
            'function' => 'get_quota_info',
        ];
        $this->record($serverId, 'CPANEL_UAPI_QUOTA_USAGE_REQUESTED', $metadata);
        try {
            $result = $this->adapter->getQuotaUsage(
                $this->connections->forServer($serverId), $username, $expectedMainDomain
            );
            $requiredFields = [
                'megabyte_limit', 'megabytes_remain', 'megabytes_used',
                'inode_limit', 'inodes_remain', 'inodes_used',
            ];
            $allowedFields = array_merge($requiredFields, [
                'under_inode_limit', 'under_megabyte_limit', 'under_quota_overall',
            ]);
            if (!is_array($result) || !isset($result['username'], $result['main_domain'], $result['usage'],
                $result['fields_reported'], $result['completeness'])
                || !is_string($result['username']) || !is_string($result['main_domain'])
                || strtolower($result['username']) !== $username
                || strtolower($result['main_domain']) !== $expectedMainDomain
                || !is_array($result['usage']) || !is_array($result['fields_reported'])
                || $result['completeness'] !== 'vendor_reported') {
                throw new ConfigurationException('The cPanel adapter returned an invalid quota-usage snapshot.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $usageFields = array_keys($result['usage']);
            if (count($usageFields) < count($requiredFields) || count($usageFields) > count($allowedFields)
                || array_diff($requiredFields, $usageFields) || array_diff($usageFields, $allowedFields)) {
                throw new ConfigurationException('The cPanel adapter returned incomplete or unsupported quota fields.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $reportedFields = [];
            foreach ($result['fields_reported'] as $field) {
                if (!is_string($field) || !in_array($field, $allowedFields, true) || isset($reportedFields[$field])) {
                    throw new ConfigurationException('The cPanel adapter returned an invalid quota field list.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
                $reportedFields[$field] = true;
            }
            if (count($reportedFields) !== count($usageFields) || array_diff($usageFields, array_keys($reportedFields))) {
                throw new ConfigurationException('The cPanel adapter quota field list does not match its values.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            foreach ($result['usage'] as $field => $value) {
                if (!is_string($field) || !in_array($field, $allowedFields, true)) {
                    throw new ConfigurationException('The cPanel adapter returned an unsupported quota field.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
                if (in_array($field, ['under_inode_limit', 'under_megabyte_limit', 'under_quota_overall'], true)) {
                    if (!is_bool($value)) {
                        throw new ConfigurationException('The cPanel adapter returned an invalid quota status.', [
                            'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                        ]);
                    }
                    continue;
                }
                $pattern = in_array($field, ['inode_limit', 'inodes_remain', 'inodes_used'], true)
                    ? '/^[0-9]{1,32}$/D' : '/^[0-9]{1,32}(?:\.[0-9]{1,12})?$/D';
                if (!is_string($value) || !preg_match($pattern, $value)) {
                    throw new ConfigurationException('The cPanel adapter returned an invalid quota value.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $failure = $metadata;
            $failure['error_code'] = $e instanceof AppsException ? $e->errorCode() : 'CPANEL_UAPI_QUOTA_USAGE_FAILED';
            try {
                $this->record($serverId, 'CPANEL_UAPI_QUOTA_USAGE_FAILED', $failure, 'error');
            } catch (\Throwable $auditError) {
                // Preserve the original provider error and job retry semantics.
            }
            throw $e;
        }
        $confirmed = $metadata;
        $confirmed['field_count'] = count($result['usage']);
        $confirmed['completeness'] = 'vendor_reported';
        $this->record($serverId, 'CPANEL_UAPI_QUOTA_USAGE_CONFIRMED', $confirmed);
        return $result;
    }

    /** Read the one allowlisted StatsBar bandwidth field for the mapped account. */
    public function getBandwidthUsage($serverId, $username, $expectedMainDomain)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_VIEW);
        ControlPanelAdapterRegistry::assertSupports($this->adapter, ['account.usage.bandwidth.read']);
        $serverId = (int) $serverId;
        $username = strtolower(trim((string) $username));
        $expectedMainDomain = strtolower(trim((string) $expectedMainDomain));
        $metadata = [
            'username' => $username,
            'expected_main_domain' => $expectedMainDomain,
            'module' => 'StatsBar',
            'function' => 'get_stats',
            'display' => 'bandwidthusage',
        ];
        $this->record($serverId, 'CPANEL_UAPI_BANDWIDTH_USAGE_REQUESTED', $metadata);
        try {
            $result = $this->adapter->getBandwidthUsage(
                $this->connections->forServer($serverId), $username, $expectedMainDomain
            );
            $fields = ['used', 'limit', 'percent', 'units', 'zero_is_unlimited', 'is_maxed', 'normalized'];
            if (!is_array($result) || !isset($result['username'], $result['main_domain'], $result['usage'],
                $result['fields_reported'], $result['completeness'])
                || !is_string($result['username']) || !is_string($result['main_domain'])
                || strtolower($result['username']) !== $username
                || strtolower($result['main_domain']) !== $expectedMainDomain
                || !is_array($result['usage']) || !is_array($result['fields_reported'])
                || $result['completeness'] !== 'vendor_reported'
                || array_diff($fields, array_keys($result['usage']))
                || array_diff(array_keys($result['usage']), $fields)
                || count($result['usage']) !== count($result['fields_reported'])) {
                throw new ConfigurationException('The cPanel adapter returned an invalid bandwidth-usage snapshot.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $reported = [];
            foreach ($result['fields_reported'] as $field) {
                if (!is_string($field) || !in_array($field, $fields, true) || isset($reported[$field])) {
                    throw new ConfigurationException('The cPanel adapter returned an invalid bandwidth field list.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
                $reported[$field] = true;
            }
            if (count($reported) !== count($fields) || array_diff($fields, array_keys($reported))) {
                throw new ConfigurationException('The cPanel adapter bandwidth field list does not match its values.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            foreach ($result['usage'] as $field => $value) {
                if (!is_string($field) || !in_array($field, $fields, true)) {
                    throw new ConfigurationException('The cPanel adapter returned an unsupported bandwidth field.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
                if (in_array($field, ['used', 'limit'], true)) {
                    if (!is_string($value) || !preg_match('/^[0-9]{1,32}(?:\\.[0-9]{1,12})?$/D', $value)) {
                        throw new ConfigurationException('The cPanel adapter returned an invalid bandwidth value.', [
                            'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                        ]);
                    }
                } elseif ($field === 'percent') {
                    if (!is_int($value) || $value < 0 || $value > 100) {
                        throw new ConfigurationException('The cPanel adapter returned an invalid bandwidth percentage.', [
                            'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                        ]);
                    }
                } elseif (in_array($field, ['zero_is_unlimited', 'is_maxed', 'normalized'], true)) {
                    if (!is_bool($value)) {
                        throw new ConfigurationException('The cPanel adapter returned an invalid bandwidth status.', [
                            'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                        ]);
                    }
                } elseif (!is_string($value) || !preg_match('/^[A-Za-z0-9 ._-]{1,16}$/D', $value)) {
                    throw new ConfigurationException('The cPanel adapter returned invalid bandwidth units.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $failure = $metadata;
            $failure['error_code'] = $e instanceof AppsException ? $e->errorCode() : 'CPANEL_UAPI_BANDWIDTH_USAGE_FAILED';
            try {
                $this->record($serverId, 'CPANEL_UAPI_BANDWIDTH_USAGE_FAILED', $failure, 'error');
            } catch (\Throwable $auditError) {
                // Preserve the original provider error and job retry semantics.
            }
            throw $e;
        }
        $confirmed = $metadata;
        $confirmed['field_count'] = count($result['usage']);
        $confirmed['completeness'] = 'vendor_reported';
        $this->record($serverId, 'CPANEL_UAPI_BANDWIDTH_USAGE_CONFIRMED', $confirmed);
        return $result;
    }

    public function createAccount($serverId, array $account, $idempotencyKey)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_MANAGE);
        $metadata = $this->safeAccountMetadata($account);
        return $this->mutate($serverId, 'CPANEL_ACCOUNT_CREATE', $metadata, function ($connection) use (
            $account, $idempotencyKey
        ) {
            return $this->adapter->createAccount($connection, $account, $idempotencyKey);
        });
    }

    public function suspendAccount($serverId, $username, $reason)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_MANAGE);
        $metadata = ['username' => (string) $username];
        return $this->mutate($serverId, 'CPANEL_ACCOUNT_SUSPEND', $metadata, function ($connection) use (
            $username, $reason
        ) {
            return $this->adapter->suspendAccount($connection, $username, $reason);
        });
    }

    public function unsuspendAccount($serverId, $username)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_MANAGE);
        $metadata = ['username' => (string) $username];
        return $this->mutate($serverId, 'CPANEL_ACCOUNT_UNSUSPEND', $metadata, function ($connection) use ($username) {
            return $this->adapter->unsuspendAccount($connection, $username);
        });
    }

    public function terminateAccount($serverId, $username, $confirmation)
    {
        $this->assertWorker(Rbac::PANEL_ACCOUNT_TERMINATE);
        $metadata = ['username' => (string) $username];
        return $this->mutate($serverId, 'CPANEL_ACCOUNT_TERMINATE', $metadata, function ($connection) use (
            $username, $confirmation
        ) {
            return $this->adapter->terminateAccount($connection, $username, $confirmation);
        }, 'warning');
    }

    private function mutate($serverId, $action, array $metadata, callable $operation, $severity = 'info')
    {
        $serverId = (int) $serverId;
        // This write-ahead audit entry is recorded before the external mutation.
        $this->record($serverId, $action . '_REQUESTED', $metadata, $severity);
        try {
            $result = $operation($this->connections->forServer($serverId));
        } catch (\Throwable $e) {
            $failure = $metadata;
            $failure['error_code'] = $e instanceof AppsException ? $e->errorCode() : 'CPANEL_OPERATION_FAILED';
            try {
                $this->record($serverId, $action . '_FAILED', $failure, 'error');
            } catch (\Throwable $auditError) {
                // Keep the provider failure and original job retry semantics intact.
            }
            throw $e;
        }
        $completed = $metadata;
        if (isset($result['created'])) {
            $completed['created'] = (bool) $result['created'];
        }
        if (isset($result['changed'])) {
            $completed['changed'] = (bool) $result['changed'];
        }
        if (isset($result['confirmed'])) {
            $completed['confirmed'] = (bool) $result['confirmed'];
        }
        if (isset($result['idempotent_replay'])) {
            $completed['idempotent_replay'] = (bool) $result['idempotent_replay'];
        }
        $this->record($serverId, $action . '_CONFIRMED', $completed, $severity);
        return $result;
    }

    private function assertWorker($permission)
    {
        AgentClient::assertWorkerContext();
        Rbac::assert($this->actor, $permission);
    }

    private function credentialRow($serverId)
    {
        return Db::first('server_credentials', [
            'server_id' => (int) $serverId,
            'credential_type' => CredentialVault::TYPE_WHM_API_TOKEN,
            'name' => 'primary',
            'status' => CredentialVault::STATUS_ACTIVE,
        ]);
    }

    private function safeAccountMetadata(array $account)
    {
        $metadata = [];
        $username = isset($account['username']) && is_scalar($account['username'])
            ? strtolower(trim((string) $account['username'])) : '';
        if (preg_match('/^[a-z][a-z0-9]{0,15}$/', $username)) {
            $metadata['username'] = $username;
        }
        $domain = isset($account['domain']) && is_scalar($account['domain'])
            ? strtolower(trim((string) $account['domain'])) : '';
        if ($domain !== '' && strlen($domain) <= 253
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
            $metadata['domain'] = $domain;
        }
        $package = isset($account['package']) && is_scalar($account['package'])
            ? trim((string) $account['package']) : '';
        if ($package !== '' && strlen($package) <= 80 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $package)) {
            $metadata['package'] = $package;
        }
        // Never copy password, token, contact details, or API response data into audit metadata.
        return $metadata;
    }

    private function record($serverId, $action, array $metadata, $severity = 'info')
    {
        $id = Audit::record($this->actor, (string) $action, [
            'resource_type' => 'control_panel_server',
            'resource_id' => (int) $serverId,
            'server_id' => (int) $serverId,
            'metadata' => $metadata,
            'severity' => (string) $severity,
        ]);
        if (!(int) $id) {
            throw new ConfigurationException('A control-panel action cannot run without a writable audit log.', [
                'error_code' => 'CPANEL_AUDIT_UNAVAILABLE',
            ]);
        }
        return $id;
    }
}
