# ADR-0037 — Several verified facts that tie on one topic are answered together only when their `raw_values` name provably different quantities; the logical key cannot tell the two cases apart, and everything unproven still escalates

**Status:** accepted (Slice 13d — plan accepted 2026-09-30, decisions Q1–Q10 on `sprints/sprint-13d/plan.md` §10). Gate results are appended to this ADR at close-out (`sprints/sprint-13d/review.md`).

## Context

`ReferenceFactAnswerService::selectMostRecent()` assumes one verified fact per (scope, topic). When two verified, in-scope, in-validity facts in the **same tier** share the most-recent `validity_start` and differ in `value`, it returns `ambiguous_conflict` and the turn escalates `reference_fact_coverage_gap` — for every question on that topic, for every employee of that convenio, on both engines (Slice 13b, `ho-jor-04`; roadmap §7 pre-pilot ticket). Sprint 7d's resolution machinery (ADR-0024) handles *two versions of one value*; it was never built for *two complementary facts filed under one topic*, which ADR-0034's bundling precedent legitimately produces (convenio 20 / `jornada`: fact 140 the annual hours, fact 143 the general working-time rules).

Slice 13d's spec proposed the discriminator "different logical keys ⇒ complementary; same key + different value + no supersession ⇒ contradictory". The plan's read-only checks on staging showed it cannot work:

1. **Facts 140 and 143 have an identical full logical key** — convenio 20, topic `jornada`, no category, no group label, `2025-01-01..2028-12-31` (`ReferenceFact::LOGICAL_KEY`; the 7b-2 writer adds `source` and `source_document_id`, also identical). It is the only same-key pair across all 154 rows. Under the spec's rule they are *contradictory*: the slice would have changed nothing for `ho-jor-04`.
2. **Tier 3 (convenio-wide) can never have different keys** — null category and null label by definition; the clause is dead code for the one tier the slice exists for.
3. **In tiers 1–2 it would be unsafe.** Facts there can differ only by `group_label`, a printed string (ADR-0028: the node is the identity, the label is provenance). "Grupo 2" and "Grupo 2 (área 5)" bound to one node are exactly the *probable versions* 7d/7g flag; treating a label difference as "complementary" would compose two versions of one value.
4. The collision was **created by a human edit** (fact 140's `group_label`/`value` were edited during AI-assisted triage, 2026-09-15, in a batch created together on 2026-09-14), which `ReferenceFactProposalService::persist()`'s upsert guard cannot prevent.

## Decision

1. **Quantity, not key (rule R-Q).** Two facts are **complementary** iff the *named quantities* in their `raw_values` are **disjoint** (keys lowercased, accent-stripped, non-alphanumeric runs → `_`). 140 = `{2025, 2026, 2027, 2028}`; 143 = `{jornada_irregular, computo_tiempo_trabajo, dias_libre_disposicion, descanso_jornada_continuada}` ⇒ complementary. Anything the rule cannot **prove** complementary is **contradictory**, i.e. today's behaviour:
   - `raw_values` null / empty / a JSON list (no named quantity to vouch for);
   - any **shared** key with differing values;
   - a `duplicate_of_id` link between the two whose `resolution` is still null (7b-2 flagged them as probable versions and no human decided). `coexists` does **not** unlock composition (ADR-0024: such a pair "will still escalate").
   A cohort is a complementary **set** only if *every pair* is complementary; one contradictory pair escalates the **whole** cohort (never a subset, never "the compatible ones"), with the pair listed in the trace. The rule lives in one pure class, `App\Support\FactSetClassifier`, shared by the answer service and `facts:same-topic-audit`.
2. **Structurally narrow.** `selectMostRecent()` is **unedited** and runs first. The set logic is reachable only from its `ambiguous_conflict` result (same top `validity_start`, ≥ 2 distinct values). A single fact, a recency pick, an identical-value duplicate, supersession (older fact closed → one candidate), and tier precedence are untouched. Group facts still win outright over convenio-wide facts; nothing composes across tiers; an indeterminate node still escalates without falling through.
3. **Answering a set.** Ordered deterministically — most distinct `(unit, figure)` pairs in `value` first, then shorter `value`, then lower id — and capped at **3** (classification runs over *all* members first; a contradictory 4th escalates the cohort; the remainder is recorded in the trace as `facts_omitted`). Phase 1 quotes every fact verbatim with its own citation. Phase 2 hands `/synthesise` one typed `reference_fact` source per fact (verbatim `value`, `structured_reference`), checks fact-vs-prose figure conflicts **per fact**, requires every cited fact to be one of the offered facts (Check B, fail closed), and entails each cited fact against **its own** value in `/ground`. Grounding, the figure guard and the prompt are unchanged.
4. **Additive hr-ai identity.** `/synthesise` de-duplicated a null-chunk source on `(source_type, document_id)`; two facts from one document (140 and 143 are both document 50) collapsed onto one citation. `SynthesisChunk`/`ChunkInput` gain an optional `fact_id`; the key becomes `(source_type, document_id, fact_id)` and the returned citation carries `fact_id` only when set. Absent ⇒ same key, same output, no prompt-text change; a prose turn and a single-fact composition send and receive exactly what they did before.
5. **Trace (nested, cohort-only).** `trace.reference_fact.fact_set = { composition: complementary|conflict, facts_selected, facts_omitted, order_rule, pairs:[{a,b,relation,reason,shared_keys}] }`, emitted **only** when a tie cohort was evaluated (so the 22 existing golden traces are byte-identical), plus `trace.composition.fact_ids_offered/fact_ids_cited` on a composed set. It is nested because `trace.composition` already means the fact+prose merge. `reference_fact.fact_id/value` keep meaning the primary fact; `validity_selection` gains `same_validity_complementary`.
6. **The audit is a human-reviewed go-live item.** `facts:same-topic-audit` (read-only, SELECT-only) reports every (convenio, topic, scope) with ≥ 2 verified in-validity facts as `complementary | contradictory | identical_value | recency_shadowed`, with `over_cap` and `dup_flagged`. **Its `complementary` rows are read by a human** at go-live, after every ingestion batch and after every triage batch (deploy.md §6c).

## Residual risk (accepted)

A pair about the **same** quantity under **different key names** (`horas_anuales` vs `jornada_maxima_anual`) classifies as complementary and is composed: the employee sees two figures, each verbatim and each cited — visible, never silent. Mitigations: each fact was independently human-verified; the audit lists both key sets for a human look at every batch; the stricter alternative (compose only pairs a human declared) is recorded below as a possible follow-up. The keys are model-authored, but they are read **once, from rows a human already verified**, and only to decide *compose vs escalate* — never to match, upsert or overwrite. That is why this is not the alternative ADR-0034 rejected (a model-generated discriminator as an *upsert identity*, unstable across runs → silent duplication).

## Alternatives considered

- **Human-declared coexistence (compose only pairs an HR user marked `coexists`).** Zero heuristic, but `coexist()` requires a `duplicate_of_id` pair (140/143 are unflagged, so "Resolver versión" cannot reach them), it redefines `coexists` against ADR-0024, needs a staging data write, and every future pair waits on HR. Kept as a stricter follow-up, not the default.
- **The spec's key-difference rule.** Contradictory on 140/143; dead in tier 3; unsafe in tiers 1–2. Rejected.
- **HR bundles 140 + 143 into one fact (ADR-0034's intended shape).** A data pass, not code, and does not cover the class. Still open to HR (Q7): the code is for the *next* pair.
- **Collapse the set into one synthesis source / a negative `chunk_id`.** Breaks "each fact its own citation" and 1:1 markers / abuses ADR-0006's "a fact is never a chunk". Rejected.

## Consequences

- Classic and agent change on exactly one class: a same-start distinct-value cohort whose quantity keys are provably disjoint goes from *escalate* to *answer*. Everything else is byte-identical (S1 differential test against a verbatim copy of the legacy function; goldens 01–22 unchanged; staging replay).
- The pre-existing fact-vs-prose figure guard is unchanged (it is classic-wide). If it escalates one member of a set for lack of a matching chunk, that is reported, not tuned.
- Contradictory pairs created by an *edit* are invisible to "Resolver versión" and to `facts:scan-duplicates` (roadmap §7 ticket).

## References

`sprints/sprint-13d/plan.md`, `sprints/sprint-13d/spec.md` (§3/§5 amended), ADR-0021/0022 (logical key, `persist()`), ADR-0023 (reference-fact answer and composition), ADR-0024 (human-adjudicated resolution — unchanged), ADR-0028 (group node is the identity), ADR-0034 (bundling; the rejected model-label upsert identity), `hr-ai/scripts/synthesis_factset_contract_test.py`.

## Gate results (2026-09-30, staging)

Full detail in `sprints/sprint-13d/review.md`; streams in `sprints/sprint-13d/eval/results/`.

- **Blast radius:** replay of the pre-13d `answer()` against the new one over 2,760 (convenio × topic × archetype) comparisons on staging data: 42 differ, **all** in (convenio 20, jornada, convenio-wide). Goldens 01–22 byte-identical; 23–25 recorded before the change (escalation) and after (answer / contradictory escalation with the pair listed).
- **Convenio 20 / jornada:** every canonical variant that stays on the jornada topic and both colloquials (including held-out `ho-jor-04`) answer on the agent with `facts_selected=[140,143]`; every cited fact ∈ the offered set on every row; the overview cites both. `ho-jor-04` and `fr-c20-jornada-493db` flipped fail→pass. The fact-vs-prose figure guard did not fire on 143.
- **Contradiction and precedence (seeded, rolled back):** same-quantity, null-`raw_values` and indeterminate-node cases escalate; a group fact wins outright; an ungrouped employee gets the wide pair. 30/30 across both engines.
- **Regression:** agent 88/93 + 73/76 + 17/17 against 13b's 88 + 71 + 17, 0 hard; whitelist 16/16 on both engines, 0 hard.
- **Audit on staging:** 59 pairs / 102 verified in-validity facts / 45 wide; complementary 1 (140, 143), contradictory 0. The complementary row is the human-reviewed item in `deploy.md` §6c.
- **Recorded, not tuned:** one `low_confidence` escalation in seven agent runs of the overview question (both facts cited; sub-outcome unknown because the turn was not persisted).
- **Departures:** classic S3 reduced to the 8 rows that could differ or that failed at 13b, because classic costs ≈ $0.06 per composed turn (planned $0.02); the classic path shares `ReferenceFactAnswerService`, which the replay covers.
