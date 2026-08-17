# Database Backup and Restore Runbook

## Protection model

The scheduled workflow creates a transaction-consistent MySQL dump, encrypts it
with AES-256-CBC/PBKDF2, verifies its SHA-256 digest, restores it into a
disposable MySQL 8 service, and only then uploads the encrypted artifact.
Artifacts are retained for 14 days. Plain SQL exists only in temporary runner
storage and is deleted by the script trap.

## Required GitHub environment

Create an environment named `production-backup` and add these secrets:

- `BACKUP_DB_HOST`
- `BACKUP_DB_PORT` (normally `3306`)
- `BACKUP_DB_NAME`
- `BACKUP_DB_USER`
- `BACKUP_DB_PASSWORD`
- `BACKUP_ENCRYPTION_KEY` (at least 32 random characters)

Use a dedicated MySQL account with only the privileges needed to read and dump
the application database. Restrict the environment to the `main` branch.
Store the encryption key in a second secure location; without it, backups are
unrecoverable.

Azure MySQL networking must permit the GitHub-hosted runner. If that is not
acceptable, run the same scripts from a self-hosted runner inside the allowed
network.

## Schedule and alerts

The workflow runs daily at 02:30 UTC (05:30 Baghdad) and can also be started
manually. Configure GitHub Actions failure notifications for the repository.
A backup is successful only when the disposable restore test passes.

## Manual restore drill

Download both the `.sql.enc` file and its `.sha256` file from a successful
workflow artifact. Never restore directly over production.

```bash
export BACKUP_ENCRYPTION_KEY='value-from-secure-storage'
export VERIFY_DB_HOST='127.0.0.1'
export VERIFY_DB_PORT='3306'
export VERIFY_DB_NAME='hercule_restore_drill'
export VERIFY_DB_USER='restore_operator'
export VERIFY_DB_PASS='...'
bash scripts/verify_backup.sh hercule-database.sql.enc
```

After verification, inspect row counts and application behavior against the
isolated restore database. Production recovery requires an approved maintenance
window, a fresh pre-restore backup, and explicit confirmation of the target
database.

## Rotation

Rotate the database password normally. Rotating `BACKUP_ENCRYPTION_KEY`
requires retaining the previous key for every backup encrypted with it.
