#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Azure App Service keeps /home on persistent storage. BACKUP_DIR can still
# override this path for other environments.
BACKUP_DIR="${BACKUP_DIR:-/home/backups/hercule-pos}"
export BACKUP_DIR

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

mkdir -p "$BACKUP_DIR"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
plain="$(mktemp "${TMPDIR:-/tmp}/hercule-backup.XXXXXX.sql")"
trap 'rm -f "$plain"' EXIT
encrypted="$BACKUP_DIR/hercule-${DB_NAME}-${timestamp}.sql.enc"

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
  "$DB_NAME" > "$plain"
unset MYSQL_PWD

if [[ ! -s "$plain" ]] || ! grep -q 'CREATE TABLE' "$plain"; then
  echo "Database dump is empty or contains no table definitions." >&2
  exit 1
fi

openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$plain" -out "$encrypted"

sha256sum "$encrypted" > "$encrypted.sha256"
printf '%s\n' "$encrypted"
