#!/usr/bin/env bash
# stop.sh — stop EC2 + RDS (compute stopped, storage still billed). Idempotent
# (checks current state; no-ops if already stopped).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

INSTANCE_ID=$(cat "${LOCAL_STATE_DIR}/instance_id")
STATE=$(aws ec2 describe-instances --instance-ids "$INSTANCE_ID" --query 'Reservations[0].Instances[0].State.Name' --output text)
if [[ "$STATE" == "stopped" || "$STATE" == "stopping" ]]; then
  log "EC2 already ${STATE}."
else
  log "Stopping EC2 ${INSTANCE_ID}..."
  aws ec2 stop-instances --instance-ids "$INSTANCE_ID" >/dev/null
fi

RDS_STATUS=$(aws rds describe-db-instances --db-instance-identifier "$NAME_RDS_INSTANCE" --query 'DBInstances[0].DBInstanceStatus' --output text)
if [[ "$RDS_STATUS" == "stopped" || "$RDS_STATUS" == "stopping" ]]; then
  log "RDS already ${RDS_STATUS}."
else
  log "Stopping RDS ${NAME_RDS_INSTANCE}..."
  aws rds stop-db-instance --db-instance-identifier "$NAME_RDS_INSTANCE" >/dev/null
fi
log "stop.sh done. (EIP stays allocated/associated — a stopped instance keeps its Elastic IP.)"
