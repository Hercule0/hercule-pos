# Hercule License Server

PHP 8.3 / MySQL license-management service for Hercule POS. It provides a
mobile-first administration panel, device-bound license activation, signed
runtime validation, and an administrator-reviewed password-recovery flow.

## Production features

- Session-based admin authentication with CSRF protection, strict cookies,
  idle expiry, login throttling, password changes, role-based permissions, and encrypted TOTP MFA
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
- Daily encrypted database backups with an automated disposable restore test
- Five-minute production monitoring, outage incidents, and structured error logs

## Routes

### Administration

- `/public/admin/login.php`
- `/public/admin/index.php`
- `/public/admin/customers.php`
- `/public/admin/licenses.php`
- `/public/admin/recovery_requests.php`
- `/public/admin/mfa_settings.php`
- `/public/admin/admin_users.php` (Owner only)

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
  "time": "2026-08-16T00:00:00Z",
  "request_id": "correlation-id"
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
MFA_ENCRYPTION_KEY
```

`MFA_ENCRYPTION_KEY` must be at least 32 cryptographically random characters.
It encrypts administrator TOTP secrets with AES-256-GCM and must remain stable
across deployments. Losing it prevents existing MFA secrets from being decrypted.

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
- disabled-account rejection and mandatory temporary-password replacement
- RFC 6238 TOTP generation and verification
- encrypted MFA secret round-trip
- two-stage login, invalid-code rejection, and one-time recovery-code consumption
- recovery identity checks
- duplicate recovery prevention
- conditional approval/rejection
- HWID-bound authorization
- one-time claim and token consumption
- invalid token and replay rejection

GitHub Actions runs PHP syntax validation and this suite on every pull request.
Only pushes to `main` deploy to Azure. Production deployments are serialized,
use a clean package, and must pass `/public/health.php` after deployment.

## Administrator management

Owners can create, disable, re-enable, change the role of, reset MFA for, or
permanently delete another administrator from the mobile-responsive account
management page. Every sensitive change requires the acting Owner's current
password and is written to `admin_audit_log`.

New accounts receive a temporary password and are forced to replace it before
accessing the administration panel. The system prevents self-demotion,
self-disable, self-delete, and any action that would remove the final active
Owner.

## Admin roles

- `owner`: full access, including customer changes and permanent license deletion
- `support`: license lifecycle actions, recovery review, and CSV export
- `read_only`: dashboard and record viewing only

Existing administrators become `owner` when `php db/migrate.php` adds the role
column. Role checks are enforced server-side.

## Security model

- Database access uses PDO prepared statements with emulated prepares disabled.
- Admin state changes require CSRF tokens.
- Enabled MFA accounts require password plus TOTP or a one-time recovery code.
- TOTP secrets are encrypted at rest with AES-256-GCM; recovery codes are password-hashed.
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

## Database backups

The `Encrypted database backup` workflow runs daily at 02:30 UTC (05:30
Baghdad). It creates a transaction-consistent dump, encrypts it before upload,
restores it into disposable MySQL, verifies the required tables, and retains
only the encrypted artifact for 14 days.

Configure the `production-backup` GitHub environment before enabling the
schedule. Full setup, restore-drill, key-rotation, and network requirements are
in [the database backup runbook](docs/DATABASE_BACKUP_RUNBOOK.md).

## Monitoring and alerts

The production workflow checks application availability, database readiness,
and response time every five minutes. It opens one `uptime-alert` GitHub issue
on failure and closes it automatically after recovery. Set the repository
variable `PRODUCTION_HEALTH_URL` to the deployed health endpoint.

Unhandled application errors are logged as structured JSON with an
`X-Request-ID` correlation header, while clients receive no stack traces.
Configuration and incident procedures are documented in
[the monitoring runbook](docs/MONITORING_RUNBOOK.md).

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
scripts/                 encrypted backup and restore verification
docs/                    operational runbooks
.github/workflows/       validated Azure deployment
```

## Remaining production work

- mandatory MFA policy controls
- payment-provider webhooks
- customer expiry notifications
- staging deployment slot and automated rollback
- full PWA installation support
