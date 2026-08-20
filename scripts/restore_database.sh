#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
Usage:
  RESTORE_CONFIRM=RESTORE-PRODUCTION BACKUP_ENCRYPTION_KEY=... \
  DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=... DB_PASS=... \
  bash scripts/restore_database.sh /path/to/backup.sql.enc

Safety:
- Requires the exact RESTORE_CONFIRM value RESTORE-PRODUCTION.
- Verifies checksum (when available) and validates decrypted SQL before production import.
- Creates a separate pre-restore encrypted safety snapshot before changing production.
EOF
  exit 2
}

encrypted="${1:-}"
[[ -n "$encrypted" && -f "$encrypted" ]] || usage

if [[ "${RESTORE_CONFIRM:-}" != "RESTORE-PRODUCTION" ]]; then
  echo "Refusing restore: set RESTORE_CONFIRM=RESTORE-PRODUCTION explicitly." >&2
  exit 1
fi

required=(DB_HOST DB_PORT DB_NAME DB_USER DB_PASS BACKUP_ENCRYPTION_KEY)
for name in "${required[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required environment variable: ${name}" >&2
    exit 1
  fi
done

if [[ ${#BACKUP_ENCRYPTION_KEY} -lt 32 ]]; then
  echo "BACKUP_ENCRYPTION_KEY must be at least 32 characters." >&2
  exit 1
fi

BACKUP_DIR="${BACKUP_DIR:-/home/backups/hercule-pos}"

if [[ -f "$encrypted.sha256" ]]; then
  (cd "$(dirname "$encrypted")" && sha256sum -c "$(basename "$encrypted").sha256")
fi

plain="$(mktemp "${TMPDIR:-/tmp}/hercule-prod-restore.XXXXXX.sql")"
safety_plain=""
cleanup() {
  rm -f "$plain"
  [[ -n "$safety_plain" ]] && rm -f "$safety_plain"
}
trap cleanup EXIT

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$encrypted" -out "$plain"

if [[ ! -s "$plain" ]] || ! grep -q 'CREATE TABLE' "$plain"; then
  echo "Refusing restore: decrypted backup is invalid." >&2
  exit 1
fi

# Keep the emergency pre-restore snapshot outside normal retention pruning.
safety_dir="$BACKUP_DIR/pre-restore"
mkdir -p "$safety_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
safety_plain="$(mktemp "${TMPDIR:-/tmp}/hercule-pre-restore.XXXXXX.sql")"
safety_enc="$safety_dir/hercule-${DB_NAME}-pre-restore-${timestamp}.sql.enc"

export MYSQL_PWD="$DB_PASS"
mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USER" \
  --ssl \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "$DB_NAME" > "$safety_plain"

if [[ ! -s "$safety_plain" ]] || ! grep -q 'CREATE TABLE' "$safety_plain"; then
  unset MYSQL_PWD
  echo "Refusing restore: could not create the pre-restore safety backup." >&2
  exit 1
fi

openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$safety_plain" -out "$safety_enc"
sha256sum "$safety_enc" > "$safety_enc.sha256"

mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$DB_NAME" < "$plain"
unset MYSQL_PWD

printf 'Production restore completed successfully.\nSafety backup: %s\nRestored from: %s\n' "$safety_enc" "$encrypted"
