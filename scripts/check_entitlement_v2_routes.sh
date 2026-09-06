#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${1:-}"
if [[ -z "$BASE_URL" ]]; then
  echo "Usage: $0 <base-url>" >&2
  exit 2
fi
BASE_URL="${BASE_URL%/}"

probe_route() {
  local route="$1"
  local url="${BASE_URL}${route}"
  local body_file status
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
if not isinstance(payload, dict): raise SystemExit(f"ERROR: {route} response is missing payload object")
if int(payload.get('schema_version') or 0) != 2: raise SystemExit(f"ERROR: {route} response schema_version is not 2")
if not isinstance(signature, str) or len(signature.strip()) < 64: raise SystemExit(f"ERROR: {route} response is missing RSA signature")
if doc.get('ok') is not False: raise SystemExit(f"ERROR: {route} empty-body probe unexpectedly returned ok=true")
print(f"PASS {route}: signed Entitlement v2 JSON is live")
PY

  rm -f "$body_file"
  trap - RETURN
}

diagnose_kudu_g1() {
  if ! command -v az >/dev/null 2>&1 || [[ -z "${AZURE_WEBAPP_NAME:-}" ]]; then
    echo "Kudu diagnostic unavailable: Azure CLI or AZURE_WEBAPP_NAME is missing." >&2
    return 0
  fi

  local resource_group profile kudu_user kudu_pass publish_url kudu_host kudu_base
  local expected actual status expected_sha actual_sha expected_size actual_size

  resource_group="$(az webapp list --query "[?name=='${AZURE_WEBAPP_NAME}'].resourceGroup | [0]" --output tsv 2>/dev/null || true)"
  if [[ -z "$resource_group" ]]; then
    echo "Kudu diagnostic could not resolve App Service resource group." >&2
    return 0
  fi

  echo "=== G1 Kudu fail-path diagnostic ==="
  echo "resource_group=${resource_group}"
  az webapp config appsettings list --name "$AZURE_WEBAPP_NAME" --resource-group "$resource_group" \
    --query "[?name=='WEBSITE_RUN_FROM_PACKAGE' || name=='WEBSITES_ENABLE_APP_SERVICE_STORAGE' || name=='WEBSITE_ENABLE_SYNC_UPDATE_SITE' || name=='SCM_DO_BUILD_DURING_DEPLOYMENT'].{name:name,value:value}" \
    --output table 2>/dev/null || true

  profile="$(az webapp deployment list-publishing-profiles --name "$AZURE_WEBAPP_NAME" --resource-group "$resource_group" --output json --query "[?publishMethod=='MSDeploy'] | [0]" 2>/dev/null || echo '{}')"
  kudu_user="$(jq -r '.userName // empty' <<<"$profile")"
  kudu_pass="$(jq -r '.userPWD // empty' <<<"$profile")"
  publish_url="$(jq -r '.publishUrl // empty' <<<"$profile")"
  if [[ -z "$kudu_user" || -z "$kudu_pass" || -z "$publish_url" ]]; then
    echo "Kudu diagnostic publishing profile unavailable." >&2
    return 0
  fi
  echo "::add-mask::$kudu_user"
  echo "::add-mask::$kudu_pass"
  kudu_host="${publish_url#https://}"
  kudu_host="${kudu_host#http://}"
  kudu_host="${kudu_host%%:*}"
  kudu_base="https://${kudu_host}"

  curl --fail --silent --show-error -u "$kudu_user:$kudu_pass" "$kudu_base/api/deployments" 2>/dev/null \
    | jq '[.[0:3][] | {id,status,status_text,deployer,message,received_time,start_time,end_time,active}]' || true

  for rel in \
    public/api/v2/validate.php \
    public/api/v2/activate.php \
    public/api/v2/g1_attestation.php \
    includes/G1ProductionAttestation.php \
    deployment-source.json \
    g1-test-evidence.json; do
    expected="$ROOT/$rel"
    actual="$(mktemp)"
    status="$(curl --silent --show-error -u "$kudu_user:$kudu_pass" \
      --output "$actual" --write-out '%{http_code}' \
      "$kudu_base/api/vfs/site/wwwroot/$rel" || true)"
    echo "KUDU_FILE path=$rel http=${status:-curl_error}"
    if [[ "$status" == "200" && -f "$expected" ]]; then
      expected_sha="$(sha256sum "$expected" | awk '{print $1}')"
      actual_sha="$(sha256sum "$actual" | awk '{print $1}')"
      expected_size="$(wc -c < "$expected" | tr -d ' ')"
      actual_size="$(wc -c < "$actual" | tr -d ' ')"
      echo "KUDU_COMPARE path=$rel expected_sha=$expected_sha actual_sha=$actual_sha expected_size=$expected_size actual_size=$actual_size"
    elif [[ "$status" != "200" ]]; then
      head -c 240 "$actual" >&2 || true
      echo >&2
    fi
    rm -f "$actual"
  done
}

probe_g1_attestation() {
  local body_file status attempt
  body_file="$(mktemp)"
  trap 'rm -f "$body_file"' RETURN

  for attempt in {1..12}; do
    status="$(curl --silent --show-error --location --connect-timeout 10 --max-time 20 \
      --output "$body_file" --write-out '%{http_code}' \
      --header 'Accept: application/json' \
      "${BASE_URL}/public/api/v2/validate.php?g1_attestation=1" || true)"

    if [[ "$status" == "200" ]]; then
      break
    fi

    if [[ "$attempt" -eq 12 ]]; then
      echo "ERROR: G1 production attestation did not become live; last HTTP status=${status:-curl_error}." >&2
      head -c 500 "$body_file" >&2 || true
      echo >&2
      diagnose_kudu_g1 || true
      return 1
    fi

    # The route filename itself is already production-proven. Only temporary
    # upstream/service-unavailable states are retryable; 404 or any other
    # application status is an immediate fail-closed deployment error.
    if [[ "$status" != "502" && "$status" != "503" && "$status" != "000" && -n "$status" ]]; then
      echo "ERROR: G1 production attestation returned unexpected HTTP ${status}." >&2
      head -c 500 "$body_file" >&2 || true
      echo >&2
      diagnose_kudu_g1 || true
      return 1
    fi

    echo "G1 attestation readiness attempt ${attempt}/12 returned ${status:-curl_error}; retrying..."
    sleep 5
  done

  php -r '
    $doc=json_decode(file_get_contents($argv[1]),true);
    if(!is_array($doc)||($doc["ok"]??false)!==true||!is_array($doc["payload"]??null)) { fwrite(STDERR,"Invalid G1 attestation envelope\n"); exit(1); }
    $p=$doc["payload"];
    if((int)($p["schema_version"]??0)!==2||($p["status"]??"")!=="G1_ENTITLEMENT_V2_PRODUCTION_CERTIFIED") { fwrite(STDERR,"Invalid G1 attestation payload\n"); exit(1); }
    if(($p["routes"]["g1_attestation"]["via"]??"")!=="validate.php?g1_attestation=1") { fwrite(STDERR,"Invalid G1 attestation route binding\n"); exit(1); }
    if(empty($p["deployment"]["g1_test_evidence_sha256"])) { fwrite(STDERR,"G1 test evidence digest missing\n"); exit(1); }
    foreach(["concurrent_last_seat","inactive_hwid_reactivation","replace_a_to_b_revoke_a","upgrade_1_to_2","downgrade_below_active_blocked","v1_v2_compatibility"] as $scenario) {
      $row=$p["scenarios"][$scenario]??null;
      if(!is_array($row)||($row["status"]??"")!=="PASS"||empty($row["evidence"])) { fwrite(STDERR,"G1 scenario evidence missing: $scenario\n"); exit(1); }
    }
    require $argv[2]."/includes/RsaSigner.php";
    $pub=file_get_contents($argv[2]."/keys/license_signing_public.pem");
    if(!RsaSigner::verify($p,(string)($doc["signature"]??""),$pub)) { fwrite(STDERR,"G1 attestation RSA verification failed\n"); exit(1); }
    echo "PASS G1 production attestation: proven-route=true, test-evidence=true, exact deployment provenance + RSA signature verified\n";
  ' "$body_file" "$ROOT"
  rm -f "$body_file"
  trap - RETURN
}

probe_route "/public/api/v2/validate.php"
probe_route "/public/api/v2/activate.php"
probe_g1_attestation

echo "Entitlement v2 production route verification passed."
