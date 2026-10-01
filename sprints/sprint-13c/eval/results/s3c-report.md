# S3c — the 12 lane-answering positives ×1 on `cov` with the new gate (caveat hard check)

`answer:gate --engine=agent --repeat=1` on LP-09, 11, 14, 15, 45, 47, 48, 53, 58, 67, 69, 73; lane + sub-flag on for the process. Raw: `s3c-rows.jsonl`, `s3c-gate.log`. Spend $0.56 (gate list pricing).

## Hard number: `lane_answer_without_caveat` = **0 of 9** lane answers

- Lane answers: **9** (all `model_knowledge`). Each full answer contains the model caveat exactly once (9/9, checked on the full `answer` text, which the row now carries) — the gate's own hard check agrees: 0 hard violations; gate pass 12/12.
- Every lane answer: no digit, no citation, words 86–110 (max 110 ≤ 120).
- Not lane answers this time (3): LP-14 (Inspección de Trabajo), LP-45 (SMAC), LP-48 (comité de seguridad y salud). In S3a both runs gave lane answers for all three; here the **corpus path answered** with `outcome=answer`, `path=prose`, and two of the three are "no dispongo de información suficiente en las fuentes…" style answers (LP-14, LP-45). Corpus synthesis is not deterministic across runs, so these three flip between lane and corpus.
- **Consequence for the restated S3a rule ("answered, lane or corpus"):** a corpus "no dispongo de información suficiente" counts as `outcome=answer` for the gate. In S3a the four corpus answers (LP-07/25/28/29) were real content; here two of the three corpus answers are not. The rule as restated counts both the same; flagged, not changed.
