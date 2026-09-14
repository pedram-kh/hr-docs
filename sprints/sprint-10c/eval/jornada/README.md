# Sprint 10c — jornada gold-fixture eval (topic 3, next checkpoint after CP-B)

Same harness shape as `eval/permisos/` and `eval/vacaciones/`: hand-built
gold set → score offline → live run → paste numbers into `review.md`.

## Files

- `gold-set.json` — 21 entries across the same 6 convenios as permisos/
  vacaciones (2, 3, 10, 18, 20, 25): 19 positive (`fact`) figure-level
  entries, 2 negative (`no_fact`) restraint entries.
- `quotes/*.txt` — raw pages pulled live from staging (`document_pages`,
  `document_type='Convenio (texto)'`, `retrieval_status='active'`), the
  traceability record behind every gold quote.
- `score_eval.py` — the scorer, copied unmodified in logic from
  `eval/vacaciones/score_eval.py` (topic-agnostic scoring shape).
- `runs/` — live-run exports and recorded fact ids (written once the live
  run executes).

## Why these 6 convenios (same as permisos/vacaciones)

Reused for continuity — and because this pass found the jornada topic's
real corpus interleaves directly with BOTH topics already evaluated,
making the same 6 convenios the natural, cheapest place to find this
topic's boundary traps too:

- **c.20** — Art. 22 JORNADA is the exact same four-year, three-job-
  category declining schedule (general staff / Técnicos de Actividad
  deportiva / Técnicos de Sala, 2025-2028) the vacaciones eval found
  misattributed by plan.md and used as ITS negative gold entry. Here it is
  jornada's own genuine, richest positive case — 12 figures (3 groups × 4
  years) that must all land correctly. Its adjacent Art. 25 VACACIONES (30
  días naturales) becomes THIS eval's own negative entry — the exact
  mirror image of the vacaciones eval's finding, same document, same
  page-break/running-header adjacency, opposite direction.
- **c.10** — Art. 23's "1.752 horas... en cómputo anual" is the same bare
  figure the vacaciones eval flagged as a restraint trap on its own side
  (found leaking near vacaciones content in the same paragraph block). Here
  it is jornada's own correct, simple positive fact.
- **c.2** — Art. 25: a genuinely rich COMPOUND trap not seen in either
  prior topic — 3 years (2024/2025/2026) × 2 job-category groups (1&2 vs
  3&4) = 6 figures, all of which must bundle into one fact (D5 × D4
  together, not separately).
- **c.3** — Art. 24: a clean 4-year declining schedule (2023-2026, no group
  split), each year's figure explicitly folded together with a "20 horas de
  formación" caveat that belongs IN the fact — plus, two articles earlier
  (Art. 22, "De la formación continua"), an entirely unrelated training-
  CREDIT clause that happens to share jornada's own `TopicLexicon` anchor
  term "horas anuales." This is this eval's other negative entry: not a
  lexical topic-name collision (permisos' lactancia shape) or a document-
  structural page-break collision (vacaciones'/c.20's shape here), but an
  **anchor-term collision** — a third distinct shape for the same
  underlying "the corpus's real structure creates a topic-boundary trap"
  category ADR-0034 already names.
- **c.18** — Art. 28: a clean single-year figure (1620 horas, 2025) plus a
  genuine non-figure jornada provision (the 6h/15min continuous-shift
  break rule) — confirms a jornada article can legitimately carry more than
  one kind of fact (an hours figure AND a scheduling rule), both real,
  neither a trap.
- **c.25** — Art. 33 (1750 horas flat) plus Art. 34 "Jornada intensiva"
  (the summer-schedule date range, 15 junio–15 septiembre) — two adjacent
  but genuinely DISTINCT articles. Kept as two separate gold entries
  deliberately: per ADR-0034's article-level granularity, this is not a
  multi-motivo list to bundle (rule 3) and not a collision risk (they don't
  share a logical key's scope in the way that matters — they're just two
  real, separate provisions on two real, separate articles) — a good check
  that the model doesn't either wrongly merge them into one fact or wrongly
  drop the second one as "redundant" with the first.

## No preemptive disambiguation rule added before this eval

Unlike D2's preaviso rule (explicitly preemptive, per Pedram's own
decision), this eval follows this sprint's default, REACTIVE methodology
(the same one permisos and vacaciones both used): run first against the
existing rule set (rule 3's cross-passage-merge already generalized, no
jornada-specific rule yet), let real failures — if any — name the fix,
same as every prior topic this sprint.
