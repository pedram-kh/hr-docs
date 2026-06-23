# Sprint 2c (re-chunk) — Plan: article-boundary chunking of active prose convenios

> Location: `hr-docs/sprints/sprint-02c-rechunk/plan.md`
> Status: **plan + survey catalogue — for review. No chunker code written, no re-embed run this turn.**
> Substrate task in `hr-ai` (chunker + re-embed). The durable fix for the buried-grant artifact behind 2b-2 Correction-03.
> Read with: `architecture.md` §5, the 2a review + 2a Correction-01, 2b-2 review §15 + Correction-03, `sprint-02c-rechunk-spec.md`, `roadmap.md` §7.

---

## §0 — How this survey was done (method + caveat)

The `hr_ai` container and the `hr_platform` Postgres/MinIO are **down** this session (failed `docker restart hr_ai`; the only running containers belong to other projects). So I could not run the live `inspect_doc.py` / `regress_chunks.py` / DB queries. Instead I surveyed the **original source PDFs on disk** at `hr-backend/data/all-files/` (the exact corpus that was ingested into S3) using a **local PyMuPDF (`fitz`)** read-only pass — the same library the production extractor uses (`extract_columns.py`). This gives a faithful read of the real article headers, the real text/scan status, and a length estimate.

Two consequences, both handled below:
- The header catalogue and the scanned/garbled findings are **real** (read straight from the source files).
- The **length distribution (§1.4)** is a raw-`get_text()` estimate that does *not* apply the production furniture-strip / column-split / BGE tokenizer, and my survey regex was deliberately loose (it counted inline lowercase `artículo N del ET` cross-references as anchors). So segment **counts and maxima are inflated**; I use the distribution only to size the cap with margin, and I flag exact-token confirmation against the BGE-M3 tokenizer as a build-time step (§7).
- The **active-vs-historical document cut (§4)** is derived from folder placement + the 2a embed log + filename validity windows; the authoritative cut needs a one-line `documents` query, which I specify in §4 and §7 for reconciliation at build time.

---

## §1 — Article-header survey (drives everything below)

I inspected the active prose convenios across every province folder (Álava, Andalucía, Asturias, Cantabria, Estatal, Gipuzkoa, Huesca, Madrid, Navarra, Salamanca, Valencia, Vizcaya), the four bilingual Gipuzkoa docs, and the not-yet-embedded actives (Madrid, Huesca, Deporte Navarra, Pacto Cultura, COEAS Estatal, Deporte Estatal).

### §1.1 — Article-header variants in real use

The corpus uses **digit numbering everywhere** (no spelled-out cardinals/ordinals, no roman numerals on *articles* — roman appears only on `CAPÍTULO`/`TÍTULO` containers). The real variation is in the **abbreviation form** and the **separator** between the number and the title:

| # | Variant (verbatim) | Where seen | Note |
|---|---|---|---|
| V1 | `Artículo 1. Ámbito funcional.` | Álava (2,6), COEAS Estatal, Deporte Estatal, Madrid, Cantabria, Agencias, Enseñanza, Intervención Social Gipuzkoa (45) & Navarra (96) | full word + `.` + space — the **plurality** form |
| V2 | `Artículo 1.º  Ámbito territorial.` | Limpieza Gipuzkoa (es side), Alojamientos Gipuzkoa, Oficinas Navarra (93), Hostelería Navarra (75) | full word + **ordinal `º`**; often **double space** |
| V3 | `Art. 2.º Ámbito funcional.` / `Art. 11. Cese voluntario.` | Limpieza Navarra (95 — the ★ target doc) | **abbreviated** `Art.` + digit, mixed `.º`/`.` |
| V4 | `Artículo 1.—Ámbito funcional` | Intervención Social Bizkaia (18), Deportes Asturias (hist.) | **em-dash `—`** separator, no space |
| V5 | `Artículo 1.–Ámbito territorial y personal.` | COEAS Navarra (99) | **en-dash `–`** separator |
| V6 | `Artículo 1.- Finalidad.` / `Art. 13.- Contratación.` | Valencia (20) | **hyphen-space `.- `**, mixes `Artículo`/`Art.` |
| V7 | `ART 7.- VINCULACIÓN A LA TOTALIDAD` | **Salamanca (51)** | **bare uppercase `ART`, NO period after ART** — the production regex misses this entirely (see §1.5) |
| V8 | `1. artikulua.    Lurralde eremua.` | Limpieza, Alojamientos, Gestores (the bilingual eu stream) | **Basque**: `N. artikulua` + multi-space |

**Sub-article / special markers in real use:**

| Marker | Verbatim example | Doc |
|---|---|---|
| `N.1` decimal sub-clause | `9.1. … disfrutará de 37 días laborables de vacaciones.` | Limpieza Navarra (95) — **the buried grant itself** |
| `bis` (several spellings) | `Art. 8.ºbis. Horas complementarias.`, `Art. 22.bis.`, `Art. 25.ºbis.`, `Art. 30.ºbis.` | Limpieza Navarra (95) |
| `bis` (hyphen form) | `Artículo 13.-bis. Empresas de Trabajo Temporal.` | Valencia (20) |
| **letter** sub-articles | `Art. 13.a. … / Art. 13.b. … / Art. 13.c. …` | Valencia (20) |
| empty-title header | `Artículo 7.` (number alone on its line) | Enseñanza Estatal (68) |
| duplicate numbers | two `Artículo 16.` in a row; `Artículo 11.` after `Artículo 9.` (10 skipped) | Oficinas Navarra (93), Agencias (54) — real numbering quirks |
| no-accent typo | `Articulo 10.–…`, `Articulo 31.` | COEAS Navarra (99), Oficinas Navarra (93) |

`§9.3`-style headers (the `§` glyph) from the architecture/Correction-02 narrative were **not** observed as literal headers in the source PDFs — that notation appears in the prose docs as plain `9.1`/`9.3` decimals; the `§` is doc-writers' shorthand. The splitter targets the real `N.1` decimal form.

**Container/non-article anchors present** (used by the chunker only as secondary boundaries, never as the article unit): `CAPÍTULO` (roman), `TÍTULO`, `Disposición adicional/transitoria/final/derogatoria` (these the production regex already anchors), and `ANEXO` (usually marks salary/table back-matter → handled by the not-cleanly-split flag, ADR-0006).

### §1.2 — The Basque headers on the bilingual Gipuzkoa docs

On Limpieza (46), Alojamientos 2025-2028 (48), Gestores (50) the Basque header is **`N. artikulua.`** (e.g. `7. artikulua. Oporrak.` = `Artículo 7.º Vacaciones`). In the **two-column layout** the Basque column is **left**, Spanish **right**; the raw `get_text()` stream reads them as **alternating blocks of same-language headers** (a run of `1..6 artikulua`, then a run of `Artículo 1.º..6.º`) — i.e. the languages are already block-separated, which is exactly what `extract_columns.py` resolves into clean `eu`/`es` streams **before** chunking. The Basque text also carries the justification-spacing artifact (`Fun tzio`, `lizen tzi a`, `An tzi natasuna`) that the geometry-first de-spacer fixes; `N. artikulua` detects regardless of that spacing.

**Important scope nuance:** the 2a/2b docs say "the **four** bilingual Gipuzkoa docs." The fourth is **Alojamientos Gipuzkoa 2020-2024 (id 41)** — which lives in `GUIPUZCOA/Antiguo/` and is **historical**, hence **out of re-chunk scope**. So the re-chunk touches only **three** active bilingual docs (46, 48, 50); the regression check still asserts "`eu` only on the genuine bilingual docs" corpus-wide (§5), which includes 41 unchanged.

Intervención Social Gipuzkoa (id 45) is a **Gipuzkoa doc that is monolingual Spanish** (`Artículo 1. Ámbito funcional`, no `artikulua`, `eu=0` in 2a) — it must **not** be forced into a column split. The positive-evidence two-column detector already handles this (confirmed `es=91 eu=0` in 2a Correction-01).

### §1.3 — OCR / extraction noise that defeats naïve detection

- **Two active docs are pure scans (zero extractable text):** `NAVARRA/CONVENIO DEPORTE NAVARRA 2025 A 2028.pdf` (27 pp, 0 chars/page) and `NAVARRA/PACTO CULTURA NAVARRA.pdf` (2 pp). These were **never embedded in 2a** (correctly — OCR is out of scope) and are **excluded** here. (Deporte Navarra also exists as `.doc`, a skipped format.)
- **Salamanca (51)** is a BOP gazette whose article headers read **`ART 7.- …`** (no period after `ART`) — systematically missed by the current regex, which is why it yielded only 35 chunks in 2a. **Handled** by extending the detector (§2). Its body also interleaves BOP furniture (`Pág. 22  N.º 221 … BOLETÍN OFICIAL …`) — already stripped by the repetition/boilerplate furniture rule.
- **No-accent header typos** (`Articulo`) and **mixed separators** within one doc (Limpieza Navarra mixes `Art. 9.º` and `Art. 11.`) — both covered by the detector.
- **Inline cross-references** (`…según el artículo 22 del Estatuto…`, lowercase, mid-sentence) are the **principal false-positive risk** once we stop packing (see §2.2 / §3.3); the detector must reject them.
- **`Resumen.pdf` (Cantabria)** and **`ACUERDO PARCIAL COEAS ANDALUCIA_Alhambra.pdf`** have **no article structure** (a 3-page summary; an 11-page partial agreement with only an `Anexo`). Treated as non-article (paragraph-fallback) or excluded — §4.

### §1.4 — Article-length distribution (sets the cap)

Raw estimate across 19 active prose docs (loose regex, ~1.5 tok/word, furniture not stripped — so **inflated**, used for margin only):

- Median article ≈ **130–200 tokens** — most articles are **small** (this is *why* the old packing chunker merged them, and *why* article-boundary chunking helps: a small article like Navarra Art. 9.º Vacaciones becomes its own ~135-tok chunk dominated by the vacaciones grant instead of being a tail behind horas-complementarias text).
- p90 ≈ **680**, p99 ≈ **2 356** (inflated by inline-reference false anchors).
- Genuinely long articles exist and are **predictable by topic**: disciplinary regimes (`Clasificación de faltas`), professional classification, `Salud laboral`, `Subrogación`, and the tablas/retribuciones article. These legitimately run **~1 000–3 000 tokens** and are the ones the size fallback must sub-split.
- After removing the inline-ref false positives, the real count of articles exceeding a ~800-token cap is a **small minority** (the topic list above), concentrated in the largest convenios (Andalucía 65 pp, COEAS Estatal 74 pp, Intervención Social Navarra 74 pp, Valencia 86 pp).

**Cap implication:** keep a *typical whole article* in one chunk (don't re-introduce dilution), sub-split only the genuine long-tail. See §2.3.

### §1.5 — Veto check — **NOT vetoed; catalogue is workable**

The corpus is **splittable**. The overwhelming majority (~20 active docs) use one of a **small, regular family** of digit-numbered headers (V1–V6, V8) that differ only in abbreviation and separator — all reducible to a single tolerant pattern. The exceptions are bounded and individually handled, not a reason to abandon splitting:
- 2 scanned actives → **excluded** (not a splitter problem; OCR out of scope).
- 1 format gap (Salamanca `ART N.-`) → **one regex clause** (§2.1).
- 2 non-article docs (Resumen, Alhambra acuerdo parcial) → **paragraph fallback / exclude**.

No spelled-out-ordinal or roman-numeral *article* headers exist in the active set, so that whole class of complexity is moot (I keep a defensive clause anyway, §2.1). **Proceeding to §2–§7.**

---

## §2 — Splitter design, mapped to the survey

### §2.1 — The detector (per-variant)

Extend the existing anchor regex (`chunker.py::_CORE`) to one tolerant, **case-aware** header pattern. Detection is applied **per already-separated language stream** (so `Artículo`/`Art.`/`ART` for `es`, `artikulua` for `eu`).

| Survey variant | Detected by | Handling |
|---|---|---|
| V1 `Artículo 1.` | `Artículo\s+\d+` | new chunk at header |
| V2 `Artículo 1.º` | `Artículo\s+\d+` (stops at digit; `.º` is trailing) | new chunk |
| V3 `Art. 2.º` / `Art. 11.` | `Art\.\s*\d+` | new chunk |
| V4 `Artículo 1.—` | `Artículo\s+\d+` (em-dash trailing) | new chunk |
| V5 `Artículo 1.–` / `Articulo 10.–` | `Art[íi]culo\s+\d+` (`[íi]` covers the no-accent typo) | new chunk |
| V6 `Artículo 1.- ` / `Art. 13.- ` | covered by V1/V3 clauses | new chunk |
| **V7 `ART 7.-`** | **new clause** `\bART\s+\d+` (uppercase, line-anchored) | new chunk — **closes the Salamanca gap** |
| V8 `7. artikulua.` | `\d{1,3}\.?\s*artikulua` (existing) | new chunk (eu stream) |
| `Disposición adicional/transitoria/final/derogatoria` | existing clause | new chunk (back-matter) |
| defensive: `ART[ÍI]CULO (PRIMERO|…|NOVENO|…)` spelled-out | optional ordinal-word clause | new chunk (none in corpus today; future-proof) |

**Sub-article handling (V `bis` / letter / `N.1`):**
- `bis` headers (`Art. 8.ºbis`, `Artículo 13.-bis`, `Art. 22.bis`) are **distinct provisions** → each starts its own chunk. Good — `bis` articles are semantically standalone (e.g. `8.ºbis Horas complementarias`).
- **Letter** sub-articles (`Art. 13.a/13.b/13.c`, Valencia) and **decimal** sub-clauses (`9.1`, `9.3`) are **kept inside their parent article** by default (they are *not* separate top-level anchors; the detector only fires on `Art(ículo)/ART/artikulua + integer`). A `13.a` line is matched by `Art\.\s*\d+` → to avoid splitting Art 13 into 13/13.a/13.b/13.c, the detector requires the integer to be **immediately followed by a header terminator** (`.`, `.º`, `.—`, `.–`, `.- `, whitespace-then-Capitalized-title) and **not** by `.<letter>`/`.<digit>` — so `13.a`/`9.1` stay with the parent. (If a `13.a` *does* land as its own chunk, it still carries `Art. 13` text and resolves correctly — acceptable degradation, not a correctness loss.)

### §2.2 — Precision guards (the key change vs. the packing chunker)

The current chunker tolerates loose detection because it **packs** — a spurious boundary just gets re-merged. **Article-boundary chunking removes packing**, so a false `artículo N` (inline lowercase reference) would create a spurious micro-chunk. Three guards, in priority order:

1. **Line-anchored** — the anchor must start a line (preceded by `\n`/start, allowing leading spaces), not appear mid-sentence. (Tightens the existing `text[s-1] in "\n.;)º"` guard to line-start.)
2. **Case-aware** — headers are **capitalized** (`Artículo`/`Art.`/`ART`); inline references are **lowercase** (`…del artículo 22…`). Prefer capitalized leading form; accept a lowercase leading form **only** if it also passes guard 3. (The Basque `artikulua` is always a header — no inline-reference ambiguity.)
3. **Monotonic-number sanity** — accept a header whose number is **≥ the current article number** (sequential), rejecting a lowercase `artículo 22 del ET` that appears *inside* Art. 40. Tolerates the real duplicate/skip quirks (two `Artículo 16`, `9→11`) because it checks `≥`, not strict `+1`. A small forward-jump tolerance handles `bis`.

These are deterministic and corpus-validated against §1's variants; no LLM.

### §2.3 — One chunk per article (no packing) + size fallback

- **Primary rule:** start a new chunk at each detected header; the chunk contains that article header + its body + its decimal/letter sub-clauses, **and nothing from the next article**. Small articles stay small (good — a clean ~135-tok `Vacaciones` chunk embeds strongly on a vacaciones query; this is the whole point).
- **Stop cross-article packing** — the current `_pack_to_cap`/`target`-merge of consecutive tiny articles is **removed for the article path** (it is the buried-grant cause). The preamble-before-Art-1 segment and any anchor-less annex still use the size-capped paragraph fallback (`_pack_to_cap`) unchanged.
- **Size fallback for an over-cap article** (the genuine long-tail in §1.4): sub-split on a **sub-clause / paragraph boundary, never mid-sentence**, preferring (in order) decimal/letter sub-clause boundaries (`9.1`, `9.2`, `a)`, `b)`) → blank-line paragraph → sentence (the existing `_PARA`/`_SENT` ladder). **Each sub-chunk retains the article reference** (carry the `Artículo N.º <title>` header onto each sub-chunk so it still resolves to its article and cites cleanly).
- **Cap value:** set the **hard cap ≈ 800 tokens, target ≈ 512** (up from today's 512/400). Justification from §1.4: typical articles sit well under 512; raising the cap to ~800 keeps the medium articles (the 500–800-tok band) **whole** instead of fragmenting them (which would partially re-create the dilution we're fixing), while still sub-splitting only the genuine 1 000–3 000-tok disciplinary/classification/salud/subrogación/tablas articles. BGE-M3 handles 800 tokens comfortably (8192 max). **Confirm exact counts with the BGE-M3 tokenizer at build** before locking the constant (§7) — the §1.4 numbers are estimates.

---

## §3 — Composition with the 2a pipeline (article-split **layers on**, never replaces)

The 2a pipeline order is preserved; article-boundary chunking is a change to **one stage only** (the per-stream chunker, `chunker.py`). The extraction front-end (`extract_columns.py`) is **untouched**.

**Pipeline order (unchanged except the chunk stage):**
1. PyMuPDF rawdict extract + **geometry-first de-spacing** (`normalize.py`) — unchanged.
2. **Furniture stripping = repetition/margin-band + boilerplate** (Correction-01 change 2) — unchanged; the bilingual BOG footer + Salamanca BOP masthead still stripped.
3. **Positive-evidence two-column detection** + **Spanish-function-word language gate** (Correction-01 change 1) — unchanged; emits clean `es`/`eu` streams (or a single `es` stream for monolingual two-column).
4. **NEW: article-boundary chunker** runs **per language stream** (§2) — replaces the packing behaviour of the old chunker.
5. Over-strip **sanity flags** (Correction-01 change 3) — unchanged (still fires on stripped>kept / <1-chunk-per-3-pages).

### §3.1 — Why composition is clean

The chunker already consumes **one separated stream at a time** (`chunk_stream` is called per `es`/`eu` in `chunk_document`). So article detection never sees both languages at once — the `es` stream has `Artículo …`, the `eu` stream has `… artikulua`, and the existing `out.sort` + `language` tagging are preserved verbatim. **`eu` is still never stored or filtered** (ADR-0006/0013); language tagging stays internal.

### §3.2 — The article-split × column-split interaction (highest-risk seam)

This is the one place to verify explicitly. The column split happens **before** chunking, so on the three active bilingual docs the article splitter runs on **already-clean single-language streams** — it cannot blend `eu`/`es`. The failure mode is **not** "double split"; it is the *pre-existing* one Correction-01 already guards: if the **language gate mis-classified** a bilingual page as single-column, the two languages would be in one stream and the article splitter would then interleave `Artículo 7.º` and `7. artikulua` boundaries in one chunk. Mitigation = **the gate is unchanged and re-verified** (§5): every one of the three active bilingual docs must come back with a healthy non-zero `eu` count after re-chunk. Because chunking is downstream of and independent from the column decision, re-chunking **cannot** regress the column split — but the regression check asserts it anyway (defense-in-depth on the hard-won 2a bilingual work).

### §3.3 — New risk introduced by removing packing

As noted in §2.2: dropping packing makes the detector's **false-positive** behaviour matter (a spurious inline `artículo N` would now survive as a micro-chunk instead of being re-merged). The §2.2 guards (line-anchor + case + monotonic) neutralize this; the §5 regression watches for a **chunk-count explosion** (the signature of false-positive over-splitting) and the sanity flags catch the inverse.

---

## §4 — In-scope document list (explicit)

**Derivation:** active editions in each province folder that are (a) prose convenios, (b) not under `Antiguo/Antiguos/`, (c) not salary `.xlsx`/`Tablas` PDFs, (d) have extractable article text. Cross-checked against the 2a `chunks:embed` log (the 31 embedded prose docs) and filename validity windows. **Authoritative cut to be reconciled at build** with:
```sql
SELECT id, storage_key, title, retrieval_status, validity_start, validity_end
FROM documents
WHERE document_type IN ('convenio_text','national_law')   -- prose types
  AND retrieval_status = 'active'
  AND tagging_status NOT IN ('draft','under_review');
```

### §4.1 — IN SCOPE — active prose convenios to re-chunk

| 2a id | Province | Convenio (validity) | Notes |
|---|---|---|---|
| 2 | Álava | OCIO EDUCATIVO 2023-2026 | V1 |
| 6 | Álava | ACT. DEPORTIVAS 2024-2026 | V1 |
| 26 | Cantabria | DEPORTE 2024-2028 | V1 |
| 45 | Gipuzkoa | Intervención Social 2023-2025 | V1, **monolingual ES** (no column split) |
| **46** | Gipuzkoa | **Limpieza 2019-2026** | V2+V8, **bilingual** |
| **48** | Gipuzkoa | **Alojamientos 2025-2028** | V2+V8, **bilingual** |
| **50** | Gipuzkoa | **Gestores información 2024-2026** | V1+V8, **bilingual** |
| 54 | Estatal | Agencias de viajes 2025 | V1 |
| 68 | Estatal | Enseñanza no reglada 2024-2027 | V1, has empty-title `Artículo 7.` |
| 75 | Navarra | Hostelería 2022-2025 | V2 — validity flag (see §4.4) |
| **95** | Navarra | **Limpieza 2024-2027** | **★ the buried-grant target**; V3, `bis` articles |
| 93 | Navarra | Oficinas y Despachos 2019-2025 | V2, dup `Artículo 16` — validity flag |
| 96 | Navarra | Intervención Social 2025 | V1 |
| 99 | Navarra | COEAS 2023-2026 | V5 en-dash, no-accent typos |
| 51 | Salamanca | Oficinas y Despachos 2023-2025 | **V7 `ART N.-`** — needs §2.1 clause |
| 20 | Valencia | Oficinas y Despachos 2024-2026 | V6, letter sub-articles, `13.-bis` |
| 18 | Vizcaya | Intervención Social 2022-2025 | V4 em-dash — validity flag |
| 29 | Andalucía | COEAS 2022-2025 | V1, largest doc — **validity flag** (2a probe treated it as out-of-scope as-of 2026) |

**Present in corpus but NOT in the 2a embed log — confirm registry status, then include if active:**
| — | Estatal | COEAS Estatal 2025-2027 | V1, clean — likely belongs in scope |
| — | Estatal | Deporte Estatal 2023-2025 | V1, clean — validity flag |
| — | Madrid | COEAS Madrid 2023-2027 | V1, clean — likely belongs in scope |
| — | Huesca | Hostelería 2022-2024 | V1, clean — validity flag (2024) |

These four parse cleanly (verified in §1) but weren't in the 2a 31-doc run — most likely a tagging/assignment state, not a content problem. Reconcile against the registry query above; re-chunk those that come back `active`.

### §4.2 — National law (decision needed, §7)

`ESTATUTO TRABAJADORES_julio2025.pdf` (id 73, `national_law`, active) is article-structured prose. The spec says "active prose **convenios**"; the Estatuto is the baseline, not a convenio. **Recommendation: include it** for chunking consistency (it benefits from clean per-article chunks too), but it is **not required** to close Correction-03, and re-chunking it carries a small risk to the `trabajo a distancia → national_law` gold test. Flagged for your call (§7). The older Estatuto editions (60, 66) are historical → out.

### §4.3 — OUT OF SCOPE (do NOT touch)

- **Historical / `Antiguo`** (left on existing chunks, untouched): ids 10, 13, 16, 21, **41 (the 4th, historical bilingual Alojamientos)**, 52 (Deportes Asturias, also scan-thin), 55, 60, 63, 66, 81, plus every PDF under `*/Antiguo*/`.
- **Salary-table PDFs / `Tablas`**: every `*_Tablas *.pdf` / `*_Tabla *.pdf` (e.g. id 94 Oficinas Navarra Tabla 2025, the Álava/Gestores/Limpieza-Navarra Tablas PDFs) — salary is SQL (ADR-0006).
- **Parked 2a wage-table-chunk cleanup** — not attempted here.
- **Scanned (no text), un-embeddable actives**: `CONVENIO DEPORTE NAVARRA 2025 A 2028.pdf`, `PACTO CULTURA NAVARRA.pdf` (and the `.doc` Deporte Navarra texts — skipped format). OCR is out of scope.
- **Non-article docs**: `CANTABRIA/Resumen.pdf`, `ACUERDO PARCIAL COEAS ANDALUCIA_Alhambra.pdf` (partial agreement), `ALAVA/…2023-2026 Cambios.pdf` (addendum) — exclude (or paragraph-fallback only if registry marks them active prose; they carry no article structure to benefit from this task).
- **Ignored cruft**: `CONVENIOS 2026.xls`, `__MACOSX/`, `*.docx` (`PERIODOS DE PRUEBA*.docx`), `Plan_Igualdad_texto.pdf` (not a convenio).

### §4.4 — Validity-window caveat (flagged for reconciliation)

The 2a `chunks:embed` selection embedded prose **regardless of validity** (it embedded historical editions too — they answer time-scoped questions). Several "current-folder" docs have validity windows ending **2024/2025** (Andalucía 2022-2025, Hostelería/Oficinas Navarra, Vizcaya, Huesca, Deporte Estatal) and the 2a Probe B explicitly treated Andalucía COEAS as out-of-scope as-of 2026. So **"in the active folder" ≠ `retrieval_status = active`**. The registry query in §4 is the arbiter; I re-chunk whatever it returns as active prose and leave the rest untouched. (Re-chunking is per-document idempotent, so the set can be adjusted after reconciliation without disturbing other docs.)

---

## §5 — Re-embed + regression approach

### §5.1 — Re-embed (idempotent, resumable — per 2a)

Reuse the existing `chunks:embed` path (`replace_document_chunks` is already a per-document clean replace — idempotent, ADR-0007: `hr-ai` writes only `document_chunks` + reads S3). Run **per-document** over the §4.1 active set (`chunks:embed --document=<id>` in a loop, or a scoped batch), so a crash resumes by re-running the remaining ids; a completed doc re-runs harmlessly. **No migration, no other table, no answer-loop code.**

### §5.2 — 2a-style regression gate (run BEFORE trusting any re-embed)

Mirror Correction-01's `regress_chunks.py` discipline — compute new vs. old chunk stats **without DB writes first**, eyeball, then embed:
1. **No body-text loss** — `blocks_total` vs `column_blocks_kept` unchanged from 2a (the chunker change is downstream of furniture stripping, so kept-block counts must be **identical** to the committed 2a run); the over-strip sanity flag (stripped>kept, <1 chunk/3 pp) behaves as in 2a. No furniture over-delete recurrence.
2. **Bilingual split holds** — the three active bilingual docs (46, 48, 50) come back with healthy **non-zero `eu`** counts; **every monolingual doc stays `eu=0`** (esp. Intervención Social Gipuzkoa 45, Valencia 20, the no-accent Navarra docs). Re-run the Correction-01 bilingual trigger check (`regress_chunks.py <id>:<key>` → assert `eu>0` on the known-bilingual).
3. **Chunk counts sane** — **expect more chunks** (articles are smaller units, packing removed): roughly the article-count per doc (§1 header counts: ~40–110 `es` articles each) plus sub-splits, vs. the 2a packed counts. Watch both failure signatures: a **collapse** (detector missing headers → fewer chunks, e.g. a Salamanca-style regression) **or** an **explosion** (false-positive over-splitting from §3.3 → many micro-chunks). Spot-check Salamanca (51) specifically rises from its anomalous 35 toward a real article count.
4. **Sanity flags behave** — `pages_not_cleanly_split` still fires only on genuine non-prose (salary grids, signature blocks, indices), unchanged from 2a.

---

## §6 — Acceptance + de-crutch verification

Run on the live stack (stack must be restored first — §7) with a real EU key, against the re-chunked corpus.

### §6.1 — Bound acceptance gate (closes the Correction-03 residual)

- **Terse `¿qué vacaciones tengo?` (Navarra) ×5** → **≥4/5 answer**, each **cited to the clean vacaciones chunk** (the re-chunked Art. 9.º Vacaciones), **37 días laborables**, `authority_used = official_convenio`, **0/5 baseline** (`30 días naturales` never appears). This is the literal lift over the Correction-03 residual (terse phrasing was ~60% safe-escalate before).
- **Navarra Art. 9 still → 37 días laborables** on the focused and compound phrasings (regression of the Correction-03 win).
- Re-confirm the natural compound (`¿…vacaciones… y …periodo de prueba?`) stays 5/5 → 37 + 15/30.

### §6.2 — De-crutch check (the re-rank is no longer load-bearing here)

- In the **deterministic retrieval trace** for the Navarra vacaciones queries, confirm the clean re-chunked vacaciones chunk **ranks into the synthesis cap on raw cosine, *before* the precedence boost** — i.e. it is in the top-k by score, and `rerank.boosted` is **no longer what places it** (contrast the Correction-03 trace where 7721 was #15 pre-rerank and only reached synthesis via the boost). The proof is: same query, inspect rank pre-rerank vs post-rerank — the clean chunk should already be in-cap pre-rerank.
- **The re-rank stays** (defense-in-depth, per the hard constraint — no answer-loop change). We only *verify* it is no longer carrying this case.
- **Silent-topic still falls back** — `trabajo a distancia` (Gipuzkoa & Navarra) still → `national_law` (re-chunk doesn't create a spurious convenio chunk for a genuinely silent topic).

### §6.3 — No answer-loop regression (against the re-chunked corpus)

- **3 gold tests:** Gipuzkoa vacaciones **31/26** → `official_convenio`; Navarra periodo de prueba **15/30** → `official_convenio`; trabajo a distancia → `national_law`.
- **Round-2 PASS set:** scope isolation (P6b Gipuzkoa prueba cites only Gipuzkoa), silent fallback, salary exact-from-SQL (unaffected — SQL path), sensitive → escalate (guardrail before router), Q9 jornada citation match. The entailment gate / figure-guard / guardrail baseline behave unchanged (substrate-only change).

---

## §7 — Assumptions & questions

1. **Size cap** — proposed **target ≈ 512 / hard cap ≈ 800 tokens** with sub-clause sub-split (§2.3), justified by §1.4 but on a **raw estimate**. Build-time step: recompute the distribution with the **real BGE-M3 tokenizer + furniture-stripped streams** and lock the constant before bulk embed. *Confirm the 800 cap, or prefer a tighter 512 (more sub-splits) / looser 1024 (fewer)?*
2. **Active-prose set** — derived in §4 from folder + 2a log + validity; the **authoritative list is the registry query in §4**. Open: the **validity-expired-2025/2024** docs (Andalucía 29, Navarra 75/93, Vizcaya 18, Huesca, Deporte Estatal) — re-chunk only if `retrieval_status = active`. And the **four not-in-2a actives** (COEAS Estatal, Deporte Estatal, Madrid, Huesca) — include the ones that come back active. *Please confirm the cut against the registry.*
3. **National law (Estatuto)** — include id 73 in the re-chunk or hold it out? (§4.2 — recommend include, not required, small gold-test risk.)
4. **Re-embed runtime/cost** — 2a embedded ~3 500 chunks in a multi-hour CPU run (~10 min per ~150-chunk doc, BGE-M3 on CPU). Article-boundary chunking yields **more, smaller chunks** (estimate ~5 000–6 000 across ~22 active docs), so plan a **similar multi-hour run**; idempotent per-doc so it resumes. No GPU assumed.
5. **Stack restore** — the `hr_ai` container + `hr_platform` Postgres/MinIO are currently **down**; they must be brought back up before the regression/acceptance runs (§5–§6). The survey above stands regardless (read from source PDFs).
6. **Salamanca `ART N.-` clause** — confirm the §2.1 `\bART\s+\d+` extension is acceptable as a general rule (it is uppercase + line-anchored, so it won't match prose); it materially fixes one active doc.
7. **`bis` / letter / decimal sub-clauses** — confirm the §2.1 policy: `bis` = own chunk; letter/decimal sub-clauses kept with parent (degrading gracefully to own-chunk-with-article-ref if they do split).

---

**Plan + survey catalogue ready for review.** No chunker code written, no re-embed run. On approval of the survey catalogue (§1), the splitter design (§2–§3), and the in-scope list (§4 — pending the registry reconciliation), the next turn builds the splitter and runs the regression guard before the multi-hour re-embed.
