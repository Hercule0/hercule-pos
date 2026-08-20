#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Azure App Service keeps /home on persistent storage. BACKUP_DIR can still
# override this path for other environments.
BACKUP_DIR="${BACKUP_DIR:-/home/backups/hercule-pos}"
BACKUP_RETENTION_COUNT="${BACKUP_RETENTION_COUNT:-7}"
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

if ! [[ "$BACKUP_RETENTION_COUNT" =~ ^[1-9][0-9]*$ ]]; then
  echo "BACKUP_RETENTION_COUNT must be a positive integer." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

# Avoid overlapping database dumps. flock is available on Azure Linux images;
# mkdir provides a conservative fallback for other environments.
lock_file="$BACKUP_DIR/.backup.lock"
lock_dir="$BACKUP_DIR/.backup.lockdir"
if command -v flock >/dev/null 2>&1; then
  exec 9>"$lock_file"
  if ! flock -n 9; then
    echo "Another backup is already running; skipping this run." >&2
    exit 0
  fi
else
  if ! mkdir "$lock_dir" 2>/dev/null; then
    echo "Another backup is already running; skipping this run." >&2
    exit 0
  fi
  trap 'rmdir "$lock_dir" 2>/dev/null || true' EXIT
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
plain="$(mktemp "${TMPDIR:-/tmp}/hercule-backup.XXXXXX.sql")"
cleanup() {
  rm -f "$plain"
  rmdir "$lock_dir" 2>/dev/null || true
}
trap cleanup EXIT
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

# Keep only the newest N normal backups locally. Pre-restore safety snapshots
# live in BACKUP_DIR/pre-restore and are intentionally excluded from pruning.
mapfile -t backups < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql.enc' -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2-)
if (( ${#backups[@]} > BACKUP_RETENTION_COUNT )); then
  for old in "${backups[@]:BACKUP_RETENTION_COUNT}"; do
    rm -f -- "$old" "$old.sha256"
  done
fi

printf '%s\n' "$encrypted"
