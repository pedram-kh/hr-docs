#!/usr/bin/env bash
# Re-capture hr-docs/guides/screenshots against staging. See capture-screenshots.mjs.
set -euo pipefail
cd "$(dirname "$0")"
npm install --no-fund --no-audit
npx playwright install chromium
node capture-screenshots.mjs
