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

# Sprint 7g Item 5 (F-3) fix. The log dump is the mail's FULL raw MIME
# message — headers (From/To/Subject/Date/Message-ID/...) followed by its
# rendered HTML body — and the two things this must get right are (a) pick
# the right FIELD out of that dump, not just any 6-digit run, and (b) pick
# the right MESSAGE, i.e. the newest one addressed to THIS email.
#
# (a) — a real Message-ID FRAGMENT was being returned as "the code":
# Found live on staging (sprint-07g/review.md): a request for
# admin@hr-staging.internal logged
#   Message-ID: <798002088274730d69836be853d485a0@hr-platform.local>
# and the PREVIOUS version of this script (grep -oE '[0-9]{6}' on everything
# from the Subject line onward, `head -n 1`) returned "798002" — the first
# six digits of that hex Message-ID, which sits in the header block ABOVE
# the body — while the real, rendered code below it was "795253". A
# Message-ID frequently contains a run of 6+ consecutive decimal digits
# purely by coincidence (it is hex, and hex digits are 40% decimal), so this
# was a live, reproducible miss, not a one-off. Fixed by anchoring on the
# OTP's own markup instead of "any 6 digits anywhere": the mailable
# (resources/views/emails/login-code.blade.php) renders the code in exactly
# ONE place, a paragraph styled `letter-spacing: 6px` with nothing else
# nearby that reaches 6 consecutive digits (the style attribute's own
# numbers — 32px, 700, 6px, 24px, 0 — are each fewer than 6 digits and
# separated by non-digit characters) — so grep for THAT line specifically. A
# Message-ID, Date, or any other header can never match that line pattern,
# so this class of bug can't recur even if the header order changes.
#
# (b) — "newest code for THAT email": the previous version matched the
# newest occurrence of the SUBJECT text, with no check on WHO it was sent
# to. Two accounts requesting a code close together (e.g. this script run
# for one email right after another test run for a different one) could
# hand back the wrong account's code. Fixed by tracking each mail dump as
# its own record (a record starts at the one line Monolog itself prefixes
# with `[timestamp] channel.LEVEL: `; every other line of that same message
# is a continuation with no such prefix) and keeping only the LAST record
# whose own "To:" header matches $EMAIL exactly.
BEST_BLOCK="$(printf '%s\n' "$RAW_LOG" \
  | tr -d '\r' \
  | sed ':a;N;$!ba;s/=\n//g' \
  | awk -v email="$EMAIL" '
      function record_matches() { return (to_ok && subj_ok) }
      /^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[^]]*\] [^:]+: / {
        if (record_matches()) best = rec
        rec = $0
        sub(/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[^]]*\] [^:]+: /, "", rec)
        to_ok = (rec == "To: " email) ? 1 : 0
        subj_ok = (rec == "Subject: Your HR Platform login code") ? 1 : 0
        next
      }
      {
        rec = rec "\n" $0
        if ($0 == "To: " email) to_ok = 1
        if ($0 == "Subject: Your HR Platform login code") subj_ok = 1
      }
      END {
        if (record_matches()) best = rec
        print best
      }
    '
)"

CODE="$(printf '%s\n' "$BEST_BLOCK" \
  | grep -E 'letter-spacing:[[:space:]]*6px' \
  | tail -n 1 \
  | grep -oE '[0-9]{6}' \
  | head -n 1)"

if [[ -z "$CODE" ]]; then
  echo "[otp] could not find the OTP line (letter-spacing: 6px) in a login-code email addressed to ${EMAIL} in the recent log tail — is MAIL_MAILER=log and was the request-code call above actually for a REGISTERED, ACTIVE account?" >&2
  exit 1
fi

echo "[otp] code found: ${CODE}"
echo "[otp] verifying..."
curl -sf -X POST "${BASE_URL}/api/auth/verify-code" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"code\":\"${CODE}\"}"
echo
echo "[otp] done."
