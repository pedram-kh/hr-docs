# Sprint 10c — festivos gold-fixture eval (topic 4, next checkpoint after CP-3)

Same harness shape as `eval/permisos/`, `eval/vacaciones/`, `eval/jornada/`:
hand-built gold set → score offline → live run → paste numbers into
`review.md`. This topic's gold set was built **measured, not assumed** —
before writing a single gold entry, every 'festivos'-anchored page in the
6 eval convenios was pulled and read (see `quotes/*.txt`), and the real
distribution it revealed changed the shape of the eval itself (see below).

## Files

- `gold-set.json` — 8 entries across the same 6 convenios as permisos/
  vacaciones/jornada (2, 3, 10, 18, 20, 25): 2 positive (`fact`)
  calendar-of-festivos entries, 6 negative (`no_fact`) restraint entries —
  the inverse ratio of every prior topic in this tranche.
- `quotes/*.txt` — raw pages pulled live from staging (`document_pages`,
  `document_type='Convenio (texto)'`, `retrieval_status='active'`), the
  traceability record behind every gold quote (including the ones that
  justify a NEGATIVE entry — the absence of festivos-calendar content is
  itself verified against the real page, not assumed from a dry-run
  document list).
- `score_eval.py` — the scorer, copied unmodified in logic from
  `eval/jornada/score_eval.py` (topic-agnostic scoring shape).
- `runs/` — live-run exports and recorded fact ids (written once the live
  run executes).

## The real finding, made BEFORE writing the gold set: this topic is corpus-wide dominated by the wrong content

`TopicLexicon::ANCHORS['festivos']` = `['festivos', 'dias festivos',
'fiestas laborales']` — reasonable-looking anchors. But reading every
anchored page across all 6 eval convenios (not just the ones that turned
out positive) showed that almost every real "festivos" mention in this
corpus is a **retribución** clause — a per-hour or per-day premium for
*working* on a festivo ("Plus de festivos", "Plus de domingos y/o
festivos", "Plus de festivos de especial significación") — not a calendar
of which days ARE festivos or how many días de cierre the convenio grants.
Only 2 of the 6 convenios (c.2, c.10) have any genuine calendar-of-festivos
content at all. This is the same posture as the jornada checkpoint's own
lesson applied one topic early: **verify the input before assuming a
topic's shape** — here the assumption would have been "festivos should
look like jornada/permisos/vacaciones, mostly positive with a couple of
boundary traps"; the real corpus said otherwise, and the gold set (and the
prompt rule below) were built to match reality, not the pattern of the
prior three topics.

## Why these 6 convenios (same as permisos/vacaciones/jornada)

Reused for continuity, and because reading all 6 gave a genuinely
representative sample of the shape above:

- **c.2** — Art. 27 "Descanso semanal y festivos": a real, simple grant
  ("mínimo dos días festivos anuales... 1 de enero y 25 de diciembre, de
  cierre de la instalación, no susceptibles de compensación"). This
  convenio's OWN Art. 34 salary structure ("Plus de domingos y festivos, a
  razón de un 15/22 por ciento del precio hora") is this eval's first
  same-convenio negative — several pages later, a genuinely different
  article, testing that the percentage doesn't get merged into or emitted
  alongside the calendar fact.
- **c.10** — 23.4 "Días de libranza adicionales" (24/31 diciembre + Sábado
  Santo): a real grant, sitting in the SAME article as this eval's hardest
  adjacency trap — 23.3 "Trabajo en festivos" (a 51,50€ premium),
  *immediately before* 23.4 in the source text. Confirms whether the model
  can split one article into a genuine fact (23.4) and a correctly-omitted
  sub-clause (23.3) rather than merging them by proximity.
- **c.3** — Art. 31: "Plus de domingos y festivos" (a per-hour premium,
  2023-2026 schedule) + "Plus de festivos de especial significación"
  (real dates: 24/25/31 dic, 1 enero, even down to the hour) — every hit
  in this convenio is retribución. Zero facts is the correct, measured
  outcome.
- **c.18** — Art. 37: the **hardest** negative trap in the set. Real,
  specific dates ARE present (24 dic 16h–24h, 25 dic, 31 dic 16h–24h, 1
  enero) — unlike c.20 below, there is genuine substantive content to
  tempt an extraction. But its sole function is bounding a "plus de
  festivos de especial significación" premium's eligibility window, not
  granting días de cierre. No dedicated calendar article exists anywhere
  in this convenio's 7 anchored pages (4, 15, 28, 35, 36, 37, 73 — all
  read, none is a calendar article).
- **c.20** — Art. 17 "PLUS FESTIVO": the **easiest** negative trap — no
  dates at all, only a bare count-reference ("los 14 festivos oficiales
  fijados anualmente") deferring entirely to an external, unlisted
  calendario laboral, existing solely to bound a pay premium.
- **c.25** — Art. 40 "Plus de trabajo en domingos y festivos": the
  **plainest** negative — no dates, no count, no calendar reference of any
  kind, pure retribución.

## Prompt rule added PRE-EMPTIVELY (unlike permisos/vacaciones/jornada's reactive-only default)

Every prior topic this sprint ran reactively first (no topic-specific rule
until a real failure named one). Festivos breaks that pattern **on
purpose**: reading all 6 convenios' anchored pages *before* writing the
gold set surfaced such a strong, consistent, corpus-wide over-extraction
risk (4 of 6 convenios are pure retribución traps, one of them — c.18 —
with real dates attached, exactly the kind of detail that tempts a
plausible-looking wrong extraction) that waiting for a live failure to
name the fix would have meant knowingly running the eval against a
predicted failure mode instead of a genuinely unknown one. `claude.py`
rule 17 (`FESTIVOS_DISAMBIGUATION_ES`) was added before run 1, defining
festivos strictly as "the number/calendar of festivo days granted" and
explicitly excluding (a) any "Plus de festivos"-family retribución clause,
(b) a festivo *definition* that exists only to bound such a plus (even
with real dates, per c.18), and (c) rest-hours compensation for working a
festivo. This is the inverse of jornada's rule 16 (a RECALL fix, for a
real article the model was under-reading) — festivos' rule is a
RESTRAINT fix, for content the model should not read into at all.

## Result: 6/6 clean on run 1 — no iteration needed

The only topic in this tranche whose first live run required no correction.
See `review.md`'s festivos section for the full run1 numbers, cost, and
queue-state report.
