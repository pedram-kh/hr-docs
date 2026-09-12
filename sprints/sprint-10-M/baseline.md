# Sprint 10-M — Baseline (Step 2)

**Model at capture time:** `claude-sonnet-4-5` on the single `HR_AI_ANSWER_MODEL` knob (governs `/synthesise` + `/ground`). `/route` + `/explain` on `claude-haiku-4-5` throughout (out of scope, unaffected by this sprint).

The post-merge state IS the baseline. Nothing was re-run for this file — per Step 2's own instruction, the post-10a-close results are reused verbatim because nothing material changed between the 10a close (2026-09-11) and this capture (2026-09-12): same code, same chunker (md5-verified), same migration state, same corpus.

## 1. Four 2c gold answer cases

Captured 2026-09-11T23:41:43Z, as part of Sprint 10a's post-deploy smoke check (deployed build, real loop, `gold-answer-run.php`).

| case | outcome | authority | citations | result |
|---|---|---|---|---|
| Navarra, periodo de prueba | answer | `official_convenio` | doc 36 only | 15 días / 30 días |
| Gipuzkoa, vacaciones | answer | `official_convenio` | doc 13 only | 31 días naturales / 26 laborables |
| Navarra, trabajo a distancia | answer | `national_law` | doc 75 only | Ley 10/2021 |
| Gipuzkoa, trabajo a distancia | answer | `national_law` | doc 75 only | Ley 10/2021 |

No `fallback` key on any of the four turns (expected — convenio-covered / silent-convenio national-law, not the Estatuto-gap path). No cost/latency was captured on this run (not the point of the 10a smoke test); Step 3 will capture it fresh on both models for a fair comparison.

## 2. Fallback positive set — `test-fullgap@` (convenio 7, `never_ingested`, 13 questions)

Captured during Sprint 10a's build phase (2026-09-11), `php artisan estatuto:gold-eval --profile=positive`.

- **10 of 13 answerable questions answered**, all grounded, all carrying the fallback caveat on the persisted row.
- All four article-37 questions answered (descanso semanal, matrimonio, fallecimiento, festivos).
- Both salary questions escalated `salary_coverage_gap`, no fallback key.
- **Q6** (despido objetivo / preaviso): sensitive-topic guardrail fires pre-router on every profile — correct behavior, not a fallback defect, not expected to move.
- **Q5** *"¿Cuánto preaviso tengo que dar si me voy?"* — escalates `low_confidence`. Per-claim grounding gate declines to entail. **Most likely to move under Sonnet 5.**
- **Q13** *"¿Cuánto dura el permiso por nacimiento?"* — escalates `low_confidence`, `grounded = false`. Same gate, same reason. **Most likely to move under Sonnet 5.**
- **Q4 is non-deterministic at the margin**: escalated on the first 10a run, answered on the second — honest baseline figure is **9–10/13**, not a fixed 10/13. This flap predates the model swap (three LLM calls in the loop); Step 3 should not read a Q4 change as caused by Sonnet 5 without a repeat run.
- Check-A top score across the set: min `0.5561`, median `0.6214`, max `0.7123` — retrieval-side, must NOT move under a synthesis/grounding model swap (retrieval is untouched by this sprint).

## 3. Negative sets — the trigger-split test (both 15 questions)

Captured during Sprint 10a's build phase (2026-09-11), `--profile=negative` / `--profile=second-negative`.

**Not one question was answered from the Estatuto on either profile, and no turn carried a fallback key.** This is the hard-requirement row this sprint must not move.

- `test-andalucia@` (convenio 4, `expired_only`, historical text *with* 151 chunks): 10 questions reached the fallback decision and refused it with `estatuto_fallback_gap`; 2 stopped at an earlier gate (Q6 guardrail; Q3/Q4 `reference_fact_coverage_gap` — convenio 4 has a verified periodo-de-prueba fact with no job-category scope, an unrelated pre-check).
- `test-midingest@` (convenio 16, `expired_only`, historical text with *zero* chunks): 12 reached the decision and refused it; 1 stopped earlier (Q6 guardrail).

## 4. What Step 3 must re-verify, not re-derive

- Hard requirements (from `prompt.md` Step 3.3): four gold figures byte-identical in substance/authority/citations; both negative sets stay 0 answered with no fallback key; every positive answer still grounded + caveated; salary still escalates.
- Observations: positive answer rate vs 9–10/13 baseline (Q5/Q13/Q4 specifically); answer length/latency; Check-A top-score distribution (must be unchanged — a mover here means something broke beyond the model, since retrieval is untouched).
- New this sprint (Pedram's CP-A follow-up): summed `cost_usd` before vs after (with the corrected `OCR_PRICING_PER_MTOK` table), per-question latency before vs after, and a truncation watch on every eval response (`grounding_truncated` / `retried_on_truncation` on `/ground`; any `parse_error` on `/synthesise`) — expected count is zero on both.
