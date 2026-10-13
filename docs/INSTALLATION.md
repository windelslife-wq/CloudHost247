# CloudHost247 — Complete Installation Guide

This guide walks through installing the **CloudHost247** platform from scratch on top of a WHMCS `8.x` installation, including server preparation, file layout, cryptographic key setup, web-server rules, and the per-module WHMCS Admin configuration for every theme, addon, provisioning module, payment gateway, and cron job in the repository.

> Looking for the release/upgrade runbook for an already-installed environment? See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md).

---

## Table of Contents

1. [System Requirements & Server Preparation](#1-system-requirements--server-preparation)
2. [Download & Overlay File Installation](#2-download--overlay-file-installation)
3. [Directory Permissions & Private Storage Setup](#3-directory-permissions--private-storage-setup)
4. [Cryptographic Keys & Environment Variables](#4-cryptographic-keys--environment-variables)
5. [Composer Dependencies (Passkey & Phone Services)](#5-composer-dependencies-passkey--phone-services)
6. [Web Server Configuration (Apache & Nginx)](#6-web-server-configuration-apache--nginx)
7. [Theme, Mega Menu & Order Form Installation](#7-theme-mega-menu--order-form-installation)
8. [Addon Modules — Step-by-Step Installation & Setup](#8-addon-modules--step-by-step-installation--setup)
9. [Server Provisioning Modules — Product & Server Setup](#9-server-provisioning-modules--product--server-setup)
10. [Payment Gateway Installation (Blockonomics)](#10-payment-gateway-installation-blockonomics)
11. [Cron Jobs Installation](#11-cron-jobs-installation)
12. [Post-Installation Verification & Troubleshooting](#12-post-installation-verification--troubleshooting)

---

## 1. System Requirements & Server Preparation

### 1.1 Supported Stack
* **OS:** Ubuntu 22.04 / 24.04 LTS, Debian 12, or AlmaLinux / Rocky Linux 9
* **WHMCS:** Version `8.x` (installed and licensed at `/var/www/whmcs` or your chosen `$WHMCS_ROOT`)
* **PHP:** `8.1`, `8.2`, or `8.3` (CLI and PHP-FPM must run the same version)
* **Database:** MySQL `8.0+` or MariaDB `10.5+` (`InnoDB` engine, `utf8mb4` charset)
* **Web Server:** Apache `2.4+` (`mod_rewrite` enabled) or Nginx `1.22+` with `php-fpm`

### 1.2 Install Required Packages & PHP Extensions (Ubuntu / Debian Example)

```bash
sudo apt-get update
sudo apt-get install -y \
  rsync unzip curl git dnsutils iputils-ping traceroute \
  php8.2-cli php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring \
  php8.2-xml php8.2-zip php8.2-gd php8.2-imagick php8.2-imap \
  php8.2-intl php8.2-bcmath php8.2-soap composer
```

### 1.3 Install ionCube Loader (Required for `hostx` & `xtreme_currency_rates`)
Two commercial third-party modules (`modules/addons/hostx` and `modules/addons/xtreme_currency_rates`) are ionCube-encoded and require the ionCube Loader extension:

```bash
cd /tmp
curl -fsSLO https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
tar -xzf ioncube_loaders_lin_x86-64.tar.gz
PHP_EXT_DIR="$(php -i | awk '/^extension_dir =>/ {print $3}')"
sudo cp "ioncube/ioncube_loader_lin_8.2.so" "$PHP_EXT_DIR/"

echo "zend_extension = ${PHP_EXT_DIR}/ioncube_loader_lin_8.2.so" | sudo tee /etc/php/8.2/mods-available/ioncube.ini
sudo phpenmod -v 8.2 ioncube
sudo systemctl restart php8.2-fpm
php -v   # Confirm "with the ionCube PHP Loader" appears in output
```

### 1.4 Recommended `php.ini` Settings
Ensure the following minimums in both `/etc/php/8.2/fpm/php.ini` and `/etc/php/8.2/cli/php.ini`:

```ini
memory_limit = 256M
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 120
date.timezone = UTC
```

---

## 2. Download & Overlay File Installation

`CloudHost247` is an **overlay repository** designed to be copied directly over a working WHMCS root directory (`$WHMCS_ROOT`). It never overwrites WHMCS core files (`init.php`, `configuration.php`, `clientarea.php`, etc.).

```bash
export WHMCS_ROOT="/var/www/whmcs"

# 1. Clone the repository into a staging directory
git clone https://github.com/windelslife-wq/CloudHost247.git /opt/cloudhost247-src
cd /opt/cloudhost247-src

# 2. Copy the overlay into $WHMCS_ROOT (excluding git, docs, CI, and test suites)
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

# 3. Copy the LTE Proxy hook bootstrap into WHMCS's global hooks folder
cp -f "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/hooks.php" \
      "$WHMCS_ROOT/includes/hooks/cloudhost247_lteproxy.php"

# 4. Seed the HostX CSS override file (preserves custom styles across updates)
if [ ! -f "$WHMCS_ROOT/templates/hostx/css/overrides/override.css" ]; then
  cp "$WHMCS_ROOT/templates/hostx/css/overrides/override.css.new" \
     "$WHMCS_ROOT/templates/hostx/css/overrides/override.css"
fi
```

---

## 3. Directory Permissions & Private Storage Setup

Create the private storage directory for `digitalproducts` **outside** the web root so release archives can never be downloaded directly via HTTP, and ensure runtime cache/log folders are writable by your web user (`www-data`):

```bash
export WEB_USER="www-data"
export WEB_GROUP="www-data"

# 1. Create private storage directory outside $WHMCS_ROOT
sudo mkdir -p /var/lib/cloudhost247/digitalproducts
sudo chown -R "$WEB_USER:$WEB_GROUP" /var/lib/cloudhost247/digitalproducts
sudo chmod 0750 /var/lib/cloudhost247/digitalproducts

# 2. Create module runtime cache, log, and rate-limiter directories
sudo mkdir -p \
  "$WHMCS_ROOT/modules/addons/cloudhost247_i18n/cache" \
  "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/cache" \
  "$WHMCS_ROOT/modules/servers/cloudhost247_lteproxy/logs" \
  "$WHMCS_ROOT/modules/servers/Smtphosting/storage/app/smtp-usage-ratelimit" \
  "$WHMCS_ROOT/modules/servers/Smtphosting/storage/config"

# 3. Ensure sitemap.xml is writable by the cron runner
sudo touch "$WHMCS_ROOT/sitemap.xml"

# 4. Set ownership across deployed files
sudo chown -R "$WEB_USER:$WEB_GROUP" "$WHMCS_ROOT"
sudo chmod 0664 "$WHMCS_ROOT/sitemap.xml"
```

---

## 4. Cryptographic Keys & Environment Variables

CloudHost247 encrypts all provider API tokens, kubeconfigs, registrar credentials, and license secrets with AES-256-GCM using keys supplied via environment variables.

### 4.1 Generate Your Environment Keys

```bash
cat <<EOF | sudo tee /etc/cloudhost247.env >/dev/null
# CloudHost247 Services (Registrar, AI & HTTP Infrastructure vault key)
CHS_CREDENTIALS_KEY=$(openssl rand -hex 32)

# CloudHost247 App & VPS Platform (cPanel, Kubernetes & Cloud provider vault key)
CH247APPS_ENCRYPTION_KEY_V1=$(openssl rand -hex 32)

# CloudHost247 Cloudflare Reseller vault key
CLOUDFLARE_ENCRYPTION_KEY=$(openssl rand -hex 32)
CLOUDFLARE_ENABLED=1

# Digital Products Marketplace storage & encryption key
DIGITALPRODUCTS_STORAGE=/var/lib/cloudhost247/digitalproducts
DIGITALPRODUCTS_ENCRYPTION_KEY=$(openssl rand -hex 32)

# Reverse proxy trust (comma-separated IPs/CIDRs; leave empty if not behind a proxy)
CLOUDHOST247_TRUSTED_PROXIES=
DOMAINBROKER_TRUSTED_PROXIES=
DOMAINBROKER_BASE_URL=https://your-whmcs-domain.com

# Optional provider secrets (fill in as needed)
CH247AI_API_KEY=
SOYOUSTART_AVAILABILITY_FEED_SECRET=
SMTPHOSTING_USAGE_SECRET=
SMTPHOSTING_LOGS_SECRET=
TOOLS_API_TOKEN=$(openssl rand -hex 24)
TOOLS_API_SECRET=$(openssl rand -hex 32)
VIRUSTOTAL_API_KEY=
IPGEOLOCATION_API_KEY=
EOF

sudo chmod 0640 /etc/cloudhost247.env
sudo chown root:"$WEB_GROUP" /etc/cloudhost247.env
```

### 4.2 Expose Environment Variables to PHP-FPM
Add the key definitions to your PHP-FPM pool configuration (e.g. `/etc/php/8.2/fpm/pool.d/www.conf`) so web requests can seal/unseal credentials:

```bash
sudo awk -F= '/^[A-Z0-9_]+=.+/ {print "env[" $1 "] = \"" substr($0, index($0,$2)) "\""}' /etc/cloudhost247.env \
  | sudo tee -a /etc/php/8.2/fpm/pool.d/www.conf >/dev/null

sudo systemctl restart php8.2-fpm
```

---

## 5. Composer Dependencies (Passkey & Phone Services)

Every module includes a standalone `autoload.php`, so WHMCS boots cleanly even without Composer. To enable live WebAuthn cryptographic ceremonies in `cloudhost247passkey` and live Twilio/Vonage SDKs in `phoneservices`, install their Composer packages:

```bash
sudo -u "$WEB_USER" composer install --no-dev --optimize-autoloader \
  -d "$WHMCS_ROOT/modules/addons/cloudhost247passkey"

sudo -u "$WEB_USER" composer install --no-dev --optimize-autoloader \
  -d "$WHMCS_ROOT/modules/addons/phoneservices"
```

---

## 6. Web Server Configuration (Apache & Nginx)

### Option A: Apache (`.htaccess`)
The repository includes a `.htaccess` file in `$WHMCS_ROOT` that routes `/tools` and `/tools/<slug>` to `modules/addons/CloudHost247_tools/front.php`. Ensure `AllowOverride All` and `mod_rewrite` are enabled on your WHMCS `<Directory>`, and verify these rules sit **above** WHMCS's Friendly URL rules in `$WHMCS_ROOT/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # 1. CloudHost247 Tools (/tools/*) front controller
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^tools(?:/(.*))?$ modules/addons/CloudHost247_tools/front.php?route=$1 [QSA,L]

    # 2. Deny direct HTTP access to crons, tests, migrations, and internal storage
    RewriteRule ^(?:crons|modules/.*/(?:tests|install|upgrade|storage))/ - [F,L]
</IfModule>
```

### Option B: Nginx
In your Nginx `server { ... }` block, add these locations before the default `location /`:

```nginx
# Block direct HTTP access to crons, tests, migrations, and internal storage
location ~* ^/(crons|modules/.*/(tests|install|upgrade|storage))/ {
    deny all;
    return 404;
}

# Route /tools and /tools/<slug> to CloudHost247_tools front controller
location ^~ /tools {
    try_files $uri $uri/ /modules/addons/CloudHost247_tools/front.php?route=$uri&$args;
}
```

Reload your web server (`sudo systemctl reload apache2` or `sudo systemctl reload nginx`).

---

## 7. Theme, Mega Menu & Order Form Installation

1. Log in to the WHMCS Admin Area (`https://your-whmcs-domain.com/admin`).
2. Go to **Configuration (System Settings) → General Settings → General**:
   * Set **System Theme** to **`Hostx`**.
   * Click **Save Changes**.
3. Go to **Configuration → General Settings → Ordering**:
   * Set **Default Order Form Template** to **`Hostx`**.
   * Click **Save Changes**.
   * *(Note: For OVH/SoYouStart product groups, set the Product Group order form template to **`ovh_cart`** under **System Settings → Products/Services → Edit Group**).*
4. **Top Mega Menu:**
   * Once `cloudhost247services` is activated (Section 8.2 below), its `ClientAreaPage` hook automatically injects the 4 top-level mega-menu categories (`Domains`, `Websites & Builders`, `Marketing`, `Hosting & Services` — 33 destinations) into HostX.
   * If you previously created manual top-level menu entries with those same names in **Addons → HostX → Menu Manager**, delete the duplicate manual entries.
5. **Announcement Bar (`templates/hostx/includes/announcementbar.tpl`):**
   * Already integrated into `templates/hostx/header.tpl`. To populate custom scrolling announcements, assign `$announcementBarMessages` via a WHMCS hook (see `templates/hostx/includes/announcementbar-hook.example.php`).

---

## 8. Addon Modules — Step-by-Step Installation & Setup

In WHMCS Admin, navigate to **Configuration (System Settings) → Addon Modules**. Activate and configure the modules below.

> **Important — Retired Module (`hostx_tools`):** Do **not** activate **HostX Tools** (`modules/addons/hostx_tools`). If it is active on an existing install, click **Deactivate**. All of its functionality is superseded by **CloudHost247 Tools** (`CloudHost247_tools`).

### 8.1 CloudHost247 Internationalization (`cloudhost247_i18n`)
1. Click **Activate** next to **CloudHost247 Internationalization**.
2. Grant **Full Administrator** access and click **Save Changes**.

### 8.2 CloudHost247 Services (`cloudhost247services`)
1. Click **Activate** next to **CloudHost247 Services** (automatically runs migrations `0001`–`0014`).
2. Configure settings:
   * **Enable Services Suite (`service_enabled`):** Checked (`on`)
   * **System URL (`system_url`):** `https://your-whmcs-domain.com/`
   * Grant **Full Administrator** access and click **Save Changes**.
3. Open **Addons → CloudHost247 Services**:
   * **Domain Providers:** Add your write-capable HTTP registrar provider (credentials are sealed under `CHS_CREDENTIALS_KEY`). Without a provider, domain search/WHOIS/pricing still work via WHMCS's built-in registrar chain.
   * **Infrastructure → Providers & Images:** Add your HTTP infrastructure provider (`base_url`, endpoint map, and API key) and map OS catalog versions (`Ubuntu 24.04`, `Debian 12`, etc.) to provider image IDs.

### 8.3 CloudHost247 Tools (`CloudHost247_tools`) & Tools Center (`tools_center`)
1. Activate **CloudHost247 Tools** (`CloudHost247_tools`):
   * Configure **OCR.space API Key (`ocr_api_key`)** if offering the opt-in server-side Image-to-Text OCR tool.
   * Grant **Full Administrator** access and click **Save Changes**.
   * Verify `https://your-whmcs-domain.com/tools` loads the 91-tool catalog.
2. *(Optional)* Activate **Tools Center** (`tools_center`):
   * In `modules/addons/tools_center/external-api/config.php`, verify `api_token` and `api_secret` match `TOOLS_API_TOKEN` and `TOOLS_API_SECRET`.

### 8.4 CloudHost247 App & VPS Platform (`cloudhost247apps`)
1. Activate **CloudHost247 App & VPS Platform** (`cloudhost247apps`) and grant **Full Administrator** access.
2. Open **Addons → CloudHost247 Apps**:
   * Register your **Control Panels** (cPanel/WHM servers) and **Kubernetes Clusters** (kubeconfig sealed under `CH247APPS_ENCRYPTION_KEY_V1`).
   * Register cloud infrastructure credentials (Hetzner, DigitalOcean, Vultr, or Contabo read-only adoption).

### 8.5 CloudHost247 Cloudflare Reseller (`cloudhost247cloudflare`)
1. Activate **CloudHost247 Cloudflare** (`cloudhost247cloudflare`) — runs migration `0001_cloudflare_core.php`.
2. Open **Addons → CloudHost247 Cloudflare**:
   * **Accounts:** Add a Cloudflare account (`API Base URL` must be `https://api.cloudflare.com/client/v4`, plus your Cloudflare Account ID and API Token) and click **Test**.
   * **Package Mappings:** Map your WHMCS hosting products or product addons to Cloudflare plans (`free`, `pro`, `business`, `enterprise`), zone mode (`full` or `partial`), and default SSL/security settings.

### 8.6 CloudHost247 Passkey (`cloudhost247passkey`)
1. Activate **CloudHost247 Passkey** (`cloudhost247passkey`) and grant **Full Administrator** access.
2. Open **Addons → CloudHost247 Passkey → Settings**:
   * Set **Relying Party ID (RP ID)** to your domain (e.g. `your-whmcs-domain.com`).
   * Set **Expected Origin** to `https://your-whmcs-domain.com`.

### 8.7 CloudHost247 AI Control Plane (`cloudhost247ai`)
1. Activate **CloudHost247 AI** (`cloudhost247ai`) and grant **Full Administrator** access.
2. Open **Addons → CloudHost247 AI → Settings**:
   * Configure your OpenAI-compatible model endpoint URL and model name (and set `CH247AI_API_KEY` in `/etc/cloudhost247.env`).
   * Keep **Writes Enabled (`writes_enabled`)** set to `off` (`false`) unless explicitly enabling human-approved write tools.

### 8.8 CloudHost247 Email Marketing (`cloudhost247marketing`) & Cart Recovery (`cloudhost247_cart_recovery`)
1. Activate **CloudHost247 Email Marketing** (`cloudhost247marketing`):
   * Configure SMTP relay host, port, TLS mode (`tls` recommended), and sender identity under **Addons → CloudHost247 Marketing → Settings**.
2. Activate **CloudHost247 Cart Recovery** (`cloudhost247_cart_recovery`):
   * Configure reminder intervals, token lifetime (default 7 days), and email templates under **Addons → CloudHost247 Cart Recovery**.

### 8.9 Digital Products Marketplace (`digitalproducts`)
1. Activate **Digital Products** (`digitalproducts`) and grant **Full Administrator** access.
2. Open **Addons → Digital Products → Settings**:
   * Confirm the private storage path resolves to `/var/lib/cloudhost247/digitalproducts` (`DIGITALPRODUCTS_STORAGE`).
   * Set **Default License Domain Limit (`license_default_domain_limit`)** and **Default License Activations Limit (`license_default_activations_limit`)** (`0` = unlimited).

### 8.10 Domain Broker (`domainbroker`), Phone Services (`phoneservices`) & Custom Affiliate (`customaffiliate`)
1. Activate **Domain Broker** (`domainbroker`) — configure brokerage commission rates and RDAP/DNS lookup settings.
2. Activate **Phone Services** (`phoneservices`):
   * Enter Twilio / Vonage / eSIM credentials under **Settings** (stored encrypted under `enc:v1:`).
   * Issue scoped per-client API keys under **Addons → Phone Services → Client API Keys** (`P-5`).
   * Point your Twilio Status Callback and Vonage DLR webhook URLs to `https://your-whmcs-domain.com/modules/addons/phoneservices/webhooks.php`.
3. Activate **Custom Affiliate** (`customaffiliate`) — configure product-group commission tiers and payout rules.

### 8.11 SMM Addon (`smmaddon`) & OVH / SoYouStart Addon (`soyoustart`)
1. Activate **SMM Addon** (`smmaddon`):
   * Configure your primary SMM Panel HTTPS `api_url` (`https://...`) and `api_key` (encrypted at rest via `enc:v1:`), or map per-server credentials under **Server Mappings**.
2. Activate **SoYouStart / OVH** (`soyoustart`):
   * Under **Addons → SoYouStart → API Credentials**, add your OVH API credentials (`application_key`, `secret_key`, `consumer_key`, `endpoint`). Ensure an active **Canada (`ca`)** credential is present if using the Admin OS template list.
   * Under **IMAP Settings**, enter and save your mailbox credentials (stored encrypted via `enc:v1:`).

---

## 9. Server Provisioning Modules — Product & Server Setup

Navigate to **Configuration (System Settings) → Products/Services** to bind products to CloudHost247's server modules under the **Module Settings** tab:

| Server Module (`modules/servers/<name>`) | Product Configuration Notes |
|---|---|
| **`cloudhost247cloudflare`** | Select **CloudHost247 Cloudflare**. Optionally set **Plan Override** and **Auto Provision on Create** (`yes`/`no`). Also maps via the addon's **Package Mappings** screen. |
| **`smmprovisioning`** | Select **SMM Provisioning**. Set `SMM Service ID`, `Min Quantity`, and `Max Quantity`. Create product Custom Fields for `Link` (text) and `Quantity` (text), **and** a `Quantity` Configurable Option so paid-quantity enforcement (`M-5`) derives the paid ceiling from the customer's order. |
| **`soyoustart`** | Dedicated OVH/SoYouStart servers. Select OVH Account, Datacenter Location, Plan Code, and Default OS template. |
| **`soyoustart_vps`** | OVH/SoYouStart VPS products. Select OVH Account, Location, VPS Model, and optional Snapshot / Automated Backup / Additional IP pricing. |
| **`hostx_email`** | Email hosting provisioning. Add your email server under **System Settings → Servers** (`hostx_email`); credentials are sealed with `hxem:v1:` AES-256-GCM. |
| **`Smtphosting`** | SMTP relay provisioning. Set `SMTPHOSTING_USAGE_SECRET` and `SMTPHOSTING_LOGS_SECRET` in `/etc/cloudhost247.env` for client-area usage/log charts (`smtp-api.php`). |
| **`RDP`** | RDP reseller provisioning (`rdparena.com`). Add the server token under **System Settings → Servers** (`RDP`), then select the upstream plan from the product's **Module Settings** dropdown. |
| **`cloudhost247_lteproxy`** | LTE proxy provisioning (`api.cloudhost247.com`). Configure API Key, API Secret, Default Region/Carrier, and Timeout (`30`s) on the product's **Module Settings** tab. Ensure `includes/hooks/cloudhost247_lteproxy.php` was copied in Step 2.2. |

---

## 10. Payment Gateway Installation (Blockonomics)

1. Navigate to **Configuration (System Settings) → Payment Gateways**.
2. Under **All Payment Gateways**, click **Blockonomics (`blockonomics`)** to activate it.
3. Open **Addons → CloudHost247 Services → Blockonomics** to configure your API key, xPubs, callback secret, and optional Web3/USDT settings (`docs/BLOCKONOMICS.md`).
4. Verify the callback URL in Blockonomics points to:
   `https://your-whmcs-domain.com/modules/gateways/callback/blockonomics.php?secret=<your-callback-secret>`

---

## 11. Cron Jobs Installation

Install `/etc/cron.d/cloudhost247` so all background workers, domain/server provisioning queues, and OVH/SoYouStart sync jobs run under the CLI SAPI with environment keys loaded:

```bash
cat <<'EOF' | sudo tee /etc/cron.d/cloudhost247 >/dev/null
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
BASH_ENV=/etc/cloudhost247.env

# 1. Core Addon Queue & Maintenance Workers (every 5 minutes)
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247services/cron/cloudhost247services.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247apps/cron/cloudhost247apps.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247ai/cron/cloudhost247ai.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/digitalproducts/cron/digitalproducts.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/domainbroker/cron/domainbroker.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/modules/addons/cloudhost247_cart_recovery/cron.php >/dev/null 2>&1

# 2. OVH / SoYouStart Synchronization Crons
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/crons/getServer.php >/dev/null 2>&1
*/5 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/crons/getIpStatus.php >/dev/null 2>&1
*/10 * * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/crons/emailSend.php >/dev/null 2>&1
15 2 * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/crons/priceSync.php >/dev/null 2>&1

# 3. Internationalization Currency Rate Cache Warm (every 6 hours)
0 3,9,15,21 * * * www-data source /etc/cloudhost247.env && /usr/bin/php -q /var/www/whmcs/crons/currencyRateRefresh.php >/dev/null 2>&1
EOF

sudo chmod 0644 /etc/cron.d/cloudhost247
```

---

## 12. Post-Installation Verification & Troubleshooting

### 12.1 Quick Verification Commands
Run these checks from the server shell after completing Steps 1–11:

```bash
# 1. Verify CLI cron execution works without errors
sudo -u www-data bash -c 'source /etc/cloudhost247.env && php -q /var/www/whmcs/modules/addons/cloudhost247services/cron/cloudhost247services.php'

# 2. Verify /tools front controller responds with HTTP 200
curl -sI https://your-whmcs-domain.com/tools | head -n 5

# 3. Verify internal crons/ and tests/ paths are blocked over HTTP (expect 403 or 404)
curl -sI https://your-whmcs-domain.com/crons/priceSync.php | head -n 5
```

### 12.2 Common Troubleshooting

| Symptom | Cause | Resolution |
|---|---|---|
| Blank screen or `exit(199)` on HostX pages | `ionCube Loader` extension is not loaded in PHP-FPM | Follow [Section 1.3](#13-install-ioncube-loader-required-for-hostx--xtreme_currency_rates) and restart `php-fpm`. |
| `ConfigurationException: set the CHS_CREDENTIALS_KEY environment variable` when saving a provider in CloudHost247 Services | `CHS_CREDENTIALS_KEY` is missing from PHP-FPM's environment | Verify `env[CHS_CREDENTIALS_KEY]` is present in `/etc/php/8.x/fpm/pool.d/www.conf` and restart `php-fpm` ([Section 4.2](#42-expose-environment-variables-to-php-fpm)). |
| `/tools/dns-lookup` returns a 404 or redirects to WHMCS homepage | WHMCS catch-all rewrite rule runs before the `/tools` rule | Move the `RewriteRule ^tools(?:/(.*))?$` block **above** WHMCS's friendly URL rules in `$WHMCS_ROOT/.htaccess` ([Section 6](#6-web-server-configuration-apache--nginx)). |
| Duplicate `Domains` or `Marketing` menus in the top navigation bar | Hand-made HostX menu items exist alongside the automatic `cloudhost247services` hook | Delete the old manual top-level items in **Addons → HostX → Menu Manager** ([Section 7](#7-theme-mega-menu--order-form-installation)). |
| Digital product upload fails with storage error | `DIGITALPRODUCTS_STORAGE` directory is inside `$WHMCS_ROOT` or not writable by `www-data` | Ensure `DIGITALPRODUCTS_STORAGE` points outside `$WHMCS_ROOT` (e.g. `/var/lib/cloudhost247/digitalproducts`) and is owned by `www-data` ([Section 3](#3-directory-permissions--private-storage-setup)). |
