# Staging Deployment and Rollback Runbook

## Required Azure plan

Azure App Service deployment slots require a plan tier that supports slots
(typically Standard or higher). If the plan does not support them, the workflow
stops before production changes.

## One-time staging setup

The deployment workflow creates the `staging` slot if it does not exist.
Configure these GitHub Actions secrets:

- `STAGING_DB_HOST`
- `STAGING_DB_PORT`
- `STAGING_DB_NAME`
- `STAGING_DB_USER`
- `STAGING_DB_PASS`

The staging database must be separate from production and have the current
schema. Run `php db/migrate.php` against staging before the first deployment.
Use synthetic data only.

Database settings are written as slot-sticky settings, so they remain attached
to staging during swaps. In Azure, also configure staging-specific
`LICENSE_PRIVATE_KEY` and `MFA_ENCRYPTION_KEY` as deployment-slot settings.
Never copy real customer records or production private keys into staging.

## Deployment sequence

1. PHP, shell syntax, and integration tests run.
2. A clean deployment package is built.
3. The candidate is deployed only to `staging`.
4. The staging health route must confirm PHP and staging-database readiness.
5. Azure swaps the verified slot into production.
6. Production health is checked up to 12 times.
7. On failure, Azure swaps the prior version back automatically.
8. The workflow verifies that rollback restored health.

Production is not changed when staging verification fails.

## Manual rollback

The old production version remains in the staging slot immediately after a
successful swap. To reverse it before another deployment replaces that slot:

```bash
az webapp deployment slot swap \
  --name pos \
  --resource-group YOUR_RESOURCE_GROUP \
  --slot staging \
  --target-slot production
```

Immediately verify:

```text
https://YOUR_PRODUCTION_HOST/public/health.php
```

A code rollback does not reverse database migrations. Migrations must remain
backward-compatible and destructive schema changes require a separate,
approved migration plan and a verified database backup.

## Safety rules

- Never cancel a deployment while a slot swap or rollback is running.
- Do not use the production database from staging.
- Take a verified backup before risky migrations.
- Keep one deploy in progress at a time.
- Test login, dashboard, license validation, and recovery on staging before a
  high-risk release.
