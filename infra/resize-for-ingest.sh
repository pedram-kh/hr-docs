#!/usr/bin/env bash
# resize-for-ingest.sh — stop -> change instance type to c7i.2xlarge -> start.
# The EBS root volume (Docker images, the model-cache named volume) is
# untouched by an instance-type change, so there is no re-download. RDS is
# left alone (unaffected by an EC2 resize). Idempotent (no-ops if already
# c7i.2xlarge).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

INSTANCE_ID=$(cat "${LOCAL_STATE_DIR}/instance_id")
CURRENT_TYPE=$(aws ec2 describe-instances --instance-ids "$INSTANCE_ID" --query 'Reservations[0].Instances[0].InstanceType' --output text)
if [[ "$CURRENT_TYPE" == "$EC2_INSTANCE_TYPE_INGEST" ]]; then
  log "Already ${EC2_INSTANCE_TYPE_INGEST} — no-op."
  exit 0
fi

log "Stopping ${INSTANCE_ID} to resize ${CURRENT_TYPE} -> ${EC2_INSTANCE_TYPE_INGEST}..."
aws ec2 stop-instances --instance-ids "$INSTANCE_ID" >/dev/null
aws ec2 wait instance-stopped --instance-ids "$INSTANCE_ID"

log "Changing instance type..."
aws ec2 modify-instance-attribute --instance-id "$INSTANCE_ID" --instance-type "$EC2_INSTANCE_TYPE_INGEST"

log "Starting ${INSTANCE_ID}..."
aws ec2 start-instances --instance-ids "$INSTANCE_ID" >/dev/null
aws ec2 wait instance-running --instance-ids "$INSTANCE_ID"

EIP_ALLOC=$(aws ec2 describe-addresses --filters "Name=tag:Name,Values=${NAME_EIP_TAG_NAME}" --query 'Addresses[0].AllocationId' --output text)
aws ec2 associate-address --instance-id "$INSTANCE_ID" --allocation-id "$EIP_ALLOC" >/dev/null

log "resize-for-ingest.sh done — now ${EC2_INSTANCE_TYPE_INGEST}. Give the compose stack ~30-60s to restart."
