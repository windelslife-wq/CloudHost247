<?php
/**
 * Thin, read-mostly bridge to the host WHMCS install.
 *
 * Everything here degrades gracefully outside WHMCS (tests, CLI) so the module
 * never fatals just because a core helper is missing.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Core;

class Whmcs
{
    /** @var callable|null test seam for the local API */
    private static $apiFake;
    /** @var string|null test seam */
    private static $systemUrlOverride;

    public static function setApiFake($fn)
    {
        self::$apiFake = $fn;
    }

    public static function setSystemUrl($url)
    {
        self::$systemUrlOverride = $url === null ? null : rtrim((string) $url, '/');
    }

    /** Public base URL of the WHMCS install, no trailing slash. */
    public static function systemUrl()
    {
        if (self::$systemUrlOverride !== null) {
            return self::$systemUrlOverride;
        }
        $configured = Settings::string('tracking_base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        try {
            if (Db::whmcsTableExists('tblconfiguration')) {
                $rows = Db::query("SELECT value FROM tblconfiguration WHERE setting = 'SystemURL' LIMIT 1");
                if ($rows && $rows[0]['value'] !== '') {
                    return rtrim((string) $rows[0]['value'], '/');
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    /** Absolute URL to a file inside this addon module. */
    public static function moduleUrl($file)
    {
        $base = self::systemUrl();
        return ($base === '' ? '' : $base) . '/modules/addons/' . CH247M_MODULE_NAME . '/' . ltrim((string) $file, '/');
    }

    /** Call the WHMCS local API, or the injected fake in tests. */
    public static function api($command, array $params = [])
    {
        if (self::$apiFake !== null) {
            return call_user_func(self::$apiFake, $command, $params);
        }
        if (!function_exists('localAPI')) {
            throw new ServiceUnavailableException('WHMCS local API is not available in this context.');
        }
        return localAPI($command, $params);
    }

    /**
     * Resolve the merge-tag context for a WHMCS client.
     *
     * Returns only the fields the personaliser exposes. Never returns secrets,
     * password hashes or payment data.
     *
     * @return array<string,string>
     */
    public static function clientContext($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0 || !Db::whmcsTableExists('tblclients')) {
            return [];
        }
        $rows = Db::query(
            'SELECT id, firstname, lastname, companyname, email, country FROM tblclients WHERE id = ? LIMIT 1',
            [$clientId]
        );
        if (!$rows) {
            return [];
        }
        $c = $rows[0];
        $ctx = [
            'client_id'  => (string) $c['id'],
            'first_name' => (string) $c['firstname'],
            'last_name'  => (string) $c['lastname'],
            'company'    => (string) $c['companyname'],
            'email'      => (string) $c['email'],
            'country'    => (string) $c['country'],
        ];

        // Nearest upcoming service, if any — powers {{service_name}} / {{renewal_date}}.
        if (Db::whmcsTableExists('tblhosting') && Db::whmcsTableExists('tblproducts')) {
            $svc = Db::query(
                'SELECT h.domain, h.nextduedate, p.name AS product
                   FROM tblhosting h LEFT JOIN tblproducts p ON p.id = h.packageid
                  WHERE h.userid = ? AND h.domainstatus = ?
               ORDER BY h.nextduedate ASC LIMIT 1',
                [$clientId, 'Active']
            );
            if ($svc) {
                $ctx['service_name']  = (string) ($svc[0]['product'] ?? '');
                $ctx['domain']        = (string) ($svc[0]['domain'] ?? '');
                $ctx['renewal_date']  = (string) ($svc[0]['nextduedate'] ?? '');
            }
        }

        // Most recent unpaid invoice — powers {{invoice_number}}.
        if (Db::whmcsTableExists('tblinvoices')) {
            $inv = Db::query(
                'SELECT id FROM tblinvoices WHERE userid = ? AND status = ? ORDER BY id DESC LIMIT 1',
                [$clientId, 'Unpaid']
            );
            if ($inv) {
                $ctx['invoice_number'] = (string) $inv[0]['id'];
            }
        }

        return $ctx;
    }

    /** Admin display name, for audit labels. */
    public static function adminName($adminId)
    {
        $adminId = (int) $adminId;
        if ($adminId <= 0) {
            return '';
        }
        try {
            if (!Db::whmcsTableExists('tbladmins')) {
                return '';
            }
            $rows = Db::query('SELECT username FROM tbladmins WHERE id = ? LIMIT 1', [$adminId]);
            return $rows ? (string) $rows[0]['username'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @return array<int,array{id:int,name:string}> admin roles for the permission matrix */
    public static function adminRoles()
    {
        try {
            if (!Db::whmcsTableExists('tbladminroles')) {
                return [];
            }
            $out = [];
            foreach (Db::query('SELECT id, name FROM tbladminroles ORDER BY id ASC') as $row) {
                $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
