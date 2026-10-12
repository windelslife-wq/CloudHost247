# CloudHost247 WHMCS tools and storefront audit — 2026-10-12

## Executive result

- The mounted repository is `windelslife-wq/CloudHost247` on the confirmed session branch `arena/ca8f1fe8-cloudhost247`. It is a WHMCS/PHP storefront and module repository.
- The requested native Node.js/React/API tools host is **not present in this checkout**: there is no `cloudhost247-node/`, `platform/`, root React app, or native tools API project. No PAYE/pension/VAT/EWA calculators, Salario document generators, employee ID-card designer, or MRZ utility has been added to this PHP storefront. Implementing those here would violate the standing target constraint.
- The current public PHP tools addon has 91 routes, including the 89 DNSChecker-inspired concepts plus two additional tools. Its code and local module tests are materially more honest about provider coverage than several legacy descriptions, but the suite is not live-provider, browser-viewport, or WHMCS deployment evidence.
- There are **three overlapping WHMCS tools surfaces**: `CloudHost247_tools` (recommended, 91 routes), `tools_center` (81 actions with a separate API/catalogue), and retired `hostx_tools` (four legacy lookup tools; WHMCS deactivation remains pending). Do not add a fourth PHP copy of the requested tools.
- Tests executed in this checkout are recorded in §8. No production WHMCS instance, full browser run, mobile/tablet inspection, live provider validation, or screenshots/recordings were available.

## 1. Scope, method, and limits

This addendum inventories the mounted WHMCS checkout and verifies the branch and current working-tree source. It reviews the tool registries, route wiring, security boundaries, test harnesses, deployment notes, the existing domain/WHOIS paths, and the live Salario/DNSChecker reference surfaces. It is a code/reference audit, not a production readiness attestation.

The current checkout does not include WHMCS core (`init.php` and a live database are not mounted), so the review cannot exercise real client/admin sessions, checkout, cron, provider calls, CORS at a deployed web server, or the rendered Hostx theme. Tests below are local/offline/mocked. A native `php` binary is not installed; changed PHP sources were syntax-parsed separately (see §8), not run through the system's `php -l` binary.

Historical native-app evidence is kept separately in [`TOOLS_CENTER_TRACEABILITY.md`](TOOLS_CENTER_TRACEABILITY.md). That file is explicitly a snapshot of another repository and is **not** treated as current native-code or build evidence.

## 2. Repository and route inventory

### Main website and theme

- 62 root-level PHP page entry points are present. They cover hosting/product pages, legal/policy pages, help and announcements, and CloudHost247 services.
- `templates/hostx/` contains 160 top-level `.tpl` files, 42 CSS files, and 19 JavaScript files, plus fonts, icons, images, banners, order forms, and nested includes. The shared layout/navigation is in `header.tpl`, `footer.tpl`, `common.tpl`, `hostx.tpl`, and sidebar templates.
- The custom PHP page groups include:
  - Hosting/product routes: `web-hosting.php`, `wordpress-hosting.php`, `cpanel-hosting.php`, `plesk-hosting.php`, `vps-hosting.php`, `vps-privatecloud.php`, `vps-publiccloud.php`, `dedicated-server.php`, `windows-hosting.php`, `game-servers.php`, `enterprise-servers.php`, `website-builder.php`, `ai-website-builder.php`, `online-store.php`, `website-design.php`, and `ssl-certificate.php`.
  - Domain and platform routes: `domain-search.php`, `bulk-domain-search.php`, `domain-transfer.php`, `domain-valuation.php`, `domain-auctions.php`, `domain-broker.php`, `discount-domain-club.php`, `tld-directory.php`, `whois-lookup.php`, `unified-inbox.php`, `hire-an-expert.php`, `logo-maker.php`, and `digital-marketing.php`.
  - About/help/legal routes: `aboutus.php`, `blog.php`, `faqs.php`, `help-center.php`, `privacy-policy.php`, `terms-of-service.php`, `refund-policy.php`, `refund-and-cancellation-policy.php`, and the other root policy pages.
- `domain.php` and `domains.php` both dispatch to the `chs-domains` landing, so they are duplicate entry points unless the deployment intentionally uses them as aliases. Canonical/redirect behavior was not tested live.
- The dedicated product page `ssl-certificate.php` is separate from the technical `/tools/ssl-checker` utility.
- `domain-search.php` uses `DomainSearchService` for the platform's real registry availability/pricing/cart workflow; `/tools/domain-search` is a separate preliminary WHOIS classification tool and must not be presented as the same availability/pricing guarantee. `whois-lookup.php` uses `WhoisService` and a CSRF-protected POST form.
- Core hosting/domain product pages, WHMCS login/client-area flows, checkout, and administration routes were not changed by this audit pass. The Tools Center and public tools addon's own client-area/API files were changed for the tool-specific route and security findings documented below. All live workflows remain unverified in this sandbox.
- The root `.htaccess` has a `/tools/*` rewrite to `modules/addons/CloudHost247_tools/front.php`; the comment requires this rule to precede WHMCS rewrites. Deployment ordering must be checked on the live host.

#### Root PHP page inventory (all 62 files)

Every item below is present as a root PHP entry point. “UI/content present” is **not** a claim that each page, asset, WHMCS hook, or conversion flow was rendered and exercised in a live browser. Domain workflows were selectively reviewed in source; the rest are catalogued by route/file and their live rendering remains unverified.

| Route/file group | Root PHP entry points | Audit disposition |
|---|---|---|
| Hosting and product landing pages | `ai-website-builder.php`, `cpanel-hosting.php`, `dedicated-server.php`, `enterprise-servers.php`, `game-servers.php`, `online-store.php`, `plesk-hosting.php`, `ssl-certificate.php`, `vps-hosting.php`, `vps-privatecloud.php`, `vps-publiccloud.php`, `web-hosting.php`, `website-builder.php`, `website-design.php`, `windows-hosting.php`, `wordpress-hosting.php` | UI/product pages present; WHMCS product data, order buttons, responsive layouts, and checkout hand-off not exercised live. |
| Domain and WHOIS workflows | `bulk-domain-search.php`, `discount-domain-club.php`, `domain-auctions.php`, `domain-broker.php`, `domain-search.php`, `domain-transfer.php`, `domain-valuation.php`, `domain.php`, `domains.php`, `tld-directory.php`, `whois-lookup.php` | Partial — code paths/services exist; live registry, pricing, login, cart, and provider paths unverified. `domain.php` and `domains.php` share the `chs-domains` landing and are duplicate entry points unless intentionally deployed as aliases. |
| Other service pages | `digital-marketing.php`, `hire-an-expert.php`, `logo-maker.php`, `unified-inbox.php` | UI/service entry points present; integrations, form submissions, and fulfilment not live-verified. |
| Brand, content, help, demo, and error pages | `aboutus.php`, `all-element-hostx.php`, `blog.php`, `comingsoon.php`, `developer-friendly.php`, `faqs.php`, `future-element.php`, `help-center.php`, `hostx-sample.php`, `hostx-vps-sample.php`, `notfound.php`, `offers.php`, `tables.php` | UI/content/demo/error pages; individual CTA links, search/help interactions, responsive viewports, and page-not-found response codes not browser-verified. `comingsoon.php` / `future-element.php` are intentionally promotional/placeholder surfaces, not tools. |
| Policies, notices, and agreements | `acceptable-use-policy.php`, `backup-policy.php`, `cookie-policy.php`, `cybercrime-policy.php`, `data-deletion.php`, `data-privacy-notice-and-consent-form.php`, `data-protection-standards.php`, `domain-agreement.php`, `domain-renewal-policy.php`, `domainregistrationaddendum.php`, `fair-usage-policy.php`, `legal-notice.php`, `legal.php`, `privacy-policy.php`, `refund-and-cancellation-policy.php`, `refund-policy.php`, `terms-of-service.php`, `trademark-policy.php` | Static/legal content routes; content correctness/legal review and production rendering are outside this code-level pass. |

#### Hostx theme assets and navigation

- Shared shell/layout files: `templates/hostx/header.tpl`, `footer.tpl`, `common.tpl`, `hostx.tpl`, `sidebar-categories*.tpl`, `sidebar-categories-selector.tpl`, and `sidebar-categories-collapsed.tpl`; custom domain/service templates use the `chs-*.tpl` files. The full top-level template roster is 160 files (311 `.tpl` files including nested templates).
- Primary theme styles include `css/style.css`, `css/styles.css`, `css/main-style.css`, `css/custom.css`, `css/overrides.css`, `css/custom-responsive.css`, `css/responsive.css`, `css/style-rtl.css`, and `css/announcementbar.css`; the remaining CSS includes vendor/bootstrap/font/icon/order-form/store/theme variants. There are 42 CSS files total.
- Primary scripts include `js/base.js`, `js/custom.js`, `js/custom_scripts.js`, `js/scripts.js`, `js/scripts.min.js`, `js/whmcs.js`, the off-canvas-nav, cookie, carousel/slider, and store integrations; there are 19 JS files total. No complete dependency/license audit or browser-console/mobile/keyboard run was performed.
- Theme audit evidence in this checkout includes the focused announcement-bar suite (43/43) and prior template/source review. It does not establish complete WCAG conformance, functioning navigation at all breakpoints, or compatibility with the live WHMCS template compiler.

### Modules and archives

- The repository-level module source/provenance and duplicate-resolution inventory is [`MODULES.md`](MODULES.md); unfinished/audit states are in [`UNFINISHED_MODULES.md`](UNFINISHED_MODULES.md) and [`MODULE_COMPLETION_TRACKER.md`](MODULE_COMPLETION_TRACKER.md).
- There are no `.zip`, `.tar`, `.tgz`, `.gz`, `.7z`, or `.rar` source archives in the checked-out working tree. `MODULES.md` records which imported archives supplied each module and which duplicate payloads were intentionally not extracted twice.
- `modules/addons/hostx_tools/README.md` marks that addon retired, with WHMCS deactivation pending. Its four legacy functions are domain WHOIS, IP lookup, DNS lookup, and domain availability; they overlap the newer tools surfaces. The code-side spoofed-forwarded-IP rate-limit issue was fixed, but an installed/active retired addon is still a deployment exposure until actually deactivated.

## 3. Current PHP tools inventory

### `CloudHost247_tools` — primary/public route set

Source of truth: `modules/addons/CloudHost247_tools/config/cloudhost247-tools.php`; route/page controller: `front.php` + `includes/Front.php` + `includes/Router.php`; API: `api/index.php`; browser bundle and route-split modules: `assets/js/CloudHost247-tools.js` and `assets/js/tools/*.js`.

The catalog has 91 tools across 9 categories: DNS 15, IP 22, Developer 14, Designer 4, Webmaster 6, Network 5, Security 4, Productivity 20, Gaming 1. Execution is 46 client-side, 44 server-side (43 active plus one marked `requires_configuration`), and one hybrid. The catalog includes the full set of 89 DNSChecker-inspired concepts, with two additional entries (`domain-whois`, `website-status`). The exact current public reference names/URLs and the one-to-one CloudHost route mapping are recorded in [`DNSCHECKER_REFERENCE_CROSSWALK_2026-10-12.md`](DNSCHECKER_REFERENCE_CROSSWALK_2026-10-12.md). Presence does not mean reference parity or verified live operation.

`tools.test.mjs` found all 91 route-split modules. It executed 46 client `run()` modules on representative input and exercised 45 server `render()` modules. Those are isolated DOM-shim checks, not a full real-browser test.

Disposition key for the route matrix:
- **Complete (local)** means a deterministic local function is present and the representative module test passed. It does not mean all boundary conditions, accessibility, downloads, mobile layouts, or every visible control have been browser-verified.
- **Partial (server/live-unverified)** means a server handler is present, but real DNS/provider/socket behavior has not been validated against the deployment environment.
- **UI-only/limited** means the result is a manual link, local preview, or explicit unavailable state rather than a provider-backed answer.
- No tool is marked end-to-end complete. No currently failing route was exposed by the executed offline suites; this is not proof none exists.

| Route | Tool | Execution | Audit disposition / caveat |
|---|---|---|---|
| `/tools/dns-propagation-checker` | DNS Propagation Checker | Server | Partial — multi-resolver implementation exists; live resolver reachability unverified. |
| `/tools/dns-validation` | Domain DNS Validation | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/reverse-ip` | Reverse IP Lookup | Server | Partial — coverage/provider behavior not live-verified. |
| `/tools/dns-lookup` | DNS Lookup | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/cname-lookup` | CNAME Lookup | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/ns-lookup` | NS Lookup | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/mx-lookup` | MX Lookup | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/spf-checker` | SPF Record Checker | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/dmarc-checker` | DMARC Checker | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/dns-health` | Domain DNS Health Checker | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/dmarc-generator` | DMARC Record Generator | Client | Complete (local) — generator function present; representative test passed. |
| `/tools/dnskey-lookup` | DNSKEY Lookup | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/ds-lookup` | DS Lookup | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/dkim-checker` | DKIM Checker | Server | Partial — handler exists; live DNS/environment unverified. |
| `/tools/domain-whois` | Domain WHOIS Lookup | Server | Partial — handler exists; registry coverage and live results unverified. |
| `/tools/ping-ipv4` | Ping IPv4 Address | Server | Partial — mocked parsing/security tests pass; live ICMP/host capability unverified. |
| `/tools/ping-ipv6` | Ping IPv6 Address | Server | Partial — mocked parsing/security tests pass; live ICMP/host capability unverified. |
| `/tools/my-ip` | What Is My IP Address | Server | Partial — deployed trusted-proxy/client-IP configuration not validated. |
| `/tools/traceroute` | Traceroute | Server | Partial — parser/allowlist tests pass; live host routing/capability unverified. |
| `/tools/ip-location` | IP Location Lookup | Server | Partial — provider/network data not live-verified. |
| `/tools/email-header-analyzer` | Trace Email / Header Analyzer | Client | Complete (local) — local parser module representative test passed. |
| `/tools/blacklist-checker` | IPv4 Blacklist Checker | Server | Partial — checks selected DNSBL zones through the server resolver; IPv4 only. IPv6, private/reserved ranges, and email-address reputation are explicitly unsupported; list freshness and live responses are unverified. |
| `/tools/ip-to-decimal` | IP to Decimal | Client | Complete (local) — representative test passed. |
| `/tools/ip-to-hostname` | IP to Hostname | Server | Partial — reverse-DNS environment not live-verified. |
| `/tools/ip-whois` | IP WHOIS Lookup | Server | Partial — RDAP/WHOIS availability not live-verified. |
| `/tools/ipv6-whois` | IPv6 WHOIS Lookup | Server | Partial — IPv6 registry path not live-verified. |
| `/tools/ipv4-to-ipv6` | IPv4 to IPv6 | Client | Complete (local) — representative test passed. |
| `/tools/ipv6-address-generator` | Local IPv6 Address Generator | Client | Complete (local) — representative test passed. |
| `/tools/ipv6-cidr-range` | IPv6 CIDR to Range | Client | Complete (local) — representative test passed; core IPv6 suite also covers range math. |
| `/tools/ipv6-range-cidr` | IPv6 Range to CIDR | Client | Complete (local) — representative test passed; core IPv6 suite also covers range math. |
| `/tools/ipv6-compress` | IPv6 Compression | Client | Complete (local) — representative test passed. |
| `/tools/ipv6-expand` | IPv6 Expand | Client | Complete (local) — representative test passed. |
| `/tools/ip-subnet-calculator` | IP Subnet Calculator | Client | Complete (local) — representative test passed. |
| `/tools/ipv6-to-ipv4` | IPv6 to IPv4 | Client | Complete (local) — representative test passed. |
| `/tools/ipv6-compatibility` | IPv6 Compatibility Checker | Server | Partial — mocked family/transport tests pass; live deployment network unverified. |
| `/tools/my-isp` | What Is My ISP | Server | Partial — external lookup/deployment behavior unverified. |
| `/tools/domain-to-ip` | Domain to IP Lookup | Server | Partial — live DNS behavior unverified. |
| `/tools/http-headers` | HTTP Headers Checker | Server | Partial — SSRF/redirect controls are tested; live fetch results not verified. |
| `/tools/website-os` | Website Operating System Checker | Server | Partial — heuristic fingerprint only; not authoritative OS identification. |
| `/tools/md5-base64` | MD5 & Base64 Generator | Client | Complete (local) — representative test passed. |
| `/tools/multi-url-opener` | Multi URL Opener | Client | Complete (local) — safe URL construction is tested; popup behavior not browser-tested. |
| `/tools/smtp-test` | SMTP Test | Server | Partial — bounded probe controls are tested; live SMTP reachability unverified. |
| `/tools/htaccess-redirect-generator` | HTACCESS Redirect Generator | Client | Complete (local) — representative test passed. |
| `/tools/url-rewrite-generator` | URL Rewrite Generator | Client | Complete (local) — representative test passed. |
| `/tools/broken-link-checker` | Broken Link Checker | Server | Partial — safe fetch/redirect controls are tested; crawling not live-tested. |
| `/tools/open-graph-checker` | Open Graph Checker | Server | Partial — live page-fetch/metadata path not validated. |
| `/tools/raid-calculator` | RAID Calculator | Client | Complete (local) — representative calculation test passed. |
| `/tools/binary-translator` | Binary Translator | Client | Complete (local) — representative test passed. |
| `/tools/text-to-binary` | Text to Binary | Client | Complete (local) — representative test passed. |
| `/tools/json-tool` | JSON Viewer, Beautifier & Minifier | Client | Complete (local) — representative test passed. |
| `/tools/email-verifier` | Email Verifier | Server | Partial — syntax/DNS signals do not prove mailbox deliverability; live checks unverified. |
| `/tools/rgb-colortone` | RGB to Colortone | Client | Complete (local) — representative conversion test passed. |
| `/tools/hex-colortone` | HEX to Colortone | Client | Complete (local) — representative conversion test passed. |
| `/tools/cmyk-colortone` | CMYK to Colortone | Client | Complete (local) — representative conversion test passed. |
| `/tools/hsv-colortone` | HSV to Colortone | Client | Complete (local) — representative conversion test passed. |
| `/tools/link-analyzer` | Website Link Analyzer | Server | Partial — live fetch/crawl behavior unverified. |
| `/tools/user-agent` | What Is My User Agent | Client | Complete (local) — representative test passed. |
| `/tools/page-rank` | PageRank Status (Retired Metric) | Server | UI-only/unsupported — no score is returned; retirement is disclosed. |
| `/tools/punycode` | Punycode Converter | Client | Complete (local) — representative test passed. |
| `/tools/serp-simulator` | Google SERP Simulator | Client | Complete (local preview) — no live Google results are claimed. |
| `/tools/robots-generator` | Robots.txt Generator | Client | Complete (local) — representative test passed. |
| `/tools/port-checker` | Port Checker | Server | Partial — allowlist/SSRF controls are tested; live reachability unverified. |
| `/tools/mac-lookup` | MAC Address Lookup | Client | Partial — only a small bundled, unversioned local OUI sample; no vendor is guessed for unknown/local addresses. |
| `/tools/mac-generator` | MAC Address Generator | Client | Complete (local) — cryptographically random locally administered unicast test addresses; not vendor-issued MACs. |
| `/tools/asn-whois` | ASN WHOIS Lookup | Server | Partial — RDAP/WHOIS availability not live-verified. |
| `/tools/website-status` | Website Status Checker | Server | Partial — live status/redirect behavior unverified. |
| `/tools/ssl-checker` | SSL Certificate Checker | Server | Partial — standard-port TLS metadata and hostname checks; no full CA trust-chain validation. |
| `/tools/password-encryption` | Password Encryption | Client | Complete (local) — local utility representative test passed; do not confuse reversible encodings with password storage policy. |
| `/tools/password-generator` | Random Password Generator | Client | Complete (local) — representative generation test passed. |
| `/tools/password-strength` | Password Strength Checker | Client | Complete (local) — representative test passed. |
| `/tools/qr-generator` | QR Code Generator | Client | Complete (local) — QR payload/render/download tests pass; camera/browser downloads not fully tested. |
| `/tools/qr-scanner` | QR Scanner | Client | Partial — module loads; actual camera permissions, image decoding, and device UX not browser-tested. |
| `/tools/lorem-ipsum` | Lorem Ipsum Generator | Client | Complete (local) — representative test passed. |
| `/tools/time-card` | Time Card Calculator | Client | Complete (local) — representative time calculation test passed. |
| `/tools/bin-checker` | BIN Checker | Server | Partial — accepts 6/8 digits but sends only first six to Binlist; bundled fallback is limited/unversioned; full card numbers are rejected. |
| `/tools/credit-card-checker` | Credit Card Checker | Client | Complete (local-only) — Luhn/check-format function runs in browser; do not send/store real card numbers. |
| `/tools/reverse-image-search` | Reverse Image Search | Server (configuration-required) | UI-only/partial — builds external-provider links from a public image URL; does not perform a search or upload the image. |
| `/tools/name-checker` | Username Profile Links | Server | UI-only/partial — provides fixed-host profile links for manual checks; no availability API/query. |
| `/tools/notepad` | Online Notepad | Client | Complete (local) — local notepad module representative test passed. |
| `/tools/small-text` | Small Text Generator | Client | Complete (local) — representative test passed. |
| `/tools/word-counter` | Word Counter | Client | Complete (local) — representative test passed. |
| `/tools/domain-search` | Domain WHOIS Status Check | Server | Partial — explicit record/no-match/unconfirmed WHOIS status, not registrar availability or pricing. |
| `/tools/rot13` | ROT13 Decoder / Encoder | Client | Complete (local) — representative test passed. |
| `/tools/morse-code-translator` | Morse Code Translator | Client | Complete (local) — representative test passed. |
| `/tools/bimi` | BIMI Checker & Generator | Server | Partial — current handler checks a BIMI DNS record; no generator is implemented in this catalog handler. |
| `/tools/image-to-text` | Image to Text Converter | Server | Partial/config-dependent — OCR.space server call requires explicit opt-in and configured key; image bytes are validated and not persisted. |
| `/tools/runic-translator` | Runic Translator | Client | Complete (local) — digraph precedence and round-trip paths are tested. |
| `/tools/invisible-character` | Invisible Character Tool | Client | Complete (local) — generate/detect/clean behavior representative-tested. |
| `/tools/speed-test` | Internet Speed Test | Hybrid | Partial — browser/network throughput, provider endpoints, and device behavior not validated live. |
| `/tools/wifi-qr-scanner` | WiFi QR Scanner | Client | Complete (local) — Wi-Fi QR build/parse round trips are tested; real camera behavior is not. |
| `/tools/minecraft-color-codes` | Minecraft Color Codes | Client | Complete (local) — representative display/function test passed. |

### Important API/data limitations

- PageRank has no current authoritative score source and must remain an unavailable explanation, not a made-up rating.
- The blacklist checker is IPv4-only, queries a hard-coded set of selected DNSBL zones through the server's resolver, and does not assess email addresses. IPv6/private/reserved inputs now fail closed instead of producing potentially false clean results. The list's current availability/freshness and every DNS response remain live-unverified.
- MAC lookup only has a small bundled sample with no recorded source/version; the current copy correctly says it does not establish complete vendor attribution. The generator now emits cryptographically random locally administered unicast **test** addresses, not vendor-issued identifiers.
- BIN lookup strictly accepts only a 6- or 8-digit prefix, sends only the first six digits to Binlist, and labels the limited fallback; a full card number is rejected. A BIN response is not a payment authorization or card-validity check.
- Reverse image search and username checks do not query provider availability; they produce external links/manual checks. The legacy app must not present these as results.
- `domain-search` uses WHOIS classification and never guarantees registrar availability/pricing. Use the separate `domain-search.php`/`DomainSearchService` for the actual WHMCS registry/cart flow.
- The SSL checker reports certificate/TLS details on supported ports but does not perform full CA trust-chain validation. The website OS tool is a heuristic, not authoritative evidence.
- OCR.space is external and requires a key plus per-submit user opt-in; no production key/provider was tested.

## 4. Overlapping and legacy tools directories

| Surface | Current size | Route/deployment | Finding |
|---|---:|---|---|
| `modules/addons/CloudHost247_tools` | 91 tools, 9 categories | Public `/tools/*`, POST `/tools/api/<slug>` | Recommended/current implementation; registry-backed, route-split browser modules, CSRF/rate-limit/SSRF protections. |
| `modules/addons/tools_center` | 81 action definitions, 10 categories | WHMCS client area `index.php?m=tools_center`; calls configured external PHP API | Duplicates broad DNS/IP/dev/webmaster/network/productivity functions. It has a separate registry/UI/API and is not a shared canonical catalog with `CloudHost247_tools`. QR Generator/Scanner were intentionally removed (decision F-3). |
| `modules/addons/hostx_tools` | 4 lookup tools | WHMCS client area `index.php?m=hostx_tools` | Retired and overlaps WHOIS/IP/DNS/domain-availability tools; deactivation still requires an operator in WHMCS. |

The Tools Center action registry is inline in `clientarea.php` (81 action keys); detail pages use `index.php?m=tools_center&cat=<category>&tool=<action>`, while dashboard/category routes are `index.php?m=tools_center` and `index.php?m=tools_center&cat=<category>`. The same-origin AJAX runner POSTs the selected category/action/parameters plus CSRF to the current client-area endpoint. Tool implementation is spread across `external-api/tools/*.php`; the external service deployment and the WHMCS client addon are separate configuration/deployment steps. A static crosswalk found matching public method definitions for **81/81** registry action/category pairs in the corresponding API class files, but a method match is not an execution test. Offline tests exercise selected API/auth paths, domain search, route validation, outbound helpers, and now the host-directed IP/WHOIS implementations with mocked transports. **Every row below is present in the client registry, but no row has been individually end-to-end executed in a live WHMCS deployment**; `Partial / unverified` is the default disposition unless the row says otherwise.

| Category | Action | Visible tool name | API category file | Audit disposition |
|---|---|---|---|---|
| Search | `domainSearch` | Domain Name Search | `external-api/tools/search.php` | Partial — preliminary WHOIS/registry classification only; not registrar availability/pricing. |
| DNS | `spfChecker` | SPF Record Checker | `external-api/tools/dns.php` | Partial / unverified — registry entry; not individually executed. |
| DNS | `dnsValidation` | Domain DNS Validation | `external-api/tools/dns.php` | Partial / unverified — registry entry; not individually executed. |
| DNS | `reverseIpLookup` | Reverse IP Lookup | `external-api/tools/dns.php` | Partial / unverified — provider/coverage unverified. |
| DNS | `dnsLookup` | DNS Lookup | `external-api/tools/dns.php` | Partial / unverified — registry entry; live resolver behavior unverified. |
| DNS | `cnameLookup` | CNAME Lookup | `external-api/tools/dns.php` | Partial / unverified — registry entry; live resolver behavior unverified. |
| DNS | `nsLookup` | NS Lookup | `external-api/tools/dns.php` | Partial / unverified — registry entry; live resolver behavior unverified. |
| DNS | `mxLookup` | MX Lookup | `external-api/tools/dns.php` | Partial / unverified — registry entry; live resolver behavior unverified. |
| DNS | `dnsPropagation` | DNS Propagation | `external-api/tools/dns.php` | Partial / unverified — resolver coverage/live behavior unverified. |
| DNS | `dmarcValidation` | DMARC Validation | `external-api/tools/dns.php` | Partial / unverified — registry entry; live resolver behavior unverified. |
| DNS | `dnsHealth` | DNS Health | `external-api/tools/dns.php` | Partial / unverified — scoring/data behavior not individually checked. |
| DNS | `dmarcGenerator` | DMARC Generator | `external-api/tools/dns.php` | Partial / unverified — generator output not individually checked. |
| DNS | `dnskeyLookup` | DNSKEY Lookup | `external-api/tools/dns.php` | Partial / unverified — resolver/DNSSEC output not live-checked. |
| DNS | `dsLookup` | DS Lookup | `external-api/tools/dns.php` | Partial / unverified — resolver/DNSSEC output not live-checked. |
| DNS | `dkimChecker` | DKIM Checker | `external-api/tools/dns.php` | Partial / unverified — provider/selector coverage not live-checked. |
| IP | `ping` | Ping | `external-api/tools/ip.php` | Partial / unverified — IPv4/IPv6 transport capability not live-checked. |
| IP | `whatIsMyIP` | What is My IP | `external-api/tools/ip.php` | Partial / unverified — deployed proxy/IP configuration unverified. |
| IP | `traceroute` | Traceroute | `external-api/tools/ip.php` | Partial / unverified — live route/capability unverified. |
| IP | `ipLocation` | IP Location | `external-api/tools/ip.php` | Partial / unverified — geolocation provider/data not live-checked. |
| IP | `emailHeaderAnalyzer` | Email Header Analyzer | `external-api/tools/ip.php` | Partial / unverified — parser behavior not individually checked. |
| IP | `ipBlacklist` | IP Blacklist | `external-api/tools/ip.php` | Partial / unverified — RBL coverage and resolver results not live-checked. |
| IP | `ipToDecimal` | IP to Decimal | `external-api/tools/ip.php` | Partial / unverified — converter output not individually checked. |
| IP | `resolveIPtoHostname` | Resolve IP to Hostname | `external-api/tools/ip.php` | Partial / unverified — reverse-DNS behavior unverified. |
| IP | `ipWhois` | IP WHOIS | `external-api/tools/ip.php` | Partial / unverified — RDAP/WHOIS coverage unverified. |
| IP | `ipv6Whois` | IPv6 WHOIS | `external-api/tools/ip.php` | Partial / unverified — IPv6 registry behavior unverified. |
| IP | `ipv4ToIPv6` | IPv4 to IPv6 | `external-api/tools/ip.php` | Partial / unverified — conversion output not individually checked. |
| IP | `localIPv6Generator` | Local IPv6 Generator | `external-api/tools/ip.php` | Partial / unverified — generated ranges/prefix assumptions not checked. |
| IP | `ipv6CIDRtoRange` | IPv6 CIDR to Range | `external-api/tools/ip.php` | Partial / unverified — boundary/output behavior not individually checked. |
| IP | `ipv6RangeToCIDR` | IPv6 Range to CIDR | `external-api/tools/ip.php` | Partial / unverified — boundary/output behavior not individually checked. |
| IP | `ipv6Compatibility` | IPv6 Compatibility | `external-api/tools/ip.php` | Partial / unverified — live IPv6-only destination checks not run. |
| IP | `ipv6Compression` | IPv6 Compression | `external-api/tools/ip.php` | Partial / unverified — converter output not individually checked. |
| IP | `ipv6Expand` | IPv6 Expand | `external-api/tools/ip.php` | Partial / unverified — converter output not individually checked. |
| IP | `subnetCalculator` | Subnet Calculator | `external-api/tools/ip.php` | Partial / unverified — boundary/output behavior not individually checked. |
| IP | `ipv6ToIPv4` | IPv6 to IPv4 | `external-api/tools/ip.php` | Partial / unverified — supported transition types not individually checked. |
| IP | `ispChecker` | ISP Checker | `external-api/tools/ip.php` | Partial / unverified — provider/data not live-checked. |
| IP | `websiteToIP` | Website to IP | `external-api/tools/ip.php` | Partial / unverified — DNS output not live-checked. |
| Developer | `httpHeadersCheck` | HTTP Headers Check | `external-api/tools/developer.php` | Partial / unverified — safe-fetch tests cover guards, not live target behavior. |
| Developer | `websiteOSChecker` | Website OS Checker | `external-api/tools/developer.php` | Partial / unverified — heuristic evidence/results not individually tested. |
| Developer | `hashGenerator` | MD5 & Base64 Generator | `external-api/tools/developer.php` | Partial / unverified — algorithm/output behavior not individually tested. |
| Developer | `multiUrlOpener` | Multi URL Opener | `external-api/tools/developer.php` | Partial / unverified — browser popup behavior not tested. |
| Developer | `smtpTest` | SMTP Test | `external-api/tools/developer.php` | Partial / unverified — live SMTP transport not tested. |
| Developer | `htaccessRedirectGenerator` | htaccess Redirect | `external-api/tools/developer.php` | Partial / unverified — generated rules not individually tested. |
| Developer | `urlRewriteGenerator` | URL Rewrite | `external-api/tools/developer.php` | Partial / unverified — generated rules not individually tested. |
| Developer | `brokenLinksChecker` | Broken Links | `external-api/tools/developer.php` | Partial / unverified — live crawl not tested. |
| Developer | `openGraphGenerator` | Open Graph Generator | `external-api/tools/developer.php` | Partial / unverified — output/escaping not individually checked. |
| Developer | `raidCalculator` | RAID Calculator | `external-api/tools/developer.php` | Partial / unverified — math/output not individually checked. |
| Developer | `binaryTextConverter` | Binary/Text Converter | `external-api/tools/developer.php` | Partial / unverified — conversion edge cases not individually checked. |
| Developer | `jsonTool` | JSON Tool | `external-api/tools/developer.php` | Partial / unverified — validation/minify edge cases not individually checked. |
| Designer | `rgbToPantone` | RGB to Pantone | `external-api/tools/designer.php` | Partial / unverified — reference/palette source and mapping not validated. |
| Designer | `hexToPantone` | HEX to Pantone | `external-api/tools/designer.php` | Partial / unverified — reference/palette source and mapping not validated. |
| Designer | `cmykToPantone` | CMYK to Pantone | `external-api/tools/designer.php` | Partial / unverified — reference/palette source and mapping not validated. |
| Designer | `hsvToPantone` | HSV to Pantone | `external-api/tools/designer.php` | Partial / unverified — reference/palette source and mapping not validated. |
| Webmaster | `websiteLinkAnalyzer` | Website Link Analyzer | `external-api/tools/webmaster.php` | Partial / unverified — live crawl and link classification not tested. |
| Webmaster | `userAgentChecker` | User Agent Checker | `external-api/tools/webmaster.php` | Partial / unverified — parser behavior not individually checked. |
| Webmaster | `pageRankChecker` | PageRank Data (Unavailable) | `external-api/tools/webmaster.php` | UI-only/unavailable — deliberately returns no PageRank or substitute score. |
| Webmaster | `punycodeConverter` | Punycode Converter | `external-api/tools/webmaster.php` | Partial / unverified — conversion edge cases not individually checked. |
| Webmaster | `serpSimulator` | SERP Simulator | `external-api/tools/webmaster.php` | Partial / unverified — preview must not be represented as live Google results. |
| Webmaster | `robotsGenerator` | Robots.txt Generator | `external-api/tools/webmaster.php` | Partial / unverified — generated content not individually checked. |
| Network | `portChecker` | Port Checker | `external-api/tools/network.php` | Partial / unverified — mocked guard checks pass; live reachability unverified. |
| Network | `macLookup` | MAC Lookup | `external-api/tools/network.php` | Partial / unverified — OUI data provenance/coverage unverified. |
| Network | `macGenerator` | MAC Generator | `external-api/tools/network.php` | Partial / unverified — output semantics not individually checked. |
| Network | `asnWhois` | ASN WHOIS | `external-api/tools/network.php` | Partial / unverified — registry transport/data unverified. |
| Security | `sslChecker` | SSL Checker | `external-api/tools/security.php` | Partial — public-only pinned TLS metadata retrieval is regression-tested; no live handshake. Trust/hostname verification and an overall grade/score are intentionally not claimed. |
| Security | `passwordEncrypt` | Password Encrypt | `external-api/tools/security.php` | Partial / unverified — confirm non-reversible hashing vs encoding; don't present as password-storage guidance without review. |
| Security | `passwordGenerator` | Password Generator | `external-api/tools/security.php` | Partial / unverified — entropy/output behavior not individually checked. |
| Security | `passwordStrength` | Password Strength | `external-api/tools/security.php` | Partial / unverified — scoring/feedback not individually checked. |
| Productivity | `loremIpsum` | Lorem Ipsum | `external-api/tools/productivity.php` | Partial / unverified — output limits not individually checked. |
| Productivity | `timeCard` | Time Card | `external-api/tools/productivity.php` | Partial / unverified — overnight/break/overtime boundaries not tested. |
| Productivity | `binChecker` | BIN Checker | `external-api/tools/productivity.php` | Partial / unverified — provider/current BIN coverage not live-checked; never pass full card numbers. |
| Productivity | `creditCardValidator` | CC Validator | `external-api/tools/productivity.php` | Partial / unverified — validate only format/checksum locally; do not collect or submit real card numbers. |
| Productivity | `reverseImageSearch` | Reverse Image | `external-api/tools/productivity.php` | Partial / unverified — no provider result/permission verified; don't claim a search occurred. |
| Productivity | `usernameChecker` | Username Format & Profile Links | `external-api/tools/productivity.php` | UI-only/limited — description says format check and profile links, not provider availability. |
| Productivity | `notepad` | Notepad | `external-api/tools/productivity.php` | Partial / unverified — storage/privacy behavior not individually checked. |
| Productivity | `smallText` | Small Text | `external-api/tools/productivity.php` | Partial / unverified — Unicode/output behavior not individually checked. |
| Productivity | `wordCounter` | Word Counter | `external-api/tools/productivity.php` | Partial / unverified — tokenization/counting rules not individually checked. |
| Productivity | `rot13` | ROT13 | `external-api/tools/productivity.php` | Partial / unverified — conversion edge cases not individually checked. |
| Productivity | `morseCode` | Morse Code | `external-api/tools/productivity.php` | Partial / unverified — punctuation/timing conventions not individually checked. |
| Productivity | `bimiChecker` | BIMI | `external-api/tools/productivity.php` | Partial / unverified — verify generator vs checker scope and live DNS behavior. |
| Productivity | `imageToText` | Image to Text | `external-api/tools/productivity.php` | Partial / unverified — OCR provider/key, consent, file limits, and retention need deployment verification. |
| Gaming | `minecraftColorCodes` | MC Color Codes | `external-api/tools/gaming.php` | Partial / unverified — rendering/escaping behavior not individually checked. |
| Gaming | `minecraftFormatCodes` | MC Format Codes | `external-api/tools/gaming.php` | Partial / unverified — rendering/escaping behavior not individually checked. |

Do not enable both Tools Center and the public Tools addon without an explicit product decision.

## 5. Existing domain, WHOIS, SSL, and hosting workflows

- `domain-search.php` runs the real registry-backed domain search, pricing/suggestions, and existing WHMCS cart/checkout links through `DomainSearchService` and related services. `bulk-domain-search.php`, `domain-transfer.php`, `tld-directory.php`, `domain-valuation.php`, `domain-auctions.php`, `discount-domain-club.php`, `whois-lookup.php`, and `domain-broker.php` are separate workflows with module-backed services; they are not substitutes for a generic web-tool route.
- `whois-lookup.php` uses CSRF, `WhoisService`, cache/rate controls, and keeps registry privacy-redacted placeholders rather than claiming hidden registrant data.
- `ssl-certificate.php` is a WHMCS sales/landing page; `/tools/ssl-checker` is the TLS metadata utility with narrower guarantees.
- Core hosting/domain pages, WHMCS login, checkout, and administration flows were not edited; Tools Center/public-tools addon client-area routes were edited only for the tool-specific fixes described above. No integration/build/deployment run against WHMCS was possible. Do not treat a passing unit suite as evidence that the real cart or login workflow is live.

## 6. Security, auth, privacy, and operations

### `CloudHost247_tools`

- Central `includes/Security.php` validates public host/URL targets, rejects private/reserved IPv4/IPv6 and internal names, resolves and pins outbound addresses, revalidates manual redirects, limits response bytes/timeouts, verifies TLS certificates for HTTP fetches, and maintains a diagnostic-port allowlist.
- `includes/RateLimiter.php`, `includes/Runner.php`, and the front/API entry points implement request limits, CSRF, enablement checks, safe error envelopes, client-only execution refusals on the server, and opt-in result logging. The IP resolver trusts forwarded IP headers only for configured trusted proxies.
- `Runner::redact()` masks sensitive fields and drops CSRF tokens; the log tests assert that tool results are not retained. OCR images are validated in memory and require an explicit server-side opt-in; no persistent image storage is introduced.
- Recent output work removed automatic `window.open()` behavior from reverse-image links; URLs are restricted to safe HTTP(S) links and opened with `noopener noreferrer`. Renderer output for coordinates, traceroute hops, OS guess, page rank, and external links is escaped or status-limited.
- Resolver hardening on 2026-10-12: the misnamed “IP & Email Blacklist Checker” is now labelled “IPv4 Blacklist Checker”; IPv6, private, and reserved inputs are rejected before DNSBL lookups. Shared domain validation and `CloudHost247_tools_dns_query()` now refuse local/special-use suffixes before resolver access; PTR reverse lookup is public-IP-only; the email verifier reports local/special-use domains as unconfirmed without querying; and IPv6 compatibility skips local names returned as NS/MX targets. Tests cover these paths without live DNS. Email reputation remains out of scope, and the 11 hard-coded DNSBL zones still need owner/source review and live validation.

### `tools_center`

- Current checks cover POST-only API behavior, exact-origin allowlisting, server-side API token use, signed HMAC freshness/nonce replay protection, fail-closed placeholder secrets, session CSRF before the same-origin proxy call, rate limits, non-instantiating cache reads, and non-echo of internal exception details.
- Follow-up security review on 2026-10-12 found and fixed host-directed transport bypasses: `ping`/`traceroute` accepted non-public targets and let the OS re-resolve hostnames; IP geolocation used direct cURL with redirects enabled; IP/ASN WHOIS used raw sockets; and the SSL checker used a direct TLS socket without public-address validation. Commands now use a validated public numeric address; GeoIP uses the shared pinned fetch helper with redirects disabled; WHOIS uses guarded sockets with read timeouts/size bounds; SSL inspection uses a pinned public TLS socket with hostname SNI. Positive bounds are enforced on command counts/hops/timeouts; DNS propagation accepts only a fully qualified public DNS name and A/AAAA/MX/NS/TXT, queries a fixed public-resolver list, and bounds `dig` retries. The SSL checker now explicitly reports metadata-only status instead of its previous unsupported grade/score; it still does not validate certificate trust/hostname. GeoIP output mappings now match the consuming tools, and ISP lookup reports VPN status as unknown rather than deriving a false negative.
- A caller-controlled resolver audit then confirmed local/special-use DNS disclosure paths. `outbound.php` now validates public DNS names without resolving first and rejects single-label, RFC special-use, and common local suffixes; DNS-tools record queries pass through name-checked seams; CNAME, NS, and MX targets in local namespaces are not recursively queried; reverse PTR, DNSBL, and ASN-by-IP paths reject non-public IPs; `SearchTools` and BIMI validation reject local-use domains before lookup. Regressions use synthetic DNS fixtures only. The same namespace policy now covers the active `CloudHost247_tools` validator/DNS helper and `DomainBroker\Core\DomainName`; the retired `hostx_tools` DNS input validator was also hardened in source, but actual WHMCS deactivation remains pending. Offline evidence: Tools Center **28/28** host-directed DNS/HTTP/TLS cases and **89/89** shared outbound guard cases; CloudHost247_tools **923/923**; DomainBroker **1,036/1,036**; hostx_tools **39/39**; domain search **20/20**. No live command, DNS lookup, socket, or provider request was made.
- **Open security item:** `external-api/outbound.php` uses the safe-host resolver/pinned redirect path but its cURL options do not explicitly set `CURLOPT_SSL_VERIFYPEER`/`CURLOPT_SSL_VERIFYHOST`; HTTP is also accepted. The repository's older tracker records this as T-8/accepted, not as verified secure transport. GeoIP retains the existing `ip-api.com` HTTP endpoint (the existing free endpoint/provider was not changed). Reassess before production API deployment; do not silently change an owner-approved transport policy.
- The external API requires deployment-supplied `TOOLS_API_TOKEN` (and `TOOLS_API_SECRET` for signed HMAC), exact allowed origins only if browser calls are enabled, private cache/log/temp storage, HTTPS, and web-server restrictions per `INSTALL.md`. The docs describe a separate API host; this repository does not prove that the API is deployed or correctly configured.

### DomainBroker and retired legacy DNS modules

- `DomainBroker\Core\DomainName::normalise()` now rejects local/special-use namespaces before `DomainIntelService` reaches DNS or the optional RDAP resolver. An injected-resolver regression confirms a blocked name produces zero resolver callbacks; the full DomainBroker PHP-WASM suite passed **1,036/1,036**.
- The retired `hostx_tools` addon now rejects common local/special-use suffixes and private/reserved PTR owners before `DnsTool` can call the system resolver; public IP literals are normalized to reverse-DNS owner names only for PTR requests (27 namespace/PTR address checks plus 6 mocked lookup-flow checks; 39/39 combined addon checks). This source fix does not deactivate an installed copy; WHMCS deactivation is still pending and remains a deployment risk.

## 7. Configuration, dependencies, build, and deployment

- There is no root `package.json`, root `composer.json`, or React/API build here. The checkout is a WHMCS/PHP application. Several individual addons have their own Composer or Node manifests; `cloudhost247services/package.json` includes `@php-wasm/node` for local PHP-WASM test execution. No production dependency was added by this audit.
- The repo CI workflow (`.github/workflows/ci.yml`) runs PHP 8.3 module suites, a PHP 7.4 compatibility lane for declared legacy modules, Node 22 tests, an ionCube-excluded PHP syntax sweep, and Python static checks. It is CI configuration evidence, not proof a deployment completed.
- `CloudHost247_tools` public routes require the `.htaccess` rewrite to be deployed before WHMCS's catch-all rewrite; the addon must also be enabled/configured. Optional APIs are configured independently; current provider/live status was not checked.
- `tools_center/INSTALL.md` requires separate external API hosting or an out-of-web-root placement, WHMCS endpoint/token configuration, TLS, and protected runtime directories. No service/account/user system was added.
- No archive files are checked into this working tree. Historical imported archive provenance and duplicate resolution are recorded in `docs/MODULES.md`.

## 8. Test and build evidence (this audit pass)

All results below are offline, mocked, or local DOM-shim tests unless explicitly stated. No live provider, production DB, WHMCS login/cart, or browser viewport was tested.

| Check | Result |
|---|---:|
| `node modules/addons/CloudHost247_tools/tests/run.mjs` on PHP-WASM 8.3 | 923 passed, 0 failed; 16 PHP test files (includes local/special-use DNS, private PTR/DNSBL, and NS/MX target regressions) |
| `node modules/addons/CloudHost247_tools/tests/core.test.mjs` | 68 passed, 0 failed |
| `node modules/addons/CloudHost247_tools/tests/qr.test.mjs` | 36 passed, 0 failed |
| `node modules/addons/CloudHost247_tools/tests/tools.test.mjs` | 867 passed, 0 failed; 46 client `run()` functions executed, 45 server render functions exercised |
| Tools Center `run-tests.js` with test-only jsdom 24 (`npm --no-save`, removed afterward) | 12/12; 9 static checks (including the 81-action handler and outbound-transport crosswalks) + 3 UI wiring checks |
| Tools Center PHP-WASM host-directed/DNS regressions | 28/28; mocked commands, provider, WHOIS, TLS, DNS records and resolver dispatch; public-only PTR/DNSBL/ASN; local CNAME/NS/MX targets are not queried |
| Tools Center PHP-WASM outbound guard | 89/89; includes public DNS-name validation and TLS socket rejection checks |
| Tools Center PHP-WASM auth/security checks | 37/37 |
| Tools Center PHP-WASM domain-search checks | 20/20; local-use suffixes rejected before resolver/WHOIS callbacks |
| DomainBroker PHP-WASM full suite | 1,036/1,036; in-memory DB and injected resolver; blocked names make no resolver callbacks |
| Retired hostx_tools PHP-WASM tests | 39/39; proxy-aware client IP, local/special-use DNS guards, public-only PTR/IP reverse-name validation, and mocked resolver dispatch |
| Tools Center PHP-WASM client-area route checks | 4/4 |
| `templates/hostx/tests/announcementbar.test.mjs` | 43/43 |
| `node --check` on changed JavaScript files and relevant JS test runners | Passed |
| PHP syntax parsing | Prior audit: 34 changed/new PHP files parsed with `php-parser` (PHP 8.3 grammar); current Tools Center PHP-WASM security suite runs `TOKEN_PARSE` over `ip.php`, `network.php`, and the new outbound regression test | Prior 34 clean; current suite passed |
| `git diff --check` | Passed |

Test-only `jsdom` was installed with `--no-save` for the UI run and removed afterward; PHP-WASM dependencies were reached through temporary sibling symlinks, also removed. No package manifest, lockfile, or production dependency was added. The PHP syntax checks ran through PHP-WASM, not native `php -l`; CI's repo-wide native lint gate remains authoritative. These results do not cover a browser console, keyboard/assistive-technology run, desktop/tablet/mobile screenshots, real file/image APIs, full per-tool malformed/timeout/privacy matrices, or live integrations.

## 9. Salario reference inventory (current public pages, 2026-10-12)

The Salario tools directory reported **49 tools: 5 calculators, 12 generators, 4 comparisons, and 28 guides**. Guides are a separate content category. The directory's first rendered page showed entries 1–6 (five calculators and Job Description Generator) and displayed pagination links 1–9. A fresh check on 2026-10-12 found `/tools`, `?page=2`, `?page=3`, `?category=Generators`, and `?category=Generators&page=2` all returning the same first six instead of advancing/filtering. The sitemap lists 11 clear generator routes plus two LinkedIn-Engage candidate routes; direct pages expose titles, but neither source establishes the category membership/canonical status of the 12th generator. **Do not silently treat an inferred 12th generator as verified.**

### Calculators (5)

| Directory label (live listing) | Direct page heading/title variant | Route |
|---|---|---|
| PAYE & Net Salary Calculator | Nigeria PAYE & Net Salary Calculator (2026 Tax Law) | [`/tools/paye-tax-calculator`](https://www.salariopay.com/tools/paye-tax-calculator) |
| Pension Calculator | H1: Nigeria Pension Calculator (PenCom Compliant); SEO title: Nigeria Pension Calculator (PenCom Rules) \| Salario | [`/tools/pension-calculator`](https://www.salariopay.com/tools/pension-calculator) |
| VAT Calculator | Nigeria VAT Calculator (7.5% Tax Rate) | [`/tools/vat-calculator`](https://www.salariopay.com/tools/vat-calculator) |
| Employer Cost Calculator | Nigeria Employer Cost & Payroll Overhead Calculator | [`/tools/employer-cost-calculator`](https://www.salariopay.com/tools/employer-cost-calculator) |
| EWA ROI Calculator | Earned Wage Access (EWA) ROI & Savings Calculator | [`/tools/ewa-roi-calculator`](https://www.salariopay.com/tools/ewa-roi-calculator) |

### Comparisons (4)

| Current public page title/name | Route |
|---|---|
| Accounting Software Payroll Comparison in Nigeria (H1: Nigeria Payroll & Accounting Comparison) | [`/tools/accounting-tool-comparison`](https://www.salariopay.com/tools/accounting-tool-comparison) |
| Expense Management & Reimbursement Tool Comparison in Nigeria (H1: Nigeria Expense Tool & Payout Comparison) | [`/tools/expense-tool-comparison`](https://www.salariopay.com/tools/expense-tool-comparison) |
| HMO Comparison in Nigeria for Employers (2026) | [`/tools/hmo-comparison-nigeria`](https://www.salariopay.com/tools/hmo-comparison-nigeria) |
| PFA Comparison in Nigeria \| Choose a Pension Administrator (page title; H1: “Find a PFA that fits your priorities”) | [`/tools/pfa-comparison-nigeria`](https://www.salariopay.com/tools/pfa-comparison-nigeria) |

### Generator pages/routes verified directly (11 clear generator routes + one category-unverified candidate)

| Public page name | Route | Verification note |
|---|---|---|
| Free AI Job Description Generator & Template Creator | [`/tools/job-description-generator`](https://www.salariopay.com/tools/job-description-generator) | Direct page; preview/copy/TXT/PDF controls described. |
| Free Organizational Chart Generator | [`/tools/free-organizational-chart-generator`](https://www.salariopay.com/tools/free-organizational-chart-generator) | Direct page; manual, CSV/XLS and AI-assisted editable chart claims. |
| Free Employee ID Card Generator | [`/tools/free-employee-id-card-generator`](https://www.salariopay.com/tools/free-employee-id-card-generator) | Direct page; claims 12 templates, QR/Code128, CSV/Excel bulk and PNG/JPG/PDF. Not interacted with or tested. |
| Free Employee ID Number Generator | [`/tools/free-employee-id-generator`](https://www.salariopay.com/tools/free-employee-id-generator) | Direct page; prefix/location/sequence and bulk-export controls described. |
| Free Business Invoice Generator | [`/tools/free-business-invoice-generator`](https://www.salariopay.com/tools/free-business-invoice-generator) | Direct page; form/preview, local draft, tax and export claims. |
| Free Custom Job Offer Letter Generator | [`/tools/job-offer-letter-generator`](https://www.salariopay.com/tools/job-offer-letter-generator) | Direct page; candidate/terms/company fields and Word/PDF controls described. |
| Free Professional Payslip Generator | [`/tools/official-payslip-generator`](https://www.salariopay.com/tools/official-payslip-generator) | Direct page; single/bulk flow, bank details, deductions and PDF/email controls described. |
| Free NDA Contract Generator & Non-Disclosure Agreement Creator | [`/tools/nda-contract-generator`](https://www.salariopay.com/tools/nda-contract-generator) | Direct page; unilateral/mutual clauses, party fields, Word/PDF controls described. |
| Free Custom Employment Contract Generator | [`/tools/employment-contract-generator`](https://www.salariopay.com/tools/employment-contract-generator) | Direct page; 2026 PAYE summary and editable contract/export controls described. |
| Free AI CV Generator & Resume Builder | [`/tools/cv-generator`](https://www.salariopay.com/tools/cv-generator) | Direct page; local builder; Google account connection shown as disconnected. |
| Free Professional Business Card Generator & Designer | [`/tools/business-card-generator`](https://www.salariopay.com/tools/business-card-generator) | Direct page claims 10 premium templates while the extracted template selector enumerates Styles 1–35; reference content is internally inconsistent. |
| LinkedIn Engagement Assistant / Salario Engage — candidate only | [`/tools/linkedin-engagement-assistant`](https://www.salariopay.com/tools/linkedin-engagement-assistant) and [`/tools/salario-engage`](https://www.salariopay.com/tools/salario-engage) | Both direct pages show the same title/content and interactive-looking draft/re-engagement workspace; category placement and canonical-vs-alias relationship were not exposed by the directory. Do **not** count it as the confirmed 12th generator until the live directory/owner confirms. |

The pages are reference-only; no button, account connection, output, export, file handling, or comparison filter was exercised. The directory count is not enough to verify all generator category names/routes because pagination did not advance in the retrieved page.

### Statutory/data-source caution

The live PAYE page claims the Nigeria Tax Act 2025 is effective 2026-01-01 and publishes bands/reliefs; the pension page states 8% employee and at least 10% employer on basic+hous­ing+transport; the VAT page states 7.5% and an effective date of 2020-02-01. The employer-cost, payslip, and employment-contract pages add statutory threshold/benefit claims. These statements have **not** been independently validated against current primary Nigerian legal/regulator sources in this audit and must not be copied as legal truth or used in a production calculator without a source, effective date, assumptions, formulas, tests, and legal/tax disclaimer.

The EWA page's sample uses a configurable turnover-reduction assumption and advertises 20% turnover reduction and “infinite ROI” based on a zero-employer-fee claim. Treat those as vendor-model assumptions, not verified market facts or guaranteed savings. The HMO page labels price ranges as planning estimates, asks employers to obtain quotes, links to the NHIA HMO register, and states Salario is not an HMO. The PFA page labels star ratings editorial rather than PenCom ratings and points readers to PenCom. Accounting/expense pages make broad product-capability, integration, and tax-treatment assertions that need direct vendor/regulator substantiation. Preserve visible dates, direct citations, and estimate/editorial labels if implementing comparable data.

## 10. Requested native tools: status in this checkout

| Request | Current WHMCS checkout status |
|---|---|
| Salario's five calculators, twelve generators, four comparisons | Not present as these products/routes. Existing WHMCS tools do not implement them. Exact Salario generator catalogue has one unresolved category/canonical-route question. |
| Employee ID card designer | Not present. No 12-template card editor, company/employee field model, safe card image workflow, local QR/Code128, bulk spreadsheet preview/export/report, or print-quality card export exists in the WHMCS tools catalogs. |
| MRZ synthetic test utility | No MRZ route/handler in this checkout. No document image/template or identity verification feature should be added here. A historical native-app note is not current evidence. |
| All 89 DNSChecker-inspired tools | Concept coverage exists in the 91-route `CloudHost247_tools` catalog, with documented partial/provider/unavailable cases above. This is a WHMCS PHP tool suite, not the requested native React/API target, and its offline tests do not meet the full live/per-tool acceptance bar. |
| Shared accessible responsive directory | `/tools/*` exists for `CloudHost247_tools`; `tools_center` and `hostx_tools` remain separate/overlapping client-area products. No shared canonical registry was implemented across them, and responsive/accessibility behavior was not browser-tested. |

## 11. Open defects and decisions

1. **Native implementation hand-off:** the user confirmed `windelslife-wq/ai/apps/workforce-platform` as the intended Node.js/React/API target on 2026-10-12. This Arena session is fixed to the CloudHost247 WHMCS branch, and the workforce repo is not mounted here; no files there were changed. Continue native implementation in a separate session with that repo mounted. Do not implement the missing native feature set in this PHP repository or introduce a second backend/user system here.
2. **Salario catalog blocker:** the live tools page reports 12 generators, but `/tools`, `?page=2`, `?page=3`, and generator-category queries repeat the first six. The sitemap shows 11 clear generator routes and two duplicate-looking LinkedIn Engage routes; their category/canonical status is unresolved. The user selected owner/source confirmation rather than guessing. Obtain the full current list/category data before accepting the 12-route inventory.
3. **Statutory-source blocker:** confirm Nigerian 2026 tax, pension, VAT, NHF/NHIS/ITF/NSITF/group-life assumptions from current primary sources; capture source URL, effective date, formula, eligibility boundary, and test vectors before any financial calculation is shipped.
4. **Tools Center transport risk:** decide whether to explicitly enable TLS certificate/hostname verification and disallow plain HTTP for outbound fetches. The existing tracker marks the issue accepted; this audit does not silently reverse that decision.
5. **Legacy addon operations:** deactivate `hostx_tools` in WHMCS per D-2; source-only fixes do not remove its production surface.
6. **Browser/production validation:** before calling the broader request complete, run the configured WHMCS install and real browsers at desktop/tablet/mobile widths, exercise every visible control and negative/error path, inspect console/server logs, verify actual providers, and capture screenshots/recordings. None of this was possible here.

## Sources consulted

- DNSChecker reference directory: <https://dnschecker.org/all-tools.php> (89 tools; 3-page directory, read in the prior audit pass).
- Salario directory: <https://www.salariopay.com/tools> and the non-advancing page query <https://www.salariopay.com/tools?page=2>.
- Salario sitemap: <https://www.salariopay.com/sitemap.xml> (candidate routes, not a complete category inventory).
- Selected direct calculator, comparison, and generator URLs are linked in §9.
- Repository-level module provenance/deployment: [`MODULES.md`](MODULES.md), [`CI.md`](CI.md), [`UNFINISHED_MODULES.md`](UNFINISHED_MODULES.md), [`MODULE_COMPLETION_TRACKER.md`](MODULE_COMPLETION_TRACKER.md).
