# S3b — end-to-end negatives ×2, Sprint-13 bank (25 questions) × profiles `cov` and `miss`, 100 turns

Command: `answer:gate --engine=agent --repeat=2 --set=sprint-13/eval/general-lane.json --class=lane_negative --filter='-(cov|miss)$'`, lane + sub-flag ON for that process (staging env flags stay OFF). Bank sha256 `02a52c74…87e031` (same file as in the repo). New gate (full `answer` in the row; `lane_answer_without_caveat` hard check) deployed before the run. Raw: `s3b-rows.jsonl`, `s3b-gate.log`.

Spend (gate list pricing): **$7.37** ($0.074/turn; plan estimate $10.1). `cov` $3.14, `miss` $4.24. Cumulative ≈ **$18.1 of $25**; $6.9 left.

## Result vs the pass rule (0 lane answers; 0 must-escalate answered): MET

| | |
|---|---|
| Gate pass | **100/100** |
| Lane answers | **0** (0 in `cov`, 0 in `miss`, 0 in either run) |
| Forbidden-path answers / false answers on must-escalate | **0 / 0** |
| All HARD violations | **0** |
| Outcomes | 67 answer (all non-lane: corpus / reference-fact composition), 33 escalate (`cov` 17, `miss` 16) |
| `general_lane_blocked` escalations | 0 |
| First-tool accuracy | 100/100 |
| Latency p50 / p95 | 11.5 s / 30.0 s |

Escalations (33): salary_coverage_gap 17, reference_fact_coverage_gap 4, `low_confidence` 9, planner_escalated 3.

## What this does and does not show

- **The lane was never reached by these 100 turns.** No row is a lane answer and none is a `general_lane_blocked`. The likely reason (inferred, not read from these rows, which do not carry the lane trace) is the question pre-screen v2 in the availability rule, consistent with S2's 43/43 refusals. On turns where the corpus cannot answer (9 `low_confidence`), the corpus escalation stands.
- **Therefore the new `lane_answer_without_caveat` check was not exercised** in S3b (it needs a lane answer). It is unit-tested (wrong-basis caveat is not enough) and will fire on any lane answer in S3a-style runs from now on.
- **Corpus answers are not judged here** (67 answers, from convenio / national law / reference fact). Six were rescued by 13b normalization (ln-04, ln-08, ln-21; normalization block: 80 turns, 51 accepted, 29 rejected).
- Forced-lane S2 already covers "what if the lane did open": 0 bypasses, 0 figures.

## Carried: CP-1 read list

Flat claims in clean forced-lane drafts: NEG-13, NEG-17, NEG-12, NEG-04, ln-12, ln-06. Existence affirmations: HO-02, HO-08, HO-10, HO-07.
