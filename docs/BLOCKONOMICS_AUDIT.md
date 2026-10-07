# Blockonomics — acceptance audit

Audit of the existing Blockonomics integration against the CloudHost247
crypto-payment specification, plus the fixes that audit produced.

**Scope note.** The feature was already built and merged (PR #5). This pass
verified it rather than rebuilding it, per the spec's own "exactly one
gateway, never duplicate it" rule. Everything below is a verdict on code
that is in the repository now.

Verdict key: **PASS** verified as already correct · **FIXED** a real gap was
found and closed in this pass · **N/A** the acceptance item assumes
infrastructure this repository does not have.

---

## 1. Architecture / no duplication

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 1 | Exactly one Blockonomics gateway | PASS | `ls modules/gateways/*.php` → only `blockonomics.php`. No `new-blockonomics.php`. |
| 2 | WHMCS remains the payment executor | PASS | All crediting is `addInvoicePayment()` inside WHMCS; no parallel payment system. |
| 3 | Existing API comms / address generation / conversion reused | PASS | `getNewAddress()`, `get_crypto_rate_from_params()`, `convertPercentPaidToInvoiceCurrency()` untouched; governance wraps, never replaces. |
| 4 | One callback endpoint, hardened in place | PASS | `modules/gateways/callback/blockonomics.php` is the only callback; no second endpoint added. |
| 5 | `blockonomics_orders` history preserved; additive migrations only | PASS | No `DROP TABLE` / `TRUNCATE` / `DELETE FROM` in any gateway PHP. `upgrade.php`'s `delete()` calls are `WHMCS\File` removing superseded *module files*, not rows. |

## 2. Server-side enforcement

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 6 | Enforcement is server-side, not UI-only | PASS | Three independent layers: `blockonomics.php:435` (checkout offer), `payment.php:53/80` (403), `blockonomics/blockonomics.php:631` (backstop before address generation). |
| 7 | POST body / query / hidden fields never trusted | PASS | Every gate re-resolves state from the governance store; nothing reads an "enabled" flag from the request. |
| 8 | Effective state = gateway ON ∧ currency ON ∧ valid config | PASS | `Policy::availabilityMatrix()`; master short-circuits every currency. |
| 9 | BTC/USDT never hard-coded as permanently enabled | PASS | No literal `true` default anywhere in the matrix; missing keys read false. |
| 10 | Disabled currency cannot reach address generation | PASS | `createNewCryptoOrder()` asserts before any address work; covered by tests. |
| 11 | **Unknown currency codes are refused, not defaulted open** | **FIXED** | `getActiveCurrencies()` previously special-cased `btc`/`usdt` and let anything else through on the stock checkbox alone. It now denies any code the policy layer does not model. |
| 12 | **Gateway ON with every currency OFF presents as unavailable** | **FIXED** | Previously the customer got a "Pay now" button and then an *empty* currency picker. `Policy::anyCurrencyAvailable()` / `Bridge::isCheckoutAvailable()` now gate both the invoice link and `payment.php`. |

## 3. Credentials

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 13 | API key server-side only — never to browsers/HTML/cookies/logs | PASS | No `ApiKey` reference in any `.tpl`; admin template renders `api_key_mask` only. Callback logging is sanitised. |
| 14 | Stored encrypted | PASS | `Vault` uses AES-256-GCM keyed from WHMCS `cc_encryption_hash`. |
| 15 | Saved credentials masked, "Replace" offered, never revealed | PASS | `Vault::MASK` fixed-width bullets; `replaceApiKey()` is write-only; no read-back path to the UI. |
| 16 | Secrets never written to audit records | PASS | `Vault::AUDIT_REDACTED` written instead of values; a test asserts the real key is absent from all tester output. |
| 17 | Test Connection is server-side with a sanitised result | PASS | `ConnectionTester` returns exactly `connected / auth_failed / invalid_configuration / provider_unavailable / connection_timeout`; the key never appears in the result. |

## 4. Money integrity

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 18 | No "Mark as Paid" button anywhere | PASS | Zero matches for `mark as paid` / `markpaid` / `force_paid` across gateway and admin code. The transaction console is read-only. |
| 19 | Never credit on browser-asserted success | PASS | The only browser-supplied value is a candidate txid, regex-validated (`/^0x[0-9a-f]{64}$/i`) and stored as *reported, unverified*; crediting happens only in the server-side poller. |
| 20 | Provider responses never fabricated | PASS | Every amount/status originates from the callback or an Etherscan fetch. |
| 21 | Callback authentication is constant-time | PASS | `hash_equals` at `callback/blockonomics.php:61`. |
| 22 | Unknown addresses rejected | PASS | 404 at `callback/blockonomics.php:74`. |
| 23 | Callbacks idempotent / replay-guarded | PASS | `checkIfTransactionExists()` before `addInvoicePayment()`; terminal orders refuse re-credit via `Policy::creditAllowed()`. |
| 24 | A txid cannot be reused across invoices | PASS | `blockonomicsTransactionExists()` is a global lookup on `blockonomics_orders.txid`, so a hash already attached to one order is never attached to another. |
| 25 | Confirmation depth enforced before credit | PASS | `Policy::normalizeConfirmations()` (clamped 0–2) in both the callback and the poller; the poller returns early when depth is short or unobtainable. |
| 26 | Underpayment/overpayment handled explicitly | PASS | `Policy::classifyAmount()` → exact / under-slack (full credit) / under (proportional) / over / invalid. |
| 27 | Fail closed on missing config, failed verification, ambiguous status | PASS | Every `catch` around governance sets the denied matrix; the poller credits nothing when Etherscan data is incomplete. |
| 28 | USDT transfer validated on-chain, not trusted | PASS | Poller checks the ERC-20 `transfer` selector, the token contract address, and that the recipient equals the configured receiving address. |

## 5. USDT network handling

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 29 | Only provider-supported networks; none invented | PASS | `Policy::NETWORKS` is exactly `ethereum` + `sepolia`; `save()` throws on anything else. |
| 30 | Network always displayed next to the address | PASS | `Policy::networkDisplay()` → "USDT — Ethereum" / "USDT — Sepolia (Test Network)", passed into the checkout templates. |
| 31 | Wrong-network loss warning shown | PASS | Present in `crypto_options.tpl` and `web3_checkout.tpl`. |
| 32 | Test networks visibly flagged | PASS | `Policy::networkIsTest()` drives a badge in the admin panel. |

## 6. Administration

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 33 | Super Admin controls master switch + per-currency toggles | PASS | Admin → Addons → CloudHost247 Services → **Payments · Blockonomics**. |
| 34 | Confirmations, USDT network and receiving address configurable | PASS | Same panel; address validated as `0x`+40 hex. |
| 35 | No PHP editing required to configure in production | PASS | Every setting above is reachable from the admin UI. |
| 36 | Enabling a dead configuration is refused | PASS | `Governance::save()` validates readiness per currency and throws. |
| 37 | Crypto transactions listing with search + filters | PASS | `BlockonomicsTransactions::query()` — txid / invoice / customer / email search, currency + status + date filters, masked addresses. |
| 38 | All admin mutations audited | PASS | Per-setting governance audit lines plus module-level `Audit::admin()`; actor id and hashed IP recorded, raw IPs never stored. |
| 39 | CSRF protection on admin posts | PASS | `checkToken()` runs at `AdminPortal.php:68`, before `handlePost()`. |
| 40 | Existing CloudHost247 admin design language reused | PASS | The panel is a `.phtml` view using the module's existing components; no new UI framework. |

## 7. Bitcoin Cash (this pass)

| # | Acceptance | Verdict | Evidence |
|---|---|---|---|
| 41 | **BCH governed like BTC/USDT** | **FIXED** | BCH was previously governed only by its stock checkbox. It now has `bch_enabled` in the governance store, a toggle in the admin panel, `Policy::bchReadiness()`, server-side enforcement through the same three layers, legacy mirroring to `tblpaymentgateways.bchEnabled`, and audit lines. |
| 42 | **Upgrades must not silently disable a live BCH install** | **FIXED** | `Governance::backfillBch()` imports the legacy checkbox once on stores seeded before BCH was governed, audited as `governance.bch_backfilled`. Without it the missing key would have read fail-closed and stopped a working BCH install. |
| 43 | **Future upstream currencies cannot be silently unpayable** | **FIXED** | A test asserts `getSupportedCurrencies()` codes and `Policy::CURRENCIES` stay identical, so adding a coin upstream fails the suite loudly. |

## 8. Items whose premises do not exist in this repository

These acceptance items assume infrastructure that is not present. Each was
handled by the spec's own stated fallback rather than by inventing the
missing system.

| Acceptance | Status | What was done instead |
|---|---|---|
| Node payment service under `cloudhost247-node/src/payments/` | N/A | No `cloudhost247-node/` directory exists and no production Node payment flow is running. Per the constraint "do not migrate the WHMCS flow into Node unless an active Node payment flow already exists", the WHMCS gateway remains authoritative. |
| Node migrations under `cloudhost247-node/database/migrations/` | N/A | Governance tables are created idempotently by the gateway's own store (`mod_blockonomics_governance`, `mod_blockonomics_governance_audit`). |
| Central credential vault in `modules/addons/cloudhost247_integrations/` | N/A | That addon does not exist. Credentials live in the gateway's own `Vault.php` with the same encryption and masking guarantees, surfaced through the existing CloudHost247 Services console. |
| Repository `subwindels-hash/CloudHost247` | N/A | The actual remote is `windelslife-wq/CloudHost247`. |

## 9. Regression surface

- Other gateways: untouched — no shared file was modified.
- Invoices, orders, subscriptions, balances, checkout, client area: no
  schema or flow changes; governance only adds pre-flight denials.
- Legacy mirroring is additive (update-or-insert), never deletes rows.

## 10. Verification

```
cd modules/addons/cloudhost247services
node tests/run.mjs            # TOTAL PASS=777 FAIL=0
node tests/lint.mjs           # FILES=71 BAD=0
node tests/lint-root.mjs      # ROOT_LINT_OK
```

Blockonomics-specific: `11_BlockonomicsTest.php` 172 assertions,
`12_BlockonomicsAdminTest.php` 66 assertions.

BTC and USDT remain independently testable — each currency's toggle,
readiness rule and enforcement path is asserted separately, and BCH now has
equivalent coverage.
