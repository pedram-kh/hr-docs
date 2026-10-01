#!/bin/bash
# Slice 12b item 2 — runs INSIDE the hr-backend container (docker exec), the same
# PID-1 env-load recipe as /opt/hr-staging/run-*.sh. EC_* variables come from
# `docker exec -e`; see eval-clear.php for what they mean.
awk 'BEGIN{RS="\0"} {
  i = index($0, "=")
  if (i > 0) {
    k = substr($0, 1, i-1)
    v = substr($0, i+1)
    if (k ~ /^[A-Za-z_][A-Za-z0-9_]*$/) {
      gsub(/'"'"'/, "'"'"'\\'"'"''"'"'", v)
      printf "export %s='"'"'%s'"'"'\n", k, v
    }
  }
}' /proc/1/environ > /tmp/pid1env.sh
# Keep the EC_* variables passed by `docker exec -e` ahead of anything from PID 1.
_ec="$(env | grep '^EC_' || true)"
set -a
. /tmp/pid1env.sh
set +a
while IFS= read -r l; do [ -n "$l" ] && export "$l"; done <<<"$_ec"
php artisan tinker --execute="require '/tmp/eval-clear.php';"
