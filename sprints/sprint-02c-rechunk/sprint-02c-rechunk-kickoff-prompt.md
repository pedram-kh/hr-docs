# Re-chunk task — Cursor kickoff prompt (plan-gate)

> Paste the block below into a **fresh** Cursor thread (the `hr-platform/` workspace) — **not** the 2b-2 thread. It must **survey, plan, and stop** — no chunker code, no re-embed, until the plan + survey catalogue are reviewed.

---

You are in the `hr-platform` workspace (`hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs`). Sprints 0–2b-2 are built and committed. This is the **article-boundary re-chunk task** — a **substrate** task in `hr-ai` (chunker + re-embed), the durable fix for the buried-grant artifact behind 2b-2 Correction-03.

Before doing anything, read in full:
- `hr-docs/architecture/architecture.md` (the ingestion/chunking pipeline)
- **the Sprint 2a review + 2a Correction-01** — the furniture-stripping over-delete and its fix (two-column **positive-evidence** detection, the Spanish-function-word language gate, repetition-across-pages furniture detection), the **bilingual Gipuzkoa column split**, language tagging, and the **idempotent re-embed**
- **`hr-docs/sprints/sprint-02b-2/review.md` §15 + Correction-03** — the baseline-over-convenio finding: the Navarra grant chunk **7721** ("37 días laborables") buried at a chunk **tail** (body = part-time hours), ranked **#15**, and the **precedence re-rank** that is its *interim* compensation
- `hr-docs/sprints/sprint-02c-rechunk/spec.md`
- `hr-docs/roadmap.md` §7, ADR-0006/0007, and the `hr-ai` AGENTS.md

Your task this turn is to **survey the corpus and plan — write no chunker code and run no re-embed.**

Produce a single file `hr-docs/sprints/sprint-02c-rechunk/plan.md`, then **STOP and wait for review.**

**§1 — Article-header survey (do this first; it drives everything below).** Inspect the **actual** active prose convenios and report:
- every **article-header variant** in real use — spellings/casing/numbering ("Artículo 9.º", "Art. 9", "ARTÍCULO NOVENO", "Artículo noveno", roman vs ordinal vs cardinal), and **sub-article markers** ("9.1", "§9.3", "9 bis")
- the **Basque headers** on the four bilingual Gipuzkoa docs (e.g. "9. artikulua") and how they sit relative to the Spanish header in the two-column layout
- the **article-length distribution** — the longest article, and how many articles would exceed a sane chunk cap (this sets the size-fallback threshold)
- any **OCR/extraction noise** in headers that would defeat naïve detection
- **Veto check:** if the catalogue shows headers are too inconsistent or garbled to split reliably, **STOP after §1 and flag it with examples** — do not force a splitter onto a corpus that can't support it.

If the catalogue is workable, continue:

**§2 — Splitter design, mapped to the survey.** Propose the article-boundary chunker, and for **each** surveyed header variant show how it's detected and handled. Specify the **size fallback** (a too-long article sub-splits on a **sub-clause/paragraph** boundary, never mid-sentence, each sub-chunk retaining its article reference) with the cap justified by §1's length data.

**§3 — Composition with the 2a pipeline.** How article-boundary splitting **layers on top of** (does not replace) furniture stripping, the positive-evidence two-column detection, the language gate, repetition-furniture detection, and especially the **bilingual Gipuzkoa column split** — with explicit attention to the article-split × column-split interaction (the highest-risk seam; how you avoid double-/mis-splitting the four bilingual docs).

**§4 — In-scope document list (explicit).** The exact active prose convenios to re-chunk, derived from the registry/corpus — and the explicit exclusions (**historical / `Antiguo`**, **salary-table PDFs**, the parked **wage-table cleanup**, `CONVENIOS 2026.xls` / `__MACOSX/`).

**§5 — Re-embed + regression approach.** The idempotent, resumable re-embed (per 2a); and the **2a-style regression gate** you'll run: no body-text loss (no furniture over-delete recurrence), the bilingual split still holds (`eu` only on the four genuine bilingual docs), chunk counts sane (more chunks expected — articles are smaller — but no collapse/explosion), the 2a sanity flags behave.

**§6 — Acceptance + de-crutch verification.** How you'll prove (a) the **bound acceptance gate**: terse "¿qué vacaciones tengo?" (Navarra) now answers **reliably**, cited to the **clean vacaciones chunk**, 0 baseline, ≥4/5 runs (closing the Correction-03 residual); Navarra Art. 9 still → 37 laborables; and (b) the **de-crutch check**: the clean convenio chunk now ranks **into the synthesis cap on its own merits** (correct *before* the precedence boost — `rerank.boosted` no longer what's saving Navarra vacaciones). The re-rank **stays** (defense-in-depth); you only verify it's no longer load-bearing here.

**§7 — Assumptions & questions.** The size cap; how you'll identify the active prose set from the registry; the re-embed runtime/cost; anything the survey leaves ambiguous.

Hard constraints:
- **Substrate only.** Change **nothing** in the answer loop (router, retrieval union, **precedence re-rank**, grounding, synthesis). This task is the chunker + re-embed.
- **`hr-ai` writes only `document_chunks` + S3, never migrates** (ADR-0007). No `hr-backend` / `hr-frontend` changes.
- **Active prose convenios only** — never touch historical/`Antiguo`, salary-table PDFs, the parked wage-table cleanup, or the ignored cruft.
- **Preserve every 2a win** — furniture stripping, two-column positive-evidence detection, language gate, repetition-furniture detection, the bilingual column split, language tagging.
- **Plan only — no chunker code, no re-embed this turn.** The multi-hour re-embed runs only after the splitter is reviewed against the survey catalogue.

Do not create or modify any file other than `hr-docs/sprints/sprint-02c-rechunk/plan.md` this turn. After writing it, stop and say it is ready for review.
