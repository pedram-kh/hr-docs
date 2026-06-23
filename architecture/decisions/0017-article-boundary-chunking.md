# ADR-0017 — Article-boundary chunking (the durable buried-grant fix)

**Status:** accepted (Sprint 2c)
**Supersedes (in part):** ADR-0013 — the "pack tiny articles up to a token target" clause.
**Relates to:** ADR-0006 (BGE-M3 / salary-is-SQL), ADR-0007 (hr-ai writes only `document_chunks` + S3, never migrates), ADR-0013 (chunking & bilingual extraction), 2b-2 review §15 + Correction-03 (the interim precedence re-rank).

## Context

Sprint 2a chunked prose by **packing** consecutive articles up to a token
target (~400, cap 512). Packing was efficient but **mixed topics inside one
chunk**, and 2b-2 review §15 traced a concrete legal-weight failure to it:

- Navarra *Limpieza* (id 95) Art. 9.º **Vacaciones** grants **"37 días
  laborables"**. Under packing, that grant landed at the **tail** of a chunk
  whose body was a neighbouring article (Art. 8.ºbis, *horas complementarias* /
  part-time hours). Embedded as one vector, the chunk read mostly as "jornada/
  horas", so against *"¿qué vacaciones tengo?"* it ranked **~#15** — below the
  Estatuto's vacaciones baseline chunk ("30 días naturales", ranked ~#3), which
  then reached synthesis. The system answered the **national baseline for a
  convenio-governed topic** — the worst class of wrong answer in a *convenio-
  over-`national_law`* system.

2b-2 Correction-03 shipped an **interim** compensation — a widened-pool
**precedence re-rank** that lifts a governing convenio chunk above a same-topic
baseline before truncation. It made the answer correct, but it was **load-
bearing**: the right chunk only reached synthesis *because* the re-rank rescued
it from #15. The durable fix has to make the grant chunk **rank on its own
merits**, which means fixing the substrate — the chunk shapes — not the answer
loop.

## Decision

**Chunk on article boundaries: one chunk per article, and remove cross-article
packing.** A short governing article (Vacaciones) becomes its **own small,
topically-clean chunk** that embeds as "vacaciones" and retrieves on raw score.

1. **One chunk per detected article.** No packing of distinct articles into a
   shared chunk (the buried-grant cause).
2. **Size fallback only for genuinely long articles.** An article over
   `chunk_token_cap` is sub-split on a **sub-clause → paragraph → sentence**
   boundary (never mid-sentence), and **every** sub-chunk carries the article's
   `Artículo N.º <título>` header so it still resolves to — and cites as — its
   article. The **pre-Article-1 preamble** and **anchor-less annexes** keep the
   size-capped paragraph fallback.
3. **Cap, locked on real data.** `chunk_token_target` = **512**,
   `chunk_token_cap` = **800** tokens, measured with the **real BGE-M3
   tokenizer** on furniture-stripped streams over the active corpus (1,124
   article segments): median 164, p90 954, and the **800–1024 "true middle"
   band is only 1.8%** of articles, so 800 keeps ~89% of articles whole while
   sub-splitting only the disciplinary/classification/salud long-tail and the
   trailing-annex segments (which benefit from focused sub-chunks). Raising the
   cap to 1024 would spare only ~1.8% of articles one extra split, at the cost
   of larger, more diluted chunks — not worth it. (Full recompute in
   `sprint-02c-rechunk/review.md`.)
4. **The detector is load-bearing — three precision guards.** Packing used to
   forgive loose detection (a missed header just merged into a neighbour). With
   packing gone, a missed header **merges two articles** (re-burying a grant)
   and a false header **fragments** one, so detection precision matters
   directly. Three guards make a false positive structurally hard:
   - **Line-anchored** — a header must start its line (allowing indentation);
     an inline "…conforme al artículo 22 del Estatuto…" mid-paragraph cannot
     anchor.
   - **Case-aware** — real headers are capitalised (`Artículo` / `Art.` /
     `ART`); a lowercase candidate (`artículo`, `art.`) is rejected unless it
     also passes the number check.
   - **Monotonic-number** — a lowercase candidate is accepted only when its
     number is ≥ the running article number, so "artículo 22 del ET" appearing
     inside Art. 40 can never spawn a chunk (22 < 40).
   The detector covers the surveyed header variants: `Artículo N` · `Art. N.º` ·
   `Artículo N.—/–/-` · the **Salamanca uppercase `ART N.-`** · `N. artikulua`
   (Euskara) · `Disposición adicional/transitoria/final/derogatoria` · a
   defensive spelled-out clause (`ARTÍCULO PRIMERO…`). A decimal/letter
   **sub-clause** (`9.1`, `13.a`) is kept **with its parent** article; a **`bis`**
   header (`Art. 8.ºbis`) gets its **own** chunk.

5. **Substrate only — composition with 2a is exact.** The chunker
   (`hr-ai/app/chunking/chunker.py`) is the **only** stage that changes.
   `extract_columns.py` — geometry de-spacing, repetition/margin-band furniture
   stripping, positive-evidence two-column detection, the Spanish-function-word
   language gate, language tagging, and the over-strip sanity flags — is
   **untouched**; the chunker still receives one already-separated language
   stream at a time. The **answer loop is untouched** (router, retrieval union,
   precedence re-rank, grounding, synthesis). `hr-ai` still writes only
   `document_chunks` + S3 and never migrates (ADR-0007). Re-embed is the
   existing idempotent, resumable `chunks:embed` (per-document clean replace).

6. **Scope: convenio residual only.** Re-chunk the **registry-active prose
   convenios** (the registry is the arbiter, not folder placement). The
   **Estatuto** (national law, id 73) is **held out** — re-chunking the
   universal baseline perturbs the convenio-vs-baseline precedence balance just
   stabilised in 2b-2 (broad blast radius); it is tracked as a roadmap
   follow-up (Sprint 8) on this same proven splitter.

## Consequences

- **The grant ranks on its own merits.** Navarra Art. 9.º Vacaciones is now a
  clean chunk led by *"…disfrutará de 37 días laborables de vacaciones"* and
  reaches the synthesis cap on **raw score, before** the precedence boost.
- **The precedence re-rank is reclassified from interim load-bearing to
  defence-in-depth.** It stays (no answer-loop change), but it is no longer what
  saves Navarra vacaciones — verified by the de-crutch trace
  (`sprint-02c-rechunk/review.md`). This closes the Correction-03 residual.
- **Chunk counts shift, not collapse.** Removing packing makes more small
  chunks; raising the cap (512→800) makes fewer big-article sub-splits — net per
  doc varies either way. The regression watches the two real failure
  signatures: a **collapse** (detector missing headers → giant merged segments)
  and an **explosion** (false-positive over-splitting). Neither occurred.
- **No body-text loss.** The chunker is downstream of furniture stripping, so
  kept-block / furniture counts are **identical** to the committed 2a run.
- **A known residual:** the last article's segment absorbs trailing back-matter
  (`Disposición adicional` + annex/tables) where no further header anchors; the
  size fallback sub-splits it cleanly (no loss). Per-sub-disposition anchoring
  is a future nicety, not a grant-burying risk.
