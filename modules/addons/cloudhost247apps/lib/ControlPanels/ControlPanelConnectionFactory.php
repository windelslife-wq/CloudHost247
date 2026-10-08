<?php
/** Resolve cPanel connection material only from the registered server and vault. */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Servers\AgentClient;
use Ch247Apps\Servers\CredentialVault;
use Ch247Apps\Servers\ServerService;

class ControlPanelConnectionFactory
{
    /** @var Actor */
    private $actor;

    /** @var ServerService */
    private $servers;

    /** @var CredentialVault */
    private $vault;

    public function __construct(Actor $actor = null, ServerService $servers = null, CredentialVault $vault = null)
    {
        $this->actor = $actor ?: Actor::system('ControlPanelConnectionFactory');
        $this->servers = $servers ?: new ServerService($this->actor);
        $this->vault = $vault ?: new CredentialVault($this->actor);
    }

    /**
     * Resolve a transient adapter connection from trusted DB state.
     *
     * The hostname is always loaded from the registered server row and the token
     * is revealed from the existing encrypted credential vault. This operation
     * is worker-only; callers must never serialize or persist its return value.
     */
    public function forServer($serverId)
    {
        AgentClient::assertWorkerContext();
        Rbac::assert($this->actor, Rbac::SERVER_VIEW);
        $server = $this->servers->row((int) $serverId);
        $supportedTypes = [ServerService::TYPE_CPANEL, ServerService::TYPE_SHARED];
        if (!in_array((string) $server['server_type'], $supportedTypes, true)
            || empty($server['cpanel_enabled'])
            || in_array((string) $server['status'], [ServerService::STATUS_DISABLED, ServerService::STATUS_MAINTENANCE], true)) {
            throw new ConfigurationException('The selected server is not enabled for cPanel operations.', [
                'server_id' => (int) $server['id'], 'error_code' => 'CPANEL_SERVER_DISABLED',
            ]);
        }
        $credential = Db::first('server_credentials', [
            'server_id' => (int) $server['id'],
            'credential_type' => CredentialVault::TYPE_WHM_API_TOKEN,
            'name' => 'primary',
            'status' => CredentialVault::STATUS_ACTIVE,
        ]);
        if (!$credential || empty($credential['username'])) {
            throw new ConfigurationException('The cPanel server has no active named WHM API token.', [
                'server_id' => (int) $server['id'], 'error_code' => 'CPANEL_CREDENTIAL_MISSING',
            ]);
        }

        return [
            'hostname' => (string) $server['hostname'],
            'server_type' => (string) $server['server_type'],
            'cpanel_enabled' => (bool) $server['cpanel_enabled'],
            'credential_type' => CredentialVault::TYPE_WHM_API_TOKEN,
            'api_username' => (string) $credential['username'],
            // This plaintext is short-lived and must remain in the worker process.
            'api_token' => $this->vault->reveal((int) $credential['id']),
        ];
    }
}
