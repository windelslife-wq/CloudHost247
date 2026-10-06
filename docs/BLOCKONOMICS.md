# Blockonomics Cryptocurrency Gateway — CloudHost247 Governance

How the (single, existing) Blockonomics WHMCS gateway is governed,
hardened and administered inside CloudHost247.

**Authority rule:** there is exactly ONE payment executor —
`modules/gateways/blockonomics.php` + `modules/gateways/blockonomics/`
+ `modules/gateways/callback/blockonomics.php`. The CloudHost247 layer
controls *availability and configuration governance*; it never re-implements
address generation, conversion or on-chain verification.

## Architecture

```
Admin → Addons → CloudHost247 Services
    ├── Payments · Blockonomics        ← admin console (this layer)
    ├── Crypto Transactions            ← read-only order listings
    └── Audit Log                      ← module-wide audit

modules/gateways/blockonomics/
    cloudhost247/                      ← governance layer (new, self-contained)
        Governance.php                 authoritative runtime state
        Vault.php                      AES-256-GCM credential vault
        Policy.php                     pure rules (matrix, amounts, status, networks)
        ConnectionTester.php           sanitized server-side test
        Bridge.php                     WHMCS glue + legacy mirror
        CapsuleStore.php / PdoStore.php
```

Two tables (created idempotently, additive only):
`mod_blockonomics_governance` (key/value state), `mod_blockonomics_governance_audit` (append-only).

## Switch matrix (spec §15)

`effective(currency) = gateway_enabled AND currency_enabled`. Master wins. New
installs are fail-closed (everything off) until an admin enables the gateway.

## Backward compatibility (spec §31)

First access with an empty governance store performs a one-way import from
WHMCS `tblpaymentgateways` (`btcEnabled`, `usdtEnabled`, `Confirmations`,
`NetworkType`, presence of `ApiKey`). A previously working install keeps
working: master = (any currency on AND api key present). The import is
audited (`governance.seeded`) and never runs twice. After seeding, the
governance store is authoritative; each admin save mirrors flags back to
`tblpaymentgateways` so the stock `configgateways.php` page stays consistent.

## Credentials (spec §12–§14)

- `ApiKey` / `EtherScanAPIKey`: stored AES-256-GCM encrypted in
  `vault.api_key` / `vault.etherscan_api_key` (key derived from WHMCS
  `cc_encryption_hash`). Never rendered — only a fixed bullet mask.
- Resolution order at runtime: vault → legacy gateway params (compat).
- "Replace API Key" is write-only. `Test Connection` probes
  `https://www.blockonomics.co/api/balance` server-side and returns one of
  `connected | auth_failed | invalid_configuration | provider_unavailable |
  connection_timeout` — the key never echoes in any response or log.

## Customer-facing network labelling (spec §9–§10)

USDT checkout shows `USDT — Ethereum` or `USDT — Sepolia (Test Network)`
(TEST badge on testnet) beside the receiving address, plus the mandatory
wrong-network loss warning. Values come from configuration only — never
from the request. Unsupported networks cannot be configured or offered.

## Server-side enforcement (spec §16)

Enforced in three places — never only in the UI:

1. `blockonomics_link()` — master off → no crypto option/link rendered.
2. `payment.php` — master + per-currency gate with HTTP 403 JSON-safe
   message on `show_order`, `get_order`, `finish_order`.
3. `Blockonomics::createNewCryptoOrder()` — final fail-closed backstop
   before any address generation.

Callback/poller remain operational for in-flight orders after a disable
(disable stops *new* payments; historical settlement is protected).

## Verification & idempotency (spec §17, §20–§23, §38–§39)

- Callback: constant-time secret comparison, shape validation, 404 on
  unknown addresses, `Policy::classifyAmount` slack math, order expiry
  finalisation (no credit for expired windows), replay guards (tx exists /
  invoice paid / terminal order), and **sanitised** gateway logs (the
  callback secret is never logged).
- Poller (USDT): Etherscan `eth_getTransactionByHash` + contract/recipient
  verification stay; chain-depth confirmations (`eth_blockNumber` delta)
  must satisfy the configured requirement before crediting — fail closed
  when the count cannot be obtained. Amount math via Policy as well.
- Browser `finish_order` is only a *report*: the txid is validated for
  shape (`0x` + 64 hex) and recorded as unverified; crediting is exclusively
  the poller/callback's job.

## Admin surfaces

- **Payments · Blockonomics** — master + BTC + USDT toggles, network
  select (only the real networks), confirmations 0–2, masked credentials,
  Replace forms (write-only), Test Connection, governance audit tail.
  Validation refuses enabled-but-dead configurations (spec §34).
- **Crypto Transactions** — order listing with search (txid / invoice /
  customer / email), currency + status + date filters, masked addresses,
  normalised statuses. Read-only — no "mark as paid" exists anywhere here.

## Tests

- `tests/11_BlockonomicsTest.php` — 113 assertions (matrix, amounts,
  status, expiry, networks, readiness, replay policy, vault, governance,
  tester taxonomy, bridge).
- `tests/12_BlockonomicsAdminTest.php` — 52 assertions (panel, save chain,
  credential replacement + redaction, connection audit, transaction
  filters, masking).
- Gateway tree parse-checked (17 files) +
  full module suite `692/692` green.

## Remaining honest limits

- BCH support is unchanged stock behavior (governed only by its stock
  checkbox) — the CloudHost247 spec scopes BTC + USDT; nothing new was
  invented for BCH.
- `mark-paid` style webhooks from Blockonomics Sandbox never auto-verify;
  every credit flows through confirmation depth + amount classification.
