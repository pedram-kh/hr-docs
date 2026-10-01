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
10. **Gate semantics changed mid-slice** (disclosed in `s3a-report.md` addendum): the row carries the full `answer` and `lane_answer_without_caveat` is a hard check from S3b on; from S3d an abstention is `abstain`, never `answer`.

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

Staging engine override: **agent** (DB), as 13b/13d left it. The close-out section below records merge SHAs, deploy, flags, verification and snapshots.

## Close-out

(appended at merge time)
