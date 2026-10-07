<?php
namespace CloudHost247\Cloudflare\Service;
use CloudHost247\Cloudflare\Core\ValidationException;

class DnsRecordValidator
{
    const TYPES = ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'];
    public static function validate(array $input, $zoneName, $existingType = null)
    {
        $type = strtoupper(trim((string) ($input['type'] ?? $existingType ?? '')));
        if (!in_array($type, self::TYPES, true)) throw new ValidationException('Choose a supported DNS record type.');
        $name = DomainName::recordName($input['name'] ?? '', $zoneName, $type === 'SRV');
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '' || strlen($content) > 4096) throw new ValidationException('Enter DNS record content (up to 4096 characters).');
        switch ($type) {
            case 'A':
                if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new ValidationException('A records require a valid IPv4 address.');
                break;
            case 'AAAA':
                if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new ValidationException('AAAA records require a valid IPv6 address.');
                break;
            case 'CNAME': case 'NS': case 'MX':
                $content = DomainName::normalize($content);
                break;
            case 'TXT':
                if (strlen($content) > 2048) throw new ValidationException('TXT records cannot exceed 2048 characters.');
                break;
            case 'CAA':
                if (!preg_match('/^(?:0|128)\s+(?:issue|issuewild|iodef)\s+.{1,500}$/i', $content)) throw new ValidationException('CAA content must include flags, a tag, and a value (for example: 0 issue letsencrypt.org).');
                break;
            case 'SRV':
                if (!preg_match('/^(\d{1,5})\s+(\d{1,5})\s+([a-z0-9.-]+)$/i', $content, $m)) throw new ValidationException('SRV content must be: weight port target. Set priority in the Priority field.');
                if ((int) $m[1] > 65535 || (int) $m[2] < 1 || (int) $m[2] > 65535) throw new ValidationException('SRV weight must be 0–65535 and port must be 1–65535.');
                $content = (int) $m[1] . ' ' . (int) $m[2] . ' ' . DomainName::normalize($m[3]);
                $zone = DomainName::normalize($zoneName);
                $prefix = $name === $zone ? '' : substr($name, 0, -strlen('.' . $zone));
                $parts = explode('.', $prefix);
                if (count($parts) !== 2 || !preg_match('/^_[a-z0-9-]{1,62}$/', $parts[0]) || !preg_match('/^_[a-z0-9-]{1,62}$/', $parts[1])) throw new ValidationException('SRV record names must be _service._protocol.domain.');
                break;
        }
        $ttl = isset($input['ttl']) && $input['ttl'] !== '' ? (int) $input['ttl'] : 1;
        if ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) throw new ValidationException('TTL must be Automatic or between 60 and 86400 seconds.');
        $proxied = !empty($input['proxied']) && in_array($type, ['A','AAAA','CNAME'], true);
        if ($proxied) $ttl = 1;
        $priority = null;
        if (in_array($type, ['MX','SRV'], true)) {
            $priority = (int) ($input['priority'] ?? 0);
            if ($priority < 0 || $priority > 65535) throw new ValidationException('Priority must be between 0 and 65535.');
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if (strlen($comment) > 512) throw new ValidationException('Record comments cannot exceed 512 characters.');
        return ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl, 'proxied' => $proxied, 'priority' => $priority, 'comment' => $comment];
    }
    public static function toApi(array $record)
    {
        $api = ['type' => $record['type'], 'name' => $record['name'], 'content' => $record['content'], 'ttl' => (int) $record['ttl'], 'proxied' => !empty($record['proxied'])];
        if ($record['comment'] !== '') $api['comment'] = $record['comment'];
        if ($record['priority'] !== null && in_array($record['type'], ['MX','SRV'], true)) $api['priority'] = (int) $record['priority'];
        if ($record['type'] === 'SRV') {
            if (!preg_match('/^(\d+)\s+(\d+)\s+(.+)$/', $record['content'], $m)) throw new ValidationException('SRV record content is invalid.');
            $zone = ''; // Extract the target's zone-independent SRV labels from the owner name.
            $nameParts = explode('.', $record['name']);
            $zoneTail = array_slice($nameParts, 2);
            $api['data'] = [
                'service' => $nameParts[0], 'proto' => $nameParts[1], 'name' => implode('.', $zoneTail),
                'priority' => (int) $record['priority'], 'weight' => (int) $m[1], 'port' => (int) $m[2], 'target' => $m[3],
            ];
        }
        return $api;
    }
    public static function validIpOrCidr($value)
    {
        $value = trim((string) $value); $parts = explode('/', $value, 2); $packed = @inet_pton($parts[0]);
        if ($packed === false) return false;
        if (count($parts) === 1) return true;
        if (!ctype_digit($parts[1])) return false;
        $prefix = (int) $parts[1]; $max = strlen($packed) * 8;
        return $prefix >= 0 && $prefix <= $max;
    }
    public static function firewallRule(array $input)
    {
        $address = trim((string) ($input['address'] ?? ''));
        if (!self::validIpOrCidr($address)) throw new ValidationException('Enter a valid IPv4 or IPv6 address/CIDR range.');
        $action = strtolower(trim((string) ($input['rule_action'] ?? 'block')));
        $map = ['block'=>'block','challenge'=>'managed_challenge','managed_challenge'=>'managed_challenge','js_challenge'=>'js_challenge'];
        if (!isset($map[$action])) throw new ValidationException('Choose Block, Managed Challenge, or JavaScript Challenge.');
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '' || strlen($description) > 255) throw new ValidationException('Enter a rule description (up to 255 characters).');
        $expression = strpos($address, '/') === false ? '(ip.src eq ' . $address . ')' : '(ip.src in { ' . $address . ' })';
        return ['address' => $address, 'action' => $map[$action], 'description' => $description, 'expression' => $expression];
    }
}
