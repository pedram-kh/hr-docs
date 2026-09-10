#!/usr/bin/env bash
# deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>
#
# Staging plan §5. Run FROM the EC2 (ssh in, cd anywhere, then run this
# script — it clones/updates everything itself into /opt/hr-staging).
#
# This file is DELIBERATELY a tiny, rarely-changing bootstrap: its only job
# is to get hr-docs itself checked out to the target SHA, then `exec` the
# REAL deploy logic from that freshly-checked-out copy (deploy-run.sh). It
# does NOT `git checkout` its own repo and then keep running — a script
# that rewrites itself out from under its own already-running interpreter
# is a real hazard (found live: the old one-file version of this script did
# exactly that, and the running process kept executing whatever content
# bash had already buffered — sometimes stale, sometimes not, invisibly).
# `exec`ing a fresh process on the fresh file avoids that class of bug
# entirely, not just the one instance of it found this session.
#
# ROLLBACK is symmetric — it's just this same script with an OLDER set of
# SHAs:
#   ./deploy.sh $(cat /opt/hr-staging/.last-good-shas)
# (.last-good-shas holds the PREVIOUS good set until a new run succeeds, so
# rolling back means re-running with the four SHAs that were good BEFORE the
# run you're rolling back from — read it before, not after, a bad deploy.)
set -euo pipefail

ROOT=/opt/hr-staging
HR_DOCS_SHA="${4:?usage: deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>}"

log() { echo "[deploy $(date +%H:%M:%S)] $*"; }

sudo mkdir -p "$ROOT"
sudo chown "$(id -u):$(id -g)" "$ROOT"
cd "$ROOT"

HR_DOCS_SSH_URL="git@github-hr-docs:pedram-kh/hr-docs.git"

if [[ -d hr-docs/.git ]]; then
  # Self-healing: a checkout made before Item 0 still has origin pointed at
  # the old anonymous-HTTPS URL — re-assert it every run (no-op once it
  # already matches), same pattern as deploy-run.sh's clone_or_checkout.
  log "hr-docs: fetching..."
  git -C hr-docs remote set-url origin "$HR_DOCS_SSH_URL"
  git -C hr-docs fetch --all --tags -q
else
  log "hr-docs: cloning..."
  # Sprint 7g Item 0: aliased SSH via a read-only, per-repo deploy key
  # (~/.ssh/config on the box maps this alias -> github.com + the one key
  # scoped to this repo) — never anonymous HTTPS, never a PAT. See deploy.md.
  git clone -q "$HR_DOCS_SSH_URL" hr-docs
fi
git -C hr-docs checkout -q "$HR_DOCS_SHA"
log "hr-docs @ $(git -C hr-docs rev-parse HEAD)"

exec bash "$ROOT/hr-docs/infra/deploy-run.sh" "$@"
