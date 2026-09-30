# Slice 13d — Spec: Multiple verified facts on one topic

> Save as: `hr-docs/sprints/sprint-13d/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Origin: Slice 13b, case ho-jor-04 → roadmap §7 pre-pilot ticket "same-validity conflict class". Small slice, one gate, one checkpoint.

---

## 1. The problem

`ReferenceFactAnswerService::selectMostRecent()` assumes one verified fact per (scope, topic). When two verified, in-scope, in-validity facts share the same validity start on the same topic, it treats them as a **value conflict** and escalates `reference_fact_coverage_gap`. Sprint 10c's bundling precedent (ADR-0034) legitimately produces this shape: convenio 20 / jornada has fact 140 (annual hours) and fact 143 (general working-time rules). Both correct, both verified, both convenio-wide — and every jornada question for that convenio escalates on both engines.

Staging today: 1 of 59 convenio×topic pairs. The shape is produced by construction whenever two general facts are filed under one topic, so it grows with every ingestion tranche.

## 2. The principle

**Two facts about different things are not a conflict.** A conflict is two facts asserting different values for the *same* thing. The route must distinguish "complementary" from "contradictory" — deterministically, never by asking a model which is right.

## 3. In scope

- **Fact selection returns a set, not one.** For a (scope, topic), all verified, in-scope, in-validity facts are candidates. Precedence within the set is unchanged (group-bound > category-bound > convenio-wide; later validity supersedes earlier for the *same* logical key).
> **AMENDED at plan acceptance (2026-09-30, ADR-0037).** The key-difference rule below cannot classify the case the slice exists for: facts 140 and 143 carry an *identical* full logical key (plan §0, Appendix A.3), the convenio-wide tier can never have different keys, and in the group tiers a `group_label` difference marks probable *versions*. The rule is replaced by **R-Q — disjoint `raw_values` quantity keys ⇒ complementary; null/list/empty `raw_values`, any shared key, or an unresolved `duplicate_of_id` flag ⇒ contradictory; one contradictory pair escalates the whole cohort.** The original text is kept below for the record. The trace names are nested as `reference_fact.fact_set` (`trace.composition` already exists). Criteria 1 and 7 are restated in §5.

- **Complementary vs contradictory, deterministic rule (ORIGINAL — superseded by R-Q above):**
  - Facts with **different logical keys** (the 7b-2 upsert key: scope + topic + group_label/category) that both survive precedence are **complementary** → compose.
  - Facts with the **same logical key** and different values and no supersession link → **contradictory** → escalate as today (`reference_fact_coverage_gap`, sub-outcome `version`), with the pair listed so HR can run `Resolver versión`.
  - Never: pick one by recency when keys differ, or merge values.
- **Composition:** complementary facts are passed to the existing `composeFactWithProse` path as an ordered set (the same composition that already merges one fact with convenio chunks). Synthesis sees each fact verbatim with its own citation; grounding unchanged. The answer cites each fact it uses.
- **Both engines, same code path** — the service is shared. Classic's behaviour changes on exactly this class (from escalate to answer); everything else is byte-identical. The golden-trace suite gains fixtures for this class; existing 22 stay identical.
- **Trace:** the fact step records `facts_selected: [ids]`, `composition: complementary|single|conflict`.
- **Diagnostic command:** `facts:same-topic-audit` — lists every (scope, topic) with ≥2 candidate facts, classified complementary/contradictory, for the data pass and for re-running after each ingestion.

## 4. Out of scope

Changing what a logical key is; merging or editing facts (HR's job via `Resolver versión` / `Fix then verify`); the general lane; any prompt change.

## 5. Acceptance criteria — the gate

1. **Convenio 20 / jornada** (facts 140 + 143) — *restated (Q3, ADR-0037):* every canonical jornada variant, and `ho-jor-04` (agent only; classic recorded, not gated — the 13b contract), **answers**; `facts_selected = [140, 143]` (both **offered**); ≥ 1 fact cited and **every cited source ∈ the offered set**; on the *overview* question **both** facts are cited (reported for the other variants — synthesis cites what it uses and the route must not fabricate a citation). Agent ×3, classic ×3 on the canonical variants. If the pre-existing fact-vs-prose figure guard escalates fact 143 for lack of a matching chunk, that is reported, not tuned (it is classic-wide).
2. **Contradiction still escalates:** a fixture with two same-key facts, different values, no supersession → `reference_fact_coverage_gap` / `version`, both engines. Never answers.
3. **Precedence unchanged:** existing group-vs-convenio-wide fixtures identical; a group fact plus a convenio-wide fact on the same topic for a grouped employee behaves exactly as today (plan states which: group wins, or both compose — cite current code).
4. Fact-routing set (all classes) ×1 on both engines: no case regresses vs 13b's CP-1 rows; ho-jor-04 and fr-c20-jornada flip fail→pass.
5. Whitelist-temptation ×1: 0 hard.
6. Golden traces: existing 22/22 byte-identical; ≥2 new fixtures for this class recorded.
7. `facts:same-topic-audit` output on staging matches the read-only query from 13b's ticket: 59 convenio×topic pairs / 102 verified in-validity facts / 45 convenio-wide; **1 complementary (20·jornada·{140,143}), 0 contradictory** — *the complementary pairs are a human-reviewed item* (deploy.md §6c).
8. Spend ≤ $15.

## 6. Checkpoint

**CP-1 (single):** gate results + the convenio 20 jornada answer live on staging, both engines — Pedram reads it and confirms the two facts read as one coherent answer, each cited.

## 7. Risks / plan-gate questions

- **R1:** the current precedence code for group + convenio-wide on the same topic — the plan must show exactly what happens today before touching it (the wt-01/wt-05 "pass-with-caveat" cases depend on it).
- **R2:** composition with 3+ facts — synthesis length and citation clutter; the plan proposes a cap or an ordering (annual figure first, rules second).
- **R3:** logical key availability on old 7b facts (the HR-table ones) — the plan checks every verified fact has one; if not, the class falls back to today's behaviour for those and says so.
