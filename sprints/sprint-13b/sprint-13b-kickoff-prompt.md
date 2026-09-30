# Slice 13b — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-13b/kickoff-prompt.md`

---

You are planning **Slice 13b** in the hr-platform workspace. Read `hr-docs/sprints/sprint-13b/spec.md`, then Sprint 13's `plan.md` §B/§C (planner contract, tools, rule engine) and `review.md` (the fact-routing gate results and the colloquial/canonical split), and ADR-0035. Write `hr-docs/sprints/sprint-13b/plan.md`, then **STOP — no code, no commits.**

Cite `path:line`. Classic must stay byte-identical; this is agent-only.

1. **Reproduce the baseline.** From the existing fact-routing rows (no spend), label each case canonical vs colloquial and produce the split the spec cites (≈ 90% / 18%). Show the labelling rule so the gate compares like with like (R4).
2. **Planner contract extension.** Show the current `/plan` request/response schema and propose the `normalization` block (topic_id, canonical_query ≤ 25 words, confidence, reason) as native structured output on the **same** planner call — no extra model call. Show the prompt text the planner sees for it.
3. **Validation rule.** Propose `NormalizationValidationRule` (pre-call): topic exists and is approved; diff check — canonical adds no figures/amounts/dates/entitlement language absent from the literal (reuse `GeneralLanePostCheck::scan` on the *added* tokens), and no group/convenio/territory names the employee didn't use. Rejection → literal path, trace records it. Tests, including the ≥ 20-case normalization negative set (R1).
4. **Tool consumption.** `reference_fact` takes `topic_id` (lexicon fallback when null); `convenio_search`/`national_law` retrieve on canonical **unioned** with literal, never replacing (R3) — show how Check A behaves on the union and that the literal hits are preserved.
5. **Round-0 unchanged.** Show that the deterministic fast path still settles obvious questions before the planner and that normalization only runs for what it misses (R2).
6. **Trace + UI.** The normalization trace step (literal, canonical, topic, confidence, verdict), rendered in the trace panel and Historial; i18n keys both dictionaries.
7. **Gate plan.** How `answer:gate` reports the colloquial/canonical split; the run list (agent ×3 on fact-routing colloquial + canonical, all negative sets ×3, gold-2c ×3, situational ×3; classic not re-run) with an estimated spend; and the ten live colloquial questions for CP-1.
8. Ordered build steps, tests, open questions, the single **CP-1**.

Then **STOP** and wait for review.
