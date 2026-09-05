#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

migrations=(
  "db/migrate_device_management.php"
  "db/migrate_release_management.php"
  "db/migrate_support_feedback.php"
  "db/migrate_performance_indexes.php"
  "db/migrate_expiry_alerts.php"
  "db/migrate_notification_preferences.php"
  "db/migrate_admin_permissions.php"
  "db/migrate_push_subscription_hygiene.php"
)

fix408_migration="db/migrate_fix408.php"

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

if [[ ! -f "$fix408_migration" ]]; then
  echo "ERROR: required Multi-Cashier migration is missing: $fix408_migration" >&2
  echo "Entitlement v2 must not be deployed without its schema migration." >&2
  exit 2
fi

for migration in "${migrations[@]}"; do
  echo "==> php $migration"
  php "$migration"
  echo
done

# Fix408 wrapper intentionally runs the canonical migration first and then the
# idempotent Entitlement v2 migration. Keeping the pair together prevents a
# deployed /public/api/v2 surface from being left on a v1-only database.
echo "==> php $fix408_migration"
php "$fix408_migration"

echo
echo "All release migrations completed successfully, including Entitlement v2."
echo "Next: verify /public/api/v2/validate.php returns signed JSON, then run the production smoke-test checklist in docs/RELEASE_READINESS.md before enabling scheduled jobs."
