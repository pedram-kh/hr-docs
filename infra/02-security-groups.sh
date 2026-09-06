#!/usr/bin/env bash
# 02-security-groups.sh — hr-staging-ec2-sg (22 from Pedram's current IP,
# resolved live, never hardcoded; 80/443 public) and hr-staging-rds-sg
# (5432 from hr-staging-ec2-sg ONLY, SG-to-SG, immune to the EC2's IP
# changing). Idempotent: describe before create; SSH rule is re-diffed
# against the current IP on every run (revoke stale, authorize current).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

VPC_ID=$(cat "${LOCAL_STATE_DIR}/vpc_id")
MY_IP=$(curl -s https://checkip.amazonaws.com | tr -d '[:space:]')
if [[ ! "$MY_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ERROR: could not resolve current public IP (got '$MY_IP')." >&2
  exit 1
fi
MY_CIDR="${MY_IP}/32"
log "Current public IP resolved live: ${MY_CIDR} (never hardcoded)."

get_sg_id() {
  aws ec2 describe-security-groups \
    --filters Name=vpc-id,Values="$VPC_ID" Name=group-name,Values="$1" \
    --query 'SecurityGroups[0].GroupId' --output text 2>/dev/null
}

# --- hr-staging-ec2-sg ---
EC2_SG_ID=$(get_sg_id "$NAME_EC2_SG")
if [[ "$EC2_SG_ID" == "None" || -z "$EC2_SG_ID" ]]; then
  log "Creating ${NAME_EC2_SG}..."
  EC2_SG_ID=$(aws ec2 create-security-group \
    --group-name "$NAME_EC2_SG" \
    --description "hr-staging EC2: SSH from admin IP only, HTTP/HTTPS public" \
    --vpc-id "$VPC_ID" \
    --tag-specifications "$(tag_spec security-group "$NAME_EC2_SG")" \
    --query 'GroupId' --output text)
  log "Created ${NAME_EC2_SG} = ${EC2_SG_ID}"
else
  log "${NAME_EC2_SG} already exists = ${EC2_SG_ID}"
fi
echo "$EC2_SG_ID" > "${LOCAL_STATE_DIR}/ec2_sg_id"

# Diff SSH rule against the current IP: revoke any stale 22/tcp CIDR rule that
# isn't the current IP, then authorize the current IP if not already present.
EXISTING_SSH_CIDRS=$(aws ec2 describe-security-groups --group-ids "$EC2_SG_ID" \
  --query "SecurityGroups[0].IpPermissions[?FromPort==\`22\`].IpRanges[].CidrIp" --output text)
for cidr in $EXISTING_SSH_CIDRS; do
  if [[ "$cidr" != "$MY_CIDR" ]]; then
    log "Revoking stale SSH rule for ${cidr}..."
    aws ec2 revoke-security-group-ingress --group-id "$EC2_SG_ID" \
      --protocol tcp --port 22 --cidr "$cidr" >/dev/null || true
  fi
done
if ! grep -qx "$MY_CIDR" <<< "$EXISTING_SSH_CIDRS"; then
  log "Authorizing SSH from ${MY_CIDR}..."
  aws ec2 authorize-security-group-ingress --group-id "$EC2_SG_ID" \
    --protocol tcp --port 22 --cidr "$MY_CIDR" >/dev/null
else
  log "SSH rule for ${MY_CIDR} already present."
fi

for port in 80 443; do
  EXISTS=$(aws ec2 describe-security-groups --group-ids "$EC2_SG_ID" \
    --query "SecurityGroups[0].IpPermissions[?FromPort==\`${port}\`].IpRanges[] | [?CidrIp=='0.0.0.0/0']" --output text)
  if [[ -z "$EXISTS" ]]; then
    log "Authorizing public ${port}/tcp..."
    aws ec2 authorize-security-group-ingress --group-id "$EC2_SG_ID" \
      --protocol tcp --port "$port" --cidr 0.0.0.0/0 >/dev/null
  else
    log "Public ${port}/tcp already present."
  fi
done

# --- hr-staging-rds-sg ---
RDS_SG_ID=$(get_sg_id "$NAME_RDS_SG")
if [[ "$RDS_SG_ID" == "None" || -z "$RDS_SG_ID" ]]; then
  log "Creating ${NAME_RDS_SG}..."
  RDS_SG_ID=$(aws ec2 create-security-group \
    --group-name "$NAME_RDS_SG" \
    --description "hr-staging RDS: 5432 from hr-staging-ec2-sg ONLY" \
    --vpc-id "$VPC_ID" \
    --tag-specifications "$(tag_spec security-group "$NAME_RDS_SG")" \
    --query 'GroupId' --output text)
  log "Created ${NAME_RDS_SG} = ${RDS_SG_ID}"
else
  log "${NAME_RDS_SG} already exists = ${RDS_SG_ID}"
fi
echo "$RDS_SG_ID" > "${LOCAL_STATE_DIR}/rds_sg_id"

SG_RULE_EXISTS=$(aws ec2 describe-security-groups --group-ids "$RDS_SG_ID" \
  --query "SecurityGroups[0].IpPermissions[?FromPort==\`5432\`].UserIdGroupPairs[] | [?GroupId=='${EC2_SG_ID}']" --output text)
if [[ -z "$SG_RULE_EXISTS" ]]; then
  log "Authorizing 5432/tcp from ${EC2_SG_ID} (SG-to-SG, not a CIDR)..."
  aws ec2 authorize-security-group-ingress --group-id "$RDS_SG_ID" \
    --protocol tcp --port 5432 --source-group "$EC2_SG_ID" >/dev/null
else
  log "5432/tcp from ${EC2_SG_ID} already present."
fi

log "02-security-groups.sh done. EC2 SG=${EC2_SG_ID} RDS SG=${RDS_SG_ID}"
