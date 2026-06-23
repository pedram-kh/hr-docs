# Re-chunk task — Cursor build-authorization prompt

> Paste the block below into the Sprint 2c (re-chunk) Cursor thread (the one that wrote `plan.md`). The plan + survey are approved; this authorizes the **build**. Every `plan.md` §7 question is resolved below. **Precondition: the stack must be restored first** (the survey ran off source PDFs because the stack was down). Build the splitter, run the regression guard **before** the re-embed, then the re-embed, then the acceptance + de-crutch gates. Update the named docs, write `review.md`, and **STOP — do not commit.**

---

The Sprint 2c re-chunk plan (`hr-docs/sprints/sprint-02c-rechunk/plan.md`) and its survey catalogue are approved. Build it. This is a **substrate task** — `hr-ai` chunker + re-embed only; **change nothing in the answer loop** (router, retrieval union, precedence re-rank, grounding, synthesis), `hr-ai` writes only `document_chunks` + S3 and **never migrates** (ADR-0007), no `hr-backend`/`hr-frontend` changes. All `plan.md` §7 questions are resolved as follows.

## Precondition — restore the stack first
The survey was done off source PDFs because `hr_ai` + Postgres/MinIO were down. Before any regression or acceptance run, bring the stack back up cleanly (clean Docker restart; if `hr_ai` is wedged, `docker stop`/`start` unsandboxed, or a full Docker Desktop restart). Confirm `/health` = 200 and `/health/config` shows the loaded models before proceeding.

## Resolved open questions (apply exactly)

1. **§7 Q1 — size cap: hard ≈ 800 / target ≈ 512 tokens**, per the §2.3 design — but the §1.4 distribution is an inflated raw estimate, so **recompute the article-length distribution with the real BGE-M3 tokenizer on furniture-stripped streams and lock the cap constant *before* the bulk embed.** If the real distribution shows a materially larger share of articles in the 800–1024 band than §1.4 implied, report it before locking. Keep typical whole articles in one chunk; sub-split only the genuine 1,000–3,000-tok long-tail on a sub-clause/paragraph boundary (never mid-sentence), each sub-chunk retaining its `Artículo N.º <title>` reference.
2. **§7 Q2 — active set: the registry query (§4) is the arbiter, not folder placement.** Re-chunk exactly the docs it returns as `retrieval_status = active`, prose type, not draft/under_review. **Report explicitly** in `review.md`: (a) any doc that is folder-active but **registry-expired** (the 2024/2025-validity ones — Andalucía 29, Navarra 75/93, Vizcaya 18, Huesca, Deporte Estatal — and esp. Andalucía COEAS which the 2a probe treated as out-of-scope as-of 2026) listed as **excluded-because-expired**, flagging any that are **expired with no loaded successor** (a coverage gap, same family as the scans); (b) the four not-in-2a actives (COEAS Estatal, Deporte Estatal, Madrid, Huesca) re-chunked only if the query returns them active.
3. **§7 Q3 — hold the Estatuto (id 73) OUT of this task.** It is the universal national-law baseline; re-chunking it perturbs the convenio-vs-baseline precedence balance just stabilized in 2b-2 (broad blast radius, the most sensitive axis). This task is the *convenio* residual only. **Record it as a roadmap follow-up instead** (see "Docs" below) — do not re-chunk id 73 here.
4. **§7 Q5 — stack restore** is the precondition above.
5. **§7 Q6 — the Salamanca `\bART\s+\d+` clause is approved** (uppercase, line-anchored — won't match prose; closes the doc-51 under-chunk).
6. **§7 Q7 — sub-article policy approved:** `bis` headers → own chunk; letter (`13.a`) and decimal (`9.1`) sub-clauses → kept with their parent article, degrading safely to own-chunk-with-article-ref if they do split.

## Build (the §2–§3 design)
- The article-boundary detector (V1–V8 + the Salamanca clause + the defensive spelled-out clause), applied **per already-separated language stream**, with the **three precision guards** (line-anchored, case-aware, monotonic-number) — these are load-bearing now that packing is removed.
- **One chunk per article; remove cross-article packing** for the article path (the buried-grant cause). Keep the size-capped paragraph fallback only for the pre-Art-1 preamble and anchorless annexes.
- **Composition with 2a is preserved exactly:** the chunker change is the **only** stage that changes; `extract_columns.py`, geometry de-spacing, furniture stripping, positive-evidence two-column detection, the Spanish-function-word language gate, language tagging, and the over-strip sanity flags are **untouched**.

## Regression guard — run BEFORE trusting any re-embed (no DB writes first)
Mirror Correction-01's `regress_chunks.py` discipline — compute new-vs-old chunk stats and eyeball before embedding:
1. **No body-text loss** — kept-block counts **identical** to the committed 2a run (the chunker change is downstream of furniture stripping); no over-strip recurrence.
2. **Bilingual split holds** — the **three active bilingual docs (46, 48, 50)** come back with healthy non-zero `eu`; **every monolingual doc stays `eu=0`** (esp. Intervención Social Gipuzkoa 45, Valencia 20, the no-accent Navarra docs). The historical 4th bilingual doc (id 41) is untouched and still counts in the corpus-wide "`eu` only on genuine bilingual docs" assertion.
3. **Counts sane** — expect **more** chunks (articles are smaller, packing removed); watch both failure signatures — a **collapse** (detector missing headers) or an **explosion** (false-positive over-splitting, the §3.3 risk). **Salamanca (51) must rise from its anomalous 35** toward a real article count.
4. **Sanity flags behave** — `pages_not_cleanly_split` still fires only on genuine non-prose, unchanged.

Report the regression table; if anything trips, stop and report before the bulk embed.

## Re-embed
Idempotent, resumable, per-document over the registry-active set (`replace_document_chunks` per-doc clean replace; a crash resumes by re-running remaining ids). No other table touched.

## Acceptance + de-crutch gates (on the live stack, real EU key, against the re-chunked corpus)
1. **Bound acceptance gate (closes the Correction-03 residual):** terse "**¿qué vacaciones tengo?**" (Navarra) ×5 → **≥4/5 answer**, each cited to the **clean re-chunked Art. 9 Vacaciones chunk**, **37 días laborables**, `official_convenio`, **0/5 baseline** (`30 días naturales` never appears). Navarra Art. 9 still → 37 on focused + compound; the natural compound stays 5/5 → 37 + 15/30.
2. **De-crutch check:** in the deterministic retrieval trace, the clean vacaciones chunk now ranks **into the synthesis cap on raw score, BEFORE the precedence boost** (`rerank.boosted` is no longer what places it — contrast the Correction-03 trace where the grant was #15 pre-rerank). The re-rank **stays** (defense-in-depth — no answer-loop change); only verify it's no longer load-bearing here.
3. **Silent fallback intact:** trabajo a distancia (Gipuzkoa & Navarra) still → `national_law`.
4. **No answer-loop regression:** the 3 gold tests (Gipuzkoa 31/26, Navarra prueba 15/30, trabajo a distancia → national_law) and the round-2 PASS set (scope isolation, salary exact-from-SQL, sensitive → escalate before router, Q9 citation match) all hold.

## Docs (update in the same change)
- **`architecture.md`** — the article-boundary chunking strategy + that it composes with (does not replace) the 2a pipeline; the three precision guards.
- **New `ADR-0017` — article-boundary chunking.** The durable fix for the buried-grant artifact; **the precedence re-rank reclassified from interim-load-bearing to defense-in-depth** (per the de-crutch check).
- **`roadmap.md`:** mark the re-chunk task **done** and the Correction-03 residual **closed**; and **add a follow-up item: "Re-chunk the Estatuto (national law, id 73) on article boundaries"** — *home: the analytics/coverage-gaps sprint (Sprint 8), where corpus-wide answer-quality data will show whether messy Estatuto chunks cause any wrong/weak-precedence answers; **jumps the queue if it causes a visible wrong answer on the national-law side before then** (same trigger that moved the convenio re-chunk up). Uses this task's proven splitter; gated on the national-law gold tests (esp. trabajo a distancia → national_law).*
- **`deploy.md`** — add a go-live coverage item: **active convenios with no extractable text (scanned PDFs: Deporte Navarra 2025-2028, Pacto Cultura Navarra) are currently unanswerable; they need OCR (or an explicit scope exclusion) before go-live.** Also note any expired-no-successor convenios surfaced by §7-Q2 reporting.
- Correct the standing **"four bilingual Gipuzkoa docs" → "three active + one historical (id 41)"** wherever it appears (architecture/2a notes).
- `hr-ai` README.

Write `hr-docs/sprints/sprint-02c-rechunk/review.md` (the locked cap + tokenizer recompute, the registry cut + expired-doc report, the regression table, the acceptance + de-crutch results, the findings logged). Then **STOP — do not commit until I review.**

---

After Cursor reports, paste it back. I'll verify against the re-chunked corpus: the regression table (no body-text loss, bilingual split holds, Salamanca risen, counts sane), the **terse-vacaciones gate** (≥4/5, 37, clean chunk, 0 baseline), and the **de-crutch check** (the grant ranks in-cap pre-rerank) — then your eyes-on → commit → **2b is durably closed.**
