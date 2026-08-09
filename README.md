# Hercule License Server

Phase 4 of the Hercule POS project: a standalone PHP/MySQL license management
server. Independent of the Electron/Node.js desktop app — the two only talk
over the HTTP API in `public/api/`.

## Status: built and tested

Everything below was verified with real HTTP requests against a running
PHP dev server (using SQLite as a local stand-in for MySQL — production
uses MySQL, see Setup), not just written and assumed correct:

- Admin login with CSRF protection, timing-safe password check, and a
  per-username-per-IP rate limiter (5 failed attempts / 15 min lockout)
- Customer management (add, list)
- License issuance with auto-generated keys (format `XXXX-XXXX-XXXX-XXXX-XXXX`,
  ambiguous characters like `0/O/1/I/L` excluded)
- Device activation with a configurable per-license device limit —
  re-activating the same device is idempotent, activating past the limit
  is rejected
- Runtime validation endpoint that checks status, expiry, and HWID binding
  on every call
- RSA-signed API responses (2048-bit) — verified that a real signed
  response validates correctly with the public key, and that a tampered
  payload (e.g. someone flipping `plan` to `lifetime` client-side) fails
  verification
- License lifecycle: renew (extends from whichever is later — now or
  current expiry, so early renewals don't lose remaining time), suspend,
  revoke, reactivate — confirmed a suspended license is immediately
  rejected by the API on the next validation call
- CSV export with formula-injection guarding (same fix pattern as
  Ur Library's export)
- A `tests/run_test.php` harness covering 32 assertions across the full
  license lifecycle
- Per-IP rate limiting on `/api/activate.php` and `/api/validate.php`
  (20 requests / 5 min by default) — verified by hammering an endpoint
  past the limit and confirming it returns 429
- Admin password change — verified wrong-current-password rejection,
  mismatched-confirmation rejection, and that the change takes effect
  immediately (old password stops working, new one works)

## Project structure

```
hercule-license-server/
├── config/
│   └── config.php          # DB credentials, RSA key paths, security settings
├── includes/                # Outside the web root — not directly reachable
│   ├── Database.php         # PDO singleton
│   ├── Auth.php              # Admin login, session guard, rate limiting
│   ├── Csrf.php               # CSRF token generation/verification
│   ├── License.php            # Core business logic — key gen, issue, activate, validate, renew
│   └── RsaSigner.php           # Signs/verifies API responses
├── db/
│   ├── schema.sql              # MySQL schema (production)
│   ├── migrate.php             # Idempotent migration runner
│   └── schema.sqlite.test.sql  # SQLite equivalent, test-only
├── keys/                    # RSA keypair lives here (gitignored) — outside web root
├── tests/
│   └── run_test.php        # Full lifecycle test suite (32 checks)
├── public/                  # THIS is the web server's document root
│   ├── admin/                # Admin panel — login, dashboard, customers, licenses
│   └── api/
│       ├── activate.php       # POST — first-time device activation
│       └── validate.php       # POST — runtime license check
└── setup.sh                 # One-time setup: migration + RSA keypair generation
```

**Why `public/` is the document root:** `config/`, `includes/`, `keys/`, and
`db/` sit outside it on purpose. Point your web server's DocumentRoot at
`public/` — that way the RSA private key, DB credentials, and business logic
classes are never directly reachable over HTTP, no matter how someone probes
the URL.

## Setup

1. Create a MySQL database and a user with access to it.
2. Edit `config/config.php` — either hardcode the `db` values or set the
   `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` environment
   variables (preferred for production).
3. Run:
   ```bash
   ./setup.sh
   ```
   This runs the migration (creates tables, seeds a default admin user with
   a random printed password) and generates the RSA signing keypair.
4. Point your web server's DocumentRoot at `public/`.
5. Log in at `/admin/login.php` with the printed credentials, then **change
   that password** — there's no "change password" UI yet (see Known gaps),
   so for now that means updating `admin_users.password_hash` directly via
   `password_hash('new-password', PASSWORD_DEFAULT)` until that's built.

## API reference

### `POST /api/activate.php`
First-time device activation.
```json
// Request
{ "license_key": "XXXX-XXXX-XXXX-XXXX-XXXX", "hwid": "..." }

// Response (success)
{
  "ok": true,
  "payload": { "status": "active", "plan": "monthly", "expires_at": "...", "server_time": "..." },
  "signature": "base64..."
}

// Response (failure) — still ok:false, still worth checking `error`
{ "ok": false, "error": "This license has reached its device activation limit." }
```

### `POST /api/validate.php`
Runtime check — call on launch and periodically thereafter (Phase 5 decides
the interval and adds clock-tamper detection using the `server_time` field).
Same request/response shape as `activate.php`, but requires the hwid to
already be activated rather than consuming a new activation slot.

**Both success and failure responses from `validate.php` are RSA-signed** —
this matters because otherwise a network attacker could strip the signature
from a failure response and the desktop app would have no way to distinguish
a forged "yes it's fine" from a genuine one.

## Changelog

- **Production hardening pass**: added rate limiting to `/api/activate.php` and `/api/validate.php` (20 requests / 5 min per IP by default, configurable) — verified by hammering the endpoint past the limit and confirming a 429 response. Added an admin password-change page (`/admin/change_password.php`) — verified the full flow: wrong current password rejected, mismatched confirmation rejected, successful change takes effect immediately (old password stops working, new one works).
- **Post-Phase-4, during Phase 5 integration testing**: fixed `activate.php` to sign failure responses too (it previously only signed success responses, unlike `validate.php` which always signs both). Found this while building the Node license client — for consistency and defense-in-depth, a network attacker shouldn't be able to strip the signature from a failure response undetected, even though the practical risk was lower here than on `validate.php`.

## Known gaps / next steps

- **No payment webhook integration.** `subscription_events` has an
  `event_type` of `renewed` ready to be written by a future Stripe/Paddle
  webhook handler — right now renewals are manual (admin clicks "Renew").
- **No email notifications** (e.g. "your license expires in 7 days") — the
  dashboard surfaces this to the admin, but nothing goes to the customer yet.
- **HTTPS is assumed, not enforced** at the app level — that's the web
  server/reverse-proxy's job, but worth calling out since license keys and
  signed responses travel over these endpoints.
- This is what Phase 5 (HWID + C++ security core) will talk to — the
  desktop app ships with `keys/license_signing_public.pem` embedded and
  verifies every response signature before trusting it.
