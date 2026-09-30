#!/bin/sh
# Slice 13d — freeze the NEW eval inputs. `answer:gate` refuses a bank whose sha256 differs from MANIFEST.sha256.
# (The 93-case fact-routing banks are the 13b ones, frozen by sprint-13b/eval/MANIFEST.sha256; the whitelist set is sprint-13's.)
cd "$(dirname "$0")/.." || exit 1
shasum -a 256 c20-jornada.json s2-seeded.json > MANIFEST.sha256
cat MANIFEST.sha256
