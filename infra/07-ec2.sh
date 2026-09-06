#!/usr/bin/env bash
# 07-ec2.sh — t3.large, Ubuntu 24.04 (AMI resolved live via the public SSM
# parameter, never hardcoded), 100GB gp3, hr-staging-ec2-profile,
# hr-staging-ec2-sg, an Elastic IP. User-data installs Docker + Compose so
# session 2 can `docker compose up` immediately. Creates a dedicated SSH key
# pair whose PRIVATE KEY is written ONLY to a local file outside any repo
# (~/.hr-staging/, 0400) — never printed, never committed.
#
# Idempotent: describe first; only creates if absent.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

AMI_ID=$(aws ssm get-parameters \
  --names /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id \
  --query 'Parameters[0].Value' --output text)
log "Resolved Ubuntu 24.04 AMI (live, not hardcoded): ${AMI_ID}"

# --- Key pair (idempotent: describe first; private key written once, locally only) ---
KEY_FILE="${LOCAL_STATE_DIR}/${NAME_EC2_KEYPAIR}.pem"
if aws ec2 describe-key-pairs --key-names "$NAME_EC2_KEYPAIR" >/dev/null 2>&1; then
  log "Key pair ${NAME_EC2_KEYPAIR} already exists in AWS."
  if [[ ! -f "$KEY_FILE" ]]; then
    log "  WARNING: no local private key file at ${KEY_FILE}. If this is a fresh checkout, the" >&2
    log "  original private key material is unrecoverable from AWS (by design) — SSH will need" >&2
    log "  the key pair deleted+recreated, or Pedram's own already-saved copy." >&2
  fi
else
  log "Creating key pair ${NAME_EC2_KEYPAIR} (private key saved locally ONLY, never printed)..."
  aws ec2 create-key-pair --key-name "$NAME_EC2_KEYPAIR" \
    --tag-specifications "$(tag_spec key-pair "$NAME_EC2_KEYPAIR")" \
    --query 'KeyMaterial' --output text > "$KEY_FILE"
  chmod 400 "$KEY_FILE"
  log "  Saved to ${KEY_FILE} (chmod 400). This file is the only copy — back it up outside the repo."
fi

# --- EC2 instance ---
EXISTING_ID=$(aws ec2 describe-instances \
  --filters "Name=tag:Name,Values=${NAME_EC2_TAG_NAME}" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query 'Reservations[].Instances[0].InstanceId' --output text)
if [[ -n "$EXISTING_ID" && "$EXISTING_ID" != "None" ]]; then
  log "EC2 instance already exists: ${EXISTING_ID} — skipping create."
  INSTANCE_ID="$EXISTING_ID"
else
  SUBNET_ID=$(cat "${LOCAL_STATE_DIR}/ec2_subnet_id")
  EC2_SG_ID=$(cat "${LOCAL_STATE_DIR}/ec2_sg_id")

  USER_DATA=$(cat <<'EOF'
#!/bin/bash
set -e
apt-get update -y
apt-get install -y ca-certificates curl gnupg jq postgresql-client
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
apt-get update -y
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
usermod -aG docker ubuntu
mkdir -p /opt/hr-staging/ingest-scratch
chown -R ubuntu:ubuntu /opt/hr-staging
EOF
)

  log "Launching EC2 instance (${EC2_INSTANCE_TYPE_NORMAL}, ${EC2_ROOT_VOLUME_GB}GB gp3)..."
  INSTANCE_ID=$(aws ec2 run-instances \
    --image-id "$AMI_ID" \
    --instance-type "$EC2_INSTANCE_TYPE_NORMAL" \
    --key-name "$NAME_EC2_KEYPAIR" \
    --subnet-id "$SUBNET_ID" \
    --security-group-ids "$EC2_SG_ID" \
    --iam-instance-profile "Name=${NAME_IAM_PROFILE}" \
    --block-device-mappings "[{\"DeviceName\":\"/dev/sda1\",\"Ebs\":{\"VolumeSize\":${EC2_ROOT_VOLUME_GB},\"VolumeType\":\"gp3\",\"DeleteOnTermination\":true}}]" \
    --user-data "$USER_DATA" \
    --tag-specifications "$(tag_spec instance "$NAME_EC2_TAG_NAME")" \
    --query 'Instances[0].InstanceId' --output text)
  log "Launched ${INSTANCE_ID}. Waiting for 'running'..."
  aws ec2 wait instance-running --instance-ids "$INSTANCE_ID"
fi
echo "$INSTANCE_ID" > "${LOCAL_STATE_DIR}/instance_id"

# --- Elastic IP ---
EIP_ALLOC=$(aws ec2 describe-addresses --filters "Name=tag:Name,Values=${NAME_EIP_TAG_NAME}" \
  --query 'Addresses[0].AllocationId' --output text)
if [[ -z "$EIP_ALLOC" || "$EIP_ALLOC" == "None" ]]; then
  log "Allocating Elastic IP..."
  EIP_ALLOC=$(aws ec2 allocate-address --domain vpc \
    --tag-specifications "$(tag_spec elastic-ip "$NAME_EIP_TAG_NAME")" \
    --query 'AllocationId' --output text)
else
  log "Elastic IP already allocated: ${EIP_ALLOC}"
fi

ASSOCIATED_INSTANCE=$(aws ec2 describe-addresses --allocation-ids "$EIP_ALLOC" --query 'Addresses[0].InstanceId' --output text)
if [[ "$ASSOCIATED_INSTANCE" != "$INSTANCE_ID" ]]; then
  log "Associating Elastic IP with ${INSTANCE_ID}..."
  aws ec2 associate-address --instance-id "$INSTANCE_ID" --allocation-id "$EIP_ALLOC" >/dev/null
else
  log "Elastic IP already associated with ${INSTANCE_ID}."
fi

EIP_ADDR=$(aws ec2 describe-addresses --allocation-ids "$EIP_ALLOC" --query 'Addresses[0].PublicIp' --output text)
echo "$EIP_ADDR" > "${LOCAL_STATE_DIR}/eip_address"

log "07-ec2.sh done. Instance: ${INSTANCE_ID}  EIP: ${EIP_ADDR}"
log "SSH (once user-data finishes, ~1-2 min after running): ssh -i ${KEY_FILE} ubuntu@${EIP_ADDR}"
