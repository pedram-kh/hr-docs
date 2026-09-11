# Sprint 10a — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-10a/kickoff-prompt.md`

---

You are planning **Sprint 10a** of the hr-platform workspace (`hr-backend`, `hr-ai`, `hr-frontend`, `hr-docs`). Read `hr-docs/sprints/sprint-10a/spec.md` first — it defines the sprint. Your job is to write `hr-docs/sprints/sprint-10a/plan.md` and then **STOP. Do not write any application code, migrations, or tests. Do not commit.**

## Ground rules

- Inspect the **real code and the staging DB**, not your memory of them. Cite actual file paths and line numbers for every integration point you name.
- Everything in this sprint is **additive**. `Sprint7cAdditivityRegressionTest` must stay green; your plan must state how each change avoids touching existing trace output.
- AI never decides (ADR-0015/0016): the clarify decision, the question text, the fallback trigger, and the caveat are all deterministic hr-backend code.

## What to inspect and report in plan.md

### A. Answer loop integration (hr-backend)

1. Locate the answer-or-escalate decision code (the Check A/B/figure-guard/ground sequence) and the escalation-reason taxonomy (7g's 39-entry `explanation_facts` matrix). List the **actual escalation reasons** and, for each, say whether a missing *question parameter* (not directory scope) is ever the cause. This produces the candidate clarification matrix — propose it as a table: `reason × parameter → question text (es)`.
2. Pull the **real escalation cards on staging** (top cluster first). For each candidate matrix entry, cite at least one real card it would have converted. If a card's missing piece is directory scope (unassigned group, unconfirmed doc scope), mark it **not clarifiable** and confirm the existing fix link is the right outcome.
3. Show where the clarified parameter would be injected on re-run (typed context, not free text) and where the echo line and the fallback caveat would be appended — these must be after synthesis, before persistence, in deterministic code.

### B. Chat/message model (hr-backend + hr-frontend)

4. Show the current message/turn model and how a non-terminal assistant turn (pending clarification) fits: state machine, expiry when the employee asks something new, trace fields (`floor_decision.clarification`, `floor_decision.fallback`). Confirm no migration is needed in hr-ai (ADR-0007) and list the hr-backend migrations you do need.
5. Frontend: which components render the chat turn; propose the clarify UI (option chips for enums, numeric input for years) within the token design system (no Tailwind).

### C. Estatuto fallback

6. Find the exact query `CorpusCoverageService` uses to classify a convenio as full-gap. Propose how the answer loop reuses it (shared service/method — a second definition is forbidden).
7. Confirm `/retrieve` in hr-ai can be restricted to `national_law` for this path without modifying the recall-hardened union used by normal prose (cite the code). If it needs a parameter, spec it.
8. **Assess Estatuto chunk quality (R3):** the Estatuto was held out of the 2c article-boundary re-chunk. Query the actual chunks on staging — count, average size, whether article boundaries survived. State plainly whether a national-law-only answer path can work on today's chunks or whether re-chunking the Estatuto is a prerequisite step of this sprint (and if so, plan it).
9. List which full-gap-convenio test employees exist on staging (or which need seeding) for the eval and eyes-on.

### D. Evals

10. Propose both gold sets concretely: for clarify, the specific staging cards; for fallback, 10–15 Estatuto-answerable questions with expected grounded answers. State the metrics from spec §2.3 and where the harness lives (`hr-docs/sprints/sprint-10a/eval/`).

### E. Plan output

11. `plan.md` ends with: ordered build steps (foundation first, one risk at a time), the migrations list, the invariant tests you'll write (spec §4.1 minimum), open questions for the reviewer, and ⏸ checkpoints where Pedram must act (e.g., resize for any re-chunk/embedding run).

Then **STOP** and wait for review.
