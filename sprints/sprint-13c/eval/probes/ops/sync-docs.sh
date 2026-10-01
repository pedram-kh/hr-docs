#!/usr/bin/env bash
# Slice 13c — push ONLY the sprint-13c docs tree (fixtures + probes) to the staging box's /opt/hr-staging/hr-docs (mounted as /var/hr-docs in the containers).
set -euo pipefail
KEY="$HOME/.hr-staging/hr-staging-ec2-key.pem"; HOST=ubuntu@52.211.251.235
cd "$(dirname "$0")/../../../.."   # hr-docs/sprints
rsync -rlptc --exclude='.git' --exclude='.DS_Store' --exclude='results/' sprint-13c/ -e "ssh -i $KEY" $HOST:/opt/hr-staging/hr-docs/sprints/sprint-13c/
echo synced
