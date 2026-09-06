#!/usr/bin/env bash
# start.sh — start EC2 + RDS, wait for both, then confirm the compose stack
# (once deployed, session 2+) comes back on its own via restart:
# unless-stopped — no manual step. Idempotent (no-ops if already running).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

INSTANCE_ID=$(cat "${LOCAL_STATE_DIR}/instance_id")
STATE=$(aws ec2 describe-instances --instance-ids "$INSTANCE_ID" --query 'Reservations[0].Instances[0].State.Name' --output text)
if [[ "$STATE" == "running" ]]; then
  log "EC2 already running."
else
  log "Starting EC2 ${INSTANCE_ID}..."
  aws ec2 start-instances --instance-ids "$INSTANCE_ID" >/dev/null
  aws ec2 wait instance-running --instance-ids "$INSTANCE_ID"
fi

RDS_STATUS=$(aws rds describe-db-instances --db-instance-identifier "$NAME_RDS_INSTANCE" --query 'DBInstances[0].DBInstanceStatus' --output text)
if [[ "$RDS_STATUS" == "available" ]]; then
  log "RDS already available."
else
  log "Starting RDS ${NAME_RDS_INSTANCE}..."
  aws rds start-db-instance --db-instance-identifier "$NAME_RDS_INSTANCE" >/dev/null
  log "Waiting for RDS to become available (~2-5 min)..."
  aws rds wait db-instance-available --db-instance-identifier "$NAME_RDS_INSTANCE"
fi

log "Re-associating Elastic IP (in case the instance got a fresh ENI)..."
EIP_ALLOC=$(aws ec2 describe-addresses --filters "Name=tag:Name,Values=${NAME_EIP_TAG_NAME}" --query 'Addresses[0].AllocationId' --output text)
aws ec2 associate-address --instance-id "$INSTANCE_ID" --allocation-id "$EIP_ALLOC" >/dev/null

log "start.sh done. Give the compose stack ~30-60s to auto-restart (restart: unless-stopped), then run status.sh."
