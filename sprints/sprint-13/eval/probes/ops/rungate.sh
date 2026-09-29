#!/usr/bin/env bash
# usage: rungate.sh <label> <artisan args...>   -> detached run inside hr-backend, log at /tmp/gate-<label>.log (container)
label="$1"; shift
"$(dirname "$0")/stg.sh" hr-backend "nohup sh -c 'php artisan answer:gate $* > /tmp/gate-${label}.log 2>&1; echo EXIT=\$? >> /tmp/gate-${label}.log' >/dev/null 2>&1 & echo started ${label}"
