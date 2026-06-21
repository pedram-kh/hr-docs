# Sprint 1 — Cursor build-authorization prompt

> Paste the block below into Cursor with the `hr-platform/` workspace open. This authorizes the **build** (the plan was reviewed and approved). All open questions from `plan.md` §9 are resolved below; build per the §1 order, update the named docs in the same change, write `review.md`, and stop.

---

The Sprint 1 plan (`hr-docs/sprints/sprint-01/plan.md`) is approved. Build it now, in the build order of plan §1. All `plan.md` §9 questions are resolved as follows — apply these exactly. Also read the new **ADR-0011** before building (it affects the review-task fields).

**Resolved decisions (from §9):**
1. **Idempotency / content hash (Q5):** add `documents.content_hash varchar NULL`, indexed; use sha256 of the file bytes as the primary idempotency key (filename+convenio as fallback). Update `data-model.md`.
2. **Validity dates (Q6):** filename-derived dates are provisional: start year → `YYYY-01-01`, end year → `YYYY-12-31`. Record as a provisional assumption.
3. **Language (Q7):** default `language = es` for every document this sprint. **Note in `data-model.md`** that some documents are bilingual (Euskara + Spanish in parallel columns, e.g. Gipuzkoa bulletin convenios); all have full Spanish text, language is metadata only and never a retrieval filter (ADR-0006). Do not attempt to split or detect `eu` this sprint.
4. **National docs / Estatuto (Q8):** the `ESTATAL` folder holds national docs (`99` prefix). The Estatuto (`ESTATUTO TRABAJADORES_*.pdf`) is a no-numero national-law doc: `authority_level = national_law`, `convenio_id = NULL`, `level = national`. **A missing convenio number on a recognized national-law doc is NOT a conflict.**
5. **Scanned PDFs (Q9):** image-only pages yield empty text; no OCR this sprint. Page images are still stored. **A document whose extracted text is entirely empty must be VISIBLY flagged** (e.g. an `empty_text` marker surfaced in the admin list), so an unreadable scan is never mistaken for a clean ingest.
6. **Service auth (Q10):** add a shared `X-Internal-Token` header between `hr-backend` and `hr-ai`, value from env on both sides; localhost in dev.
7. **Conflict provenance (Q11):** on any conflict, write a `source = system` `tag_events` row on the conflicting facet, in addition to the `document_review_tasks` row.

**Registry file (Q1–Q4):** import only `01_listado_convenios.xlsx` (at `hr-backend/data/`). Read the real sheet: confirm the exact sheet name and header strings and parse by header name. **Prefer reading a scope/level column directly over inferring level from the numero prefix.** Confirm that Andalucía COEAS resolves to a **regional** territory (not a province), and report what you found in `review.md`. **Do NOT import `CONVENIOS 2026.xls`** — it is a human status note (convenio name + free-text 2026 status), not a registry; it has no numbers, provinces, or structure. Ignore it this sprint.

**Real-corpus catches (apply to parser + import):**
8. **`Antiguo` subfolder → `retrieval_status = historical`** for everything under it (still ingested, never current).
9. **Validity appears in two filename formats:** handle both `YYYYYYYY` (e.g. `20232026`) and `YYYY_YYYY` (e.g. `2024_2026`).
10. **Territory names are Basque in filenames, Spanish in folders.** The registry import must populate each Basque territory's `aliases` with BOTH spellings — at least **Bizkaia/Vizcaya, Gipuzkoa/Guipúzcoa, Araba/Álava** — and the parser's normalization must match against `aliases`. Without this, every Basque-province file (filename `Bizkaia`, folder `VIZCAYA`) would false-conflict. Apply the same alias approach to any sector spelling/language variants found in the sheet.
11. **`Plan_Igualdad_texto.pdf`** (PDF, no numero) lands in `under_review` as designed — do not special-case it. Salary `.xlsx` and `.docx` files are skipped (PDF-only).

**Forward-looking review-task fields (ADR-0011) — build these now, even though the LLM tier is deferred:**
12. The `document_review_tasks` row must record a **`reason`** enum: `unresolved` (parser had nothing to resolve — LLM-eligible later) vs `conflict` (resolved-but-contradicts-registry — human-adjudicated). Set it correctly per §6 of the plan.
13. The review task must also store the **raw unmatched value(s)** the parser couldn't resolve (the literal string), so a human (or the future agent) can later decide "fold into an existing value's aliases" vs "propose a new vocabulary value." Update `data-model.md` for both fields.

**Eyes-on test step (do this as part of self-verification and report in `review.md`):** run ingestion against a deliberately mixed sample that includes at least: a Basque-province bilingual file, a file under `Antiguo`, the no-numero `Plan_Igualdad` PDF, a national `ESTATAL` file, and a salary `.xlsx` (which must be skipped). Confirm the Basque file tags cleanly (no false territory conflict), the `Antiguo` file is `historical`, the no-numero PDF is `under_review` with `reason = unresolved`, the national file tags as `national`, and the xlsx is skipped. Report the per-file outcome.

Hard constraints (unchanged): `hr-backend` owns all migrations and DB writes; `hr-ai` gains only `/extract`, writes only to S3, never the DB, never migrates (ADR-0010, ADR-0007). Tags are FKs into controlled vocabulary; the parser never creates vocabulary values (ADR-0002). Conflict beats confidence. Build nothing on the plan's out-of-scope list. PDF only.

When done: update `data-model.md` (territories restructure + the three FK renames + `content_hash` + the two review-task fields + the deferred-precedence and bilingual notes), update the READMEs, write `hr-docs/sprints/sprint-01/review.md` documenting what you built, the registry/Andalucía findings, the eyes-on sample outcomes, and any forced doc changes — then stop. Do not commit until I review.

---

After Cursor builds and writes `review.md`, paste it back here. I'll review against the acceptance criteria, you do your own eyes-on test, then we commit and move to Sprint 2.
