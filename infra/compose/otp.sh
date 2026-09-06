#!/usr/bin/env bash
# otp.sh <email> — request + auto-read + verify an OTP login code on staging.
#
# Build-time decision: no Postmark account exists yet, so staging runs
# MAIL_MAILER=log (see hr-backend/.env.staging.example) — the rendered email,
# including the plaintext OTP code, is written to the hr-backend container's
# storage/logs/laravel.log instead of being sent anywhere. This script reads
# it from there so login can be verified end-to-end without a real inbox.
# NOT needed once Postmark is configured (switch MAIL_MAILER=postmark, no
# code change — this script simply becomes unnecessary, not wrong).
#
# Run FROM the EC2 (after SSHing in, cd /opt/hr-staging).
set -euo pipefail

EMAIL="${1:?usage: otp.sh <email>}"
BASE_URL="${STAGING_BASE_URL:-http://localhost}"
COMPOSE_FILE="${COMPOSE_FILE:-/opt/hr-staging/docker-compose.staging.yml}"

echo "[otp] requesting a login code for ${EMAIL}..."
curl -sf -X POST "${BASE_URL}/api/auth/request-code" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\"}" >/dev/null
echo "[otp] requested."

sleep 1

echo "[otp] reading the code from hr-backend's log (MAIL_MAILER=log)..."
RAW_LOG="$(docker compose -f "$COMPOSE_FILE" exec -T hr-backend \
  tail -n 500 storage/logs/laravel.log)"

# The mailable's subject line ("Your HR Platform login code",
# app/Mail/LoginCodeMail.php) anchors the search so we don't accidentally
# match an unrelated 6-digit number elsewhere in the log tail. Quoted-
# printable soft line breaks ("=\n") are collapsed first in case the 6-digit
# code happens to straddle a wrapped line.
CODE="$(printf '%s\n' "$RAW_LOG" \
  | tr -d '\r' \
  | sed ':a;N;$!ba;s/=\n//g' \
  | awk '/Your HR Platform login code/{f=1} f' \
  | grep -oE '[0-9]{6}' \
  | head -n 1)"

if [[ -z "$CODE" ]]; then
  echo "[otp] could not find a 6-digit code in the recent log tail — is MAIL_MAILER=log and was the request-code call above actually for a REGISTERED, ACTIVE account?" >&2
  exit 1
fi

echo "[otp] code found: ${CODE}"
echo "[otp] verifying..."
curl -sf -X POST "${BASE_URL}/api/auth/verify-code" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"code\":\"${CODE}\"}"
echo
echo "[otp] done."
