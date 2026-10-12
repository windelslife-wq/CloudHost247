# DNSChecker reference-to-route crosswalk — 2026-10-12

This is a catalog crosswalk, not a claim of functional parity. The source is the current public [DNSChecker “All Online Tools” directory](https://dnschecker.org/all-tools.php), fetched on 2026-10-12. The listed directory contains **89 distinct tool entries**: DNS 14, IP 22, Dev 14, Designer 4, Webmasters 6, Network 4, Cyber Security 4, Productivity 20, Gaming 1. Reference titles/routes below reflect the directory listing; individual reference-page buttons, provider output, or export controls were not exercised.

Every reference concept maps to a unique existing `CloudHost247_tools` route. The current CloudHost catalog has **91 routes**: these 89 concepts plus `domain-whois` and `website-status`. The route-level inventory in [`TOOLS_WHMCS_AUDIT_2026-10-12.md`](TOOLS_WHMCS_AUDIT_2026-10-12.md) records the more detailed caveats. **Local/unit-tested** means representative offline tests, not full boundary, browser, accessibility, responsive, or production tests. **Partial/server** means the server path exists but live DNS/provider/capability was not validated. UI/manual/unavailable entries do not claim provider-backed results.

| # | Reference category | DNSChecker directory title and route | CloudHost247 route | Current disposition |
|---:|---|---|---|---|
| 1 | DNS Tools | [DNS Checker — Check DNS Propagation](https://dnschecker.org/) | `/tools/dns-propagation-checker` | Partial — multi-resolver implementation; live resolver reachability unverified. |
| 2 | DNS Tools | [Domain DNS Validation — Validate Your DNS Records](https://dnschecker.org/dns-record-validation.php) | `/tools/dns-validation` | Partial — server handler present; live DNS and validation environment unverified. |
| 3 | DNS Tools | [Reverse IP Lookup — Resolve IP to Hostname](https://dnschecker.org/reverse-dns.php) | `/tools/reverse-ip` | Partial — public-only PTR behavior is guarded; live reverse-DNS results unverified. |
| 4 | DNS Tools | [DNS Lookup — See All DNS Records of a Domain](https://dnschecker.org/all-dns-records-of-domain.php) | `/tools/dns-lookup` | Partial — server handler present; live resolver behavior unverified. |
| 5 | DNS Tools | [CNAME Lookup — CNAME Records of a Domain](https://dnschecker.org/cname-lookup.php) | `/tools/cname-lookup` | Partial — handler present; live DNS and CNAME chains unverified. |
| 6 | DNS Tools | [NS Lookup — See NS Records of a Domain](https://dnschecker.org/ns-lookup.php) | `/tools/ns-lookup` | Partial — handler present; live DNS unverified. |
| 7 | DNS Tools | [MX Lookup — See Mail Records of a Domain](https://dnschecker.org/mx-lookup.php) | `/tools/mx-lookup` | Partial — handler present; live DNS unverified. |
| 8 | DNS Tools | [SPF Record Checker](https://dnschecker.org/spf-record-validation.php) | `/tools/spf-checker` | Partial — handler present; live DNS unverified. |
| 9 | DNS Tools | [DMARC Checker — DMARC Lookup & Validation](https://dnschecker.org/dmarc-record-validation.php) | `/tools/dmarc-checker` | Partial — handler present; live DNS unverified. |
| 10 | DNS Tools | [Domain DNS Health Checker](https://dnschecker.org/domain-health-checker.php) | `/tools/dns-health` | Partial — handler present; scoring and live DNS behavior unverified. |
| 11 | DNS Tools | [DMARC Record Generator](https://dnschecker.org/dmarc-record-generator.php) | `/tools/dmarc-generator` | Local deterministic generator; representative module test passed. |
| 12 | DNS Tools | [DNSKEY Lookup](https://dnschecker.org/dnskey-lookup.php) | `/tools/dnskey-lookup` | Partial — DNSSEC resolver behavior unverified live. |
| 13 | DNS Tools | [DS Lookup](https://dnschecker.org/ds-lookup.php) | `/tools/ds-lookup` | Partial — DNSSEC resolver behavior unverified live. |
| 14 | DNS Tools | [DKIM Checker](https://dnschecker.org/dkim-record-checker.php) | `/tools/dkim-checker` | Partial — selector coverage and live DNS unverified. |
| 15 | IP Tools | [Ping IPv4 Address](https://dnschecker.org/ping-ipv4.php) | `/tools/ping-ipv4` | Partial — mocked parsing/security tests pass; live ICMP capability unverified. |
| 16 | IP Tools | [Ping IPv6 Address](https://dnschecker.org/ping-ipv6.php) | `/tools/ping-ipv6` | Partial — mocked parsing/security tests pass; live ICMP capability unverified. |
| 17 | IP Tools | [What is My IP Address](https://dnschecker.org/whats-my-ip-address.php) | `/tools/my-ip` | Partial — trusted-proxy/client-IP deployment configuration unverified. |
| 18 | IP Tools | [Traceroute](https://dnschecker.org/online-traceroute.php) | `/tools/traceroute` | Partial — parser/allowlist tests pass; live route and host capability unverified. |
| 19 | IP Tools | [IP Location Lookup](https://dnschecker.org/ip-location.php) | `/tools/ip-location` | Partial — provider/network data not live-verified. |
| 20 | IP Tools | [Trace Email (Header Analyzer)](https://dnschecker.org/email-header-analyzer.php) | `/tools/email-header-analyzer` | Local parser; representative module test passed. Does not authenticate sender identity. |
| 21 | IP Tools | [IP Blacklist & Email Blacklist Check](https://dnschecker.org/ip-blacklist-checker.php) | `/tools/blacklist-checker` | Partial — IPv4-only selected DNSBL checks; no email reputation, IPv6, or live list verification. |
| 22 | IP Tools | [IP to Decimal](https://dnschecker.org/ip-to-decimal.php) | `/tools/ip-to-decimal` | Local converter; representative module test passed. |
| 23 | IP Tools | [IP to Hostname Lookup](https://dnschecker.org/ip-to-hostname.php) | `/tools/ip-to-hostname` | Partial — reverse-DNS environment not live-verified. |
| 24 | IP Tools | [IP WHOIS Lookup](https://dnschecker.org/ip-whois-lookup.php) | `/tools/ip-whois` | Partial — RDAP/WHOIS availability not live-verified. |
| 25 | IP Tools | [IPv6 WHOIS Lookup](https://dnschecker.org/ipv6-whois-lookup.php) | `/tools/ipv6-whois` | Partial — IPv6 registry path not live-verified. |
| 26 | IP Tools | [IPv4 to IPv6](https://dnschecker.org/ipv4-to-ipv6.php) | `/tools/ipv4-to-ipv6` | Local converter; representative module test passed. |
| 27 | IP Tools | [Local IPv6 Address Generator](https://dnschecker.org/ipv6-address-generator.php) | `/tools/ipv6-address-generator` | Local generator; representative module test passed. |
| 28 | IP Tools | [IPV6 CIDR to Range](https://dnschecker.org/ipv6-cidr-to-range.php) | `/tools/ipv6-cidr-range` | Local range calculation; representative module test and IPv6 core tests passed. |
| 29 | IP Tools | [IPV6 Range to CIDR](https://dnschecker.org/ipv6-range-to-cidr.php) | `/tools/ipv6-range-cidr` | Local range calculation; representative module test and IPv6 core tests passed. |
| 30 | IP Tools | [IPv6 Compression Tool](https://dnschecker.org/ipv6-compress.php) | `/tools/ipv6-compress` | Local converter; representative module test passed. |
| 31 | IP Tools | [IPv6 Expand](https://dnschecker.org/ipv6-expand.php) | `/tools/ipv6-expand` | Local converter; representative module test passed. |
| 32 | IP Tools | [IP Subnet Calculator](https://dnschecker.org/netmask-cidr.php) | `/tools/ip-subnet-calculator` | Local calculator; representative module test passed. |
| 33 | IP Tools | [IPv6 to IPv4](https://dnschecker.org/ipv6-to-ipv4.php) | `/tools/ipv6-to-ipv4` | Local conversion helper; representative module test passed; transition coverage is limited. |
| 34 | IP Tools | [IPv6 Compatibility Checker](https://dnschecker.org/ipv6-compatibility-checker.php) | `/tools/ipv6-compatibility` | Partial — mocked family/transport tests pass; live deployment network unverified. |
| 35 | IP Tools | [What is My ISP](https://dnschecker.org/what-is-my-isp.php) | `/tools/my-isp` | Partial — external lookup behavior unverified; VPN status is reported as unknown. |
| 36 | IP Tools | [Domain to IP Lookup](https://dnschecker.org/domain-ip-lookup.php) | `/tools/domain-to-ip` | Partial — live DNS behavior unverified. |
| 37 | Dev Tools | [HTTP Headers Check](https://dnschecker.org/server-headers-check.php) | `/tools/http-headers` | Partial — SSRF/redirect controls tested; live fetch behavior unverified. |
| 38 | Dev Tools | [Check Website Operating System](https://dnschecker.org/website-server-software.php) | `/tools/website-os` | Partial — heuristic fingerprint, not authoritative OS identification. |
| 39 | Dev Tools | [MD5 & Base64 Generator](https://dnschecker.org/md5-generator.php) | `/tools/md5-base64` | Local utility; representative module test passed. |
| 40 | Dev Tools | [Multi URL Opener](https://dnschecker.org/url-opener.php) | `/tools/multi-url-opener` | Local URL construction is tested; browser popup behavior unverified. |
| 41 | Dev Tools | [SMTP Test](https://dnschecker.org/smtp-test-tool.php) | `/tools/smtp-test` | Partial — bounded probe controls tested; live SMTP reachability unverified. |
| 42 | Dev Tools | [HTACCESS Redirect Generator](https://dnschecker.org/htaccess-redirect-generator.php) | `/tools/htaccess-redirect-generator` | Local generator; representative module test passed. |
| 43 | Dev Tools | [URL Rewrite Generator](https://dnschecker.org/url-rewrite-generator.php) | `/tools/url-rewrite-generator` | Local generator; representative module test passed. |
| 44 | Dev Tools | [Broken Link Checker](https://dnschecker.org/website-broken-link-checker.php) | `/tools/broken-link-checker` | Partial — safe-fetch/redirect controls tested; live crawl behavior unverified. |
| 45 | Dev Tools | [Open Graph Checker](https://dnschecker.org/open-graph-preview-generate-metatags.php) | `/tools/open-graph-checker` | Partial — live page-fetch and metadata behavior unverified; reference subtitle says “Social Media Tags Generator.” |
| 46 | Dev Tools | [RAID Calculator](https://dnschecker.org/raid-calculator.php) | `/tools/raid-calculator` | Local calculator; representative calculation test passed. |
| 47 | Dev Tools | [Binary Translator](https://dnschecker.org/binary-to-text-translator.php) | `/tools/binary-translator` | Local conversion; representative module test passed. |
| 48 | Dev Tools | [Text to Binary Translator](https://dnschecker.org/text-to-binary-translator.php) | `/tools/text-to-binary` | Local conversion; representative module test passed. |
| 49 | Dev Tools | [JSON Viewer, Beautifier & Minifier](https://dnschecker.org/online-json-viewer-beautifier.php) | `/tools/json-tool` | Local JSON utility; representative module test passed. |
| 50 | Dev Tools | [Email Verifier](https://dnschecker.org/email-verifier) | `/tools/email-verifier` | Partial — syntax/DNS signals do not prove mailbox deliverability; live checks unverified. |
| 51 | Designer Tools | [RGB to Colortone](https://dnschecker.org/rgb-to-colortone) | `/tools/rgb-colortone` | Local conversion; representative module test passed. |
| 52 | Designer Tools | [HEX to Colortone](https://dnschecker.org/hex-to-colortone) | `/tools/hex-colortone` | Local conversion; representative module test passed. |
| 53 | Designer Tools | [CMYK to Colortone](https://dnschecker.org/cmyk-to-colortone) | `/tools/cmyk-colortone` | Local conversion; representative module test passed. |
| 54 | Designer Tools | [HSV to Colortone](https://dnschecker.org/hsv-to-colortone) | `/tools/hsv-colortone` | Local conversion; representative module test passed. |
| 55 | Webmasters Tools | [Website Link Analyzer](https://dnschecker.org/website-link-analyzer.php) | `/tools/link-analyzer` | Partial — live fetch/crawl behavior unverified. |
| 56 | Webmasters Tools | [What is My User Agent?](https://dnschecker.org/user-agent-info.php) | `/tools/user-agent` | Local browser-value display; representative module test passed. |
| 57 | Webmasters Tools | [Check Google Page Rank](https://dnschecker.org/pagerank.php) | `/tools/page-rank` | Unsupported/retired metric; no score is returned. |
| 58 | Webmasters Tools | [Punycode Converter](https://dnschecker.org/idn-punycode-converter.php) | `/tools/punycode` | Local converter; representative module test passed. |
| 59 | Webmasters Tools | [Google SERP Simulator](https://dnschecker.org/google-serp-preview-optimization-tool.php) | `/tools/serp-simulator` | Local preview only; no live Google results are claimed; reference subtitle is inconsistent. |
| 60 | Webmasters Tools | [Robots.txt Generator](https://dnschecker.org/robots-txt-generator.php) | `/tools/robots-generator` | Local generator; representative module test passed. |
| 61 | Network Tools | [Port Checker](https://dnschecker.org/port-scanner.php) | `/tools/port-checker` | Partial — diagnostic port allowlist/SSRF controls tested; live reachability unverified. |
| 62 | Network Tools | [MAC Address Lookup](https://dnschecker.org/mac-lookup.php) | `/tools/mac-lookup` | Partial — small bundled OUI sample; no vendor guess for unknown/local MACs. |
| 63 | Network Tools | [MAC Address Generator](https://dnschecker.org/mac-address-generator.php) | `/tools/mac-generator` | Local generator; returns locally administered unicast test addresses, not vendor-issued identifiers. |
| 64 | Network Tools | [ASN WHOIS Lookup](https://dnschecker.org/asn-whois-lookup.php) | `/tools/asn-whois` | Partial — RDAP/WHOIS availability not live-verified. |
| 65 | Cyber Security Tools | [SSL Certificate Checker](https://dnschecker.org/ssl-certificate-examination.php) | `/tools/ssl-checker` | Partial — TLS metadata only; no full CA trust-chain validation. |
| 66 | Cyber Security Tools | [Password Encryption Online](https://dnschecker.org/password-encryption-utility.php) | `/tools/password-encryption` | Local utility; distinguish reversible encodings from password-storage hashing. |
| 67 | Cyber Security Tools | [Random Password Generator](https://dnschecker.org/password-generator.php) | `/tools/password-generator` | Local generator; representative module test passed. |
| 68 | Cyber Security Tools | [Password Strength Checker](https://dnschecker.org/password-strength-checker.php) | `/tools/password-strength` | Local checker; representative module test passed. |
| 69 | Productivity Tools | [QR Code Generator](https://dnschecker.org/qr-code-generator.php) | `/tools/qr-generator` | Local QR render/download module tests pass; real browser download behavior not fully tested. |
| 70 | Productivity Tools | [QR Scanner](https://dnschecker.org/qr-code-scanner.php) | `/tools/qr-scanner` | Client module loads; camera permissions, image decoding and device UX unverified. |
| 71 | Productivity Tools | [Lorem Ipsum Generator](https://dnschecker.org/lorem-ipsum.php) | `/tools/lorem-ipsum` | Local generator; representative module test passed. |
| 72 | Productivity Tools | [Time Card Calculator](https://dnschecker.org/time-card-calculator.php) | `/tools/time-card` | Local calculation tested; overnight/break/overtime edge cases need more coverage. |
| 73 | Productivity Tools | [BIN Checker](https://dnschecker.org/bin-checker.php) | `/tools/bin-checker` | Partial — accepts 6/8 digit prefixes, sends only six to provider; limited fallback; full PAN rejected. |
| 74 | Productivity Tools | [Credit Card Checker](https://dnschecker.org/credit-card-validator.php) | `/tools/credit-card-checker` | Local Luhn/format check only; do not submit or store real card numbers. |
| 75 | Productivity Tools | [Reverse Image Search](https://dnschecker.org/reverse-image-search.php) | `/tools/reverse-image-search` | Manual external-provider links only; no image upload or search result is claimed. |
| 76 | Productivity Tools | [Name Checker](https://dnschecker.org/social-media-name-checker.php) | `/tools/name-checker` | Fixed-host profile links/manual checks only; no availability API/query. |
| 77 | Productivity Tools | [Notepad Online](https://dnschecker.org/notepad-online.php) | `/tools/notepad` | Local module tested; storage/privacy behavior requires browser validation. |
| 78 | Productivity Tools | [Small Text Generator](https://dnschecker.org/small-text-generator.php) | `/tools/small-text` | Local converter; representative module test passed. |
| 79 | Productivity Tools | [Word Counter](https://dnschecker.org/word-counter.php) | `/tools/word-counter` | Local utility; representative module test passed; counting rules need edge-case review. |
| 80 | Productivity Tools | [Domain Name Search](https://dnschecker.org/search-domain-name-checker.php) | `/tools/domain-search` | WHOIS classification only, not registrar availability or pricing. |
| 81 | Productivity Tools | [ROT13 Decoder Encoder](https://dnschecker.org/rot13-decoder-encoder.php) | `/tools/rot13` | Local converter; representative module test passed. |
| 82 | Productivity Tools | [Morse Code Translator](https://dnschecker.org/morse-code-translator.php) | `/tools/morse-code-translator` | Local conversion; representative module test passed. |
| 83 | Productivity Tools | [BIMI Checker & Generator](https://dnschecker.org/bimi-record-lookup-generator.php) | `/tools/bimi` | Partial — current handler checks a BIMI DNS record; no generator in this catalog handler. |
| 84 | Productivity Tools | [Image to Text Converter](https://dnschecker.org/image-to-text-converter.php) | `/tools/image-to-text` | Configuration-dependent OCR; explicit opt-in and key required; no persistent image storage. |
| 85 | Productivity Tools | [Runic Translator](https://dnschecker.org/runic-translator) | `/tools/runic-translator` | Local conversion; digraph precedence and round-trip paths are tested. |
| 86 | Productivity Tools | [Invisible Character](https://dnschecker.org/invisible-character) | `/tools/invisible-character` | Local generate/detect/clean utility; representative behavior tested. |
| 87 | Productivity Tools | [Internet Speed Test](https://dnschecker.org/internet-speed-test) | `/tools/speed-test` | Hybrid; browser throughput, provider endpoints and device behavior not live-tested. |
| 88 | Productivity Tools | [WiFi QR Scanner](https://dnschecker.org/wifi-qr-code-scanner) | `/tools/wifi-qr-scanner` | Local QR build/parse round-trips tested; real camera behavior unverified. |
| 89 | Gaming Tools | [Minecraft Color Codes](https://dnschecker.org/minecraft-color-codes.php) | `/tools/minecraft-color-codes` | Local reference utility; representative module test passed. |

## Important scope differences

- DNSChecker labels its blacklist tool for both IP and email blacklists; CloudHost247 only checks selected IPv4 DNSBLs. It does not check email reputation or provide a 50+ source guarantee.
- PageRank is explicitly treated as a retired metric and no score is fabricated. The SERP tool is a local preview, not a Google results query.
- Reverse image and username tools provide manual external links only. They do not claim that an image search or availability check ran.
- DNS/domain, IP WHOIS, geolocation, SMTP, TLS, OCR, speed-test, and live website inspection paths require deployed network/provider capabilities and were not validated live.
- The route inventory is from this WHMCS repository, not the missing native Node.js/React/API application. It must not be used to imply that the requested native Tools Center expansion has shipped.

## Sources and method

- Directory: <https://dnschecker.org/all-tools.php> (fetched 2026-10-12).
- CloudHost247 route registry: `modules/addons/CloudHost247_tools/config/cloudhost247-tools.php`.
- No DNSChecker source, branding, markup, images, or implementation code was copied. This file records public labels/routes solely for inventory and feature comparison.
