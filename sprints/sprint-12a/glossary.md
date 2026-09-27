# Sprint 12a — glossary addendum

Cross-reference: the Sprint 11b glossary in `hr-docs/sprints/sprint-11b/review.md` (section “Translation glossary — FINAL”) is still the source for every other term. Two decisions from this sprint override it.

## alcance, not ámbito

**alcance** is the standing Spanish word for what the English UI calls **scope**.

Sprint 11b approved **ámbito → "scope"**. That English half stands. The Spanish half does not: product copy uses **alcance** everywhere in the frontend (`es.ts`, both shells, the map, the trace step, gap labels, the reference-fact panel). Do not reintroduce ámbito in new chrome.

Backend-generated sentences were not edited in this pass. They still say ámbito. That is a separate decision; the list is in `review.md`.

## Status and Flags stay English

**Status** and **Flags** stay in English in column headers and detail field titles. That is deliberate client wording, not an untranslated leftover. Do not translate them in a later copy pass.
