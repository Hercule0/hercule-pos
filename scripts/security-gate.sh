#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail=0

check_pattern() {
  local label="$1"
  local pattern="$2"
  if grep -RInE --exclude-dir=.git --exclude-dir=vendor --exclude-dir=deploy_package --exclude='composer.lock' "$pattern" . >/tmp/hercule-secret-scan.txt 2>/dev/null; then
    echo "SECURITY GATE FAILED: $label"
    cat /tmp/hercule-secret-scan.txt
    fail=1
  fi
}

# Detect actual PEM payloads, not source-code strings that mention PEM labels.
if ! python3 - <<'PY'
from pathlib import Path
import re, sys
skip = {'.git', 'vendor', 'deploy_package'}
pattern = re.compile(
    rb'-----BEGIN (?:RSA )?PRIVATE KEY-----\s+([A-Za-z0-9+/=\r\n]{128,})-----END (?:RSA )?PRIVATE KEY-----',
    re.M,
)
hits = []
for path in Path('.').rglob('*'):
    if not path.is_file() or any(part in skip for part in path.parts):
        continue
    try:
        data = path.read_bytes()
    except OSError:
        continue
    if pattern.search(data):
        hits.append(str(path))
if hits:
    print('SECURITY GATE FAILED: private PEM material committed')
    for hit in hits:
        print(hit)
    sys.exit(1)
PY
then
  fail=1
fi

check_pattern "OpenAI-style secret token committed" 'sk-(proj-)?[A-Za-z0-9_-]{20,}'
check_pattern "GitHub personal token committed" 'gh[pousr]_[A-Za-z0-9]{20,}'
check_pattern "AWS access key committed" 'AKIA[0-9A-Z]{16}'

# The old VAPID incident used a literal fallback in config. Ensure the private
# VAPID value can only come from environment configuration.
if grep -RInE --exclude-dir=.git --exclude-dir=vendor "VAPID_PRIVATE_KEY'\s*,\s*'[^']+" config includes public >/tmp/hercule-vapid-scan.txt 2>/dev/null; then
  echo "SECURITY GATE FAILED: hard-coded VAPID private key fallback"
  cat /tmp/hercule-vapid-scan.txt
  fail=1
fi

rm -f /tmp/hercule-secret-scan.txt /tmp/hercule-vapid-scan.txt

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi

# GitHub Actions deploys only after the complete PHP regression suite passes.
# Stamp the exact workflow source identity into the deployment package so the
# production G1 endpoint can sign an attestation tied to the deployed commit.
rm -f deployment-source.json
if [[ "${GITHUB_SHA:-}" =~ ^[0-9a-fA-F]{40}$ ]] \
  && [[ "${GITHUB_REPOSITORY:-}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] \
  && [[ -n "${GITHUB_RUN_ID:-}" ]]; then
  python3 - <<'PY'
import json, os
from pathlib import Path
payload = {
    'schema_version': 1,
    'repository': os.environ['GITHUB_REPOSITORY'],
    'commit_sha': os.environ['GITHUB_SHA'].lower(),
    'run_id': os.environ['GITHUB_RUN_ID'],
    # This file is only deployed by the workflow after all later validation
    # steps succeed; a failed test prevents the package upload/deploy jobs.
    'tests_passed': True,
}
Path('deployment-source.json').write_text(json.dumps(payload, separators=(',', ':')) + '\n', encoding='utf-8')
PY
fi

echo "Security gate passed: no committed production secret patterns found."
