# Sprint 7e — OCR for scanned documents

> Location: `hr-docs/sprints/sprint-07e/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram (on staging)
> Read first: **ADR-0010 + the 2a/2c extraction front-end** in `architecture.md` §5 — `hr-ai POST /extract` (PyMuPDF text layer → `document_pages`), then the **post-extraction pipeline that runs before chunking and must be reused unchanged**: geometry de-spacing, repetition/margin-band furniture stripping, **positive-evidence two-column detection**, the **Spanish-function-word language gate**, es/eu language tagging; the `no_extractable_text` skip posture (7a); the `∅ No text` Knowledge-Center badge; `deploy.md` §4b/§5 (the three named scans); ADR-0007 (hr-ai writes only `document_pages`/`document_chunks` + S3, never migrates), ADR-0015 (the answer-model key path, if Claude vision is chosen), ADR-0020 (inert until verified); `roadmap.md` Sprint 7e.
> **A format problem, kept narrow.** A scan has no text layer; everything downstream (tag → chunk → embed → answer) already works on text. 7e adds **one fallback at the extraction step**: when `/extract` finds a page with no extractable text, OCR it to produce the text layer, then hand the result to the **existing** post-extraction pipeline. OCR does not tag, chunk, embed, or answer. Nothing in the answer loop changes.
> **The engine is chosen by measurement, not preference** (the 7b-2 discipline). The corpus's hard case is **two-column Spanish/Basque scans** that naive OCR garbles or merges. The sprint's first deliverable is an **eval** on the real scans against a hand-transcribed gold set; the engine decision follows the numbers.

## Goal
Make scanned, image-only PDFs answerable through the normal pipeline by producing their missing text layer at extraction time — with the engine selected by a measured eval on the real bilingual scans, the OCR'd text flowing through the **unchanged** two-column/language/furniture pipeline, and OCR'd documents marked as such and **held `under_review` until a human verifies** (a garbled OCR must never silently become a confident answer). Acceptance target: the three named unanswerable active convenios become answerable.

## The named targets (acceptance)
- `CONVENIO DEPORTE NAVARRA 2025–2028` (id 89)
- `PACTO CULTURA NAVARRA` (id 91)
- `COEAS Estatal` (`numero` 99100055012011 — surfaced by the 7d calibration as active, `eligible: 0`)
Plus any other `∅ No text` document `reviews:scan-expiry` / the Knowledge Center surfaces. After 7e: chunks > 0, in the retrieval set, a scoped test employee gets a cited answer.

## In scope

### A. The eval first (the engine decision — build this before the integration)
- **Fixtures:** 3–5 real scanned pages chosen to cover the hard cases — at least two **two-column es/eu pages**, one single-column es page, one table/annex page, one low-quality page. Store under `sprint-07e/eval/fixtures/`.
- **Gold set:** a **hand-transcribed** reference text per page (`gold/`), with the two columns kept as separate es and eu streams (that is what the language gate expects downstream).
- **Candidates:** (1) **Claude vision** (the existing pluggable provider + ADR-0015 key path; page image in, text out — layout-aware); (2) **Tesseract with layout analysis** (`--psm` modes / column segmentation; `spa`+`eus` traineddata); optionally (3) a cloud OCR (AWS Textract — already in-account, eu-west-1 — layout-aware, per-page cost). Run each on the fixtures.
- **Scoring (`score_ocr.py`, stdlib):** per page and per engine — **character error rate** and **word error rate** vs gold; **column integrity** (was the es stream kept separate from the eu stream, or merged/interleaved? scored as a % of lines correctly assigned); **article-header survival** (do `Artículo N.º` headers come through intact — the 2c detector is load-bearing on them); cost/page and seconds/page.
- **Decision rule (recorded in the ADR):** pick the engine with the best **column integrity + header survival** on the two-column pages, as long as CER is acceptable; if two are close, the cheaper/simpler one. **Column integrity beats raw CER** — a merged bilingual page is unusable downstream regardless of character accuracy.

### B. The integration (narrow, additive)
- In `hr-ai /extract`: when a page's PyMuPDF text is empty/whitespace **and** the page renders to an image, rasterize (the per-page images already exist for the viewer) → OCR (the chosen engine) → the OCR'd text becomes that page's `document_pages.text` → **then the existing post-extraction pipeline runs on it unchanged** (de-spacing, furniture, two-column detection, language gate, es/eu tagging → chunker). Text-layer pages are untouched (OCR only fills gaps, page by page — a mixed PDF gets OCR only where needed).
- **Provenance:** an additive `document_pages.extraction_source` (`text_layer` | `ocr`) + a document-level `ocr_pages_count` (or a derived flag) so the Knowledge Center shows an **OCR badge** and the citation/viewer can show "texto obtenido por OCR". Auditor-visible.
- **Inert until verified (ADR-0020 spine):** an OCR'd document lands / stays **`under_review`** (the same gate that keeps AI-tagged docs out of embedding) until a human confirms the text quality on the Knowledge-Center card (a "verify OCR" action beside the existing confirm — reuse the Sprint-3 confirm). Only then is it embedded and retrievable. A garbled scan must never silently reach an answer.
- **Confidence/quality signal:** per-page OCR confidence (engine-reported) + a cheap sanity check (Spanish/Basque function-word density from the existing gate; % non-alphanumeric garbage) → a **quality score** surfaced on the card; low-quality pages flagged for the human. Deterministic, no LLM judgment.
- **Cost/ops:** OCR runs inside `/extract` (or a queued job if per-page latency is large — the plan decides); if Claude vision is chosen, cost per page is logged; a per-document page cap + a `--ocr` opt-in flag on `documents:ingest-folder` so a bulk re-ingest never silently runs OCR on hundreds of pages.

### C. Backfill + the targets
- A command (`documents:ocr-backfill`) that finds `∅ No text` documents, runs the OCR path, reports per-document pages OCR'd / quality / cost, and leaves them `under_review`. Run on staging against the three named targets (+ any others found); a human (Pedram) verifies the OCR quality on the card; then embed; then the acceptance check (chunks > 0, in retrieval, a scoped answer).

## Out of scope
- Tagging, chunking, embedding, answering — all unchanged (OCR only produces text upstream of them). The 2c chunker and the answer loop are untouched (the 7c golden-trace regression stays green).
- Re-OCR of documents that *have* a text layer; handwriting; non-PDF images.
- Auto-verifying OCR output (a human verifies — the quality score assists, never decides).
- A generic document-AI/layout platform; multi-engine ensembles (pick one).

## Acceptance criteria
1. **The eval exists and decided the engine:** fixtures + hand-transcribed gold + `score_ocr.py`; per-engine CER/WER, **column integrity**, header survival, cost, time reported; the choice recorded in the ADR with the numbers.
2. `/extract` OCRs only text-less pages, page by page; OCR'd text flows through the **unchanged** post-extraction pipeline (two-column split + language gate proven on a real bilingual scan: es and eu chunks separate, `eu=N` non-zero where the scan is bilingual).
3. `document_pages.extraction_source` set per page; the Knowledge Center shows an **OCR badge** + per-page quality; citations/viewer mark OCR'd text.
4. **OCR'd documents are `under_review` and unembedded until a human verifies** (tested: 0 chunks before verify; embedded after).
5. Backfill run on staging: the **three named convenios** (89, 91, COEAS Estatal) have chunks > 0 after verify and a scoped test employee gets a cited answer; any further `∅ No text` docs listed.
6. hr-ai writes only `document_pages`/`document_chunks`/S3, **never migrates**; hr-backend owns the additive migration(s); the answer loop is byte-for-byte unchanged (golden-trace green); `--ocr` opt-in prevents silent bulk OCR.

## Eyes-on (on staging)
Run the backfill on the three targets → open each in the Knowledge Center → the **OCR badge** + per-page quality visible; open the viewer on a two-column page and compare the extracted es/eu text against the image — is it legible and are the columns separate? → **verify** one → it embeds (chunks > 0) → as a test employee in that scope, ask a question the convenio answers → a cited answer. Confirm a normal text-layer document ingests exactly as before (no OCR ran, `extraction_source = text_layer`). Prose + salary chat questions unchanged.

## Risks / notes
- **The two-column bilingual case is the whole difficulty.** An engine that merges the columns produces text that *looks* fine, embeds, and answers — with Spanish and Basque interleaved. That is why column integrity is the primary metric and why OCR output is human-verified before embedding.
- **Claude vision vs Tesseract is a cost/quality trade** — vision is likely far better on layout but costs per page (and uses the ADR-0015 key path); Tesseract is free but needs layout tuning. Let the eval decide; don't pre-commit.
- **Keep it upstream.** The temptation is to "fix" OCR text with an LLM cleanup pass — don't in 7e (that's generated content entering the citation surface; a separate, deliberate decision if ever).
- **Textract is in-account and layout-aware** — worth including in the eval if cheap to wire; not required.

## Definition of done
All criteria pass; Pedram eyes-on on staging (the three targets answerable); docs — `architecture.md` (the OCR fallback at extraction, provenance, the under_review gate, the engine + why), `data-model.md` (`extraction_source`, the OCR quality/provenance fields), `deploy.md` (the scans list resolved; the `--ocr` flag + cost note in the runbook), `roadmap.md` (7e DONE; 7f remains), **ADR-0026** (OCR as an extraction-time fallback; engine chosen by measured eval — the numbers; column-integrity-first; provenance + human-verified-before-embedding). Cursor writes `hr-docs/sprints/sprint-07e/review.md` (the eval table is the first section) and **stops — no commit until reviewed** (feature sprint: the normal gate, not the deploy-driven exception).
