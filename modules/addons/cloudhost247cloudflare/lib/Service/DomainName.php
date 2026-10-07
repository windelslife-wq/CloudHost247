<?php
namespace CloudHost247\Cloudflare\Service;
use CloudHost247\Cloudflare\Core\ValidationException;
class DomainName
{
    public static function normalize($domain)
    {
        $domain = strtolower(trim((string) $domain));
        if ($domain === '' || strlen($domain) > 253 || strpos($domain, '/') !== false || strpos($domain, '@') !== false || strpos($domain, '*') !== false) throw new ValidationException('Enter a valid domain name for this Cloudflare service.');
        $domain = rtrim($domain, '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $domain)) {
            $ascii = idn_to_ascii($domain, defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0);
            if ($ascii === false) throw new ValidationException('The internationalized domain name is invalid.');
            $domain = strtolower($ascii);
        }
        if (strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) throw new ValidationException('Enter a valid fully qualified domain name.');
        return $domain;
    }
    public static function recordName($name, $zone, $allowServiceLabels = false)
    {
        $name = strtolower(trim((string) $name)); $zone = self::normalize($zone);
        if ($name === '' || $name === '@') return $zone;
        $name = rtrim($name, '.');
        if ($name[0] === '*') {
            if ($name !== '*.' . $zone && substr($name, -strlen('.' . $zone)) !== '.' . $zone) throw new ValidationException('DNS record name must be inside this Cloudflare zone.');
            return $name;
        }
        if (strpos($name, '.') === false) $name .= '.' . $zone;
        $label = $allowServiceLabels ? '(?:_[a-z0-9-]{1,61}|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)' : '(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)';
        if (strlen($name) > 253 || !preg_match('/^(?=.{1,253}$)(?:\\*|' . $label . ')(?:\\.' . $label . ')*$/', $name)) throw new ValidationException('DNS record name is invalid.');
        if ($name !== $zone && substr($name, -strlen('.' . $zone)) !== '.' . $zone) throw new ValidationException('DNS record name must be inside this Cloudflare zone.');
        return $name;
    }
    public static function isWithinZone($host, $zone)
    {
        $host = strtolower(rtrim((string) $host, '.')); $zone = strtolower(rtrim((string) $zone, '.'));
        return $host === $zone || substr($host, -strlen('.' . $zone)) === '.' . $zone;
    }
}
