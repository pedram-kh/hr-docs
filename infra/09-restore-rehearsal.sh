#!/usr/bin/env bash
# 09-restore-rehearsal.sh — proves a snapshot is actually restorable (staging
# plan §6), not just that `aws rds create-db-snapshot` returned 200. Restores
# the given snapshot (default: the most recent hr-staging-* one) into a
# throwaway instance, runs the same row-count query used to baseline the
# live DB post-ingest, diffs the two, then deletes the throwaway instance
# (no final snapshot — the source snapshot being rehearsed still exists).
#
# Never touches the live hr-staging-db instance — restore-db-instance-from-
# db-snapshot always creates a NEW instance identifier, by AWS design.
#
# Usage: ./09-restore-rehearsal.sh [snapshot-id]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

RESTORE_ID="hr-staging-restore-test"
SNAPSHOT_ID="${1:-}"

if [[ -z "$SNAPSHOT_ID" ]]; then
  SNAPSHOT_ID=$(aws rds describe-db-snapshots --db-instance-identifier "$NAME_RDS_INSTANCE" \
    --snapshot-type manual --query 'DBSnapshots | sort_by(@, &SnapshotCreateTime)[-1].DBSnapshotIdentifier' \
    --output text)
fi
log "Rehearsing restore of snapshot: ${SNAPSHOT_ID}"

RDS_SG_ID=$(cat "${LOCAL_STATE_DIR}/rds_sg_id")
KEY_FILE="${LOCAL_STATE_DIR}/${NAME_EC2_KEYPAIR}.pem"

cleanup() {
  if aws rds describe-db-instances --db-instance-identifier "$RESTORE_ID" >/dev/null 2>&1; then
    log "Cleanup: deleting throwaway instance ${RESTORE_ID} (no final snapshot)..."
    aws rds delete-db-instance --db-instance-identifier "$RESTORE_ID" --skip-final-snapshot >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

if aws rds describe-db-instances --db-instance-identifier "$RESTORE_ID" >/dev/null 2>&1; then
  log "${RESTORE_ID} already exists from a prior interrupted run — deleting first."
  aws rds delete-db-instance --db-instance-identifier "$RESTORE_ID" --skip-final-snapshot >/dev/null
  log "Waiting for prior instance to finish deleting..."
  aws rds wait db-instance-deleted --db-instance-identifier "$RESTORE_ID"
fi

log "Restoring ${SNAPSHOT_ID} -> ${RESTORE_ID} (db.t4g.medium, same SG/subnet-group as prod, not publicly accessible)..."
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier "$RESTORE_ID" \
  --db-snapshot-identifier "$SNAPSHOT_ID" \
  --db-instance-class "$RDS_INSTANCE_CLASS" \
  --db-subnet-group-name "$NAME_DB_SUBNET_GROUP" \
  --vpc-security-group-ids "$RDS_SG_ID" \
  --no-publicly-accessible \
  --no-multi-az \
  --tags Key=Project,Value=hr-platform Key=Env,Value=staging Key=ManagedBy,Value=hr-docs/infra Key=Name,Value="$RESTORE_ID" \
  >/dev/null

log "Waiting for ${RESTORE_ID} to become available (~5-10 min)..."
aws rds wait db-instance-available --db-instance-identifier "$RESTORE_ID"
RESTORE_ENDPOINT=$(aws rds describe-db-instances --db-instance-identifier "$RESTORE_ID" \
  --query 'DBInstances[0].Endpoint.Address' --output text)
log "Available at ${RESTORE_ENDPOINT}. Running verification query via the EC2 host (same VPC, RDS_SG allows it)..."

PW=$(aws ssm get-parameter --name "$SSM_RDS_MASTER_PASSWORD" --with-decryption --query 'Parameter.Value' --output text)
QUERY="select 'documents' t, count(*) from documents union all select 'document_chunks', count(*) from document_chunks union all select 'document_pages', count(*) from document_pages union all select 'employees', count(*) from employees union all select 'admins', count(*) from admins union all select 'salary_tables', count(*) from salary_tables union all select 'salary_table_rows', count(*) from salary_table_rows order by 1;"

RESTORED_COUNTS=$(ssh -i "$KEY_FILE" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=8 ubuntu@"$STAGING_EIP" \
  "PGPASSWORD='$PW' psql -h '$RESTORE_ENDPOINT' -U '$NAME_RDS_MASTER_USER' -d '$NAME_RDS_DB_NAME' -t -A -F',' -c \"$QUERY\"")
unset PW

echo "$RESTORED_COUNTS" | tee "${LOCAL_STATE_DIR}/restore-rehearsal-counts.csv" >&2

log "09-restore-rehearsal.sh done. Compare the above against the live-DB baseline recorded in review.md."
