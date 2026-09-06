#!/usr/bin/env bash
# 06-iam.sh — creates hr-staging-ec2-role (trust: ec2.amazonaws.com) with two
# INLINE role policies (S3 read on the two hr-staging buckets only; SSM read
# on /hr-staging/* + kms:Decrypt on alias/aws/ssm) and hr-staging-ec2-profile.
#
# REQUIRES the plan §2.4 inline policy to be attached to cursor-dev first
# (Pedram Moment 1) — PowerUserAccess alone cannot run this script. Detach
# it again immediately after (Pedram Moment 2); every later script runs on
# PowerUserAccess alone.
#
# Idempotent: get-role / get-instance-profile first; only creates if absent;
# inline policies are always re-PUT (PutRolePolicy is itself idempotent —
# it's a full replace of that named policy document, safe to re-run).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

TRUST_POLICY='{
  "Version": "2012-10-17",
  "Statement": [
    {"Effect": "Allow", "Principal": {"Service": "ec2.amazonaws.com"}, "Action": "sts:AssumeRole"}
  ]
}'

if aws iam get-role --role-name "$NAME_IAM_ROLE" >/dev/null 2>&1; then
  log "${NAME_IAM_ROLE} already exists — skipping create."
else
  log "Creating role ${NAME_IAM_ROLE}..."
  aws iam create-role --role-name "$NAME_IAM_ROLE" \
    --assume-role-policy-document "$TRUST_POLICY" \
    --tags Key=Project,Value=hr-platform Key=Env,Value=staging Key=ManagedBy,Value=hr-docs/infra \
    >/dev/null
fi

S3_READ_POLICY=$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "HrStagingS3ListBothBuckets",
      "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": [
        "arn:aws:s3:::${NAME_S3_DOCUMENTS}",
        "arn:aws:s3:::${NAME_S3_BACKUPS}"
      ]
    },
    {
      "Sid": "HrStagingS3ReadWriteDocuments",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::${NAME_S3_DOCUMENTS}/*"
    },
    {
      "Sid": "HrStagingS3ReadWriteBackups",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject"],
      "Resource": "arn:aws:s3:::${NAME_S3_BACKUPS}/*"
    }
  ]
}
EOF
)
log "Putting inline policy hr-staging-s3-read on ${NAME_IAM_ROLE}..."
aws iam put-role-policy --role-name "$NAME_IAM_ROLE" --policy-name "hr-staging-s3-read" \
  --policy-document "$S3_READ_POLICY"

SSM_READ_POLICY=$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "HrStagingSsmReadOwnPath",
      "Effect": "Allow",
      "Action": ["ssm:GetParameter", "ssm:GetParameters", "ssm:GetParametersByPath"],
      "Resource": "arn:aws:ssm:${AWS_REGION}:${ACCOUNT_ID}:parameter${SSM_PREFIX}/*"
    },
    {
      "Sid": "HrStagingKmsDecryptSsmDefaultKey",
      "Effect": "Allow",
      "Action": ["kms:Decrypt"],
      "Resource": "arn:aws:kms:${AWS_REGION}:${ACCOUNT_ID}:alias/aws/ssm"
    }
  ]
}
EOF
)
log "Putting inline policy hr-staging-ssm-read on ${NAME_IAM_ROLE}..."
aws iam put-role-policy --role-name "$NAME_IAM_ROLE" --policy-name "hr-staging-ssm-read" \
  --policy-document "$SSM_READ_POLICY"

if aws iam get-instance-profile --instance-profile-name "$NAME_IAM_PROFILE" >/dev/null 2>&1; then
  log "${NAME_IAM_PROFILE} already exists — skipping create."
else
  log "Creating instance profile ${NAME_IAM_PROFILE}..."
  aws iam create-instance-profile --instance-profile-name "$NAME_IAM_PROFILE" \
    --tags Key=Project,Value=hr-platform Key=Env,Value=staging Key=ManagedBy,Value=hr-docs/infra \
    >/dev/null
  log "Waiting for instance profile propagation..."
  sleep 8
fi

ALREADY_IN_PROFILE=$(aws iam get-instance-profile --instance-profile-name "$NAME_IAM_PROFILE" \
  --query "InstanceProfile.Roles[?RoleName=='${NAME_IAM_ROLE}'] | []" --output text)
if [[ -z "$ALREADY_IN_PROFILE" ]]; then
  log "Adding ${NAME_IAM_ROLE} to ${NAME_IAM_PROFILE}..."
  aws iam add-role-to-instance-profile --instance-profile-name "$NAME_IAM_PROFILE" --role-name "$NAME_IAM_ROLE"
else
  log "${NAME_IAM_ROLE} already in ${NAME_IAM_PROFILE}."
fi

log "Verifying..."
aws iam get-role --role-name "$NAME_IAM_ROLE" --query 'Role.{Name:RoleName,Arn:Arn,Created:CreateDate}' --output table
aws iam list-role-policies --role-name "$NAME_IAM_ROLE" --output table
aws iam get-instance-profile --instance-profile-name "$NAME_IAM_PROFILE" \
  --query 'InstanceProfile.{Name:InstanceProfileName,Arn:Arn,Roles:Roles[].RoleName}' --output table

log "06-iam.sh done."
