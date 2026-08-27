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
- Requires SHA-256 metadata for every archive and keyed HMAC metadata for v2 archives.
- Validates expected Hercule tables in decrypted SQL before production import.
- Creates a separate authenticated pre-restore safety snapshot before changing production.
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
if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
  echo "DB_NAME must contain only letters, numbers, and underscores." >&2
  exit 1
fi
if ! [[ "$DB_PORT" =~ ^[0-9]{1,5}$ ]] || (( DB_PORT < 1 || DB_PORT > 65535 )); then
  echo "DB_PORT must be a valid TCP port." >&2
  exit 1
fi
if ! command -v php >/dev/null 2>&1; then
  echo "PHP CLI is required to verify authenticated backup metadata." >&2
  exit 1
fi

BACKUP_DIR="${BACKUP_DIR:-/home/backups/hercule-pos}"

backup_hmac() {
  php -r '
$key = getenv("BACKUP_ENCRYPTION_KEY");
if (!is_string($key) || strlen($key) < 32) exit(2);
$macKey = hash("sha256", "hercule-backup-hmac-v2\0" . $key, true);
$ctx = hash_init("sha256", HASH_HMAC, $macKey);
if (!hash_update_file($ctx, $argv[1])) exit(3);
echo hash_final($ctx), PHP_EOL;
' "$1"
}

checksum_file="$encrypted.sha256"
if [[ ! -f "$checksum_file" ]]; then
  echo "Refusing restore: backup SHA-256 metadata is missing." >&2
  exit 1
fi
expected_sha256="$(awk 'NR==1 {print $1}' "$checksum_file")"
if ! [[ "$expected_sha256" =~ ^[A-Fa-f0-9]{64}$ ]]; then
  echo "Refusing restore: backup SHA-256 metadata is invalid." >&2
  exit 1
fi
actual_sha256="$(sha256sum "$encrypted" | awk '{print $1}')"
if [[ "${expected_sha256,,}" != "${actual_sha256,,}" ]]; then
  echo "Refusing restore: backup SHA-256 verification failed." >&2
  exit 1
fi

if [[ "$(basename "$encrypted")" == *.v2.sql.enc ]]; then
  hmac_file="$encrypted.hmac"
  if [[ ! -f "$hmac_file" ]]; then
    echo "Refusing restore: authenticated v2 backup is missing HMAC metadata." >&2
    exit 1
  fi
  expected_hmac="$(tr -d '[:space:]' < "$hmac_file")"
  actual_hmac="$(backup_hmac "$encrypted")"
  if ! [[ "$expected_hmac" =~ ^[A-Fa-f0-9]{64}$ ]] \
    || ! [[ "$actual_hmac" =~ ^[a-f0-9]{64}$ ]] \
    || [[ "${expected_hmac,,}" != "${actual_hmac,,}" ]]; then
    echo "Refusing restore: backup authentication failed. Archive may be tampered with." >&2
    exit 1
  fi
else
  echo "Warning: restoring legacy archive without keyed HMAC metadata." >&2
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

# Refuse an archive that decrypts successfully but is not a plausible Hercule
# production database. These are core tables required by licensing/recovery.
required_dump_tables=(admin_users customers licenses license_activations verification_log subscription_events password_recovery_requests recovery_audit_log)
for table in "${required_dump_tables[@]}"; do
  if ! grep -Fq "CREATE TABLE \`$table\`" "$plain"; then
    echo "Refusing restore: decrypted backup is missing required table $table." >&2
    exit 1
  fi
done

# Keep the emergency pre-restore snapshot outside normal retention pruning.
safety_dir="$BACKUP_DIR/pre-restore"
mkdir -p "$safety_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
safety_plain="$(mktemp "${TMPDIR:-/tmp}/hercule-pre-restore.XXXXXX.sql")"
safety_enc="$safety_dir/hercule-${DB_NAME}-pre-restore-${timestamp}.v2.sql.enc"

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
safety_name="$(basename "$safety_enc")"
safety_sha256="$(sha256sum "$safety_enc" | awk '{print $1}')"
printf '%s  %s\n' "$safety_sha256" "$safety_name" > "$safety_enc.sha256"
safety_hmac="$(backup_hmac "$safety_enc")"
if ! [[ "$safety_hmac" =~ ^[a-f0-9]{64}$ ]]; then
  unset MYSQL_PWD
  echo "Refusing restore: could not authenticate the pre-restore safety backup." >&2
  exit 1
fi
printf '%s\n' "$safety_hmac" > "$safety_enc.hmac"

# Only after the input archive and safety snapshot both pass integrity checks do
# we permit writes to the production schema.
mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$DB_NAME" < "$plain"
unset MYSQL_PWD

printf 'Production restore completed successfully.\nSafety backup: %s\nRestored from: %s\n' "$safety_enc" "$encrypted"
