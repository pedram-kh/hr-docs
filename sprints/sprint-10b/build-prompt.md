# Sprint 10b — Build authorization (paste into the Cursor build thread)

> Save as: `hr-docs/sprints/sprint-10b/build-prompt.md`
> Plan: `hr-docs/sprints/sprint-10b/plan.md` — reviewed and **accepted**. Build on branch **`sprint-10b`** (hr-backend, hr-ai, hr-docs; hr-frontend only if the trace panel needs the `decomposed_queries` render — plan suggests admin TracePanel does). **STOP — no commit, no merge, until `review.md` is reviewed.**

## Decisions — all resolved

| # | Decision |
|---|---|
| D1 | **CP-1 approved.** The §A.1 Table 2 mapping table as written: "plus de transporte" → salary pattern added (the one real addition); paga extra / nómina / asuntos propios confirmed already covered, no change; **finiquito → no change** (guardrail keeps it; out of scope per spec §3). |
| D2 | **CP-1 approved with one correction.** The `explicit_request` closed list ships at §C.6's proposed width **plus a negative lookahead** so the optional RRHH qualifier can't leak: bare "quiero/necesito/me gustaría hablar/contactar con una persona/alguien" fires; "…con alguien/una persona **de <anything not RRHH>**" must NOT fire. Add *"necesito hablar con alguien de mi equipo sobre el horario"* to the negative eval set. Exact lookahead form is the builder's call; the eval negatives are the contract. |
| D3 | **Placement per §C.4:** after both guardrail layers, before the reference-fact pre-check. Sensitive+human-request messages escalate `sensitive_topic`, never `explicit_request`. |
| D4 | **Decomposition per §B:** second parallel array (`decomposed_queries`), never a `subqueries` variant; extend `/route` prompt+schema additively per §B.1's diff; union join at `ChatService.php:1125` via `array_merge`; **cap formula counts `decomposed_queries` symmetrically** (§B.2's fix, approved); second `retrieveUnion()` call site (`:452`) gets the new param defaulted `[]`, wired to nothing. `RouterResult` gains the field with a list default so no other construction site changes. |
| D5 | **Ops hardening:** `/synthesise` truncation retry as §D.1's line-for-line port of `/ground`'s pattern, including the distinct `synthesis_truncated` outcome (not conflated with `parse_error`). `parse_error` enrichment (`stop_reason`, `completion_tokens`) at **all eight** sites per §D.2's corrected table. No prompt text changes anywhere in this item. |
| D6 | **ADR-0033** records the decomposition design AND the pre-pilot rationale explicitly: real staging traffic is entirely self-authored canonical test questions; the constructed eval is deliberate readiness-before-pilot, and the pilot is the real measurement. Roadmap numbering fixed per spec header (10-M tickets close here; "10c" reverts to fact segmentation). |

## Build order — plan §E.3 as written

1. `explicit_request` pre-check (with D2's corrected patterns) + its unit coverage.
2. Colloquial lexicon addition + the consolidated regression sets (§C.1/C.2 → `eval/salary-lexicon-gold.json`, `eval/reference-fact-lexicon-gold.json`).
   → ⏸ **CP-2**: inject to staging, Pedram eyes-on spec §6 items 3–5.
3. Ops hardening (§D.1, D.2).
   → ⏸ **CP-3**: `Sprint7cAdditivityRegressionTest` + the full 10a gold-eval re-run (§E.2, all three profiles) — report the numbers, not "still green."
4. Situational decomposition (§B, with D4).
   → ⏸ **CP-4** *before* building the eval harness runs: present the constructed situational gold set (§E.1 item 3) for Pedram's realism check.
   → ⏸ **CP-5**: eyes-on spec §6 items 1–2, 6.

## Invariants — `Sprint10bInvariantTest`, plan §E.4 as written, plus:

- **T-D2:** the D2 lookahead negative ("…alguien de mi equipo…") asserted directly.
- **T-superset note:** the additivity assertion (§E.4 first bullet) must assert on the **chunk-id set**, not answer text, per §E.1 item 3's own reasoning.
- Migrations: none (§E.5 confirmed). If build discovers one is needed anyway, STOP and report before writing it.

## At close

`review.md` with: all eval tables (real vs constructed labelled), the CP-3 regression numbers, the injection table if staging carries uncommitted code at any point, and docs per spec §7 (architecture.md diagram gains the `explicit_request` pre-check and the decomposition arrow; data-model.md trace fields; roadmap fix). **Then STOP** for review and final eyes-on.
