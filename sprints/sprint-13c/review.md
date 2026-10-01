# Slice 13c — review (the general-knowledge lane, made real) — CLOSED 2026-10-01

Spec `sprint-13c-spec.md`, plan `plan.md` (accepted 2026-09-30), decisions Q1–Q12 on plan §9.6, rule on existence claims and the abstention flag in **ADR-0038**. Raw per-stage evidence is under `eval/results/` (one report per stage, rows as `*-rows.jsonl`, gate logs as `*-gate.log`). Gate banks are frozen (`eval/MANIFEST.sha256`, `eval/FREEZE-NOTE.md`); `answer:gate` refuses an edited bank.

## 0. What shipped

A real lane for concept questions the corpus cannot answer, behind two flags (`HR_GENERAL_LANE_ENABLED` + new sub-flag `HR_GENERAL_LANE_MODEL_KNOWLEDGE`, both default **off** in `config/hr.php`; the Guardarraíles toggles are restrict-only):

- **Opening.** `GeneralLaneAvailabilityRule` → `general_knowledge` tool. The lane opens on `check_a_miss`, `entailment_only` and (sub-flag on) `synthesis_abstention`. Abstention is recognised from a **structured `abstained` flag** returned by `/synthesise` (opt-in `report_abstention`), with the anchored opening phrase as fallback only (ADR-0038, §6 below).
- **Pre-screen v2** (`questionAdmitsLane`): fail-closed shape allow-list plus first-person / obligation / `figure_concept` denies. Offline fixtures (`prescreen-fixtures.json`, 34 entitlement / 32 explanatory, 13 minimal pairs, held-out half): entitlement recall ≥ 95 % asserted by `Sprint13cPrescreenFixturesTest` (v1 was 22/34 = 65 %, `plan.md:209`); false-explanatory (explanatory refused) 3/32 = 9.4 %, reported not gated. End to end it refused 43/43 of the S2 negatives.
- **Sources.** Web-grounded first where a catalogue page yields an excerpt; otherwise (and as a **web → model-knowledge fallback** for an empty or ungrounded web draft) model knowledge: a ≈ 90-word, one-paragraph concept definition that never affirms or denies a right, permit, payment or obligation. Frozen prompt `68893dba…57a59af` (hr-ai `GENERAL_KNOWLEDGE_MODEL_SYSTEM_PROMPT`; web prompt `e9e2c6a1…` untouched). The prompt hash is recorded in the trace with `basis` (`web` | `model_knowledge`).
- **Locks, in order.** `GeneralLanePostCheck` (F1–F3, D1, A1, E1–E3, X1; E2 widened with the third-person general-entitlement shape and `un derecho` / `derecho preferente`), then `ModelKnowledgeShapeCheck` (S1 no citation, S2 ≤ 120 words, S3 pointer to convenio / HR) via `ModelKnowledgeShapePostCallRule`. A hit discards the draft and escalates `general_lane_blocked` with `trace.agent.general_lane_blocked.sub` (`entitlement_language` | `figure` | `shape` | `question_prescreen`; new MATRIX key `general_lane_blocked.shape`, 70 → 71).
- **Presentation.** Persisted answer = draft + `ChatService::GENERAL_LANE_MODEL_CAVEAT` (`TurnPersister::decorate`); trace `authority: general_knowledge`; frontend chip/trace row for the model-knowledge basis, Guardarraíles toggles and a catalogue card; the escalate button is unchanged.
- **Catalogue.** Admin-managed lane pages (table, service, endpoints, seed) in Guardarraíles.
- **Gate tooling.** `answer:gate` row carries the full `answer`, `outcome_class` (`abstain` | raw outcome), `abstained_by`, `lane_basis`, `caveat_ok`, `lane_words`; hard checks `lane_answer_without_declared_basis` and `lane_answer_without_caveat` (basis-specific).
- **Lane off is byte-identical.** The 25 Sprint-13 goldens are untouched; +6 lane-on goldens (26, 27, 30, 31, 28, 29) and +2 web-fallback goldens (32, 33). 28 and 29 were **re-recorded** and disclosed (the trace gained `general_lane_blocked.sub`; 29's card now names the real block).

## 1. Gate results (plan §8, spec §5)

Spend figures are the gate's list-price tally (claude-sonnet-5 $3 / $15 per MTok).

| Stage | What | Result | $ |
|---|---|---|---:|
| S0 / S0b | V1 + V2 verification of the 52 + 24 candidate pool on `cov`, freeze | pool 1: 18 survived (< 20 → stopped and asked, Q6); second-tier pool added after approval; **33 survivors** frozen; gate set of 20 by the stratified rule (7 non-abstention + 13 by `sha256`, family cap 12/20 not binding; max family 6) | 2.63 + 1.20 |
| S1 / S1b / S1c | forced-lane positives ×1 | R1 block rate 10/28 = 35.7 % → 4/33 = 12.1 % after the length prompt and the fallback → 7/33 = 21.2 % with the define-only prompt and the wider E2 (the cost of E2, below); words p50 114 → 102 → 100 | 0.30 + 0.30 (S1c is in the S2c figure) |
| S2 | forced-lane negatives ×3 (43 questions) | **Met after the audit alignment (your decision, 2026-10-01): 0 bypass in 129 drafts, 0 in the 10-question held-out, 0 figures leaked.** Path: old prompt 12/129 bypasses → define-only prompt + E2 shape: 1/43 ×1, 2/129 ×3 (both bare `obligatorio`) → audit narrowed to entitlement shapes → 0/129. Disclosed: the audit got narrower, so that 0 is partly definitional | 1.6 + 0.95 (S2c + S1c) + 1.89 |
| S3a | end-to-end positives ×2 on `cov`, 20-case gate set | literal rule (≥ 15/20 lane answers per run): **12/20 and 12/20, NOT MET**. Restated rule (≥ 15/20 answered, lane or corpus): **16/20 and 16/20, PASSED** (§5). Hard checks 40/40, 0 violations, no digit/citation in any lane answer, words max 112 | 1.93 |
| S3b | end-to-end negatives ×2, Sprint-13 bank × `cov` + `miss` (100 turns) | **100/100 pass, 0 lane answers, 0 false answers on must-escalate, 0 hard.** The lane was never reached (pre-screen v2), so S3b does not exercise the lane's own locks; S2 does | 7.37 |
| S3c | the 12 lane-answering positives ×1, caveat as a hard check | `lane_answer_without_caveat` = **0 of 9** lane answers (9/9 contain the model caveat exactly once). LP-14 / LP-45 / LP-48 flipped to the corpus path; LP-14 / LP-45 were abstentions persisted as answers → the blocker fixed in §6 | 0.56 |
| S4 | regression ×1: whitelist 16, gold-2c 4, situational 12 | **16/16, 3/4, 12/12; no pass → fail on any case**; 0 hard, 0 lane answers, 0 forbidden asks. gold-2c exits 1 on `2c-periodo-prueba-navarra`, as at baseline (0/3 at CP-2) | 1.42 |
| CP-1 prep | five live topics, persisted on staging | below | 0.28 |
| S3d | LP-14 / LP-45 ×2 after the abstention fix | LP-45 lane answer 2/2; LP-14 lane opened 2/2, draft blocked by the post-check 2/2 (§6) | 0.14 |
| **Total** | | | **≈ $20.6 of the $25 cap** |

(The cap held at every stage; the S3a and S3b estimates were $4.5 and $10.1 and came in at $1.93 and $7.37.)

### Offline gates (free)

- Goldens, lane off: 25 Sprint-13 traces unchanged. Backend suite 1389/1389 (1372 before the abstention work, +17), pint clean. hr-ai scripts: `general_lane_fetch_test.py` (prompt hash pin), `synthesis_abstention_test.py` A1–A8, `synthesis_factset_contract_test.py`.

## 2. CP-1 (your ruling, 2026-10-01)

**Read list: all ten pass.** Flat claims in clean forced-lane drafts: NEG-13, NEG-17, NEG-12, NEG-04, ln-12, ln-06. Existence affirmations: HO-02, HO-08, HO-10, HO-07 (full questions and drafts in `eval/results/cp1-read-list.md`). Ruling recorded in ADR-0038: *the lane may state that a legal figure exists; it may never state that it exists or applies for the reader.*

Five live topics persisted on staging (`test-gipuzkoa@example.com`, `cov`; `eval/results/cp1c-live-5.md`): excedencia and IT are answered by the **corpus** with citations (13b normalization), parte de baja médica, vida laboral and mutua colaboradora are **lane** answers (`model_knowledge`, 103 / 91 / 104 words, caveat present, no digit). The parte de baja and vida laboral web fetches found no excerpt and fell back to model knowledge. Note: I have not opened Historial in a browser; the chip and trace row are covered by vitest and by the stored traces.

## 3. Deviations from the spec (all disclosed as they happened)

1. **V1 + V2** positive verification instead of Check A only (plan §3.1); pool widened once after the stop at 18 survivors (Q6).
2. **Abstention opens the lane**: the F.8 relaxation, scoped to the sub-flag. A planner-rewrite (`pre_call:escalate` → lane, Q7) was **not** built: S3a showed planner escalations are 3 of 40 rows.
3. **`ModelKnowledgeShapeCheck`** added beside the post-check (Q5). **Pre-screen v2** as an allow-list (Q4). **`AnswerGate`** invariant `lane_answer_without_web_source` replaced by the declared-basis invariant.
4. **Sub-flag and restrict-only admin toggle** for model knowledge (Q9); source order web first (Q2); S3b on `cov` + `miss` (Q10); CP-1 topics substituted for finiquito / ERTE / preaviso (Q1); gate 2 read as "0 audit bypasses + passing drafts read at CP-1" (Q3).
5. **S2 was not met on the first three passes** (12/129, then 1/43, then 2/129). It was met by (a) the define-only prompt, (b) E2 shapes (`corresponde(n)`, `es (un) derecho`, `tiene(n) derecho`, `está(n) obligado(s) a`, `un derecho`, `derecho preferente`) and (c) **your decision that bare `obligatori*` is audit-only** (`audit()` flags it only in `es obligatori*`, `obligatori* que`, `obligad@(s) a`). The independent audit is vocabulary-based, which is why CP-1 reads the existence affirmations.
6. **S3a rule restated** (your decision, 2026-10-01): "≥ 15/20 lane answers per run" became "≥ 15/20 answered, lane **or** corpus, per run". Why: 13b normalization maps LP-07, LP-25, LP-28, LP-29 to a corpus topic, so the corpus now answers them with convenio / national-law authority; the promise to the employee is an explanation, not a specific path. The literal 12/20 per run stays recorded in `s3a-report.md`.
7. **Prompt iterations**: length (once, p50 only moved ≈ 12 of the ≈ 25 words asked; frozen as is) and define-only (S2c). Both inside the plan's ≤ $1.0 reserve.
8. **Goldens 28 and 29 re-recorded** (trace gained `general_lane_blocked.sub`); 32 and 33 added.
9. **Abstention fix after S3c** (the blocker, §6), which changed `/synthesise` (opt-in field), `ProsePath`, `CorpusMiss` and `AnswerGate`. Not in the plan.
10. **Lane goldens were date-fragile (found at the close-out deploy).** `Sprint13cLaneGoldenTraceTest` recorded the literal `scope_filters.as_of_date` (2026-09-30), so 7 of the 8 lane goldens failed on the deployed build the next day, while the 25 Sprint-13 goldens (which normalise it) passed. Fixed in a test-only follow-up on `main` (`4b929ef`: the lane comparator normalises today's date to `#as_of_date:today`, fixtures updated; no app code), then redeployed. The full suite passed 1389/1389 before and after.
11. **Gate semantics changed mid-slice** (disclosed in `s3a-report.md` addendum): the row carries the full `answer` and `lane_answer_without_caveat` is a hard check from S3b on; from S3d an abstention is `abstain`, never `answer`.

## 4. Findings and tickets (roadmap §7)

- **F2 / fresh-bank ticket** (E2 and F2 false positives on benign definitional wording; E2 was not loosened): `garantizar` (LP-01, LP-65, LP-75 → `general_lane_blocked`), `corresponde` (LP-11, LP-67), and HO-07's `una vez` ("…hasta la reincorporación **una vez** recibida el alta"; F2 treats `una vez` as a spelled number + quantity noun). LP-14's two post-check blocks in S3d (`figure`, `entitlement_language`) are probably the same family; the blocked drafts were not read.
- **Residual E2 shape** `derecho de <noun>` ("derecho de reingreso", NEG-15, old prompt only; 0 of 172 drafts under the new prompt).
- **Audit blind spot** (vocabulary-based): existence affirmations pass every lock and were handled by the CP-1 read and ADR-0038, not by code.
- **`finiquito`** is sensitive-guarded and **`nómina`** is pay-routed (returns the employee's salary-table answer): out of 13c (Q1, Q11). Narrowing `SENSITIVE_PATTERNS` is an ADR-0019 decision.
- **Classic engine** with the sub-flag on also escalates a flagged abstention instead of answering "no dispongo…" (rule 6 as intended). A partial answer that merely *ends* with a "no contienen una definición…" sentence is not an abstention by the anchored phrase rule.
- **S3b does not exercise the lane's locks** (pre-screen refuses first); S2's forced-lane harness is the standing test of what the lane would say.
- Normalization (`scanAll`) shares E2, so the E2 shapes added here also apply to 13b canonical rejection (`wt-04-trienios-full` now `rejected [scan:E2, topic_canonical_mismatch]`; outcome unchanged).

## 5. S3a restated, in numbers

Run 1 and run 2, 20 cases: 12 lane answers (LP-09, 11, 14, 15, 45, 47, 48, 53, 58, 67, 69, 73), 4 corpus answers (LP-07, 25, 28, 29), 4 escalations (LP-01 ×2 and LP-75 ×2 post-check blocks; LP-65 once; LP-74 planner escalation). With the abstain rule applied offline (`eval/probes/rescore-abstention.php`): S3a 16/20 and 16/20 (no abstention among the answers; the rows hold a 700-character excerpt and the phrase is anchored at the start); S3c 12/12 → 10/12 (LP-14 and LP-45 become `abstain`); S3b and S4 unchanged. **16/20 holds.**

## 6. The S3c blocker: synthesis abstention persisted as the corpus answer

LP-14 and LP-45 were persisted as the corpus answer ("No dispongo de información suficiente…") and the lane did not open. Root cause: detection was **structural, not phrase-based** (`check_b_citations=false`); the model abstained in prose while still citing a related source (`[Fuente 1]`), which passes Check B. Fix (ADR-0038): `/synthesise` returns a structured `abstained` flag when asked (`report_abstention`, only with the sub-flag on, so lane-off requests and responses are byte-identical); the model's boolean wins, the anchored opening phrase is the fallback only, a disagreement is recorded; `ProsePath` escalates a flagged abstention and records `floor_decision.synthesis_abstained`; `CorpusMiss` opens the lane on that key; the gate classifies an abstention as `abstain`. Tests: `Sprint13cSynthesisAbstentionTest` (17) and `hr-ai/scripts/synthesis_abstention_test.py` (A1–A8). Live (S3d, 4 turns, $0.14): LP-45 lane answer 2/2 (caveat ok); LP-14 lane opened 2/2 and the post-check blocked the draft 2/2 (`figure`, then `entitlement_language`). No abstention was persisted as an answer.

Full account: `eval/results/s3d-report.md`.

## 7. Staging state at close

See the close-out below.

## Close-out

**Squash and merge.** The 13c WIP commits were squashed to one commit per repo (tree hashes verified identical before and after), merged to `main` with `--no-ff`, and `main` and `sprint-13c` pushed. `hr-frontend/count-strings*` stayed untracked and out of every commit.

| Repo | Squashed branch commit | `main` merge commit | Previous `main` (rollback target) |
|---|---|---|---|
| hr-backend | `8bde38c` | `2d0e08ce8d1e4c455d94d52760e20877c39ad435`; follow-up test-only fix **`4b929efbc72c1d501094504b9d5d3b76115b100c`** (deployed) | `2004e38ed6c63d96563e63d136d9335ab1571d1b` |
| hr-ai | `d82b62e` | `09503bc86602509bbb0b72f94a585c0443ca98b5` | `e9a1e31cf792739e80ec0e215aa9a8eb09ba7eb5` |
| hr-frontend | `5a7c736` | `cf374d8592a99653cae2a1dc93c57b48f9146630` | `8aa460328c123f6c27c35bcee2ecd2580f3b60aa` |
| hr-docs | `66d92e0` | `0c5f4386c6598bdf76a339576a8b74aa6af5ad6c` (deployed; this close-out is a later docs-only commit) | `ae6caca` (box was on `56aae3b40c63366d454b2a4455a009bf664c5cb5`) |

Rollback: `deploy.sh 2004e38… e9a1e31… 8aa4603… 56aae3b…` (the full SHAs above). One migration belongs to the slice (`2026_09_30_120000_add_general_lane_model_knowledge_toggle_and_catalogue_pages`, additive, already applied during the gate); `deploy.sh` reported "Nothing to migrate" both times.

**Deploy.** Pre-merge snapshot `hr-staging-pre-13c-merge` (available before anything moved on the box) → on-box checkouts reset (`git reset --hard`, `git clean -fd` after a dry run listing only the slice's injected files; `.git` preserved) → `deploy.sh` with the four merge SHAs (leak scan clean, health green on attempt 2) → fixed OTP `135790` re-applied in the flat compose → `hr-backend`, worker and scheduler recreated with the `vars.sh` exports → artisan health. The golden fix (deviation 10) needed a second `deploy.sh` with the follow-up backend SHA (leak scan clean, nothing to migrate, health green on attempt 3), the OTP re-applied and the three services recreated again. Final: Laravel 13.16.1, PHP 8.4.26, `migrate:status --pending` none, environment staging, debug off, maintenance off, pgsql, `/up` 200.

**Flags (final state).**

| Where | `HR_GENERAL_LANE_ENABLED` | `HR_GENERAL_LANE_MODEL_KNOWLEDGE` |
|---|---|---|
| `config/hr.php` defaults (`main`) | `false` | `false` |
| repo compose `hr-docs/infra/compose/docker-compose.staging.yml` (deployed hr-docs `0c5f438`) | `"true"` | `"true"` |
| box compose `/opt/hr-staging/docker-compose.staging.yml` (diff against the repo copy: only `STAGING_FIXED_OTP_CODE: "135790"`) | `"true"` | `"true"` |
| running `hr-backend` container env | `true` | `true` |
| Guardarraíles admin values (`general_lane_enabled`, `general_lane_model_knowledge_enabled`) | `null` (no override) | `null` |
| Engine override (`answer_engine_settings`) | `agent` | |

**Verification (from the deployed build; staging flags only, no per-process override).**

| Check | Result |
|---|---|
| Goldens on the deployed commit `4b929ef` (`ops/golden-verify.sh`: deployed image runtime, throwaway Postgres and `APP_KEY`, never the staging DB; filter `Sprint13(c(Lane)?)?GoldenTraceTest`) | **35 passed, 216 assertions** (25 Sprint-13 traces + 8 lane goldens + 2 lane behaviour tests). The first run on `2d0e08c` failed 7 lane goldens on the date (deviation 10); the 25 Sprint-13 traces passed |
| Live lane answer over the real HTTP path (`ops/close-smoke.sh`, `test-gipuzkoa@example.com`, fresh session, «¿Qué es una mutua colaboradora con la Seguridad Social?») | `outcome=answer`, response `general_lane = {basis: model_knowledge, sources: []}` (what the frontend renders as the chip), draft + model caveat ("Información general, redactada sin consultar tu convenio… sin una fuente verificable…"). Trace of msg 8055 (previous build, same wording): `floor_decision.authority_used = ["general_knowledge"]`, `path = general_knowledge`, `general_lane.basis = model_knowledge`, source `conocimiento general del modelo`, `shape.verdict = pass`, `prompt_sha256 = 68893dba…57a59af`. Repeated on the final build (msg 8063) with the same payload and answer shape |
| Entitlement question refused by the pre-screen, end to end (msg 8073, «¿Tengo derecho a teletrabajar dos días por semana?», agent engine, real planner) | the corpus synthesis abstained via the flag (`floor_decision.note = "synthesis abstained (flag)"`), the lane was **not** opened (no `general_lane` block, no `general_knowledge` step), the turn escalated `low_confidence` with the HR hand-over message and no answer. The deployed `GeneralLanePostCheck::questionRefusal()` returns `prescreen_v1` for this question and for «¿Con cuántos días de preaviso…?» (msg 8067, same shape), and `null` for the mutua and SMAC questions that do open the lane. Honest scope: this is the *hand-over denial* branch (`CorpusMiss` returns null for a blocked question, the corpus escalation stands); the forced `general_lane_blocked` / `question_prescreen` branch needs the planner to call the lane on such a question and did not occur in this run (it is covered by golden 30) |
| Guardarraíles toggles turn it off with no deploy (real admin HTTP API, `admin@hr-staging.internal`, `POST /api/admin/guardrails`; fresh session per turn) | lane toggle `false` → `effective` false for both, the mutua question escalated `low_confidence`, no lane payload (msg 8081); restored to `null` → lane answer again (msg 8083). Model-knowledge toggle `false` → lane `effective` still true, model-knowledge `effective` false, mutua escalated (msg 8085, no catalogue page exists for it so the web-only lane has nothing); restored to `null`, effective true both. Every POST returned 200 |
| Classic smoke via override flip and back (`answer-engine:set classic --admin=…`, «¿Cuántos días de permiso retribuido me corresponden por matrimonio?», then `agent`) | classic answered (20 días naturales + parejas de hecho, cited); its trace (msg 8089) has **no `agent` block** and no `engine` key, the agent turn of the same question (msg 8087) has both; DB read-back `agent` |

Other live turns on the way (all `test-*@example.com`, persisted, visible in Historial): corpus answers for the matrimonio, indemnización, fallecimiento, mudanza and NEG-13 birthday questions; planner escalations for «coche de empresa»; `salary_coverage_gap` for the festivo plus question. No lane answer on any entitlement question.

**Snapshots** (`hr-staging-db`, manual): `post-ingest-20260906`, `post-11b`, `post-12a`, `post-13`, `post-13b`, `post-13d`, **`post-13c`** (2026-10-01 01:06 UTC, after verification and with the engine and toggles restored). `pre-13c-merge` deleted.

**Spend.** Gate stages, CP-1 and the abstention fix ≈ $20.6 (table in §1) plus the close-out's 18 live turns (≈ $0.03 per lane turn, ≈ $0.06–0.10 per agent corpus turn, upper bound 18 × $0.074 = $1.3; the figure below uses ≈ $1.1): **≈ $21.7 of the $25 cap**. The golden runs use a throwaway Postgres and no model calls.

**Staging state.** Lane and model knowledge **on** (env), admin overrides null, engine override agent. The Guardarraíles writes above left audit rows in `guardrail_config_events` (4 writes: off, restore, off, restore). Persisted close-out turns remain under `test-gipuzkoa@example.com` and `test-deporte-estatal@example.com` (msgs 8055–8089).
