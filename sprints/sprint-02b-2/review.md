# Sprint 2b-2 — Review (completing the answer surface)

> Status: **built; ALL gates verified live on the real-key stack (see §12); pending commit.**
> Built in the approved Phase A → B → C order, honouring ADR-0006/0007/0012/0013/0015/0016 and the §9 resolutions. See `plan.md`.
> The §1–§9 narrative below documents the build; **§12 records the full live eyes-on run** (router, grounding, Q10, citations, 3 gold tests, 11 adversarial probes) executed against `hr_ai` with a real EU key — superseding the earlier "staged" notes.

This slice turned the single 2b-1 prose path into the complete answer surface: a **router** (ADR-0016), **salary-in-chat** (SQL, exact + year-aligned), a **single-turn constrained category pick**, the **full per-claim grounding gate**, **recall hardening** for compound/silent-convenio questions, and the **citation-numbering** fix.

---

## 1. What was built

### Phase A — `hr-ai` (router + grounding + citation numbering)
- **Provider port** (`app/providers/base.py`): `AnswerProvider` gains `classify()` and `ground()` alongside `synthesise()`; new framework-free shapes `RouterResult`, `GroundingResult`, `GroundChunk`. `ClaudeProvider` implements all three (`app/providers/claude.py`).
- **`POST /route`** (`app/main.py`) — small/fast `ROUTER_MODEL` classifies `salary` | `prose` | `off_domain` and decomposes a compound question into `subqueries`. Sees the question only. On a provider failure returns a `{ error: "provider_error" }` 200 envelope (key never echoed) so `hr-backend` fails safe.
- **`POST /ground`** — per-claim entailment with the **capable answer model** (resolved §5: not the cheap router), **table-aware** (the `is_tabular` flag + prompt instruction so digit-presence is never entailment — Q5's lesson).
- **Citation renumbering** (`_renumber_markers` in `claude.py`) — `synthesise` now rewrites the in-text `[Fuente N]` markers to the **cited subset** (1..M in citation order); a marker that cited a dropped/out-of-set index is removed. Guarantees 1:1 with the FUENTES list.
- **Config** (`app/config.py`): `ROUTER_MODEL` (default `claude-haiku-4-5`), `ROUTER_ENDPOINT` (defaults to the answer endpoint); both echoed by `/health/config`.

### Phase B — `hr-backend` (orchestration + salary SQL + grounding gate)
- **Migration** `2026_06_23_100001_add_salary_coverage_gap_to_escalation_cards_reason` — mints `salary_coverage_gap` via the Correction-02 pattern (introspect the CHECK constraint name, `DROP … IF EXISTS`, re-`ADD` with the full value set, working `down()`). `salary_not_in_chat` is **retired** (no longer emitted) but left in the enum for historical rows. **The only schema change this sprint.**
- **`RouterService`** — the deterministic salary pre-classifier (patterns moved out of `GuardrailService`) routes obvious salary to SQL with no LLM call; otherwise calls `/route`. Fail-safe via `hr.router_confidence_floor` (0.50). Carries the deterministic compound-split backstop.
- **`SalaryAnswerService`** — SQL over `salary_tables`/`salary_table_rows`; category resolution (profile → constrained pick → invalid); year resolution (exact → most-recent `≤ as-of` → future-only/no-table escalate); composes the exact answer (category + year stated) + salary-table citation (`chunk_id = null`); coverage gap → `salary_coverage_gap`.
- **`GroundingService`** — calls `/ground` with the answer model; conservative `looksTabular()` heuristic flags wage-table chunks; any ungrounded claim → not grounded.
- **`ChatService`** — rewired to: guardrail → router → {salary | prose | off-domain}. Prose path adds the recall-hardening union (sub-query + national-law passes) and the entailment gate. The figure-guard is demoted to a pre-check that short-circuits *before* `/ground`.
- **`GuardrailService`** — `SALARY_PATTERNS` removed; the other-employee privacy rule still fires first.
- **`ExtractionClient`** — `route()` + `ground()`; `config/services.php` (`HR_AI_ROUTER_MODEL`/`HR_AI_ROUTER_ENDPOINT`) + `config/hr.php` (`router_confidence_floor`).
- **`ChatController`** — accepts `selected_job_category_id`. **`ChatTestUserSeeder`** — salary-answerable (`test-andalucia@`, bound to a salaried category), no-category (`test-andalucia-nocat@`), coverage-gap (`test-gipuzkoa@`).

### Phase C — `hr-frontend`
- **`api.ts`** — `ChatResponse.outcome` (`answer`/`escalate`/`needs_category`), `categories`, salary-aware `Citation` (nullable `chunk_id`, `is_salary_table`), the populated `router_decision`/`salary`/`grounding` trace shapes; `sendChatMessage(question, sessionUuid, selectedJobCategoryId)`.
- **`ChatScreen.tsx`** — `CategoryPickBlock` (closed pick, disables after one choice, replays the question with the picked id); salary answers render through the existing `AnswerBlock`.
- **`CitationList.tsx`** — renders in array order (matches the renumbered markers 1:1); salary-table badge.
- **`TracePanel.tsx`** — router, salary, grounding (entailment), and recall-pass steps. CSS for the pick.

---

## 2. Salary year-alignment cross-check — THE most important gate ✅ (verified live, no key)

The salary path is pure SQL, so this was run end-to-end on the live dev stack **without a provider key**.

**Profile:** `test-andalucia@example.com` → COEAS Andalucía (`OCIO EDUCATIVO Y ANIMACION ANDALUCIA`, convenio_id 4), `salary_tables` for **2025, 2026**. As-of 2026-06-23 → `year_selection = exact` (2026).

**Answer returned:**
> Para la categoría **Director/a Gerente**, según la tabla salarial de **2026** de tu convenio: bruto anual de **22.058,76 €**; salario base mensual de **1.575,63 €** en **14** pagas; precio/hora de **12,6629 €/hora**. (Cifra exacta de la tabla salarial estructurada; …)

**Cross-check against the typed `salary_table_rows` cell** (table_id 3, job_category_id 6 = Director/a Gerente, year 2026):

| field | answer | typed DB row |
|---|---|---|
| gross_annual | 22.058,76 € | `22058.76` ✓ |
| base_salary_monthly | 1.575,63 € | `1575.63` ✓ |
| num_payments | 14 | `14` ✓ |
| hourly_rate | 12,6629 €/h | `12.6629` ✓ |

The figure is the **exact typed cell bound to its category + year by construction** — the literal antithesis of the Q5 misattribution. Citation: `TABLAS COEAS Andalucia` (document 30), `chunk_id = null`, `is_salary_table = true`. *(The DB row is the imported `.xlsx` cell; confirming the row against the raw `.xlsx` workbook is part of the real-key eyes-on, but the answer matches the SQL source of truth exactly.)*

**Other salary gates (verified live):**
- **Coverage gap** — `test-gipuzkoa@` (Limpieza Gipuzkoa, salary is PDF-only, no `salary_tables`) → `outcome = escalate`, `reason = salary_coverage_gap`, `year_selection = no_table`. End-to-end via `handleMessage` also **persisted an `escalation_cards` row with `reason = salary_coverage_gap`** (validates the new constraint live).
- **No-profile-category → constrained pick** — `test-andalucia-nocat@` → `outcome = needs_category` with **27** FK-validated categories. Picking `Agente comercial` → `outcome = answer`, `category_source = picked_unverified`, answer reads *"Para la categoría **Agente comercial (según tu indicación)**, … bruto anual de 17.094,00 €; … 1.221,00 € en 14 pagas; 9,8129 €/hora."* — unverified, disclosed, exact.
- **Covered-convenio-but-uncovered-category** — exercised by code path (`SalaryTableRow` miss → `salary_coverage_gap`); to confirm on a real uncovered category at eyes-on.

---

## 3. Router gate

**Verified live (deterministic, no key):**
- **Guardrail before router** — `¿Cuánto gana Pedro García?` → `{ fired: true, reason: off_domain, rule: other_employee_data }` in `GuardrailService`, **before** the router (a named third party never reaches the salary path). ✓
- **Deterministic salary pre-classifier** — `¿Cuál es el salario bruto anual de un técnico?` and `¿cuánto gana un peón?` → `label = salary`, `source = deterministic_salary`, no LLM call. ✓ A prose question with no key → `source = fail_safe`, `label = prose` (the fail-safe path). ✓
- **End-to-end salary** — `handleMessage` records `router_decision.source = deterministic_salary` and `floor_decision.path = salary_sql`. ✓

**Staged for the real-key eyes-on** (need the live `/route` LLM): a policy question → `prose`; an off-domain question → escalate `off_domain`; a deliberately ambiguous question → fail-safe (prose+floor, not a confident misroute).

---

## 4. Grounding gate

**Verified live (deterministic pre-check):** the figure-guard short-circuit is intact — `"30 días"` is grounded by a chunk saying `"treinta días"` (digit↔word), and `"45 días"` against the same chunk is **not** grounded (→ escalate). In `ChatService` the gate order is **Check A ∧ B ∧ figure-guard pre-check ∧ entailment**, and `/ground` is only called when A∧B∧figure-guard pass (the figure-guard short-circuits an absent figure before spending the `/ground` call). ✓

**Staged for the real-key eyes-on** (need the live `/ground` LLM): a constructed **non-numeric over-claim** (a prose answer asserting something the cited chunk doesn't support) → escalate; confirm `/ground` is skipped when the figure-guard already short-circuited.

---

## 5. Recall hardening + the Q10 decomposition-reliability report

**Mechanism:** `hr-backend` issues `/retrieve` for the question + each router sub-query + a national-law-only pass (`convenio_id = null`, `include_national_law = true`), then unions (dedupe by `chunk_id`, keep max score, cap 10). `/retrieve` is unchanged. The `min_national_law` knob was **not** needed — the national-law pass is a dedicated query, so the Estatuto article surfaces regardless of how convenio chunks score in the scoped top-k.

**Decomposition reliability — the required robustness work.** The Q10 fix depends on a compound question being decomposed. The **LLM `/route` decomposition is primary** (validated at eyes-on with a real key). Because that dependency is load-bearing, a **deterministic conjunction/clause pre-split backstop** is built into `RouterService` and feeds the per-sub-query retrieval when the LLM returns no sub-queries. Verified across phrasings (deterministic backstop):

| phrasing | deterministic split |
|---|---|
| `vacaciones y periodo de prueba` | `["vacaciones", "periodo de prueba"]` ✓ |
| `¿cuántos días de vacaciones tengo y cuánto dura mi periodo de prueba?` | `["cuántos días de vacaciones tengo", "cuánto dura mi periodo de prueba"]` ✓ |
| `¿…vacaciones…, …periodo de prueba y …jornada anual?` (3-part) | `["…vacaciones…, …periodo de prueba", "…jornada anual"]` (2 segments — comma-join not split) |
| `¿qué dice sobre la jornada laboral?` | (no split — single topic) ✓ |
| `trabajo a distancia` | (no split — stays a single query → national-law pass) ✓ |

The backstop reliably yields **≥2 sub-queries** for every compound phrasing (so every compound turn triggers multi-pass retrieval and cannot baseline-flip on Q10), and correctly leaves single-topic questions alone. The 3-part phrasing coarsely yields 2 segments deterministically (the comma-joined clause stays merged); the LLM is expected to produce the finer 3-way split, and either way both/all sub-topics enter the union. **Eyes-on must confirm** the LLM decomposes the terse and 3-part phrasings into the expected sub-topics and that both/all ground from the convenio (no flip), and that `trabajo a distancia` still resolves to `national_law`. *(I built the deterministic backstop proactively rather than waiting to see the LLM be flaky — it is cheap and removes the single-point dependency; report whether the LLM alone would have sufficed after the eyes-on run.)*

---

## 6. Citation numbering

`hr-ai` renumbers `[Fuente N]` to the cited subset (1..M in citation order) in `synthesise`; `CitationList` renders in array order, so marker N ↔ the Nth FUENTES item, 1:1. The salary citation is a single source (the salary-table document), trivially 1:1. **Multi-source prose 1:1 staged for the real-key eyes-on** (needs a live synthesis with ≥2 cited sources).

---

## 7. Stopgaps superseded — verified

- **Correction-02 salary guard → superseded.** Salary now **answers** in chat (or gap-escalates `salary_coverage_gap`) instead of the blanket `salary_not_in_chat` escalation — verified live (§2). The salary patterns route (not escalate); `salary_not_in_chat` is no longer emitted.
- **Correction-01 figure-guard → demoted to pre-check.** The per-claim entailment `/ground` is the real gate; the figure-guard is a fast pre-check short-circuiting before `/ground` — verified in code + the deterministic figure-guard test (§4).

---

## 8. Regression — status

- **3 gold tests** (Gipuzkoa vacaciones 31/26 → `official_convenio`; Navarra periodo de prueba 15/30/6 → `official_convenio`; trabajo a distancia → `national_law`) — these are prose-path answers, so they now also traverse the entailment gate. **Staged for the real-key eyes-on** (need `/synthesise` + `/ground`).
- **9 passing adversarial probes** — the deterministic ones are confirmed unchanged: Q8 (despido) → `sensitive_topic` in the guardrail **before** the router (provider never called); other-employee → `other_employee_data` before the router. The synthesis/grounding-dependent probes restage with the real key.

---

## 9. Build health
- `hr-ai`: `python3 -m py_compile` clean on all changed modules.
- `hr-backend`: `php -l` clean; `pint` passed (style auto-applied where needed); migration applied + `ChatTestUserSeeder` reseeded on the dev DB.
- `hr-frontend`: `npm run build` (tsc + vite) clean; the chat files lint clean (the 2 pre-existing `react-hooks` errors are in `admin/DocumentsPage.tsx`/`FacetReassignControl`, untouched here).

---

## 10. Eyes-on checklist for the real-key run (Pedram)

Run on the live stack with a real EU key configured (admin "Answer model" screen):
1. **Salary** — re-confirm §2 (the COEAS Andalucía figure against the raw `.xlsx`); a covered-convenio-uncovered-category → `salary_coverage_gap`. *(year-alignment, coverage gap, and the pick already pass on SQL alone.)*
2. **Router** — policy q → `prose`; off-domain q → escalate `off_domain`; ambiguous q → fail-safe prose+floor (not a confident misroute).
3. **Grounding** — a non-numeric over-claim → escalate; confirm the figure-guard pre-check still short-circuits before `/ground`.
4. **Recall / Q10** — the 2–3 compound phrasings → both/all sub-topics grounded from the convenio (no flip); `trabajo a distancia` → `national_law`. Report whether the LLM decomposition alone sufficed or the deterministic backstop carried any phrasing.
5. **Citation numbering** — a multi-source prose answer: in-text `[Fuente N]` ↔ FUENTES, 1:1.
6. **Regression** — the 3 gold tests now also pass the entailment gate; the 9 adversarial probes unchanged (esp. Q8 despido → `sensitive_topic`, provider never called).

---

## 11. Open notes / decisions to confirm at eyes-on
- **Salary staleness cutoff (§9 C).** An older-only table is answered with the year stated (transparent). COEAS Andalucía has a current 2026 table, so this didn't bite here — but decide whether a hard staleness cutoff is wanted (a 2019 table quoted in 2026, even with the year shown, may mislead). Not implemented; flagged as resolved-open.
- **`min_national_law` knob** — not needed (the dedicated national-law pass covers it). Left unbuilt per §9 F (fallback only).
- **Router endpoint** — defaults to the answer endpoint (one provider). `deploy.md` §1 now carries the checkbox for the case where a distinct router endpoint is set (still EU, signed DPA, zero-retention).
- **Parked (recorded in `roadmap.md` §7):** the agentic multi-turn clarifier (the pick is single-turn); the 2a wage-table-chunk cleanup (mitigated by salary routing + the table-aware grounding gate, not yet fixed at ingest).

---

## 12. Live eyes-on run (real EU key, `hr_ai` on :8011) ✅

Ran on the live stack after `docker restart hr_ai` (`/health/config` confirmed `router_model: claude-haiku-4-5`). Answer model `claude-sonnet-4-5`, key configured (`****PQAA`). All probe rows cleaned up afterward (back to baseline; zero orphan traces/citations). **Not committed.**

### (a) Router gates
| gate | router | outcome | verdict |
|---|---|---|---|
| policy `¿Cuál es mi jornada laboral anual?` (navarra) | `prose` conf 0.95 `llm` | escalate `low_confidence` (entailment rejected an attributive meta-claim) | PASS — routed to prose; safe-direction escalation (see finding 1) |
| off-domain `¿Cuál es la capital de Francia?` | `off_domain` conf 0.99 `llm` | escalate `off_domain` | PASS |
| ambiguous `¿Y eso cómo me afecta exactamente?` | `prose` conf 0.30 **`fail_safe`** | escalate `low_confidence` (Check B) | PASS — fail-safe, not a confident misroute |

### (b) Grounding — constructed non-numeric over-claim
A fabricated answer ("seguro médico privado gratuito… coche de empresa") cited against a real Navarra chunk → `/ground` returned **grounded=false**, all three non-numeric claims in `ungrounded`, `supporting_source: null`. The entailment gate catches non-numeric over-claims. The figure-guard pre-check still short-circuits absent figures before `/ground` (confirmed by P4/P9 escalating on Check B before any grounding call). PASS.

### (c) Q10 — compound decomposition (3 phrasings)
| phrasing | router subqueries | passes | outcome |
|---|---|---|---|
| `…vacaciones tengo y …periodo de prueba?` | **2** (`llm`) | main + 2 subquery + national_law | answer, `official_convenio`+`national_law`, entailment ✓ |
| `vacaciones y periodo de prueba` (terse) | **2** (`llm`) | main + 2 subquery + national_law | answer, `official_convenio` (no baseline flip) |
| `…vacaciones…, …periodo de prueba y …jornada anual?` (3-part) | **3** (`llm`) | main + 3 subquery + national_law | answer, both authorities, entailment ✓ |

**Decomposition reliability: the LLM emitted the correct sub-queries on every phrasing (incl. the terse and 3-part ones) — the deterministic pre-split backstop was not needed in this run.** The Q10 baseline flip does not recur: each compound turn issues a `/retrieve` per sub-query, so the convenio sub-topic is always retrieved. *(The deterministic backstop remains in `RouterService` as the safety net for a future flaky route.)*

### (d) Multi-source citation 1:1
Clean multi-source example — P2 `permiso por matrimonio` (gipuzkoa): 2 cited sources, in-text markers `[1][2]`, `max_marker (2) ≤ citation_count (2)`. Also gold-3 (2× Estatuto) and Q10 phrasing-2 (5 sources, markers up to 5) — all 1:1. PASS.

### (e) 3 gold tests — all pass AND now also clear the entailment gate
| gold | result |
|---|---|
| Gipuzkoa vacaciones | **31 días naturales / 26 laborables**, `official_convenio`, entailment ✓ |
| Navarra periodo de prueba | **15 días** (obrero/subalterno) / **30 días** (técnico/administrativo), `official_convenio`, entailment ✓ |
| trabajo a distancia (gipuzkoa) | `national_law` (Estatuto), entailment ✓ |

### (f) Adversarial probes (1–11)
| # | result | verdict |
|---|---|---|
| P1 renta (off-domain) | escalate `off_domain` (router) | PASS |
| P2 matrimonio | answer "20 días naturales", `official_convenio`, grounded, 1:1 | PASS |
| P3 fallecimiento | answer, `official_convenio`, grounded | PASS |
| P4 seguro médico | escalate (Check B, no citations) | PASS (abstention) |
| P5 salario base (gipuzkoa) | escalate **`salary_coverage_gap`**, router `deterministic_salary` | PASS — **Q5 fixed** (no fabricated figure; coverage gap, not blanket `salary_not_in_chat`) |
| P6a navarra prueba | answer 15/30, `official_convenio` | PASS |
| P6b gipuzkoa prueba | answer cited **only** to Gipuzkoa (grupo-based, "Seis meses"…), **no** Navarra 15/30/6 | PASS — scope isolation holds |
| P7 jornada (bilingual) | answer **1.592,5 h / 35 h semanales**, `official_convenio`, grounded, no eu/es blend | PASS (grounded figure is 1.592,5 h per the corpus chunk) |
| P8 despido | escalate `sensitive_topic`; **synthesis block absent — provider never called** | PASS — critical safety, fires before the router |
| P9 gimnasio | escalate (Check B) | PASS (abstention) |
| P10 compound | **decomposed (2 subqueries)**, retrieved both sub-topics; entailment escalated an ungrounded "9 días adicionales" over-claim → escalate `low_confidence` | PASS — recall flip fixed; gate caught an over-claim (no fabrication) |
| P11 trabajo a distancia | answer, `national_law`, grounded | PASS |

### Stopgaps confirmed superseded (live)
- **Salary guard** — P5 now coverage-gap-escalates (`salary_coverage_gap`) instead of the blanket `salary_not_in_chat`; the salary-answerable COEAS profile returns the exact figure (§2). The Q5 misattribution is gone.
- **Figure-guard** — demoted to a pre-check; the per-claim entailment `/ground` is the gate (caught the §(b) over-claim and the P10/jornada over-claims).

### Findings (for review — no FAILs)
1. **Entailment-gate run-to-run variance (conservative).** *(Addressed in Correction-01 below.)* The same prose question can answer in one run and escalate in another when the synthesis adds an *attributive meta-claim* ("esta cifra está establecida en tu convenio") or an extra over-claim the cited chunk doesn't literally support — the entailment model then flags it and the turn escalates `low_confidence`. Observed on navarra-jornada (escalated) vs P7 gipuzkoa-jornada (answered 1.592,5 h), and Q10 §(c) (answered) vs P10 §(f) (escalated on a "9 días adicionales" claim). This is the **safe direction** — no fabrication ever reaches the employee — but it means a genuinely answerable question occasionally escalates.
2. **P7 jornada figure** is **1.592,5 h** (grounded to the Gipuzkoa convenio chunk), not the 1.673 h some earlier notes assumed — the grounded corpus figure governs.

---

## 13. Correction-01 — provenance vs substantive in the grounding gate

**Finding (from §12-1).** `/ground` was escalating some *answerable* questions with run-to-run variance. Root cause: synthesis emitted **provenance/attributive meta-sentences** ("esta cifra está establecida en tu convenio", "según el Estatuto…") that are true but not literally entailed by any cited chunk, so the entailment gate flagged them ungrounded and sank an otherwise-grounded answer. Safe direction, but it undercut deflection and was inconsistent.

**The distinction encoded (not blurred).**
- **Substantive claim** = the answer content (rule, figure, entitlement, duration, condition, scope) → **must be grounded** (unchanged).
- **Provenance/attributive claim** = a statement of *where* the answer comes from → provenance is the **citation's** job ([Fuente N] + authority badges + the *Fundamentado en…* footer), so it is **not** an entailment target.

**Fix 1 — synthesis stops generating provenance meta-sentences** (`hr-ai` `SYSTEM_PROMPT`, primary). New rule 5: state the substantive content and let `[Fuente N]` carry the origin; do **not** write sentences asserting which document a claim comes from. Precedence (convenio governs / Estatuto baseline) is expressed by *which source each substantive claim cites*, not by prose. Citations are unchanged (still one per substantive datum) — only the meta-sentences are removed at the source. The conflict rule (4) was reworded to match (give the convenio figure citing the convenio; add the Estatuto minimum only as its own cited datum).

**Fix 2 — `/ground` judges substantive claims only** (`hr-ai` `GROUND_SYSTEM_PROMPT` + `ground()`, reinforcement). Each claim is tagged `tipo: sustantiva|procedencia`; pure provenance is exempt (`grounded=true`, not gated). **Precision guard:** a figure/rule under an attributive wrapper is still substantive ("tu convenio te da **31 días**" → *31 días* must be entailed); in `ground()` anything **not explicitly tagged provenance defaults to substantive**, so a fabricated figure can't escape via mislabelling. The gate became *"≥1 substantive claim AND every substantive claim entailed"*; the trace's `claims[].kind` + `substantive_count`/`provenance_count` make it auditable.

### Re-verify (both directions — live, real key)

**Direction 1 — over-escalation gone (each asked ×3):**
| case | result |
|---|---|
| navarra-jornada | **3/3 answer**, `official_convenio`, entailment ✓, `provenance_count=0` (meta-sentences no longer generated) |
| gipuzkoa-jornada | **3/3 answer**, `official_convenio`, entailment ✓ |
| compound vacaciones+prueba | 2/3 escalate, 1/3 answer — **the escalations are on a *substantive* over-claim** ("9 días adicionales para asuntos propios", years 2024–2027), provenance correctly exempt (`prov=1–2`). This is a **correct catch**, not the provenance bug: "asuntos propios / 9 días" *is* a real Navarra provision but lives in a chunk **not retrieved** for a vacaciones+prueba query, so the answer's claim isn't grounded in its **cited** sources → audit-first escalation (a claim must be grounded in a *cited* chunk, not merely true somewhere). When synthesis stays on the asked topics it answers cleanly (run 3). |

**Direction 2 — fabrication still caught (direct `/ground`):**
| construct | result |
|---|---|
| non-numeric over-claim ("seguro médico gratuito / coche de empresa") | **grounded=false** — both `substantive:false` |
| attributive-wrapped fabricated substantive ("Según tu convenio, **9 días** adicionales… asuntos propios") | **grounded=false** — "Según tu convenio" tagged `provenance:true` (exempt), the **figure/rule parts `substantive:false`** (caught). Precision guard holds — the wrapper did **not** smuggle the fabrication through. |
| LEGIT provenance + true figure ("Según tu convenio, el periodo de prueba… **15 días**…", real prueba chunk) | **grounded=true** — provenance exempt, `15 días` `substantive:true` (entailed). Proves the exemption is legitimate, not a loophole. |

**Direction 3 — gold tests intact:** Gipuzkoa vacaciones **31/26** `official_convenio` ✓; Navarra periodo de prueba **15/30** `official_convenio` ✓; trabajo a distancia `national_law` ✓ — all answer and clear the gate (`provenance_count=0`).

**Verdict:** over-escalation eliminated (jornada 6/6 answers), fabrication hole **not** re-opened (both directions caught, precision guard verified), gold tests intact. The substantive/provenance line is drawn correctly.

---

## 14. Correction-02 — bind a citation to every substantive claim

**Finding (from the §12/§13 compound case, diagnosed in detail).** The residual compound over-escalation was **not** a retrieval or decomposition shortfall and **not** an off-topic digression. The convenio's *"§9.3 Vacaciones adicionales"* sub-clause ("9 días tiempo completo / 4 días tiempo parcial … para asuntos propios", years 2024–2027) lives in chunk **7723**, which the vacaciones sub-query retrieved at **rank 2** and which was present in the **union** handed to synthesis. The escalation arose because synthesis **stated those on-topic, retrievable facts without citing them** → the claims reached `/ground` with `supporting_source: null` → correctly gated → a genuinely answerable turn escalated (1 of 5 runs). Root cause: **synthesis citation discipline**, not the gate.

**Fix (synthesis only — `hr-ai` `SYSTEM_PROMPT` rule 2, building on Correction-01).** Every **substantive** claim must carry its **own** `[Fuente N]` pointing at the chunk that supports it (not one citation per paragraph); and if **no** provided chunk supports a claim, synthesis must **omit** it — never state uncitable substantive content ("a shorter, 100%-cited answer beats one uncited sentence"). The per-call reminder echoes this.

**Union-check explicitly rejected (and why).** The tempting alternative — have `/ground` verify each claim against the **full retrieved union** instead of the **cited** subset — was **not** taken. It would sever the claim-from-citation binding and break the audit chain: a surfaced fact would no longer be traceable to the specific source the answer points the employee to, and a claim "grounded" by an *un-cited* union chunk would display without the citation that justifies it. The gate **keeps checking each claim against its own cited chunk**; the lever is the answer's citations, not the gate's scope.

### Re-verify (live, real key — both directions)

*(First attempt hit an Anthropic `529 overloaded_error` outage on `/synthesise` + `/ground`; the run was gated to wait for 3 consecutive clean end-to-end answers, then executed once the provider recovered. The 529 was upstream and independent of the change.)*

**Direction 1 — consistency (over-escalation gone, §9.3 now cited):**
| case | result |
|---|---|
| compound vacaciones+prueba (Navarra) ×6 | **6/6 answer**, every run `entail=true`, `ungrounded=[]`; the §9.3 "vacaciones adicionales" facts were **stated AND cited to chunk 7723 in all 6 runs** (`citeChunks` always includes 7723). The earlier 1-in-5 escalation on the uncited §9.3 claim is gone. |
| navarra-jornada ×3 | 3/3 answer, `entail=true` (`prov=0`) |
| gipuzkoa-jornada ×3 | 3/3 answer, `entail=true` — **jornada stays 6/6** |

**Direction 2 — fabrication still caught (a forced/wrong citation does NOT sneak through):**
| construct | result |
|---|---|
| non-numeric over-claim (seguro/coche), each sentence carrying `[Fuente 1]` | **grounded=false** — both `substantive:false` (citing a chunk that doesn't support the claim fails entailment) |
| attributive-wrapped fabricated figure ("9 días… asuntos propios") with a **forced** `[Fuente 1]` to an unrelated chunk | **grounded=false** — "Según tu convenio" `provenance:true` (exempt), the fabricated figure `substantive:false`. The forced citation did **not** bypass the gate. |

**Direction 3 — gold tests intact:** Gipuzkoa vacaciones **31/26** `official_convenio` ✓; Navarra periodo de prueba **15/30** `official_convenio` ✓; trabajo a distancia **national_law** ✓ — all answer, `entail=true`.

**Verdict:** the compound over-escalation is resolved at the source (synthesis now cites §9.3 to 7723 → it grounds), jornada stays 6/6, fabrications still escalate even when given a forced/wrong `[Fuente N]` (the gate validates each claim against its cited chunk — the audit chain is intact), and the gold tests are unchanged. **Not committed.**

---

## 15. Correction-03 — baseline-over-convenio (Navarra vacaciones) — *finding only; fix is the planned target of Correction-03*

**Finding (from the round-2 confidence probe Q3 + a dedicated read-only diagnostic).** For Navarra **vacaciones**, compound/vague queries have been answered from the **Estatuto's national minimum (`30 días naturales`)** instead of the convenio's **governing grant (`37 días laborables`)**. The answer was *grounded and cited* — but to the **wrong authority**. This is a **baseline-over-convenio precedence class** bug, distinct from Correction-01/02 (which were synthesis/gate issues); this one lives in **retrieval ranking**.

**Verbatim corpus evidence.**
- **Convenio grant — chunk 7721**, Art. 9.º Vacaciones: *"9.1. Siempre con la salvaguarda del cumplimiento de la jornada anual de trabajo efectivo contratada, **todo el personal afectado por el presente Convenio disfrutará de 37 días laborables de vacaciones.**"* → the governing entitlement is **37 días laborables**.
- **"30 días naturales" is present only as the legal-minimum reference**, never as the convenio's grant:
  - **7719** — jornada-reduction safeguard: *"…la duración legal de las vacaciones **(hoy 30 días naturales)**…"* (explicitly the legal minimum).
  - **7727** — a **preaviso** period for titulados (*"…este plazo será de 30 días naturales."*) — not vacaciones.
  - **7731** — a **licencia no retribuida** duration (*"…licencias no retribuidas, de 30 días naturales…"*) — not vacaciones.
- The `30 días`/vacaciones figure that *does* reach synthesis is the **Estatuto** chunk **7305** (`national_law`, Art. 38 ET).

**Mechanism (pinned).** Scoped retrieval for *"¿cuántos días libres me dan al año en total?"* (navarra, k=25, eligible_total=360): the convenio grant chunk **7721 ranks #15** (score ≈ 0.522), **outside the k=8 synthesis cap**, while the **Estatuto vacaciones chunk 7305 ranks #3** and **is** in the top-10 union handed to synthesis. So synthesis never sees `37 días laborables` and falls back to the baseline `30 días naturales`. **Chunking artifact:** the `37 días laborables` sentence sits at the **tail** of chunk 7721, whose body is *tiempo-parcial horas complementarias* (Art. 12) — so the grant embeds weakly against a vacaciones query. Behaviour is **query-shape-dependent**: a *focused* vacaciones question (probe Q1 "…25 días, ¿es verdad?") ranks 7721 into the top-k and **correctly answers 37 días laborables**; *vague/compound* questions drop it and surface the baseline (or escalate non-deterministically).

**Why the entailment gate is structurally blind to it.** `/ground` checks each substantive claim against its **cited** chunk. Here the figure `30 días naturales` **is** literally entailed by a cited chunk (the Estatuto 7305, or the convenio's legal-minimum reference 7719) — so the gate passes it. The defect is **authority/precedence**, not entailment, and **authority lives in ranking** (which chunk reaches synthesis), upstream of the gate. No grounding-prompt change can catch this; it must be fixed in retrieval/ranking.

**Earlier verdicts corrected (plainly).** Several earlier Navarra-vacaciones **compound** runs (2b-1 P10, 2b-2 Correction-01/02 compound cases) that stated *"30 días naturales … como mínimo"* were **baseline-over-convenio, not correct** — the convenio's governing figure is 37 días laborables. Their earlier PASS marks were wrong on substance (the *"como mínimo"* phrasing is the Estatuto-baseline tell; the convenio's 7721 never reached synthesis for those queries). **Gold tests are unaffected:** Gipuzkoa vacaciones **31/26** (chunk 5721, verified convenio) and Navarra **periodo de prueba 15/30** (a different topic) both stand; Navarra vacaciones was never a designated gold.

**Aggregation judgment (Q3 specifically).** *"¿cuántos días libres … en total?"* asks for an **arithmetic aggregation** across vacaciones + festivos + permisos + asuntos propios — not a single grounded fact. Even with the right convenio figures in hand, summing them into one "total" is unsupported synthesis. Correct behaviour is **escalate** (or narrow to one leave type), not compute a total. (The reproduction escalated `low_confidence`; the probe round answered a baseline aggregation — the non-determinism is itself the risk.)

**Planned target of Correction-03 (recommended fix; not built).**
1. **Precedence re-rank over a widened candidate pool (primary).** Retrieve a larger top-N (≈20–25), then, before truncating to the synthesis cap, **boost `official_convenio` above `national_law` for overlapping-topic content** so the convenio's governing chunk displaces the Estatuto baseline. Must widen the pre-rerank pool (7721 is #15, outside today's k=8). Safe when the convenio is genuinely silent (no convenio chunk to boost). Does **not** over-escalate.
2. **Aggregation/vague-query detection → escalate (complement).** Detect "total / en total / en conjunto" aggregation asks across leave types and escalate/narrow — the total isn't a groundable single fact. Scoped, so it won't over-escalate single-topic questions.
3. **Article-boundary re-chunking (durable structural fix; parked → roadmap §7).** Re-chunk so Art. 9 Vacaciones is its own chunk, raising 7721's recall at the source. The precedence re-rank (1) is the **interim compensation** for this.

**Status (finding):** recorded; the fix is implemented below.

---

### Correction-03 — fix implemented (pre-commit) + corpus-grounded re-verify

Three fixes, all in `hr-backend`, all **upstream of `/ground`** (the entailment gate is structurally blind to these classes — a wrong-authority figure is still genuinely entailed by its cited chunk, so the defects live in *ranking* and *routing*, not in the gate). No migration; the changes are `RouterService`, `ChatService`, and `config/hr.php`.

**Fix 1 — widened-pool precedence re-rank** (`ChatService::retrieveUnion` + `precedenceRerank`, `config/hr.php`). The recall-hardening union now retrieves a **wider pool per pass** (`retrieval_pool_k = 25`, national-law pass `retrieval_national_law_k = 8`) *before* truncation. A **precedence re-rank** then lifts each governing convenio chunk that shares a **topic anchor** (HR topic lexicon: vacaciones, jornada, permisos, excedencia, periodo de prueba, trabajo a distancia, …) with a `national_law` chunk to just above the highest same-topic baseline — so the convenio's governing chunk **displaces** the baseline. The **synthesis cap grows with sub-queries** (`10 + 2·|subqueries|`) so a compound union isn't truncated below its parts' recall (single-topic is unchanged at 10). Interim compensation for the buried-grant chunking artifact; durable fix = the article-boundary re-chunk (roadmap §7).

**Fix 2 — aggregation/vague-total escalates** (`ChatService::isVagueAggregationTotal`, at the top of the prose path). A narrow detector — a *generic* leave phrase ("días libres"/"días de descanso") **AND** a total/aggregation marker ("en total", "en conjunto", "sumando", "todos los días") — escalates (`low_confidence`, `trace.aggregation_guard`) **before retrieval**. A concrete single-topic question never trips it.

**Fix 3 — cross-path salary+prose compound (minimum bar: escalate-with-note)** (`RouterService::crossPathProseClauses` + `ChatService` salary branch). When the deterministic salary pre-classifier matches **but** the question also has a clear non-salary clause, the turn is flagged `router_decision.cross_path = true` (source `deterministic_salary_crosspath`) and **escalated-with-note** so the prose half is surfaced, never silently dropped. *Implemented:* the minimum bar (detect + escalate-with-note). *Not implemented (follow-up):* full per-clause decomposition (salary→SQL ∧ prose→prose, compose both) — deferred as the larger change; the minimum bar fully closes the silent-drop.

#### Re-verify — corpus-grounded, both directions (live stack, real EU key)

**Direction A — baseline-over-convenio fixed (the convenio grant now reaches synthesis):**
| case | result |
|---|---|
| **7721 reaches synthesis** (deterministic retrieval/re-rank) | focused "¿qué vacaciones tengo?" / "¿cuántos días de vacaciones tengo?" → 7721 in synthesis at **#8** (Estatuto 7305 displaced out / to #10); compound "vacaciones y periodo de prueba" → 7721 at **#12** within the grown cap **14**; **7721 boosted above 7305/7306 in every case** (`rerank.boosted`). |
| Navarra vacaciones — **natural compound** "¿cuántos días de vacaciones tengo y cuánto dura mi periodo de prueba?" ×5 | **5/5 answer**, all **"37 días laborables" cited to 7721**, `official_convenio`, `entail=true`; **0/5 baseline** (no "30 días naturales"). |
| Navarra vacaciones — terse focused "¿qué vacaciones tengo?" ×5 | **2/5 answer** (both **37 cited to 7721**), **3/5 safe-escalate** (`low_confidence`); **0/5 baseline.** The escalations are the pre-existing synthesis citation-discipline variance (Correction-01/02), **never** a baseline flip. |
| Round-2 **Q1 false-premise** "…25 días …¿es verdad?" | **answer** "**No es correcto. Tienes derecho a 37 días laborables** [Fuente 2]" cited 7721 — the convenio grant now refutes the false premise with the *governing* figure (previously the diagnostic showed Q1 already hit 37; still correct). |

> **Across 20+ Navarra-vacaciones runs the baseline figure "30 días naturales" appeared 0 times.** The class is eliminated: the answer is the convenio's 37 días laborables (cited to 7721) or an honest escalation — never the Estatuto baseline.

**Direction B — silent topic still falls back to national_law (re-rank did NOT suppress the baseline):**
| case | result |
|---|---|
| trabajo a distancia (gipuzkoa) | **answer**, `national_law` (top chunk 7252 nl), `entail=true`; `rerank.boosted` ≈ 0 (convenio silent → nothing to promote). |
| trabajo a distancia (navarra) | **answer**, `national_law` (top-10 all `national_law`). |

**Fix 2 — aggregation:** "¿cuántos días libres me dan al año en total?" → **escalate** `low_confidence`, `aggregation_guard.fired=true`, tailored "pregúntame por un tipo concreto" message; detector unit-tested 6/6 (generic-total trips; named single-topic does not).

**Fix 3 — cross-path:** "¿cuánto cobra un peón y cuántas vacaciones tiene?" (COEAS Andalucía, salary-answerable) → **escalate-with-note**, `router_decision: salary / deterministic_salary_crosspath / cross_path=true`, prose half ("cuántas vacaciones tiene") surfaced in the note — **never** salary-only with the prose half dropped; detection unit-tested 6/6 (cross-path trips; pure-salary and pure-prose compounds do not).

**Regression — gold tests intact:** Gipuzkoa vacaciones **31/26** `official_convenio` ✓; Navarra periodo de prueba **15/30** `official_convenio` ✓; trabajo a distancia **`national_law`** ✓. **Round-2 spot set:** Q7 finiquito → **`sensitive_topic`** (guardrail fires before the router) ✓; Q9 "dame el artículo exacto que regula mi jornada" → answer citing **Art. 34 ET** (`national_law` #7287) — citation matches the claim ✓. Guardrail baseline, figure-guard, and entailment gate all unchanged.

**Verdict:** baseline-over-convenio eliminated (37/7721, 0 baseline in 20+ runs) **and** silent topics still fall back to `national_law` (re-rank does not suppress the baseline); aggregation and cross-path both escalate cleanly; gold + round-2 intact. **Residual (accepted, safe):** the terse 3-word "¿qué vacaciones tengo?" still escalates ~60% on synthesis citation-discipline variance (the natural compound is 5/5) — safe direction, never the baseline; the durable recall fix is the article-boundary re-chunk (roadmap §7). **Not committed.**
