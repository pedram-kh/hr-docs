# S3d — synthesis abstention: root cause, fix, S3a/S3c re-score, LP-14/LP-45 live ×2

## 1. Root cause (S3c: LP-14, LP-45 persisted as the corpus answer, lane never opened)

- **Not a phrase match.** `CorpusMiss::isSynthesisAbstention()` was structural: `check_a_retrieval=true`, `check_b_citations=false`, `escalate/low_confidence`. The lane hand-over therefore depended on the model obeying `/synthesise` rule 6 (abstain ⇒ `cited_sources: []`, confidence ≤ 0.2).
- LP-14's S3c draft: *"No dispongo de información suficiente … Las fuentes solo mencionan a la Inspección de Trabajo y Seguridad Social [Fuente 1], pero no contienen una definición."* It **cites** a related source → Check B passes → the turn is a normal answer. LP-45's draft opens "Ninguna de las fuentes proporcionadas menciona el SMAC…" (same shape).
- It is model non-determinism: a diagnostic persisted run (messages 8043/8045, ≈ $0.1) abstained cleanly and the lane opened.

## 2. Fix

- hr-ai: opt-in `report_abstention` → `abstained` / `abstained_by`; model flag wins, anchored phrase is the fallback only (`hr-ai` commit `8207f4d`; `scripts/synthesis_abstention_test.py` A1–A8 pass).
- hr-backend: `ProsePath` requests the flag only when the model-knowledge sub-flag is effective; flagged abstention ⇒ escalate `low_confidence` + `floor_decision.synthesis_abstained`; `CorpusMiss` opens the lane on that flag first, structural form kept; `SynthesisAbstention::phrase()` is the gate's/trace's fallback (commit `9734436`).
- Gate: `outcome_class = abstain` (never `answer`), `abstained_by`, summary `abstentions`.
- Tests: `Sprint13cSynthesisAbstentionTest` (17: lane hand-over on a *cited* abstention, `CorpusMiss` exclusions, sub-flag off / lane off byte-identical request and trace, `abstained=false` answers, request body top-level only, phrase fixture ×9, gate classification and summary). Full backend suite **1389/1389** (was 1372), pint clean, goldens unchanged.

## 3. Offline re-score with the abstain rule (`probes/rescore-abstention.php`)

| Set | Run | answered (raw) | answered (abstain rule) | abstain |
|---|---|---|---|---|
| S3a | 1 | 16/20 | **16/20** | 0 |
| S3a | 2 | 16/20 | **16/20** | 0 |
| S3c | 1 | 12/12 | 10/12 | 2 (LP-14, LP-45) |
| S3b ×2, S4 ×3 | — | unchanged | unchanged | 0 |

**16/20 holds** (restated S3a rule ≥ 15/20 answered, lane or corpus). Caveat: `s3a-rows.jsonl` stores only a 700-char `answer_excerpt`; the phrase is anchored at the start of the answer so the excerpt is sufficient. In S3a LP-14 and LP-45 were *lane* answers (both runs). S3c's lane answers go from 9 to 9 (the two abstentions were never lane answers) — S3c was 12/12 raw only because abstentions were counted.

## 4. Live: LP-14, LP-45 ×2 on `cov`, lane + sub-flag on for the process (`s3d-gate.log`, `s3d-rows.jsonl`)

| Case | run 1 | run 2 |
|---|---|---|
| LP-45 | lane answer, `model_knowledge`, caveat ok (msg 8051) | lane answer, `model_knowledge`, caveat ok (msg 8053) |
| LP-14 | lane **opened**, draft blocked by post-check `figure` (msg 8047) → escalate `general_lane_blocked` | lane **opened**, draft blocked `entitlement_language` (msg 8049) → escalate `general_lane_blocked` |

- No abstention was persisted as an answer in 4/4 turns; 0 hard violations; 4/4 gate pass.
- **LP-45: the lane answers, 2/2.** **LP-14: the lane opens 2/2 but the lane's own post-check discards the draft** (F-family figure in run 1, E-family entitlement language in run 2). That is the lane working as designed (an escalation to HR rather than a wrong "no dispongo…"), but it is not an answer. In S3a LP-14 answered in both runs, so this is draft variance against a deterministic block, i.e. the same false-positive family as the F2/E2 fresh-bank ticket. I did not dig into the blocked drafts (not persisted beyond the trace sub-reason); worth reading them when that ticket is opened.
- Spend: 4 turns, **$0.138** (list pricing). Cumulative ≈ **$20.6 of $25**.
