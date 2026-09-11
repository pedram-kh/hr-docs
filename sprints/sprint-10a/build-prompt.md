# Sprint 10a — Build authorization (paste into the Cursor build thread)

> Save as: `hr-docs/sprints/sprint-10a/build-prompt.md`
> Plan: `hr-docs/sprints/sprint-10a/plan.md` — reviewed and **accepted with Option A**. Build exactly what is authorized below, on branch **`sprint-10a`**. **STOP — no commit, no merge, until `review.md` is reviewed.** (Exception: nothing in this sprint is deploy-driven; there is no commit-as-you-go lane.)

---

## Scope

**Sprint 10a = Estatuto fallback only** (plan §E.0 Option A). The clarifying-question turn is **deferred**; its only trace in this sprint is the falsification probe (step 9), which produces evidence for a possible Sprint 10d spec and builds nothing.

## Decisions — all resolved, none open

| # | Decision |
|---|---|
| D1 | **Option A confirmed.** Fallback only. ADR-0031 number stays reserved, unwritten. |
| D2 | **Trigger split confirmed (the central decision).** `never_ingested` → fallback fires; `expired_only` → **never** falls back, escalates with new reason `estatuto_fallback_gap` (ultraactividad, ET 86.4). ADR-0032 is written this sprint and records this split as its core. |
| D3 | **Mid-ingest guard (review addition — tightens the plan's predicate).** `never_ingested` requires **no prose document of ANY retrieval status** for the convenio AND no chunks of any status. A prose doc that exists with zero chunks (mid-ingest, pending embed) → escalate `estatuto_fallback_gap`, fail closed. Fold into `classifyProseGap()` (step 2). |
| D4 | **F2 = general guard-1 relaxation** (sentence-initial, capitalised, monotonic), NOT a special case for arts. 26/27/37 — per-article special-casing is the digit-regex failure class deleted in 7f. Hard gate: the 2c false-positive corpus must stay 100% rejected. **F1/F2 unit tests run against the actual ingested source file of staging doc 75** ("ESTATUTO TRABAJADORES julio2025") — not any other Estatuto copy; at least one local copy in circulation is a scan with no text layer. |
| D5 | **Seeding confirmed:** `test-fullgap@example.com` on **convenio 16** (HOSTELERIA Y TURISMO HUESCA); negative control reuses `test-andalucia@` (convenio 4); partial-gap control reuses `test-navarra@` (convenio 22). All seeded/test accounts go on the pre-production scrub list in `deploy.md` (same list as `DEV-FIXTURE-0001`). Do not build any eval on convenio 28. |
| D6 | **Q5 (explicit_request never emitted / card 9 misrouted)** → follow-up ticket recorded in `roadmap.md` under Sprint 10b. No code this sprint. |
| D7 | **Q6 (convenios 4 and 21 expired 2025-12-31, no successor)** → record now as a go-live data-pass item in `deploy.md`/`roadmap.md` with the fix action from plan §C.6.3 ("source the current text or confirm ultraactividad"). Pedram informs the client separately. |
| D8 | **Spec deltas recorded, docs self-heal:** append a short "Plan-gate addendum" section to `spec.md` stating: re-scoped to fallback only; R4 was wrong (`needs_category` is the existing non-terminal turn); matrix is 44 keys post-Sprint 8; eyes-on §6.1–6.2 not applicable, §6.7 added (expired-convenio negative). Also fix the "39" count where it appears in hr-docs. |

## Build steps — plan §E.1 as ordered, with the deltas above

1. Extract `hasZeroProseChunks()` from `proseCell()`; equivalence test; no behaviour change.
2. `classifyProseGap(): 'covered'|'expired_only'|'never_ingested'` — **with the D3 doc-existence check**. Pure unit, no caller.
3. Chunker fixes **F1 + F2** per D4; unit tests against the doc-75 source and the 2c false-positive corpus; **no re-embed yet**. → ⏸ **CP-1**
4. ⏸ **Snapshot first: `hr-staging-pre-10a-rechunk`** (the re-embed mutates staging data before review; make it reversible). Then re-chunk + re-embed **doc 75 only**; national-law gold tests before/after. No resize. → ⏸ **CP-2**
5. Fallback trigger + retrieval branch (national-law-only pass, skip `precedenceRerank`, `floor_decision.fallback = "estatuto_gap"`). No caveat yet.
6. Caveat via `decorate()` at the single override point (`persistTurn`, after all gates); migration **M1** (`estatuto_fallback_gap` CHECK swap, PRIOR/CURRENT pattern); `EscalationExplainer` entries + fix links for the new reason.
7. Frontend + admin trace (`MessageTrace` type, `TracePanel` fallback branch, Analítica separates the new reason).
8. Seed per D5 → ⏸ **CP-3**; run **both** eval sets (positive on `test-fullgap@`, negative: all 13 answerable questions as `test-andalucia@` must escalate `estatuto_fallback_gap` with no fallback key); record the observed Check-A top-score distribution; write `review.md`.
9. **Falsification probe** (plan §D.10.2), half-day cap: for each of the four spec parameters, count questions on this corpus where the grounded answer differs by that parameter and the loop doesn't already produce the conditional. Method + counts into `review.md`. Build nothing from the result.

## Invariant tests — `Sprint10aInvariantTest`

Plan §E.3 **T1–T10 as written**, plus:

- **T11 — A/B equivalence (review addition):** the doc-based predicate (`hasZeroProseChunks`) and the chunk-based predicate `/retrieve` sees (`convenio_id = X AND retrieval_status='active'` → zero rows) agree for every convenio in the fixture corpus. Drift between doc and chunk `retrieval_status` fails the build.
- **T12 — mid-ingest fail-closed (D3):** fixture convenio with an active prose doc and zero chunks → `classifyProseGap` ≠ `never_ingested`; a question escalates `estatuto_fallback_gap`; no `floor_decision.fallback` key.

`Sprint7cAdditivityRegressionTest` stays green throughout, extended with the partial-gap traces (plan §D.10.3).

## ⏸ Checkpoints (Pedram acts)

| ⏸ | When | What |
|---|---|---|
| CP-1 | After step 3 | Review the F1/F2 detector diff + false-positive results **before** any re-embed |
| CP-2 | Step 4 | Approve the pre-rechunk snapshot; confirm national-law gold tests pass before/after the doc-75 re-embed |
| CP-3 | Step 8 | Approve the seeded test employees |
| CP-4 | After `review.md` | Eyes-on in a real browser on staging, **chat included**: spec §6.3–§6.6 plus **§6.7** — `test-andalucia@` (expired convenio) asks a vacaciones question and must **escalate**, not fall back; admin trace shows `estatuto_fallback_gap`, no fallback key |

## Docs at close

- `hr-docs/adr/ADR-0032-estatuto-fallback.md` (trigger split + D3 guard as the central decisions; records why expired ≠ never-ingested).
- `architecture.md`: answer-loop diagram gains the fallback branch (prose path only; salary and reference-fact pre-checks untouched).
- `data-model.md`: `floor_decision.fallback` key; `escalation_cards.reason` gains `estatuto_fallback_gap`. `floor_decision.outcome` values are **unchanged** (no `clarify`).
- `deploy.md`: scrub-list additions (D5); data-pass item (D7).
- `roadmap.md`: Sprint 10d placeholder gated on the probe result; 10b ticket (D6).
- `spec.md` addendum (D8); `review.md` with both eval tables, the top-score distribution, and the probe result.

**Then STOP.** Write `review.md`, do not commit, do not merge. Review and eyes-on come first.
