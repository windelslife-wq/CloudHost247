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
