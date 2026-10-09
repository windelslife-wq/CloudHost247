# App Cloud node heartbeat and Linux uptime slices

**Code status:** HMAC-authenticated heartbeat, independently gated **Linux kernel uptime-only** samples, and narrowly scoped uptime retention are implemented; **all three switches default off**. App Cloud addon version `1.11.1` applies the additive `0014_agent_uptime_source` migration. Offline tests only. This is **not** an inbound command-listening daemon, complete monitoring collector, or application-deployment transport. No live WHMCS/agent staging or production enablement has occurred.

## Boundary

- Physical endpoint: `POST /modules/addons/cloudhost247apps/api/agent.php`, served over HTTPS by the WHMCS installation. It requires the installed WHMCS `init.php` and database; there is no standalone API fallback.
- HMAC signing path (independent of the PHP URL): `/agent/v1/heartbeat`. Requests use the already-existing `AgentAuthenticator` headers (`X-CH247-Agent-Uuid`, `X-CH247-Timestamp`, `X-CH247-Nonce`, `X-CH247-Signature`) and its sealed per-agent shared secret. Sign the exact UTF-8 JSON bytes of the request body. Timestamp TTL and unique stored nonce reject old and replayed requests.
- JSON body: `{}` or `{"agent_version":"1.2.3"}` for liveness. With the **independent** `agent_uptime_ingress_enabled` gate, the sender can also submit `{"agent_version":"1.0.0","uptime_seconds":12345}`. That value must be an integer from 0 through 2,147,483,647, collected from Linux `/proc/uptime`. WHMCS stamps it on arrival in the existing `metrics` table. The per-agent uptime rate limit is 12 samples/hour; ordinary heartbeats are unaffected. Server ID, IP, status, capabilities, capacity, arbitrary metrics, health, client timestamps, job results and unknown fields are rejected. The source IP is taken from the trusted HTTP request context, never from JSON. The response returns only the resulting server status and recorded timestamp.
- The authenticated agent must still be the **currently assigned agent** of a non-deleted, non-disabled server; an old/rotated, revoked or unassigned agent cannot bring a server online. Requests are subject to the existing per-agent rate limit. Other methods/operations are not exposed.
- Set `agent_heartbeat_ingress_enabled` only after testing an isolated WHMCS installation, a registered node, shared-secret provisioning out of band, TLS/clock synchronization, nonce replay, rotation/revocation and firewall restrictions. The WHMCS addon checkbox and `CH247APPS_AGENT_HEARTBEAT_INGRESS_ENABLED` environment override both default off. Do not put agent secrets in Git or browser code.

## Outbound node sender

`modules/addons/cloudhost247apps/agent/HeartbeatSender.php` and `agent/heartbeat.php` implement a **one-shot CLI heartbeat sender**; `agent/UptimeReader.php` optionally reads real Linux kernel uptime. It requires no WHMCS bootstrap, inbound listening socket, Docker socket, agent credentials in arguments, or shared code on the node. Its HTTPS target is derived from the operator's credential-free WHMCS base URL, with TLS/hostname verification, no redirects, bounded responses and no automatic retry. It signs the exact JSON bytes with a fresh nonce for each execution; HTTP errors never print provider response bodies or secret material. `agent/cloudhost247-heartbeat.service` and `.timer` are optional deployment examples, not activated by the WHMCS addon.

**Isolated staging install, not performed here:**

1. Register a deployment-target server/agent in App Cloud and provision its returned UUID and shared secret out of band to the *actual* node. Protect and rotate the secret; the web application and node must have synchronized clocks. Use a valid HTTPS certificate for the WHMCS host.
2. On the node, install PHP CLI with cURL. Copy **only** `agent/HeartbeatSender.php`, `agent/UptimeReader.php` and `agent/heartbeat.php` to `/opt/cloudhost247/agent/` (outside any document root), owned by root. Create a dedicated `ch247-heartbeat` system user. Store the shared secret as `/etc/cloudhost247/heartbeat.secret`, owned by that user, mode `0600`, inside a directory that user can traverse. Never add it to Git, a command argument, a browser, or the WHMCS root.
3. Copy `agent/heartbeat.env.example` to `/etc/cloudhost247/heartbeat.env`, replace its example URL/UUID with real values, and restrict it to root mode `0600`. `CH247_AGENT_WHMCS_URL` is the base WHMCS HTTPS URL (including any installation subdirectory), **not** the agent PHP endpoint. Deploy the supplied service/timer units to `/etc/systemd/system/` only after reviewing their paths and the dedicated user. The systemd service loads the environment file; running the PHP file directly requires setting those variables separately.
4. Keep `agent_heartbeat_ingress_enabled` off until WHMCS/MySQL activation, TLS, clock skew, replay rejection, secret rotation/revocation, current-agent binding, and node permissions are checked in isolation. Then explicitly enable it and run the service **once**. Only after confirming its recorded heartbeat and an acceptable stale/offline cycle should an operator enable the timer. Do not enable application installation on the basis of this check-in.
5. **Separate uptime staging:** leave `agent_uptime_ingress_enabled` and the node's `CH247_AGENT_UPTIME_ENABLED` at `0` until `/proc/uptime` is available on the actual Linux node, `monitoring_enabled` is set on the assigned server, both HMAC gates and row permissions work in staging, and the **retention/backup policy** below is approved. Enable the WHMCS gate first, then opt in on the node. The CLI sends a plain heartbeat first and only then a second, freshly signed report containing the kernel counter. A failed uptime read/report returns a nonzero CLI exit code but cannot invent a metric or undo an accepted heartbeat. The timer remains optional.

## Uptime-only retention (separate operator approval)

Migration `0014_agent_uptime_source` adds a nullable `metrics.source` and an age index. **Only newly accepted** node-uptime reports are tagged `agent_linux_uptime`; existing rows, even if they contain `uptime_seconds`, remain untagged and are never retroactively guessed or purged. Application, provider and other server metrics are never eligible.

The existing App Cloud cron (`cron/cloudhost247apps.php`) runs the new `agent_uptime_pruned` task. It returns `0` while `agent_uptime_retention_enabled` is off. Once an operator approves data retention, sets `agent_uptime_retention_days` (integer **1–3650**, default **30**), verifies backups/export and enables the separate retention switch, each cron run removes **at most 200** tagged samples whose WHMCS-recorded `sampled_at` is *strictly older* than the cutoff. Later runs drain any backlog. Invalid periods or missing migration fail closed; the task never interprets uptime as evidence of health. Stage an upgrade and verify `--task=agent_uptime_pruned --json` against an isolated WHMCS/MySQL install before enabling the switch. Enabling uptime ingestion alone does **not** enable cleanup.

**Limits:** Retention is implemented but not live-validated; it is **not** a platform-wide metrics lifecycle or an archive/export service. Set your storage/backup policy before leaving periodic uptime collection enabled. The platform's health verdict remains `unknown` for uptime-only samples: elapsed kernel uptime is not evidence that applications or services are healthy. No CPU, RAM, disk, network, application metrics, arbitrary agent values, or guest uptime for provider VMs are collected here. This adds an outbound heartbeat sender, **not** an inbound command-listening daemon, and does not make Docker/Traefik, backups, SSL issuance, or deployments operational. An HMAC-authenticated report establishes only that a holder of the provisioned key checked in.

Offline checks (from `modules/addons/cloudhost247apps/`):

```bash
npm ci --ignore-scripts
node tests/run.mjs AgentHeartbeatIngress
node tests/run.mjs HeartbeatSender
node tests/run.mjs AgentUptime
node tests/run.mjs UptimeRetention
npm test
npm run lint
```
