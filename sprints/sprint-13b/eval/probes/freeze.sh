#!/bin/sh
# Sprint 13b — freeze the eval inputs. Any later edit changes the hash; the gate refuses a bank whose sha256
# differs from MANIFEST.sha256 (checked by `answer:gate` at build step 7).
cd "$(dirname "$0")/.." || exit 1
shasum -a 256 fact-routing-colloquial-dev.json fact-routing-colloquial.json normalization-negatives.json normalization-positives.json prompt-examples.json cp1-colloquial.json fact-routing-existing-canonical-anchored.json fact-routing-existing-colloquial-unanchored.json fact-routing-existing-colloquial-anchored.json > MANIFEST.sha256
cat MANIFEST.sha256
