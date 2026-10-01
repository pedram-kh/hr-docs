# Slice 13e — review (decline off-domain questions) — CLOSED 2026-10-01

**Outcome: CP-1 passed on every hard criterion; ADR-0039 accepted; merged to `main` (`--no-ff`, WIP squashed to one commit per repo) and deployed to staging — see "Close-out" at the end.** A question that has nothing to do with work now gets a short decline with a «¿Quieres que lo revise RR. HH.?» button instead of an HR card. A decline is impossible for any reason other than `off_domain`, and that is enforced by the types, the writer and the tests, not by convention.

| | |
|---|---|
| What it does | a planner `off_domain` verdict (agent engine) or an admin `off_domain` guard row (both engines) becomes `outcome = decline`: the admin's off-domain message if set, else the fixed decline copy; no card, no escalation reason. The planner source needs the router to independently say `off_domain` at ≥ 0.90 and the workplace-word veto not to fire |
| Blast radius | only turns whose reason is already `off_domain` can reach the gate. Classic-engine router `off_domain` stays an escalate (Q2). `HR_DECLINE_ENABLED` is **off by default** in `config/hr.php` and on in the staging compose; off = byte-identical to before (golden `04_admin_blocked_topic_flag_off`) |
| hr-ai | **untouched** (planner prompt `sha256:9f0d13fb…` unchanged; Option B, no `confidence` field) |
| Migrations | none (the outcome lives in `trace.floor_decision.outcome`; the rollup column is `string(32)`) |
| What HR sees | `declined` is its own figure in Analítica (excluded from the deflection denominator), declines per day, a «Declinadas esta semana» ranking, a `declined` bucket and badge in Historial, a «Declinación» step in the trace panel |
| Cost | **≈ $6.93 of the $8 cap** (list price; gate and CP-1 $6.84 measured from the rows, including the $0.19 plan-gate probe, plus ≈ $0.09 for the two close-out smoke turns) |

## Gate

Deployed for the gate: hr-backend + hr-frontend working trees rsynced to staging (no `ai` change), planner and router as deployed, `HR_DECLINE_ENABLED=true`, engine override `agent`. Banks: `eval/MANIFEST.sha256` (`off-domain-24.json`, `borderline-10.json`; both verified before the run); the never-decline sets are 13/13c's frozen banks. Streams and logs: `eval/results/`.

### Question list sign-off
The 24 off-domain questions were printed in the chat before S1 and confirmed by Pedram unchanged. OD-01 (`what is the definition of job?`) and OD-22 (`¿Cuál es el sentido de la vida?`) were the two nearest the line; both kept.

### S1 — off-domain-24 ×2 (`--stop-on=answer_or_card`, cap $2.00): **48/48 declined, 0 cards, 0 answers, never stopped; $0.83**
All 48 granted by the planner source; the router's confirming vote was 0.95 or higher on every row (floor 0.90); the workplace-word veto fired on none (as the offline replay predicted). Median latency 4.1 s. 18 classes (tech, cooking, sport, geography, language, chit-chat, maths, philosophy, …).

### S2 — borderline-10 ×1 (reported, not gated; $0.46)
| ID | Question | Outcome | Why |
|---|---|---|---|
| BL-01 | ¿Me corresponde un coche de empresa? | escalate `low_confidence` | normal corpus route, never reached the gate |
| BL-02 | Buenas tardes | **ask** (clarifying question, no card) | not a decline — see finding 1 |
| BL-03 | ¿Puedo llevar a mi perro a la oficina? | **escalate** `planner_escalated` | planner said `off_domain`; denied by D7 (veto word «oficina»); card created |
| BL-04 | ¿La empresa me paga el parking? | escalate `salary_coverage_gap` | |
| BL-05 | ¿Puedo fumar en el descanso? | escalate `low_confidence` | |
| BL-06 | ¿Puedo tener otro trabajo por las tardes? | answer | |
| BL-07 | ¿Cómo declaro la renta si he tenido dos pagadores este año? | **decline** | planner `off_domain`, router `off_domain` 0.95, no veto word |
| BL-08 | Mi encargado me ha gritado… | escalate `planner_escalated` (`needs_human_judgement`) | |
| BL-09 | ¿Cuántos días de vacaciones se llevan en Italia? | escalate `low_confidence` | |
| BL-10 | Estoy pensando en irme a otra empresa… | escalate `planner_escalated` (`needs_human_judgement`) | |

### S3 — never-decline sets ×1 (`--stop-on=decline`): **0 declines in 82 turns; $5.15 (cap $5.6)**
| Set | Turns | Pass | Baseline | Lane answers | Hard |
|---|---:|---|---|---:|---:|
| whitelist-temptation | 16 | **16/16** | 16/16 | 0 | 0 |
| gold-2c | 4 | 3/4 | 3/4 (`2c-periodo-prueba-navarra` fails at baseline) | 0 | 0 |
| situational | 12 | 11/12 | 12/12 | 0 | 0 |
| lane negatives, profiles cov + miss (lane + model-knowledge on for the process) | 50 | **50/50** | 13c S3b | 0 | 0 |

The one regression, `c2-permisos-matrimonio` (answer ×5 at baseline → `low_confidence` once), reached `reference_fact_composition` and ended `finalize`; the planner category was empty, so the decline gate never ran. Re-run ×3 ($0.20): **answer 3/3**. Accepted as a single synthesis/grounding flake (decision below). Canary `ln-19` ended `planner_escalated` as `needs_human_judgement` / `unanswerable`, never `off_domain`.

### CP-1 (real HTTP path through caddy, `test-gipuzkoa@example.com`, persisted)
| Question | Result |
|---|---|
| ¿Cuál es la capital de Australia? (msg 8389) | `decline`, no card, fixed decline copy |
| ¿Cómo se hace una tortilla de patatas? (msg 8391) | `decline` |
| ¿Quién ganó el último mundial de fútbol? (msg 8393) | `decline` |
| ¿Puedo llevar a mi perro a la oficina? (BL-03, msg 8395) | `escalate` / `planner_escalated`, card, HR hand-over message |

Analítica after `stats:rollup --date=2026-10-01`: `declined 3`, `answered 14`, `escalated 15`, deflection 48.28 % — rollup and `--live` identical.

### Local suites
Backend **1564/1564** (baseline 1389; +175: gate truth table 121, structural, agent, guard, review-request, reporting, explainer). Goldens: 25 unchanged byte-identical; `04_admin_blocked_topic` re-recorded (decline) and `04_admin_blocked_topic_flag_off` added (the old content, byte-identical to before). Frontend `tsc -b` clean, vitest 154/154. Pint clean on touched files.

## Decisions taken at CP-1
1. **«renta» stays declined** (BL-07): a personal-tax question is not HR's. No change to the veto list.
2. **`c2-permisos-matrimonio` accepted as a flake** (3/3 on re-run). A known-flaky case is not a decline problem.
3. **Config default off.** `HR_DECLINE_ENABLED` defaults to `false` in `config/hr.php` (was `true` during the build); staging turns it on in both compose copies; `phpunit.xml` turns it on for the suite. Same shape as the general lane.

## Deviations and findings
1. **BL-02 «Buenas tardes» got an `ask`, not a decline.** Not the decline copy, but a greeting still does not get a greeting. Ticket (roadmap §7): a small deterministic greeting reply, outside this slice.
2. **D7 (workplace-word veto) is planner-only**, so an admin's own `blocked_topic` pattern declines (golden 04). By design: the admin's pattern is HR's explicit statement.
3. **`Sprint6GuardrailInvariantTest`** changed, which the plan said it would not: the old assertion runs with the flag off, a new test covers the flag-on decline.
4. **Declined ranking** groups by exact (lower-cased, trimmed) question text over 7 days, computed at read time; it does not use the nightly clusters.
5. **No `statusLabels.outcome` record**; `traceOutcomeLabel` (`Record<ChatOutcome,string>`) is the compile-time exhaustiveness guard.
6. **Explicit request + admin off-domain row** (a question that asks for a human and matches an admin `off_domain` row) falls through to today's escalate with D3 denied (the admin layer runs before `explicit_request`).
7. **Deflection-rate discontinuity at deploy**: declines used to count as escalations. The Analítica period note says so.
8. **Veto replay (offline, no spend)**: fires 0/24 off-domain, 8/10 borderline; BL-02 and BL-07 are not vetoed.
9. Gate harness: the `answer:gate` run is not persisted; the CP-1 turns above are, and remain under `test-gipuzkoa@example.com` (msgs 8389–8395).

## Tickets (roadmap §7)
- **Greeting follow-up** (from finding 1).
- **Known-flaky cases: an auto-×3 rule in `answer:gate`** (from decision 2).
- Both are written up in `roadmap.md` §7; no code in this slice.

## Close-out

**Squash and merge.** Each repo's slice work is one commit on `sprint-13e` (the build was never committed in pieces), merged to `main` with `--no-ff`, and `main` and `sprint-13e` pushed. hr-ai is untouched (`main` unchanged). `hr-frontend/count-strings*` stayed untracked and out of every commit.

| Repo | Branch commit | `main` merge commit (deployed) | Previous `main` (rollback target) |
|---|---|---|---|
| hr-backend | `e3ba1f1` | `54fea620f09e156ce1d2e476289380e943256db6` | `4b929efbc72c1d501094504b9d5d3b76115b100c` |
| hr-ai | — (no change) | `09503bc86602509bbb0b72f94a585c0443ca98b5` | same |
| hr-frontend | `5e27175` | `e3e94f603b1842b9efdaf8a44e7dc3093ca985f8` | `cf374d8592a99653cae2a1dc93c57b48f9146630` |
| hr-docs | `e9a51dd` | `a0079b7d477ca7e3ca819e5890a9f811626fbf4a` (deployed; this close-out is a later docs-only commit) | `436b2b5` (box was on `0c5f4386c6598bdf76a339576a8b74aa6af5ad6c`) |

Rollback: `deploy.sh 4b929efbc72c1d501094504b9d5d3b76115b100c 09503bc86602509bbb0b72f94a585c0443ca98b5 cf374d8592a99653cae2a1dc93c57b48f9146630 0c5f4386c6598bdf76a339576a8b74aa6af5ad6c`. No migration belongs to the slice (`deploy.sh` reported "Nothing to migrate"); a rollback to the previous build also needs nothing beyond that, because declines already written are plain `message_traces` rows the older build reads as unknown outcomes (displayed, not interpreted) — or set `HR_DECLINE_ENABLED: "false"` and recreate to stop new ones without a rollback.

**Deploy.** Pre-merge snapshot `hr-staging-pre-13e-merge` (available before anything moved on the box; the slice has no migration, taken as the user instructed) → on-box checkouts reset (`git reset --hard`, `git clean -fd` after a dry run listing only the slice's injected files and the untracked `count-strings*`; `.git` preserved) → `deploy.sh` with the four merge SHAs (leak scan clean; compose hit a transient "removal already in progress" on the worker while recreating, the script carried on and finished: `.last-good-shas` updated to the four SHAs above, nothing to migrate) → fixed OTP `135790` re-applied in the flat compose (the only diff against the repo copy) → `hr-backend`, worker and scheduler recreated with the `vars.sh` exports → health. Final: Laravel 13.16.1, PHP 8.4.26, environment staging, debug off, maintenance off, pgsql, `migrate:status --pending` none, `/up` 200 on the box and `/api/up` 200 on the public host, hr-ai healthy.

**Flags (final state).**

| Where | `HR_DECLINE_ENABLED` | `HR_DECLINE_ROUTER_CONFIRM_FLOOR` |
|---|---|---|
| `config/hr.php` default (`main`) | `false` | `0.90` |
| `phpunit.xml` (the suite only) | `true` | — |
| repo compose `hr-docs/infra/compose/docker-compose.staging.yml` (deployed hr-docs `a0079b7`) | `"true"` | not set |
| box compose `/opt/hr-staging/docker-compose.staging.yml` (diff against the repo copy: only `STAGING_FIXED_OTP_CODE: "135790"`) | `"true"` | not set |
| running `hr-backend` container (`config('hr.decline')`) | `true` | `0.9` |
| General lane / model knowledge (13c, unchanged) | `true` / `true` | |
| Engine override (`answer_engine_settings`) | `agent` | |

**Verification (from the deployed build `54fea62`; staging flags only, no per-process override).**

| Check | Result |
|---|---|
| Goldens on the deployed commit (`ops/golden-verify.sh`: deployed image runtime, throwaway Postgres and `APP_KEY`, never the staging DB; filter `Sprint13(c(Lane)?)?GoldenTraceTest`) | **36 passed, 223 assertions** (26 Sprint-13 traces including the re-recorded 04 and the new `04_flag_off`, 8 lane goldens, 2 lane behaviour tests) |
| Decline suites on the same image (`Sprint13eDecline*`, `DeclineGateTest`, `DeclineStructuralTest`) | **167 passed, 1605 assertions** |
| Live decline over the real HTTP path (`ops/close-smoke.sh`, `test-gipuzkoa@example.com`, «¿Cuál es el mejor sitio para ver las auroras boreales?», msg 8417) | `outcome=decline`, `escalated=false`, no reason, fixed decline copy; trace: `floor_decision.outcome=decline`, `path=agent_planner`, `decline.granted=true`, `source=planner`, router confirm 1.0, `gate_version=dg-1` |
| Classic smoke via override flip and back (`answer-engine:set classic --admin=admin@hr-staging.internal`, «¿Cuántos días de permiso retribuido me corresponden por matrimonio?», then `agent`) | classic answered (20 días naturales + parejas de hecho, cited, msg 8419, path `reference_fact_composition`); its trace has **no `agent` block**; DB read-back `agent` after the flip back |
| Served frontend | `assets/index-CAIh6q5-.js` contains the decline/Analítica strings (same hash as the gate build — the merge tree equals the injected tree) |

**Snapshots** (`hr-staging-db`, manual): `post-ingest-20260906`, `post-11b`, `post-12a`, `post-13`, `post-13b`, `post-13d`, `post-13c`, **`post-13e`** (2026-10-01 19:27 UTC, after verification and with the engine restored). `pre-13e-merge` deleted.

**Spend.** Gate and CP-1 ≈ $6.84 of the $8 cap (S1 $0.83, S2 $0.46, S3 $5.15, `c2` re-run $0.20, probe $0.19) plus the close-out's two live turns (≈ $0.03 for the decline, ≈ $0.06 for the classic answer): **≈ $6.93**. The golden and decline-suite runs use a throwaway Postgres and no model calls.

**Staging state.** Decline **on** (env), lane and model knowledge on, engine override `agent`. Persisted turns remain under `test-gipuzkoa@example.com` (msgs 8389–8395 at CP-1, 8417 and 8419 at close-out).
