#!/bin/sh
# Slice 13c — freeze the eval inputs (13b precedent). Any later edit changes the hash; `answer:gate` (build step 7) refuses
# a bank whose sha256 differs from MANIFEST.sha256.
#  - build-fixtures.py    -> the pool (52), the second-tier pool (24), prescreen fixtures, colloquial negatives
#  - freeze-positives.py  -> lane-positives.json (the S0 survivors, from results/s0-verification.jsonl + results/s0b-verify.log)
cd "$(dirname "$0")/.." || exit 1
python3 probes/build-fixtures.py >/dev/null && python3 probes/freeze-positives.py >/dev/null || exit 1
shasum -a 256 lane-positives-pool.json prescreen-fixtures.json lane-colloquial-negatives.json lane-positives-pool-2.json lane-positives.json > MANIFEST.sha256
cat MANIFEST.sha256
