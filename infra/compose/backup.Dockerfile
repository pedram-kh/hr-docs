# backup.Dockerfile — the db-backup service's image (staging plan §6).
#
# Found live: the shared entrypoint.sh (bind-mounted into every service,
# including this one) calls `aws ssm get-parameter` BEFORE exec-ing the
# container's command — so aws-cli must already be on $PATH at container
# START, not installed by the command itself (an `apk add aws-cli` inside
# `command:` runs too late; entrypoint.sh's own PGPASSWORD lookup fails
# first with "aws: command not found"). Baking it into the image at build
# time (this file) is the fix — one extra `docker compose build` layer,
# cached like every other service's image.
#
# postgres:16-alpine (not a generic aws-cli image): matches RDS's engine
# version 16 so pg_dump/pg_restore are protocol-compatible; alpine 3.23's
# community repo carries an `aws-cli` package directly (confirmed: `apk
# search aws-cli` -> aws-cli-2.32.7-r0), no pip/python bootstrapping needed.
FROM postgres:16-alpine
RUN apk add --no-cache aws-cli
