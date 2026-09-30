# ADR-0036 — The planner may restate a colloquial question in the corpus's vocabulary, but only through a validator that proves it added nothing: a validated normalization routes, it never answers

**Status:** accepted (Sprint 13b, staged gate S1–S4 and CP-1 passed 2026-09-30 — `sprints/sprint-13b/review.md`)

## Context

Sprint 13's CP-2 measured the one weakness both engines share: a *canonical* lookup ("¿cuál es mi periodo de prueba?") passes ≈ 90 %, the same question said the way employees say it ("Llevo dos semanas, ¿cuánto tiempo estoy todavía a prueba?") ≈ 18 %. The cause is not the answer path but the route into it: `ReferenceFactRouter::detectTopic` and the retrieval query both need the corpus's own words, and a colloquial sentence has none. Fixing that with a wider lexicon is a slow, brittle list (ticketed for classic, unchanged). The agent already has a model in the loop, so the cheaper move is to let it *restate* the question — and the danger is exactly the one ADR-0035 exists to prevent: a model that rewrites a question is a model that can quietly answer a different one (add "15 días", a group, a territory, a pay angle).

## Decision

1. **`normalize_question` is a control tool on the same `/plan` call.** Offered on round 1 only, no extra model call and no envelope change. Input `{topic_id | null, canonical_query | null, confidence, reason}`; `topic_id` must come from the approved-topic list in the scope summary (`approved_topics`, with `has_verified_fact`).
2. **The planner's proposal is never trusted; `NormalizationValidationRule` (`pre_call:normalize_question`) proves it added nothing.** `NormalizationDiff` rejects a proposal that (a) is not a short noun-phrase canonical (≤ 25 words / 220 chars, no question form), (b) introduces a number, group, territory, convenio name or pay intent the literal did not contain, (c) trips the guardrail baseline or any general-lane post-check pattern on a token **it added** (a *span-based* diff: a pattern hit counts only if its matched span contains an added token — a token-set diff was shown bypassable), (d) names a topic that is unknown, not approved, not offered, or that contradicts the canonical, or (e) throws. A rejection is the **literal path, exactly as if nothing had been proposed**: no round is spent, the planner is not told, nothing is denied to it. A topic proposed below `hr.normalization.min_topic_confidence` (0.6) is dropped and the canonical alone is used. `reason` is an audit string, not a safety property: over 160 characters it is **truncated, never a rejection** (a valid rewrite was lost to it at S2). Validator version `nd-1`.

   Two narrowings were decided after the dev-bank gate (S1), each with tests, the 28 over-reach negatives still rejecting 28/28: **(A1)** with a *validated, effective* (confidence ≥ 0.6) `permisos retribuidos` topic, `retribuci*` is not an added pay term (paid leave, not pay) — `plus`, `complemento`, `salario`, `sueldo`, `nómina`, `trienio` still reject; **(D1)** when the literal already contains a year term (`año`, `anual*`, `cada año`), `al año` / `cada año` in the canonical restates it and is not an added figure — only that span; `un día`, `por semana`, a year *number* and every other pattern (E2 included) are unchanged. **No rule was narrowed after S2** (the held-out bank is contaminated for tuning); the remaining false rejects are pinned in `normalization-positives.json`, fail in the safe direction, and are ticketed (`roadmap.md` §7) for validation on a fresh bank.
3. **A validated topic routes; it does not answer.** `ReferenceFactRouter::detectFromTopic` applies the *same* scope, period, group and job-category gates as `detectTopic` and yields a fact only when a verified in-scope one exists. **Round 1a**: after a validated normalization naming such a topic, the shell runs `reference_fact` itself — a binding route like Round 0 — for a single question on the session's first turn; a compound or follow-up question keeps the topic for the planner's own `reference_fact` call. The answer text is the verified fact (or its Phase 2 composition), unchanged.
3a. **Retrieval is union-never-replace.** A validated canonical is one extra decomposed retrieval pass; the employee's literal question stays the question for synthesis, grounding and the national-law pass, and `protectMain` guarantees the literal pass's own top-10 survives the synthesis cap. Check A on the union is monotone (never below the literal-only top score). `convenio_search`'s planner `query` no longer replaces the question (finding 7): it is one more retrieval-only rephrasing.
4. **Every rescue is traced and counted.** `trace.agent.normalization` records literal, proposed, verdict (`accepted | rejected | declined | absent`), the rejecting rules with spans, what was used, Round 1a, and each consumer's `literal_top_score`, `canonical_top_score`, `union_top_score`, `check_a_rescued`, `rescued_answer`. `answer:gate` aggregates them, lists each rescued answer, and excludes `normalization_validation` from the routing-correction counts. The trace renders in Historial.
5. **Flag and reversibility.** `hr.normalization.enabled` (default on for the agent engine; the agent engine itself stays behind `answer-engine:set`, classic the default). Off ⇒ the pre-13b agent, byte for byte (tests pin it). Classic is untouched: no shared path changed behaviour when `normalization` is null.

## Gate result (the decision was accepted on this evidence)

Agent engine, frozen `planner_prompt_version` `sha256:ae1366e9…dc4d`, list-price spend ≈ $36 of a $50 cap.

| | Result |
|---|---|
| G1 colloquial-unanchored ≥ 75 % | held-out bank ×2 **57/72 = 79.2 %** (convenio-wide 31/46 = 67.4 %, group-unbound 26/26); existing 76 ×1 **71/76 = 93.4 %** (convenio-wide 26/29). Baseline for the population: 0 % |
| G2 canonical ≥ baseline − 3 pts | 88/93 = 94.6 % (baseline ≈ 89 %); anchored-colloquial control 17/17 |
| G3 negatives | whitelist 48/48, lane negatives 150/150 (0 lane answers, 0 forbidden asks), Estatuto negative and second-negative 15/15 each — 0 hard violations, 0 must-escalate answered |
| G4 | validator rejects the 28 over-reach negatives (28/28; 56/56 injected on staging data); `GeneralLanePostCheck::audit()` over all 240 accepted canonicals: 0 hits |
| G5 | gold-2c 3/4 (the fourth fails on both engines at CP-2), situational 12/12; no per-case regression |
| CP-1 | ten live colloquial questions, Historial-readable traces; 0 accepted canonicals adding a figure, group or entitlement; the two baits (marriage, house move) answered from the verified fact, the situational one rejected to the literal path |

Recorded misses: false-reject rate 16.7 % on the first held-out pass vs ≤ 15 % (12.5 % pooled over two passes) — a **soft miss**, not tuned away. Failures that are not this decision's: two colloquial «… y que/no …» questions split by the shared `deterministicSplit`, and a convenio×topic with two verified same-validity facts, which escalates on both engines (both ticketed).

## Consequences

- The agent can now answer colloquial lookups classic cannot — bounded by (2) and the unchanged downstream gates, and reported as *rescues* so the gain is auditable, not assumed. ADR-0035 §2 carries the matching addendum.
- Cost: one more tool in the round-1 prompt (~0.5 k tokens) and, on a validated topic, no extra model call at all (Round 1a is deterministic).
- Not solved here: the classic lexicon (ticketed; classic stays 0 % on unanchored colloquial by contract and stays the config default), a window-aware diff that would let a follow-up's canonical use earlier turns (ticketed), the `salario`-phrase and E2 false rejects (ticketed, post-merge, fresh bank), the `deterministicSplit` «y que/no» over-split (shared with classic, ticketed), and the same-validity fact conflict (pre-existing, **pre-pilot**).

## Alternatives considered

- **Widen `TopicLexicon` only.** Slow and brittle; leaves the prose path's retrieval vocabulary problem; stays as the classic ticket.
- **Let the planner call `reference_fact` with a topic id.** Rejected: an unvalidated model-chosen id would route without the proof of "added nothing".
- **Replace the literal question with the canonical for synthesis.** Rejected: `/synthesise` and `/ground` would answer a sentence the employee never wrote (finding 7).
- **A separate normalization model call.** Rejected: extra latency and cost for no capability the same call does not have.

## References

`sprints/sprint-13b/plan.md`, `ADR-0035` (§2 addendum), `ADR-0033` (decomposition), `ADR-0034` (fact granularity), `sprints/sprint-13b/eval/` (frozen banks, `MANIFEST.sha256`).
