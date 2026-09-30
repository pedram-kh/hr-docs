# Slice 13b — Spec: Planner-driven question normalization

> Save as: `hr-docs/sprints/sprint-13b/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Follow-on to Sprint 13 (ADR-0035). Small slice, one gate, one checkpoint.

---

## 1. The problem, measured

Sprint 13's fact-routing gate: canonical phrasings pass ~90%, colloquial phrasings ~18%, identically on both engines. "¿Cuántos días de permiso tengo por matrimonio?" is served from a verified fact; "¿cuántos días me dan si me caso?" escalates — for a fact the system already has, verified and in scope. Real employees phrase things the second way. This is the largest single driver of avoidable escalation the gate found.

Cause: topic detection for the fact route and the retrieval query both use the employee's literal words (anchor lexicon + embedding similarity). The model's understanding of the question never reaches the lookup.

## 2. The principle

**The model normalizes the question; the rules validate the normalization; the answer is unchanged.**

The employee's words are kept verbatim. The planner produces a second form *for the tools*: a topic from the closed vocabulary and a canonical rephrasing in the convenio's language. Tools run on that form. Every rule after that is untouched: the topic must be an approved one, the fact must be verified and in scope, prose is still grounded, salary still comes only from the table. A wrong normalization produces an escalation, never a wrong answer.

## 3. In scope

- **Planner output extended** with a structured `normalization` block: `topic_id` (from the closed `topics` table, or null), `canonical_query` (Spanish, convenio vocabulary, ≤ 25 words), `confidence`, and one-line `reason`. Native structured output, no free text.
- **Validation rule (pre-call):** `topic_id` must exist and be approved; `canonical_query` must not add figures, amounts, dates, or entitlement language absent from the original (the `GeneralLanePostCheck::scan` patterns, applied as a diff check); must not name a group/convenio/territory the employee didn't. Any violation → normalization discarded, tools run on the literal question (today's behaviour), trace records the rejection.
- **Tools consume it:** `reference_fact` uses `topic_id` (falling back to the anchor lexicon only when null); `convenio_search` / `national_law` retrieve on `canonical_query` **unioned** with the literal query — never replacing it (10b's union-never-replace rule).
- **Round-0 fast path unchanged:** obvious questions that hit the anchor lexicon are still settled deterministically before the planner, zero cost. The planner normalizes only what the fast path missed.
- **Trace:** both forms recorded (literal, canonical, topic, confidence, validation verdict) as one trace step; Historial and the trace panel render it.
- **Classic engine untouched** — golden traces stay byte-identical. This is agent-only.

## 4. Out of scope

Any change to answers, grounding, composition, verification, the salary rule, or the lane. Adding topics to the vocabulary. Multi-language questions (Basque/Catalan) — ticket if seen.

## 5. Acceptance criteria — the gate

1. **Fact-routing set, colloquial subset, agent ×3:** pass rate ≥ 75% (from 18%). Canonical subset does not regress from its current rate.
2. **All negative sets** (lane negatives, whitelist-temptation, Estatuto negatives), agent ×3: 0 hard violations, no increase in answered-when-should-escalate.
3. **Normalization negative set (new, ≥ 20 cases):** colloquial questions whose canonical form *would* add an entitlement/figure/group if the model over-reached — every one rejected by the validation rule, trace shows the rejection.
4. gold-2c and situational: no regression, agent ×3.
5. Golden traces 22/22; full suites green.
6. Cost per answer reported; expected +$0.005–0.01 (one structured field on an existing call, not a new call).

## 6. Checkpoint

**CP-1 (single):** gate results, plus ten hand-picked colloquial questions run live on staging with their traces — Pedram reads the normalization step for each and judges whether the canonical form is what an HR person would have said.

## 7. Risks / plan-gate questions

- **R1:** will the planner over-normalize (turn "me caso" into "permiso por matrimonio, 15 días")? The validation diff check is the guard; the plan proposes its exact patterns and tests.
- **R2:** null-topic questions — when the model can't map to an approved topic, the plan shows the fall-through is exactly today's path.
- **R3:** union vs replace in retrieval — the plan shows the union keeps the literal query's hits and the Check A threshold behaves.
- **R4:** the fact-routing set's colloquial subset must be labelled as such — plan shows how the 18%/90% split is reproduced before the change so the gate compares like with like.
