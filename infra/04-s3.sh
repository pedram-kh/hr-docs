#!/usr/bin/env bash
# 04-s3.sh — hr-staging-documents-<account> (encrypted, public-access blocked,
# versioning off — documents are replaced via re-ingest) and
# hr-staging-backups-<account> (encrypted, public-access blocked, versioning
# ON, per spec). Names include the account id so a fresh-account re-run
# (production) never collides on a global S3 name.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

create_bucket_if_absent() {
  local bucket="$1"
  if aws s3api head-bucket --bucket "$bucket" >/dev/null 2>&1; then
    log "${bucket} already exists — skipping create."
    return 1
  fi
  log "Creating bucket ${bucket}..."
  if [[ "$AWS_REGION" == "us-east-1" ]]; then
    aws s3api create-bucket --bucket "$bucket" >/dev/null
  else
    aws s3api create-bucket --bucket "$bucket" \
      --create-bucket-configuration LocationConstraint="$AWS_REGION" >/dev/null
  fi
  aws s3api put-bucket-tagging --bucket "$bucket" --tagging \
    'TagSet=[{Key=Project,Value=hr-platform},{Key=Env,Value=staging},{Key=ManagedBy,Value=hr-docs/infra}]'
  aws s3api put-public-access-block --bucket "$bucket" --public-access-block-configuration \
    BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
  aws s3api put-bucket-encryption --bucket "$bucket" --server-side-encryption-configuration \
    '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}'
  return 0
}

create_bucket_if_absent "$NAME_S3_DOCUMENTS" || true

if create_bucket_if_absent "$NAME_S3_BACKUPS"; then
  :
fi
log "Ensuring versioning is ON for ${NAME_S3_BACKUPS}..."
aws s3api put-bucket-versioning --bucket "$NAME_S3_BACKUPS" \
  --versioning-configuration Status=Enabled

log "04-s3.sh done."
aws s3api list-buckets --query "Buckets[?starts_with(Name,'hr-staging-')].Name" --output table
