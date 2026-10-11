# Tools Center — requirements traceability (audit 2026-10-11)

Status of each tool in the DNSChecker-inspired specification, checked against the native application `subwindels-hash/CloudHost247` (`main`, commit `8327b58`, `cloudhost247-node/`).

This is an **audit only**. No tool was implemented or changed by this document.

## 1. Repository audit

- **Connected repo `windelslife-wq/CloudHost247` (this session, branch `arena/d014b2ab-cloudhost247`):** a WHMCS/PHP storefront. It has no Node.js/React application and no tool catalogue of the spec's kind. It is **not** the target for the spec.
- **Native repo `subwindels-hash/CloudHost247` (`main`):** the intended native application.
  - `cloudhost247-node/`: Fastify API (TypeScript, Postgres, Vitest) with a React frontend. Tools live in `src/tools/` (core SSRF/DNS/rate-limit/provider layers, plus `dns/`, `ip/`, `network/`, `developer/`, `webmaster/`, `security/`, `productivity/`, `domain/`, `diagnostics/`). The catalogue is `src/tools/catalog.ts` and is documented in `docs/tools/TOOLS_CATALOG.md`.
  - `platform/`: a separate Node/Postgres service with a React SPA (`platform/spa`). It is not a tools host.
  - PHP/WHMCS pages and modules also live in that repo. They are legacy and are not the target for the spec.
- **Baseline run on native `main`:** `npm ci` in `cloudhost247-node` succeeded (381 packages). `tests/integration/tools-api.test.ts` passed **39/39**.
- **Reference catalogue:** `dnschecker.org/all-tools.php` was read in full (3 pages). It contains 89 tools across DNS, IP, Dev, Designer, Webmasters, Network, Cyber Security, Productivity and Gaming. This matches the 89 items in the specification.

## 2. Summary

| Status | Meaning | Count |
|---|---|---|
| E | Exists | 57 |
| P | Exists, needs a provider or capability | 12 |
| V | Exists, needs verification | 4 |
| PT | Partial | 9 |
| M | Missing | 7 |
| | **Total** | **89** |

Only **E** rows are close to done, and even those still need the per-tool verification in spec §9 (empty, malformed, timeout, abuse, mobile, keyboard, authorization, and a live data check). None is marked complete.

## 3. Traceability matrix

| # | Category | Reference tool | Native match | Status | Notes |
|---|---|---|---|---|---|
| 1 | A. DNS | DNS Propagation Checker | `dns-propagation` | E | Multi-resolver query already implemented. |
| 2 | A. DNS | Domain DNS Validation | `dns-health (no dedicated tool)` | PT | No standalone validation page; confirm dns-health covers record validation. |
| 3 | A. DNS | Reverse IP Lookup | `reverse-ip` | P | Needs REVERSE_IP provider. |
| 4 | A. DNS | DNS Lookup (all record types) | `dns-lookup` | E | Catalog lists A, AAAA, CNAME, MX, NS, TXT, SOA, SRV, CAA, PTR, DS, DNSKEY; verify against full supported set. |
| 5 | A. DNS | CNAME Lookup | `cname-lookup` | E | Route listed in TOOLS_CATALOG.md. |
| 6 | A. DNS | NS Lookup | `nameserver-lookup` | E |  |
| 7 | A. DNS | MX Lookup | `mx-lookup` | E |  |
| 8 | A. DNS | SPF Record Checker | `spf-checker` | E |  |
| 9 | A. DNS | DMARC Checker | `dmarc-checker` | E |  |
| 10 | A. DNS | Domain DNS Health Checker | `dns-health` | E |  |
| 11 | A. DNS | DMARC Record Generator | `dmarc-generator` | E |  |
| 12 | A. DNS | DNSKEY Lookup | `dnskey-lookup` | E |  |
| 13 | A. DNS | DS Lookup | `ds-lookup` | E |  |
| 14 | A. DNS | DKIM Checker | `dkim-checker` | P | Needs DKIM_VERIFY provider. |
| 15 | B. IP | IPv4 Ping | `ping` | V | Single ping tool; confirm IPv4 path separately. |
| 16 | B. IP | IPv6 Ping | `ping` | V | Confirm IPv6 path. |
| 17 | B. IP | What Is My IP | `my-ip` | E |  |
| 18 | B. IP | Traceroute | `traceroute` | P | Capability-gated (environment must permit). |
| 19 | B. IP | IP Location Lookup | `ip-lookup` | P | Needs GEOLOCATION provider. |
| 20 | B. IP | Email Header Trace / Analyzer | `email-header` | E |  |
| 21 | B. IP | IP Blacklist and Email Blacklist Checker | `ip-blacklist` | PT | IP (DNSBL) only. No email/domain blacklist check. |
| 22 | B. IP | IP to Decimal Converter | `ip-converters` | E |  |
| 23 | B. IP | IP to Hostname Lookup | `ip-to-hostname` | E |  |
| 24 | B. IP | IP WHOIS Lookup | `ip-whois` | P | RDAP-backed. |
| 25 | B. IP | IPv6 WHOIS Lookup | `ip-whois` | V | Confirm IPv6 input is accepted. |
| 26 | B. IP | IPv4 to IPv6 Converter | `ip-converters` | E |  |
| 27 | B. IP | Local IPv6 Address Generator | `none` | M | No catalog entry or handler. |
| 28 | B. IP | IPv6 CIDR to Range Converter | `ip-converters` | E |  |
| 29 | B. IP | IPv6 Range to CIDR Converter | `ip-converters` | E |  |
| 30 | B. IP | IPv6 Compression Tool | `ip-converters` | E |  |
| 31 | B. IP | IPv6 Expansion Tool | `ip-converters` | E |  |
| 32 | B. IP | IP Subnet Calculator | `subnet-calculator` | E |  |
| 33 | B. IP | IPv6 to IPv4 Converter | `ip-converters` | E |  |
| 34 | B. IP | IPv6 Compatibility Checker | `none` | M | No catalog entry or handler. |
| 35 | B. IP | What Is My ISP | `isp-lookup` | P | Needs GEOLOCATION provider. |
| 36 | B. IP | Domain to IP Lookup | `domain-to-ip` | E |  |
| 37 | C. Developer | HTTP Headers Checker | `http-headers` | E |  |
| 38 | C. Developer | Website OS Detection | `server-os` | E | Must report confidence level; verify evidence-only policy. |
| 39 | C. Developer | MD5 and Base64 Generator | `encoding-tools` | E |  |
| 40 | C. Developer | Multi-URL Opener | `url-tools` | E |  |
| 41 | C. Developer | SMTP Test | `smtp-tester` | E | Requires sign-in. Verify relay-abuse controls. |
| 42 | C. Developer | HTACCESS Redirect Generator | `url-tools` | E |  |
| 43 | C. Developer | URL Rewrite Generator | `url-tools` | E |  |
| 44 | C. Developer | Broken Link Checker | `broken-links` | E | Requires sign-in. |
| 45 | C. Developer | Open Graph Checker | `open-graph` | E |  |
| 46 | C. Developer | RAID Calculator | `none` | M | Only a marketing string matched in the code search. |
| 47 | C. Developer | Binary Translator | `encoding-tools` | E |  |
| 48 | C. Developer | Text to Binary Translator | `encoding-tools` | E |  |
| 49 | C. Developer | JSON Viewer, Beautifier and Minifier | `json-tools` | E |  |
| 50 | C. Developer | Email Verifier | `none` | M | No catalog entry or handler. |
| 51 | D. Designer | RGB to Colortone | `color-tools` | E |  |
| 52 | D. Designer | HEX to Colortone | `color-tools` | E |  |
| 53 | D. Designer | CMYK to Colortone | `color-tools` | E |  |
| 54 | D. Designer | HSV to Colortone | `color-tools` | E |  |
| 55 | E. Webmaster/SEO | Website Link Analyzer (internal/external) | `broken-links (links.ts classifies links)` | PT | No standalone analyzer page. |
| 56 | E. Webmaster/SEO | User Agent Information | `user-agent` | E |  |
| 57 | E. Webmaster/SEO | Google Page Rank | `none` | M | No reliable data source. Needs an honest unavailable notice, not fabricated data. |
| 58 | E. Webmaster/SEO | Punycode Converter | `punycode` | E |  |
| 59 | E. Webmaster/SEO | Robots.txt Generator | `robots-generator` | E |  |
| 60 | E. Webmaster/SEO | Google SERP Simulator | `serp-simulator` | E | Must not claim real Google results. |
| 61 | F. Network | Port Checker | `port-checker` | E | Requires sign-in. |
| 62 | F. Network | MAC Address Vendor Lookup | `mac-lookup` | P | Needs a configured IEEE OUI reference. |
| 63 | F. Network | MAC Address Generator | `mac-generator` | E |  |
| 64 | F. Network | ASN WHOIS Lookup | `asn-whois` | P | RDAP-backed. |
| 65 | G. Security | SSL Certificate Checker | `ssl-checker` | E |  |
| 66 | G. Security | Password / Text Encoding Utility | `password-tools + encoding-tools` | PT | Must separate reversible encoding from password hashing. Verify. |
| 67 | G. Security | Random Password Generator | `password-tools` | E |  |
| 68 | G. Security | Password Strength Checker | `password-tools` | E |  |
| 69 | H. Productivity | QR Code Generator (URL, text, contact card) | `qr-generator` | PT | Verify contact-card (vCard) support. |
| 70 | H. Productivity | QR Code Scanner (camera or image) | `qr-scanner` | V | Confirm camera mode. |
| 71 | H. Productivity | Lorem Ipsum Generator | `lorem-ipsum` | E |  |
| 72 | H. Productivity | Time Card / Working Hours Calculator | `time-card` | E |  |
| 73 | H. Productivity | BIN Checker | `bin-checker` | P | Needs BIN provider. |
| 74 | H. Productivity | Credit Card Format and Checksum Validator | `none (Luhn only inside bin.ts)` | PT | No standalone validator. Must not collect full card numbers. |
| 75 | H. Productivity | Reverse Image Search | `reverse-image-search` | P | Needs an authorized search provider. |
| 76 | H. Productivity | Social Media Username Checker | `none` | M | No catalog entry or handler. |
| 77 | H. Productivity | Online Notepad | `notepad` | E |  |
| 78 | H. Productivity | Small Text Generator | `small-text` | E |  |
| 79 | H. Productivity | Word Counter | `word-counter` | E |  |
| 80 | H. Productivity | Domain Name Availability Search | `domain-availability` | P | Needs AVAILABILITY provider. |
| 81 | H. Productivity | ROT13 Encoder/Decoder | `encoding-tools` | E |  |
| 82 | H. Productivity | Morse Code Translator | `encoding-tools` | E |  |
| 83 | H. Productivity | BIMI Checker and Generator | `bimi-checker` | PT | Checker only. No generator. |
| 84 | H. Productivity | Image-to-Text Converter (OCR) | `image-ocr` | P | Needs OCR provider. |
| 85 | H. Productivity | Runic Translator | `runic-translator` | E |  |
| 86 | H. Productivity | Invisible Character Generator and Inspector | `invisible-character` | PT | Generator confirmed; inspector not confirmed. |
| 87 | H. Productivity | Internet Speed Test | `speed-test` | E |  |
| 88 | H. Productivity | Wi-Fi QR Code Scanner | `wifi-qr (generator) + qr-scanner` | PT | wifi-qr only generates. Verify scanner parses WIFI: payloads. |
| 89 | I. Gaming | Minecraft Color and Formatting Codes | `none` | M | No catalog entry or handler. |

## 4. Native tools not in the reference catalogue

These exist in the native app but are not listed in the reference: `uuid-generator`, `mrz-generator`, `business-tools` (calculators, ID-card generator), `reverse-dns`, `domain-health` (Domain Health Center), `tool-favorites`, `tool-history`, `tool-reports`, `tool-monitors`. They are not counted above.

## 5. Gaps that need a decision before implementation

1. **Wrong repository for this session.** The spec says to implement in `subwindels-hash/CloudHost247`, but this session is fixed to `windelslife-wq/CloudHost247` on branch `arena/d014b2ab-cloudhost247`, and I may not push to other branches. Implementing in the native repo needs a different session target, or the owner to open a branch there.
2. **Missing outright (M, 7):** Local IPv6 generator, IPv6 compatibility checker, RAID calculator, Email verifier, Google PageRank, Social username checker, Minecraft colour codes. Google PageRank has no reliable public data source. The spec requires an honest unavailable notice for it.
3. **Provider-gated (P, 12):** these need owner-chosen, paid or licensed data sources (RDAP is free, but geolocation, BIN, OCR, reverse image search, reverse IP, DKIM verification, OUI, availability). Each needs a provider decision and credentials.
4. **Partial (PT, 9):** email/domain blacklist; standalone link analyzer; card-number format/checksum validator; BIMI generator; QR contact-card support; Wi-Fi QR scanning; invisible-character inspector; password vs text encoding separation; domain-DNS validation.
5. **Verification (V, 4):** IPv4 and IPv6 ping, IPv6 WHOIS, QR camera scanning.

## 6. Recommended next step

Confirm the target repository and branch for implementation (the native repo, on a branch this session can push to). Then implement in vertical slices in the order of the phases in the spec, starting with the 7 missing tools, which need no paid provider.
