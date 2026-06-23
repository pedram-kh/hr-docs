# ADR-0013 — Chunking & bilingual extraction for retrieval

**Status:** accepted

## Context
Sprint 2a turns Sprint-1's ingested prose documents into retrievable vector chunks. Reading the real corpus — the Gipuzkoa *Limpieza* convenio, a 33-page bilingual *Boletín Oficial de Gipuzkoa* (BOG) publication — surfaced three facts that shape how chunking must work:

1. **Intra-word spacing artifact.** PyMuPDF extraction of the justified two-column BOG layout produces pervasive split tokens: `hi tzar men` for `hitzarmen`, `Gi puz koa` for `Gipuzkoa`, `an tzi natasun` for `antzinatasun`, and on the Spanish side `rea li za do`, `de sa rro llo`, `kon tsul ta`. Embedding this directly tokenises to garbage and craters retrieval quality.
2. **Parallel two-column bilingual layout.** Bilingual convenios run Euskara (left) and Spanish (right) in parallel. The Sprint-1 per-page extraction linearises each page as the full Euskara column followed by the full Spanish column. ADR-0006 forbids blending `eu`+`es` in a single chunk, and `documents.language` is `es` for every document (Sprint 1), so the chunker **cannot** rely on the language field to keep chunks clean.
3. **Clean article boundaries.** `N. artikulua.` (eu) and `Artículo N.º` (es) are consistent throughout — a natural chunk boundary once languages are separated. Article lengths vary widely (Art. 9 *licencias* is long; Art. 1–5 are short).

## Decision
- **Article-aware chunking with a size cap.** Split on article boundaries; sub-split oversized articles; pack tiny ones. Record `page_from`/`page_to` and `chunk_index`. *(Superseded in part by **ADR-0017** (Sprint 2c): the **"pack tiny ones"** clause is removed — cross-article packing buried a short governing article and is the cause of the 2b-2 Correction-03 artifact. The article-boundary detector and language separation here remain in force; only the packing changes.)*
- **Separate languages before chunking by re-extracting the original PDF column-aware in `hr-ai`** (using PyMuPDF block bounding boxes to distinguish left/right columns), **not** by post-hoc splitting the stored linear page text. Every chunk is single-language; `eu` and `es` are never blended (ADR-0006).
- **Normalize the spacing artifact on the text that is embedded and stored as `document_chunks.content`.** The Sprint-1 `document_pages` text + page images are left untouched and remain the authoritative citation surface — so normalization only has to be good enough to embed well, not to perfectly reconstruct display prose.
- **Embed `active` *and* `historical` documents** (carrying `retrieval_status` on each chunk so 2b's eligibility filter decides current-vs-time-scoped). **Exclude `draft` and any `under_review`** (conflicted/unresolved scope) documents from embedding — their denormalized scope is not yet trustworthy, and scope drives eligibility.
- **Embedding is BGE-M3 / `vector(1024)`** (ADR-0006), confirmed by a retrieval sanity test on real ES + EU chunks as the first `hr-ai` task before any bulk embed.
- **`hr-ai` writes `document_chunks` directly** (ADR-0010); **`hr-backend` resolves and passes each document's scope** so the denormalized scope columns are populated from the system of record (ADR-0007).

## Consequences
- Retrieval quality is protected against the two failure modes the real corpus exposed: the BOG typesetting artifact and language-blended chunks.
- The chunker re-reads originals from S3 rather than trusting stored page text — one extra extraction pass on the embedding path (acceptable; embedding is a background admin action, not a latency-critical employee path).
- Excluding `under_review` from embedding slightly tightens the read-path rule in `data-model.md` §11 (which keys only on `retrieval_status`); data-model.md is updated to record that **embedding additionally requires `tagging_status ≠ under_review`**. Whether 2b further gates on `verified`-only is a separate 2b policy decision.
- Normalization is heuristic; surviving edge cases are a known, bounded quality risk, mitigated by eyeballing real chunk boundaries before authorizing the bulk embed (the Sprint-1 discipline).
