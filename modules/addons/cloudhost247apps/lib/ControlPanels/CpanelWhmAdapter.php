<?php
/**
 * cPanel & WHM account lifecycle adapter using the WHM API 1 surface.
 *
 * This does not install cPanel, activate a license, or deploy an application
 * into an account. Account mutations are worker-only, checked before and after
 * the API call, and return only a small allowlisted account projection.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Core\CpanelException;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\RetryableCpanelException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class CpanelWhmAdapter implements ControlPanelAdapterInterface
{
    /** @var CpanelWhmClient */
    private $client;

    public function __construct(CpanelWhmClient $client = null)
    {
        $this->client = $client ?: new CpanelWhmClient();
    }

    public function key()
    {
        return 'cpanel_whm';
    }

    public function name()
    {
        return 'cPanel & WHM';
    }

    public function capabilities()
    {
        return [
            'account.verify' => true,
            'account.get' => true,
            'account.domains.list' => true,
            'account.domains.aliases.list' => true,
            'account.usage.quota.read' => true,
            'account.usage.bandwidth.read' => true,
            'account.create' => true,
            'account.suspend' => true,
            'account.unsuspend' => true,
            'account.terminate' => true,
        ];
    }

    public function verify(array $connection)
    {
        $response = $this->client->call($connection, 'version', 'GET');
        $version = isset($response['data']['version']) ? $response['data']['version']
            : (isset($response['version']) ? $response['version'] : null);
        if (!is_scalar($version) || trim((string) $version) === '') {
            throw new CpanelException('WHM did not return a verifiable software version.', [
                'error_code' => 'CPANEL_VERSION_UNVERIFIED',
            ]);
        }
        return ['verified' => true, 'version' => Str::clip((string) $version, 80)];
    }

    /** Return null only after WHM confirms a valid list response with no match. */
    public function getAccount(array $connection, $username)
    {
        $username = self::username($username);
        $response = $this->client->call($connection, 'list_accounts', 'GET', [
            'searchtype' => 'user', 'search' => $username,
        ]);
        $rows = $this->accountRows($response);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw self::invalidAccountResponse();
            }
            $rowUsername = isset($row['user']) ? strtolower((string) $row['user'])
                : (isset($row['username']) ? strtolower((string) $row['username']) : '');
            if ($rowUsername === $username) {
                return $this->normaliseAccount($row, $username);
            }
        }
        return null;
    }

    /**
     * Read only the target cPanel account's UAPI DomainInfo::list_domains data.
     * The authenticated username and primary-domain binding come from trusted
     * worker state, never from an API request or queue payload.
     */
    public function listDomains(array $connection, $username, $expectedMainDomain)
    {
        $username = self::username($username);
        $expectedMainDomain = self::domainName($expectedMainDomain);
        $response = $this->client->call($connection, 'uapi_domain_list', 'GET', [
            'cpanel.user' => $username,
            'cpanel.module' => 'DomainInfo',
            'cpanel.function' => 'list_domains',
            // Exclude temporary domains so the inventory is stable and
            // customer-service oriented; the API still must return all fields.
            'hide_temporary_domains' => 1,
        ]);
        return $this->normaliseDomainInventory($response, $username, $expectedMainDomain);
    }

    /**
     * Read built-in subdomain aliases for the mapped account's primary domain.
     * The documented response contains alias labels, not account configuration.
     */
    public function listBuiltinDomainAliases(array $connection, $username, $expectedMainDomain)
    {
        $username = self::username($username);
        $expectedMainDomain = self::domainName($expectedMainDomain);
        $response = $this->client->call($connection, 'uapi_domain_aliases', 'GET', [
            'cpanel.user' => $username,
            'cpanel.module' => 'DomainInfo',
            'cpanel.function' => 'main_domain_builtin_subdomain_aliases',
            'hide_temporary_domains' => 1,
        ]);
        return $this->normaliseBuiltinDomainAliases($response, $username, $expectedMainDomain);
    }

    /** Read only cPanel's documented quota counters for the mapped account. */
    public function getQuotaUsage(array $connection, $username, $expectedMainDomain)
    {
        $username = self::username($username);
        $expectedMainDomain = self::domainName($expectedMainDomain);
        $response = $this->client->call($connection, 'uapi_quota_info', 'GET', [
            'cpanel.user' => $username,
            'cpanel.module' => 'Quota',
            'cpanel.function' => 'get_quota_info',
        ]);
        return $this->normaliseQuotaUsage($response, $username, $expectedMainDomain);
    }

    /** Read the single allowlisted bandwidth statistic for the mapped account. */
    public function getBandwidthUsage(array $connection, $username, $expectedMainDomain)
    {
        $username = self::username($username);
        $expectedMainDomain = self::domainName($expectedMainDomain);
        $response = $this->client->call($connection, 'uapi_bandwidth_stats', 'GET', [
            'cpanel.user' => $username,
            'cpanel.module' => 'StatsBar',
            'cpanel.function' => 'get_stats',
            'display' => 'bandwidthusage',
        ]);
        return $this->normaliseBandwidthUsage($response, $username, $expectedMainDomain);
    }

    /**
     * Create one account, or recover a prior create after an uncertain retry.
     *
     * WHM exposes no idempotency-key parameter for createacct. The adapter uses
     * the unique cPanel username as the recovery key and verifies the existing
     * domain/package before treating a retry as the same operation.
     */
    public function createAccount(array $connection, array $account, $idempotencyKey)
    {
        $account = $this->validateCreateAccount($account);
        $idempotencyKey = trim((string) $idempotencyKey);
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 120
            || !preg_match('/^[A-Za-z0-9_.:-]+$/', $idempotencyKey)) {
            throw new ValidationException('A valid idempotency key is required for cPanel account creation.', [
                'field' => 'idempotency_key',
            ]);
        }

        $existing = $this->getAccount($connection, $account['username']);
        if ($existing !== null) {
            $this->assertSameCreateRequest($existing, $account);
            return ['account' => $existing, 'created' => false, 'idempotent_replay' => true];
        }

        $parameters = [
            'username' => $account['username'],
            'domain' => $account['domain'],
            'plan' => $account['package'],
            'password' => $account['password'],
        ];
        if ($account['contact_email'] !== null) {
            $parameters['contactemail'] = $account['contact_email'];
        }

        try {
            $this->client->call($connection, 'create_account', 'POST', $parameters);
        } catch (CpanelException $e) {
            // A timeout or duplicate response may follow a successful remote
            // create. Reconcile by the immutable username/domain/package before
            // deciding whether to rethrow; never blindly repeat createacct.
            try {
                $existing = $this->getAccount($connection, $account['username']);
            } catch (\Throwable $lookupError) {
                throw $e;
            }
            if ($existing !== null) {
                $this->assertSameCreateRequest($existing, $account);
                return ['account' => $existing, 'created' => false, 'idempotent_replay' => true];
            }
            throw $e;
        }

        $created = $this->getAccount($connection, $account['username']);
        if ($created === null) {
            throw new RetryableCpanelException('WHM accepted account creation but the account is not yet verifiable.', [
                'error_code' => 'CPANEL_ACCOUNT_NOT_CONFIRMED',
            ]);
        }
        $this->assertSameCreateRequest($created, $account);
        return ['account' => $created, 'created' => true, 'idempotent_replay' => false];
    }

    public function suspendAccount(array $connection, $username, $reason)
    {
        $username = self::username($username);
        $reason = trim((string) $reason);
        if ($reason === '' || strlen($reason) > 200 || preg_match('/[\r\n\x00]/', $reason)) {
            throw new ValidationException('A cPanel suspension reason of 1–200 safe characters is required.', [
                'field' => 'reason',
            ]);
        }
        return $this->setSuspended($connection, $username, true, [
            'username' => $username, 'reason' => $reason,
        ], 'suspend_account');
    }

    public function unsuspendAccount(array $connection, $username)
    {
        $username = self::username($username);
        return $this->setSuspended($connection, $username, false, ['username' => $username], 'unsuspend_account');
    }

    public function terminateAccount(array $connection, $username, $confirmation)
    {
        $username = self::username($username);
        if (!is_string($confirmation) || !hash_equals('TERMINATE ' . $username, $confirmation)) {
            throw new ValidationException('Account termination requires the exact username-bound confirmation phrase.', [
                'error_code' => 'CPANEL_TERMINATION_CONFIRMATION_REQUIRED',
            ]);
        }
        $existing = $this->getAccount($connection, $username);
        if ($existing === null) {
            return ['terminated' => true, 'confirmed' => true, 'already_absent' => true];
        }

        try {
            $this->client->call($connection, 'terminate_account', 'POST', [
                'username' => $username,
                'keepdns' => 0,
            ]);
        } catch (CpanelException $e) {
            // Reconcile uncertain responses. Absence is the only confirmation
            // accepted for this destructive operation.
            try {
                if ($this->getAccount($connection, $username) === null) {
                    return ['terminated' => true, 'confirmed' => true, 'already_absent' => false];
                }
            } catch (\Throwable $lookupError) {
                throw $e;
            }
            throw $e;
        }

        if ($this->getAccount($connection, $username) !== null) {
            throw new RetryableCpanelException('WHM accepted account removal but the account is still present.', [
                'error_code' => 'CPANEL_TERMINATION_NOT_CONFIRMED',
            ]);
        }
        return ['terminated' => true, 'confirmed' => true, 'already_absent' => false];
    }

    private function setSuspended(array $connection, $username, $target, array $parameters, $operation)
    {
        $before = $this->getAccount($connection, $username);
        if ($before === null) {
            throw new NotFoundException('That cPanel account does not exist.', [
                'error_code' => 'CPANEL_ACCOUNT_NOT_FOUND',
            ]);
        }
        if (!is_bool($before['suspended'])) {
            throw new CpanelException('WHM did not return a verifiable account suspension state.', [
                'error_code' => 'CPANEL_STATE_UNVERIFIED',
            ]);
        }
        if ($before['suspended'] === $target) {
            return ['account' => $before, 'changed' => false, 'confirmed' => true];
        }

        $this->client->call($connection, $operation, 'POST', $parameters);
        $after = $this->getAccount($connection, $username);
        if ($after === null || !is_bool($after['suspended']) || $after['suspended'] !== $target) {
            throw new RetryableCpanelException('WHM accepted the account state change but read-back did not confirm it.', [
                'error_code' => 'CPANEL_STATE_CHANGE_NOT_CONFIRMED',
            ]);
        }
        return ['account' => $after, 'changed' => true, 'confirmed' => true];
    }

    private function validateCreateAccount(array $input)
    {
        $allowed = ['username', 'domain', 'package', 'password', 'contact_email'];
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new ValidationException('Unsupported cPanel account field.', ['field' => (string) $key]);
            }
        }
        $username = self::username(isset($input['username']) ? $input['username'] : '');
        $domain = strtolower(trim(isset($input['domain']) ? (string) $input['domain'] : ''));
        if ($domain === '' || strlen($domain) > 253 || substr($domain, -1) === '.'
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new ValidationException('A valid fully-qualified domain is required for a cPanel account.', [
                'field' => 'domain',
            ]);
        }
        $package = trim(isset($input['package']) ? (string) $input['package'] : '');
        if ($package === '' || strlen($package) > 80
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $package)) {
            throw new ValidationException('A valid WHM package name is required.', ['field' => 'package']);
        }
        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        if (strlen($password) < 16 || strlen($password) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new ValidationException('The cPanel account password must be 16–1024 safe characters.', [
                'field' => 'password',
            ]);
        }
        $email = isset($input['contact_email']) ? trim((string) $input['contact_email']) : '';
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new ValidationException('The cPanel account contact email is invalid.', ['field' => 'contact_email']);
        }
        return [
            'username' => $username,
            'domain' => $domain,
            'package' => $package,
            'password' => $password,
            'contact_email' => $email !== '' ? $email : null,
        ];
    }

    private function assertSameCreateRequest(array $existing, array $request)
    {
        if ($existing['domain'] !== $request['domain'] || $existing['package'] !== $request['package']) {
            throw new CpanelException('The cPanel username is already assigned to a different account configuration.', [
                'error_code' => 'CPANEL_ACCOUNT_CONFLICT',
            ]);
        }
    }

    private function normaliseDomainInventory(array $response, $expectedUsername, $expectedMainDomain)
    {
        $uapi = isset($response['data']['uapi']) && is_array($response['data']['uapi'])
            ? $response['data']['uapi'] : null;
        if ($uapi === null || !array_key_exists('status', $uapi)) {
            throw new CpanelException('cPanel UAPI returned an unverifiable domain inventory.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }
        if (!in_array($uapi['status'], [1, '1', true], true)
            || self::hasUapiMessages(isset($uapi['errors']) ? $uapi['errors'] : null)) {
            throw new CpanelException('cPanel UAPI rejected the domain inventory request.', [
                'error_code' => 'CPANEL_UAPI_API_REJECTED',
            ]);
        }
        if (self::hasUapiMessages(isset($uapi['warnings']) ? $uapi['warnings'] : null)) {
            throw new CpanelException('cPanel UAPI returned a warning; domain inventory completeness cannot be confirmed.', [
                'error_code' => 'CPANEL_UAPI_WARNING',
            ]);
        }

        $data = isset($uapi['data']) && is_array($uapi['data']) ? $uapi['data'] : null;
        $required = ['main_domain', 'addon_domains', 'parked_domains', 'sub_domains'];
        if ($data === null) {
            throw self::invalidDomainInventory();
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw self::invalidDomainInventory();
            }
        }

        // The cPanel API documents that permission failures may produce blank
        // output fields without an error. Requiring the expected main domain
        // prevents those responses from being represented as an empty inventory.
        if (!is_string($data['main_domain']) || trim($data['main_domain']) === '') {
            throw new CpanelException('cPanel did not return a verifiable primary domain for this account.', [
                'error_code' => 'CPANEL_UAPI_INVENTORY_UNVERIFIED',
            ]);
        }
        $mainDomain = self::domainName($data['main_domain']);
        if ($mainDomain !== $expectedMainDomain) {
            throw new CpanelException('cPanel UAPI returned a primary domain that does not match the linked account.', [
                'error_code' => 'CPANEL_ACCOUNT_BINDING_MISMATCH',
            ]);
        }

        $domains = [];
        $seen = [];
        $this->appendDomain($domains, $seen, $mainDomain, 'main');
        foreach ([
            'addon_domains' => 'addon',
            'parked_domains' => 'parked',
            'sub_domains' => 'sub',
        ] as $field => $type) {
            if (!is_array($data[$field])) {
                throw self::invalidDomainInventory();
            }
            foreach ($data[$field] as $domain) {
                if (!is_string($domain) || trim($domain) === '') {
                    throw self::invalidDomainInventory();
                }
                $this->appendDomain($domains, $seen, self::domainName($domain), $type);
            }
        }
        if (count($domains) > 1000) {
            throw new CpanelException('The cPanel domain inventory exceeds the supported safe response size.', [
                'error_code' => 'CPANEL_UAPI_INVENTORY_TOO_LARGE',
            ]);
        }

        return [
            'username' => $expectedUsername,
            'main_domain' => $mainDomain,
            'domains' => $domains,
            'count' => count($domains),
            'temporary_domains_excluded' => true,
        ];
    }

    private function normaliseBuiltinDomainAliases(array $response, $expectedUsername, $expectedMainDomain)
    {
        $uapi = isset($response['data']['uapi']) && is_array($response['data']['uapi'])
            ? $response['data']['uapi'] : null;
        if ($uapi === null || !array_key_exists('status', $uapi)) {
            throw new CpanelException('cPanel UAPI returned an unverifiable built-in alias list.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }
        if (!in_array($uapi['status'], [1, '1', true], true)
            || self::hasUapiMessages(isset($uapi['errors']) ? $uapi['errors'] : null)) {
            throw new CpanelException('cPanel UAPI rejected the built-in alias request.', [
                'error_code' => 'CPANEL_UAPI_API_REJECTED',
            ]);
        }
        if (self::hasUapiMessages(isset($uapi['warnings']) ? $uapi['warnings'] : null)) {
            throw new CpanelException('cPanel UAPI returned a warning; built-in aliases cannot be confirmed.', [
                'error_code' => 'CPANEL_UAPI_WARNING',
            ]);
        }
        if (!isset($uapi['data']) || !is_array($uapi['data']) || $uapi['data'] !== array_values($uapi['data'])) {
            throw new CpanelException('cPanel UAPI returned an unverifiable built-in alias list.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }

        $aliases = [];
        $seen = [];
        foreach ($uapi['data'] as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 253) {
                throw new CpanelException('cPanel UAPI returned a malformed built-in alias.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $value = strtolower(trim($value));
            if (strpos($value, '.') === false) {
                $label = $value;
                if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                    throw new CpanelException('cPanel UAPI returned a malformed built-in alias.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
                $domain = $label . '.' . $expectedMainDomain;
            } else {
                $domain = self::domainName($value);
                $suffix = '.' . $expectedMainDomain;
                if (substr($domain, -strlen($suffix)) !== $suffix) {
                    throw new CpanelException('cPanel UAPI returned an alias outside the linked primary domain.', [
                        'error_code' => 'CPANEL_ACCOUNT_BINDING_MISMATCH',
                    ]);
                }
                $label = substr($domain, 0, -strlen($suffix));
                if ($label === '' || filter_var($label, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                    throw new CpanelException('cPanel UAPI returned a malformed built-in alias.', [
                        'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                    ]);
                }
            }
            if (isset($seen[$domain])) {
                throw new CpanelException('cPanel UAPI returned a duplicate built-in alias.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $seen[$domain] = true;
            $aliases[] = ['alias' => $label, 'domain' => $domain];
        }
        if (count($aliases) > 1000) {
            throw new CpanelException('The cPanel built-in alias list exceeds the supported safe response size.', [
                'error_code' => 'CPANEL_UAPI_INVENTORY_TOO_LARGE',
            ]);
        }

        return [
            'username' => $expectedUsername,
            'main_domain' => $expectedMainDomain,
            'aliases' => $aliases,
            'count' => count($aliases),
            'completeness' => 'vendor_reported',
            'temporary_domains_excluded' => true,
        ];
    }

    private function normaliseQuotaUsage(array $response, $expectedUsername, $expectedMainDomain)
    {
        $uapi = isset($response['data']['uapi']) && is_array($response['data']['uapi'])
            ? $response['data']['uapi'] : null;
        if ($uapi === null || !array_key_exists('status', $uapi)) {
            throw new CpanelException('cPanel UAPI returned an unverifiable quota snapshot.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }
        if (!in_array($uapi['status'], [1, '1', true], true)
            || self::hasUapiMessages(isset($uapi['errors']) ? $uapi['errors'] : null)) {
            throw new CpanelException('cPanel UAPI rejected the quota information request.', [
                'error_code' => 'CPANEL_UAPI_API_REJECTED',
            ]);
        }
        if (self::hasUapiMessages(isset($uapi['warnings']) ? $uapi['warnings'] : null)) {
            throw new CpanelException('cPanel UAPI returned a warning; quota usage cannot be confirmed.', [
                'error_code' => 'CPANEL_UAPI_WARNING',
            ]);
        }
        if (!isset($uapi['data']) || !is_array($uapi['data']) || !$uapi['data']) {
            throw new CpanelException('cPanel UAPI omitted the quota fields needed for a snapshot.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }

        $usage = [];
        $decimalFields = ['megabyte_limit', 'megabytes_remain', 'megabytes_used'];
        $integerFields = ['inode_limit', 'inodes_remain', 'inodes_used'];
        $booleanFields = ['under_inode_limit', 'under_megabyte_limit', 'under_quota_overall'];
        foreach (array_merge($decimalFields, $integerFields) as $field) {
            if (!array_key_exists($field, $uapi['data'])) {
                continue;
            }
            $value = $uapi['data'][$field];
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new CpanelException('cPanel UAPI returned a malformed quota value.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $value = trim((string) $value);
            $pattern = in_array($field, $integerFields, true) ? '/^[0-9]{1,32}$/D' : '/^[0-9]{1,32}(?:\.[0-9]{1,12})?$/D';
            if (!preg_match($pattern, $value)) {
                throw new CpanelException('cPanel UAPI returned a malformed quota value.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
            $usage[$field] = $value;
        }
        foreach ($booleanFields as $field) {
            if (!array_key_exists($field, $uapi['data'])) {
                continue;
            }
            $value = $uapi['data'][$field];
            if (in_array($value, [1, '1', true], true)) {
                $usage[$field] = true;
            } elseif (in_array($value, [0, '0', false], true)) {
                $usage[$field] = false;
            } else {
                throw new CpanelException('cPanel UAPI returned a malformed quota status.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
        }
        $requiredFields = [
            'megabyte_limit', 'megabytes_remain', 'megabytes_used',
            'inode_limit', 'inodes_remain', 'inodes_used',
        ];
        if (array_diff($requiredFields, array_keys($usage))) {
            throw new CpanelException('cPanel UAPI omitted one or more required disk or inode quota values.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }

        return [
            'username' => $expectedUsername,
            'main_domain' => $expectedMainDomain,
            'usage' => $usage,
            'fields_reported' => array_keys($usage),
            'completeness' => 'vendor_reported',
        ];
    }

    private function normaliseBandwidthUsage(array $response, $expectedUsername, $expectedMainDomain)
    {
        $uapi = isset($response['data']['uapi']) && is_array($response['data']['uapi'])
            ? $response['data']['uapi'] : null;
        if ($uapi === null || !array_key_exists('status', $uapi)) {
            throw new CpanelException('cPanel UAPI returned an unverifiable bandwidth snapshot.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }
        if (!in_array($uapi['status'], [1, '1', true], true)
            || self::hasUapiMessages(isset($uapi['errors']) ? $uapi['errors'] : null)) {
            throw new CpanelException('cPanel UAPI rejected the bandwidth statistics request.', [
                'error_code' => 'CPANEL_UAPI_API_REJECTED',
            ]);
        }
        if (self::hasUapiMessages(isset($uapi['warnings']) ? $uapi['warnings'] : null)) {
            throw new CpanelException('cPanel UAPI returned a warning; bandwidth usage cannot be confirmed.', [
                'error_code' => 'CPANEL_UAPI_WARNING',
            ]);
        }
        if (!isset($uapi['data']) || !is_array($uapi['data'])
            || $uapi['data'] !== array_values($uapi['data']) || count($uapi['data']) !== 1
            || !isset($uapi['data'][0]) || !is_array($uapi['data'][0])) {
            throw new CpanelException('cPanel UAPI did not return exactly one bandwidth statistics row.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }

        $row = $uapi['data'][0];
        foreach (['id', '_count', '_max', 'percent', 'units',
            'zeroisunlimited', 'is_maxed', 'normalized'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new CpanelException('cPanel UAPI omitted required bandwidth statistics fields.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
        }
        if (!is_string($row['id']) || $row['id'] !== 'bandwidthusage') {
            throw new CpanelException('cPanel UAPI returned bandwidth statistics outside the requested display item.', [
                'error_code' => 'CPANEL_ACCOUNT_BINDING_MISMATCH',
            ]);
        }
        $used = self::nonNegativeDecimal($row['_count']);
        $limit = self::nonNegativeDecimal($row['_max']);
        if ($used === null || $limit === null
            || !is_string($row['units']) || !preg_match('/^[A-Za-z0-9 ._-]{1,16}$/D', $row['units'])) {
            throw new CpanelException('cPanel UAPI returned malformed bandwidth statistics values.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }
        $percent = $row['percent'];
        if (is_string($percent) && preg_match('/^([0-9]{1,3})%?$/D', $percent, $percentMatch)) {
            $percent = (int) $percentMatch[1];
        }
        if (!is_int($percent) || $percent < 0 || $percent > 100) {
            throw new CpanelException('cPanel UAPI returned a malformed bandwidth percentage.', [
                'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
            ]);
        }
        $binaryFlags = [];
        foreach (['zeroisunlimited', 'is_maxed', 'normalized'] as $field) {
            $value = $row[$field];
            if (in_array($value, [1, '1', true], true)) {
                $binaryFlags[$field] = true;
            } elseif (in_array($value, [0, '0', false], true)) {
                $binaryFlags[$field] = false;
            } else {
                throw new CpanelException('cPanel UAPI returned an invalid bandwidth status flag.', [
                    'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
                ]);
            }
        }

        return [
            'username' => $expectedUsername,
            'main_domain' => $expectedMainDomain,
            'usage' => [
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'units' => $row['units'],
                'zero_is_unlimited' => $binaryFlags['zeroisunlimited'],
                'is_maxed' => $binaryFlags['is_maxed'],
                'normalized' => $binaryFlags['normalized'],
            ],
            'fields_reported' => ['used', 'limit', 'percent', 'units',
                'zero_is_unlimited', 'is_maxed', 'normalized'],
            'completeness' => 'vendor_reported',
        ];
    }

    private static function nonNegativeDecimal($value)
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        if (is_float($value) && (!is_finite($value) || $value < 0)) {
            return null;
        }
        $value = (string) $value;
        return preg_match('/^[0-9]{1,32}(?:\\.[0-9]{1,12})?$/D', $value) ? $value : null;
    }

    private function appendDomain(array &$domains, array &$seen, $domain, $type)
    {
        if (isset($seen[$domain])) {
            if ($seen[$domain] !== $type) {
                throw self::invalidDomainInventory();
            }
            return;
        }
        $seen[$domain] = $type;
        $domains[] = ['domain' => $domain, 'type' => $type];
    }

    private static function domainName($value)
    {
        if (!is_string($value) && !is_scalar($value)) {
            throw self::invalidDomainInventory();
        }
        $domain = strtolower(trim((string) $value));
        if ($domain === '' || strlen($domain) > 253 || substr($domain, -1) === '.'
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw self::invalidDomainInventory();
        }
        return $domain;
    }

    private static function hasUapiMessages($messages)
    {
        if ($messages === null || $messages === false || $messages === '' || $messages === []) {
            return false;
        }
        return true;
    }

    private static function invalidDomainInventory()
    {
        return new CpanelException('cPanel UAPI returned a domain inventory that could not be safely interpreted.', [
            'error_code' => 'CPANEL_UAPI_RESPONSE_INVALID',
        ]);
    }

    private function accountRows(array $response)
    {
        if (!isset($response['data']) || !is_array($response['data'])
            || !array_key_exists('acct', $response['data'])) {
            throw self::invalidAccountResponse();
        }
        $rows = $response['data']['acct'];
        if (!is_array($rows)) {
            throw self::invalidAccountResponse();
        }
        if (isset($rows['user']) || isset($rows['username'])) {
            $rows = [$rows];
        }
        return $rows;
    }

    private function normaliseAccount(array $row, $expectedUsername)
    {
        $username = isset($row['user']) ? strtolower((string) $row['user'])
            : (isset($row['username']) ? strtolower((string) $row['username']) : '');
        if ($username !== $expectedUsername) {
            throw self::invalidAccountResponse();
        }
        $domain = isset($row['domain']) ? strtolower((string) $row['domain']) : '';
        $package = isset($row['plan']) ? (string) $row['plan']
            : (isset($row['package']) ? (string) $row['package'] : '');
        if ($domain === '') {
            throw self::invalidAccountResponse();
        }
        $suspended = null;
        if (array_key_exists('suspended', $row)) {
            $suspended = self::normaliseBoolean($row['suspended']);
        } elseif (array_key_exists('suspend', $row)) {
            $suspended = self::normaliseBoolean($row['suspend']);
        }
        return [
            'username' => $username,
            'domain' => $domain,
            'package' => Str::clip($package, 80),
            'suspended' => $suspended,
            'suspend_reason' => isset($row['suspendreason']) ? Str::clip((string) $row['suspendreason'], 200) : null,
        ];
    }

    private static function normaliseBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        if (in_array($value, ['1', 'yes', 'true', 'suspended'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'no', 'false', 'unsuspended'], true)) {
            return false;
        }
        return null;
    }

    private static function username($value)
    {
        $username = strtolower(trim((string) $value));
        if ($username === '' || strlen($username) > 16 || !preg_match('/^[a-z][a-z0-9]{0,15}$/', $username)) {
            throw new ValidationException('A valid cPanel username is required.', ['field' => 'username']);
        }
        return $username;
    }

    private static function invalidAccountResponse()
    {
        return new CpanelException('WHM returned an account list that could not be safely interpreted.', [
            'error_code' => 'CPANEL_ACCOUNT_RESPONSE_INVALID',
        ]);
    }
}
