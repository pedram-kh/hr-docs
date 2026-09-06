#!/usr/bin/env bash
# 01-network.sh — confirm the default VPC + subnets (creates NOTHING network-
# level), and create the RDS DB subnet group (RDS requires >=2 AZs even for a
# single-AZ instance — an RDS constraint, not a staging choice).
#
# Idempotent: describes first; only creates the subnet group if absent.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

log "Confirming default VPC in ${AWS_REGION}..."
VPC_ID=$(aws ec2 describe-vpcs --filters Name=is-default,Values=true \
  --query 'Vpcs[0].VpcId' --output text)
if [[ "$VPC_ID" == "None" || -z "$VPC_ID" ]]; then
  echo "ERROR: no default VPC in ${AWS_REGION}. This plan assumes one exists (staging-env-spec.md:22)." >&2
  exit 1
fi
log "Default VPC: ${VPC_ID}"

log "Confirming default subnets..."
SUBNET_IDS=$(aws ec2 describe-subnets --filters Name=vpc-id,Values="$VPC_ID" Name=default-for-az,Values=true \
  --query 'Subnets[].SubnetId' --output text)
SUBNET_COUNT=$(wc -w <<< "$SUBNET_IDS" | tr -d ' ')
if [[ "$SUBNET_COUNT" -lt 2 ]]; then
  echo "ERROR: fewer than 2 default subnets found; RDS subnet group needs >=2 AZs." >&2
  exit 1
fi
log "Default subnets (${SUBNET_COUNT}): ${SUBNET_IDS}"
# First AZ (alphabetical) is used for the EC2 instance in 07-ec2.sh.
FIRST_SUBNET=$(aws ec2 describe-subnets --filters Name=vpc-id,Values="$VPC_ID" Name=default-for-az,Values=true \
  --query 'sort_by(Subnets, &AvailabilityZone)[0].SubnetId' --output text)
log "EC2 target subnet (first AZ): ${FIRST_SUBNET}"

echo "$VPC_ID" > "${LOCAL_STATE_DIR}/vpc_id"
echo "$FIRST_SUBNET" > "${LOCAL_STATE_DIR}/ec2_subnet_id"
echo "$SUBNET_IDS" > "${LOCAL_STATE_DIR}/all_subnet_ids"

log "Checking DB subnet group '${NAME_DB_SUBNET_GROUP}'..."
if aws rds describe-db-subnet-groups --db-subnet-group-name "$NAME_DB_SUBNET_GROUP" >/dev/null 2>&1; then
  log "DB subnet group already exists — skipping."
else
  log "Creating DB subnet group..."
  # shellcheck disable=SC2086
  SUBNET_ARR=($SUBNET_IDS)
  aws rds create-db-subnet-group \
    --db-subnet-group-name "$NAME_DB_SUBNET_GROUP" \
    --db-subnet-group-description "hr-staging RDS subnet group (default VPC subnets)" \
    --subnet-ids "${SUBNET_ARR[@]}" \
    --tags Key=Project,Value=hr-platform Key=Env,Value=staging Key=ManagedBy,Value=hr-docs/infra Key=Name,Value="$NAME_DB_SUBNET_GROUP" \
    >/dev/null
  log "Created DB subnet group '${NAME_DB_SUBNET_GROUP}'."
fi

log "01-network.sh done."
