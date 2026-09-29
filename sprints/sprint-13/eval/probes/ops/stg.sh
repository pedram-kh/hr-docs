#!/usr/bin/env bash
# usage: stg.sh <container-suffix> '<shell command run in /var/www with PID-1 env>'
# Mirrors hr-docs' on-box run-*.sh pattern (PID-1 environ re-imported for the SSM-sourced secrets).
set -euo pipefail
svc="$1"; shift
PREFIX=$(cat <<'EOS'
awk 'BEGIN{RS="\0"} { i = index($0, "="); if (i > 0) { k = substr($0, 1, i-1); v = substr($0, i+1); if (k ~ /^[A-Za-z_][A-Za-z0-9_]*$/) { gsub(/'"'"'/, "'"'"'\\'"'"''"'"'", v); printf "export %s='"'"'%s'"'"'\n", k, v } } }' /proc/1/environ > /tmp/pid1env.sh
set -a; . /tmp/pid1env.sh; set +a
cd /var/www
EOS
)
printf '%s\n%s\n' "$PREFIX" "$*" | ssh -i ~/.hr-staging/hr-staging-ec2-key.pem -o ConnectTimeout=15 ubuntu@52.211.251.235 "docker exec -i hr-staging-${svc}-1 bash -s"
