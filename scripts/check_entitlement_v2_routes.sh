#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"
if [[ -z "$BASE_URL" ]]; then
  echo "Usage: $0 <base-url>" >&2
  exit 2
fi
BASE_URL="${BASE_URL%/}"

probe_route() {
  local route="$1"
  local url="${BASE_URL}${route}"
  local body_file status content_type
  body_file="$(mktemp)"
  trap 'rm -f "$body_file"' RETURN

  echo "Probing ${route}"
  status="$(curl --silent --show-error --location \
    --connect-timeout 10 --max-time 20 \
    --output "$body_file" \
    --write-out '%{http_code}' \
    --header 'Content-Type: application/json' \
    --header 'Accept: application/json' \
    --data '{}' \
    "$url")"

  # Empty input is intentionally invalid; the v2 contract must therefore
  # return signed JSON with HTTP 400. Any 404/HTML proves deployment drift.
  if [[ "$status" != "400" ]]; then
    echo "ERROR: ${route} returned HTTP ${status}; expected signed JSON HTTP 400." >&2
    head -c 500 "$body_file" >&2 || true
    echo >&2
    return 1
  fi

  python3 - "$body_file" "$route" <<'PY'
import json, sys
path, route = sys.argv[1], sys.argv[2]
try:
    with open(path, 'r', encoding='utf-8') as fh:
        doc = json.load(fh)
except Exception as exc:
    raise SystemExit(f"ERROR: {route} returned non-JSON response: {exc}")

payload = doc.get('payload') if isinstance(doc, dict) else None
signature = doc.get('signature') if isinstance(doc, dict) else None
if not isinstance(payload, dict):
    raise SystemExit(f"ERROR: {route} response is missing payload object")
if int(payload.get('schema_version') or 0) != 2:
    raise SystemExit(f"ERROR: {route} response schema_version is not 2")
if not isinstance(signature, str) or len(signature.strip()) < 64:
    raise SystemExit(f"ERROR: {route} response is missing RSA signature")
if doc.get('ok') is not False:
    raise SystemExit(f"ERROR: {route} empty-body probe unexpectedly returned ok=true")
print(f"PASS {route}: signed Entitlement v2 JSON is live")
PY

  rm -f "$body_file"
  trap - RETURN
}

probe_route "/public/api/v2/validate.php"
probe_route "/public/api/v2/activate.php"

echo "Entitlement v2 production route verification passed."
