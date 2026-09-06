#!/usr/bin/env bash
# Shared constants for every hr-docs/infra script. Source, don't execute:
#   source "$(dirname "${BASH_SOURCE[0]}")/vars.sh"
#
# SHARED-ACCOUNT DISCIPLINE: this AWS account also hosts another project
# (reviewpilot*/scheduler* resources). Every name below carries the
# hr-staging- prefix; every create_* helper tags with Project/Env/ManagedBy.
# No script in this directory ever lists, describes-to-modify, or touches a
# resource without that prefix.

set -euo pipefail

export AWS_REGION="eu-west-1"
export AWS_DEFAULT_REGION="$AWS_REGION"
export ACCOUNT_ID="049681810267"

# --- Tags (applied to every created resource) ---
export TAG_PROJECT="Project=hr-platform"
export TAG_ENV="Env=staging"
export TAG_MANAGED_BY="ManagedBy=hr-docs/infra"
# aws cli --tag-specifications JSON fragment (Name added per-resource by caller)
tag_spec() {
  # $1 = ResourceType, $2 = extra Name value (optional)
  local rtype="$1"; local name="${2:-}"
  local tags="{Key=Project,Value=hr-platform},{Key=Env,Value=staging},{Key=ManagedBy,Value=hr-docs/infra}"
  if [[ -n "$name" ]]; then
    tags="{Key=Name,Value=${name}},${tags}"
  fi
  echo "ResourceType=${rtype},Tags=[${tags}]"
}

# --- Naming (every HR resource carries the hr-staging- prefix) ---
export NAME_EC2_SG="hr-staging-ec2-sg"
export NAME_RDS_SG="hr-staging-rds-sg"
export NAME_DB_SUBNET_GROUP="hr-staging-db-subnet-group"
export NAME_RDS_INSTANCE="hr-staging-db"
export NAME_RDS_DB_NAME="hr_platform"
export NAME_RDS_MASTER_USER="hr_staging_admin"
export NAME_S3_DOCUMENTS="hr-staging-documents-${ACCOUNT_ID}"
export NAME_S3_BACKUPS="hr-staging-backups-${ACCOUNT_ID}"
export NAME_IAM_ROLE="hr-staging-ec2-role"
export NAME_IAM_PROFILE="hr-staging-ec2-profile"
export NAME_IAM_BOOTSTRAP_POLICY="hr-staging-iam-bootstrap"
export NAME_EC2_KEYPAIR="hr-staging-ec2-key"
export NAME_EC2_TAG_NAME="hr-staging-app"
export NAME_EIP_TAG_NAME="hr-staging-eip"

export EC2_INSTANCE_TYPE_NORMAL="t3.large"
export EC2_INSTANCE_TYPE_INGEST="c7i.2xlarge"

# --- Stable resource identifiers, hardcoded on purpose ---
# deploy.sh (§5) runs ON the EC2 under the hr-staging-ec2-role instance
# profile, which deliberately has NO ec2:Describe*/rds:Describe* permission
# (only the narrow S3+SSM read policies from 06-iam.sh) — so it cannot
# self-discover the RDS endpoint or its own EIP the way scripts running under
# cursor-dev's PowerUserAccess can. Both are stable once created (an RDS
# endpoint hostname never changes across restarts/resizes; an Elastic IP is
# static by definition) — hardcoded here once, the same way ACCOUNT_ID above
# already is, rather than granting the instance profile broader describe
# access just to re-derive values that don't change. Update these two lines
# if the RDS instance or EIP is ever recreated from scratch (e.g. the
# production account re-run — vars.sh already carries different constants
# per account anyway).
export RDS_ENDPOINT="hr-staging-db.cpsukkwcomk6.eu-west-1.rds.amazonaws.com"
export STAGING_EIP="52.211.251.235"
export RDS_INSTANCE_CLASS="db.t4g.medium"
export RDS_ALLOCATED_STORAGE_GB="50"
export RDS_BACKUP_RETENTION_DAYS="7"
export EC2_ROOT_VOLUME_GB="100"

# --- SSM parameter names (values set separately — never hardcoded here) ---
export SSM_PREFIX="/hr-staging"
export SSM_RDS_MASTER_PASSWORD="${SSM_PREFIX}/rds/master-password"
export SSM_HR_BACKEND_APP_KEY="${SSM_PREFIX}/hr-backend/app-key"
export SSM_HR_BACKEND_POSTMARK_API_KEY="${SSM_PREFIX}/hr-backend/postmark-api-key"
export SSM_SHARED_INTERNAL_TOKEN="${SSM_PREFIX}/shared/internal-token"
export SSM_SHARED_HR_AI_DB_PASSWORD="${SSM_PREFIX}/shared/hr-ai-db-password"
# hr-ai's config.py takes ONE composed DSN (DATABASE_URL), not decomposed
# DB_HOST/DB_USER/... vars like hr-backend's config/database.php does — so
# this holds the FULL connection string (scoped `hr_ai` role + RDS endpoint +
# db name), derived once by 08-derived-secrets.sh from values that already
# exist (the RDS endpoint via `describe-db-instances`, the password via
# SSM_SHARED_HR_AI_DB_PASSWORD above) rather than typed by a human.
export SSM_HR_AI_DATABASE_URL="${SSM_PREFIX}/hr-ai/database-url"

# --- Local scratch (never committed) ---
export LOCAL_STATE_DIR="${HOME}/.hr-staging"
mkdir -p "$LOCAL_STATE_DIR"
chmod 700 "$LOCAL_STATE_DIR"

log() { echo "[$(date +%H:%M:%S)] $*" >&2; }
