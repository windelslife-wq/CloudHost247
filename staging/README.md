# Staging verification — no production deployment

This repository is a **WHMCS overlay**, not a redistributable WHMCS application.
It deliberately lacks the commercial core (`init.php`, installed core languages,
licensed configuration and database). See `docs/I18N_STATUS.md`: the rollout is
still incomplete site-wide. Neither this harness nor the recipe is production.

## 1. Live sandbox / local preview

```sh
npm ci --prefix staging/i18n-preview               # dev-only dependencies
node staging/i18n-preview/build.mjs                # native PHP, otherwise php-wasm
node staging/i18n-preview/server.mjs               # 0.0.0.0:4321
npm run test:browser --prefix staging/i18n-preview
```

Sandbox preview URL: **https://4321-it4r0er3fttme19zqbmki.e2b.app**
(the Live Preview on port 4321). Useful review paths:

- `/p/fr-eur-refund-policy`
- `/p/de-gbp-backup-policy`
- `/p/es-usd-faqs`
- `/p/pt-BR-ngn-help-center`
- `/p/fr-eur-terms-of-service`

325 snapshots cover 5 languages × 5 fixture currencies × 13 page contexts.
Eight contexts render the **actual translated body templates**, alongside real
switcher markup/CSS/JS. Smarty 4.5.6 is downloaded from its official GitHub
release with a pinned SHA-256; the dev dependency/cache is never deployed.
Language/currency links navigate equivalent fixture pages and retain independent
preferences in a harness-only cookie. No-JS links also work.

**This is not WHMCS.** It proves rendering, escaping, keyboard/viewport behaviour
and deterministic pricing rules, not login, taxes, live rates or an actual order.
NGN/USD/EUR/GBP fixture rates are clearly labeled as test data; JPY deliberately
has no rate. They must never be copied into live currency configuration.

## 2. Licensed WHMCS Docker recipe

**Not executed here.** Docker and licensed WHMCS/HostX files are not provided by
the sandbox. Use a separate licensed staging checkout and test-only database.

Prerequisites:

1. Place a licensed, PHP-8.2-compatible WHMCS 8.x installation at repository-root
   `whmcs/` (gitignored). Use the vendor's installation instructions and a staging
   hostname permitted by your WHMCS/HostX licenses.
2. Install the baseline CloudHost247 overlay and its required runtime addon
   dependencies. A fresh commercial WHMCS ZIP alone does not include HostX or
   `cloudhost247services`, which the existing pages depend on.
3. Supply the correct Linux x86-64 PHP 8.2 ionCube loader at
   `staging/ioncube/ioncube_loader_lin_8.2.so` (gitignored). Obtain it from the
   vendor; do not commit or send licensed files/credentials in chat.
4. Create a local, gitignored `staging/.env` with `STAGING_DB_PASSWORD`, an
   independently chosen **staging-only** database password. Defaults bind the
   web port to local loopback. If using a protected remote preview proxy, set
   `STAGING_BIND=0.0.0.0` and restrict public access through that proxy/firewall.

Copy the i18n changes after the baseline overlay is installed:

```sh
node staging/copy-overlay.mjs ./whmcs --staging --dry-run
node staging/copy-overlay.mjs ./whmcs --staging

docker compose --env-file staging/.env -f staging/docker-compose.yml up -d --build
```

The copier uses an explicit runtime allowlist and **never deletes core files**.
It does not copy test harnesses, dev packages, compiled caches or credentials.
Back up your staging checkout first; existing override files are replaced by the
reviewed repository versions.

Complete the installer at `http://localhost:8080/install/` if needed. Database
host: `db`; name/user: `whmcs_staging`; password: the value you set locally.
Set WHMCS **SystemURL to the actual browser-facing staging URL**, not localhost
when reviewing through a remote proxy. Do not point staging at production data.

Ensure writable runtime directories in this staging checkout, for example:

```sh
docker compose --env-file staging/.env -f staging/docker-compose.yml exec --user root whmcs \
  sh -c 'install -d -o www-data -g www-data -m 0770 /var/www/html/templates_c /var/www/html/modules/addons/cloudhost247_i18n/cache'
```

Then configure the real app:

- Set default language to English; verify the five core WHMCS languages exist.
- Activate **CloudHost247 Internationalization**. This creates only the additive
  browsing-preference table; it does not change billing currencies.
- Configure the currencies the shop actually supports in WHMCS. Use its native
  currency-rate automation/trusted configured feed; do not invent rates.
- Schedule/run WHMCS's **native daily cron** according to its own installation
  instructions. The optional `rate-cache` service only reloads configured rates
  after that automation; it does not fetch the feed.
- Import/review curated translations for installed product descriptions, HostX
  blocks/menus, knowledge-base articles and email templates. None of those live
  database contents can be certified by this source-only harness.

Run `staging/VERIFICATION.md` end to end, including login, guest/account cart,
taxes/discounts/billing cycles, historic invoices, complete menu/footer layouts,
and linguistic/legal review. Do not tick a real-WHMCS item based on a fixture
unit test alone.

## Production

**Not authorized or performed.** Finish the remaining content coverage, get real
staging sign-off, then obtain explicit production approval. Never use
`rsync --delete` from this overlay into WHMCS: absent licensed core files would
be deleted. Any later release procedure must back up core/database/configuration
and copy only reviewed runtime overlay files without deleting the core.
