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
if ! [[ "$VERIFY_DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
  echo "VERIFY_DB_NAME must contain only letters, numbers, and underscores." >&2
  exit 1
fi
if ! [[ "$VERIFY_DB_PORT" =~ ^[0-9]{1,5}$ ]] || (( VERIFY_DB_PORT < 1 || VERIFY_DB_PORT > 65535 )); then
  echo "VERIFY_DB_PORT must be a valid TCP port." >&2
  exit 1
fi
if ! command -v php >/dev/null 2>&1; then
  echo "PHP CLI is required to verify authenticated backup metadata." >&2
  exit 1
fi

checksum_file="$encrypted.sha256"
if [[ ! -f "$checksum_file" ]]; then
  echo "Backup SHA-256 metadata is missing." >&2
  exit 1
fi
expected_sha256="$(awk 'NR==1 {print $1}' "$checksum_file")"
if ! [[ "$expected_sha256" =~ ^[A-Fa-f0-9]{64}$ ]]; then
  echo "Backup SHA-256 metadata is invalid." >&2
  exit 1
fi
actual_sha256="$(sha256sum "$encrypted" | awk '{print $1}')"
if [[ "${expected_sha256,,}" != "${actual_sha256,,}" ]]; then
  echo "Backup SHA-256 verification failed." >&2
  exit 1
fi

# New v2 archives require a keyed HMAC so an attacker who can modify stored
# files cannot simply replace both the archive and its ordinary checksum.
if [[ "$(basename "$encrypted")" == *.v2.sql.enc ]]; then
  hmac_file="$encrypted.hmac"
  if [[ ! -f "$hmac_file" ]]; then
    echo "Authenticated v2 backup is missing its HMAC metadata." >&2
    exit 1
  fi
  expected_hmac="$(tr -d '[:space:]' < "$hmac_file")"
  if ! [[ "$expected_hmac" =~ ^[A-Fa-f0-9]{64}$ ]]; then
    echo "Backup HMAC metadata is invalid." >&2
    exit 1
  fi
  actual_hmac="$(php -r '
$key = getenv("BACKUP_ENCRYPTION_KEY");
if (!is_string($key) || strlen($key) < 32) exit(2);
$macKey = hash("sha256", "hercule-backup-hmac-v2\0" . $key, true);
$ctx = hash_init("sha256", HASH_HMAC, $macKey);
if (!hash_update_file($ctx, $argv[1])) exit(3);
echo hash_final($ctx), PHP_EOL;
' "$encrypted")"
  if ! [[ "$actual_hmac" =~ ^[a-f0-9]{64}$ ]] || [[ "${expected_hmac,,}" != "${actual_hmac,,}" ]]; then
    echo "Backup authentication failed. The archive may be corrupted or tampered with." >&2
    exit 1
  fi
else
  echo "Warning: verifying legacy backup without keyed HMAC metadata." >&2
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
