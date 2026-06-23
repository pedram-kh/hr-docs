# Re-chunk task — article-boundary chunking of active prose convenios

> Location: `hr-docs/sprints/sprint-02c-rechunk/spec.md`
> **Fresh Cursor thread** (substrate task — touches the chunker + re-embeds; do not run in the 2b-2 answer-loop thread).
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `architecture.md` (ingestion/chunking), **the 2a review + 2a Correction-01** (furniture-stripping, two-column positive-evidence detection, Spanish-function-word language gate, repetition-across-pages furniture, bilingual Gipuzkoa column split, idempotent re-embed), **2b-2 review §15 / Correction-03** (the baseline-over-convenio finding: Navarra grant chunk 7721 "37 días laborables" buried at a chunk tail, ranked #15, and the precedence re-rank that is its *interim* compensation), `roadmap.md` §7 (this task, parked), ADR-0006/0007, and the `hr-ai` AGENTS.md.
> **2b-2 is committed.** This task runs *after* it, never under an uncommitted answer loop.

## Goal
Fix the **durable root cause** behind Correction-03 at the source. Today's chunking is size-packed: it fills a chunk until "full," so an article boundary can fall mid-chunk and a governing grant ends up buried at a chunk tail (Navarra "37 días laborables" sat at the tail of a chunk whose body was about part-time hours → it embedded weakly against a vacaciones query → ranked #15 → synthesis answered from the Estatuto baseline). **Article-boundary chunking** makes each `Artículo N.º` its own clean chunk, so the governing grant retrieves strongly on its own merits — removing the precedence re-rank's load-bearing role and closing the Correction-03 residual (terse "¿qué vacaciones tengo?" still ~60% safe-escalate). Scoped to **active prose convenios**, with a re-embed, the 2a-style regression gate, and the bound terse-vacaciones acceptance gate.

## In scope

### A. Survey first — catalogue real article-header formats (step one, non-negotiable)
Before any splitter is designed, **inspect the actual corpus** and report the real article-header variants across the **active prose convenios**: the spellings and numbering styles in use ("Artículo 9.º", "Art. 9", "ARTÍCULO NOVENO", "Artículo noveno", roman/ordinal/cardinal), sub-article markers ("9.1", "§9.3", "9.bis"), **bilingual Basque headers** on the Gipuzkoa docs (e.g. "9. artikulua"), and the **article-length distribution** (how long is the longest article — does any single article exceed a sane chunk size?). Deliver the catalogue **before** designing the splitter. This is the corpus-inspection-first discipline that has caught every substrate bug so far (furniture-stripping, the bilingual split, the buried grant itself) — the splitter must be built against real patterns, not assumed ones.

### B. Article-boundary chunker (layered on top of the 2a pipeline, not replacing it)
- Start a new chunk at each detected article header; keep the header with its body; group an article's sub-clauses (9.1, §9.3, …) **with their article** unless the article exceeds the size cap.
- **Size fallback:** an article longer than the chunk cap is sub-split on a **sub-clause/paragraph boundary** (never mid-sentence), each sub-chunk retaining the article reference so it still resolves to its article.
- **Preserve every 2a win:** furniture stripping, two-column positive-evidence detection, the Spanish-function-word language gate, repetition-across-pages furniture detection, the **bilingual Gipuzkoa column split**, and language tagging all still apply. Article-boundary splitting **composes with** the column split — it must not double-split or mis-split the bilingual docs (the trickiest interaction; verify explicitly).

### C. Scope — active prose convenios only
- **In:** the active prose convenios employees get answers from.
- **Out (do NOT touch):** historical / `Antiguo` editions, **salary-table PDFs** (salary is SQL — chunk quality is irrelevant), the parked **wage-table-chunk cleanup**, and the ignored `CONVENIOS 2026.xls` / `__MACOSX/` cruft.
- Cursor determines the in-scope document list from the registry/corpus and **states it explicitly** in the plan (Claude verifies it against the active set).

### D. Re-chunk + re-embed
- Re-chunk the in-scope convenios on article boundaries and **re-embed** them (idempotent re-embed per 2a — re-running must not duplicate). Other documents' chunks are untouched.

## Out of scope
- Any **answer-loop** change (router, retrieval union, precedence re-rank, grounding, synthesis) — this is **substrate only**. (The re-rank stays as-is this task; see the de-crutch *check* below — verify, don't remove.)
- Salary-table PDFs / the wage-table cleanup (parked). Historical/`Antiguo`. Sprint 3+ work. No migrations (`hr-ai` writes only `document_chunks` + S3).

## Acceptance criteria
1. **Survey catalogue delivered**, and the splitter demonstrably handles **every** surveyed header variant (incl. the Basque headers).
2. Active prose convenios **re-chunked on article boundaries and re-embedded**; the in-scope list is explicit; historical / salary / parked items **untouched**.
3. **2a regression intact:** no body-text loss (no recurrence of the furniture-stripping over-delete); the Gipuzkoa bilingual split still holds (`eu` chunks only on the four genuine bilingual docs); chunk counts sane (expect **more** chunks — articles are smaller units — but no collapse or explosion that signals mis-splitting); the 2a sanity flags behave.
4. **The bound acceptance gate (the reason this task is gated):** terse "**¿qué vacaciones tengo?**" (Navarra) now answers **reliably** — cited to the **clean vacaciones chunk**, **0 baseline**, materially better than the Correction-03 ~60%-escalate residual (target: consistently answers across ≥4/5 runs, never the Estatuto baseline). Navarra Art. 9 still → **37 días laborables**.
5. **Re-rank de-crutch check:** confirm the clean vacaciones chunk now retrieves **into the synthesis cap on its own merits** — i.e. the answer is correct because the chunk is clean, **not** because the precedence re-rank is compensating (verify the convenio chunk ranks well *before* the boost; `rerank.boosted` is no longer what's saving Navarra vacaciones). The re-rank **stays** as defense-in-depth; this only verifies it's no longer load-bearing for this case.
6. **No answer-loop regression:** the three gold tests (Gipuzkoa 31/26, Navarra periodo de prueba 15/30, trabajo a distancia → `national_law`) and the round-2 PASS set (scope isolation, silent fallback, salary exact-from-SQL, sensitive→escalate) all still hold against the re-chunked corpus.

## Eyes-on
Pedram, on the running app: ask "¿qué vacaciones tengo?" (Navarra) **several times** → consistently **37, cited to the clean vacaciones chunk**, no escalation flapping; spot-check Gipuzkoa **31/26** (scope still isolated after re-chunk), trabajo a distancia (silent fallback intact), and one salary question (unaffected — SQL). Confirm the FUENTES citations point at sensible, clean article chunks.

## Risks / notes
- **Long articles** → the size-fallback sub-split must trigger on a sub-clause boundary; the survey's length distribution informs the cap.
- **Bilingual interaction** (Gipuzkoa) is the highest-risk seam — article split × column split. Verify the four bilingual docs explicitly; a mis-split here would regress the hard-won 2a bilingual work.
- **Re-embed cost** — a multi-hour CPU run like 2a; plan for it; idempotent so it can resume.
- This task **completes 2b durably** — until its acceptance gate passes, 2b is "committed but not durably closed."

## Definition of done
All criteria pass; Pedram eyes-on; docs updated in the same change — `architecture.md` (the article-boundary chunking strategy + that it composes with the 2a pipeline), a new **ADR-0017** (article-boundary chunking — the durable fix; the precedence re-rank reclassified from interim-load-bearing to defense-in-depth), the 2a ingestion notes, `roadmap.md` (§7 re-chunk **done**; the Correction-03 residual **closed**), and the `hr-ai` README. Cursor writes `hr-docs/sprints/sprint-02c-rechunk/review.md` and **stops — no commit until I review** (against the real re-chunked corpus: the survey catalogue, the bilingual docs, the terse-vacaciones gate, and the de-crutch check).
