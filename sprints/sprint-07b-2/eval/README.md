# Sprint 7b-2 segmentation eval

**The eval is the deliverable.** This folder is the reproducible harness that turns the
agent's proposals into the accuracy report in [`../review.md`](../review.md).

## Files
- `gold-set.json` — hand-built ground truth, one entry per expected per-scope fact,
  verified line-by-line against the real fixtures in [`../fixtures/`](../fixtures/) and the
  registry mapping (plan §1.5). Carries the trap markers (`spot_check`), the
  `uncertain`/`skip` expectations, and the version `duplicate_of` links.
- `score_eval.py` — stdlib-only scorer. Matches proposed facts to gold on
  normalized `(territory, sector, group)`, scores value (digit↔spelled-out tolerant)
  and scope, and prints the **mis-scoped enumeration** (the heart of the report).

## Why it is decoupled from the live LLM
The score step needs no API key and is reproducible/cheap. The expensive, non-deterministic
step (calling the model) is the live run below, which spends the Anthropic budget and needs
the full stack up — so it is run once, its output captured to JSON, and scored offline. This
also lets the prompt be iterated (re-segment → re-export → re-score) without touching the scorer.

## Live run (produces the numbers)
1. Bring the stack up (the throwaway test/dev Postgres on the alt ports, hr-ai, hr-backend),
   set the Anthropic key in `AnswerModelSetting`.
2. Ingest each fixture as a **`reference_source`**, setting validity at ingest
   (file 1 → pre-2026, file 2 → 2026, xlsx → its window). Ingest auto-dispatches
   `SegmentReferenceSource`; or trigger manually:
   `POST /admin/reference-sources/{uuid}/segment` (gated `knowledge.edit`).
3. Export the proposals per fixture to JSON (the queue rows already carry every scored field):
   ```
   GET /admin/reference-facts?source=ai_agent&queue=true     # → save the `rows` array
   ```
   Save as e.g. `facts_file1.json` (a JSON list of the row objects).
4. Score:
   ```
   python3 score_eval.py --gold gold-set.json --facts facts_file1.json \
       --fixture "PERIODOS DE PRUEBA.docx"
   python3 score_eval.py --gold gold-set.json --facts facts_file2.json \
       --fixture "PERÍODOS PRUEBA ACTUALIZADOS 2026.docx"
   python3 score_eval.py --gold gold-set.json --facts facts_xlsx.json \
       --fixture "Tablas acuerdo parcial_Alhambra.xlsx"
   ```
5. Paste the per-fixture line + the mis-scoped enumeration into `../review.md`,
   and confirm the spot-checks (`grep spot_check gold-set.json`).

## The judgement (plan §5.3)
A high mis-scope rate the **review UX makes catchable** (every error surfaces with its
source line + low confidence; rejected in seconds) is **acceptable** for a human-gated tool.
A mis-scope rate where errors are **confident and plausible** (high confidence, no
uncertainty flag — would be rubber-stamped) is the **failure signal → iterate the prompt**
before declaring done. `review.md` states which regime we landed in, with the numbers.
