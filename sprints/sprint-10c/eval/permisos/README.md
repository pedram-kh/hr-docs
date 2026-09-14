# Sprint 10c permisos eval (plan §D.10)

**The eval is the deliverable.** This is topic 1 of the tranche — the first live
proof the new per-(convenio, topic) driver (Step 3) produces correctly-scoped,
correctly-restrained facts against REAL convenio text, before topic 1's batch
run (gated on this eval's result, ⏸ CP-A).

## Files
- `gold-set.json` — hand-built ground truth, 44 entries across 6 REAL convenios
  (2, 3, 10, 18, 20, 25), pulled live from staging (`document_pages` where
  `document_type=convenio_text` AND `retrieval_status=active`) and verified
  quote-by-quote. 33 `expect: "fact"` entries (a real motivo with a real
  figure), 10 `expect: "no_fact"` entries (a figure-less clause, or — the real
  finding this pull surfaced — a DIFFERENT topic's content living inside or
  beside the same article), 1 `context_note_not_a_fact` (informational only).
- `score_eval.py` — stdlib-only scorer, adapted from 7b-2's for this driver's
  single-convenio shape (motivo-keyword matching, not cross-province scope
  matching — see the file's own docstring for why).
- `runs/` — each live run's exported facts (`run{N}_convenio{ID}.json`) AND
  the fact ids it wrote (`run{N}_fact_ids.json`, per the eval-iteration-hygiene
  requirement: a superseded run's facts are deleted BY THESE RECORDED IDS,
  never a broad delete-by-query).

## The real finding this pull surfaced (beyond §B.4's prediction)
The plan's §B.4 sample (5 quotes) predicted permisos would be "the easiest
topic." The full gold-fixture pull mostly confirms that (clean, enumerated,
scoped clauses in every convenio sampled) — but surfaced ONE real risk §B.4's
shallow sample missed: convenio 2 and convenio 25 both have maternidad/
paternidad/lactancia clauses (statutory suspension-of-contract machinery — 16
semanas, permiso parental, cuidado del lactante) living inside or immediately
beside the very same "Permisos"/"Licencias" article, anchored on the same
word ("permiso"/"licencia"). This is a genuine, real topic-boundary trap,
structurally identical in shape to §B.4's preaviso-polysemy finding (D2) —
just for a topic the plan predicted would be easy. `gold-set.json`'s two
`spot_check: "... TOPIC-BOUNDARY TRAP ..."` entries (convenio 2's letter (j)
lactancia clause, convenio 25's whole nacimiento/lactancia/parental CAPÍTULO)
gold-fixture this directly. See `review.md` for whether the prompt's existing
rule 12 (generic "off-topic content → emit nothing" gate) catches this
out-of-the-box or needed a topic-specific addition.

## Live run (produces the numbers)
1. Sprint 10c branch code injected onto staging (`hr-backend`, `hr-ai`) —
   see `review.md`'s injection table.
2. Run inline, per convenio, via the new command (writes real `needs_review`
   facts + logs `prompt_tokens`/`completion_tokens` per D1):
   ```
   php artisan facts:segment-topic permisos --convenio=2
   php artisan facts:segment-topic permisos --convenio=3
   ... (one per gold convenio)
   ```
3. Export each convenio's proposed facts (`source=ai_agent`,
   `topic_id`=permisos' id, `convenio_id`=that run's convenio) to
   `runs/run{N}_convenio{ID}.json`; record the written fact ids to
   `runs/run{N}_fact_ids.json` in the SAME step (eval-iteration hygiene —
   a superseded run's facts are deleted by these ids, not by a broad query).
4. Score each convenio:
   ```
   python3 score_eval.py --gold gold-set.json --facts runs/run1_convenio2.json --convenio 2
   ```
5. Paste the per-convenio numbers + the restraint-failure enumeration into
   `../../review.md`. If the gate (0 mis-scope, 0 restraint failures) fails,
   iterate the prompt (`hr-ai/app/providers/claude.py`'s
   `_build_topic_segment_system_prompt`), re-inject, DELETE the superseded
   run's facts by its recorded ids, re-run, re-score. Only the FINAL passing
   run's facts are left in the queue.

## The judgement (same posture as 7b-2, plan §D.10)
0 mis-scope (convenio_id) is a hard gate — single-candidate binding should
make this structurally near-impossible, so ANY violation here is a real bug,
not a prompt-quality question. 0 restraint failures is the real gate this
topic's eval is actually testing (the topic-boundary trap above). A `missed`
motivo (a real figure the model didn't propose) is a lower-severity finding —
recorded, not gate-failing, per 7b-2's own precedent that missed>false-positive
in a human-gated review queue.
