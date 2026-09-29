#!/usr/bin/env bash
# usage: smoke13.sh <email> <question>  — real HTTP path through caddy on the box (fixed staging OTP), prints outcome + message_id
set -euo pipefail
EMAIL="$1"; Q="$2"
curl -sf -X POST http://localhost/api/auth/request-code -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$EMAIL\"}" >/dev/null
TOK=$(curl -sf -X POST http://localhost/api/auth/verify-code -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$EMAIL\",\"code\":\"135790\"}" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("token") or d.get("access_token"))')
curl -s -X POST http://localhost/api/chat/message -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$(python3 -c 'import json,sys; print(json.dumps({"question": sys.argv[1]}))' "$Q")" | python3 -c '
import sys,json
d=json.load(sys.stdin)
print(json.dumps({k:d.get(k) for k in ("outcome","escalated","escalation_reason","message_id","session_uuid")}, ensure_ascii=False))
print("answer:", (d.get("answer") or "")[:260].replace("\n"," "))
print("citations:", len(d.get("citations") or []))'
