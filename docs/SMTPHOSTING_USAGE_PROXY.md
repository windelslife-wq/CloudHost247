# Smtphosting usage and sent-log proxy

Module: `modules/servers/Smtphosting/`
Endpoint: `modules/servers/Smtphosting/smtp-api.php`
Tracker: `docs/MODULE_COMPLETION_TRACKER.md` (finding S-6)

## What it does

The client area shows a customer's mail usage and sent-mail log. The browser
calls this proxy, and the proxy calls the Smtphosting upstream API with the
shared upstream secrets. The browser never sees the secrets.

```
GET smtp-api.php?fn=usage&serviceid=<id>
GET smtp-api.php?fn=logs&serviceid=<id>[&page=<n>][&per_page=<1..100>]
```

| Parameter | Required | Notes |
|---|---|---|
| `fn` | yes | `usage` or `logs`. Anything else returns 400. |
| `serviceid` | yes | Positive integer ID of a `tblhosting` row owned by the logged-in client. |
| `page` | no | Integer, minimum 1, default 1. Used only by `logs`. |
| `per_page` | no | Integer, 1 to 100, default 10. Used only by `logs`. |

Any other request parameter is ignored. In particular, `user_name`,
`main_domain`, and `secret` are no longer accepted from the browser.

## Request flow and checks

1. WHMCS is bootstrapped (`init.php`), so the WHMCS session is available.
2. Only `GET` is accepted (405 otherwise).
3. The client ID comes from `$_SESSION['uid']`. Without it, the response is 401.
4. `fn` must be `usage` or `logs`, and `serviceid` must be a positive integer (400 otherwise).
5. The request is rate-limited per client: 100 requests per 300 seconds (429 when exceeded). If the limiter's storage cannot be written, the request fails closed with 503.
6. The service is looked up in `tblhosting` where `id = serviceid` and `userid = client ID`. If no row matches, the response is 404 (the same response as a nonexistent service, so ownership is not revealed).
7. The service's `username` and `domain` are used as the upstream `user_name` and `main_domain`. Values from the request are never used.
8. If the upstream secret for `fn` is not configured, the response is 503.
9. The upstream request is made over HTTPS only, with TLS verification on, no redirects followed, and an 8-second timeout.
10. Any non-200 upstream response, transport error, or non-JSON body returns a generic 502. Upstream bodies are never echoed.
11. Successful upstream JSON is returned unchanged, after any occurrence of a secret is replaced with `[redacted]`.

Error bodies have the shape `{"status":"error","msg":"..."}`. Exception details
are never returned to the browser.

### Behaviour changes from the previous public proxy

- Authentication is required. Previously, anyone who knew the shared secret could call it.
- The rate limit is per authenticated client, not per IP. The limit and window are unchanged.
- `per_page` is capped at 100. `page` and `per_page` are validated (previously unvalidated).
- Upstream non-200 status codes are no longer passed through. They return a generic 502.
- Non-JSON upstream bodies return 502 instead of being passed through.

## Configuring the upstream secrets (owner action)

The module reads two values:

| Name | Used for | Environment variable | File key |
|---|---|---|---|
| usage secret | `fn=usage` | `SMTPHOSTING_USAGE_SECRET` | `usage` |
| logs secret | `fn=logs` | `SMTPHOSTING_LOGS_SECRET` | `logs` |

Environment variables take precedence. The file is a fallback.

Option A (recommended): set the environment variables for the PHP process that
serves WHMCS (for example in the PHP-FPM pool or the web server's environment).

Option B: create `modules/servers/Smtphosting/storage/config/smtp-usage-secrets.php`:

```php
<?php
return [
    'usage' => 'PASTE_USAGE_SECRET_HERE',
    'logs'  => 'PASTE_LOGS_SECRET_HERE',
];
```

This file is git-ignored (`.gitignore`), so it is never committed. Set file
permissions so that only the web server user can read it. Keep the file out of
version control and backups that are shared with customers.

If either value is missing, the endpoint returns 503 for that `fn` and does not
call the upstream.

## Secret rotation (required after S-6)

The two previous shared secrets were committed to this repository and were
visible to every customer through the client-area page. Treat both as
compromised.

1. In the Smtphosting provider account, issue new usage and logs secrets and revoke the old ones. This must be done by the account owner. The agent cannot do it.
2. Configure the new values using Option A or Option B above.
3. Verify (see below), then confirm the old values are rejected by the upstream.
4. Optional: rewrite git history to remove the old values. This is not required once the old secrets are revoked upstream, and it was not done as part of this change.

## Verifying

Automated (no network, no WHMCS needed):

```
cd modules/servers/Smtphosting/tests
php run.php            # 39 tests, includes usage_proxy_tests.php
```

Manual, on a live WHMCS install, as a logged-in client who owns service `<id>`:

```
curl -s -b "<session cookie>" "https://<whmcs>/modules/servers/Smtphosting/smtp-api.php?fn=usage&serviceid=<id>"
```

- Expect a JSON body from the upstream, with no `secret` key.
- Expect 404 for a service ID that belongs to another client.
- Expect 401 without the session cookie.

## Limitations and open items

- The automated tests run on php-wasm, with a stub standing in for WHMCS `init.php` and `Capsule`. They do not call the real Smtphosting API (the sandbox has no outbound access to it, and php-wasm has no working cURL transport). The upstream response shape is the same as the original proxy's, but it has not been re-checked live.
- `tblhosting` columns `username` and `domain` are confirmed by the repo's `Core/Models/Whmcs/Hosting.php` model. The `Capsule` access pattern matches the rest of the module. The query has not been run against a live WHMCS database.
- The client-area template uses `$serviceid`, which the same template already uses in other links. Not checked against a running WHMCS page.
- An owner-run check on a live install is still needed before the module is marked verified.
- The rate limiter's counter files are kept under `storage/app/smtp-usage-ratelimit/` and are git-ignored.
