# SoYouStart cron regressions

Run the offline suite with:

```sh
node crons/tests/run.mjs
```

`CronRegressionTest.php` uses PHP-WASM 8.3. It exercises the CLI guard, the
pure price-sync cursor helper, WHMCS email-log redaction, and a stubbed cURL
transport for Gmail URL/TLS/redirect/timeout rules. The four production cron
entry points are parsed and inspected as text only; they are never included or
executed by the test suite.

This suite does **not** establish live WHMCS, IMAP, Gmail, OVH, billing,
checkout, or IP-move behavior. Those jobs have external side effects and remain
owner/staging validation tasks; never run the scripts against production as a
test.
