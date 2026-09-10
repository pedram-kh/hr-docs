# Sprint 8 — Cursor build-authorization prompt

> Paste into the Sprint 8 Cursor thread (the one that wrote `plan.md`). The plan is approved, including its three corrections to the kickoff's assumptions (`corpus:coverage` never existed as code — build `CorpusCoverageService`; no endpoint returns raw embeddings — add one read-only `POST /embed-batch`; no scheduler exists — build it first). All nine §12 questions are resolved below. Build on `sprint-8` in the §11 order. Feature gate: `review.md`, **stop before merge**.

---

The Sprint 8 plan (`hr-docs/sprints/sprint-08/plan.md`) is approved. Build it in the §11 order. Apply the decisions below exactly.

## Resolved open questions
1. **Real staging counts first.** At build kickoff, run §2.2's query and `select reason, sub_outcome, count(*) from escalation_cards … group by 1,2` against staging (over SSH as in every sprint), paste the results into `review.md` §0, and validate the sparse-by-construction rollup design against real sparsity before writing the rollup migration.
2. **`needs_category`: excluded from the deflection ratio, shown as its own tile** ("preguntas de salario pendientes de categoría"). Never counted as answered or escalated.
3. **Cluster threshold τ = 0.80 ships unmeasured, honestly:** log every cluster's internal min/max pairwise similarity, and **show min/max on the cluster row** so a bad merge is eyeball-able without a query. No question-pair gold set in this sprint; record "revisit with real traffic" in ADR-0030.
4. **Scheduler = step 1:** Laravel `withSchedule()` + a `scheduler` compose service running `schedule:run` every minute (mirrors the worker: same image, `restart: unless-stopped`). Nightly rollup + a manual "refresh today" action. Add it to `deploy.md` and `status.sh`.
5. **"Current year" for salary ✓ = `CURRENT_DATE`'s year**, resolved by the same logic `SalaryAnswerService` uses (read it; don't re-derive). Never a hardcoded year.
6. **No-registry-convenio rows: the registry import's source list defines "should exist."** A territory × sector with employees and no registry row is the signal; the product question is Sedena's, the row is how they see it.
7. **`analytics.view` approved as a new ability** — granted to `super_admin`, `hr_agent`, `auditor`; `knowledge_editor` sees coverage only. Server-gated via `EnsureCan`, API-matrix-tested like Sprint 5.
8. **Feedback: keep, ship last, cut-able.** Decide the chat insertion point at build; one component; `message_feedback` schema per §7.
9. **Board throughput: `resolved_at − created_at` default**, `escalation_events`-based per-state timing available as a drill-down.

## Additions
- **Byte-stable export test:** the §5.6 agreement test also runs the export twice on the same DB state and asserts an empty diff (stable ordering, no timestamps except the header date) — the ledger must not drift on ordering.
- **`usePaginatedQuery(fetcher)` hook:** do the refactor (replace the five copies before adding three); no behaviour change; the 7g pagination tests stay green.
- **`POST /embed-batch`** (hr-ai): read-only, internal-token-guarded, takes a list of strings, returns vectors from the existing BGE-M3 loader; no DB access, no migration. State its cap (e.g. 256 strings/call) and the nightly job's batching.

## Build order (§11) — with what "done" means per step
1. **Scheduler infra** → `schedule:run` container up on staging, a no-op job logs on schedule.
2. **`CorpusCoverageService`** (from `KnowledgeMap::coverageGaps()` + the ledger's reason-code vocabulary, now assigned by code) → the headcount join → the no-registry-convenio rows → `corpus:coverage` command = the export → **the agreement + byte-stable tests** → the gaps-closed snapshot table + nightly snapshot job.
3. **Rollups + `stats:*`** → the `analytics_daily` (or per-plan) rollup, the nightly job, `stats:deflection --live` reproducing the rollup (its own correctness test), the §2.1 definitions as tests.
4. **Escalations-by-fix** (the read over the 7g matrix) → backfill pre-7g cards first (`escalations:backfill-explanations`), then the grouped query + board throughput + fence outcomes.
5. **Clustering** → `/embed-batch`, the nightly cluster job (τ=0.80, medoid label, min/max logged), the topic-anchor grouping, the unanswered ranking (escalation rate × volume × headcount, sharing the coverage headcount helper).
6. **Quality sampling** → `quality_samples` migration, `quality:sample --month --n --seed` (stratified by path × territory, reproducible — test), the review screen (one decision per turn; every open logged to `conversation_access_log`), "wrong → task with fix link," the monthly trend.
7. **Screens** → the two primitives (`.kpi-tile`, the SVG bar/line chart — **added to `index.css` once**, tokens only), *Analítica* (KPI tiles + trends + the escalations-by-fix and clusters tabs via `ReviewQueuePage`'s pattern), *Cobertura* (`Hierarchy.tsx` `lens=coverage`, gap leaves open the existing `DocumentDetailPanel`, reason-code badges on existing tokens, the export button), *Calidad* (the review tab), nav entries gated per §9. `tsc -b` + `vite build` clean; light + dark checked.
8. **Feedback** (last): `message_feedback`, the chat thumbs component, the satisfaction tile.
9. **Estatuto decision:** pull the §8.1 evidence (`authority_used` national-law-only share on convenio-active topics + the 2c national-law gold tests), apply the §8.2 rule, **record the decision with the numbers** in `review.md` + ADR-0030. If triggered: re-chunk doc 73 with the 2c splitter, gold tests green, re-run the 7d anchors, report before/after.
10. **Tests** — definitions, agreement + byte-stable, stratification, access matrix, `usePaginatedQuery` parity, `Sprint7cAdditivityRegressionTest` green throughout, full suite green.

## Hard constraints (carry)
- **Read-only over the answer path**: no change to routing/retrieval/synthesis/grounding/escalation decisions; golden trace green after every step.
- **Every number reproducible** by a `stats:*`/`corpus:coverage` command; **screen == export by construction** (shared service) and byte-stable.
- **No LLM labelling/summarising** of analytics; clusters use a real question (the medoid) as the label.
- **ADR-0018 everywhere**: aggregates are free; any drill-down to a conversation goes through the existing role-gated, logged path — never a new unlogged view.
- **Design system only (ADR-0012/0013)**: existing tokens, light/dark, the named components; exactly two new primitives, in `index.css`; no third-party charting theme, no Tailwind.
- **Additive migrations; hr-ai gains only `/embed-batch` (read-only, no migration); no new model.**
- Feature gate on `sprint-8`; no direct-to-main commits (the scheduler compose change is part of the branch, deployed to staging from the branch for verification).

## Eyes-on (staging; report in review.md)
Open *Analítica*: real numbers over the test traffic — deflection, the path/authority split, the `needs_category` tile, hr_agent replies; escalations-by-fix with "asignar grupo del empleado" near the top after 7f; clusters with medoid labels and min/max. Open *Cobertura*: the full-gap convenios ranked by headcount, reason codes with links, a no-registry row if one exists; export the snapshot and diff against the last hand-run `corpus-coverage.md` (differences explained by the code-assigned reason codes, not by errors). Run `quality:sample --month=2026-09 --n=5`, review five as `hr_agent` (mark one "partially"), see the trend and the access-log rows. Thumbs-down one answer as a test employee; see the satisfaction tile. Ask a prose + a salary question → unchanged.

## Docs at close
`architecture.md` §10.5 (the analytics module; the shared coverage service; the sampling workflow; the scheduler), `data-model.md` (rollups, `coverage_snapshots`, `quality_samples`, `message_feedback`; `analytics.view`), `deploy.md` (scheduler service; the ledger is now the export; the sampling cadence; `/embed-batch`), `corpus-coverage.md` regenerated by the export, `roadmap.md` (8 DONE; the Estatuto decision), **ADR-0030 — Measurement: read-only, reproducible, embedding-clustered with a real-question label, human-sampled; the coverage map and ledger share one query; τ unmeasured-by-design until real traffic**. `sprint-08/review.md` (§0 real counts, per-step proof, the eyes-on). Then **STOP — do not merge until I review.**
