# Sprint 10a — Correction-02 (paste into the Cursor thread)

> Save as: `hr-docs/sprints/sprint-10a/correction-02.md` (no "Save as" line was given this time — inferred from the Correction-01 naming pattern; flagged back to Pedram in the closing report rather than assumed silently).
> Source: CP-4 eyes-on, step 6 (escalation board). Two findings, C2-1 (merge blocker) and C2-2 (pre-existing, fixed opportunistically). **Branch `sprint-10a`, before merge. Still no commit until this is re-reviewed.**

## Findings

- **C2-1** — `estatuto_fallback_gap` shared its display label ("Hueco en el texto del convenio") with a generic prose-gap reason on the board/filter; no way to tell them apart. Audit the full label map against the `escalation_cards.reason` enum (10 values) — `reference_fact_coverage_gap` suspected of the same gap — and add a guard test so this can't recur silently.
- **C2-2** — the card-detail modal shows no employee context (name/email/territory/category-group/seniority) for `escalation.work` viewers. Detail modal only; board list unchanged; no new ability, no new access-log surface; respect the existing design system.

## Authorized changes

1. **C2-1 (backend label map + frontend filter/Analítica surfaces).** Give `estatuto_fallback_gap` a distinct label; fix any other enum value found missing/colliding on audit; apply everywhere the reason renders (board badges, filter dropdown, History filters, Analítica row labels); add a guard test asserting every live enum value has a unique, explicit label.
2. **C2-2 (backend payload + frontend detail modal).** Add an employee block to the card-DETAIL payload only (never the list), gated by the existing `escalation.work` ability, reusing whatever access-log coverage already applies to a card-detail read (no new one). UI follows the existing design system's conventions (`section`/`h4`, `dl.kv`, `notice notice--neutral` restricted-access pattern).

## Constraints

- No new permission, no new logged-access surface (C2-2).
- Detail modal only — board list-view cards stay exactly as they are (C2-2).
- Guard test must cover the FULL live enum (introspected from the DB CHECK constraint), not a hand-copied list that could itself drift (C2-1).
- Report what the label map(s) actually contained before/while fixing.
- Re-inject to staging using the Correction-01 mechanism (backend `docker compose cp`; frontend `rsync` + `docker compose build frontend-dist` + `up -d --force-recreate frontend-dist`).

**Then STOP** — no commit, no merge, no further action beyond that.

---

## Resolution (full record in `review.md` §14)

**Label-map audit finding (the actual state, not the hypothesis):** no literal string collision for `'Hueco en el texto del convenio'` was found anywhere in the current codebase — `EscalationController::REASON_LABELS` (the backend map that drives the board badge via `reason_label`, on both `index()` and `show()`) already had `estatuto_fallback_gap` mapped to that string uniquely, confirmed both in the local working tree and on the LIVE staging container (`docker compose exec hr-backend sed -n '/REASON_LABELS/,/\];/p'`). What the audit DID find, empirically:
- `quality_sample_wrong` (added Sprint 8) had **no entry at all** in `REASON_LABELS` — it silently fell through to `?? $card->reason`, i.e. displayed the literal string `quality_sample_wrong` on the badge.
- The frontend filter dropdowns (`EscalationBoardPage.tsx`'s `REASON_FILTERS`, `HistoryPage.tsx`'s `REASONS` — two independently hand-copied arrays) were missing **three** enum values as filter options entirely: `reference_fact_coverage_gap`, `salary_not_in_chat`, `quality_sample_wrong`. This is the confirmed half of the user's `reference_fact_coverage_gap` suspicion — it had a correct backend label, but no way to filter by it on the board or in History.
- Analítica (`AnalyticsPage.tsx`, §3 "Escalaciones por corrección" and §4 clusters) rendered the **raw** `reason` string with no label lookup at all — an admin literally saw `estatuto_fallback_gap` as text there, not any label, colliding or otherwise.
- `GuardrailsPage.tsx`'s own, much smaller `REASON_LABELS` (5 entries) is a genuinely different, narrower map — it labels `GuardrailPolicy::CONVERTIBLE_REASONS_BASELINE` (4 values) plus the hardcoded `locked: ['sensitive_topic']`, never the full reason enum — confirmed correct and complete for its own scope; left untouched.

**Implemented:**
- `EscalationController::REASON_LABELS` — added `quality_sample_wrong => 'Muestra de calidad incorrecta'`; renamed `estatuto_fallback_gap` to `'Convenio vencido / sin texto vigente'` (the suggested wording, verbatim).
- New `hr-frontend/src/lib/escalationReasons.ts` — single shared `ESCALATION_REASON_LABELS`/`ESCALATION_REASON_FILTERS`/`escalationReasonLabel()`, now imported by `EscalationBoardPage.tsx`, `HistoryPage.tsx` (replacing their duplicated local arrays) and `AnalyticsPage.tsx` (replacing the raw-string render in both tables) — one place to update when the enum grows, instead of three.
- New `EscalationReasonLabelCoverageTest.php` — introspects the live Postgres CHECK constraint (same idiom the reason-enum migrations use) and asserts (a) every live enum value has an explicit `REASON_LABELS` entry, (b) no two live enum values share a label. Verified both branches actually fail before the fix (temporarily removed `quality_sample_wrong`'s entry → test (a) failed; temporarily renamed `estatuto_fallback_gap`'s label to `'Baja confianza'` → test (b) failed), then restored and confirmed green.
- `EscalationController::show()` — new `employee_context`/`employee_context_restricted` response keys, gated by `escalation.work` specifically (narrower than the pre-existing `conversation`/`conversation_restricted` gate, which also accepts `history.view_all`). New private `employeeContext()` helper reads `full_name`/`email`/`territory`/`jobCategory`/`convenioGroup`/`start_date` off the already-loaded `Employee` relation. Never called from `cardSummary()` (shared by `index()` and `show()`), so the board's list payload is provably unchanged — confirmed by a test asserting the list endpoint's cards never carry `employee_context` or `employee.email`.
- `EscalationCardDrawer.tsx` — new `EmployeeContextBlock` function component, placed as its own `<section><h4>Empleado</h4>…</section>` (mirrors `ExplanationBlock`'s existing pattern), using the existing `dl.kv` definition-list convention (same as the summary block above it) and the existing `notice notice--neutral` restricted-access copy pattern (same shape as the conversation gate's own notice) for the `escalation.work`-missing case. No new CSS, no new component pattern.
- No new access-log write: inspected `EscalationController::show()` directly — there was no dedicated access-log call on this read path to begin with (unlike `HistoryController`'s `ConversationAccessLogger`, a different, broader browsing surface), so there was nothing to reuse or risk duplicating.

**Tests added:**
- `EscalationReasonLabelCoverageTest.php` (2 tests) — coverage + no-collision, both proven to actually catch the class of bug they guard against.
- `Sprint10aCorrection02EmployeeContextTest.php` (5 tests) — `escalation.work` sees the full block; seniority is null exactly when `start_date` is unset ("where recorded"); `history.view_all`-only (auditor) sees the conversation unaffected but not the employee block; `knowledge_editor` (neither ability) sees neither; the board list endpoint never carries the block or `employee.email` on any card.

**Regression verification:** full backend suite **631/631 passed** (2,919 assertions) — 624 pre-existing + 2 + 5 new, zero unrelated failures. Frontend: `tsc -b --noEmit` clean, `vitest run` 6/6 (unchanged from Correction-01), `npm run build` clean, `eslint` on every touched file shows only the same 4 pre-existing `react-hooks` errors already present on `sprint-10a` before this correction (confirmed by running eslint against the pre-correction `git stash`), zero new lint issues.

**Not touched, by design:** the answer/chat loop, `EscalationExplainer::MATRIX` (the internal fix-guidance registry — a different map from `REASON_LABELS`, out of scope), `GuardrailsPage.tsx`'s own narrower label map (confirmed correct and complete for its scope), `escalation_events`/the "Actividad" timeline, every write path (assign/move/reply/resolve unchanged), the board's list-view card shape (`EscalationCardSummary`/`cardSummary()`), any ability/permission definition, any access-log table or write call.

**Staging re-injection (same session):** `EscalationController.php` `docker compose cp`'d into the running `hr-backend` container (md5sum-verified identical to the local file, both before and after the copy); confirmed live via `php artisan tinker` reading `REASON_LABELS['estatuto_fallback_gap']`, `REASON_LABELS['quality_sample_wrong']`, and `method_exists(..., 'employeeContext')` back from the running process. Frontend: `rsync`'d the current working tree, `docker compose build frontend-dist`, `up -d --force-recreate frontend-dist`; confirmed by fetching the live site's new hashed bundle and grepping it for `"Convenio vencido / sin texto vigente"`, `"Muestra de calidad incorrecta"`, `"Antigüedad"`, `"Categoría / grupo"` (all present) and `"Hueco en el texto del convenio"` (zero occurrences — fully gone, not just superseded). All services healthy; site returns `200`.

Still uncommitted, still on `sprint-10a`. **Stopping here per the authorization.**
