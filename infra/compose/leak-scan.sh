#!/usr/bin/env bash
# leak-scan.sh <deploy-root> — staging plan §4, step 4.
#
# Proves no secret ever lands in a repo, an image layer, or the compose file
# itself. Run by deploy.sh BEFORE every build (not just once) so a future
# accidental secret commit is caught automatically on the next deploy, not
# just remembered as a one-time manual check.
#
# Exits non-zero (deploy.sh aborts) on any hit.
set -uo pipefail
ROOT="${1:?usage: leak-scan.sh <deploy-root>}"
cd "$ROOT"

fail=0

PATTERN='(AKIA[0-9A-Z]{16}|aws_secret_access_key\s*=\s*[^$[:space:]]|POSTMARK_API_KEY\s*=\s*[A-Za-z0-9]|-----BEGIN [A-Z ]*PRIVATE KEY-----)'

echo "[leak-scan] hr-backend repo..."
if git -C hr-backend grep -InE "$PATTERN" -- . ':(exclude)vendor' 2>/dev/null; then
  echo "[leak-scan] LEAK in hr-backend" >&2
  fail=1
fi

echo "[leak-scan] hr-ai repo..."
if git -C hr-ai grep -InE "$PATTERN" -- . ':(exclude).venv' 2>/dev/null; then
  echo "[leak-scan] LEAK in hr-ai" >&2
  fail=1
fi

echo "[leak-scan] hr-frontend repo..."
if git -C hr-frontend grep -InE "$PATTERN" -- . ':(exclude)node_modules' 2>/dev/null; then
  echo "[leak-scan] LEAK in hr-frontend" >&2
  fail=1
fi

echo "[leak-scan] docker-compose.staging.yml..."
if grep -InE 'AKIA|secret_access_key[[:space:]]*[:=][[:space:]]*[A-Za-z0-9]' docker-compose.staging.yml 2>/dev/null; then
  echo "[leak-scan] LEAK in docker-compose.staging.yml" >&2
  fail=1
fi

echo "[leak-scan] built image histories..."
for img in hr-staging-hr-backend:latest hr-staging-hr-ai:latest hr-staging-hr-frontend:latest; do
  if docker image inspect "$img" >/dev/null 2>&1; then
    if docker history --no-trunc "$img" 2>/dev/null | grep -iE 'AKIA|secret_access_key'; then
      echo "[leak-scan] LEAK in $img history" >&2
      fail=1
    fi
  fi
done

if [[ "$fail" -eq 0 ]]; then
  echo "[leak-scan] clean."
else
  echo "[leak-scan] FAILED — aborting deploy." >&2
fi
exit "$fail"
