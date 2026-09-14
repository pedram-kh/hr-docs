# Sprint 10c — Build authorization (paste into the Cursor build thread)

> Save as: `hr-docs/sprints/sprint-10c/build-prompt.md`
> Plan: `hr-docs/sprints/sprint-10c/plan.md` — reviewed and **accepted**. Branch **`sprint-10c`** (hr-backend, hr-ai, hr-frontend for the queue UI, hr-docs). **STOP — no commit, no merge, until `review.md` is reviewed.** Staging batch runs use the established injection workflow; keep the injection table in review.md.

## Decisions — all resolved

| # | Decision |
|---|---|
| D1 | **Pricing/logging:** `claude-sonnet-5` at $3/$15 (verified in 10-M CP-A; no re-verification needed unless Anthropic announces a change mid-sprint). Add the one-line `prompt_tokens`/`completion_tokens` logging in `ReferenceFactProposalService` so topic 1's cost is a measurement. |
| D2 | **Preaviso disambiguation, decided now:** the topic is defined as *the employee's own resignation notice only*. Add the prompt-level instruction pre-emptively (never dismissal, shift-change, recall, or working-time-flexibility notice); the preaviso gold set fixtures the four wrong contexts as mandatory negatives. Dismissal-notice questions stay `sensitive_topic` — out of scope here. |
| D3 | **Batch ceiling:** no artificial per-topic cap. The per-topic checkpoint IS the ceiling: Pedram reviews each topic's eval + spot-checks the queue before the next topic batches (HR verification progress does NOT gate engineering cadence — facts are inert until verified). Circuit-breaker: if total `needs_review` facts exceed **300**, pause all further batches and report — that becomes a client staffing conversation, not more proposals. |
| D4 | **Untreed group facts: flag-and-persist** per plan §A.3's backstop — deterministic check in `persist()`: non-null `group_label` + no `approved` tree → force `uncertainty = {field:'group', reason:'no hay árbol de grupos aprobado…'}` if the model didn't set it. Never hard-skip. |
| D5 | **Multi-year schedules are ONE fact** (c.20 pattern): breakdown in `value`/`raw_values`, validity = source document window, rule 8 untouched (the model never proposes dates). Locked into the vacaciones/jornada gold sets. |
| D6 | **Source selection reads `retrieval_status='active'`, full stop** — 4/21's exclusion falls out of the filter; no hardcoded convenio skip list, ever. Proven by the §D.11 source-selection test. |
| D7 | **Queue changes per §C.7/C.8, presentation only:** demand tier ordered AFTER uncertainty and confidence (safety outranks demand), via the nightly `topic_demand_score` computed in the existing `questions:cluster` command; topic column in the list; topic filter wired to the existing `topic_id` param; the dead `onOpenDocument` wiring fixed. No bulk actions of any kind. |
| D8 | **Festivos and descanso** stay in the tranche with explicit go/no-go framing: deeper sample (15–20 anchor pages) at their gold-fixture step; "gate fails or near-zero yield" is a reported finding, not a failure. |

## Build order — plan §D.11 steps 1–11 as written, with:

- ⏸ **Snapshot `hr-staging-pre-10c-batch` before topic 1's batch run** (step 5). Batch writes are `needs_review` rows only and thus reversible by targeted delete, but the snapshot makes it trivially reversible.
- Steps 1+2 (dispatch-validity fix, D4 backstop) land first with their invariant tests, before any driver work.
- Step 3 (the new per-(convenio,topic) driver, topic-parameterized prompt, passage-scoped input) is the sprint's real engineering — the existing `reference_source` path stays untouched alongside it.
- ⏸ **CP-A (before topic 1's batch):** present topic 1 (permisos) gold-fixture eval results — gold size, mis-scope count (**gate: 0**), restraint cases, prompt iterations taken. Pedram/reviewer approve, then batch.
- ⏸ **CP-B (before each subsequent topic):** same presentation per topic. Topic 2 (vacaciones) does not start until topic 1's CP-B review.
- Step 6's queue-ordering + UI changes ship with topic 1's batch.
- Step 11's data-pass handoff (group-tree unlock quantification, 4/21 status, ranked verification ask) is a named deliverable file: `hr-docs/sprints/sprint-10c/data-pass-handoff.md`.

## Invariant tests — plan §D.11's four, verbatim:

dispatch-validity capture; additivity (needs_review rows for new topics change zero golden traces — asserted against the router's `status='verified'` clause directly); group-restraint backstop; source-selection active-only (historical-text convenio → zero candidate pages).

## At close

`review.md`: per-topic eval tables (gate results, iterations), per-topic×convenio yield, queue before/after, measured cost (D1's logging), injection table, and the D8 go/no-go verdicts. Docs: roadmap stub replaced; deploy.md cost note; data-pass-handoff.md. ADR only if a genuine new decision emerged (D2's topic definition may warrant a short ADR-0034 — builder's call, flag either way). **Then STOP** for review and Pedram's eyes-on (spec §6).
