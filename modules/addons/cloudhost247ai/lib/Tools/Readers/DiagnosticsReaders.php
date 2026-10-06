<?php
/**
 * Diagnostics READ tools — thin, audited wrappers over the REAL diagnostics in
 * modules/addons/CloudHost247_tools/includes/tools/. The AI never invents a
 * DNS/SSL/mail answer: it only re-reports what those proven tools returned.
 * If the tools module is absent, the tool fails closed (SERVICE_UNAVAILABLE).
 */

namespace Ch247Ai\Tools\Readers;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\ServiceUnavailableException;
use Ch247Ai\Core\Validator;
use Ch247Ai\Tools\ToolDefinition;

/** Locate the tools module root (name casing varies on disk). */
function ch247ai_tools_root()
{
    $candidates = [
        CH247AI_ROOT . '/modules/addons/CloudHost247_tools',
        CH247AI_ROOT . '/modules/addons/cloudhost247_tools',
    ];
    foreach ($candidates as $path) {
        if (is_dir($path . '/includes/tools')) {
            return $path;
        }
    }
    return null;
}

/**
 * Whitelist: our tool key -> [file, function, arg name].
 * The functions come from the tools module and take a $post array.
 */
function ch247ai_diagnostics_map()
{
    return [
        'dns_lookup' => ['dns_tools.php', 'CloudHost247_tool_dns_lookup', 'domain'],
        'dns_health' => ['dns_tools.php', 'CloudHost247_tool_dns_health', 'domain'],
        'dns_propagation' => ['dns_tools.php', 'CloudHost247_tool_dns_propagation', 'domain'],
        'domain_dns_validation' => ['dns_tools.php', 'CloudHost247_tool_domain_dns_validation', 'domain'],
        'ssl_checker' => ['security_tools.php', 'CloudHost247_tool_ssl_checker', 'domain'],
        'spf_checker' => ['dns_tools.php', 'CloudHost247_tool_spf_checker', 'domain'],
        'dmarc_checker' => ['dns_tools.php', 'CloudHost247_tool_dmarc_lookup', 'domain'],
        'dkim_checker' => ['dns_tools.php', 'CloudHost247_tool_dkim_checker', 'domain'],
        'mx_checker' => ['dns_tools.php', 'CloudHost247_tool_mx_lookup', 'domain'],
        'ns_checker' => ['dns_tools.php', 'CloudHost247_tool_ns_lookup', 'domain'],
        'cname_lookup' => ['dns_tools.php', 'CloudHost247_tool_cname_lookup', 'domain'],
        'reverse_ip' => ['dns_tools.php', 'CloudHost247_tool_reverse_ip_lookup', 'domain'],
        'domain_whois' => ['dns_tools.php', 'CloudHost247_tool_domain_whois', 'domain'],
        'website_status' => ['network_tools.php', 'CloudHost247_tool_website_status', 'domain'],
        'port_checker' => ['network_tools.php', 'CloudHost247_tool_port_checker', 'host'],
        'asn_lookup' => ['network_tools.php', 'CloudHost247_tool_asn_lookup', 'domain'],
        'ip_blacklist' => ['ip_tools.php', 'CloudHost247_tool_ip_blacklist', 'domain'],
    ];
}

/** Load a tool file once and run its function. */
function ch247ai_run_diagnostic($key, array $post)
{
    $map = ch247ai_diagnostics_map();
    if (!isset($map[$key])) {
        throw new ServiceUnavailableException('SERVICE_UNAVAILABLE: diagnostic "' . $key . '" is not whitelisted.');
    }
    list($file, $function, $arg) = $map[$key];
    $root = ch247ai_tools_root();
    if ($root === null) {
        throw new ServiceUnavailableException('SERVICE_UNAVAILABLE: the CloudHost247 tools module (diagnostics) is not installed, so live ' . $key . ' checks cannot run. The AI will not guess a result.');
    }
    if (!is_file($root . '/includes/tools/' . $file)) {
        throw new ServiceUnavailableException('SERVICE_UNAVAILABLE: diagnostic file ' . $file . ' is missing from the tools module.');
    }
    if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
        define('CLOUDHOST247_TOOLS', 1);
    }
    require_once $root . '/includes/tools/' . $file;
    if (!function_exists($function)) {
        throw new ServiceUnavailableException('SERVICE_UNAVAILABLE: diagnostic function ' . $function . ' did not load.');
    }
    $result = call_user_func($function, $post);
    if (!is_array($result)) {
        throw new ServiceUnavailableException('SERVICE_UNAVAILABLE: diagnostic "' . $key . '" returned no structured result.');
    }
    if (isset($result['error'])) {
        return ['_error' => 'Diagnostic ' . $key . ' failed: ' . $result['error']];
    }
    return $result;
}

/** Whitelist of exposed diagnostics with model-facing metadata. */
function ch247ai_diagnostics_spec()
{
    return [
        'dns_lookup' => 'Live DNS lookup for a domain (A, AAAA, MX, NS, TXT) via the platform DNS tools.',
        'dns_health' => 'DNS health assessment for a domain (record presence, SPF/DKIM/DMARC completeness) via the platform tools.',
        'dns_propagation' => 'DNS propagation of a domain across public resolvers.',
        'domain_dns_validation' => 'Full DNS record validation (A/AAAA/MX/TXT/NS/SOA/CNAME) for a domain.',
        'ssl_checker' => 'Live TLS/SSL certificate check: issuer, validity window, days remaining.',
        'spf_checker' => 'Parse and validate the SPF record of a domain.',
        'dmarc_checker' => 'Parse and validate the DMARC record of a domain.',
        'dkim_checker' => 'Check DKIM records for a domain (default selectors).',
        'mx_checker' => 'Mail (MX) record check for a domain.',
        'ns_checker' => 'Nameserver check for a domain.',
        'cname_lookup' => 'CNAME record lookup for a hostname.',
        'reverse_ip' => 'Reverse DNS / IP lookup for a domain or IP.',
        'domain_whois' => 'WHOIS record for a domain (registrar, dates, nameservers).',
        'website_status' => 'HTTP status and reachability of a website.',
        'port_checker' => 'TCP port reachability check for a host and port.',
        'asn_lookup' => 'ASN ownership lookup for an IP or hostname.',
        'ip_blacklist' => 'DNSBL blacklist check for an IP address.',
    ];
}

/** @return ToolDefinition[] */
function ch247ai_diagnostics_readers()
{
    $out = [];
    foreach (ch247ai_diagnostics_spec() as $key => $description) {
        $argName = ch247ai_diagnostics_map()[$key][2];
        $params = [
            $argName === 'host' ? 'host' : 'domain' => ['type' => 'string', 'description' => 'Public domain name, hostname or IP to check'],
        ];
        if ($key === 'port_checker') {
            $params['port'] = ['type' => 'integer', 'description' => 'TCP port (1-65535)'];
        }
        $out[] = new ToolDefinition(
            'read_diag_' . $key,
            'read.diagnostics',
            'READ',
            $description . ' Returns the structured result of the platform diagnostic — report it verbatim with its values.',
            $params,
            function (array $args, array $ctx) use ($key, $argName) {
                $target = strtolower(trim((string) (isset($args[$argName]) ? $args[$argName] : '')));
                if (strpos($target, 'http://') === 0 || strpos($target, 'https://') === 0) {
                    $parts = parse_url($target);
                    $target = isset($parts['host']) ? $parts['host'] : $target;
                }
                $target = preg_replace('/[^a-z0-9.:-]/', '', $target);
                if ($target === '' || strlen($target) > 253) {
                    return ['_error' => 'Invalid domain/host parameter.'];
                }
                if (filter_var($target, FILTER_VALIDATE_IP) === false && !Validator::isDomain($target) && !preg_match('/^[0-9.]+$/', $target)) {
                    return ['_error' => 'Invalid domain/host parameter.'];
                }
                $post = [$argName => $target];
                if ($key === 'port_checker') {
                    $rawPort = isset($args['port']) ? $args['port'] : 0;
                    if (!Validator::isInt($rawPort) || (int) $rawPort < 1 || (int) $rawPort > 65535) {
                        return ['_error' => 'Port must be between 1 and 65535.'];
                    }
                    $post['port'] = (int) $rawPort;
                }
                $result = ch247ai_run_diagnostic($key, $post);
                if (isset($result['_error'])) {
                    return $result;
                }
                Audit::agent('diagnostics', 'ai.diag.' . $key, ['target' => $target]);
                return [
                    'diagnostic' => $key,
                    'target' => $target,
                    'result' => $result,
                    '_citations' => ['CloudHost247_tools diagnostic ' . $key . '(' . $argName . '=' . $target . ')'],
                ];
            },
            ['entity' => 'tools:' . $key]
        );
    }
    return $out;
}
