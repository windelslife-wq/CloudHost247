<?php
/**
 * CloudHost247 App Cloud — domain registry.
 *
 * The customer-facing half of domain management: which domains exist, who owns
 * them, which installation they point at and which one is primary. Verification
 * (DNS/HTTP/WHOIS) and certificate issuance live in the SSL/DNS services and
 * update the rows created here.
 *
 * A domain is registered once per customer and attached to installations, so the
 * same domain cannot silently end up routing to two applications.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Domains;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Validator;

class DomainService
{
    const TYPE_CUSTOMER   = 'customer';
    const TYPE_SUBDOMAIN  = 'subdomain';
    const TYPE_PLATFORM   = 'platform';

    const VERIFICATION_UNVERIFIED = 'unverified';
    const VERIFICATION_PENDING    = 'pending';
    const VERIFICATION_VERIFIED   = 'verified';
    const VERIFICATION_FAILED     = 'failed';

    const SSL_NONE     = 'none';
    const SSL_PENDING  = 'pending';
    const SSL_ISSUED   = 'issued';
    const SSL_FAILED   = 'failed';
    const SSL_EXPIRING = 'expiring';
    const SSL_EXPIRED  = 'expired';

    /** @var Actor */
    private $actor;

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('DomainService');
    }

    /* -------------------------------------------------------------- register */

    /**
     * Register a domain for a customer.
     *
     * @param array $input domain, domain_type, provider, whmcs_domain_id, notes
     * @return array the domain row (presentation form)
     */
    public function register($customerId, array $input)
    {
        if (!$this->actor->isMachine()) {
            if ((int) $customerId !== (int) $this->actor->clientId) {
                Rbac::assert($this->actor, Rbac::DOMAIN_MANAGE_ALL);
            } else {
                Rbac::assert($this->actor, Rbac::DOMAIN_MANAGE_OWN);
            }
        }

        $values = Validator::make($input)
            ->required('domain')->domain('domain')
            ->optional('domain_type', self::TYPE_CUSTOMER)
                ->in('domain_type', [self::TYPE_CUSTOMER, self::TYPE_SUBDOMAIN, self::TYPE_PLATFORM])
            ->optional('provider')->string('provider', 40)
            ->optional('whmcs_domain_id')->integer('whmcs_domain_id', 0)
            ->optional('notes')->string('notes', 1000)
            ->validate();

        $domain = strtolower(trim((string) $values['domain']));
        $existing = Db::first('domains', ['domain' => $domain, 'deleted_at' => null]);
        if ($existing) {
            if ((int) $existing['customer_id'] === (int) $customerId) {
                return $this->present($existing);
            }
            // Never reveal who owns it, and never allow a takeover by registering.
            throw new StateException('That domain is already registered on this platform.', [
                'error_code' => 'DOMAIN_ALREADY_REGISTERED',
            ]);
        }

        $now = Clock::now();
        $id = Db::insert('domains', [
            'customer_id' => (int) $customerId,
            'domain' => Str::clip($domain, 253),
            'domain_display' => isset($input['domain_display']) ? Str::clip($input['domain_display'], 253) : $domain,
            'domain_type' => (string) $values['domain_type'],
            'provider' => isset($values['provider']) ? $values['provider'] : null,
            'whmcs_domain_id' => !empty($values['whmcs_domain_id']) ? (int) $values['whmcs_domain_id'] : null,
            'verification_status' => self::VERIFICATION_UNVERIFIED,
            'verification_token' => Str::token(24),
            'dns_status' => 'unknown',
            'ssl_status' => self::SSL_NONE,
            'notes' => isset($values['notes']) ? $values['notes'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Audit::record($this->actor, Audit::DOMAIN_ADDED, [
            'resource_type' => 'domain', 'resource_id' => $id, 'client_id' => (int) $customerId,
            'metadata' => ['domain' => $domain, 'type' => $values['domain_type']],
        ]);
        Logger::info('Domain registered.', ['domain_id' => $id, 'client_id' => (int) $customerId,
            'source' => 'domains']);
        return $this->present($this->row($id));
    }

    /** Find by name, or register it. */
    public function findOrCreate($customerId, $domain, array $input = [])
    {
        $name = strtolower(trim((string) $domain));
        $existing = Db::first('domains', ['domain' => $name, 'deleted_at' => null]);
        if ($existing && (int) $existing['customer_id'] === (int) $customerId) {
            return $this->present($existing);
        }
        return $this->register($customerId, array_merge(['domain' => $name], $input));
    }

    /* ---------------------------------------------------------------- attach */

    /**
     * Attach a domain to an installation.
     *
     * @throws StateException when the domain already routes to another installation
     */
    public function attach($installationId, $domainId, $primary = false, $pathPrefix = null)
    {
        $installation = Db::first('installations', ['id' => (int) $installationId, 'deleted_at' => null]);
        if (!$installation) {
            throw new NotFoundException('That installation does not exist.');
        }
        $domain = $this->row($domainId);
        if ((int) $domain['customer_id'] !== (int) $installation['customer_id']
            && !$this->actor->can(Rbac::DOMAIN_MANAGE_ALL) && !$this->actor->isMachine()) {
            throw new NotFoundException('That domain does not exist.');
        }

        // One domain routes to one installation: attaching it elsewhere would
        // silently steal traffic from a running application.
        $elsewhere = Db::first('installation_domains', [
            'domain_id' => (int) $domain['id'], 'status' => ['in', ['pending', 'active']],
        ]);
        if ($elsewhere && (int) $elsewhere['installation_id'] !== (int) $installationId) {
            throw new StateException('That domain is already attached to another installation.', [
                'error_code' => 'DOMAIN_IN_USE', 'installation_id' => (int) $elsewhere['installation_id'],
            ]);
        }

        $now = Clock::now();
        $existing = Db::first('installation_domains', [
            'installation_id' => (int) $installationId, 'domain_id' => (int) $domain['id'],
        ]);
        $fields = [
            'installation_id' => (int) $installationId,
            'domain_id' => (int) $domain['id'],
            'primary_domain' => $primary ? 1 : 0,
            'path_prefix' => $pathPrefix === null || $pathPrefix === '' ? null
                : '/' . trim((string) $pathPrefix, '/'),
            'status' => 'pending',
            'updated_at' => $now,
        ];
        if ($existing) {
            Db::update('installation_domains', $fields, ['id' => (int) $existing['id']]);
        } else {
            $fields['created_at'] = $now;
            Db::insert('installation_domains', $fields);
        }

        if ($primary) {
            $this->makePrimary((int) $installationId, (int) $domain['id']);
            Db::update('installations', ['domain' => $domain['domain'], 'updated_at' => $now],
                ['id' => (int) $installationId]);
        }

        Audit::record($this->actor, Audit::DOMAIN_CHANGED, [
            'resource_type' => 'domain', 'resource_id' => (int) $domain['id'],
            'installation_id' => (int) $installationId, 'client_id' => (int) $installation['customer_id'],
            'metadata' => ['domain' => $domain['domain'], 'action' => 'attached', 'primary' => (bool) $primary],
        ]);
        Events::emit(Events::DOMAIN_ATTACHED, ['domain' => $domain['domain'], 'primary' => (bool) $primary],
            ['installation_id' => (int) $installationId, 'client_id' => (int) $installation['customer_id']]);

        return $this->present($this->row((int) $domain['id']));
    }

    public function detach($installationId, $domainId)
    {
        $link = Db::first('installation_domains', [
            'installation_id' => (int) $installationId, 'domain_id' => (int) $domainId,
        ]);
        if (!$link) {
            throw new NotFoundException('That domain is not attached to this installation.');
        }
        Db::update('installation_domains', ['status' => 'removed', 'primary_domain' => 0,
            'updated_at' => Clock::now()], ['id' => (int) $link['id']]);
        Audit::record($this->actor, Audit::DOMAIN_CHANGED, [
            'resource_type' => 'domain', 'resource_id' => (int) $domainId,
            'installation_id' => (int) $installationId,
            'metadata' => ['action' => 'detached'],
        ]);
        return true;
    }

    /** Exactly one primary domain per installation. */
    public function makePrimary($installationId, $domainId)
    {
        Db::update('installation_domains', ['primary_domain' => 0, 'updated_at' => Clock::now()],
            ['installation_id' => (int) $installationId]);
        $updated = Db::update('installation_domains', ['primary_domain' => 1, 'updated_at' => Clock::now()],
            ['installation_id' => (int) $installationId, 'domain_id' => (int) $domainId]);
        if (!$updated) {
            throw new NotFoundException('That domain is not attached to this installation.');
        }
        $domain = $this->row($domainId);
        Db::update('installations', ['domain' => $domain['domain'], 'updated_at' => Clock::now()],
            ['id' => (int) $installationId]);
        return $this->present($domain);
    }

    /** Mark a domain as attached and routing (the deployment engine calls this). */
    public function markActive($installationId, $domainId = null)
    {
        $where = ['installation_id' => (int) $installationId];
        if ($domainId !== null) {
            $where['domain_id'] = (int) $domainId;
        }
        Db::update('installation_domains', ['status' => 'active', 'updated_at' => Clock::now()], $where);
        return true;
    }

    /* ------------------------------------------------------------------ read */

    /** @throws NotFoundException */
    public function row($domainId)
    {
        $row = Db::first('domains', ['id' => (int) $domainId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That domain does not exist.');
        }
        return $row;
    }

    public function findByName($domain)
    {
        return Db::first('domains', ['domain' => strtolower(trim((string) $domain)), 'deleted_at' => null]);
    }

    public function forInstallation($installationId)
    {
        $out = [];
        foreach (Db::fetch('installation_domains', ['installation_id' => (int) $installationId,
            'status' => ['in', ['pending', 'active']]], ['order' => 'primary_domain', 'dir' => 'desc']) as $link) {
            $domain = Db::first('domains', ['id' => (int) $link['domain_id']]);
            if (!$domain) {
                continue;
            }
            $presented = $this->present($domain);
            $presented['primary'] = (bool) $link['primary_domain'];
            $presented['path_prefix'] = isset($link['path_prefix']) ? $link['path_prefix'] : null;
            $presented['attachment_status'] = $link['status'];
            $out[] = $presented;
        }
        return $out;
    }

    public function forCustomer($customerId, $limit = 200)
    {
        $isAdmin = $this->actor->can(Rbac::DOMAIN_VIEW_ALL);
        if (!$isAdmin && (int) $customerId !== (int) $this->actor->clientId) {
            throw new NotFoundException('Those domains do not exist.');
        }
        $out = [];
        foreach (Db::fetch('domains', ['customer_id' => (int) $customerId, 'deleted_at' => null],
            ['order' => 'domain', 'limit' => (int) $limit]) as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function present(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'customer_id' => (int) $row['customer_id'],
            'domain' => $row['domain'],
            'domain_display' => isset($row['domain_display']) && $row['domain_display'] !== ''
                ? $row['domain_display'] : $row['domain'],
            'domain_type' => $row['domain_type'],
            'provider' => isset($row['provider']) ? $row['provider'] : null,
            'whmcs_domain_id' => $row['whmcs_domain_id'] ? (int) $row['whmcs_domain_id'] : null,
            'verification_status' => $row['verification_status'],
            'verification_method' => isset($row['verification_method']) ? $row['verification_method'] : null,
            'verified_at' => isset($row['verified_at']) ? $row['verified_at'] : null,
            'dns_status' => $row['dns_status'],
            'resolved_ip' => isset($row['resolved_ip']) ? $row['resolved_ip'] : null,
            'ssl_status' => $row['ssl_status'],
            'ssl_issued_at' => isset($row['ssl_issued_at']) ? $row['ssl_issued_at'] : null,
            'ssl_expires_at' => isset($row['ssl_expires_at']) ? $row['ssl_expires_at'] : null,
            'expires_at' => isset($row['expires_at']) ? $row['expires_at'] : null,
            'notes' => isset($row['notes']) ? $row['notes'] : null,
            'created_at' => $row['created_at'],
        ];
    }

    /** The verification token a customer must publish (DNS TXT or HTTP file). */
    public function verificationChallenge($domainId)
    {
        $row = $this->row($domainId);
        $this->assertCanManage($row);
        return [
            'domain' => $row['domain'],
            'methods' => [
                ['method' => 'dns_txt', 'host' => '_cloudhost247-verify.' . $row['domain'],
                    'value' => (string) $row['verification_token'],
                    'instructions' => 'Create a TXT record with this value, then verify.'],
                ['method' => 'http', 'url' => 'http://' . $row['domain'] . '/.well-known/cloudhost247-verify',
                    'value' => (string) $row['verification_token'],
                    'instructions' => 'Serve this exact value as plain text at that URL, then verify.'],
            ],
            'status' => $row['verification_status'],
        ];
    }

    private function assertCanManage(array $row)
    {
        if ($this->actor->isMachine() || $this->actor->can(Rbac::DOMAIN_MANAGE_ALL)) {
            return true;
        }
        Rbac::assert($this->actor, Rbac::DOMAIN_MANAGE_OWN);
        if ((int) $row['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That domain does not exist.');
        }
        return true;
    }

    /** A platform-provided subdomain for customers without their own domain. */
    public function platformSubdomain($customerId, $slug)
    {
        $base = \Ch247Apps\Core\Settings::string('app_preview_domain', '');
        if ($base === '') {
            return null;
        }
        $name = Str::projectSlug((string) $slug, 40) . '-' . (int) $customerId . '.' . $base;
        $existing = $this->findByName($name);
        if ($existing) {
            return $this->present($existing);
        }
        $now = Clock::now();
        $id = Db::insert('domains', [
            'customer_id' => (int) $customerId,
            'domain' => $name,
            'domain_display' => $name,
            'domain_type' => self::TYPE_SUBDOMAIN,
            'provider' => 'platform',
            // A subdomain of a domain the platform controls needs no verification.
            'verification_status' => self::VERIFICATION_VERIFIED,
            'verified_at' => $now,
            'verification_method' => 'platform',
            'dns_status' => 'pointing',
            'ssl_status' => self::SSL_ISSUED,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->present($this->row($id));
    }

    /** Remove a domain (soft). Refused while it is attached to an installation. */
    public function remove($domainId)
    {
        $row = $this->row($domainId);
        $this->assertCanManage($row);
        $attached = Db::count('installation_domains', ['domain_id' => (int) $row['id'],
            'status' => ['in', ['pending', 'active']]]);
        if ($attached > 0) {
            throw new StateException('That domain is still attached to an installation.', [
                'error_code' => 'DOMAIN_IN_USE', 'attachments' => $attached,
            ]);
        }
        Db::update('domains', ['deleted_at' => Clock::now(), 'updated_at' => Clock::now()],
            ['id' => (int) $row['id']]);
        Audit::record($this->actor, Audit::DOMAIN_DELETED, [
            'resource_type' => 'domain', 'resource_id' => (int) $row['id'],
            'client_id' => (int) $row['customer_id'], 'metadata' => ['domain' => $row['domain']],
        ]);
        return true;
    }
}
