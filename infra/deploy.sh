#!/usr/bin/env bash
# deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>
#
# Staging plan §5. Run FROM the EC2 (ssh in, cd anywhere, then run this
# script — it clones/updates all four repos itself into /opt/hr-staging).
#
# Steps: clone/checkout pinned SHAs -> leak scan -> build -> migrate
# (hr-backend only, ADR-0007) -> up -> health-check loop -> print deployed
# SHAs -> record `.last-good-shas` (only on a fully green run).
#
# ROLLBACK is symmetric — it's just this same script with an OLDER set of
# SHAs:
#   ./deploy.sh $(cat /opt/hr-staging/.last-good-shas)
# (.last-good-shas holds the PREVIOUS good set until a new run succeeds, so
# rolling back means re-running with the four SHAs that were good BEFORE the
# run you're rolling back from — read it before, not after, a bad deploy.)
set -euo pipefail

ROOT=/opt/hr-staging
INFRA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_DIR="$INFRA_DIR/compose"

HR_BACKEND_SHA="${1:?usage: deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>}"
HR_AI_SHA="${2:?usage: deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>}"
HR_FRONTEND_SHA="${3:?usage: deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>}"
HR_DOCS_SHA="${4:?usage: deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>}"

# vars.sh is pure constants (no AWS API calls) — safe to source under the
# instance profile's narrow role, which has no ec2:Describe*/rds:Describe*.
source "$INFRA_DIR/vars.sh"

log() { echo "[deploy $(date +%H:%M:%S)] $*"; }

sudo mkdir -p "$ROOT"
sudo chown "$(id -u):$(id -g)" "$ROOT"
cd "$ROOT"

clone_or_checkout() {
  local name="$1" url="$2" sha="$3"
  if [[ -d "$name/.git" ]]; then
    log "${name}: fetching..."
    git -C "$name" fetch --all --tags -q
  else
    log "${name}: cloning..."
    git clone -q "$url" "$name"
  fi
  git -C "$name" checkout -q "$sha"
  log "${name} @ $(git -C "$name" rev-parse HEAD)"
}

clone_or_checkout hr-backend  https://github.com/pedram-kh/hr-backend.git  "$HR_BACKEND_SHA"
clone_or_checkout hr-ai       https://github.com/pedram-kh/hr-ai.git       "$HR_AI_SHA"
clone_or_checkout hr-frontend https://github.com/pedram-kh/hr-frontend.git "$HR_FRONTEND_SHA"
clone_or_checkout hr-docs     https://github.com/pedram-kh/hr-docs.git     "$HR_DOCS_SHA"

# Flatten the compose assets from hr-docs alongside the four repo checkouts —
# docker-compose.staging.yml's relative build contexts (./hr-backend, ...)
# and relative bind mounts (./entrypoint.sh, ./Caddyfile) both depend on this
# exact flat layout, not on hr-docs's own directory structure.
cp "$ROOT/hr-docs/infra/compose/docker-compose.staging.yml" "$ROOT/docker-compose.staging.yml"
cp "$ROOT/hr-docs/infra/compose/entrypoint.sh" "$ROOT/entrypoint.sh"
cp "$ROOT/hr-docs/infra/compose/Caddyfile" "$ROOT/Caddyfile"
cp "$ROOT/hr-docs/infra/compose/warm-model.py" "$ROOT/warm-model.py"
chmod +x "$ROOT/entrypoint.sh"

log "Leak scan (staging plan §4 step 4) — must pass before any build..."
bash "$ROOT/hr-docs/infra/compose/leak-scan.sh" "$ROOT"

# Non-secret values the compose file ${VAR}-substitutes (staging plan §3.3
# header) — all pure constants from vars.sh, no secret ever passed this way.
export AWS_REGION RDS_ENDPOINT STAGING_EIP
export S3_DOCUMENTS_BUCKET="$NAME_S3_DOCUMENTS"

log "Building images (on-instance build — no ECR this cut, plan §9 item 5)..."
docker compose -f docker-compose.staging.yml build

log "Running hr-backend migrations (hr-backend ONLY — ADR-0007, hr-ai never migrates)..."
docker compose -f docker-compose.staging.yml run --rm hr-backend php artisan migrate --force

log "Bringing the stack up (unchanged-image services are left running by compose)..."
docker compose -f docker-compose.staging.yml up -d

log "Health-check loop (hr-ai runs warm-model.py before uvicorn even binds its"
log "port on a first deploy — the BGE-M3 download, ~4.3GB, can take a while)..."
ok=1
ATTEMPTS=90
for i in $(seq 1 "$ATTEMPTS"); do
  ok=1
  curl -sf "http://localhost/up" >/dev/null || ok=0
  curl -sf "http://localhost/" >/dev/null || ok=0
  docker compose -f docker-compose.staging.yml exec -T hr-ai curl -sf http://localhost:8001/health >/dev/null 2>&1 || ok=0
  docker compose -f docker-compose.staging.yml exec -T hr-ai curl -sf http://localhost:8001/health/model >/dev/null 2>&1 || ok=0
  worker_state="$(docker compose -f docker-compose.staging.yml ps hr-backend-worker --format '{{.State}}' 2>/dev/null || echo missing)"
  [[ "$worker_state" == "running" ]] || ok=0
  if [[ "$ok" -eq 1 ]]; then
    log "All health checks green (attempt ${i}/${ATTEMPTS})."
    break
  fi
  log "Not ready yet (attempt ${i}/${ATTEMPTS}). Retrying in 10s..."
  sleep 10
done

if [[ "$ok" -ne 1 ]]; then
  log "Health checks did NOT pass after ${ATTEMPTS} attempts. NOT recording this as the last-good deploy."
  docker compose -f docker-compose.staging.yml ps
  exit 1
fi

log "Deployed commit SHAs:"
for name in hr-backend hr-ai hr-frontend hr-docs; do
  printf '  %-12s %s\n' "$name" "$(git -C "$ROOT/$name" rev-parse HEAD)"
done

# Only a fully green run updates .last-good-shas, so it's always one file
# away from "the four SHAs that were known-good before this run."
echo "$HR_BACKEND_SHA $HR_AI_SHA $HR_FRONTEND_SHA $HR_DOCS_SHA" > "$ROOT/.last-good-shas"
log "Recorded .last-good-shas. deploy.sh done."
