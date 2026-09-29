#!/usr/bin/env bash
# usage: deploy-stg.sh [backend] [ai]   (no migration; rsync excludes .git etc.)
set -euo pipefail
KEY="$HOME/.hr-staging/hr-staging-ec2-key.pem"; SSHCMD="ssh -i $KEY"; HOST=ubuntu@52.211.251.235
cd /Users/pedram/Desktop/PROJECT/JV/HR-AI
svcs=()
for w in "$@"; do
  case $w in
    backend) (cd hr-backend && rsync -rlptc --exclude='.git' --exclude='vendor' --exclude='node_modules' --exclude='storage' --exclude='.env' --exclude='.env.backup' --exclude='.env.production' --exclude='.phpunit.cache' --exclude='.phpunit.result.cache' --exclude='public/build' --exclude='public/hot' --exclude='public/storage' --exclude='.idea' --exclude='.vscode' --exclude='.zed' --exclude='.nova' --exclude='.codex' --exclude='.cursor' --exclude='.DS_Store' --exclude='data/' --exclude='bootstrap/cache/' ./ -e "$SSHCMD" $HOST:/opt/hr-staging/hr-backend/); svcs+=(hr-backend hr-backend-worker hr-backend-scheduler);;
    ai) (cd hr-ai && rsync -rlptc --exclude='.git' --exclude='.venv' --exclude='__pycache__' --exclude='*.pyc' --exclude='.env' --exclude='.DS_Store' --exclude='.ruff_cache' --exclude='.pytest_cache' --exclude='_sanity.log' ./ -e "$SSHCMD" $HOST:/opt/hr-staging/hr-ai/); svcs+=(hr-ai);;
  esac
done
(cd hr-docs && rsync -rlptc --exclude='.git' --exclude='.DS_Store' sprints/sprint-13/ -e "$SSHCMD" $HOST:/opt/hr-staging/hr-docs/sprints/sprint-13/)
if [ ${#svcs[@]} -gt 0 ]; then
  build=$(printf '%s\n' "${svcs[@]}" | grep -E '^(hr-backend|hr-ai)$' | tr '\n' ' ')
  $SSHCMD $HOST "cd /opt/hr-staging && source hr-docs/infra/vars.sh && export AWS_REGION RDS_ENDPOINT STAGING_EIP && export S3_DOCUMENTS_BUCKET=\$NAME_S3_DOCUMENTS S3_BACKUPS_BUCKET=\$NAME_S3_BACKUPS && docker compose -f docker-compose.staging.yml build $build 2>&1 | tail -2 && docker compose -f docker-compose.staging.yml up -d --force-recreate ${svcs[*]} 2>&1 | tail -3; sleep 45; docker ps --format '{{.Names}} {{.Status}}'"
fi
