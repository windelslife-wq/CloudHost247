# Installation

1. Copy the repository overlay onto a WHMCS 8.x installation.
2. Set `DIGITALPRODUCTS_STORAGE` outside the web root and `DIGITALPRODUCTS_ENCRYPTION_KEY` in the PHP-FPM/cron environment.
3. Create the WHMCS email template `Digital Product Download Info`.
4. Activate **CloudHost247 Digital Products** under WHMCS Addon Modules. Migrations are additive and preserve legacy tables.
5. Grant the WHMCS admin role access, link a real WHMCS product, upload a staged release, publish it, and run a paid staging order.
6. Add `*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/digitalproducts/cron/digitalproducts.php`.

The storage path must be writable by PHP and must not be below `ROOTDIR`. Never use a public uploads directory.