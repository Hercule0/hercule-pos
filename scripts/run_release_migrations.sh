#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

migrations=(
  "db/migrate_device_management.php"
  "db/migrate_release_management.php"
  "db/migrate_performance_indexes.php"
  "db/migrate_expiry_alerts.php"
  "db/migrate_notification_preferences.php"
  "db/migrate_admin_permissions.php"
)

echo "Hercule POS release migration preflight"
echo "Application root: $ROOT_DIR"
echo

for migration in "${migrations[@]}"; do
  if [[ ! -f "$migration" ]]; then
    echo "ERROR: required migration is missing: $migration" >&2
    echo "The release batch is incomplete. Stop rollout and verify the expected feature PRs were merged/deployed." >&2
    exit 2
  fi
done

for migration in "${migrations[@]}"; do
  echo "==> php $migration"
  php "$migration"
  echo
 done

echo "==> php db/migrate.php"
php db/migrate.php

echo
echo "All release migrations completed successfully."
echo "Next: run the production smoke-test checklist in docs/RELEASE_READINESS.md before enabling scheduled jobs."
