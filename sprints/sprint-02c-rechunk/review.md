# Sprint 2c — Article-boundary re-chunk — Review

**Status:** built; pending eyes-on review + commit. **Do not commit until reviewed.**
**Scope:** substrate only — `hr-ai/app/chunking/chunker.py` + `app/config.py` (cap) + a re-embed of the registry-active convenio set. The answer loop (router, retrieval union, precedence re-rank, grounding, synthesis) and `hr-backend`/`hr-frontend` are **untouched**; `hr-ai` wrote only `document_chunks` (+ read S3), never migrated (ADR-0007). See **ADR-0017**.

---

## 0. Precondition — stack restored

The stack was down (survey was done off source PDFs). Restored cleanly:
- `hrdev_pg` (Postgres :55432) + `hr_minio` (MinIO :9900) started; `pg_isready` OK, MinIO `/health/live` = 200.
- `hr_ai` brought up; `GET /health` = **200**; `/health/config` shows **BGE-M3 / 1024**, answer `claude-sonnet-4-5`, router `claude-haiku-4-5`.
- **Dev-infra fix (recorded):** after a `docker restart`, `hr_ai`'s container env `AWS_ENDPOINT=host.docker.internal:9900` is **broken in this Docker Desktop session** (the failure the `hr-ai/.env` note warned about) → S3 GETs timed out → `/embed` 502. The DB path already used the working bridge gateway `172.17.0.1` (from `.env`). Fix: re-created the `hr_ai` container pointing S3 at **`http://172.17.0.1:9900`** (snapshotted first via `docker commit` to preserve the cached BGE-M3 weights — no 2.3 GB re-download). After that, `get_object_bytes` returned bytes and `/embed` worked. This is the `.env`-documented remedy; no app change.

---

## 1. Registry cut (§7 Q2 — the registry is the arbiter, not folder placement)

Query: `documents JOIN document_types` where `code='convenio_text' AND retrieval_status='active' AND tagging_status <> 'under_review'`, **minus** the Estatuto (Q3) and the salary-table mistag (id 94, below).

### 1.1 Re-chunked set — **12 active prose convenios**

| id | conv | title | validity | bilingual |
|----|------|-------|----------|-----------|
| 2  | 3  | OCIO EDUCATIVO ALAVA 2023–2026 | 2023–26 (open) | — |
| 6  | 2  | ACT DEPORTIVAS ALAVA 2024–2026 | 2024–26 | — |
| 20 | 25 | Oficinas y despachos Valencia 2024–2026 | 2024–26 | — |
| 26 | 6  | DEPORTE CANTABRIA 2024–2028 | 2024–28 | — |
| 46 | 13 | Limpieza **Gipuzkoa** 2019–2026 | 2019–26 | **eu+es** |
| 48 | 12 | Alojamientos **Gipuzkoa** 2025–2028 | 2025–28 | **eu+es** |
| 50 | 15 | Gestores información **Gipuzkoa** 2024–2026 | 2024–26 | **eu+es** |
| 54 | 10 | Agencias de viajes 2025 | (open) | — |
| 68 | 8  | Enseñanza no reglada Estatal 2024–2027 | 2024–27 | — |
| **95** | 22 | **Limpieza Navarra 2024–2027** ★ (the buried-grant doc) | 2024–27 | — |
| 96 | 18 | Intervención Social de Navarra 2025 | (open) | — |
| 99 | 19 | COEAS Navarra 2023–2026 | 2023–26 | — |

This is **materially smaller than the plan's §4 folder estimate** (~18–22). The registry arbiter classifies many "current-folder" convenios as `historical` (validity ended 2024/2025; as-of 2026 they are expired) — exactly the §4.4 caveat the plan flagged, now resolved.

### 1.2 (a) Folder-active but **registry-expired** → excluded-because-expired

Each below is registry `historical` with **no active prose successor loaded** → a **coverage gap** (same family as the scans, §6):

| id | convenio | expired window | successor? |
|----|----------|----------------|-----------|
| 29 | Andalucía **COEAS** (conv 4) | 2022–2025 | none active → **gap** |
| 18 | Vizcaya **Intervención Social** (conv 26; + 13/16) | 2022–2025 | none active → **gap** |
| 33 | **Huesca** Hostelería (conv 16) | 2022–2024 | none active → **gap** |
| 51 | **Salamanca** Oficinas (conv 24) | 2023–2025 | none active → **gap** |
| 45 | Gipuzkoa **Intervención Social** (conv 14) | 2023–2025 | none active → **gap** |
| 75 | **Navarra Hostelería** (conv 21) | 2022–2025 | none active → **gap** |
| 93 | **Navarra Oficinas** prose (conv 23) | 2019–2025 | only the **salary-table PDF** id 94 is active → **prose gap** |
| 69 | **Deporte Estatal** (conv 9) | 2023–2025 | none active → **gap** |
| 52 | Asturias Deportes (conv 5) | 2009–2012 | none active → **gap** |
| 80 | Deporte Navarra (conv 20) | 2016–2020 | active id 89 is a **scan**, `under_review` → **gap** |
| 41 | Alojamientos Gipuzkoa (conv 12) **bilingual** | 2020–2024 | superseded by **active id 48** ✓ (the one historical of the four BOG docs) |

> All of these were listed in the plan's §4.1 as candidate in-scope (folder heuristic). The registry overrides folder placement → **excluded**. (Andalucía COEAS specifically: the 2a probe already treated it as out-of-scope as-of 2026 — consistent.)

### 1.3 (b) The four "not-in-2a" actives — re-chunked only if the query returns them active

| id | doc | registry verdict | re-chunked? |
|----|-----|------------------|-------------|
| 72 | COEAS **Estatal** 2025–2027 | active but **`under_review`** | **no** (under_review) |
| 24 | COEAS **Madrid** 2023–2027 | active but **`under_review`** | **no** (under_review) |
| 69 | Deporte **Estatal** | **historical** (expired) | **no** (expired, §1.2) |
| 33 | **Huesca** Hostelería | **historical** (expired) | **no** (expired, §1.2) |

None of the four are re-chunked: two are `under_review` (untrustworthy scope), two are expired.

### 1.4 Salary-table mistag surfaced (excluded; flagged for retag)

**id 94** (`31005105011984_OFICINAS Y DESPACHOS NAVARRA_Tabla 2025.pdf`, conv 23) is registry `active` + `convenio_text` + `auto_proposed` — so the §7-Q2 query *returns* it — **but it is a salary-table PDF** (filename `_Tabla 2025`, 4 table chunks, no articles). Per ADR-0006 (salary is SQL; salary-table PDFs are out of scope) it was **excluded** from the re-chunk. **Recommendation:** retag id 94 to `salary_tables` (logged as a roadmap follow-up). Re-chunking it on article boundaries would be a no-op (no articles) and carries no benefit.

### 1.5 Held out (§7 Q3)

**Estatuto (national law, id 73)** — deliberately **not** re-chunked (universal baseline; broad blast radius on the convenio-vs-baseline precedence balance). Recorded as a **Sprint 8 follow-up** (roadmap.md) on this task's proven splitter, gated on the national-law gold tests.

---

## 2. Cap recompute & lock (§7 Q1 — real BGE-M3 tokenizer on furniture-stripped streams)

Recomputed the per-article token distribution with the **real BGE-M3 tokenizer** over the 12-doc active set, on **furniture-stripped** streams (i.e. through the actual `extract_columns.py` front-end), measuring each *article segment before* the size-fallback sub-split — **1,124 article segments**:

| metric | value |
|--------|-------|
| median | 164 tok |
| p90 | 954 |
| p95 | 1,621 |
| p99 | 3,795 |
| max | 9,887 |
| > 512 | 220 (19.6%) |
| > 800 | 127 (11.3%) |
| > 1024 | 107 (9.5%) |
| **800–1024 band** | **20 (1.8%)** |

**Decision: cap LOCKED at `chunk_token_target=512`, `chunk_token_cap=800`.**
- The §7-Q1 "report before locking if the 800–1024 band is materially larger than §1.4 implied" trigger is **not** met: the true middle band (articles that would stay whole at 1024 but split at 800) is only **1.8%**. Raising the cap to 1024 would spare only ~20 articles one extra split, at the cost of larger, more diluted chunks — not worth it.
- The `>800` tail (11.3%) is **dominated by the `>1024` mass** (9.5%): genuinely sprawling disciplinary/classification/salud articles **plus trailing back-matter mega-segments** (the last article's span absorbs `DISPOSICIÓN ADICIONAL` + annex/tables where no further header anchors — e.g. id 48's `max=9887` segment HEAD=`DISPOSICIÓN ADICIONAL Primera…`, TAIL=annex). These correctly sub-split into focused ≤800-tok sub-chunks (no body loss; the >8192 tokenizer warning is on the *pre-split* measurement only).

---

## 3. Regression guard (run BEFORE the bulk embed; no DB writes) — **PASS**

Computed new-vs-old chunk stats via `hr-ai/scripts/rechunk_survey.py` (the 2c analogue of Correction-01's `regress_chunks.py`) before any embed. Confirmed live by the embed run (right two columns).

| id | old | new (es/eu) | furniture (2a→new) | pages_not_cleanly_split | signal |
|----|-----|-------------|--------------------|--------------------------|--------|
| 2  | 89  | 134 (134/0) | 257 → **257** | none | more (packing removed) |
| 6  | 83  | 132 (132/0) | 227 → **227** | none | more |
| 20 | 137 | 117 (117/0) | 344 → **344** | none | fewer (cap 512→800) |
| 26 | 97  | 81 (81/0)   | 200 → **200** | 49,50 | fewer |
| 46 | 158 | 177 (**92/85**) | 206 → **206** | 1,35 | bilingual ✓ |
| 48 | 160 | 157 (**82/75**) | 249 → **249** | 1,3,4,30,31,32 | bilingual ✓ |
| 50 | 140 | 160 (**79/81**) | 194 → **194** | 1 | bilingual ✓ |
| 54 | 160 | 115 (115/0) | 223 → **223** | none | fewer |
| 68 | 82  | 74 (74/0)   | 118 → **118** | none | ~same |
| **95** | 87 | **75 (75/0)** | 53 → **53** | 46 | ★ target |
| 96 | 128 | 171 (171/0) | 74 → **74** | none | more |
| 99 | 32  | 31 (31/0)   | 30 → **30** | none | ~same |

- **No body-text loss / no over-strip recurrence.** `furniture_blocks_stripped` (and `column_blocks_kept`) are **identical to the committed 2a run** for every doc — the chunker change is downstream of furniture stripping, so the extraction front-end output is byte-for-byte unchanged.
- **Bilingual split holds.** The **three active** bilingual docs (46/48/50) return healthy non-zero `eu` (85/75/81); **every monolingual doc is `eu=0`** (incl. Valencia 20, the no-accent Navarra 95/96/99, COEAS Navarra 99). The eu#0 sample on each BOG doc is genuine Basque (`AUTONOMIA ERKIDEGOKO ADMINISTRAZIOA…`), not mis-split Spanish. The **historical 4th** bilingual doc **id 41** is untouched and still counts in the corpus-wide "`eu` only on genuinely bilingual docs" assertion.
- **Counts sane — neither failure signature.** A mix of more/fewer per doc: *more* from removing packing (small articles → own chunks), *fewer* from raising the cap (512→800 → fewer big-article sub-splits). **No collapse** (no doc dropped toward zero — would signal the detector missing headers) and **no explosion** (no doc ballooned — would signal false-positive over-splitting, the §3.3 risk). Corpus total for the 12 docs: 1,353 → **1,424** (+71).
- **Sanity flags behave.** `pages_not_cleanly_split` fires on exactly the same genuine non-prose pages as 2a (e.g. id 95 p46 = salary table; id 46 p1/35), unchanged.

### 3.1 Detector spot-checks
- **Buried-grant fix (id 95).** Art. 9.º Vacaciones is now its **own** chunk(s): chunk #12 (768 tok) leads with `Art. 9.º Vacaciones. 9.1. … todo el personal … disfrutará de 37 días laborables de vacaciones.` Art 9 (>800 tok) sub-split on its **9.1 / 9.3 / 9.7 sub-clause boundaries**, each carrying the `Art. 9.º Vacaciones` header. No more tail-burial.
- **Salamanca `ART N.-` (§7 Q6).** On id 51 (historical — not re-embedded), the new detector finds **48 anchors** (`ART 1.- OBJETO`, `ART 2.- ÁMBITO …`, …) vs ~1 with the 2a regex. The clause is proven; id 51 will benefit if/when it becomes active.
- **Precision guards held** — no spurious chunks from inline `…del artículo 22 del Estatuto…` (line-anchored + case-aware + monotonic), and the `>1024` segments are confirmed trailing back-matter, not missed mid-doc headers.

**Nothing tripped → proceeded to the bulk embed.**

---

## 4. Re-embed — **complete, 0 failed**

Idempotent, resumable, per-document over the 12-doc registry-active set via `php artisan chunks:embed --document=<uuid>` (`replace_document_chunks` per-doc clean replace). **All 12 succeeded, 0 failed.** Live per-doc counts match §3 exactly. No table other than `document_chunks` touched.

> Process note: a first embed pass wrote stale counts because the long-running `uvicorn` process predated the chunker edit (no `--reload`); after the container re-create (§0) a single-doc check (id 2 → exactly the survey's 134) confirmed the live process runs the new chunker, and the full set was re-run cleanly.

---

## 5. Acceptance + de-crutch gates (live stack, real EU key, re-chunked corpus)

### 5.1 De-crutch check — **PASS (the headline result)**

Deterministic raw-score retrieval (`retrieval:probe`, Navarra Limpieza, `¿qué vacaciones tengo?`, k=25) — this is **`/retrieve` raw cosine, BEFORE** the `hr-backend` precedence re-rank:

```
[0.655] Limpieza Navarra p6-8 (chunk 12): Art. 9.º Vacaciones. 9.1. … 37 días laborables…   ← #1
[0.648] Limpieza Navarra p6-8 (chunk 13): Art. 9.º Vacaciones. 9.3. Vacaciones adicionales…  ← #2
[0.633] Limpieza Navarra p6-8 (chunk 14): Art. 9.º Vacaciones. 9.7. …                          ← #3
[0.632] ESTATUTO p75 (chunk 92): Artículo 38. Vacaciones anuales…                              ← #4 (baseline)
```

The clean re-chunked vacaciones chunk now ranks **#1 on raw cosine**, **above** the Estatuto Art. 38 baseline (#4) — contrast the Correction-03 trace where the grant was buried at **#15** and the baseline reached synthesis. **Confirmed in the live answer path too:** the cited vacaciones chunks (ch 9403/9404/9405) are **NOT** in `trace.retrieval.rerank.boosted` — they reach the synthesis cap on raw merits; the boost lands (if at all) only on unrelated convenio chunks. **The precedence re-rank is no longer load-bearing here → reclassified to defence-in-depth** (kept; no answer-loop change). **Correction-03 residual closed at the substrate.**

### 5.2 Bound acceptance gate — **PARTIAL (substrate goal met; answer-rate bounded by the answer-loop grounding gate)**

Navarra terse `¿qué vacaciones tengo?` ×13 (5 + 8 diagnostic):
- **Answered ≈ 7/13** — **every** answer = **37 días laborables**, `official_convenio`, **cited to the clean re-chunked Art. 9 chunk (doc 95)**. **0/13 ever said "30 días naturales"** (the baseline error is fully eliminated).
- The **non-answers are all safe escalations** (`low_confidence`) with the trace note **`"ungrounded claim (per-claim entailment gate)"`**. **Check A passes 100%** (top_score=0.655, the vacaciones chunk #1 every run) — i.e. **retrieval is solid every time**; the variance is entirely the **answer-loop `/ground` LLM** conservatively gating the richer *multi-claim* synthesis the per-article chunks now enable (vacaciones grant + "vacaciones adicionales 9/4 días" + "disfrute en bloques").

**This misses the literal ≥4/5 bar (~50% answer rate).** It is **not a substrate/retrieval defect** and **not the precedence re-rank** — it is an **answer-loop interaction** (the per-claim grounding gate, ADR-0016/Correction-02), which is **out of scope for this task** ("change nothing in the answer loop"). Re-chunking *amplified* it: per-article chunks give synthesis more groundable detail, so it writes a richer answer whose secondary claims the gate sometimes can't entail. **Recommended follow-up (answer-loop, not chunking):** tighten synthesis to bind a `[Fuente N]` to each substantive claim and/or let `/ground` accept a secondary claim present in a *cited* chunk — the same Correction-02 lever — to restore the terse answer rate. Flagged for review; **no answer-loop edit made here.**

### 5.3 Other gates — **PASS**

| check | profile / question | result |
|-------|--------------------|--------|
| Navarra Art. 9 (compound, natural) | `…vacaciones … y … periodo de prueba?` | **answer 37 + 15/30**, cited doc 95 (3/4 runs; the 4th = same grounding-gate escalation) |
| Navarra periodo de prueba (gold) | `¿cuánto dura el periodo de prueba?` | **15/30** ("…no podrá exceder de 15 días … obrero/subalterno, ni de 30 días … técnico/administrativo"), cited doc 95 |
| Gipuzkoa vacaciones (gold) | `¿qué vacaciones tengo?` | **31/26** ("31 días naturales, de los cuales 26 serán laborables"), cited **only doc 46** → **scope isolation holds** (never doc 95) |
| Silent fallback (gold) | trabajo a distancia — **Navarra & Gipuzkoa** | **answer, `authority=national_law`**, cites the **Estatuto (doc 73)** ("Ley 10/2021 de trabajo a distancia") — the convenio is silent, baseline fills the gap ✓ |
| Salary exact-from-SQL (round-2) | Andalucía `¿cuánto gano?` | **answer, router=salary, path=salary_sql**, exact figure ("22.058,76 € bruto anual … 14 pagas … 12,6629 €/h") ✓ (salary path untouched) |
| Sensitive → escalate **before** router (round-2) | acoso laboral | **escalate, reason=sensitive_topic, router=null (pre-router), guardrail_fired=true** ✓ |
| Off-domain (round-2) | `¿qué tiempo hará mañana?` | **escalate, off_domain** ✓ |

**No answer-loop regression** from the re-chunk: gold (periodo de prueba 15/30, trabajo a distancia → national_law, Gipuzkoa 31/26) and the round-2 set (scope isolation, salary exact-from-SQL, sensitive→escalate-before-router) all hold.

---

## 6. Findings logged

1. **Substrate fix succeeds.** The buried-grant artifact is fixed at the chunk shape: the Navarra vacaciones grant ranks **#1 on raw score** and is **not boosted** → the precedence re-rank is de-crutched to defence-in-depth; **0 baseline (30 naturales) leakage** across all runs.
2. **Terse answer-rate residual is answer-loop, not substrate** — **now fixed in Correction-04 (§8).** The terse vacaciones gate sat ~50% because the **per-claim grounding gate** escalated the richer multi-claim synthesis; the root cause was **`/ground` output-token truncation** (a fully-grounded answer whose per-claim JSON exceeded the 1024-tok cap), not retrieval, binding, or entailment. The fix (budget bump + retry-once-on-truncation) restored the rate to **8/8** with the fabrication gate intact — see §8.
3. **Registry shrinks the live convenio answerable set to 12** — many province×sector cells are **expired with no active successor** (§1.2) or **scanned/`under_review`** (§1.3, §6 of deploy.md). This is a **go-live coverage** matter (now in `deploy.md` §5; feeds Sprint 8 coverage-gap detection).
4. **Salary-table mistag (id 94)** typed `convenio_text`/`active` — excluded per ADR-0006, flagged for retag (roadmap follow-up).
5. **Dev-infra:** `host.docker.internal` is unreliable in this Docker Desktop session; `hr_ai` S3 must use the bridge gateway `172.17.0.1:9900` (the `.env`-documented remedy). Recorded in §0.

## 7. Changes in this sprint

- **Code:** `hr-ai/app/chunking/chunker.py` (rewritten — article-boundary detector + 3 guards + size fallback; packing removed), `hr-ai/app/config.py` (cap 400/512 → **512/800**). New tool `hr-ai/scripts/rechunk_survey.py` (2c regression/recompute harness). `extract_columns.py` and the whole answer loop **unchanged**.
- **Docs:** `architecture.md` (§5 Sprint-2c block), new **ADR-0017**, ADR-0013 supersession note, `data-model.md` (population note), `roadmap.md` (2c row done + Correction-03 residual closed + Estatuto Sprint-8 follow-up + id-94 retag), `deploy.md` (§5 coverage: scans + expired-no-successor), `sprint-02a/review.md` ("four bilingual" → three active + one historical id 41), `hr-ai/README.md`.
- **Data:** `document_chunks` re-embedded for the 12 active prose convenios (idempotent). No migrations. No other table written.

---

## 8. Correction-04 (answer-loop) — `/ground` truncation (the terse-vacaciones residual)

Closes the §5.2 / finding-2 follow-up. This is an **answer-loop** change (the grounding adapter), deliberately *outside* the 2c substrate boundary — approved separately. The chunker, retrieval, precedence re-rank, and synthesis prompt are unchanged.

### 8.1 Root cause (decision-trace diagnosis)
A read-only trace of the escalating terse runs showed the escalation is **not** retrieval, citation-binding, or entailment:
- **Retrieval:** the clean Art. 9 chunks rank #1/#2/#3 (9403/9404/9405) every run — Check A passes 100%.
- **Binding:** synthesis binds a `[Fuente N]` to every substantive claim, resolving to the right sub-chunks (37 días→9403, 9/4 adicionales→9404 [9.3], bloques/IT→9403/9405).
- **The real cause:** `claude.py::ground()` capped the `/ground` response at **`max_tokens=1024`**. A richer per-article answer decomposes into ~13–16 atomic claims needing **~1,150 tok** of claim-by-claim JSON, so the response hit **`stop_reason=max_tokens`**, `_extract_json` failed, and the conservative *"unparseable → not grounded"* branch escalated a **fully-grounded** answer.

Deterministic confirmation — the exact long escalating answer (1,942 chars, 16 claims) fed back through `/ground`:

| `/ground` max_tokens | stop_reason | output_tokens | result |
|----------------------|-------------|---------------|--------|
| 1024 (old) | **max_tokens** | 1024 (capped) | JSON truncated → **UNPARSEABLE** → escalate |
| 4096 (new) | end_turn | 1,152 | **PARSES, all_grounded=true, 16 claims** |

The 2c re-chunk *amplified* this (and is why it surfaced now): per-article chunks made 9.1/9.3/9.7 individually retrievable, so synthesis writes a longer, more complete answer → tips `/ground`'s JSON over the old cap. Short answers (≤~9 claims) always fit, hence the ~50% split.

### 8.2 Fix (`hr-ai/app/providers/claude.py::ground()` + a distinct hr-backend trace note)
- **Budget bump:** `/ground` output cap **1024 → 4096** tok (`GROUND_MAX_TOKENS`); worst observed ground response ≈1,152 tok → comfortable headroom.
- **Retry-once-on-truncation (safety hardening, not optional):** if the response *still* stops at `max_tokens`, retry **once** at **8192** (`GROUND_MAX_TOKENS_RETRY`). Only if it truncates **again** does it escalate — and then as a **distinct outcome**: `grounded=false`, `ungrounded=["<grounding check truncated>"]`, `trace_fragment.grounding_truncated=true` / `retried_on_truncation=true`, and `floor_decision.note = "grounding check truncated after retry (escalated)"`. A truncation is **never** conflated with a genuine ungrounded claim.
- **The floor is unchanged — never weakened:** the "unparseable/truncated → escalate" branch stays as the conservative floor; we only give the gate room to finish. `/ground` still runs on the **answer model** (`claude-sonnet-4-5`), table-aware, per-claim.

Deterministic unit proof of the new control paths (fake provider responses, no key):

| scenario | calls | result |
|----------|-------|--------|
| both attempts truncate | 2 | `grounded=false`, `grounding_truncated=true`, `retried_on_truncation=true`, `max_tokens=8192` (escalates, distinct note) |
| truncate → retry succeeds | 2 | `grounded=true`, `retried_on_truncation=true` (recovered) |
| no truncation | 1 | normal path, `max_tokens=4096` |

### 8.3 Both-directions re-verify (live stack, real key, re-chunked corpus)

**Direction A — terse rate restored:**

| check | result |
|-------|--------|
| terse `¿qué vacaciones tengo?` (Navarra) ×8 | **8/8 answer**, every one **37 días laborables**, cited to the clean Art. 9 chunk (**doc 95**), **0/8 baseline** (30 naturales never appears) |
| natural compound (vacaciones + periodo de prueba) ×5 | **5/5 answer**, 37 + 15/30, doc 95 |

The previously-truncating rich answers now show `completion_tokens` up to **1,296** and ground cleanly at 4096 (`retried_on_truncation` never needed to fire on live traffic — 4096 alone absorbs the real distribution).

**Direction B — the gate still catches real fabrication (critical — bigger budget must not pass bad answers):**

| fabrication probe | result |
|-------------------|--------|
| non-numeric over-claim ("seguro médico gratuito / coche de empresa"), each sentence forced `[Fuente 1]` to a real but non-supporting chunk | **grounded=false** — both substantive claims F |
| attributive-wrapped fabricated figure ("Según tu convenio, **9 días** adicionales… asuntos propios") forced to an unrelated chunk | **grounded=false** — "Según tu convenio" `provenance/T` (exempt), the figure `substantive/F` (caught); precision guard holds |
| P10 cross-context "9 días adicionales 2024–2027" cited against chunks that don't contain it | **grounded=false** — the real "37 días" grounds (T), the cross-context adicionales claim flagged (F) |

All three escalate `grounded=false` on the answer model. **A larger budget only removes truncation failures; it never lets a genuinely ungrounded claim through.**

**Direction C — no regression:**

| gold / round-2 | result |
|----------------|--------|
| Gipuzkoa vacaciones | **31/26**, cited **only doc 46** (scope isolation) |
| Navarra periodo de prueba | **15/30**, doc 95 |
| trabajo a distancia (Navarra & Gipuzkoa) | **national_law**, doc 73 (Ley 10/2021) |
| salary exact-from-SQL (Andalucía) | **router=salary, path=salary_sql**, exact € |
| sensitive (acoso) | **escalate before router** (guardrail, router=null) |
| off-domain | **escalate, off_domain** |

### 8.4 Correction-04 changes
- **Code:** `hr-ai/app/providers/claude.py` (`ground()` — `GROUND_MAX_TOKENS=4096`, `GROUND_MAX_TOKENS_RETRY=8192`, retry-once-on-truncation, `grounding_truncated`/`retried_on_truncation` trace fields). `hr-backend/app/Services/ChatService.php` (distinct `floor_decision.note` on a truncated check). No DB/migration; `/ground` HTTP contract unchanged (the flags ride the existing free-form `trace_fragment`).
- **Docs:** this §8; `data-model.md` (the `grounding.grounding_truncated` trace note).
- **Ops:** `hr_ai` restarted so uvicorn loaded the new `claude.py` (no `--reload`); `/health` 200, BGE-M3/1024 + `claude-sonnet-4-5` confirmed.

**STOP — awaiting review; not committed.**
