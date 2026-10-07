# CloudHost247 Passkey & Passwordless Authentication

Native WHMCS addon (`modules/addons/cloudhost247passkey`) that adds
WebAuthn/FIDO2 Passkey sign-in for CloudHost247 clients and administrators.
It is a **customization overlay**: it reuses WHMCS identities, sessions, mail,
and database connectivity, and never replaces WHMCS password login, 2FA, or
account recovery. Everything is disabled by default and fails closed.

## 1. Architecture

```
Browser WebAuthn API ⇄ Client UI / Admin UI (passkey.js)
        ⇄ Addon JSON boundary (index.php?m=cloudhost247passkey / addonmodules.php)
        ⇄ Ceremony services (WebAuthnService + web-auth/webauthn-lib)
        ⇄ Challenge store (one-use, session/RP/origin-bound)
        ⇄ Credential store (public sources only)
        ⇄ WHMCS handoff (native session + existing 2FA preserved)
        ⇄ Audit events + login notifications (localAPI mail)
```

Key boundaries:

- **Cryptography stays in `web-auth/webauthn-lib` 3.3.12** (pinned). The addon
  never implements signature, attestation, or counter verification itself.
- **No private keys, biometrics, PINs, or secrets** are received or stored.
  Only public credential sources, counters, transports, device names, and
  lifecycle metadata are persisted.
- **No parallel sessions or accounts.** Client login sets the standard WHMCS
  `uid`/`upw` session; admin login sets the standard `adminid` session, both
  with a regenerated session ID.
- **Existing 2FA is never bypassed.** Accounts with WHMCS 2FA enabled keep the
  standard password-plus-2FA login (documented Option C); Passkey returns a
  `two_factor` next step instead of a session for those accounts.

## 2. Installation

1. Copy the repository overlay onto the WHMCS installation (see root README).
2. Install the pinned PHP dependencies inside the addon directory:
   ```sh
   cd modules/addons/cloudhost247passkey
   composer install --no-dev
   ```
   Required PHP extensions: `json`, `mbstring`, `openssl`, `simplexml`,
   `bcmath`. PHP floor is 7.4 (legacy; prefer PHP 8.2+ on new deployments).
3. Activate **CloudHost247 Passkey** under Setup → Addon Modules. Activation
   runs forward-only migrations and preserves all existing data.
4. Verify **Addons → CloudHost247 Passkey → Diagnostics** before enabling.

Deactivation preserves credentials, policy, and audit history. Nothing is
dropped automatically.

## 3. Database migration

Migrations live in `install/migrations/` and run via activation/upgrade:

| Migration | Contents |
|---|---|
| `0001_passkey_core` | `mod_ch247pk_*` credentials, challenges, events, settings, user policies, preferences, reset grants, rate limits, external identities, migration ledger |
| `0002_webauthn_core` | Serialized public credential sources, opaque WebAuthn user handles |
| `0003_passkey_phase11` | Notification switches, sensitive-action policy, Entra ID settings |

Conventions: parameterized queries only, domain-separated hashes for
challenges/sessions/rate-limit principals (raw values never stored), unique
credential hashes across identity types, append-only events, no foreign keys
into WHMCS-owned tables.

Rollback: migrations are forward-only by design (credentials and audit history
are never auto-dropped). To roll back a release, deactivate the addon,
restore the previous code, and keep the tables; if a full removal is ever
required, drop the `mod_ch247pk_*` tables explicitly after exporting audit
history.

## 4. Configuration

All settings live in **Addons → CloudHost247 Passkey → Settings** (Super
Admin only). Sensitive changes can require step-up Passkey confirmation (see
§10).

| Setting | Meaning |
|---|---|
| Service switch | Master on/off. Off until RP/origin/HTTPS verify. |
| Client / admin policy | `optional` or `required` per audience. |
| Password fallback | `allowed` (default) or `disabled when Passkey is required`. |
| Max Passkeys | 1–50 or unlimited (bounded at 1000) per audience. |
| User verification | `preferred` (default), `required`, `discouraged`. |
| Sensitive-action verification | `required` (default) or `preferred`. |
| Sensitive-action step-up | `disabled`, `optional`, `required_for_admin`, `required`. |
| RP name / RP ID / origins | See §5. |
| Notifications | Global login + security-event switches (users still opt in). |
| Retention | Event days (1–3650), challenge hours (1–720); cron purges. |

## 5. RP ID and origin configuration

- **RP ID** must be the effective authentication domain
  (for example `portal.cloudhost247.com`). Never use a development placeholder
  in production.
- **Allowed origins** is an exact allowlist of canonical `https` origins, one
  per line (for example `https://portal.cloudhost247.com`). Scheme, host, and
  port must match exactly; paths, credentials, and `http` are rejected.
- The addon independently compares the full request origin before invoking
  the library (the pinned library only performs an RP-ID suffix check).
- Misconfiguration returns `CONFIGURATION_REQUIRED` and refuses ceremonies.

## 6. HTTPS requirements

Production ceremonies require verified HTTPS. TLS state comes only from the
PHP/server environment (`HTTPS=on` or port 443); `X-Forwarded-*` headers are
never trusted. Deployments behind a TLS-terminating proxy must set `HTTPS`
at the PHP layer. Without verified HTTPS the system fails closed with
`CONFIGURATION_REQUIRED`.

## 7. Client setup

1. Administrator enables the service and completes §5–§6.
2. Client signs in with their password and opens **Passkeys** (nav, sidebar,
   or `index.php?m=cloudhost247passkey`).
3. **Add Passkey** starts a WebAuthn registration ceremony (Touch ID, Face ID,
   Windows Hello, security key, or password-manager passkey).
4. Clients can register multiple devices, rename, disable/enable, revoke,
   review recent activity, and opt into login/security notifications.
5. At login, **Sign in with Passkey** uses discoverable (usernameless)
   credentials: the browser/OS account picker selects the Passkey, so the
   login endpoint cannot be used to enumerate accounts.

## 8. Admin setup

1. Complete §5–§6, then open **Addons → CloudHost247 Passkey**.
2. **My Passkeys** registers the administrator's own credentials (each admin
   manages only their own here).
3. The admin login page gains **Sign in with Passkey** once the admin policy
   is `optional` or `required`. Client credentials can never authenticate as
   administrators (audience isolation end to end).
4. Super Admins use **Credentials** (view/rename/revoke/disable/enable),
   **Policy** (per-identity optional/required/temporary-disable with reason),
   **Activity Log**, **Settings**, and **Diagnostics**.

## 9. Microsoft Entra ID (optional)

Entra ID is disabled by default and is not required for Passkey operation.
Configuration (**Entra ID** tab): enable switch, tenant ID, client ID,
redirect URI, allowed domains, client/admin login switches, and an encrypted
client secret (stored via WHMCS encryption, never displayed or committed).

Current status: settings and the host-verified linking boundary
(`ExternalIdentityLinkService`, challenge-bound link/unlink with audit) are
implemented. Interactive OAuth sign-in is **not** enabled by this version and
reports `CONFIGURATION_REQUIRED`; account linking requires host-verified
claims and never links on email similarity alone. Full OIDC code exchange
with JWKS validation is scheduled as the next phase with a dedicated JWT
dependency.

## 10. Sensitive-action confirmation

`PasskeyActionConfirmationService` binds a short-lived (120s), one-use ticket
to the authenticated user, intended action, session, RP ID, and origin. The
ticket is consumed only after a fresh assertion for the same identity
verifies. Supported actions: `account.security`, `payment.config`,
`admin.privileges`, `config.delete`, `auth.policy`, `security.disable`,
`credential.revoke`, `credential.disable`, `entra.link`, `entra.unlink`,
`password.reset`.

Enforcement follows the sensitive-action step-up setting: administrators
confirm settings/policy/credential changes when `required_for_admin` or
`required`; clients confirm credential revoke/disable when `required`.

## 11. Passkey-assisted password reset

From the login page, **Reset password using Passkey** runs a discoverable
ceremony. The verified assertion resolves the account (no email enumeration),
a one-use session-bound grant is issued (token stays server-side), and the
user sets a new password (12+ characters) through the supported WHMCS
`UpdateClient` API. Other sessions die on next request via WHMCS password-hash
session validation. A security notification is sent when enabled.

## 12. Login notifications and activity log

- Notifications are double-gated: global switch **and** per-user preference.
  Delivery uses WHMCS `localAPI` mail with server-side content (method,
  UTC time, IP, browser/device, guidance). Delivery failures are audited and
  never block authentication.
- The append-only event log records registration, authentication success/
  failure, renames, revokes, disables/enables, resets, confirmations, policy
  changes, admin management, rate-limit hits, recovery grants, and Entra
  link/unlink — with user scope, timestamps, IP, user agent, and allowlisted
  metadata only.

## 13. Recovery

- Losing all Passkeys never bricks an account while password fallback is
  allowed (the default): standard WHMCS login and password reset keep working.
- If fallback was disabled, a Super Admin sets a **temporary exemption**
  (bounded expiry, reason-coded, audited) on the affected identity, restoring
  password access without touching credentials.
- There is no hidden bypass and no unauthenticated enforcement disable.
- Emergency procedure is restricted to Super Admins and fully audited.

## 14. Security model

- Fail closed: `CONFIGURATION_REQUIRED` (missing RP/origin/HTTPS/settings),
  `SERVICE_UNAVAILABLE` (missing platform dependency), generic
  `AUTHENTICATION_FAILED` (no oracle for attackers).
- One-use challenges (5 min), action tickets (2 min), reset grants (5 min),
  all session/RP/origin-bound with atomic consumed-state updates.
- Fixed-window rate limits on registration, login, reset, confirmation, and
  management; hashed principals; 429 with `Retry-After` semantics.
- CSRF via WHMCS `check_token()` (fallback: session token + header) on all
  authenticated state changes; public ceremony routes are rate-limited like
  the WHMCS login flow.
- Session fixation: session ID regenerated at Passkey login; masquerading
  admins (logged in as client) cannot manage that client's Passkeys.
- Storage: public sources only; counter updates under optimistic concurrency;
  secrets as versioned ciphertext; no secret/biometric/ceremony bytes in logs.
- CSP: no global weakening; the addon ships dependency-free JS/CSS loaded
  only on Passkey and login pages.

## 15. 2FA interaction (Option C)

Passkey is an alternative primary method alongside existing WHMCS 2FA.
Accounts with WHMCS 2FA enabled must complete the standard login; Passkey
verification for those accounts returns `two_factor` instead of a session.
2FA configuration, secrets, and recovery are untouched.

## 16. Testing

From `modules/addons/cloudhost247passkey` (Composer available):

```sh
npm ci
composer install --no-dev
npm test
npm run lint
```

Runners honor `PHP_WASM_VERSION` (default `8.3`; `7.4` also supported).
The core suite covers registration/login ceremonies, management, policy,
recovery, rate limits, audit, linking, notifications, maintenance, policy
administration, and the Phase 11 HTTP boundary; the integration suite runs
real `none`-attestation registration plus ES256 assertion verification
through the pinned library. Lint tokenizes every shipped PHP file under both
runtimes.

Browser matrix (manual, per release): Chrome, Edge, Safari, Firefox ×
Windows Hello, Touch ID, Face ID, Android, iPhone, macOS, and at least one
FIDO2 security key. Unsupported browsers get a clear message; password
login always remains.

## 17. Deployment checklist

1. PHP version + extensions verified on the production host.
2. `composer install --no-dev` inside the addon (or deploy `vendor/`).
3. Activate the addon; confirm migrations applied (Diagnostics → database ok).
4. Configure RP ID + exact origins; confirm HTTPS detection.
5. Register a Super Admin Passkey; test admin login in a second browser.
6. Test client registration, login, revoke, reset, and notifications.
7. Set enforcement only after verifying recovery (§13) with a test account.
8. Confirm cron runs (DailyCronJob maintenance + activity log entries).
9. Review CSP headers on login/Passkey pages (no global weakening).

## 18. Known limitations

- Secondary WHMCS users (`tblusers`) can hold credentials, but login handoff
  for that scope reports `SERVICE_UNAVAILABLE` until the deployment verifies
  the exact `uid`/`user_id` session mapping on its WHMCS version.
- 2FA-enabled accounts use standard login (see §15); combined Passkey+2FA in
  one flow is not implemented.
- Interactive Entra ID sign-in is not enabled (see §9).
- Remember-me persistent cookies are not issued for Passkey logins.
- Per-role staff permissions beyond Super Admin scoping arrive in a later
  phase; staff currently manage only their own Passkeys.
