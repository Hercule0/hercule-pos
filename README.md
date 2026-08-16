# Hercule License Server

PHP 8.3 / MySQL license-management service for Hercule POS. It provides a
mobile-first administration panel, device-bound license activation, signed
runtime validation, and an administrator-reviewed password-recovery flow.

## Production features

- Session-based admin authentication with CSRF protection, strict cookies,
  idle expiry, login throttling, and password changes
- Mobile-first dashboard, customers, licenses, license details, and recovery
  pages
- Database-backed search, filtering, and pagination for large admin datasets
- License plans: trial, monthly, semi-annual, annual, custom, and lifetime
- Atomic device activation with per-license activation limits
- Suspend, revoke, reactivate, renew, deactivate-device, and delete workflows
- RSA/SHA-256 signed activation and validation responses
- Per-IP and per-license API rate limits
- Device-bound, short-lived, single-use password-recovery authorization
- Live recovery notifications with badge, toast, sound, and optional browser
  notification
- Streaming CSV export with spreadsheet formula-injection protection
- Bounded operational-log retention
- Health endpoint that verifies both PHP and database availability
- GitHub Actions validation and Azure deployment health verification

## Routes

### Administration

- `/public/admin/login.php`
- `/public/admin/index.php`
- `/public/admin/customers.php`
- `/public/admin/licenses.php`
- `/public/admin/recovery_requests.php`

### Public API

- `POST /public/api/activate.php`
- `POST /public/api/validate.php`
- `POST /public/api/check_update.php`
- `POST /public/api/recovery_request.php`
- `POST /public/api/recovery_status.php`
- `POST /public/api/recovery_claim.php`
- `POST /public/api/recovery_reset.php`

All API request bodies use `application/json` and are limited to 16 KB.

### Health

- `GET /public/health.php`

A healthy response returns HTTP 200:

```json
{
  "ok": true,
  "service": "hercule-license-server",
  "database": "reachable",
  "time": "2026-08-16T00:00:00Z"
}
```

Database failures return HTTP 503 without exposing connection details.

## Required environment variables

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
LICENSE_PRIVATE_KEY
```

`LICENSE_PRIVATE_KEY` must contain the RSA private key used to sign license
responses. Keep it only in the Azure application environment. Never commit it
or expose it to the desktop application.

The desktop application embeds only:

```text
keys/license_signing_public.pem
```

## Local setup

Requirements:

- PHP 8.3
- PDO MySQL
- OpenSSL
- mbstring
- MySQL 8 / compatible Azure MySQL service

Configure the environment, then run the CLI-only setup:

```bash
./setup.sh
```

The setup script runs the safe migration and validates that
`LICENSE_PRIVATE_KEY` can sign a payload. Database migrations intentionally
return 404 over HTTP and must be run through a trusted CLI environment.

## Testing

Run the complete in-memory SQLite test suite:

```bash
php tests/run_test.php
```

The suite covers:

- license key format and randomness
- plan expiry calculation
- issue, activate, validate, renew, suspend, revoke, and reactivate
- activation limits and device deactivation
- RSA signing, verification, and tamper detection
- login failure throttling
- recovery identity checks
- duplicate recovery prevention
- conditional approval/rejection
- HWID-bound authorization
- one-time claim and token consumption
- invalid token and replay rejection

GitHub Actions runs PHP syntax validation and this suite on every pull request.
Only pushes to `main` deploy to Azure. Production deployments are serialized,
use a clean package, and must pass `/public/health.php` after deployment.

## Security model

- Database access uses PDO prepared statements with emulated prepares disabled.
- Admin state changes require CSRF tokens.
- Sessions use strict mode, `HttpOnly`, `SameSite=Strict`, HTTPS-aware
  `Secure` cookies, ID rotation, and idle expiration.
- Admin pages use no-store caching and security headers including CSP, HSTS,
  MIME sniffing protection, frame blocking, and restrictive permissions.
- API inputs are JSON-only, size-bounded, format-validated, and rate-limited.
- License state is trusted by the desktop app only after RSA verification.
- Recovery tokens are stored only as SHA-256 hashes, bound to the requesting
  device, short-lived, claimable once, and cleared after consumption.
- Debug license generators, browser-accessible migrations, duplicate deployment
  workflows, tests, and setup files are excluded from production.

## Operational retention

Normal traffic performs small probabilistic cleanup passes:

- API rate-limit history: 7 days
- Admin login attempts: 30 days
- Verification history: 90 days
- Recovery audit history: 180 days

Each pass deletes a bounded number of rows to avoid long blocking maintenance
queries.

## Project structure

```text
config/                 environment-backed configuration
includes/               auth, database, licensing, signing, recovery, limits
db/                     MySQL schema and CLI migration
keys/                   public signing key only
public/admin/            administration panel
public/api/              desktop-client API
public/health.php        application/database health check
tests/                   SQLite integration test harness
.github/workflows/       validated Azure deployment
```

## Remaining production work

- MFA/TOTP and role-based admin permissions
- automated database backups with a tested restore procedure
- centralized error monitoring and uptime alerting
- payment-provider webhooks
- customer expiry notifications
- staging deployment slot and automated rollback
- full PWA installation support
