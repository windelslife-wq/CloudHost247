# Tools Center API Documentation

## Base URL

```
https://tools-api.yourdomain.com/api.php
```

## Authentication

All tool requests must use **POST** and authenticate with one of these methods:

### Method 1: X-API-Token Header (recommended)
```
X-API-Token: your-configured-api-token
```

### Method 2: Authorization Bearer Header
```
Authorization: Bearer your-configured-api-token
```

### Legacy compatibility: POST body token
A token in a JSON or form-encoded **POST body** is still accepted for older clients. Prefer a header; never put a token in a URL, query string, or cookie. Query-string and cookie authentication are rejected.

```http
POST /api.php HTTP/1.1
Content-Type: application/json

{"api_token":"your-configured-api-token","category":"dns","action":"mxLookup","domain":"example.com"}
```

The API ships with no default credentials. Set `TOOLS_API_TOKEN` before exposing it. Optional signed HMAC tokens require `TOOLS_API_SECRET`; tokens produced by `APIAuth::generateToken()` expire after five minutes and are single-use. Generate a fresh one for each request.

## Request Format

```http
POST /api.php HTTP/1.1
Host: tools-api.yourdomain.com
Content-Type: application/json
X-API-Token: your-token

{
  "category": "dns",
  "action": "mxLookup",
  "domain": "example.com"
}
```

## Response Format

### Success Response
```json
{
  "success": true,
  "data": {
    "domain": "example.com",
    "mx_records": [...]
  }
}
```

Cache hits may also include `"cached": true`. The WHMCS client-area proxy adds a `response_time_ms` field when it returns results to the browser.

### Error Response
```json
{
  "success": false,
  "error": "Domain is required",
  "code": "MISSING_DOMAIN"
}
```

## Rate Limiting

| Limit | Value | Window |
|-------|-------|--------|
| Per Minute | 60 | 60 seconds |
| Per Hour | 500 | 1 hour |
| Per Day | 5,000 | 24 hours |

The API returns HTTP `429` when the configured per-minute, per-hour, or per-day limit is exceeded. It does not currently emit standard rate-limit or `Retry-After` headers. Requests authenticated with a signed HMAC token are bucketed by its client ID; the shared API token is bucketed by the API server's remote client IP.

## CORS

Browser origins are allowed only when they exactly match an entry in `external-api/config.php` under `allowed_origins`. Unlisted origins are rejected, and CORS is not an authentication mechanism. The WHMCS integration is server-to-server and does not require a CORS origin.

## Categories & Tools

### Search Tools (`category: search`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `domainSearch` | `domain` (string, required) | Preliminary registry WHOIS lookup; only explicit, complete registry outcomes are classified, and inconclusive cases remain `UNKNOWN` |

### DNS Tools (`category: dns`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `spfChecker` | `domain` (string) | Validate SPF records |
| `dnsValidation` | `domain` (string) | Complete DNS validation |
| `reverseIpLookup` | `ip` (string) | Reverse DNS lookup |
| `dnsLookup` | `domain`, `type` (A/AAAA/MX/NS/SOA/TXT/CNAME/SRV/CAA/ALL) | DNS record lookup |
| `cnameLookup` | `domain` (string) | CNAME chain trace |
| `nsLookup` | `domain` (string) | Nameserver lookup |
| `mxLookup` | `domain` (string) | Mail server lookup |
| `dnsPropagation` | `domain`, `type` (A/AAAA/MX/NS/TXT) | Compare answers from a fixed public-resolver list |
| `dmarcValidation` | `domain` (string) | DMARC record validation |
| `dnsHealth` | `domain` (string) | Overall DNS health score |
| `dmarcGenerator` | `domain`, `policy` (none/quarantine/reject), `rua`, `pct` | Generate DMARC record |
| `dnskeyLookup` | `domain` (string) | DNSSEC key lookup |
| `dsLookup` | `domain` (string) | Delegation signer lookup |
| `dkimChecker` | `domain`, `selector` (optional) | DKIM signature check |

### IP Tools (`category: ip`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `ping` | `host`, `count` (1-10), `timeout` (1-30 seconds) | ICMP ping to a public target; result includes the pinned `resolved_ip` |
| `whatIsMyIP` | (none) | Get client IP info; geolocation is skipped for non-public client IPs |
| `traceroute` | `host`, `max_hops` (1-50), `timeout` (1-30 seconds) | Network path trace to a public target; result includes the pinned `resolved_ip` |
| `ipLocation` | `ip` (optional, defaults to client IP) | GeoIP lookup for public IPs only |
| `emailHeaderAnalyzer` | `headers` (string) | Parse email headers |
| `ipBlacklist` | `ip` (string) | Check IP reputation |
| `ipToDecimal` | `ip` (string) | IP to decimal conversion |
| `resolveIPtoHostname` | `ip` (string) | PTR record lookup |
| `ipWhois` | `ip` (string) | IPv4 WHOIS lookup |
| `ipv6Whois` | `ip` (string) | IPv6 WHOIS lookup |
| `ipv4ToIPv6` | `ip` (string) | IPv4 to IPv6 conversion |
| `localIPv6Generator` | `count` (1-50) | Generate local IPv6 |
| `ipv6CIDRtoRange` | `cidr` (string) | Expand IPv6 CIDR |
| `ipv6RangeToCIDR` | `start`, `end` (IPv6) | Compress range to CIDR |
| `ipv6Compatibility` | `host` (string) | Check IPv6 readiness |
| `ipv6Compression` | `ip` (string) | Compress IPv6 |
| `ipv6Expand` | `ip` (string) | Expand IPv6 |
| `subnetCalculator` | `ip`, `cidr` or `mask` | Subnet calculation |
| `ipv6ToIPv4` | `ip` (string) | Extract IPv4 from IPv6 |
| `ispChecker` | `ip` (optional) | ISP identification |
| `websiteToIP` | `url` (string) | Domain to IP resolution |

Ping and traceroute hostname inputs are resolved by the central outbound guard. They fail if any resolved address is non-public, and the command receives a validated numeric IP rather than resolving the hostname a second time. The result includes both the submitted `host` and the pinned `resolved_ip`. Counts, timeouts, and hop counts are clamped to the ranges above. IP WHOIS and ASN WHOIS use the guarded public socket helper on port 43; ASN-by-IP, PTR/reverse-DNS, and DNSBL operations reject non-public IPs before disclosing them to a resolver or registry. GeoIP continues to use the existing `ip-api.com` HTTP endpoint, now through the guarded fetch helper with redirects disabled; provider availability and returned data are not independently verified, and non-public addresses are not sent to the provider. GeoIP fields the provider does not return remain null. The ISP checker uses the provider's hosting/proxy flags; VPN status is unknown because this provider does not supply a VPN classification. Caller-supplied DNS names must be fully qualified and outside reserved/local-use namespaces (including `.local`, `.internal`, `.test`, `.example`, `.invalid`, `.onion`, `.alt`, `.home.arpa`, and common private suffixes). CNAME, NS, and MX targets that point to such names are reported but are not recursively resolved. DNS propagation accepts only A/AAAA/MX/NS/TXT and uses fixed public resolver IPs with a bounded `dig` timeout. These checks do not prove a domain is registered or authoritative. The shared HTTP guard still has the separately tracked TLS-verification/plain-HTTP limitation (T-8).

### Developer Tools (`category: developer`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `httpHeadersCheck` | `url` (string) | HTTP security headers |
| `websiteOSChecker` | `url` (string) | Server OS detection |
| `hashGenerator` | `text` (string) | MD5/SHA/Base64 hashing |
| `multiUrlOpener` | `urls` (string, newline-separated) | Validate multiple URLs |
| `smtpTest` | `host`, `port` (optional) | SMTP connectivity test |
| `htaccessRedirectGenerator` | `type`, `from`, `to`, `www`, `https` | Generate htaccess rules |
| `urlRewriteGenerator` | `source`, `target`, `type` (apache/nginx/iis) | Rewrite rules |
| `brokenLinksChecker` | `url` (string) | Find broken links |
| `openGraphGenerator` | `title`, `url`, `description`, `image` | OG meta tags |
| `raidCalculator` | `type`, `drives`, `drive_size`, `drive_unit` | RAID storage calc |
| `binaryTextConverter` | `text`, `mode` (auto/text_to_binary/binary_to_text) | Binary converter |
| `jsonTool` | `json`, `action` (beautify/minify/validate) | JSON formatter |

### Designer Tools (`category: designer`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `rgbToPantone` | `r`, `g`, `b` (0-255) | RGB to Pantone match |
| `hexToPantone` | `hex` (string, #RRGGBB) | HEX to Pantone match |
| `cmykToPantone` | `c`, `m`, `y`, `k` (0-100) | CMYK to Pantone match |
| `hsvToPantone` | `h` (0-360), `s`, `v` (0-100) | HSV to Pantone match |

### Webmaster Tools (`category: webmaster`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `websiteLinkAnalyzer` | `url` (string) | Link analysis |
| `userAgentChecker` | `ua` (string, optional) | Parse browser info |
| `pageRankChecker` | `url` (string) | Legacy route; returns `not_configured` with null scores and makes no provider or PageRank claim |
| `punycodeConverter` | `domain`, `direction` (auto/to_punycode/to_unicode) | IDN conversion |
| `serpSimulator` | `title`, `description`, `url` | SERP preview |
| `robotsGenerator` | `disallow`, `allow`, `sitemap`, `crawl_delay` | robots.txt generator |

### Network Tools (`category: network`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `portChecker` | `host`, `ports` (optional) | Port scan |
| `macLookup` | `mac` (string) | MAC vendor lookup |
| `macGenerator` | `count`, `format`, `vendor` | Generate MAC addresses |
| `asnWhois` | `asn` or `ip` | ASN lookup |

### Security Tools (`category: security`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `sslChecker` | `host`, `port` (default 443, 1-65535) | Inspect certificate metadata on a public host |
| `passwordEncrypt` | `password`, `algorithm` (bcrypt/argon2/sha256) | Password hashing |
| `passwordGenerator` | `length`, `count`, `uppercase`, `lowercase`, `numbers`, `symbols` | Generate passwords |
| `passwordStrength` | `password` (string) | Strength analysis |

`sslChecker` rejects non-public targets and connects to a pinned numeric address while preserving the hostname for TLS SNI. It deliberately retrieves metadata even when the certificate is expired or self-signed, so it does not validate the trust chain or hostname. It reports no SSL Labs grade or overall security score (`grade` is `Not assessed`); treat the result as certificate metadata, not a complete TLS audit.

### Productivity Tools (`category: productivity`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `loremIpsum` | `type` (paragraphs/sentences/words/lists), `count`, `html` | Placeholder text |
| `timeCard` | `entries` (JSON array), `hourly_rate` | Work hours calculation |
| `binChecker` | `bin` (string, 6+ digits) | Bank ID lookup |
| `creditCardValidator` | `number` (string) | Luhn validation |
| `reverseImageSearch` | `url` (image URL) | Image search links |
| `usernameChecker` | `username` (string) | Validates a common format and builds profile links; does not check availability |
| `notepad` | `action` (create/read/update), `content`, `note_id` | Online notepad |
| `smallText` | `text`, `style` (all/superscript/subscript/smallcaps) | Small text gen |
| `wordCounter` | `text` (string) | Word/char analysis |
| `domainSearch` | `domain` (string) | Legacy API alias for `search.domainSearch`; delegates to the same registry-aware implementation |
| `rot13` | `text` (string) | ROT13 encoder |
| `morseCode` | `text`, `direction` (auto/to_morse/to_text) | Morse translator |
| `bimiChecker` | `domain`, `action` (check/generate) | BIMI record tool |
| `imageToText` | `url` (image URL) | OCR (external) |

### Gaming Tools (`category: gaming`)

| Action | Parameters | Description |
|--------|-----------|-------------|
| `minecraftColorCodes` | `preview` (optional) | MC color reference |
| `minecraftFormatCodes` | `format`, `text` | MC formatting codes |

## Example Requests

### DNS Lookup
```bash
curl -X POST https://tools-api.yourdomain.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-API-Token: your-token" \
  -d '{
    "category": "dns",
    "action": "mxLookup",
    "domain": "google.com"
  }'
```

### Ping Test
```bash
curl -X POST https://tools-api.yourdomain.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-API-Token: your-token" \
  -d '{
    "category": "ip",
    "action": "ping",
    "host": "8.8.8.8",
    "count": 4
  }'
```

### SSL Checker
```bash
curl -X POST https://tools-api.yourdomain.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-API-Token: your-token" \
  -d '{
    "category": "security",
    "action": "sslChecker",
    "host": "google.com"
  }'
```

### Password Generator
```bash
curl -X POST https://tools-api.yourdomain.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-API-Token: your-token" \
  -d '{
    "category": "security",
    "action": "passwordGenerator",
    "length": 20,
    "count": 5
  }'
```

### Credit Card Validator
```bash
curl -X POST https://tools-api.yourdomain.com/api.php \
  -H "Content-Type: application/json" \
  -H "X-API-Token: your-token" \
  -d '{
    "category": "productivity",
    "action": "creditCardValidator",
    "number": "4242424242424242"
  }'
```

## Error Codes

| Code | Description |
|------|-------------|
| `MISSING_CATEGORY` | Tool category not provided |
| `MISSING_ACTION` | Tool action not provided |
| `UNAUTHORIZED` | Invalid or missing API token |
| `RATE_LIMITED` | Too many requests |
| `CATEGORY_NOT_FOUND` | Invalid tool category |
| `ACTION_NOT_FOUND` | Invalid tool action |
| `EXECUTION_ERROR` | Tool execution failed |

## HTTP Status Codes

| Status | Meaning |
|--------|---------|
| 200 | Success |
| 400 | Bad Request (invalid parameters) |
| 401 | Unauthorized (invalid token) |
| 404 | Not Found (unknown category/action) |
| 429 | Rate Limit Exceeded |
| 500 | Server Error |

## Caching

Results are cached for 300 seconds (5 minutes) by default. Cached responses include `"cached": true` in the response.

To bypass cache, add `nocache=1` to request parameters.

## PHP Integration Example

```php
<?php
class ToolsCenterAPI {
    private $endpoint;
    private $token;
    
    public function __construct($endpoint, $token) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->token = $token;
    }
    
    public function call($category, $action, $params = []) {
        $data = array_merge($params, [
            'category' => $category,
            'action' => $action,
        ]);
        
        $ch = curl_init($this->endpoint . '/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Token: ' . $this->token,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('API request failed with HTTP ' . $httpCode);
        }
        
        return json_decode($response, true);
    }
}

// Usage
$api = new ToolsCenterAPI('https://tools-api.example.com', 'your-token');
$result = $api->call('dns', 'mxLookup', ['domain' => 'example.com']);
print_r($result);
```

## Security Notes

1. **Always use HTTPS** for production environments
2. **Rotate API tokens** regularly
3. **Monitor rate limit logs** for abuse detection
4. **Never expose API tokens** in client-side JavaScript
5. **Validate all inputs** before sending to API
6. **Set appropriate rate limits** based on your user base

## Changelog

### v1.0.0
- Initial release with 60+ tools
- 10 tool categories
- Token-based authentication
- Rate limiting and caching
- WHMCS 8.x compatibility