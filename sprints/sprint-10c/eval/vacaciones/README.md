# Sprint 10c — vacaciones gold-fixture eval (CP-B)

Same harness shape as `eval/permisos/` (plan §D.10): hand-built gold set →
score offline → live run → paste numbers into `review.md`.

## Files

- `gold-set.json` — 19 entries across the same 6 convenios as the permisos
  eval (2, 3, 10, 18, 20, 25): 15 positive (`fact`) figure-level entries, 4
  negative (`no_fact`) restraint entries.
- `quotes/*.txt` — raw pages pulled live from staging
  (`document_pages`, `document_type='Convenio (texto)'`,
  `retrieval_status='active'`), the traceability record behind every gold
  quote.
- `score_eval.py` — the scorer, copied unmodified in logic from
  `eval/permisos/score_eval.py` (topic-agnostic scoring shape).
- `runs/` — live-run exports and recorded fact ids (written once the live
  run executes).

## Why these 6 convenios (same as permisos)

Reused for continuity, and because they already contain every trap plan
§D.10 named for this topic:

- **c.18** — the dual-unit sentence ("33 días laborables o 45 días
  naturales") — plan's own named trap.
- **c.2, c.3** — genuine multi-year vacaciones day-count schedules (3-year
  and 4-year respectively) — the real substitute for plan's "c.20's
  four-year declining schedule" prediction, which this eval pass found to be
  **wrong** (see below).
- **c.20, c.10** — two NEW topic-boundary traps found live during this
  pass, not predicted by plan.md: a jornada-hours schedule sitting directly
  above the real vacaciones article body at a page-break/running-header
  repeat (c.20), and a bare jornada-hours figure in the same paragraph block
  as the vacaciones day-count (c.10, no heading in between).
- **c.25** — the interruption-by-birth-leave narrative (plan's own named
  restraint case), found on a different page (embedded in the nacimiento
  article) than plan.md's shallow sample guessed, plus a clean flat-figure
  vacaciones article for the positive case.

## Correction to plan.md §D.10

Plan.md predicted: *"c.20's four-year declining schedule (per §B.4, a
deliberate test of the multi-year-in-one-fact shape)"* for vacaciones. This
is **verified wrong** against the live document (`document_pages`,
document_id=50, pages 9-10): that four-year declining schedule (three job
category variants: general, Técnicos de Actividad, Técnicos de Sala) is
**Art. 22 JORNADA**, not Art. 25 VACACIONES. c.20's real vacaciones
entitlement is a flat, single-year "30 días naturales" (Art. 25). The
likely cause: a running chapter header
("CAPITULO CUARTO: JORNADA, DESCANSOS, PERMISOS EXCEDENCIAS Y VACACIONES")
and an "Art. 25- VACACIONES" article-list heading both repeat at a page
break (page 9→10), immediately before the tail of the Técnicos de Sala
jornada figures — a plausible source of the plan's shallow 6-page sample
misreading this as a vacaciones passage. Corrected here: the schedule is now
gold set entry #18 as a **negative** (restraint) case instead of a positive
multi-year-fact case. The genuine "multi-year schedule in one fact" test
(D5's precedent, generalized by ADR-0034 §1) is instead covered honestly by
c.2 and c.3, both confirmed live to be real multi-year vacaciones day-count
schedules.
