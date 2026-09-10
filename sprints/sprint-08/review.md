# Sprint 8 — Review

> Location: `hr-docs/sprints/sprint-08/review.md`
> Plan: [`plan.md`](plan.md) · Spec: [`sprint-08-spec.md`](sprint-08-spec.md) · Kickoff: [`sprint-08-kickoff-prompt.md`](sprint-08-kickoff-prompt.md) · Build authorization: pasted into this thread, quoted in full where it governs a decision below.
> Branch: `sprint-8` in all four repos, committed and pushed to `origin` (one commit per repo, 2026-09-10) and **deployed to real `hr-staging`** the same day.
> Status: **all 10 build-order steps complete, AND the staging measurement session (§0/§2/§5/§9/§13) is complete.** `Sprint7cAdditivityRegressionTest` green throughout. Full backend suite green (574/574, 2616 assertions) locally; real staging deploy's own health-check loop green. Frontend `tsc -b` + `vite build` clean (confirmed again as part of the staging image build). **STOP — not merged, per the build-authorization prompt's explicit, repeated instruction. Awaiting user review.**
> **The standing SSH/AWS access blocker named in every earlier section of this document no longer applies.** This session had real SSH (`ubuntu@52.211.251.235`, `hr-staging-ec2-key.pem`) and real AWS credentials (`arn:aws:iam::049681810267:user/cursor-dev`) to the actual `hr-staging` box and its RDS instance. Everything below that previously said "blocked," "local dev DB," or "cannot be executed from this sandbox" has been re-run for real and is updated in place, with the old local-only finding kept alongside it (clearly labelled) where it is still informative. §13 is the new top-level record of the staging session itself (deploy, snapshots, and what remains for the user to click through by hand).

---

## 0. Real counts — resolved question #1 (done, for real, on staging — 2026-09-10)

The build-authorization prompt's resolved question #1 said: *"At build kickoff, run §2.2's query and `select reason, sub_outcome, count(*) from escalation_cards … group by 1,2` against staging (over SSH as in every sprint), paste the results into `review.md` §0, and validate the sparse-by-construction rollup design against real sparsity before writing the rollup migration."* This was blocked in the build session (no SSH/AWS access from that sandbox); it is now done, against real `hr-staging` data, after the sprint-8 deploy (§13).

**Base counts** (`ubuntu@52.211.251.235`, via `entrypoint.sh` + `php artisan tinker`, real `hr_platform` Postgres):

```
employees:         14
chat_sessions:      12
chat_messages:      41   (22 user, 19 assistant)
message_traces:     19
escalation_cards:    11
convenios:          27   (26 registry + 1 DEV FIXTURE placeholder)
```

**`escalation_cards` by reason:**

| reason | count |
|---|---|
| low_confidence | 4 |
| sensitive_topic | 2 |
| off_domain | 2 |
| reference_fact_coverage_gap | 2 |
| salary_coverage_gap | 1 |

**`escalation_cards` by reason + sub_outcome** — run *after* actually executing `escalations:backfill-explanations --skip-ai` for real against these 5 pre-existing NULL rows (see below), not just queried as-is:

| reason | sub_outcome | count |
|---|---|---|
| low_confidence | *(unresolvable — see below)* | 3 |
| reference_fact_coverage_gap | *(unresolvable — see below)* | 2 |
| off_domain | router_off_domain | 2 |
| sensitive_topic | pattern_baseline | 2 |
| low_confidence | aggregation | 1 |
| salary_coverage_gap | no_table | 1 |

**A real, unanticipated finding: 5 of the 11 real cards pre-dated the 7g explanation backfill and had `explanation_facts IS NULL`.** Running `escalations:backfill-explanations --dry-run` first (to check before writing) reported exactly those 5; running it for real (`--skip-ai`, since a live LLM call was out of scope for this session) backfilled **2** of them (both `reference_fact_coverage_gap`) and left **3** (`low_confidence`, message ids 3/4/5, created 2026-09-06 — day 1 of staging) genuinely unresolvable: `could not resolve the originating trace, skipped`. Traced by hand: those 3 cards' `source_message_id` values (3, 4, 5) have no matching `message_traces` row at all — they predate this staging box's `message_traces` logging being fully wired up for escalated turns, not a bug in this sprint's backfill command (which degrades gracefully and reports exactly what it could and couldn't do, rather than guessing). This is now a permanent, small, explained gap in that one table — recorded here rather than silently left as "3 NULLs, unexplained."

**Employees per convenio** (14 employees across 11 convenios; the other 16 registry convenios + the DEV FIXTURE currently have 0 seeded employees): convenio 13 → 3, convenio 4 → 2, convenios 2/3/9/15/18/20/21/22/28 → 1 each.

**Sparse-rollup design, validated against real sparsity — with an honest caveat.** `stats:rollup --from=2026-09-06 --to=2026-09-11` produced **58** `analytics_daily_rollups` rows for the 5 days with traffic (5/0/14/24/15/0 per day) covering **19** real turns — i.e. at this very thin volume, the rollup is **not** sparser than the turn count (58 rows for 19 turns, ~3× rows-per-turn), not denser-than-feared either. The multiplier comes from `GROUPING SETS` legitimately producing both the granular (territory×sector×convenio×path×authority×outcome) row **and** the partial/total rollup rows for each date — **10** of the 58 rows are the fully-null "totals" rows (summing to the correct 19 turns), and **24** carry a real, non-null `convenio_id`. This is expected behaviour, not a design bug: the totals-row overhead is roughly flat per day regardless of volume, so it dominates at today's thin traffic and will amortize down as a proportion once real volume grows — but it means the original assumption ("sparse by construction ⇒ few rows") should be read as "few rows *relative to the full cross-product of every possible scope combination*," not "fewer rows than turns" at go-live volumes. Recorded as a real, measured finding rather than a pass/fail: the design is not broken, but its intuitive framing needed this correction.

**`stats:deflection --live` vs. the rollup-backed read — confirmed to agree exactly**, over the full real-traffic window (2026-09-06 .. 2026-09-11):

```
answered:        11
escalated:       8
needs_category:  0 (excluded from the ratio, per plan.md §2.1)
deflection_rate: 57.89%
path split:      {"salary_sql":6,"prose":10,"reference_fact":2,"reference_fact_composition":1}
authority split: {"none":11,"official_convenio":4,"national_law+official_convenio":1,"structured_reference":2,"national_law":1}
hr_agent replies (by admin_id): []
```

Both commands (`--live` re-running the raw per-turn query, and the default rollup-backed read) returned this **identically**, byte-for-byte — the rollup's own correctness proof, now demonstrated against real data for the first time, not only against the definitions-test fixture.

---

## 1. Step 1 — Scheduler infra

**Done: `schedule:run` container up locally AND on real staging — `hr-backend-scheduler-1` deployed and its heartbeat confirmed ticking every minute.**

- `hr-backend/bootstrap/app.php`'s `->withSchedule(...)` registers `schedule:heartbeat` (`everyMinute()`, a deliberate no-op — proves the container is alive without ever being confused with "a real job ran"), plus `stats:rollup`, `coverage:snapshot`, `questions:cluster` — all `daily()`.
- `hr-backend-scheduler` compose service added (`infra/compose/docker-compose.staging.yml`): same image as the worker (`hr-staging-hr-backend:latest`), `command: php artisan schedule:work`, `restart: unless-stopped`.
- `status.sh` updated to tail `hr-backend-scheduler`'s last few `[schedule:heartbeat]` log lines as a one-command liveness check.
- **Local proof**: `php artisan schedule:list` shows all four entries; `php artisan schedule:run` (invoked manually) executes `schedule:heartbeat` and logs a line.
- **Real staging proof (§13, 2026-09-10):** `hr-staging-hr-backend-scheduler-1` container came up as part of `deploy.sh`'s `docker compose up -d` and stayed `Up`. `docker logs hr-staging-hr-backend-scheduler-1 --tail 30` after a ~65s wait showed two consecutive real ticks:
  ```
  2026-09-10 15:40:00 Running ['artisan' schedule:heartbeat] ... 443.54ms DONE
  2026-09-10 15:41:00 Running ['artisan' schedule:heartbeat] ... 216.79ms DONE
  ```
  One-minute cadence confirmed on a real, always-on container — not just the manual local invocation.
- Full build record: `deploy.md` §"Sprint 8, Step 1" and §13 below.

## 2. Step 2 — `CorpusCoverageService`

**Done: the headcount join, the no-registry-convenio rows, `corpus:coverage` (the export), the agreement + byte-stable tests, the gaps-closed snapshot table + nightly job — all built and green locally.**

- `App\Support\CorpusCoverageService`: `grid()` (per-convenio prose/salary/facts/rulings ✓/✗ + reason code + headcount, reusing `KnowledgeMap::PROSE_TYPE_CODES`), `noRegistryConvenioRows()`, `toMarkdown()`.
- Reason codes as class constants: `SCAN_NO_TEXT`, `UNDER_REVIEW_SCOPE`, `EXPIRED_NO_SUCCESSOR`, `SALARY_PDF_NOT_IMPORTED`, `FACT_NEEDS_REVIEW`, falling back to `coverage_gap_unclassified` — deliberately distinct UPPER_SNAKE casing from the pre-existing lower_snake `GapKind` values, since they come verbatim from the constant names, not the router's vocabulary.
- `corpus:coverage {--out=} {--print}` artisan command — the same `grid()`/`toMarkdown()` call the admin screen's export button makes.
- `coverage_snapshots` migration + `coverage:snapshot` nightly command; the gaps-closed trend is a self-join on this table (no separate trend table).
- **Tests, run locally, all green**: `CorpusCoverageAgreementTest` (the grid, called twice against the same DB state, is byte-identical — proving "screen == export by construction," not by convention) and the byte-stable export test (the same export run twice produces an empty diff — stable ordering, no timestamp drift except the header date).
- **`corpus-coverage.md` has now been regenerated against real staging data (§13, §6 below) and the file has been replaced with the generated export** — the pre-Sprint-8 hand-run narrative is preserved verbatim at `sprints/sprint-08/corpus-coverage-pre-sprint8-archive.md` rather than deleted, since it is a real record of *why* the corpus looks the way it does, which the compact grid does not carry.

## 3. Step 3 — Rollups + `stats:*`

**Done: `analytics_daily_rollups`, `stats:rollup`, `stats:deflection` (`--live` reproducing the rollup), the §2.1 definitions as tests.**

- `analytics_daily_rollups` migration: sparse by construction via Postgres `GROUPING SETS`, nullable territory/sector/convenio/path/authority columns, unique per actual combination.
- `stats:rollup [--date=] [--from= --to=]` — idempotent per date (delete-then-insert).
- `stats:deflection` — reads the rollup by default; `--live` re-runs the raw §2.2 per-turn query directly, which is the rollup's own correctness test (both paths asserted identical on a fixture in the definitions test).
- **Resolved decisions applied exactly**: `needs_category` excluded from the deflection ratio, its own tile (resolved question #2); `DeflectionAnalytics`'s "current year" read follows `SalaryAnswerService`'s own year logic, never re-derived or hardcoded (resolved question #5).
- **Test**: definitions test fixture asserts `stats:deflection --live` and the rollup-backed read return identical numbers for a fixed period.

## 4. Step 4 — Escalations-by-fix

**Done: pre-7g backfill, the grouped query, board throughput, fence outcomes.**

- `escalations:backfill-explanations` run first (idempotent, already existed from 7g — this sprint's job was to actually run it, not build it). Local DB had 0 pre-existing cards to backfill. **Run for real against staging (§0/§13): 5 → 2 backfilled, 3 left permanently unresolvable** (pre-dated reliable `message_traces` logging — see §0's write-up). This is the real proof of this step's value that the local run couldn't provide.
- `EscalationFixAnalytics`: grouped by `reason`/`explanation_facts->>'sub_outcome'`/`fix_action`/`fix_surface`/`fix_link` (literally §3.1's worked SQL from `plan.md`), board throughput (`resolved_at − created_at` default per resolved question #9, `escalation_events`-based per-state drill-down available), fence outcomes read from `escalation_events.detail` (`SemanticComparison::toAudit()`'s existing shape — no new column).

## 5. Step 5 — Clustering

**Done: `/embed-batch`, the nightly cluster job (τ=0.80, medoid label, min/max logged), topic-anchor grouping, the unanswered ranking.**

- hr-ai `POST /embed-batch`: read-only, internal-token-guarded, ≤256 strings/call, wraps the existing `embed_texts()` — no DB access, no migration, no new model.
- `QuestionClusteringService::run()`: greedy single-link clustering, cosine = dot product (unit-normalized vectors), τ=0.80, medoid label (the real member question with the highest mean similarity to every other member — never an LLM summary), min/max pairwise similarity logged and stored per cluster (`question_clusters.min_similarity`/`max_similarity`), `threshold_used` stamped per run for future recalibration audit.
- `topicBreakdown()` — a direct `TopicLexicon::matchTopicKeys()` call over every turn, no new vocabulary.
- `unansweredRanking()` — `escalation_rate × volume × headcount_weight`, sharing `CorpusCoverageService`'s headcount helper.
- **Tests** (`Sprint8QuestionClusteringTest`, 5/5 green, 23 assertions, a `ScriptedEmbedClient` — same hermetic-test precedent as `Sprint7dCalibrationTest`'s `ScriptedCompareClient`): near-duplicates cluster together with a real member as the medoid (never LLM text), escalation-rate/headcount-weight computed correctly, topic breakdown is a direct lexicon call, the unanswered ranking uses the persisted formula terms, and a re-run for the same `run_date` is a clean replace (not an accumulation).
- **An earlier, single-pair real measurement (local BGE-M3, before staging access existed this session):** the spec's own worked example pair — *"¿cuántos días de vacaciones tengo?"* vs *"vacaciones que me corresponden"* — gave cosine similarity **0.7916**, below τ=0.80. One data point, flagged as a concern but not acted on.

- **The full 20-pair calibration — done for real against the staging `/embed-batch` endpoint, 2026-09-10.** `sprint-08/eval/question-pairs.json`: 10 true paraphrases + 10 hard non-pairs (same topic, different intent), across 5 topics (vacaciones, salario, periodo_de_prueba, permisos, jornada), embedded via `ExtractionClient::embedBatch()` against the real, running `hr-ai` container (BGE-M3, internal-token-guarded, not a scripted double):

  | # | topic | type | sim | pair |
  |---|---|---|---|---|
  | 1 | vacaciones | paraphrase | 0.9260 | ¿Cuántos días de vacaciones tengo? / ¿Cuál es mi número de días de vacaciones al año? |
  | 2 | vacaciones | paraphrase | 0.9451 | ¿Cuándo puedo coger mis vacaciones? / ¿En qué fechas puedo disfrutar de mis vacaciones? |
  | 3 | salario | paraphrase | 0.8877 | ¿Cuál es mi salario base? / ¿Cuánto gano de sueldo base? |
  | 4 | salario | paraphrase | 0.8412 | ¿Cuándo me pagan la nómina? / ¿Qué día del mes recibo mi salario? |
  | 5 | periodo_de_prueba | paraphrase | 0.8094 | ¿Cuánto dura mi periodo de prueba? / ¿Cuántos meses tengo de prueba en el contrato? |
  | 6 | periodo_de_prueba | paraphrase | 0.8883 | ¿Puedo dejar el trabajo durante el periodo de prueba? / ¿Puedo renunciar mientras estoy en periodo de prueba? |
  | 7 | permisos | paraphrase | **0.6846** | ¿Tengo permiso retribuido por matrimonio? / ¿Me dan días pagados si me caso? |
  | 8 | permisos | paraphrase | 0.8358 | ¿Cuántos días de permiso tengo por fallecimiento de un familiar? / ¿Qué días libres me corresponden si se muere un pariente? |
  | 9 | jornada | paraphrase | 0.8436 | ¿Cuál es mi jornada laboral semanal? / ¿Cuántas horas trabajo a la semana? |
  | 10 | jornada | paraphrase | 0.8131 | ¿Tengo derecho a un descanso durante mi jornada? / ¿Puedo parar a descansar mientras trabajo? |
  | 11 | vacaciones | hard_non_pair | 0.6779 | ¿Cuántos días de vacaciones tengo? / ¿Puedo vender mis vacaciones no disfrutadas? |
  | 12 | vacaciones | hard_non_pair | 0.7581 | ¿Cuándo puedo coger mis vacaciones? / ¿Qué pasa con mis vacaciones si estoy de baja médica? |
  | 13 | salario | hard_non_pair | 0.6061 | ¿Cuál es mi salario base? / ¿Me pueden descontar dinero del salario por llegar tarde? |
  | 14 | salario | hard_non_pair | 0.7404 | ¿Cuándo me pagan la nómina? / ¿Cómo pido un anticipo de nómina? |
  | 15 | periodo_de_prueba | hard_non_pair | **0.7772** | ¿Cuánto dura mi periodo de prueba? / ¿Qué pasa si me despiden durante el periodo de prueba? |
  | 16 | periodo_de_prueba | hard_non_pair | 0.7416 | ¿Puedo dejar el trabajo durante el periodo de prueba? / ¿Se me aplica el convenio durante el periodo de prueba? |
  | 17 | permisos | hard_non_pair | 0.6629 | ¿Tengo permiso retribuido por matrimonio? / ¿Cuántos días de permiso tengo por mudanza? |
  | 18 | permisos | hard_non_pair | 0.6179 | ¿Cuántos días de permiso tengo por fallecimiento de un familiar? / ¿Tengo permiso para ir al médico en horario laboral? |
  | 19 | jornada | hard_non_pair | 0.6356 | ¿Cuál es mi jornada laboral semanal? / ¿Puedo cambiar mi turno de jornada con un compañero? |
  | 20 | jornada | hard_non_pair | 0.7418 | ¿Tengo derecho a un descanso durante mi jornada? / ¿Puedo hacer horas extra en mi jornada? |

  **Paraphrase sims: min=0.6846 max=0.9451 mean=0.8475 (n=10). Non-pair sims: min=0.6061 max=0.7772 mean=0.6960 (n=10).**

  **The classes overlap** — the lowest true-paraphrase score (0.6846) is below the highest hard-non-pair score (0.7772) — so per the sprint's own decision rule, **τ stays at 0.80**, and that overlap is stated rather than hidden (table above). τ=0.80 sits entirely above the overlap band: **0 of 10 non-pairs would ever wrongly merge**, at the cost of **1 of 10 paraphrases** (pair 7, "permiso por matrimonio" phrasings) not clustering — the safer failure mode for a never-LLM-labelled, medoid-only cluster. The earlier single-pair 0.7916 finding shows the identical shape of result on an unrelated pair — two independent real measurements agreeing, not one fluke.

  **τ is now a named config value**: `config('hr.question_cluster_threshold')` (`HR_QUESTION_CLUSTER_THRESHOLD` env override, default 0.80) in `hr-backend/config/hr.php`, with the full calibration writeup as its doc comment (same precedent as `semantic_conflict_threshold`). `QuestionClusteringService::run()` and the `questions:cluster` command both read it; the old `DEFAULT_THRESHOLD` class constant is kept only as a fallback default, marked `@deprecated`. `Sprint8QuestionClusteringTest` re-run and still 5/5 green (23 assertions) after the refactor. **ADR-0030 §3 updated** to replace "τ=0.80 ships unmeasured" with this table and decision.

- **`questions:cluster` run for real against real staging question traffic** (`--from=2026-08-01 --to=2026-09-11`): **13 clusters from 16 distinct questions (22 total turns)**. Per-cluster medoid + internal min/max similarity (τ=0.80 for every row):

  | members | distinct | min sim | max sim | medoid (label) |
  |---|---|---|---|---|
  | 5 | 2 | 0.8056 | 0.8056 | ¿Cuánto dura el periodo de prueba en mi convenio? |
  | 3 | 1 | n/a (single text) | n/a | ¿cuánto gana mi categoría este año? |
  | 2 | 2 | 0.8643 | 0.8643 | Quiero denunciar un caso de acoso laboral por parte de mi jefe, ¿qué debo hacer? |
  | 2 | 2 | 0.8200 | 0.8200 | ¿Cuál es mi salario base mensual y mi salario anual según las tablas salariales vigentes? |
  | 2 | 1 | n/a | n/a | pregunta de prueba fence 7d |
  | 1 | 1 | n/a | n/a | Quiero hablar con una persona de Recursos Humanos, por favor. |
  | 1 | 1 | n/a | n/a | ¿Cuántos días de permiso tengo por matrimonio según mi convenio? |
  | 1 | 1 | n/a | n/a | ¿Cuánto gano? |
  | 1 | 1 | n/a | n/a | ¿Cuánto tiempo tienen las faltas leves, graves y muy graves para prescribir? |
  | 1 | 1 | n/a | n/a | ¿puedo hacer funciones de dos grupos profesionales? |
  | 1 | 1 | n/a | n/a | ¿cómo funcionan los contratos fijos discontinuos? |
  | 1 | 1 | n/a | n/a | ¿Cuál es la capital de Mongolia? |

  Topic breakdown (`TopicLexicon`): periodo_prueba 5, permisos 2, vacaciones 1. Top of the unanswered ranking: "¿Cuánto dura el periodo de prueba en mi convenio?" (score=9.00, escalation_rate=0.60, volume=5, headcount=3) — a real, actionable "this is the thing worth writing a doc for" signal, exactly what §4.2 step 7 is for. Also visible in real data: an off-domain test turn ("¿Cuál es la capital de Mongolia?"), an hr_agent-request turn, and an internal fence-test artifact ("pregunta de prueba fence 7d") — all correctly isolated as their own singleton clusters rather than merged into anything. 13 clusters from 22 turns is mostly singletons (9 of 13) at this thin volume — clustering has no `--n` cap (it's a full-rebuild threshold pass over every distinct question, not a sample draw), so the count simply reflects how few real questions repeat yet.

## 6. Step 6 — Quality sampling

**Done: `quality_samples` migration, `quality:sample --month --n --seed` (stratified, reproducible), the review screen, "wrong → task with fix link," the monthly trend.**

- `quality_samples` migration + the `quality_sample_wrong` CHECK-constraint addition to `escalation_cards.reason` (the introspect-drop-readd idiom, reused a fifth time).
- `quality:sample --month=YYYY-MM --n=N [--seed=]`: proportional-with-floor stratified draw by `(path, territory)`, seed defaults to `crc32($month)`. **A re-run preserves already-reviewed rows**, replacing only unreviewed ones — the one table in this sprint that is not a clean delete-then-insert rebuild.
- Review screen (`QualitySampleQueue.tsx` + `QualitySampleDrawer`): one turn at a time via `ConversationPresenter`, three verdict buttons (correct/partially/wrong), a failure-kind select shown only when verdict ≠ correct, a note field. Opening a sample logs to `conversation_access_log` with a `quality_sample:<uuid>` context marker (ADR-0018 extended to a third call site, per §9's own instruction).
- A `wrong` verdict opens a fix task through the **existing** `EscalationExplainer` machinery (reason `quality_sample_wrong`, five new sub-outcome entries mapped 1:1 to `failure_kind`) — no parallel task-creation path.
- **Test proof**: reproducibility (same `(month, seed)` ⇒ same `message_id` set), per-stratum floor respected on a fixture with a deliberately rare stratum, re-sample-preserves-reviewed-rows.
- **Real draw against real staging (§13):** `quality:sample --month=2026-09 --n=5` drew **8** of 11 answered turns across **8 strata**, not 5 — the per-stratum floor (at least 1 per non-empty `(path, territory)` stratum) took priority over the requested `--n` because there were 8 real strata for only 11 real answered turns this early. This is the design working as documented (`plan.md`'s "proportional-with-floor," not "exactly n"), surfaced here because 8-drawn-when-asked-5 is easy to misread as a bug rather than the floor rule; the eyes-on review (§11/§13) reviews these 8 real drawn turns, not a synthetic 5.

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

**Done, for real, against real staging traffic (§13). Decision unchanged: not re-chunked — but now on real evidence, not a zero-data default.**

**The §8.1.1 evidence query** (national-law-only `authority_used` on turns where the asker's convenio has active, on-topic prose), re-run against real `hr-staging` `message_traces`:

- **National-law-ONLY turns (any convenio): 1.** Trace id 18, convenio_id **28**, question *"¿Cuántos días de permiso tengo por matrimonio según mi convenio?"*.
- **Turns whose `authority_used` array contains `national_law` at all (mixed too): 2.** The above, plus trace id 8 (`["official_convenio","national_law"]` — a mixed answer, not a pure fallback).
- Of the 8 real traces that have any `authority_used` at all, only these 2 touch national law.

**§8.2's rule applied to the one qualifying candidate:** does convenio 28 have active, on-topic prose that the answer ignored in favour of the Estatuto? Checked directly — **convenio 28 has 0 active prose documents of any kind.** And convenio 28 itself is not a real convenio: `select * from convenios where id=28` returns `numero = 'DEV-FIXTURE-0001'`, `name = 'DEV FIXTURE — placeholder'`, `notes = 'Dev fixture only — replaced by the real registry import in Sprint 1.'`. The one candidate turn is a synthetic test employee asking against a placeholder convenio with zero real content — not a real employee whose real convenio's prose was silenced. This is the §8.1-reasoning's "convenio genuinely silent, national law correctly fills the gap" case (the same shape as 2c's `trabajo_a_distancia` precedent), **not** the "convenio speaks but got overridden" case that would trigger a re-chunk.

**Decision: doc 73 (Estatuto) is untouched. No re-chunk triggered.** This supersedes the local-sandbox "no evidence, not done" placeholder with a stronger result: there IS now real traffic, and even with real traffic, the specific §8.2 trigger condition (a real convenio with active on-topic prose, answered from national law anyway) has **zero** occurrences — not because the data doesn't exist, but because the one candidate that exists doesn't meet the condition. `roadmap.md` updated to reflect this (real evidence, not a zero-traffic default).

**No re-run of the 2c gold questions was performed this session** — same reasoning as before: the trigger condition is conclusively unmet by the one real candidate, and doc 73's chunks are unchanged from 2c's own state, so the gold set's already-documented pass is not expected to have moved. Available as a regression check in a future session if desired, not required for this decision.

ADR: **ADR-0030** (which also carries this decision; §3's τ language now reflects the real 20-pair calibration, §5 above).

## 10. Step 10 — Tests

**Full backend suite: 574/574 tests passing, 2,616 assertions, run in full this session** (`php artisan test`, ~184s). `Sprint7cAdditivityRegressionTest` re-confirmed green in isolation (3/3, 39 assertions) as the golden-trace check, on top of being part of the full 574. Frontend: `tsc -b` clean, `vite build` clean (re-confirmed this session, §7 above).

**Errors found and fixed during this build (all self-identified, no user correction — carried forward from earlier in this session, restated here for the permanent record):**
- `VocabularyQueue`/`ExpiryQueue` referenced an undefined `setError` after the `usePaginatedQuery` refactor removed the local error-state setter those callbacks closed over — fixed by introducing a local `actionError` state in each.
- The first version of `usePaginatedQuery` used a `deps: unknown[] = []` spread inside `useCallback`'s dependency array, which ESLint's `react-hooks` rule rejects (requires a static array literal) — fixed by redesigning the hook's public signature to take a scalar `depsKey: string | number`, updating the one caller to join its filters into one string.
- `Sprint8FeedbackTest`'s satisfaction-rate test initially got a 403 instead of 200 — stale Spatie permission cache after `assignRole()` mid-test with no request having warmed/reset it; fixed by adding the `forgetGuards()`/`forgetCachedPermissions()` reset already used elsewhere in `Sprint8AnalyticsAccessTest`.
- A pre-existing, codebase-wide ESLint `react-hooks/set-state-in-effect` warning in `AdminShell.tsx`/`DocumentDetailPanel.tsx` was investigated (via `git stash` + re-lint against the committed baseline) and confirmed to exist on `HEAD` already — **not a regression this sprint introduced**, left untouched as out of scope.

## 11. Eyes-on checklist (environment ready; clicks to be performed by the user)

The build-authorization prompt is explicit that this checklist is performed **by Pedram, in person, in the browser** — my role is to make sure the real staging environment and real data are ready for those clicks, not to perform them myself. As of this session, everything below is ready:

- **URL**: `http://52.211.251.235/` (plain HTTP, no domain yet — `deploy.md`/Caddyfile).
- **Login is OTP-based** (`MAIL_MAILER=log` on staging — no real inbox yet): request a code from the login screen for the email below, then read the 6-digit code from `hr-backend`'s log. From the EC2 (`ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@52.211.251.235`): `bash /opt/hr-staging/hr-docs/infra/compose/otp.sh <email>` does request+read+verify end-to-end for an API check, or just tail the log directly (`docker compose -f docker-compose.staging.yml exec -T hr-backend tail -n 50 storage/logs/laravel.log`) while using the real login form in the browser.
- **`hr_agent` account for the quality-sample review**: `agent@hr-staging.internal` (role confirmed: `hr_agent`, exactly the role the sample review screen expects).
- **The 5-turn quality sample has already been drawn for real** (§6/§13): `quality:sample --month=2026-09 --n=5` → 8 real turns across 8 strata, ready to open and review in *Calidad* → mark one "partially," per the instruction.
- **Test employee accounts for the thumbs-down click**: 13 exist (e.g. `test-ocio-alava@example.com`, convenio 3; `test-navarra@example.com`, convenio 22) — any one works for a thumbs-down on a real chat answer.
- **Analítica and Cobertura** now have real numbers behind them (§0's real deflection/path/authority splits, §6's real coverage grid) — no longer an empty-state smoke test.

**What was verified without the browser, as a stand-in for what the clicks will confirm:** the same numbers the screens will render were pulled directly from the same service classes via SSH (§0's `stats:deflection`, §6's `corpus:coverage`, §5's `questions:cluster`) — so Analítica/Cobertura opening and showing these exact numbers is the expected, already-evidenced outcome, not an unknown. The one thing that genuinely requires the browser and cannot be checked another way is the actual rendering (light/dark, real layout, real click flow) — that part is still pending your walkthrough. Let me know once you've clicked through, or ask me to fetch a fresh OTP code when you need one, and I'll take the post-8 snapshot (§13) right after.

## 12. Docs at close

- `architecture.md` §10.5 — the analytics module, the shared coverage service, the sampling workflow, the scheduler. **Done.**
- `data-model.md` §10.5 (Group H) — `analytics_daily_rollups`, `coverage_snapshots`, `question_clusters`/`question_cluster_members`, `quality_samples`, `message_feedback`; `analytics.view` added to the ability table. **Done.**
- `deploy.md` — the scheduler service (Step 1), Session 7 (Steps 2–10 build record). **Done.**
- `corpus-coverage.md` — **regenerated against real staging data and replaced for real** (§6/§13). The pre-Sprint-8 hand-run narrative is preserved verbatim at `sprints/sprint-08/corpus-coverage-pre-sprint8-archive.md`. **Done.**
- `roadmap.md` — Sprint 8 marked **DONE** (pending user review, not merged); the Estatuto decision line updated to the real-evidence version (§9); the τ line updated to "calibrated... classes overlap, kept at 0.80." **Done.**
- **ADR-0030** — title and §3 updated to replace "τ=0.80 ships unmeasured" with the real 20-pair table and decision (§5/§13). **Done.**
- `config/hr.php` — `question_cluster_threshold` added as a named config value, with the full calibration writeup as its doc comment. **Done.**
- This document, including this new §13. **Done.**

---

## 13. Staging measurement session — 2026-09-10 (this session's own record)

This session had real SSH (`ubuntu@52.211.251.235`, key at `~/.hr-staging/hr-staging-ec2-key.pem`) and real AWS credentials (IAM user `cursor-dev`, account `049681810267`) to the actual `hr-staging` EC2 (`i-057d55d8dbec6978f`) and RDS instance (`hr-staging-db`) — the standing blocker every earlier section of this document names no longer applied. What was done, in order:

1. **Committed and pushed `sprint-8`** to all four remotes (one commit per repo — the working tree had been uncommitted since the build session). SHAs: `hr-backend` `5886479`, `hr-ai` `d1b7d3f`, `hr-frontend` `6e561af`, `hr-docs` `1e0b94b` (hr-docs SHA is the pre-staging-session commit; a second hr-docs commit follows this session's doc updates, not yet pushed at the time of this write-up — see the final report for the exact final SHA).
2. **Snapshot `hr-staging-pre-8`** — RDS manual snapshot, taken and confirmed `available` before any deploy.
3. **`deploy.sh` with the four `sprint-8` SHAs** — ran clean: leak scan passed, images built, **all 9 migrations ran** (`coverage_snapshots`, `analytics_daily_rollups`, `question_clusters`/`question_cluster_members`, `quality_samples`, the `quality_sample_wrong` reason addition, the `analytics.view` permission seed, `message_feedback` — plus the pre-existing 7g `escalation_cards` explanation-columns migration that a fresh clone always re-applies as part of the same batch), `hr-backend-scheduler` container created, full stack came up, health-check loop went green on attempt 2/90 (~10s).
4. **`/embed-batch` 401 confirmed present**: called from inside the `hr-backend` container (hr-ai has no host port mapping — internal-network-only, exactly as designed) without the internal token → `401`.
5. **Scheduler heartbeat confirmed** ticking every minute on the real container (§1 above).
6. **§0's real counts pulled, backfill run, rollup validated, `--live` vs. rollup agreement confirmed exactly** (§0 above).
7. **20-pair τ calibration run for real against `/embed-batch`**; τ kept at 0.80 as a named config value; ADR-0030 updated (§5 above).
8. **`questions:cluster` run for real**; clusters + medoid + min/max captured (§5 above).
9. **Estatuto §8.1/§8.2 evidence re-run for real**; decision confirmed unchanged, now on real evidence (§9 above).
10. **`corpus:coverage --print` run for real, diffed against the hand-run ledger, and the export used to replace `corpus-coverage.md`** (§6/§2 above; the old ledger archived, not deleted).
11. **The 5-turn quality sample drawn for real** (8 turns across 8 strata — §6 above) and the environment prepared for the user's own eyes-on clicks (§11 above).
12. **Not yet done: the `hr-staging-post-8` snapshot.** Per the build-authorization prompt's own ordering, the post-8 snapshot comes *after* the eyes-on walkthrough (quality-sample review marks + the thumbs-down click are real data changes worth capturing in that snapshot) — so it is deliberately held until you confirm the walkthrough is done, rather than snapshotting a still-in-progress state and calling it final.

**What this session did NOT do, on purpose:** it did not merge `sprint-8` into `main` on any repo, and did not touch `hr-staging`'s previously-deployed `main`-tracking `.last-good-shas` in any way other than the one recorded deploy above (which itself is the standard, reversible `deploy.sh` mechanism — rollback is `deploy.sh $(cat /opt/hr-staging/.last-good-shas)` with the *previous* SHA set, unaffected by this session).

---

## Verdict

All 10 build-order steps are complete and internally proven (test suites green, golden trace green, builds clean) **and the staging measurement session (§13) has now proven the three things flagged as blocked in every earlier version of this document**: real staging counts (§0 — pulled, and the rollup/`--live` agreement confirmed exactly), a real-traffic-backed Estatuto decision (§9 — re-evidenced, decision unchanged), and τ's calibration (§5 — a real 20-pair set, classes overlap, τ kept at 0.80 as a named config value). The one remaining open item is the **eyes-on walkthrough's actual browser clicks** (§11) — the environment, data, and accounts are ready; the clicks themselves are yours to perform, after which the `hr-staging-post-8` snapshot closes out this session.

**Per the build-authorization prompt's explicit, standing instruction: STOP — this branch is not merged. Report; don't merge. Awaiting your review and your eyes-on walkthrough.**
