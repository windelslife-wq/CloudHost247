# Upgrade and migration

Upload the updated `modules/addons/digitalproducts/` directory and run the WHMCS addon upgrade. `digitalproducts_upgrade()` applies every missing migration in lexical order and records it in `mod_digitalproducts_migrations`.

Migrations copy legacy `mod_digitalproducts_files` rows into `mod_digitalproducts_versions`, backfill entitlements from active `tblhosting` services, hash legacy license/API credentials, and add audit/token columns. The legacy file table is intentionally retained for verification; it is read-only after migration.

Before an upgrade, back up the WHMCS database, the configured private storage root and `DIGITALPRODUCTS_ENCRYPTION_KEY`. Do not drop tables or rotate the encryption key without a planned re-encryption operation. Verify an existing customer download and the paid-order hook after upgrading.