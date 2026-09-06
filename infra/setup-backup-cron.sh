#!/usr/bin/env bash
# setup-backup-cron.sh — installs (idempotently) the nightly `db-backup`
# cron entry on the EC2's `ubuntu` crontab (staging plan §6). Safe to
# re-run: greps for the exact marker line first, no-ops if already present.
#
# The job itself: `docker compose run --rm db-backup` (docker-compose.staging.yml)
# — pg_dump | gzip | aws s3 cp, using the instance profile's S3-write
# permission on the backups bucket (no static key, no SSM value beyond the
# already-existing RDS master password). Runs at 03:00 UTC, logs to
# /var/log/hr-staging-backup.log on the host (rotated by logrotate's default
# /etc/logrotate.d/rsyslog-style weekly rotation is NOT configured for this
# specific file — acceptable for a low-volume one-line-per-night log; revisit
# if it ever grows unexpectedly).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

KEY_FILE="${LOCAL_STATE_DIR}/${NAME_EC2_KEYPAIR}.pem"
EIP="$(cat "${LOCAL_STATE_DIR}/eip_address")"

MARKER="# hr-staging-db-backup (hr-docs/infra/setup-backup-cron.sh)"
CRON_LINE="0 3 * * * cd /opt/hr-staging && export AWS_REGION=${AWS_REGION} RDS_ENDPOINT=${RDS_ENDPOINT} STAGING_EIP=${STAGING_EIP} S3_DOCUMENTS_BUCKET=${NAME_S3_DOCUMENTS} S3_BACKUPS_BUCKET=${NAME_S3_BACKUPS} && docker compose -f docker-compose.staging.yml run --rm db-backup >> /var/log/hr-staging-backup.log 2>&1 ${MARKER}"

log "Checking for an existing hr-staging-db-backup cron entry..."
ssh -i "$KEY_FILE" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=8 ubuntu@"$EIP" bash -s <<REMOTE
set -euo pipefail
if crontab -l 2>/dev/null | grep -qF '${MARKER}'; then
  echo "Already installed — no-op."
else
  ( crontab -l 2>/dev/null || true; echo '${CRON_LINE}' ) | crontab -
  echo "Installed:"
  crontab -l | grep -F '${MARKER}'
fi
REMOTE

log "setup-backup-cron.sh done."
