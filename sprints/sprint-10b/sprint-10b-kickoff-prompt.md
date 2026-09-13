# Sprint 10b — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-10b/kickoff-prompt.md`

---

You are planning **Sprint 10b** in the hr-platform workspace. Read `hr-docs/sprints/sprint-10b/spec.md` first. Write `hr-docs/sprints/sprint-10b/plan.md`, then **STOP — no application code, no migrations, no tests, no commits.**

Ground rules: inspect the real code and staging DB, cite actual paths/lines; everything is additive (golden-trace regression stays green; 10a negative sets stay 0); AI proposes, deterministic hr-backend code decides.

## A. Mine the real data first (this drives everything)

1. Pull every escalation card, Analítica question cluster, and distinct real/test question on staging. Produce three tables:
   - **Situational phrasings** actually observed (or absent — say so plainly per R4) → their canonical corpus-vocabulary twin(s).
   - **Colloquial term candidates** → proposed destination (salary | reference-fact | prose | none), with the real question that motivated each. Mark every term whose destination is debatable (the "finiquito" class) — Pedram approves the full mapping table at the plan gate.
   - **Human-request phrasings** for `explicit_request` (card 9 first) → the proposed closed phrase list, plus the near-miss negatives that must NOT fire.

## B. Decomposition design (spec §2.1)

2. Show the real `/route` request/response contract (hr-backend caller + hr-ai endpoint + prompt). Can it additively return `decomposed_queries[]`? What do existing consumers do with an unknown field / an absent field? If infeasible, spec the fallback separate call with its latency cost measured on staging.
3. Show exactly where the retrieval union assembles sub-queries (the 2b-2 recall-hardening code) and how decomposed queries join it. Cite the pool-size limits and the precedence re-rank entry point; state the maximum pool growth this can cause.
4. Show the deterministic guard: the code path by which decomposed queries can reach retrieval text and *nothing else* — no scope resolve, no routing precedence, no authority. Cite lines.

## C. Lexicon + explicit_request placement

5. Show where the salary and reference-fact pre-classifiers match their current lexicons; propose the mechanism for the colloquial additions (same structure, additive entries) and the regression set (every existing gold salary/fact question).
6. Propose where the `explicit_request` pre-check sits relative to the guardrail baseline and the other pre-checks — with the precedence argument spelled out (what happens if a message is both sensitive and a human request?). Cite the 7g matrix entry for `explicit_request` and confirm the completeness guard covers a reason that now has a producer.

## D. Ops hardening (spec §2.4)

7. Cite `/ground`'s truncation-retry implementation; propose the mirrored `/synthesise` change and the budget doubling. Confirm no prompt text changes.
8. Show the `parse_error` trace write site and the two fields to add; confirm no consumer breaks on the extra fields.

## E. Evals + plan output

9. Propose all three gold sets concretely (real vs constructed labelled per R4), metrics per spec §4.2, harness location. Include the 10a positive-set no-regression run in the plan.
10. End with: ordered build steps (lexicons and explicit_request first — deterministic, cheap; decomposition last — one risk at a time; ops hardening wherever it minimizes rebase friction), invariant tests (spec §4.1 minimum), migrations if any, open questions, and ⏸ checkpoints (at minimum: Pedram approves the term→destination mapping table and the explicit_request phrase list before build).

Then **STOP** and wait for review.
