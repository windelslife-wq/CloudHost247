# CloudHost247 — Digital Products Marketplace

## Audit findings, rebuild plan, security hardening & definition of done

**Repository:** `windelslife-wq/CloudHost247` (this checkout)
**Working branch:** `arena/c52c742a-cloudhost247`
**Module under work:** `modules/addons/digitalproducts/` — **upgrade in place, no second module**
**Status of this document:** work brief. Nothing below has been implemented yet; §3 is the
result of an actual read of the shipped code, not an assumption.

---

## 0. Read this before writing any code

Three facts about this repository change how the work must be done. The generic
"build me a WHMCS digital products module" brief (preserved at
`docs/WHMCS Digital Product Module/Build.txt`) does not account for them.

### 0.1 This repo is an overlay, not a WHMCS installation

`README.md` states it plainly: the tree is **copied on top of** a standard WHMCS install.
`init.php`, `clientarea.php`, `includes/` core libraries, `vendor/`, `templates/six` and
`templates/orderforms/standard_cart` are **not in this repository**.

Consequences:

* "Do not modify WHMCS core files" is not a rule to follow — it is physically impossible
  here, and any change that *requires* a core edit is out of scope by construction.
* `require_once __DIR__ . '/../../../init.php'` resolves only on a deployed host. Nothing in
  this repo can be executed end-to-end against real WHMCS. Everything that must be proven
  before merge has to be provable **offline** (see §14).
* Deployment is `rsync -av --exclude '.git' --exclude 'docs' ./ /path/to/whmcs/`, then
  activating the addon from **Configuration → Addon Modules**.

### 0.2 There is no "CloudHost247 Foundation" package

The original brief refers to `modules/addons/cloudhost247_core`,
`cloudhost247_integrations`, `cloudhost247_modules`, `cloudhost247_marketing`, a shared
audit service, a capability system and a `templates/cloudhost247/` theme. **None of these
exist.** Mapping brief → reality:

| Brief says | Actually in this repo |
|---|---|
| `modules/addons/cloudhost247_core` (Foundation) | No such module. The closest thing is `modules/addons/cloudhost247services` (`Chs\` namespace) with `lib/Core/{Audit,Csrf,Db,Identity,Logger,Migrator,RateLimiter,Settings,Validator,Platform}.php` |
| `cloudhost247_integrations` (credential vault) | No such module. Provider credentials live in each module's own `tbladdonmodules` settings; `Chs\Core\Settings` supports env-var overrides (`CHS_*` prefix) and a `SECRET_KEYS` list |
| `cloudhost247_modules` (Module Manager) | No such module. `docs/MODULES.md` is the inventory |
| `cloudhost247_marketing` | No such module |
| Shared CloudHost247 audit infrastructure | Per-module: `Chs\Core\Audit`, `DomainBroker\Core\Audit` — **each addon ships its own** |
| `templates/cloudhost247/`, `templates/cloudhost247_legacy/` | `templates/hostx/` (HostX theme) and `templates/orderforms/{hostx,ovh_cart}/` |
| CloudHost247 capability strings (`digitalproducts.products.manage`) | No capability system exists. WHMCS addon **Access Control** roles + a per-module RBAC helper (`DomainBroker\Core\Rbac`) is the established pattern |
| `CH247_MODULE_STORAGE` constant | Does not exist. Convention is a module-local `storage/` with `.gitkeep`, gitignored contents (see `modules/addons/domainbroker/storage/`) |

So the instruction "reuse the foundation, do not duplicate" translates, here, into:
**follow the established per-module architecture and copy its class names/APIs so the code
stays recognisable — do not invent a third, different one, and do not take a hard runtime
dependency on another addon that an operator may have disabled.**

### 0.3 The reference implementations to copy

Two addons in this repo were written for it and define the house style. Match them.

```
modules/addons/domainbroker/          ← closest analogue: commerce + money + API + RBAC
├── autoload.php                      PSR-4 for DomainBroker\, guard constant, optional vendor/
├── domainbroker.php                  _config / _activate (migrator) / _deactivate (no drops) / _output
├── hooks.php
├── cron/domainbroker.php
├── install/migrations/0001_…php …    numbered, additive, idempotent
├── lib/Core/                         Audit Blueprint Clock Crypto Csrf Db DomainName Exceptions
│                                     Http Idempotency Identity Logger Migrator Money RateLimiter
│                                     Rbac Settings Str Validator
├── lib/Http/                         AdminPortal BrokerDesk Controller CustomerPortal Html View
├── lib/Api/                          ApiRequest ApiResponse Router Presenter Controllers/
├── lib/Services/, lib/Workflow/, lib/Escrow/, lib/Integration/
├── templates/client/
├── storage/.gitkeep
└── tests/   bootstrap.php 01_…Test.php … lint.php lint.mjs (+ run.mjs in cloudhost247services)

modules/addons/cloudhost247services/  ← HostX theme integration + php-wasm test runner
├── lib/Http/HostxMenu.php            merges entries into the theme's $topMenusData
├── hooks.php                         ClientAreaPrimaryNavbar(1), ClientAreaPage(90), InvoicePaid(1), DailyCronJob(1)
└── tests/run.mjs                     runs the PHP suites on php-wasm (no native PHP needed)
```

`modules/addons/CloudHost247_tools/tests/` adds `RateLimiterTest.php`, `SecurityTest.php`,
`RouterTest.php` — useful shapes for §14.

**There is no native `php` binary in this workspace.** `node` v22 is available; the suites run
through `@php-wasm/node` exactly as `cloudhost247services/tests/run.mjs` does. Note that
`node_modules` under those modules is gitignored.

### 0.4 `.gitignore` constraints that affect this work

```
*.zip  *.tar  *.tar.gz  *.tgz  *.rar      ← no archive may ever be committed
modules/*/*/logs/  modules/*/*/cache/
modules/addons/domainbroker/storage/*  !…/storage/.gitkeep
```

Therefore: **test fixtures must be generated at runtime** (build a ZIP in a temp dir inside the
test), never committed. Add matching ignore rules for
`modules/addons/digitalproducts/storage/*` with a `!…/.gitkeep` exception.

---

## 1. What the module is for

`modules/addons/digitalproducts/` sells downloadable products through the existing WHMCS
order/payment lifecycle: WHMCS modules and addons, provisioning modules, WordPress plugins
and themes, PHP scripts, JS/CSS packages, HTML and SaaS templates, documentation and other
administrator-approved files.

An administrator must be able to: link a digital product to a WHMCS product, upload versions,
publish/retire versions, manage entitlements and licences, see download activity, and audit
every administrative action. A customer must be able to: buy, get access automatically on
payment, see the product under **My Downloads** in the HostX client area, download it over a
one-time expiring token, receive later versions, view their licence, and validate that licence
from the product itself over the API.

Nothing about pricing, checkout, invoicing, refunds or tax belongs in this module. WHMCS owns
all of it; the module reads the authoritative state and reacts.

---

## 2. Current state of `modules/addons/digitalproducts/`

```
modules/addons/digitalproducts/
├── README.md                        305 lines   (authored as "Your Company" / yourcompany.com)
├── digitalproducts.php              296 lines   config/activate/deactivate/upgrade/output/sidebar
├── digitalproducts_clientarea.php    42 lines
├── hooks.php                        444 lines   OrderPaid, AfterModuleCreate, ClientAreaPrimarySidebar,
│                                                DailyCronJob, AdminAreaHeadOutput + email helpers
├── download.php                     213 lines
├── api.php                          214 lines
├── lib/Core.php                     455 lines
├── lib/Admin.php                    787 lines
├── lib/Client.php                   132 lines
├── lib/License.php                  206 lines
└── templates/client/downloads.tpl    35 lines
```

Tables created by `digitalproducts_activate()`:
`mod_digitalproducts_products`, `_files`, `_licenses`, `_downloads`, `_api_tokens`.

**Absent:** `install/migrations/`, `autoload.php`, any entitlement table, any download-token
table, a versions table distinct from `_files`, a storage abstraction, CSRF, RBAC, rate
limiting, a cron entry point, tests, and any HostX theme integration beyond one sidebar hook.

The brief's claimed structure (`lib/Storage/`, `lib/Security/`, `lib/Services/`,
`lib/Admin/AdminController.php`, `migrations/V100.php`…) does not exist. The brief's claim that
the module "already contains" working purchase activation, secure downloads and download
limits is **optimistic** — see below.

---

## 3. Audit findings (read of the shipped code)

Severity: **P0** = exploitable or broken in production, fix before anything else.
**P1** = correctness/data-safety. **P2** = architecture/quality.

### P0-1 — Cross-product IDOR: any customer can download any file

`download.php:93` checks only that the *service* belongs to the client
(`Core::validateServiceOwnership()` → `tblhosting.id = ? AND userid = ? AND domainstatus='Active'`).
`download.php:110` then loads `getFileById($downloadFileId)` with **no check that the file
belongs to the digital product behind that service**. A customer with any one active digital
service can POST an arbitrary `file_id` (`download.php:31`) and receive any other product's
file. The required invariants — `service.userid == auth client`, `service.packageid ==
digitalproduct.whmcs_product_id`, `file.product_id == digitalproduct.id` — are never all
asserted together.

### P0-2 — Download tokens are session-only, so emailed links can never work

`Core::generateToken()` (`lib/Core.php:230`) writes the token into
`$_SESSION['dp_download_'.$token]` and nothing else. `Core::validateToken()` (`:249`) reads
only that session key. A token therefore dies with the session, cannot be single-use, is not
hashed at rest, and **cannot be validated from a different browser or from an email link**.

### P0-3 — Emailed download links are logged as completed downloads

`hooks.php:414 digitalproducts_generateDownloadToken()` generates a token and inserts it into
the **download log** table with `status = 'success'`, `client_id = 0`, `product_id = 0` —
there is no token store, so minting a link records a fake successful download. Combined with
P0-2, the post-purchase email link is both broken and corrupts the download counters and the
audit trail it is supposed to feed.

### P0-4 — No CSRF protection and no authorisation on any admin action

`lib/Admin.php` dispatches on raw `$_POST` keys — `delete_product` (:694), `delete_file`
(:703), `set_active` (:730), `create_product` (:604), `save_product` (:615), upload (:637),
`save_settings` (:749) — with **zero** CSRF token and **zero** permission check beyond WHMCS
having rendered the page. Any authenticated admin, and any attacker who can make an admin's
browser issue a POST, can delete products and files.

### P0-5 — API: wildcard CORS over cookie auth, plaintext tokens, no rate limiting

`api.php:22` sends `Access-Control-Allow-Origin: *` while `api.php:61` accepts
`$_SESSION['uid']` as authentication — any origin can read a logged-in customer's download and
licence data. Tokens are stored and compared in plaintext (`api.php:44`, non-constant-time),
accepted from the query string (`:37`, so they land in access logs and `Referer`), and
`validate-license` (`:150`) is unauthenticated and unthrottled, i.e. a licence-key oracle.

### P0-6 — Default storage is inside the web root, guarded by Apache 2.2 syntax

`Core::getStoragePath()` (`lib/Core.php:57`) defaults to `ROOTDIR . '/storage/digitalproducts'`
— under the public document root — and protects it by writing `.htaccess` containing
`Options -Indexes` + `deny from all`. That directive is **ignored by nginx entirely** and is
Apache 2.2 syntax (2.4 wants `Require all denied`). On a common nginx + PHP-FPM WHMCS host
every paid product is a public URL.

### P1-1 — Download limits are bypassable and racy

The limit block (`download.php:126`) runs only `if ($service)` — if the join finds no row the
limit is silently skipped. The count is a `SELECT COUNT(*)` (`lib/Core.php:183`) evaluated
before the insert, so N parallel requests all pass. No atomic counter, no `SELECT … FOR UPDATE`,
no unique-key guard.

### P1-2 — Entitlements do not exist; access is inferred from a live join

There is no entitlement record. `Core::getClientDownloads()` (`lib/Core.php:146`) derives the
library from `tblhosting.domainstatus = 'Active'`. Nothing records *what* was purchased, at
which version, under which access mode, or when access was revoked and why. Refund, fraud
cancellation, termination and suspension are never handled — the module reacts to none of
those hooks. One-time products that never produce an Active `tblhosting` row produce no
library entry at all.

### P1-3 — Purchase activation is not idempotent in any explicit way

`OrderPaid` (`hooks.php:25`) and `AfterModuleCreate` (`hooks.php:88`) both run licence
generation for the same service. The only thing preventing duplicates is a lookup inside
`License::generateLicense()` (`lib/License.php:39`); the schema's only unique key is
`license_key` itself — there is **no** unique constraint on `(service_id, product_id)`. Two
concurrent hook executions will create two licences. The e-mail is likewise sent from the
`OrderPaid` path with no delivery record, so a re-run re-sends.

### P1-4 — The daily cron silently destroys the download log

`hooks.php:155` deletes every `mod_digitalproducts_downloads` row older than 90 days,
unconditionally and unconfigurably. That is the audit trail for paid deliveries.

### P1-5 — Product deletion is destructive and unguarded

`Core::deleteDigitalProduct()` (`lib/Core.php:424`) `unlink()`s every file from disk, then
deletes downloads, licences, files and the product — even when customers hold live
entitlements, with no archive/retire path and no transaction.

### P1-6 — `download.php` references an undefined variable for its redirect

`download.php:54` uses `$WHMCS_CONFIG['SystemURL']`, which is never defined in that scope — the
unauthenticated redirect emits a malformed `Location:` header. `hooks.php:404` does it
correctly via `tblconfiguration`.

### P1-7 — No migration path

`digitalproducts_activate()` creates tables inside `if (!hasTable())` guards and
`digitalproducts_upgrade()` (`digitalproducts.php:217`) is an empty stub with a comment.
Any schema change after first install is unreachable. Deactivate correctly preserves data.

### P2-1 — Schema naming collides on `product_id`

In `_products`, `product_id` is the **WHMCS** product id. In `_files`, `_licenses`,
`_downloads`, `product_id` is the **module's** product id. Every join site has to remember
which. Rename to `whmcs_product_id` / `product_id` during migration.

### P2-2 — Client area is PHP `echo` output, not HostX

`lib/Client.php:53` builds Bootstrap 3 panel markup with `echo`, injected into
`templates/client/downloads.tpl` as a single `{$content}` blob with an inline `<style>` block.
It does not use the HostX theme's components, and the `ClientAreaPrimarySidebar` hook
(`hooks.php:136`) bails out unless a child literally named `My Account` exists. HostX renders
its navigation from `$topMenusData` (see `Chs\Http\HostxMenu`), which this never touches.

### P2-3 — Dead/incorrect imports and template bugs

`digitalproducts_clientarea.php:11` imports
`WHMCS\Module\Addon\DigitalProducts\Client as DigitalProductsClient` — a class that does not
exist — then instantiates `DigitalProducts\Client`. `digitalproducts_sidebar()`
(`digitalproducts.php:250`) interpolates `" . PHP_VERSION . "` inside a heredoc, so the admin
sidebar literally prints `" . PHP_VERSION . "`.

### P2-4 — No autoloader, no namespace discipline, branding placeholders

Every entry point `require_once`s `lib/*.php` by hand. Author metadata is `Your Company`,
`yourcompany.com`, `@copyright 2024`. Licence prefix is `DP-` (`lib/License.php:185`), not
`CH247-`.

### Still to verify on a staging WHMCS (cannot be checked from this repo)

* Whether `mod_digitalproducts_*` tables already carry production rows, and their live schema.
* Whether any customer currently holds a licence or has downloaded a file.
* Whether digital products are configured as one-time products (and therefore whether
  `tblhosting` rows exist for them at all).
* Which web server serves `/storage/` and whether files there are publicly fetchable **today**
  — if they are, that is an incident, not a backlog item.

---

## 4. Work rules

1. **One module.** Upgrade `modules/addons/digitalproducts/` in place. Do not create
   `digitalproducts_v2`, `downloadmarketplace`, `digitalmarket`, or a parallel table family.
2. **No new architecture.** Mirror `domainbroker`'s layout, class names and conventions so a
   maintainer who knows one module knows this one.
3. **No hard dependency on another addon.** `cloudhost247services` may be disabled. Ship the
   primitives under `DigitalProducts\Core\*`; if a shared foundation is ever extracted it will
   be a separate, repo-wide refactor.
4. **No WHMCS core edits** — hooks, addon module, client-area module, WHMCS API, Capsule only.
   (None of those files are even in this repo.)
5. **No demo data.** No seeded fake products, purchases, licences, prices or customers. When
   configuration is missing, render an explicit configuration state.
6. **No committed archives**, no secrets in code, nothing large added to Git.
7. **Preserve existing data.** Every schema change is an additive, re-runnable migration.
8. PHP **7.4-compatible** syntax (match the existing modules: no typed properties beyond 7.4,
   no `?->`, no enums, no named arguments), clean on 8.0–8.2, ionCube-environment safe. Do not
   encode this module.

---

## 5. Target structure

```
modules/addons/digitalproducts/
├── autoload.php                       PSR-4 DigitalProducts\ → lib/, guard constant
├── digitalproducts.php                _config, _activate (Migrator), _deactivate (no drops),
│                                      _upgrade (Migrator), _output, _sidebar
├── digitalproducts_clientarea.php     client-area entry point
├── hooks.php                          all add_hook() registrations, no business logic inline
├── download.php                       token-only download endpoint
├── api.php                            thin front controller → lib/Api/Router.php
├── cron/digitalproducts.php           token GC, entitlement revalidation, update notifications
├── install/migrations/
│   ├── 0001_baseline_existing_tables.php
│   ├── 0002_versions_from_files.php
│   ├── 0003_entitlements.php
│   ├── 0004_download_tokens.php
│   ├── 0005_licences_hardening.php
│   ├── 0006_download_log_columns.php
│   └── 0007_api_tokens_hashing.php
├── lib/
│   ├── Core/        Audit Clock Crypto Csrf Db Exceptions Idempotency Identity Logger
│   │                Migrator RateLimiter Rbac Settings Str Validator
│   ├── Storage/     StorageInterface LocalPrivateStorage StorageFactory
│   ├── Security/    TokenService DownloadAuthorizer UploadValidator
│   ├── Repositories/ ProductRepository VersionRepository EntitlementRepository
│   │                 LicenseRepository DownloadRepository TokenRepository
│   ├── Services/    ProductService VersionService EntitlementService LicenseService
│   │                DownloadService NotificationService
│   ├── Http/        Controller AdminPortal CustomerPortal View Html
│   ├── Api/         ApiRequest ApiResponse Router Presenter Controllers/
│   └── Workflow/    EntitlementStatus VersionStatus LicenseStatus
├── templates/
│   ├── admin/       *.phtml   (cloudhost247services renders admin views as .phtml)
│   └── client/      *.tpl     (Smarty, HostX components)
├── storage/.gitkeep           fallback only; real storage belongs outside the web root
├── tests/           bootstrap.php, 01…09 *Test.php, lint.php, lint.mjs, run.mjs
└── README.md
```

---

## 6. Data model and migrations

Final table set (prefix `mod_digitalproducts_`):

| Table | Purpose |
|---|---|
| `products` | digital product ← 1:1 WHMCS product |
| `versions` | releases (migrated from `files`) |
| `entitlements` | **new** — who may download what, and why |
| `licenses` | optional licence keys |
| `download_tokens` | **new** — hashed, expiring, optionally single-use |
| `downloads` | download log (success **and** every denial) |
| `api_tokens` | API credentials, hashed |

`products`

```
id, whmcs_product_id (unique), name, slug (unique), short_description, description,
product_type ENUM(module,plugin,theme,script,software,template,api,document,media,other),
status ENUM(draft,active,inactive,retired) default draft,
current_version_id NULL, access_mode ENUM(current_version,purchase_version) default current_version,
download_limit INT default 0 (0 = unlimited), download_expiry_hours INT default 48,
license_enabled TINYINT, license_expiry_mode ENUM(never,service,fixed),
created_at, updated_at
```

`versions` (replaces `files`; keep `files` only as a migrated-away legacy name)

```
id, product_id, version, storage_key, original_filename, file_size, checksum_sha256,
release_notes, changelog, min_php, max_php, min_whmcs, max_whmcs, required_extensions,
release_date, status ENUM(draft,active,disabled,retired), download_count,
created_at, updated_at
UNIQUE (product_id, version)
```

`entitlements`

```
id, product_id, whmcs_product_id, order_id, service_id, client_id,
purchase_version_id NULL, access_mode, status ENUM(active,suspended,revoked,expired),
download_limit NULL (per-entitlement override), downloads_used INT default 0,
revoked_reason NULL, purchased_at, expires_at NULL, created_at, updated_at
UNIQUE (client_id, service_id, product_id)          ← the idempotency key for §8
INDEX (client_id, status), INDEX (service_id), INDEX (product_id, status)
```

`download_tokens`

```
id, token_hash (unique, sha256 of the raw token), entitlement_id, version_id, client_id,
single_use TINYINT, expires_at, used_at NULL, created_ip_hash, created_at
INDEX (entitlement_id), INDEX (expires_at)
```

`licenses` — keep the table, add: `license_hash` (unique), `license_prefix`,
`license_encrypted` (only when the key must be re-displayed), `entitlement_id`,
`activation_limit`, `domain_limit`, and `UNIQUE (service_id, product_id)`.
Licence format becomes `CH247-XXXX-XXXX-XXXX-XXXX`; existing `DP-…` keys keep working
(migration hashes them in place and backfills the prefix).

`downloads` — add `entitlement_id`, `token_id`, `version_id`, `failure_reason`, and widen
`status` to `success, denied, expired, limit_exceeded, invalid_token, not_entitled,
file_missing`. Store `ip_hash` (salted) rather than the raw address where the operator opts in.
Never store the raw token, a licence key, or an API secret in this table.

Indexes to add: `client_id`, `product_id`, `whmcs_product_id`, `service_id`, `order_id`,
`status`, `version`, `token_hash`, `license_hash`, `created_at`, `expires_at`. Nothing else —
this table takes a write on every download attempt.

### Migration rules

* Numbered, additive, re-runnable, tracked in a migrations table, run from both `_activate()`
  and `_upgrade()` via `DigitalProducts\Core\Migrator` (copy `DomainBroker\Core\Migrator`).
* `0001` is a **baseline**: detect the five legacy tables, record them as already-applied, and
  change nothing. A fresh install and an existing install must converge on the same schema.
* `0002` copies `files` → `versions` (`file_path` → `storage_key`, `file_hash` →
  `checksum_sha256`), rewrites `products.current_file_id` → `current_version_id`, then
  **verifies row counts match** before dropping nothing. `files` is left in place, read-only,
  until a later release; never run both models simultaneously.
* `0003` backfills entitlements from existing `tblhosting` rows joined to linked products, so
  current customers do not lose access the moment the new authoriser goes live.
* `_deactivate()` keeps dropping nothing. There is no uninstall that destroys customer data.

---

## 7. Storage

* Interface `StorageInterface` { `put`, `stream`, `exists`, `size`, `checksum`, `delete` }.
  `LocalPrivateStorage` is the only implementation for now; S3 / R2 can follow without touching
  the services.
* Resolution order for the private root: module setting `storage_path` → env
  `DIGITALPRODUCTS_STORAGE` → `storage/` inside the module (last resort, with a loud admin
  warning).
* **Refuse to operate on a path inside the document root.** On activation and on settings save,
  verify the configured root is outside `ROOTDIR`, writable, and not web-reachable; surface a
  blocking admin notice if it is not. Write both `.htaccess` (`Require all denied` **and** the
  2.2 fallback) and an `index.html`, but treat them as defence in depth, not as the control.
* Layout `product-{id}/version-{id}/{random}.{ext}`; filename from `bin2hex(random_bytes(16))`.
  The original filename lives in the database only. Never emit the physical path anywhere.
* Upload validation (`Security\UploadValidator`): extension allowlist (configurable, default
  `zip tar.gz pdf js css php json xml txt md`), MIME sniff, size cap, reject traversal
  sequences and null bytes in the client filename, reject archives containing symlinks,
  absolute paths or `..` entries, and apply a compression-ratio/entry-count ceiling (ZIP bomb).
  Compute SHA-256 **after** the file lands.
* Write order: store file → verify it exists with the expected checksum and size → insert the
  version row inside a transaction → only then optionally set it current. If storage fails, no
  row. If the row fails, delete the orphan blob. Never leave a row pointing at nothing.

---

## 8. Purchase → entitlement

Hooks to register (replacing the current two):

| Hook | Action |
|---|---|
| `OrderPaid` | grant entitlements for every linked product in the order |
| `InvoicePaid` | same path, for renewals and for orders that skip `OrderPaid` |
| `AfterModuleCreate` | grant for provisioned services (same idempotent call) |
| `AcceptOrder` | grant where the product is free / manually accepted |
| `CancelOrder`, `OrderRefunded`, `FraudOrder` | revoke, reason recorded |
| `ServiceSuspend` / `ServiceUnsuspend` | suspend / restore per product policy |
| `ServiceDelete`, `AfterModuleTerminate` | revoke |
| `ClientAreaPrimaryNavbar` + `ClientAreaPage` | navigation (§10) |
| `DailyCronJob` | delegate to `cron/digitalproducts.php`; **never** delete download history |

Every grant goes through one method — `EntitlementService::grant($clientId, $serviceId,
$productId, $context)` — which is idempotent by the `UNIQUE (client_id, service_id,
product_id)` key (insert-ignore, then read back). Licence creation is idempotent by
`UNIQUE (service_id, product_id)`. Email send is recorded on the entitlement so a replayed
hook does not re-send.

Authorisation is recomputed live at download time from WHMCS state; the entitlement row is a
record and a limit counter, never the sole proof:

```
PAID + ACTIVE        → allow
PENDING / UNPAID     → deny (not_entitled)
SUSPENDED            → per product policy (default: deny)
CANCELLED/REFUNDED   → deny
TERMINATED/FRAUD     → deny
```

Email failure must never roll anything back: payment stands, entitlement stands, download
works, the failure is logged and retried by cron.

---

## 9. Secure download

`download.php` accepts **only** `?token=…`. No `service_id`/`file_id` inputs, ever.

```
token → sha256 → lookup download_tokens by token_hash (constant-time compare)
      → not expired, not used (when single_use)
      → load entitlement → client_id matches the token and, if a session exists, the session
      → entitlement.status = active
      → live WHMCS check: service belongs to client, service.packageid == product.whmcs_product_id,
        service state allows download
      → product.status = active ; version.status = active
      → version.product_id == product.id                      ← closes P0-1
      → atomic limit claim: UPDATE entitlements SET downloads_used = downloads_used + 1
        WHERE id = ? AND (limit = 0 OR downloads_used < limit)   → 0 rows ⇒ limit_exceeded
      → mark token used (single use) → stream → log success
```

Every rejection logs a row with its `failure_reason` and renders a themed error page — never a
white screen, never a stack trace, never a PHP notice. Headers on success:
`Content-Type` (from an allowlist map), `Content-Length`, `Content-Disposition: attachment`
with an RFC 5987-escaped filename, `Cache-Control: private, no-store`,
`X-Content-Type-Options: nosniff`. Stream with `fopen`/`fread` in chunks (or `fpassthru`);
support `Range` where practical; never `file_get_contents` a multi-GB file. Disable output
compression and clear any buffer before the first byte.

Tokens: `bin2hex(random_bytes(32))`, stored only as `hash('sha256', $raw)`, minted on demand
from the client area or the API, default expiry from product config, GC'd by cron. Entitlement
lifetime and token lifetime are independent — an expired token is a "generate a new link"
situation, not a loss of access.

---

## 10. Client area (HostX)

* `My Downloads` list: product, type, current version, purchased version, purchase date,
  licence (masked, reveal on click), status, downloads used/remaining, download button,
  "new version available" badge.
* Product detail: description, current vs purchased version, changelog, release history, file
  size, SHA-256, compatibility (min/max PHP, min/max WHMCS, required extensions), licence
  details and activations, download history.
* Rendered as **Smarty templates under `templates/client/`** using HostX's own card, table,
  badge, button and alert classes — not `echo`'d HTML, no inline `<style>` block. Verify
  against `templates/hostx/`. Must be usable at mobile width: the table collapses to cards.
* Navigation: register under the client navbar via `ClientAreaPrimaryNavbar` (as
  `cloudhost247services/hooks.php` does) and, for the HostX mega menu, merge an entry into
  `$topMenusData` on `ClientAreaPage` following `Chs\Http\HostxMenu`'s approach. Drop the
  fragile `getChildren()['My Account']` lookup. Show the item only when the module is active;
  an entitled-but-empty library still renders, with an explanatory empty state.

---

## 11. Admin area

Sections: Dashboard, Products, Versions, Upload, Entitlements, Licences, Downloads, API
Tokens, Settings, Audit Log. Dashboard metrics: products total/active, versions, entitlements,
active licences, downloads today / this month, failed downloads, expired tokens — all from
indexed aggregate queries, cached briefly, never a storage directory scan.

Manual operations (all audited, all CSRF-protected, all re-checked server-side): grant, revoke,
suspend, restore, reset download counter, mint a fresh link, regenerate / suspend / cancel a
licence, retire a version, set current version.

Every POST: `Core\Csrf` token issue + verify (copy `DomainBroker\Core\Csrf`). Every action:
a `Core\Rbac` capability check mapped onto WHMCS admin roles — `products.view`,
`products.manage`, `files.upload`, `files.delete`, `versions.manage`, `licenses.manage`,
`entitlements.manage`, `downloads.view`, `settings.manage`, `audit.view`. Hidden menu items are
not access control. All output escaped; all input validated with `Core\Validator`.

File deletion: a version referenced by an active entitlement, licence or purchase is
**retired/archived**, never unlinked. Physical deletion is a separate, confirmed, audited
action that refuses while references exist.

Audit events (via `DigitalProducts\Core\Audit`, same shape as `Chs\Core\Audit` — actor type,
actor id, action, context, correlation id, timestamp):
`product.created|updated|disabled|retired`, `version.uploaded|activated|retired`,
`file.deleted`, `license.created|suspended|cancelled|regenerated`,
`entitlement.granted|revoked|suspended|restored|counter_reset`, `download.policy.changed`,
`settings.changed`, plus security events `download.denied`, `api.auth_failed`,
`api.rate_limited`.

---

## 12. API

One front controller (`api.php`) → `lib/Api/Router.php`, consistent JSON envelope, correct
status codes, strict method checks.

```
GET  products                      public catalogue metadata only (no file info)
GET  product/{slug}
GET  versions/{productId}          entitlement required
GET  my/downloads                  auth required
GET  my/licenses                   auth required
POST download-token                auth required → returns a one-time URL
POST validate-license              rate-limited, generic failures
POST activate-license              rate-limited, domain + activation-limit enforced
```

Auth: `Authorization: Bearer <token>` only. Tokens hashed at rest, compared constant-time,
never accepted from the query string, scoped per client, revocable, with `last_used_at`.
Remove `Access-Control-Allow-Origin: *`; if cross-origin is genuinely needed, allowlist exact
origins and never combine it with cookie auth. Session auth stays available for same-origin
client-area XHR, protected by the CSRF token.

`validate-license` returns the minimum (`valid`, `status`, `product`, `expires_at`) and
**never** reveals whether an unknown key belongs to someone else. Rate limit by IP + key prefix
via `Core\RateLimiter` (see `Chs\Core\RateLimiter::hitOrFail`); log every failure as a security
event; apply a short lockout on repeated misses.

---

## 13. Email

Use the WHMCS email system (`sendmessage` / `SendEmail` with a module-registered template) —
no new SMTP layer. Purchase email: product, version, purchase date, licence key if enabled, a
**token** download link, a client-area link, support info. Never a permanent file URL. Always
include "Access your downloads anytime from your CloudHost247 client area."

Optional, configurable new-version notification to existing owners — links to the client area,
not to a file; queued and sent by cron in batches, never inline in a request.

---

## 14. Testing (what must be green before merge)

Offline suite under `modules/addons/digitalproducts/tests/`, mirroring
`domainbroker/tests/`: `bootstrap.php` boots the module against **in-memory SQLite using the
real migrations**, with a fake WHMCS gateway recording the calls the module would make, a
controllable `Clock`, and a temp-dir storage root. No network, no real WHMCS, no fixtures
outside `tmp`. Runner: `node tests/run.mjs` (php-wasm) plus `tests/lint.php` / `lint.mjs` for
syntax across every PHP file in the module.

| Suite | Must cover |
|---|---|
| `01_CoreTest` | migrator idempotency, baseline over legacy tables, settings, CSRF, validator |
| `02_MigrationTest` | `files` → `versions` row-count parity, `current_file_id` → `current_version_id`, legacy `DP-` licence hashing, entitlement backfill |
| `03_ProductTest` | create/edit/disable/retire, WHMCS link uniqueness, slug collisions |
| `04_UploadTest` | valid ZIP; bad extension; oversize; traversal + null-byte filenames; symlink/absolute-path archive entries; ZIP bomb ratio; duplicate version; storage failure leaves no row; orphan blob cleanup |
| `05_EntitlementTest` | paid grant; **duplicate hook ⇒ one entitlement, one licence, one email**; unpaid; refund; cancel; fraud; suspend/unsuspend; terminate |
| `06_DownloadTest` | happy path; wrong customer; **other product's version id (P0-1)**; expired token; reused single-use token; forged token; limit reached; concurrent requests claim exactly `limit` downloads; disabled product; disabled version; missing file ⇒ `file_missing`, not a crash |
| `07_LicenseTest` | generate; validate; unknown key (generic response); suspended; expired; domain activation; activation limit; legacy `DP-` key still validates |
| `08_ApiTest` | method enforcement; bearer required; query-param token rejected; no wildcard CORS header; rate limit trips; response shapes; no customer PII leakage |
| `09_SecurityTest` | CSRF required on every admin POST; RBAC denial per capability; IDOR matrix; output escaping; no raw token/secret ever written to the download log or activity log; storage root inside web root is refused |

---

## 15. Documentation deliverables

Follow the repo's convention (`docs/` holds the long-form guides; each module keeps a README)
rather than scattering six new files at the root:

* `modules/addons/digitalproducts/README.md` — rewrite: what it is, install, activate,
  configure, product setup, uploads, versions, client downloads, licensing, API reference,
  storage, security model, upgrade, troubleshooting, backup/restore. Strip the
  "Your Company / yourcompany.com / 2024" placeholders.
* `docs/DIGITAL_PRODUCTS.md` — operator guide in the style of `docs/DOMAIN_BROKER.md`:
  architecture, schema, lifecycle diagrams, cron, storage siting, security model, runbook.
* Update `docs/MODULES.md` — the `digitalproducts` row currently reads
  "Digital / downloadable product delivery & licensing" and credits the source archive; it must
  describe the rebuilt module and point at the new doc.
* Update `docs/OPERATIONS.md` — deploy checklist entry, module settings, the storage-path
  requirement, and the cron line:
  `*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/digitalproducts/cron/digitalproducts.php`
* Update `.gitignore` for `modules/addons/digitalproducts/storage/*` + `.gitkeep` exception.

---

## 16. Settings

Default download limit · default token expiry · default access mode · licences enabled ·
purchase email enabled · update notifications enabled · max upload size · allowed extensions ·
private storage path · storage provider · API rate limits · log retention (export-then-prune,
never a silent delete) · debug logging. All validated on save; the storage path additionally
checked for web-reachability and writability. Secrets accept env-var overrides and are never
rendered into HTML, URLs, JS, logs or API responses.

---

## 17. Suggested sequence

1. **Foundation** — `autoload.php`, `lib/Core/*`, migrator, settings, CSRF, RBAC, audit, tests
   harness green on an empty module.
2. **Schema** — migrations 0001–0007 + the migration test proving legacy data survives.
3. **Storage & upload** — `StorageInterface`, `LocalPrivateStorage`, `UploadValidator`, version
   service, upload tests.
4. **Entitlements & hooks** — grant/revoke lifecycle, idempotency, licence service.
5. **Download** — `TokenService`, `DownloadAuthorizer`, rewritten `download.php`, atomic limit,
   logging. *This is where the P0s die.*
6. **Admin** — controllers, templates, CSRF + RBAC on every action, audit log view.
7. **Client area** — HostX templates, navigation, mobile.
8. **API** — router, auth, rate limiting, licence endpoints.
9. **Email & cron** — purchase email, update notifications, token GC, entitlement revalidation.
10. **Docs & cleanup** — README, `docs/`, remove dead imports/placeholders, final lint + suite.

---

## 18. Definition of done

**Verifiable in this repository (hard gate):**

* `node modules/addons/digitalproducts/tests/run.mjs` — every suite in §14 green.
* `tests/lint.php` clean across the module; no PHP 8-only syntax.
* No `digitalproducts_v2`-style duplicate module, no duplicate table family, no committed
  archive, no secret, no demo data.
* Every P0 and P1 in §3 closed, each with the test that would have caught it.
* Docs in §15 updated.

**Verifiable on a staging WHMCS (sign-off gate):**

```
admin links a WHMCS product → uploads 1.0.0 → publishes it
customer orders → pays → entitlement + licence created exactly once → email arrives
customer logs in → My Downloads lists it → clicks Download → token minted → file streams
download appears in the admin log with the right client, product, version and status
admin uploads 1.1.0 → publishes → same customer sees and downloads the new version
admin resets the counter / revokes access → next attempt is denied and logged
product validates its licence over the API; a guessed key is throttled and tells the caller nothing
a second customer's token, a forged token, an expired token and a reused token all fail closed
the private storage root returns 403/404 over HTTP on both Apache and nginx
```

The feature is done when a customer can buy, receive, download and update a product, and
validate its licence — and an administrator can publish, manage and audit all of it — with no
WHMCS core modification and no second digital-products system anywhere in the tree.
