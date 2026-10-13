# CloudHost247 — Step-by-Step Deployment Guide

This runbook provides the complete, ordered procedure for deploying the **CloudHost247** WHMCS overlay (`templates/`, `modules/`, `crons/`, `includes/`, `lang/`, and root landing pages) onto a staging or production WHMCS installation. For a first-time server setup and detailed per-module WHMCS Admin configuration guide, see [`docs/INSTALLATION.md`](INSTALLATION.md).

---

## 1. Prerequisites & Runtime Requirements

Before deploying, verify that the target host meets the following requirements:

| Component | Requirement | Notes |
|---|---|---|
| **WHMCS** | WHMCS `8.x` | Installed and operational at your target web root (referred to below as `$WHMCS_ROOT`, e.g. `/var/www/whmcs`). |
| **PHP** | PHP `8.1` – `8.3` (CLI + FPM) | Both the web SAPI (`php-fpm`) and CLI SAPI (`php`) must run the same major/minor version and share environment variables. |
| **ionCube Loader** | Required (`>= 12.x` / PHP 8.x compatible) | Mandatory for `modules/addons/hostx` (63 encoded files) and `modules/addons/xtreme_currency_rates` (18 encoded files). Both modules fail closed (`exit(199)`) if the loader is absent. |
| **PHP Extensions** | `openssl`, `pdo_mysql`, `curl`, `mbstring`, `json`, `intl`, `zip`, `gd` or `imagick`, `imap`, `dom`, `xml` | `imap` is required by `crons/emailSend.php`; `openssl` (AES-256-GCM) is required by all credential vaults; `zip` is required by `digitalproducts`. |
| **Database** | MySQL `8.0+` or MariaDB `10.5+` | Strict `InnoDB` support required for foreign keys and atomic job-queue compare-and-swap operations. |
| **System Tools** | `rsync`, `composer` (2.x), `flock`, `dig`, `ping`, `traceroute` | `dig`/`ping`/`traceroute` are used by the public network diagnostic tools (`CloudHost247_tools` and `tools_center`). |

---

## 2. Step 1 — Pre-Deployment Backup & Pre-Flight Test Gate

### 1.1 Back up the existing WHMCS installation
On the target server, take a full database dump and file archive before syncing the overlay:

```bash
export WHMCS_ROOT="/var/www/whmcs"
export BACKUP_DIR="/var/backups/whmcs-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# 1. File tree backup
tar -czf "$BACKUP_DIR/whmcs-files.tar.gz" -C "$WHMCS_ROOT" .

# 2. Database backup (replace credentials/db_name as appropriate)
mysqldump --single-transaction --routines --triggers -u whmcs_user -p whmcs_db \
  | gzip > "$BACKUP_DIR/whmcs-db.sql.gz"
```

### 1.2 Run the offline CI / verification gate on the release artifact
From the `CloudHost247` repository checkout (on your build runner or staging box):

```bash
# Run all 23 PHP module test suites
bash ci/run-php.sh all

# Run JavaScript / DOM suites
bash ci/run-js.sh

# Run repo-wide PHP syntax lint (excludes ionCube-encoded modules)
bash ci/php-lint.sh
```

Do not proceed if any check reports `FAIL > 0` or a syntax error.

---

## 3. Step 2 — Sync the Overlay into the WHMCS Root

### 2.1 Rsync the repository over `$WHMCS_ROOT`
Exclude development-only directories (`.git`, `docs`, `ci`, `tests`, `node_modules`, and `.github`) so test harnesses and documentation are never web-accessible:

```bash
rsync -av \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='ci' \
  --exclude='docs' \
  --exclude='tests' \
  --exclude='**/tests' \
  --exclude='**/node_modules' \
  --exclude='.gitignore' \
  --exclude='README.md' \
  ./ "$WHMCS_ROOT/"
```

### 2.2 Install the LTE Proxy WHMCS Hook Bootstrap (`LP-O1`)
WHMCS only auto-discovers hooks inside `$WHMCS_ROOT/includes/hooks/` and active addon modules. Copy the server-module hook bootstrap for `cloudhost247_lteproxy`:

```bash
cp -f "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/hooks.php" \
      "$WHMCS_ROOT/includes/hooks/cloudhost247_lteproxy.php"
```

### 2.3 Initialize Theme Override Files (First Install Only)
If `override.css` does not yet exist in `templates/hostx/css/overrides/`, seed it from the `.new` template so future deployments do not overwrite local CSS customizations:

```bash
if [ ! -f "$WHMCS_ROOT/templates/hostx/css/overrides/override.css" ]; then
  cp "$WHMCS_ROOT/templates/hostx/css/overrides/override.css.new" \
     "$WHMCS_ROOT/templates/hostx/css/overrides/override.css"
fi
```

---

## 4. Step 3 — Configure Environment Variables & Cryptographic Keys

Several CloudHost247 modules refuse to store or unseal provider credentials unless their encryption keys are provided via environment variables (never stored in the database).

### 3.1 Generate 256-bit keys (once per environment)
Run the following to generate fresh 64-character hex keys (store them in your secret manager):

```bash
openssl rand -hex 32   # Repeat for each key below
```

### 3.2 Required & Optional Environment Variables

Configure these in **both** your PHP-FPM pool (`/etc/php/8.x/fpm/pool.d/www.conf` via `env[KEY] = ...`) **and** the cron user's environment (`/etc/cron.d/cloudhost247` or `/etc/environment`):

| Variable | Consuming Module | Purpose |
|---|---|---|
| `CHS_CREDENTIALS_KEY` | `cloudhost247services` | **Required.** 64-char hex key (or passphrase) used by `Chs\Core\Secrets` (AES-256-GCM) to seal registrar, AI, and HTTP infrastructure provider credentials. Saving credentials fails closed without it. |
| `CH247APPS_ENCRYPTION_KEY_V1` | `cloudhost247apps` | **Required.** 64-char hex key used to seal cPanel/WHM tokens, Kubernetes kubeconfigs, and cloud provider API tokens. |
| `CLOUDFLARE_ENCRYPTION_KEY` | `cloudhost247cloudflare` | **Recommended.** Secret key for `cfenc:v1:` AES-256-GCM Cloudflare API token encryption (falls back to WHMCS `$cc_encryption_hash` if unset). |
| `DIGITALPRODUCTS_ENCRYPTION_KEY` | `digitalproducts` | **Required.** Secret key used to encrypt software license keys and download tokens at rest. |
| `DIGITALPRODUCTS_STORAGE` | `digitalproducts` | **Required.** Absolute path to a writable directory **outside** `$WHMCS_ROOT` (e.g. `/var/lib/cloudhost247/digitalproducts`) where private release ZIPs are stored. |
| `CH247AI_API_KEY` | `cloudhost247ai` | **Optional.** Bearer API key for the OpenAI-compatible LLM endpoint configured in the AI Control Plane (never stored in DB). |
| `CLOUDHOST247_TRUSTED_PROXIES` | `CloudHost247_tools`, `hostx_tools` | Comma-separated list of reverse-proxy IPs/CIDRs (e.g. Cloudflare edge ranges or load balancer IPs) allowed to supply `X-Forwarded-For` / `CF-Connecting-IP`. Defaults to trusting `REMOTE_ADDR` only. |
| `DOMAINBROKER_TRUSTED_PROXIES` | `domainbroker` | Comma-separated list of trusted reverse-proxy IPs for Domain Broker client IP resolution. |
| `DOMAINBROKER_BASE_URL` | `domainbroker` | Canonical public base URL (`https://...`) used in Domain Broker notifications and links. |
| `SOYOUSTART_AVAILABILITY_FEED_SECRET` | `soyoustart` / `crons/priceSync.php` | Shared secret for the optional SoYouStart/OVH advisory availability feed. |
| `SMTPHOSTING_USAGE_SECRET` | `modules/servers/Smtphosting` | Upstream usage API secret for `smtp-api.php` (can also be placed in `modules/servers/Smtphosting/storage/config/secrets.php`). |
| `SMTPHOSTING_LOGS_SECRET` | `modules/servers/Smtphosting` | Upstream logs API secret for `smtp-api.php`. |
| `TOOLS_API_TOKEN` / `TOOLS_API_SECRET` | `tools_center` | Shared authentication token and secret for `modules/addons/tools_center/external-api/api.php`. |
| `VIRUSTOTAL_API_KEY` / `IPGEOLOCATION_API_KEY` | `tools_center` | Optional upstream API keys for VirusTotal and IPGeolocation tools in `tools_center`. |

---

## 5. Step 4 — File Permissions & Private Storage Directories

Create the external storage directory and ensure runtime cache/log directories are owned by the web/cron user (e.g. `www-data`):

```bash
export WEB_USER="www-data"
export WEB_GROUP="www-data"

# 1. Private digital products storage (MUST be outside $WHMCS_ROOT)
mkdir -p /var/lib/cloudhost247/digitalproducts
chown -R "$WEB_USER:$WEB_GROUP" /var/lib/cloudhost247/digitalproducts
chmod 0750 /var/lib/cloudhost247/digitalproducts

# 2. Module runtime cache/log/storage directories
mkdir -p "$WHMCS_ROOT/modules/addons/cloudhost247_i18n/cache" \
         "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/cache" \
         "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/logs" \
         "$WHMCS_ROOT/modules/servers/Smtphosting/storage/app/smtp-usage-ratelimit" \
         "$WHMCS_ROOT/modules/servers/Smtphosting/storage/config"

chown -R "$WEB_USER:$WEB_GROUP" \
  "$WHMCS_ROOT/modules/addons/cloudhost247_i18n/cache" \
  "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/cache" \
  "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/logs" \
  "$WHMCS_ROOT/modules/servers/Smtphosting/storage"

# 3. Allow CloudHost247 Services cron to regenerate sitemap.xml
touch "$WHMCS_ROOT/sitemap.xml"
chown "$WEB_USER:$WEB_GROUP" "$WHMCS_ROOT/sitemap.xml"
chmod 0664 "$WHMCS_ROOT/sitemap.xml"
```

---

## 6. Step 5 — Web Server & URL Rewrite Configuration

### 5.1 Apache (`.htaccess`)
The overlay ships a root `.htaccess` rule that routes `/tools` and `/tools/*` to `modules/addons/CloudHost247_tools/front.php`.
* If your WHMCS root already has a `.htaccess` file with WHMCS Friendly URLs, ensure the `/tools` rewrite block appears **above** the WHMCS `index.php` catch-all rule:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # 1. CloudHost247 Tools pretty URLs (MUST precede WHMCS catch-all rules)
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^tools(?:/(.*))?$ modules/addons/CloudHost247_tools/front.php?route=$1 [QSA,L]

    # 2. Block direct web access to internal module directories and cron scripts
    RewriteRule ^(?:crons|modules/.*/(?:tests|install|upgrade|storage))/ - [F,L]
</IfModule>
```

### 5.2 Nginx Equivalent
If running Nginx, add the following inside your `server { ... }` block before the WHMCS `location /` handler:

```nginx
# Block direct web access to crons, tests, migrations, and private storage
location ~* ^/(crons|modules/.*/(tests|install|upgrade|storage))/ {
    deny all;
    return 404;
}

# CloudHost247 Tools (/tools/*) front controller
location ^~ /tools {
    try_files $uri $uri/ /modules/addons/CloudHost247_tools/front.php?route=$uri&$args;
}
```

---

## 7. Step 6 — Install Optional Composer Dependencies

All CloudHost247 modules ship native PSR-4 `autoload.php` files so they boot without Composer. However, two modules use third-party Composer libraries for live cryptography/provider SDKs:

```bash
# 1. Passkey Authentication (web-auth/webauthn-lib for live WebAuthn attestation/assertion ceremonies)
if [ -f "$WHMCS_ROOT/modules/addons/cloudhost247passkey/composer.json" ]; then
  composer install --no-dev --optimize-autoloader -d "$WHMCS_ROOT/modules/addons/cloudhost247passkey"
fi

# 2. Phone Services (Twilio / Vonage / eSIM provider SDKs)
if [ -f "$WHMCS_ROOT/modules/addons/phoneservices/composer.json" ]; then
  composer install --no-dev --optimize-autoloader -d "$WHMCS_ROOT/modules/addons/phoneservices"
fi
```

---

## 8. Step 7 — WHMCS Theme, Order Forms & Module Activation Sequence

Log in to the WHMCS Admin Area (`https://<your-host>/admin`) and perform the following activations in order:

### 7.1 Activate the HostX Theme & Order Forms
1. Navigate to **Configuration (System Settings) → General Settings → General**.
2. Set **System Theme** to `Hostx`.
3. Under the **Ordering** tab, set **Default Order Form Template** to `Hostx` (or `ovh_cart` specifically on OVH/SoYouStart product groups).
4. Under **Configuration → Addon Modules**, ensure **HostX** (`modules/addons/hostx`) is active and its menu manager is clean of duplicate manual top-level items (`Domains`, `Websites & Builders`, `Marketing`, `Hosting & Services`), as `cloudhost247services` injects the canonical 33-link mega menu automatically via `ClientAreaPage`.

### 7.2 Deactivate the Retired `hostx_tools` Addon (`D-2` / `F-4`)
1. Go to **Configuration → System Settings → Addon Modules**.
2. Locate **HostX Tools** (`hostx_tools`) and click **Deactivate** (if currently active). It is superseded by **CloudHost247 Tools** (`CloudHost247_tools`).

### 7.3 Activate Addon Modules (in Dependency Order)
Navigate to **Configuration → System Settings → Addon Modules**, activate each module below, and grant **Full Administrator** role access. Each module runs its additive schema migrations automatically upon activation:

1. **CloudHost247 Internationalization (`cloudhost247_i18n`)** — Multi-currency and locale runtime.
2. **CloudHost247 Services (`cloudhost247services`)** — Runs migrations `0001`–`0014` (Domain platform, Auctions, Valuation, Discount Club, Logo Studio, Unified Inbox, OS Catalog, Infrastructure Provisioning, and Blockonomics governance).
   * Set `service_enabled = on` and `system_url = https://<your-host>/`.
3. **CloudHost247 Tools (`CloudHost247_tools`)** — 91-tool public & client diagnostic platform at `/tools/*`.
   * Configure `ocr_api_key` if using the opt-in server OCR (`image-to-text`) tool.
4. **Tools Center (`tools_center`)** — Secondary client-area tools suite.
   * Set `allowed_origins` in `modules/addons/tools_center/external-api/config.php` if cross-origin browser requests are needed (defaults to empty/same-origin).
5. **CloudHost247 App & VPS Platform (`cloudhost247apps`)** — Runs additive migrations for cPanel, Kubernetes, Hetzner, DigitalOcean, Vultr, and Contabo adapters.
6. **CloudHost247 Cloudflare Reseller (`cloudhost247cloudflare`)** — Runs migration `0001_cloudflare_core.php`.
   * Add your Cloudflare account (`https://api.cloudflare.com/client/v4` + API token + Account ID) and map WHMCS products/addons under **Package Mappings**.
7. **CloudHost247 Passkey (`cloudhost247passkey`)** — WebAuthn passwordless & MFA security layer.
   * Verify RP ID and Origin settings match your production HTTPS hostname.
8. **CloudHost247 AI Control Plane (`cloudhost247ai`)** — Runs migrations and boots in safe read-only mode (`writes_enabled = false` by default).
9. **CloudHost247 Email Marketing (`cloudhost247marketing`)** — Campaigns, segments, automations, and SMTP/tracking endpoints.
10. **CloudHost247 Cart Recovery (`cloudhost247_cart_recovery`)** — 256-bit hashed cart-recovery links and scheduled reminder sequences.
11. **Digital Products Marketplace (`digitalproducts`)** — Software releases, downloads, and license key management.
    * Configure `license_default_domain_limit` and `license_default_activations_limit` (`0` = unlimited).
12. **Domain Broker (`domainbroker`)** — Brokerage inquiries, negotiations, escrow milestones, and RDAP/DNS intelligence.
13. **Phone Services (`phoneservices`)** — Twilio/Vonage/eSIM virtual numbers and SMS/voice webhooks.
14. **SMM Addon (`smmaddon`)** — SMM panel synchronization, server mappings, and order lifecycle hooks.
15. **Custom Affiliate (`customaffiliate`)** — Tiered recurring/one-time affiliate commissions, proportional refund reversals, and automatic product regrouping.
16. **SoYouStart / OVH Addon (`soyoustart`)** — OVH/SoYouStart API consumer setup (`eu`, `ca`, `us`).
    * Ensure at least one active **Canada (`ca`)** credential row with a valid `application_key` is configured if using the admin OS catalog listing (`getOs()`).

### 7.4 Configure Server Provisioning Modules (`modules/servers/*`)
Under **Configuration → System Settings → Products/Services** and **Servers**, bind products to their respective server modules:
* `cloudhost247cloudflare` — Companion server module for Cloudflare zone provisioning.
* `smmprovisioning` — SMM order provisioning (enforces paid-quantity caps via `SmmAddon\OrderQuantity`).
* `hostx_email` — Email hosting provisioning (`hxem:v1:` AES-256-GCM credentials).
* `Smtphosting` — SMTP relay provisioning + `smtp-api.php` authenticated usage/logs proxy.
* `RDP` — RDP reseller provisioning (`rdparena.com`, encrypted service passwords).
* `cloudhost247_lteproxy` — LTE proxy provisioning (`api.cloudhost247.com`).
* `soyoustart` & `soyoustart_vps` — OVH/SoYouStart dedicated and VPS provisioning.

### 7.5 Activate Payment Gateways (`modules/gateways/*`)
1. Go to **Configuration → System Settings → Payment Gateways**.
2. Activate **Blockonomics (`blockonomics`)**.
3. Configure callback secret and currencies via the **CloudHost247 Services → Blockonomics** governance console (`docs/BLOCKONOMICS.md`).

---

## 9. Step 8 — One-Time Credential Migrations & Secret Rotation

When upgrading an existing installation, run these one-time credential migrations:

### 8.1 Migrate `hostx_email` Credentials to `hxem:v1:` (AES-256-GCM)
Run a dry-run first, then apply `--write` to re-wrap legacy CBC credentials in `tblservers`, `tblhosting`, and `tblproducts`:

```bash
# 1. Dry run (reports rows to migrate without writing)
php "$WHMCS_ROOT/modules/servers/hostx_email/upgrade/migrate-credentials.php"

# 2. Apply migration (idempotent)
php "$WHMCS_ROOT/modules/servers/hostx_email/upgrade/migrate-credentials.php" --write
```

### 8.2 Upgrade `soyoustart` IMAP Password to `enc:v1:`
1. In WHMCS Admin → **Addons → OVH / SoYouStart → IMAP Settings**, edit and re-save each configured IMAP mailbox password.
2. Saving re-encrypts the password with WHMCS `encrypt()` under the `enc:v1:` prefix (read by `ImapCredential::decrypt()` in `crons/emailSend.php`).

### 8.3 Issue Scoped Per-Client API Keys in `phoneservices` (`P-5`)
The legacy global shared `api_key` in `phoneservices` is retired and returns `401 Unauthorized`.
* Issue per-client keys from **Addons → Phone Services → Client API Keys** (`mod_phoneservices_apikeys`).

### 8.4 Rotate `Smtphosting` Upstream Secrets (`S-6`)
* Rotate the two upstream secrets with your SMTP relay provider and set the new values in `SMTPHOSTING_USAGE_SECRET` and `SMTPHOSTING_LOGS_SECRET` (see [`docs/SMTPHOSTING_USAGE_PROXY.md`](SMTPHOSTING_USAGE_PROXY.md)).

---

## 10. Step 9 — Install the Cron Schedule

Create `/etc/cron.d/cloudhost247` (running as your WHMCS web user, e.g. `www-data`). All CloudHost247 cron scripts enforce CLI-only execution (`CronGuard::requireCli()`) and single-flight file locking (`CronLock::acquire()`):

```cron
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# ── Core CloudHost247 Addon Workers (every 5 minutes) ──────────────────────
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247services/cron/cloudhost247services.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247apps/cron/cloudhost247apps.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247ai/cron/cloudhost247ai.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/digitalproducts/cron/digitalproducts.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/domainbroker/cron/domainbroker.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247_cart_recovery/cron.php >/dev/null 2>&1

# ── OVH / SoYouStart Synchronization Crons ─────────────────────────────────
# Note: Enable getServer.php, getIpStatus.php, and priceSync.php in production
# only after verifying OVH account mappings and billing settings in staging.
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/crons/getServer.php >/dev/null 2>&1
*/5 * * * * www-data /usr/bin/php -q /var/www/whmcs/crons/getIpStatus.php >/dev/null 2>&1
*/10 * * * * www-data /usr/bin/php -q /var/www/whmcs/crons/emailSend.php >/dev/null 2>&1
15 2 * * * www-data /usr/bin/php -q /var/www/whmcs/crons/priceSync.php >/dev/null 2>&1

# ── Internationalization Currency Rate Cache Warm (every 6 hours) ──────────
0 3,9,15,21 * * * www-data /usr/bin/php -q /var/www/whmcs/crons/currencyRateRefresh.php >/dev/null 2>&1
```

> **Note on `cloudhost247cloudflare`:** Its background worker (`CloudHost247\Cloudflare\Service\Worker::run(25)`) is automatically triggered by WHMCS's native `AfterCronJob` hook (`modules/addons/cloudhost247cloudflare/hooks.php`) and can also be run manually from **Addons → CloudHost247 Cloudflare → Jobs → Run Worker Now**.

---

## 11. Step 10 — Post-Deployment Smoke Tests & Go-Live Checklist

Execute these verification checks immediately after deploying to staging or production:

1. **Public Landing & Theme Shell:**
   * Visit `/`, `/domains.php`, `/vps-hosting.php`, and `/announcements.php`.
   * Confirm the HostX theme renders cleanly, the top mega menu displays all 4 categories (`Domains`, `Websites & Builders`, `Marketing`, `Hosting & Services`), and the Announcement Bar scrolls smoothly at the top without duplicating `/announcements.php` body text.
2. **Tools Platform (`/tools/*`):**
   * Visit `/tools`, `/tools/dns`, `/tools/dns-lookup`, and `/tools/sitemap.xml`.
   * Submit a lookup on `/tools/my-ip` (returns `200 OK` JSON) and verify that `POST /tools/api/my-ip` without a CSRF token returns `419`.
   * Verify a client-only tool (e.g. `/tools/credit-card-validator` or `/tools/password-generator`) executes entirely in the browser with zero network POST requests.
3. **Web Server Access Controls:**
   * Confirm `curl -I https://<your-host>/crons/priceSync.php` and `curl -I https://<your-host>/modules/addons/cloudhost247services/tests/01_CoreTest.php` return `403` or `404`.
4. **CloudHost247 Services & Domain/Server Order Flow:**
   * Open **Admin → Addons → CloudHost247 Services → Overview & Operations (Jobs)** and confirm zero failed migrations or unsealed-key errors.
   * Place a test order in staging, mark the invoice `Paid`, and verify `cloudhost247services.php` cron drains the `SERVER_PROVISION` / domain jobs cleanly.
5. **Cloudflare Reseller (`cloudhost247cloudflare`):**
   * In **Admin → Addons → CloudHost247 Cloudflare → Accounts**, click **Test** on your Cloudflare account and verify a test zone provisions and syncs origin A/AAAA records (`synchronizeOrigin`).
6. **Digital Products (`digitalproducts`):**
   * Upload a test ZIP release, verify it lands in `$DIGITALPRODUCTS_STORAGE` outside the web root, and test a customer download link and single-use/expiring token.
7. **Passkey (`cloudhost247passkey`) & AI Control Plane (`cloudhost247ai`):**
   * Register a passkey in the Client Area Security settings and complete a WebAuthn sign-in ceremony.
   * Confirm the AI Control Plane kill-switch and `writes_enabled = false` default are active in **Admin → CloudHost247 AI → Settings**.

---

## 12. Step 11 — Emergency Kill-Switches & Rollback Procedure

### 11.1 Module-Level Kill Switches (Zero-Downtime Mitigation)
Before performing a full rollback, you can isolate individual subsystems immediately from the WHMCS Admin Area without dropping any database tables:
* **CloudHost247 AI:** Toggle **Emergency Kill Switch** on in **Addons → CloudHost247 AI → Settings** (halts all model calls and tool executions immediately).
* **CloudHost247 Cloudflare:** Set `CLOUDFLARE_ENABLED=0` in the environment or disable **Enable Cloudflare Integration** in module settings (halts all outbound Cloudflare API calls and hooks).
* **CloudHost247 Services:** Toggle `service_enabled = off` in **Addons → CloudHost247 Services → Settings**.

### 11.2 Full File & Database Rollback
If a full rollback to the pre-deployment snapshot is required:

```bash
# 1. Pause CloudHost247 cron jobs
mv /etc/cron.d/cloudhost247 /etc/cron.d/cloudhost247.disabled

# 2. Restore WHMCS file tree from backup
tar -xzf "$BACKUP_DIR/whmcs-files.tar.gz" -C "$WHMCS_ROOT"

# 3. (Only if a schema rollback is required) Restore database snapshot
gunzip < "$BACKUP_DIR/whmcs-db.sql.gz" | mysql -u whmcs_user -p whmcs_db
```
