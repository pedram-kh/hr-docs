# Correction-13c-01 — the lane is a property of the question, not of the account

Status: **accepted 2026-10-05**. Decision record: ADR-0038, "Amendment 2026-10-05". Evidence: `sprints/sprint-13c/eval/results/correction-13c-01/` (`report.md` has the gate table; raw logs and rows beside it).

## What changed

The general-lane hand-over depends on two things only: the question passes the explanatory pre-screen, and the corpus did not answer. Nine account-state exclusions were removed (never ingested, expired, scope under review, no chunks, no group, and a verified-fact composition that did not answer); the ADR table lists each with `path:line` at `54fea62`. Entitlement questions in those states keep today's escalation reason, and with the lane off the output is byte-identical. The sensitive-topic guard, the off-domain decline and "convenio answers first" are untouched. Provider errors, `same_validity_conflict` and composition `conflict` stay terminal.

Two things the request did not name, found by the gate: `ReferenceFactTool` and round 0 (`AgentChatService`) also settled the turn on a gap, and a first-turn composition `low_confidence` on `test-navarra@` ended at round 0 with no lane. Both are rows 7–9 in the ADR table.

## Gate (list-price spend ≈ $3.6)

| Stage | Result |
|---|---|
| Suite | 1588/1588 (26 new tests: one group per removed state, lane-off byte-identity, and the Calidad sampling pin); goldens unchanged |
| Forced-lane negatives, miss profile | 0 bypasses, 0 figures (43) |
| Forced-lane negatives, expired profile | 1 audit-only bypass (NEG-11), 0 figures (43) |
| Lane positives, `test-fullgap@` / expired | 12 / 15 lane answers, caveat on all |
| Whitelist | 16/16, 0 hard, 0 lane answers (before and after the composition change) |
| PIF live, 3 accounts | 3/3 lane answer with badge (after the composition change) |

## Recorded, not fixed

- **Known limit: model-knowledge answers are shape-checked, not truth-checked.** `test-navarra@` expanded PIF wrongly ("Protección Integral frente a la violencia"); the other two accounts were right. Mitigation ticketed: **Slice 13f** (HR-curated glossary in Guardarraíles, injected into the lane prompt as given facts), `roadmap.md` §7.
- **NEG-11 is audit-only:** `spelled_number` on "una reducción de jornada", no digit or figure; pre-screen v2 refuses the question in production before the lane.
- **Calidad samples lane answers.** They are answered turns with `path = general_knowledge`, their own stratum, never sampled to zero. Pinned by a test; no pre-pilot ticket needed.
- **Cost of the composition hand-over:** a first-turn explanatory question whose composition abstains pays the composition's LLM calls twice. Accepted.
- **Behaviour change to know about:** with an unknown group and a topic that has a verified fact, an explanatory question now continues to `convenio_search`, so convenio prose may answer it. Entitlement questions still end at the gap.
- Gate-run side effects: the three PIF accounts have extra turns in Historial from the gate runs.

## Close (2026-10-05)

**Merged to `main` (`--no-ff`, pushed):**

| Repo | Branch commit | Merge | Deployed |
|---|---|---|---|
| hr-backend | `7d36183` | `effee6148c849557c5bd7fb406e066feb3695769` | yes |
| hr-docs | `6efbfeb` | `be2ba85a173401a690b519606d089b734e5f044e` (+ this close-out, docs-only) | yes (`be2ba85`) |
| hr-ai | — | `09503bc86602509bbb0b72f94a585c0443ca98b5` (unchanged) | yes |
| hr-frontend | — | `513733c9ac35a697a34cfacd5464a93489cc2bf4` (unchanged) | yes |

**Deploy.** Reset the dirty hr-backend checkout (the files injected for the gate), then `deploy.sh` with the four SHAs: leak scan clean, build on the box, "Nothing to migrate" (no snapshot needed first), health green on attempt 3. The deploy overwrote the box compose from the repo copy (identical apart from the OTP line); restored OTP `135790` and recreated `hr-backend`, worker and scheduler with the `vars.sh` exports. Checked: Laravel 13.16.1, `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL` and `DB_HOST` populated, no pending migrations, `/up` 200 on the box, `/api/up` and `/` 200 on the public address, lane flags and `HR_DECLINE_ENABLED` all `true`, containers healthy, the correction present in the running image.

**Verification from the deployed build `effee61`.**

| Check | Result |
|---|---|
| Goldens (`ops/golden-verify.sh`: deployed image runtime, throwaway Postgres, never the staging DB; filter `Sprint13(c(Lane)?)?GoldenTraceTest`) | **36 passed, 223 assertions**, the same as at `54fea62` |
| PIF «¿Qué es un permiso PIF?» over the real HTTP path (`close-smoke.sh`, fresh session each) | `test-fullgap@` msg 8595, `test-hosteleria-navarra@` msg 8597, `test-navarra@` msg 8599: all `outcome=answer`, `general_lane.basis=model_knowledge` (the badge), caveat verbatim |
| Whitelist spot-check, `test-gipuzkoa@`, 4 cases | wt-01 answered from the convenio-wide fact/prose (a pass in the bank); wt-09 and wt-14 `reference_fact_coverage_gap`; wt-12 matrimonio answered (20 días); no lane answer; same outcomes as the gate |
| Classic smoke (override flipped to `classic`, matrimonio question, flipped back) | classic answered (20 días naturales + parejas de hecho, cited, msg 8609, `path = reference_fact_composition`, no `agent` block); override read back as `agent` |

**Quality note from the live PIF runs.** The `test-navarra@` lane answer was wrong again, with a different wrong expansion (reproduction-assisted-treatment leave, versus «Protección Integral frente a la violencia» in the gate run); the other two accounts were right both times. That is two wrong in two on that account and none in four elsewhere. It does not change the decision (the lane is shape-checked, not truth-checked; badge, caveat, escalate button and Calidad sampling apply) and it raises Slice 13f's priority. The cause on that account was not investigated.

**Snapshot.** `hr-staging-post-correction-13c-01` (2026-10-05 00:26 UTC), taken after verification and with the engine restored. Manual snapshots of `hr-staging-db`: `post-ingest-20260906`, `post-11b`, `post-12a`, `post-13`, `post-13b`, `post-13d`, `post-13c`, `post-13e`, `pre-12b-close`, `post-12b`, **`post-correction-13c-01`**. `pre-12b-close` is kept until the demo is over (then deleted, per the 12b instruction).

**Spend.** Gate ≈ $3.6 (negatives $1.02, positives $1.63, whitelist $0.39 + $0.36, PIF $0.12 + $0.11), plus the close-out's eight live turns (≈ $0.3): **≈ $3.9**. Goldens ran on a throwaway Postgres with no model calls.
