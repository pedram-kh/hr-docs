# Sprint 7e — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread. Inspect, plan, and **stop** — no OCR code, no engine chosen, until the plan is reviewed. (Housekeeping first: `main` has two committed-but-undeployed fixes — `otp.sh` and `FenceCalibrateSemantic` — run `deploy.sh` on main so staging is current, confirm health, then start.)

---

You are in the `hr-platform` workspace. Sprints 0–7d are merged on `main` and deployed to staging (the real corpus lives there: 98 docs, 3,364 chunks, 109 salary rows). **This is Sprint 7e — OCR for scanned documents.** Read `roadmap.md` Sprint 7e, `deploy.md` §4b/§5, and `hr-docs/sprints/sprint-07e/spec.md`.

7e adds **one fallback at the extraction step**: when `/extract` finds a page with no text layer, OCR it, then hand the text to the **existing** post-extraction pipeline unchanged. **The engine is chosen by a measured eval on the real bilingual two-column scans — the eval is the first deliverable.** OCR'd documents stay `under_review` until a human verifies. Nothing downstream (chunker, embed, answer loop) changes.

Before anything, read in full and cite real lines:
- **`hr-ai /extract`** — the PyMuPDF text-layer extraction, how `document_pages` rows are written, how the **per-page images** are rendered/stored to S3 (the viewer uses them — OCR can reuse them), and the **empty-page / `no_extractable_text` handling** (7a). Confirm exactly where "this page has no text" is detectable.
- **The post-extraction pipeline that must be reused unchanged** (2a/2c): geometry de-spacing, repetition/margin-band furniture stripping, the **positive-evidence two-column detection**, the **Spanish-function-word language gate**, es/eu tagging — where each runs and what input shape it expects (so OCR output can be fed in at the same point as text-layer output).
- The **embedding gate** (`tagging_status != under_review`, `ChunksEmbed`), the Sprint-3 confirm action, the `∅ No text` badge, `reviews:scan-expiry` — the pieces the "inert until verified" OCR gate and the Knowledge-Center OCR badge reuse.
- The **provider/key path** (ADR-0015 — `claude.py`, the per-call key) in case Claude vision is a candidate; the hr-ai `requirements`/Dockerfile (what adding Tesseract + `spa`/`eus` traineddata would mean for the image); the AWS account is `eu-west-1` (Textract available).
- **The real scans on staging:** identify every document with `∅ No text` / 0 pages of text — at minimum ids 89, 91 and COEAS Estatal (`99100055012011`). Pull 3–5 representative page images to the fixtures folder (two-column es/eu pages especially). **Do not OCR them yet.**
- ADR-0007/0010/0017/0020; the 7c golden-trace regression (must stay green).

Your task this turn: **inspect the real extraction path and the real scans, and plan — no OCR code, no engine decision.**

Produce `hr-docs/sprints/sprint-07e/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check).** The real `/extract` flow with line cites; where empty pages are detected; how page images are produced (format, DPI, storage key); the post-extraction pipeline's entry point + input shape; the embedding gate. **The inventory of scanned documents on staging** (id, title, convenio, pages, current status) and which fixture pages you picked and why (cover: two-column es/eu ×2, single-column, table/annex, low-quality).
2. **The eval design.** The gold-set format (hand-transcribed, es and eu as separate streams), `score_ocr.py` (CER/WER, **column integrity %**, article-header survival, cost/page, sec/page), the candidate engines and how each is wired for the eval only (Claude vision via the provider; Tesseract + layout modes + `spa`/`eus`; Textract optional), and the **decision rule** (column integrity + header survival first; CER acceptable; cheaper if close). How Pedram hand-transcribes the gold pages (or how you produce a first draft for him to correct — state which).
3. **The integration (narrow).** Where the OCR fallback hooks into `/extract` (page-by-page, text-less pages only); how OCR output enters the unchanged post-extraction pipeline; the additive `document_pages.extraction_source` + document-level OCR provenance; the **under_review-until-verified** gate reusing the existing embedding gate + confirm; the deterministic per-page quality score; the `--ocr` opt-in flag + page cap; synchronous vs queued (decide by expected latency/cost).
4. **Backfill + the three targets.** `documents:ocr-backfill` (find `∅ No text` → OCR → report pages/quality/cost → leave `under_review`), the verify step, embed, and the acceptance check per target (chunks > 0, in retrieval, a scoped cited answer).
5. **Additivity.** What changes (extraction + provenance + badge + backfill) and what does **not** (chunker, embed, router/synthesis/ground, salary/fact paths); the golden-trace regression as the gate. hr-ai never migrates; the additive migration lives in hr-backend.
6. **Migrations & build order** (eval → engine decision → integration → provenance/badge → backfill on staging → docs), the **ADR-0026**, and whether OCR runs on the `t3.large` or needs the resize (CPU Tesseract vs API-bound vision).
7. **Assumptions & open questions** — esp. gold-transcription effort, whether Textract is worth wiring, the quality-score heuristic, per-page cost if vision wins, and anything the real `/extract` code makes non-obvious.

Hard constraints:
- **Eval first; the engine decision follows the numbers** (recorded in the ADR). Column integrity beats CER.
- **OCR only fills text-less pages; output enters the existing post-extraction pipeline unchanged.** No LLM "cleanup" of OCR text.
- **OCR'd docs are `under_review` + unembedded until a human verifies** (ADR-0020). Provenance visible (badge, per-page source, viewer/citation marker).
- **Answer loop, chunker, embed untouched** (golden-trace green). **hr-ai never migrates** (ADR-0007). **`--ocr` opt-in; page cap.**
- This is a **feature sprint** — the normal gate applies: plan → review → build → review → commit. No direct-to-main commits.

Do not create or modify any file other than `hr-docs/sprints/sprint-07e/plan.md` (plus copying fixture page images into `sprint-07e/eval/fixtures/`) this turn. After writing it, stop and say it is ready for review.
