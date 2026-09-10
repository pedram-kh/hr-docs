# Sprint 8 — Review

> Location: `hr-docs/sprints/sprint-08/review.md`
> Plan: [`plan.md`](plan.md) · Spec: [`sprint-08-spec.md`](sprint-08-spec.md) · Kickoff: [`sprint-08-kickoff-prompt.md`](sprint-08-kickoff-prompt.md) · Build authorization: pasted into this thread, quoted in full where it governs a decision below.
> Branch: `sprint-8` in all four repos. **Uncommitted working tree at the time of this review** (45 hr-backend, 12 hr-frontend, 9 hr-docs, 2 hr-ai changed/new files) — nothing has been committed on this branch yet; committing is the next mechanical step once the user has reviewed this document, not something this review does on its own.
> Status: **all 10 build-order steps complete.** `Sprint7cAdditivityRegressionTest` green throughout. Full backend suite green (574/574, 2616 assertions). Frontend `tsc -b` + `vite build` clean. **STOP — not merged, per the build-authorization prompt's explicit instruction. Awaiting user review.**
> **Standing blocker, stated once here and not repeated as a surprise in every section below: this sandbox has no SSH/AWS access to the real staging box.** Every number in this document that says "local" or "this sandbox" comes from a near-empty local dev Postgres (2 employees, 0 chat traffic) — not staging. Three concrete things this blocks, each flagged again at its own section: **§0**'s real staging counts (resolved question #1 — was supposed to happen at build kickoff), **§9**'s Estatuto evidence (the local DB has literally zero turns to query), and **§11**'s eyes-on checklist (cannot be walked without a real UI session against real data). This is not a new problem this sprint discovered — `plan.md`'s own header and §1.2 already flagged it before a single line of Sprint 8 code was written — but it now needs a follow-up staging session before this branch can be considered actually proven, not merely built and unit-tested.

---

## 0. Real counts — resolved question #1 (blocked; here is what could and could not be produced)

The build-authorization prompt's resolved question #1 said: *"At build kickoff, run §2.2's query and `select reason, sub_outcome, count(*) from escalation_cards … group by 1,2` against staging (over SSH as in every sprint), paste the results into `review.md` §0, and validate the sparse-by-construction rollup design against real sparsity before writing the rollup migration."* **This could not be done** — the same SSH/AWS access gap `plan.md` flagged before this sprint started is still the case in this build environment. The rollup migration (`analytics_daily_rollups`, `GROUPING SETS`-based, sparse by construction — Step 3) was written against the *design reasoning* in `plan.md` §2.4 (thin traffic, sparse by construction is right for a pre-go-live corpus), not against a real sparsity measurement. This is a real gap, not a rounding error: **the rollup's sparsity assumption is unvalidated**, and the first real staging session should run the exact query below and sanity-check that the rollup table's row count for a real day is small (as designed) rather than surprisingly dense.

**What was run instead, against this sandbox's local dev Postgres** (schema at `HEAD`, migrated through every Sprint 8 migration):

```
employees:         2
chat_sessions:      0
chat_messages:       0
message_traces:      0
escalation_cards:    0
convenios:          26
documents:          98
```

`select reason, count(*) from escalation_cards group by reason` returns **zero rows** — there are no escalation cards to group, full stop. This is not "the sparse-by-construction design is validated," it is **"there was nothing here to validate against."** The most recent *real* staging numbers on record anywhere in this repo remain `corpus-coverage.md`'s 2026-09-09 23:57 UTC run (106 documents, 27 convenios, 14 employees, 88 `reference_facts`) — corpus-side, not chat/escalation-side, and dated before this sprint's own build began. **No `message_traces`/`escalation_cards` real-staging count exists anywhere in this repository as of this review.**

**Action carried forward, not silently dropped:** before this branch is considered validated, someone with staging SSH access must run:
```sql
select reason, sub_outcome, count(*) from escalation_cards
  left join lateral (select explanation_facts->>'sub_outcome' as sub_outcome) x on true
  group by 1, 2;
```
against real staging, paste the result here, and confirm `analytics_daily_rollups`' actual row density for one real day is what §2.4's sparse design expects — a wide, dense rollup would be a real design bug this sandbox structurally cannot surface.

---

## 1. Step 1 — Scheduler infra

**Done: `schedule:run` container up (locally; not yet deployed to staging from this branch — see `deploy.md` §"Session 7" blocker note), a no-op job logs on schedule.**

- `hr-backend/bootstrap/app.php`'s `->withSchedule(...)` registers `schedule:heartbeat` (`everyMinute()`, a deliberate no-op — proves the container is alive without ever being confused with "a real job ran"), plus `stats:rollup`, `coverage:snapshot`, `questions:cluster` — all `daily()`.
- `hr-backend-scheduler` compose service added (`infra/compose/docker-compose.staging.yml`): same image as the worker (`hr-staging-hr-backend:latest`), `command: php artisan schedule:work`, `restart: unless-stopped`.
- `status.sh` updated to tail `hr-backend-scheduler`'s last few `[schedule:heartbeat]` log lines as a one-command liveness check.
- **Local proof**: `php artisan schedule:list` shows all four entries; `php artisan schedule:run` (invoked manually, since this sandbox has no long-running scheduler process) executes `schedule:heartbeat` and logs a line. **Not proven on a real always-on container** — that requires the staging deploy this sandbox cannot run.
- Full build record: `deploy.md` §"Sprint 8, Step 1".

## 2. Step 2 — `CorpusCoverageService`

**Done: the headcount join, the no-registry-convenio rows, `corpus:coverage` (the export), the agreement + byte-stable tests, the gaps-closed snapshot table + nightly job — all built and green locally.**

- `App\Support\CorpusCoverageService`: `grid()` (per-convenio prose/salary/facts/rulings ✓/✗ + reason code + headcount, reusing `KnowledgeMap::PROSE_TYPE_CODES`), `noRegistryConvenioRows()`, `toMarkdown()`.
- Reason codes as class constants: `SCAN_NO_TEXT`, `UNDER_REVIEW_SCOPE`, `EXPIRED_NO_SUCCESSOR`, `SALARY_PDF_NOT_IMPORTED`, `FACT_NEEDS_REVIEW`, falling back to `coverage_gap_unclassified` — deliberately distinct UPPER_SNAKE casing from the pre-existing lower_snake `GapKind` values, since they come verbatim from the constant names, not the router's vocabulary.
- `corpus:coverage {--out=} {--print}` artisan command — the same `grid()`/`toMarkdown()` call the admin screen's export button makes.
- `coverage_snapshots` migration + `coverage:snapshot` nightly command; the gaps-closed trend is a self-join on this table (no separate trend table).
- **Tests, run locally, all green**: `CorpusCoverageAgreementTest` (the grid, called twice against the same DB state, is byte-identical — proving "screen == export by construction," not by convention) and the byte-stable export test (the same export run twice produces an empty diff — stable ordering, no timestamp drift except the header date).
- **`corpus-coverage.md` itself was NOT regenerated against real data this sprint** — see the note added at the top of that file: this sandbox's local DB (26 convenios, near-zero employees) is not staging, and overwriting the real, staging-sourced historical ledger with sandbox noise would destroy real information. The command works; running it for real is a staging follow-up.

## 3. Step 3 — Rollups + `stats:*`

**Done: `analytics_daily_rollups`, `stats:rollup`, `stats:deflection` (`--live` reproducing the rollup), the §2.1 definitions as tests.**

- `analytics_daily_rollups` migration: sparse by construction via Postgres `GROUPING SETS`, nullable territory/sector/convenio/path/authority columns, unique per actual combination.
- `stats:rollup [--date=] [--from= --to=]` — idempotent per date (delete-then-insert).
- `stats:deflection` — reads the rollup by default; `--live` re-runs the raw §2.2 per-turn query directly, which is the rollup's own correctness test (both paths asserted identical on a fixture in the definitions test).
- **Resolved decisions applied exactly**: `needs_category` excluded from the deflection ratio, its own tile (resolved question #2); `DeflectionAnalytics`'s "current year" read follows `SalaryAnswerService`'s own year logic, never re-derived or hardcoded (resolved question #5).
- **Test**: definitions test fixture asserts `stats:deflection --live` and the rollup-backed read return identical numbers for a fixed period.

## 4. Step 4 — Escalations-by-fix

**Done: pre-7g backfill, the grouped query, board throughput, fence outcomes.**

- `escalations:backfill-explanations` run first (idempotent, already existed from 7g — this sprint's job was to actually run it, not build it). Local DB had 0 pre-existing cards to backfill (consistent with §0's zero-traffic finding), so the "before → after" count this step would normally report is **0 → 0** here — a real proof of this step's value requires staging, where 7g-era cards actually exist.
- `EscalationFixAnalytics`: grouped by `reason`/`explanation_facts->>'sub_outcome'`/`fix_action`/`fix_surface`/`fix_link` (literally §3.1's worked SQL from `plan.md`), board throughput (`resolved_at − created_at` default per resolved question #9, `escalation_events`-based per-state drill-down available), fence outcomes read from `escalation_events.detail` (`SemanticComparison::toAudit()`'s existing shape — no new column).

## 5. Step 5 — Clustering

**Done: `/embed-batch`, the nightly cluster job (τ=0.80, medoid label, min/max logged), topic-anchor grouping, the unanswered ranking.**

- hr-ai `POST /embed-batch`: read-only, internal-token-guarded, ≤256 strings/call, wraps the existing `embed_texts()` — no DB access, no migration, no new model.
- `QuestionClusteringService::run()`: greedy single-link clustering, cosine = dot product (unit-normalized vectors), τ=0.80, medoid label (the real member question with the highest mean similarity to every other member — never an LLM summary), min/max pairwise similarity logged and stored per cluster (`question_clusters.min_similarity`/`max_similarity`), `threshold_used` stamped per run for future recalibration audit.
- `topicBreakdown()` — a direct `TopicLexicon::matchTopicKeys()` call over every turn, no new vocabulary.
- `unansweredRanking()` — `escalation_rate × volume × headcount_weight`, sharing `CorpusCoverageService`'s headcount helper.
- **Tests** (`Sprint8QuestionClusteringTest`, 5/5 green, 23 assertions, a `ScriptedEmbedClient` — same hermetic-test precedent as `Sprint7dCalibrationTest`'s `ScriptedCompareClient`): near-duplicates cluster together with a real member as the medoid (never LLM text), escalation-rate/headcount-weight computed correctly, topic breakdown is a direct lexicon call, the unanswered ranking uses the persisted formula terms, and a re-run for the same `run_date` is a clean replace (not an accumulation).
- **A real measurement against τ=0.80 — done this session, using the live local BGE-M3 model (not the scripted test fixture):** the spec's own worked example pair — *"¿cuántos días de vacaciones tengo?"* vs *"vacaciones que me corresponden"* — embedded through the real model and compared directly gives **cosine similarity = 0.7916** (reproduced live while writing this review: `SentenceTransformer('BAAI/bge-m3').encode([...], normalize_embeddings=True)`, dot product = `0.79159015417099`). **This is below τ=0.80.** Two questions the spec itself uses as the canonical example of "should cluster together" would, under the current threshold and the real model, land in **separate** clusters. This is exactly the kind of evidence resolved question #3 anticipated ("τ=0.80 ships unmeasured, honestly… revisit with real traffic") — it is the first real data point against the assumption, not just an acknowledged unknown, and it points toward **0.80 being calibrated slightly too strict**, not too loose. Recorded here and in ADR-0030 §3 as the concrete number to re-check first when real traffic allows a fuller recalibration; **no threshold change was made this sprint** (that would be exactly the kind of un-measured tinkering the sprint's own "ships unmeasured, honestly" framing warns against — one data point is a flag, not a calibration).

## 6. Step 6 — Quality sampling

**Done: `quality_samples` migration, `quality:sample --month --n --seed` (stratified, reproducible), the review screen, "wrong → task with fix link," the monthly trend.**

- `quality_samples` migration + the `quality_sample_wrong` CHECK-constraint addition to `escalation_cards.reason` (the introspect-drop-readd idiom, reused a fifth time).
- `quality:sample --month=YYYY-MM --n=N [--seed=]`: proportional-with-floor stratified draw by `(path, territory)`, seed defaults to `crc32($month)`. **A re-run preserves already-reviewed rows**, replacing only unreviewed ones — the one table in this sprint that is not a clean delete-then-insert rebuild.
- Review screen (`QualitySampleQueue.tsx` + `QualitySampleDrawer`): one turn at a time via `ConversationPresenter`, three verdict buttons (correct/partially/wrong), a failure-kind select shown only when verdict ≠ correct, a note field. Opening a sample logs to `conversation_access_log` with a `quality_sample:<uuid>` context marker (ADR-0018 extended to a third call site, per §9's own instruction).
- A `wrong` verdict opens a fix task through the **existing** `EscalationExplainer` machinery (reason `quality_sample_wrong`, five new sub-outcome entries mapped 1:1 to `failure_kind`) — no parallel task-creation path.
- **Test proof**: reproducibility (same `(month, seed)` ⇒ same `message_id` set), per-stratum floor respected on a fixture with a deliberately rare stratum, re-sample-preserves-reviewed-rows.

## 7. Step 7 — Screens

**Done: `.kpi-tile`/SVG bar-line chart primitives added to `index.css` once, Analítica, Cobertura, Calidad, nav gating. `tsc -b` + `vite build` both clean (re-confirmed this session — see below).**

- Two primitives, tokens-only, following `Hierarchy.tsx`'s `GraphForm` "absolute-position + one SVG overlay" precedent — no charting library, no Tailwind: `KpiTile`, `BarChart`, `LineChart` in `charts.tsx`.
- *Analítica*: KPI tiles (deflection, answered, escalated, `needs_category`, hr_agent replies, satisfaction — the last added in Step 8), path/authority split bars, escalations-by-fix table, clusters table (medoid + min/max shown per row), topic breakdown, unanswered ranking list.
- *Cobertura*: `Hierarchy.tsx` `lens="coverage"`, gap leaves open the existing `DocumentDetailPanel` unchanged, reason-code badges reusing existing severity tokens, an export button, a gaps-closed trend line.
- *Calidad*: the review tab (Step 6).
- Nav gated on `canViewAnalytics`/`canViewCoverage` (Step 9's ability), mirroring `AdminShell.tsx`'s existing gating pattern exactly (nav hidden **and** body re-checks the ability server-side via the endpoints, not just client-side).
- **`usePaginatedQuery(fetcher, depsKey)`** — the addition to the build order the authorization prompt required before adding three more copies of the pattern. Refactored `TaggingQueue`/`ReferenceFactsQueue`/`VocabularyQueue`/`ExpiryQueue` onto it; the existing 7g pagination tests stay green (parity, not a behaviour change). `depsKey` is a scalar (`string | number`), not a spread array — ESLint's `react-hooks` rule requires a static array literal for the underlying `useCallback`'s dependency list, so the hook's public API was designed around that constraint rather than suppressing the rule.
- **Re-verified this session** (Step 10 dry-run, ahead of the formal Step 10 write-up below): `tsc -b` clean, `vite build` clean (486.70 kB / 137.82 kB gzip main bundle, no new warnings).
- Light/dark: verified by grep — zero raw hex/rgb in every CSS block this sprint added (tokens only). **A real visual eyes-on pass (light and dark, in a browser) was not done** — no staging/browser session available from this sandbox; flagged as part of §11's blocked eyes-on checklist.

## 8. Step 8 — Feedback

**Done — shipped, not cut.** `message_feedback`, the chat thumbs component, the satisfaction tile.

- `message_feedback` migration: `message_id` unique FK, `employee_id` FK (denormalized, same pattern as `escalation_cards.employee_id`), `rating` enum up/down, `comment` nullable, no `updated_at`.
- `ChatController::feedback()`: 403 for non-employees, validates `rating`, verifies the target message is `role=assistant` **and** belongs to the caller's own session (else 404), upserts via `MessageFeedback::updateOrCreate` (a second click replaces, never duplicates).
- `DeflectionAnalytics::satisfaction()` — reuses `summarize()`'s exact territory/sector/convenio filter pattern, no second filter mechanism. Wired into `AnalyticsController::deflection()`'s response and the Analítica satisfaction tile.
- `ThumbsFeedback` component in `ChatScreen.tsx`, wired into both `AnswerBlock` and `EscalationBlock` — best-effort (a failed POST never blocks or errors the chat).
- **Tests**: `Sprint8FeedbackTest`, 7/7 passing, 18 assertions — submit up, upsert-replaces on a second click, cannot rate another employee's message (404), cannot rate a `user`-role turn (404), invalid rating rejected (422), admin blocked from the employee endpoint (403), satisfaction rate correct on the deflection endpoint (required a Spatie permission-cache reset after `assignRole()` mid-test — see the fix note below).
- **Genuinely optional, proven by construction**: `message_feedback` has no foreign key anything else reads; removing this feature would be deleting one migration, one controller method, one route, one chat-UI component — no unwind required anywhere else in the answer loop.

## 9. Step 9 — Estatuto decision

**Done. Decision: not re-chunked. Recorded as "no evidence, not done," per the plan's own zero-turns rule — not silently skipped.**

**The §8.1.1 evidence query** (national-law-only `authority_used` on turns where the asker's convenio has active, on-topic prose):

```sql
select count(*) from message_traces mt
join chat_messages am on am.id = mt.message_id and am.role = 'assistant'
where mt.trace->'floor_decision'->'authority_used' = '["national_law"]'::jsonb;
```

Run against this sandbox's local dev Postgres: **0 qualifying turns** — and more fundamentally, **0 `message_traces` rows at all** (confirmed again in §0's count table above). There is no evidence to evaluate either branch of §8.2's rule against, because there is no real chat traffic in the only database this build could reach.

**Per §8.2's own instruction, this is recorded as "no evidence, not done," explicitly** — not silently skipped, not treated as a default "no" that needed no write-up. **Decision: doc 73 (Estatuto) is untouched. No re-chunk was triggered.** Confirmed doc 73 exists in the local `documents` table (count=1) so the target of a future re-chunk, if triggered later, is real and locatable — this build simply has no evidence either way about whether it *should* be re-chunked.

**This must be re-run against real staging traffic** — the query above, plus a look at whether any turns since 2c's own gold-set precedent (`sprint-02c-rechunk/review.md`'s documented, *correct* `trabajo_a_distancia` national-law fallback — not evidence of a problem, the opposite case §8.1's own reasoning distinguishes) actually show a convenio-silenced-but-should-speak pattern — before this decision can be considered actually evidenced rather than structurally unable-to-be-evidenced. Recorded here as an explicit follow-up, the same way the plan's own §12 assumption #3 (τ=0.80) asked for a later revisit rather than a guess now.

**No re-run of the 2c gold questions (trabajo a distancia, periodo de prueba, Gipuzkoa vacaciones) was attempted this session.** Since the zero-real-turns branch of §8.2's rule was already conclusively satisfied (there is categorically no evidence to evaluate, not a small or ambiguous amount), re-running the gold set would prove only that 2c's already-documented, already-passing behaviour still holds — useful as a general regression check, but not something this specific decision needs, and doc 73's chunks are unchanged from 2c's own state, so there is no reason to expect the gold set's result to have moved. Deferred to the real staging eyes-on session, where it can be run alongside a real evidence query rather than as a synthetic local-only check.

ADR: **ADR-0030** (which also carries this decision, plus τ=0.80's own "ships unmeasured, revisit with real traffic" framing and the 0.7916 finding from §5 above).

## 10. Step 10 — Tests

**Full backend suite: 574/574 tests passing, 2,616 assertions, run in full this session** (`php artisan test`, ~184s). `Sprint7cAdditivityRegressionTest` re-confirmed green in isolation (3/3, 39 assertions) as the golden-trace check, on top of being part of the full 574. Frontend: `tsc -b` clean, `vite build` clean (re-confirmed this session, §7 above).

**Errors found and fixed during this build (all self-identified, no user correction — carried forward from earlier in this session, restated here for the permanent record):**
- `VocabularyQueue`/`ExpiryQueue` referenced an undefined `setError` after the `usePaginatedQuery` refactor removed the local error-state setter those callbacks closed over — fixed by introducing a local `actionError` state in each.
- The first version of `usePaginatedQuery` used a `deps: unknown[] = []` spread inside `useCallback`'s dependency array, which ESLint's `react-hooks` rule rejects (requires a static array literal) — fixed by redesigning the hook's public signature to take a scalar `depsKey: string | number`, updating the one caller to join its filters into one string.
- `Sprint8FeedbackTest`'s satisfaction-rate test initially got a 403 instead of 200 — stale Spatie permission cache after `assignRole()` mid-test with no request having warmed/reset it; fixed by adding the `forgetGuards()`/`forgetCachedPermissions()` reset already used elsewhere in `Sprint8AnalyticsAccessTest`.
- A pre-existing, codebase-wide ESLint `react-hooks/set-state-in-effect` warning in `AdminShell.tsx`/`DocumentDetailPanel.tsx` was investigated (via `git stash` + re-lint against the committed baseline) and confirmed to exist on `HEAD` already — **not a regression this sprint introduced**, left untouched as out of scope.

## 11. Eyes-on checklist (blocked — staging session required)

The build-authorization prompt's eyes-on checklist is a **real UI + real data** walkthrough (open Analítica and read real numbers, open Cobertura and see a real full-gap ranking, run `quality:sample --month=2026-09 --n=5` and review five as `hr_agent`, thumbs-down a real answer and see the tile move, ask a real prose + salary question and confirm unchanged). **None of this can be executed from this sandbox**: there is no staging UI reachable here, and the local dev DB's near-zero data (2 employees, 0 chat traffic — §0) means even a local walkthrough would show empty screens, which proves the *mechanism* renders without erroring but proves nothing about real numbers, real gap rankings, or real cluster quality. What was verified locally instead: every screen renders without a console/build error against an empty dataset (an empty-state smoke test, not the real eyes-on), and every backend query/command runs successfully against the schema (§1–§9's test suites). **The real eyes-on checklist is carried forward as a required follow-up**, to be run by whoever has staging access, using this document's per-step "done" criteria as the checklist rather than re-deriving one.

## 12. Docs at close

- `architecture.md` §10.5 — the analytics module, the shared coverage service, the sampling workflow, the scheduler. **Done.**
- `data-model.md` §10.5 (Group H) — `analytics_daily_rollups`, `coverage_snapshots`, `question_clusters`/`question_cluster_members`, `quality_samples`, `message_feedback`; `analytics.view` added to the ability table. **Done.**
- `deploy.md` — the scheduler service (Step 1, already recorded before this window), Session 7 (Steps 2–10 build record + the standing staging-access blocker). **Done.**
- `corpus-coverage.md` — **not regenerated against real data** (see Step 2's note); a machine-generation note and the correct regeneration command added at the top and in "How to keep this current" instead, explicitly deferring the real regen to a staging session rather than overwriting real history with sandbox noise. **Partially done, by design.**
- `roadmap.md` — Sprint 8 marked **DONE** (pending user review, not merged), the Estatuto decision recorded inline. **Done.**
- **ADR-0030** — written in full (`architecture/decisions/0030-measurement-read-only-reproducible-embedding-clustered-human-sampled.md`), covering read-only measurement, reproducibility, embedding clustering with a real-question label (+ the 0.7916 finding), the coverage-map/ledger shared query, τ unmeasured-by-design, and the new `analytics.view` ability. **Done.**
- This document. **Done.**

---

## Verdict

All 10 build-order steps are complete and internally proven (test suites green, golden trace green, builds clean). **What is NOT proven, and is flagged rather than hidden:** real staging counts (§0), a real eyes-on walkthrough (§11), and a real-traffic-backed Estatuto decision (§9) — all three blocked by the same standing SSH/AWS access gap this sandbox has had since before this sprint began. The τ=0.80 clustering threshold has its first real (if small) piece of contrary evidence (§5's 0.7916 finding) rather than being purely theoretical.

**Per the build-authorization prompt's explicit, standing instruction: STOP — this branch is not merged. Awaiting your review.**
