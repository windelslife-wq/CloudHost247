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
