#!/usr/bin/env bash
# Shared SSM-secrets entrypoint for hr-backend, hr-backend-worker, and hr-ai
# on staging (staging plan §4, step 2).
#
# Resolves NAMED SSM parameters into process environment variables using the
# EC2 instance profile's credential chain (no static key material anywhere
# in the image, the container, or on disk — `aws ssm get-parameter` picks up
# temporary credentials from IMDS automatically), then `exec`s the real
# command. Nothing is ever written to a file inside the image or a
# bind-mounted `.env` — the resolved values live in this process's memory
# only and are gone the moment the container stops.
#
# Each mapping is fully explicit (VAR=/ssm/param/path) rather than derived
# from the parameter's basename — this is what lets the SAME underlying SSM
# value (e.g. the shared internal token) map to DIFFERENT env var names in
# different services (INTERNAL_TOKEN for hr-ai, HR_AI_INTERNAL_TOKEN for
# hr-backend) with zero naming-convention guesswork, and keeps every mapping
# reviewable in one place: docker-compose.staging.yml's `command:` lines.
#
# This file is bind-mounted read-only into each container by
# docker-compose.staging.yml (it lives in hr-docs, not any one app repo, so
# it is never baked into any app image) — one script, shared by construction.
#
# Usage:
#   entrypoint.sh VAR1=/ssm/path/one [VAR2=/ssm/path/two ...] -- <command> [args...]

set -euo pipefail

region="${AWS_REGION:-eu-west-1}"
mappings=()

while [[ "${1:-}" != "--" ]]; do
  if [[ $# -eq 0 ]]; then
    echo "entrypoint.sh: missing '--' separator before the command" >&2
    exit 1
  fi
  mappings+=("$1")
  shift
done
shift # drop the -- itself

if [[ ${#mappings[@]} -eq 0 ]]; then
  echo "entrypoint.sh: no VAR=/ssm/path mappings given" >&2
  exit 1
fi

for mapping in "${mappings[@]}"; do
  var="${mapping%%=*}"
  ssm_path="${mapping#*=}"
  if [[ -z "$var" || -z "$ssm_path" || "$var" == "$mapping" ]]; then
    echo "entrypoint.sh: malformed mapping '$mapping' (expected VAR=/ssm/path)" >&2
    exit 1
  fi
  value="$(aws ssm get-parameter \
             --name "$ssm_path" \
             --with-decryption \
             --region "$region" \
             --query 'Parameter.Value' \
             --output text)"
  export "$var=$value"
done

# Staging OTP uses MAIL_MAILER=log, which writes laravel.log. Artisan
# one-offs run as root and otherwise recreate that file as root:root 644;
# php-fpm (www-data) then cannot append, Send code 500s, and retries trip
# the OTP throttle. Keep the file group-writable whenever this entrypoint
# runs (container start and every `docker exec ... /entrypoint.sh` artisan).
if [[ -d /var/www/storage/logs ]]; then
  chmod 777 /var/www/storage/logs 2>/dev/null || true
  if [[ ! -f /var/www/storage/logs/laravel.log ]]; then
    touch /var/www/storage/logs/laravel.log 2>/dev/null || true
  fi
  chmod 666 /var/www/storage/logs/laravel.log 2>/dev/null || true
fi

exec "$@"
