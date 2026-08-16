#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

encrypted="${1:-}"
if [[ -z "$encrypted" || ! -f "$encrypted" ]]; then
  echo "Usage: BACKUP_ENCRYPTION_KEY=... VERIFY_DB_*... $0 backup.sql.enc" >&2
  exit 1
fi
if [[ -z "${BACKUP_ENCRYPTION_KEY:-}" || ${#BACKUP_ENCRYPTION_KEY} -lt 32 ]]; then
  echo "BACKUP_ENCRYPTION_KEY must be at least 32 characters." >&2
  exit 1
fi

for name in VERIFY_DB_HOST VERIFY_DB_PORT VERIFY_DB_NAME VERIFY_DB_USER VERIFY_DB_PASS; do
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required environment variable: ${name}" >&2
    exit 1
  fi
done

if [[ -f "$encrypted.sha256" ]]; then
  (cd "$(dirname "$encrypted")" && sha256sum -c "$(basename "$encrypted").sha256")
fi

plain="$(mktemp "${TMPDIR:-/tmp}/hercule-restore.XXXXXX.sql")"
trap 'rm -f "$plain"' EXIT
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$encrypted" -out "$plain"

if [[ ! -s "$plain" ]] || ! grep -q 'CREATE TABLE' "$plain"; then
  echo "Decrypted backup is invalid." >&2
  exit 1
fi

export MYSQL_PWD="$VERIFY_DB_PASS"
mysql --host="$VERIFY_DB_HOST" --port="$VERIFY_DB_PORT" --user="$VERIFY_DB_USER" \
  -e "DROP DATABASE IF EXISTS \`$VERIFY_DB_NAME\`; CREATE DATABASE \`$VERIFY_DB_NAME\` CHARACTER SET utf8mb4;"
mysql --host="$VERIFY_DB_HOST" --port="$VERIFY_DB_PORT" --user="$VERIFY_DB_USER" "$VERIFY_DB_NAME" < "$plain"

required_tables=(admin_users customers licenses license_activations verification_log subscription_events password_recovery_requests recovery_audit_log)
for table in "${required_tables[@]}"; do
  count="$(mysql --batch --skip-column-names --host="$VERIFY_DB_HOST" --port="$VERIFY_DB_PORT" \
    --user="$VERIFY_DB_USER" "$VERIFY_DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$VERIFY_DB_NAME' AND table_name = '$table';")"
  if [[ "$count" != "1" ]]; then
    echo "Restore verification failed: missing table $table" >&2
    exit 1
  fi
done
unset MYSQL_PWD

echo "Backup decrypted, restored, and verified successfully."
