# CloudHost247

WHMCS customisation overlay for **CloudHost247 Isc** — client-area theme, custom
landing pages, order forms and the in-house / third-party WHMCS modules.

This repository is an **overlay**: its contents are copied on top of a standard
WHMCS installation. Core WHMCS files (`init.php`, `clientarea.php`, `cart.php`,
`includes/…` core libraries, `templates/six`, `templates/orderforms/standard_cart`,
…) are intentionally **not** stored here.

## Repository layout

```
.
├── *.php                       Custom client-area landing & legal pages
│                               (deploy to the WHMCS root)
├── crons/                      OVH / SoYouStart sync cron scripts
├── includes/
│   └── hostx_page_functions.php  Shared helpers for the landing pages
├── lang/overrides/             Language overrides
├── templates/
│   ├── hostx/                  "Hostx" client-area theme (tpl, css, js, images,
│   │                           fonts, includes, hostx_includes, marketconnect …)
│   ├── orderforms/hostx/       Matching order form template
│   └── orderforms/ovh_cart/    OVH / SoYouStart order form (parent: standard_cart)
├── modules/
│   ├── addons/                 WHMCS addon modules
│   ├── servers/                WHMCS provisioning (server) modules
│   └── gateways/               WHMCS payment gateways (+ callback/)
└── docs/                       Build / installation notes shipped with the modules
```

## Deployment

Copy the tree over your WHMCS root, preserving paths:

```bash
rsync -av --exclude '.git' --exclude 'docs' ./ /path/to/whmcs/
```

Then activate the modules you need from **Configuration → Addon Modules /
Payment Gateways**, and assign the provisioning modules to the relevant products.

See [`docs/MODULES.md`](docs/MODULES.md) for the full module inventory,
per-module install notes and the duplicate-resolution decisions.

## Notes

* `templates/hostx/css/overrides/override.css` and
  `templates/hostx/js/overrides/override.js` are the live override files
  (seeded from the shipped `*.new` templates). Edit these for local tweaks so
  theme updates do not clobber your changes.
* `modules/addons/phoneservices` has optional Composer dependencies. Run
  `composer install` inside that directory to enable the Twilio/Vonage/eSIM
  providers; without it the module's own classes still autoload via
  `modules/addons/phoneservices/autoload.php`.
* `modules/addons/xtreme_currency_rates` and `modules/addons/hostx` are
  ionCube-encoded and require the ionCube Loader PHP extension.
* The OVH / SoYouStart crons in `crons/` expect to run from the WHMCS root
  (`php -q crons/priceSync.php`). Suggested schedule: `getServer.php` and
  `getIpStatus.php` every 5 minutes, `emailSend.php` every 10 minutes,
  `priceSync.php` daily.
