# S3a — end-to-end positives ×2, profile `cov`, 20-case gate set (lane + sub-flag ON per process; staging env flags stay OFF)

Command: `answer:gate --engine=agent --repeat=2 --set=lane-positives.json --filter=<the 20 gate ids>` with `HR_GENERAL_LANE_ENABLED=true HR_GENERAL_LANE_MODEL_KNOWLEDGE=true` set on that one process. Bank hash-checked against the MANIFEST. Raw: `s3a-rows.jsonl`, `s3a-gate.log`.
Positives are frozen on `cov` only (survivors are defined by `cov` corpus behaviour), so `miss` is not run here; it is the S3b negatives profile. 40 turns.

Spend (gate list pricing): **$1.93** ($0.048/turn; plan estimate $4.5). Cumulative ≈ $10.7 of $25.

## Result vs the spec rule (≥ 15/20 lane answers in each run): NOT MET — 12/20 and 12/20

| | run 1 | run 2 |
|---|---:|---:|
| Lane answers (model basis) | 12 | 12 |
| Corpus answers (lane not needed) | 4 | 4 |
| Escalations | 4 | 4 |
| Answered, lane + corpus | 16 | 16 |

Same 12 questions answered by the lane in both runs (LP-09, 11, 14, 15, 45, 47, 48, 53, 58, 67, 69, 73), so the result is stable, not noisy.

Where the 8 non-lane cases per run went:
- **Corpus now answers them (4; LP-07, LP-25, LP-28, LP-29):** after 13b normalization maps each to a topic (`significado de incapacidad temporal (IT)`, `excedencia: definición y funcionamiento`, …) the corpus answers with official_convenio / national_law authority. The S0 survivor status was measured without that path. These are good answers, but by definition not lane answers, so ≥ 15/20 lane answers is capped at 16/20 for this gate set.
- **Lane draft blocked by the post-check (3 in run 1, 3 in run 2):** LP-01 ×2, LP-75 ×2, LP-65 ×1. These are the benign-wording E2 hits seen in S1c (`garantizar`, `corresponde`) → `general_lane_blocked` escalation. Correct behaviour of the rule, but a cost of E2's breadth on definitions.
- **Planner escalated before the lane (1 in run 1, 2 in run 2):** LP-74 ×2 (`¿Cómo funciona el paro?`), LP-65 run 2 (first tool `general_knowledge`, i.e. the planner proposed the lane first; counts as a routing miss by the existing rule).

## Hard checks (all 40 rows)

- Gate pass 40/40; HARD violations 0; forbidden asks 0.
- Lane answers: basis `model_knowledge` 24/24; no web basis; no digit in any lane answer; words p50 100, max 112 (≤ 120 on all 24); no citation on any model-basis answer; `lane_answer_without_declared_basis` 0.
- Latency (lane answers): p50 12.5 s, p95 17.1 s (whole turn: planner + corpus search + lane). Cost per lane answer mean $0.034.

## Not measured end to end (be aware)

- **Badge/caveat**: `caveat_ok` is the whitelist-temptation field, not the lane caveat (the plan's 5.1 cites it in error). The gate row keeps only a 700-character excerpt; the model caveat was visible in the 12 excerpts short enough to show it, cut off in the other 12. The caveat is covered by goldens and unit tests, but not verified by this run on all 24. Adding the full answer text to the row is a one-line change.
- **Escalate button**: frontend-side, not a gate field.

## Q7 input (plan.md: decide after S3a)

Planner escalations are 3 of 40 rows — not the shortfall. The shortfall is (a) corpus now answering 4 of the 20 (8 rows) and (b) E2 blocks on benign definitional wording (5 rows). A `pre_call:escalate` → lane rewrite would recover at most 3 rows.

## CP-1 read list (carried)

Flat claims: NEG-13, NEG-17, NEG-12, NEG-04, ln-12, ln-06. Existence affirmations: HO-02, HO-08, HO-10, HO-07.

---

## Addendum — S3a restated and closed (user decision, 2026-10-01)

**Rule as restated:** S3a passes when **≥ 15/20 cases are answered (lane *or* corpus) in each run**, plus all hard checks. Result: **16/20 and 16/20 → S3a PASSED.**

**Why the rule changed** (recorded, not silently edited): the spec's "≥ 15/20 lane answers" assumed the S0 survivors stay lane questions. Sprint-13b normalization now maps 4 of the 20 (LP-07, LP-25, LP-28, LP-29) to a corpus topic, and the corpus answers them with convenio / national-law authority. The spec's promise to the employee is an **explanation** for a concept question, not a specific path. A corpus answer keeps that promise, so counting lane answers only penalised the system for answering with a better source. Literal S3a (12/20 lane) stays recorded above.

**E2 unchanged.** The definitional false positives (`garantizar`, `corresponde`: LP-01, LP-65, LP-75 → `general_lane_blocked`) stay on the fresh-bank ticket.

**Gate changes from S3b on** (`AnswerGate`): (1) the row carries the full `answer` text; (2) new hard check `lane_answer_without_caveat` — every lane answer must contain its basis-specific caveat (`GENERAL_LANE_MODEL_CAVEAT` for model basis, `GENERAL_LANE_CAVEAT` for web). Tests: `Sprint13cModelKnowledgeToolTest` (wrong-basis caveat is not enough).
