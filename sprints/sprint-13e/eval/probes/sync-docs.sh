#!/usr/bin/env bash
# Slice 13e — push ONLY the sprint-13e docs tree (fixtures + probes) to the staging box's /opt/hr-staging/hr-docs (mounted as /var/hr-docs in the containers).
# Run commands in the container with the 13c wrapper: ../../../../sprint-13c/eval/probes/ops/stg.sh hr-backend '<cmd>'
set -euo pipefail
KEY="$HOME/.hr-staging/hr-staging-ec2-key.pem"; HOST=ubuntu@52.211.251.235
cd "$(dirname "$0")/../../.."   # hr-docs/sprints
rsync -rlptc --exclude='.git' --exclude='.DS_Store' --exclude='results/' sprint-13e/ -e "ssh -i $KEY" $HOST:/opt/hr-staging/hr-docs/sprints/sprint-13e/
echo synced
