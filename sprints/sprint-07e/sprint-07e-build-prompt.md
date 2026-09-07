# Sprint 7e — Cursor build-authorization prompt

> Paste into the Sprint 7e Cursor thread (the one that wrote `plan.md`). The plan is approved — its central finding (`/embed` never reads `document_pages.text`; it re-extracts from PDF bytes) is correct and shapes the integration; its two corrections (stale ids 89/91 → 31/50; COEAS Estatal is a tagging problem, not a scan) are verified against staging and accepted. This authorizes the **build, in the plan's §6.2 order, with one checkpoint**: the engine is chosen only after the eval runs against human-corrected gold. Feature-sprint gate: **no commit until reviewed.**

---

The Sprint 7e plan (`hr-docs/sprints/sprint-07e/plan.md`) is approved. Build it in the §6.2 order. Decisions below apply exactly.

## Resolved open questions

- **Q1 — gold transcription: draft-then-human-correct, with a hard checkpoint.** Draft all five fixture pages with the strongest available engine in the `columns[]` gold shape (es and eu as separate streams, `article_headers`, table `cells`). **Then STOP and hand the drafts to Pedram.** For the **two two-column fixtures** he corrects line-by-line against the image, explicitly verifying **column assignment and es/eu tagging** (the property the engine decision hinges on); for the other three a lighter pass. Only the human-corrected files are gold. **No engine is scored, and no engine is chosen, until Pedram says the gold is corrected.**
- **Q2 — Textract: time-boxed, conditional.** Wire and score it **only if** Claude vision and Tesseract don't cleanly bracket the decision (e.g. Tesseract fails column integrity and vision cost is a real concern). Otherwise skip; say so in the ADR.
- **Q3 — quality score: no second LLM call.** Engine-native confidence where it exists (Tesseract word confidences); otherwise deterministic signals only — the existing `_es_ratio` function-word density, a garbage-character ratio, text-length-vs-page-area. Stored per page, surfaced on the card, never decides anything.
- **Q4 — cost: measured in the eval, reported per page and projected for the ≤105-page backfill.** `--ocr` stays opt-in-off for future ingests regardless.
- **Q5** — all five noted. In particular: **reuse the already-rendered page pixmap/JPEG** at `extract.py:37-38`; never re-render.

## Step 1 — The eval (the engine decision)

1. `sprint-07e/eval/`: the five fixtures (already copied), `gold/` (drafts → corrected), `score_ocr.py` (stdlib: CER, WER, **column integrity %** as defined in §2.3, article-header survival, cost/page, sec/page).
2. Wire the candidates **for the eval only** (no integration yet): Claude vision via the existing provider + ADR-0015 key path (page image in → `columns[]` out, prompted to read columns and tag es/eu); Tesseract `--psm 1`/`3` with `spa`+`eus` (TSV/HOCR bboxes kept — Option A needs them); Textract per Q2.
3. **Draft the gold → ⏸ STOP for Pedram's correction → resume.**
4. Score every candidate against the corrected gold. Apply the §2.5 decision rule in order: **(1) column integrity + header survival — an engine that scrambles the bilingual columns is disqualified outright; (2) CER/WER among those that clear (1); (3) cost if close.** Record the full table and the choice in `review.md` §1 and ADR-0026. If the eval's per-page latency is large, that number (not a guess) decides sync-vs-queued (§3.6).

## Step 2 — The integration (narrow; shape follows the engine)

- **`/extract` fallback (§3.1):** page-by-page inside the existing loop, only when `text.strip() == ""` **and** `ocr=True` **and** within the page cap; run the chosen engine on the already-rendered image; the result populates `text` exactly as the native path would — `DocumentIngestor`'s `document_pages.text` write is untouched (this alone unblocks the viewer/citations and the 7a tag-proposal reader).
- **Reaching `/embed` (§3.2) — the S3 sidecar, option (ii):** `/extract` writes `documents/{uuid}/ocr/{page:04d}.json` (text + bboxes/column units) next to the page image, inside hr-ai's existing S3-write privilege; `build_chunks`/`extract_language_streams` probes for it **only** when a page's native block count is zero. **No `/embed` request/response change.** Then, by engine:
  - **Bbox engine (Tesseract/Textract) → Option A:** inject synthetic geometry blocks in PyMuPDF's normalized page-relative shape **before** Pass 2/3, so furniture stripping, the two-column detector, and the `_es_ratio` bilingual gate run **completely unmodified** on them.
  - **Claude vision → Option B:** append its already-column-split, already-language-tagged units straight into the `es`/`eu` accumulators before the final sort; accept the documented furniture-stripping delta.
  Every existing line in `_classify_page`, the furniture pass, and the bilingual gate stays untouched either way.
- **Provenance (§3.3, hr-backend additive migration):** `document_pages.extraction_source` (`text_layer`|`ocr`, default `text_layer`) + per-page `ocr_quality` + `ocr_engine`; a document-level derived `ocr_pages_count`. Knowledge-Center **OCR badge** + per-page quality on the card; the viewer/citation marks "texto obtenido por OCR".
- **Inert until verified (§1.4/ADR-0020):** an OCR'd document is/stays `under_review` → the existing embedding gate holds it at 0 chunks until the Sprint-3 confirm (a "verify OCR" affordance beside it). **Test:** OCR'd doc → 0 chunks before verify; embedded after.
- **`--ocr` opt-in flag on `documents:ingest-folder` (default off) + a per-document page cap** (config, e.g. 60) so a bulk re-ingest never silently OCRs hundreds of pages; every OCR'd page logs engine + cost.
- **The no-op invariant test (new):** a text-layer PDF ingests with `extraction_source = text_layer` on **every** page and **zero** OCR engine calls (assert via a counting fake) — the fallback provably never fires where it shouldn't. Plus `Sprint7cAdditivityRegressionTest` green (the answer loop is byte-for-byte unchanged).

## Step 3 — Backfill on staging + the targets

- `documents:ocr-backfill` (§4.1): find text-less documents (`pages > 0 AND pages_with_text = 0`), run the OCR path (respecting the cap), **then run the existing 7a tag re-suggest** (every one has `convenio_id = NULL` — OCR fixes text, not scope), report per document: pages OCR'd, mean/min quality, engine, cost; leave them `under_review`.
- **Targets (corrected — the live query is the source of truth):** active **doc 31 PACTO CULTURA NAVARRA**, **doc 50 CONVENIO DEPORTE NAVARRA 2025–2028**, **doc 14** (active, under_review); the six historical ones for completeness. **COEAS Estatal is NOT on this list** (full text already; a tagging-verification item).
- Run it on staging (resize per §6.3 only if the eval's latency number says so). Then **⏸ Pedram verifies** the OCR text on the cards (two-column pages compared against the image) and confirms scope → embed → **acceptance per target:** chunks > 0, in the retrieval set, a scoped test employee gets a **cited answer**. Report which targets pass and why any don't (scope unresolvable is a legitimate escalation, not a failure).
- Snapshot `hr-staging-post-7e`.

## Hard constraints (carry)
- **Eval first; engine chosen by the numbers, column integrity before CER; recorded in ADR-0026.**
- **OCR fills only text-less pages; output enters the existing post-extraction pipeline unchanged; no LLM "cleanup" of OCR text.**
- **OCR'd docs `under_review` + 0 chunks until a human verifies.** Provenance visible everywhere the text is.
- **Chunker, embed logic, router/synthesis/ground, salary/fact paths untouched** — golden-trace green + the no-op invariant. **hr-ai writes only `document_pages`/`document_chunks`/S3 (new sidecar keys only), never migrates.** The one additive migration is hr-backend's.
- **`--ocr` opt-in-off; page cap; cost logged.**
- **Feature-sprint gate:** plan → build → review → commit. **No direct-to-main commits.** Two ⏸ checkpoints for Pedram (gold correction; OCR verification on staging).

## Docs at close
**ADR-0026 — OCR as an extraction-time fallback**: the engine chosen and the eval numbers (column integrity/header survival/CER/cost per candidate); Option A vs B and why it followed the engine; the S3 sidecar (ii) and why `/embed`'s contract is unchanged; provenance; human-verified-before-embedding; the `/embed`-never-reads-`document_pages` fact; `--ocr` opt-in + cap. `architecture.md` (the fallback in §5, the sidecar, the badge, the gate). `data-model.md` (`extraction_source`, `ocr_quality`, `ocr_engine`, `ocr_pages_count`; the sidecar key). **`deploy.md` corrections:** the stale ids (89/91 → 31/50); COEAS Estatal **off** the OCR/scans list and left as the tagging item; the resolved targets; the `--ocr` flag + cost note in the runbook. `roadmap.md` (7e DONE; 7f remains). READMEs. Write `hr-docs/sprints/sprint-07e/review.md` — **the eval table is §1** — then the integration, the invariants, the backfill results per target, and Pedram's verification. Then **STOP — do not commit until I review.**
