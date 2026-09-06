#!/usr/bin/env bash
# 05-ssm-params.sh — SSM SecureString parameters under /hr-staging/*.
#
# Two classes of value, handled differently (never a secret in chat/log/repo):
#
#  1. MACHINE-ONLY credentials (RDS master password, the shared HR_AI_DB_
#     PASSWORD, the shared internal token, the Laravel APP_KEY): no human
#     needs to know or type these — they exist only so two processes can
#     agree on a shared secret. This script GENERATES them with openssl and
#     writes them straight to SSM via command substitution (the value never
#     touches a file, the terminal, or shell history as a literal — only the
#     *variable name* holding it exists in this process's memory). Generated
#     ONLY if the parameter doesn't already hold a real value (idempotent —
#     re-running never rotates a value silently).
#
#  2. HUMAN-OWNED secrets (a real Postmark API key, later, once a domain +
#     Postmark server exist): this script creates the parameter as an empty
#     placeholder ONLY. Pedram sets the real value himself later via the
#     console or `aws ssm put-parameter --overwrite` with the value coming
#     from a password manager / --with-decryption pipe — never a literal on
#     a command line that lands in shell history. Session 1 does not need
#     this value at all (MAIL_MAILER=log this session).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

param_has_real_value() {
  # true if the parameter exists and is not the placeholder literal
  local name="$1"
  local val
  val=$(aws ssm get-parameter --name "$name" --with-decryption --query 'Parameter.Value' --output text 2>/dev/null) || return 1
  [[ "$val" != "SET-ME-VIA-CONSOLE" && -n "$val" ]]
}

put_generated() {
  # $1 = param name, $2 = generator command (evaluated only if needed)
  # SSM rejects --tags together with --overwrite, so: first attempt is a
  # tagged CREATE (no --overwrite); if the parameter already exists that
  # create fails and we fall back to a tag-less --overwrite (idempotent).
  local name="$1"; local gen="$2"
  if param_has_real_value "$name"; then
    log "  ${name}: already has a real value — leaving untouched."
    return
  fi
  log "  ${name}: generating a machine-only value and storing directly to SSM (never displayed)."
  local value; value="$(eval "$gen")"
  if aws ssm put-parameter --name "$name" --type SecureString \
      --value "$value" \
      --tags Key=Project,Value=hr-platform Key=Env,Value=staging Key=ManagedBy,Value=hr-docs/infra \
      >/dev/null 2>&1; then
    return
  fi
  aws ssm put-parameter --name "$name" --type SecureString --overwrite --value "$value" >/dev/null
}

put_placeholder_if_absent() {
  local name="$1"
  if aws ssm get-parameter --name "$name" >/dev/null 2>&1; then
    log "  ${name}: already exists — leaving untouched (name-only creation is idempotent)."
    return
  fi
  log "  ${name}: creating empty placeholder (Pedram sets the real value later)."
  aws ssm put-parameter --name "$name" --type SecureString \
    --value "SET-ME-VIA-CONSOLE" \
    --tags Key=Project,Value=hr-platform Key=Env,Value=staging Key=ManagedBy,Value=hr-docs/infra \
    >/dev/null
}

log "Machine-only credentials (generated now, never printed):"
put_generated "$SSM_RDS_MASTER_PASSWORD"     "openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 40"
put_generated "$SSM_SHARED_HR_AI_DB_PASSWORD" "openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 40"
put_generated "$SSM_SHARED_INTERNAL_TOKEN"    "openssl rand -hex 32"
put_generated "$SSM_HR_BACKEND_APP_KEY"       "echo -n 'base64:'; openssl rand -base64 32"

log "Human-owned secrets (placeholder names only — unused this session, MAIL_MAILER=log):"
put_placeholder_if_absent "$SSM_HR_BACKEND_POSTMARK_API_KEY"

log "05-ssm-params.sh done. Parameters under ${SSM_PREFIX}/*:"
aws ssm get-parameters-by-path --path "$SSM_PREFIX" --recursive --query 'Parameters[].Name' --output table
