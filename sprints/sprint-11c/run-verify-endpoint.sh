#!/usr/bin/env bash
# Runs INSIDE the hr-backend container on the staging box. Same pid-1
# SSM-env rehydration trick as run-measure-graph.sh. See verify-endpoint.php's
# header for the scp/docker-cp invocation.
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
set -a
. /tmp/pid1env.sh
set +a
php artisan tinker --execute="require '/tmp/verify-endpoint.php';"
