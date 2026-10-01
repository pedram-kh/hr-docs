#!/usr/bin/env bash
# usage (on the box): close-smoke.sh <email> <question>  — real HTTP path through caddy (fixed staging OTP); prints the FULL response fields the
# employee screen gets (outcome, general_lane badge payload, answer) + message_id/session_uuid. Pipe over ssh: ssh box 'bash -s' < close-smoke.sh -- ...
set -euo pipefail
EMAIL="$1"; Q="$2"; SESSION="${3:-}"   # optional session uuid: pass a fresh one, the 24h window otherwise reuses the employee's last session
curl -sf -X POST http://localhost/api/auth/request-code -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$EMAIL\"}" >/dev/null
TOK=$(curl -sf -X POST http://localhost/api/auth/verify-code -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$EMAIL\",\"code\":\"135790\"}" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("token") or d.get("access_token"))')
curl -s -X POST http://localhost/api/chat/message -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$(python3 -c 'import json,sys; d={"question": sys.argv[1]}
if sys.argv[2]: d["session_uuid"]=sys.argv[2]
print(json.dumps(d))' "$Q" "$SESSION")" | python3 -c '
import sys,json
d=json.load(sys.stdin)
print(json.dumps({k:d.get(k) for k in ("outcome","escalated","escalation_reason","message_id","session_uuid","general_lane")}, ensure_ascii=False))
print("answer:", (d.get("answer") or "").replace("\n"," "))'
