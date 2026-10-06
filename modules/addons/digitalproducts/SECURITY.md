# Security model

Downloads accept only a cryptographically random 64-character token. Only its SHA-256 hash is stored. The authorizer binds token → entitlement → client → WHMCS service → linked WHMCS product → digital product → version, checks live service/order state, product/version status, expiry and an atomic counter before streaming.

Files are stored outside the document root behind a storage abstraction. Uploads validate filename, extension, MIME, size and archive paths; symlinks and unsafe ZIP paths are rejected where `ZipArchive` is available. Uploaded PHP is never included or executed.

Admin operations require WHMCS admin access, addon capability checks and CSRF. Output is escaped and state changes are audited. API bearer tokens are hashed at rest, query-string auth is rejected, sensitive endpoints are rate limited, and no wildcard CORS is emitted. Download logs use a pseudonymised IP and never store raw tokens, license keys or API secrets.

Treat any configured public storage path as a deployment incident: move it outside the web root, review web logs and rotate product secrets as needed.