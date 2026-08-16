#!/usr/bin/env bash
set -Eeuo pipefail

url="${1:-${PRODUCTION_HEALTH_URL:-}}"
if [[ -z "$url" || "$url" != https://* ]]; then
  echo "A production HTTPS health URL is required." >&2
  exit 2
fi

response="$(mktemp "${TMPDIR:-/tmp}/hercule-health.XXXXXX")"
trap 'rm -f "$response"' EXIT

http_code="$(curl --silent --show-error --location \
  --connect-timeout 8 --max-time 15 \
  --retry 2 --retry-delay 2 --retry-all-errors \
  --output "$response" --write-out '%{http_code}' "$url")" || {
    echo "Health request failed." >&2
    exit 1
  }

if [[ "$http_code" != "200" ]]; then
  echo "Health endpoint returned HTTP $http_code." >&2
  exit 1
fi

php -r '
  $data = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($data) || ($data["ok"] ?? false) !== true || ($data["database"] ?? "") !== "reachable") {
      fwrite(STDERR, "Health payload did not confirm application and database readiness.\n");
      exit(1);
  }
' "$response"

echo "Application and database health confirmed."
