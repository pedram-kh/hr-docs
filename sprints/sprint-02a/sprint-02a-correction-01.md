# Sprint 2a — Correction 01 (pre-commit): column/furniture heuristic over-stripping

> Paste the block below into Cursor (the Sprint 2a thread). The full-corpus embed surfaced a real problem at the eyes-on gate: the column-detection + furniture-stripping heuristic **deletes legitimate body text** on a subset of layouts (the Navarra cluster + Intervención Social Gipuzkoa) and **mis-splits monolingual Spanish into the `eu` stream** on index/TOC/table pages. The first is a silent-coverage-gap risk on **active** convenios and **blocks commit**; the second is low-severity but shares the root cause. One fix addresses both. Record the change in the named files, append to `review.md`, re-embed the affected docs, and **STOP — do not commit**.

---

Sprint 2a is approved **pending one pre-commit correction** found at the eyes-on gate. The column/furniture heuristic (`hr-ai/app/chunking/extract_columns.py` + the furniture logic) is **over-stripping real body text** on some layouts and **mis-routing monolingual Spanish into the `eu` stream**. Fix the root cause, re-verify, re-embed only the affected docs, record it, and stop.

**The problem (from `review.md` §9, confirmed at the gate):**
- The **Navarra cluster** and **Intervención Social Gipuzkoa (doc 45)** report **300–1207 furniture blocks stripped** against single-digit/teens chunk counts, nearly every page flagged — the heuristic is eating **legitimate body blocks**, not masthead/footer. These are **active, in-scope** convenios (Limpieza Navarra 2024–2027, COEAS Navarra, Intervención Social Navarra 2025), so this is **silent content loss**, not cosmetic.
- Monolingual-Spanish docs produce spurious `eu` chunks (Álava OCIO EDUCATIVO `es=87 eu=2`; also Estatuto 7, Andalucía 2, Valencia 3, Bizkaia 1) — index/TOC and `GRUPO/CATEGORÍA` table pages (two visual columns of short lines) are misread as the eu/es split, dumping left-side Spanish into `eu`.

**The governing principle for the fix — bias every uncertain call toward KEEPING TEXT and SINGLE-COLUMN.** For a legal-weight system the safe failure direction is to keep a stray bit of furniture (mild retrieval noise) rather than delete an article (a silent legal gap). The current heuristic fails the wrong way; flip it.

**Change 1 — two-column detection requires positive evidence of a real two-column *prose body* (not bimodal block centres).** A page is two-column only when there are **two tall, balanced, full-height text columns** — comparable text volume on each side, a clear central gutter running most of the page height. Layouts that must **not** trip it: an article index/TOC (left = titles, right = thin page-numbers → unbalanced), and `GRUPO/CATEGORÍA` or salary-style tables (short tabular lines). **When the evidence is weak → treat as single column** (monolingual Spanish, one stream). After this, spurious `eu` chunks on monolingual docs should disappear; `eu` should appear **only** on genuinely bilingual docs (Gipuzkoa).

**Change 2 — full-width alone must NOT mean furniture.** A block is furniture only if it is full-width **AND** (it **repeats across many pages** at a top/bottom margin band) **OR** matches known boilerplate. The reliable signal is **repetition** (the Gipuzkoa footer recurs on all 35 pages); the bare "full-width ⇒ furniture" rule is the dangerous one — demote it. A full-width block in the body region that does **not** repeat is **prose → keep it** (e.g. a `preámbulo` paragraph spanning the page). Tune `chunk_furniture_width_ratio` / `chunk_repeat_furniture_min_page_fraction` in service of this rule, but the **logic** change above is the real fix — **no per-document special-casing** (the rule must be general; we do not hardcode a Navarra branch).

**Change 3 — make over-stripping self-reporting (audit-first; so this never silently recurs).** Add a per-document **sanity flag** to the `chunks:embed` summary: flag any doc where stripped blocks **exceed** kept blocks, or where chunk count is implausibly low for its page count (e.g. `< 1 chunk per 3 pages` on a non-scanned doc). Surface these in the embed summary and log, the same way coverage gaps are surfaced — so a future full-corpus run **reports suspicious docs by construction**, rather than relying on an eyeball.

**Regression guard (mandatory — re-verify the clean docs are UNCHANGED before re-embedding anything):**
- **Gipuzkoa Limpieza** (`20000785011981…`): the bilingual footer is **still stripped** (the catch-1 win must survive), the genuine **`eu`=80 Basque** split is **preserved**, and the `es` chunks are intact. The fix must tighten two-column detection **without** breaking the real bilingual case.
- **Estatuto editions, Andalucía COEAS, Bizkaia Intervención Social**: chunk output unchanged (or improved — fewer spurious `eu`), no body text newly lost.
- Use `hr-ai/scripts/inspect_doc.py <storage_key> <pages>` (added this sprint) to eyeball before/after on Gipuzkoa (regression) and on a Navarra file + the Álava OCIO doc (the fix targets).

**Re-embed scope:** the fix should not change the clean docs (the regression guard confirms this); re-embed **only the docs whose output changes** — the Navarra cluster, Intervención Social Gipuzkoa (doc 45), and the monolingual docs with spurious `eu` — via `chunks:embed --document=…`. Confirm the Navarra/doc-45 chunk counts rise to plausible levels, their `not_cleanly_split` flags now fire **only** on genuine non-prose (real salary tables / signature blocks), and the spurious `eu` chunks are gone.

**Record:**
- `data-model.md` — extend the `document_chunks` Population note: two-column detection requires balanced-tall-column evidence (uncertain → single column); furniture requires repetition/margin-band, not full-width alone; the bias-to-keep-text principle; the new per-doc over-strip sanity flag.
- `review.md` — append a Correction-01 section: the root cause, the two logic changes + the sanity flag, the regression-guard result (clean docs unchanged), and the re-embed result (before/after counts for the affected docs).

Then **STOP — do not commit** until I review the regression-guard and re-embed results.

---

After Cursor applies this and appends to `review.md`, paste it back. I'll verify the clean docs are unchanged and the affected docs now retain their body text — then we commit 2a and the substrate is finally final for 2b-1.
