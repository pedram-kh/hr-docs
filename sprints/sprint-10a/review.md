# Sprint 10a — review

**Branch:** `sprint-10a` in all four repos. **Nothing committed, nothing merged** — this document is the gate.
**Design record:** [ADR-0032](../../architecture/decisions/0032-estatuto-fallback.md).
**Status:** built, evaluated on staging, awaiting review and eyes-on (⏸ CP-4).

---

## 1. What shipped, in one paragraph

An employee whose convenio was **never ingested** now gets grounded, cited answers to ordinary statutory questions from the Estatuto de los Trabajadores instead of a blanket escalation. An employee whose convenio **exists but is not being served** — expired, mid-ingest, under review, a scan with no text — deliberately does *not*, and escalates with a new reason, `estatuto_fallback_gap`. That split is the sprint. Everything else (the chunker work, the coverage predicate, the eval harness) exists to make the split safe to rely on.

The clarifying-question turn, originally the other half of the spec, was **deferred**. A falsification probe ran in its place; §9 has the method and the counts.

---

## 2. Measurements, up front

| | result |
|---|---|
| Fallback gold set, positive profile | **10 of 13** answerable questions answered (prior answer rate: 0) |
| …of which grounded | **10 / 10** |
| …of which carry the caveat on the persisted row | **10 / 10** |
| Trigger split, `test-andalucia@` (expired *with* chunks) | **0 of 15** answered from the Estatuto |
| Trigger split, `test-midingest@` (expired with *zero* chunks) | **0 of 15** answered from the Estatuto |
| Fallback key on a turn that never took the prose branch | **0** |
| Estatuto chunks, doc 75 | 235 → **210**; TOC chunks 26 → **0**; article 37 recovered |
| Articles recovered corpus-wide by the chunker fixes | **54**, across 6 documents; **0** lost anywhere |
| Backend suite | **619 passed**, 2,859 assertions |
| Chunker guard tests | **30 passed** |
| `Sprint7cAdditivityRegressionTest` | green, byte-for-byte |

---

## 3. The decision, and why it is the whole sprint

Both cases look identical from inside the retrieval loop: zero eligible prose chunks for this convenio. The distinction is not in what retrieval returns but in *why* it returned nothing, and that lives in `documents`, not `document_chunks`.

Under **ultraactividad (ET art. 86.4)** an expired collective agreement generally remains in force until a successor is negotiated. A convenio is, by construction, at least as good as the statutory floor — that is what collective bargaining produces. So answering an expired-convenio employee from the Estatuto hands them the *minimum* while the agreement that actually governs them says better. The answer would be grounded, cited, confident, and wrong in the direction that carries legal weight, and nothing in the trace would show it.

`classifyProseGap()` therefore returns three values, and `never_ingested` is deliberately hard to reach: it requires **no prose document of any retrieval status** *and* no chunks of any status. The moment a document exists, the fallback is off. A document uploaded thirty seconds ago, or one whose embed job failed, is indistinguishable from "never had it" if you only look at chunks — requiring the absence of the document too closes that window and fails **closed**.

The classification is a database read performed *before* retrieval, never an inference from an empty `/retrieve`. An empty response can mean a poor query embedding, an index miss, or a brief outage; none of those mean "we never had this convenio's text", and a transient failure is the worst possible trigger for a fallback.

---

## 4. The Estatuto re-chunk — a prerequisite, not a side errand

The Estatuto was held out of the Sprint 2c article-boundary re-chunk because it is the universal baseline with the broadest blast radius. That holdout was correct while the Estatuto was only ever a *baseline*. This sprint makes it, for some employees, the *only* source — so its chunk quality stopped being a background concern.

It was in worse shape than expected: index pages were being retrieved as though they were content, and several substantive articles had no chunk of their own — including **article 37** (descanso semanal, fiestas y permisos), the single article the spec's permisos gold questions depend on.

### 4.1 Three chunker changes

**F1 — TOC dot-leader guard (new guard 4).** A line matching `\.{6,}\s*\d` is an index entry, not a header. This is what removed 26 decoy chunks from doc 75.

**F2 — relaxed guard 1 (sentence-initial headers).** Guard 1 required a header to start its line. Real headers in several documents follow a sentence on the same line. F2 accepts a sentence-initial candidate when it is capitalised *and* its number is the immediate successor of the running article — a **general** relaxation, not a per-article special case, since per-article special-casing is the digit-regex failure class deleted in 7f.

**Guard 5 — minimum body for F2 anchors only.** F2 alone was worse than the problem. Staging doc 89 (COEAS Andalucía) opens with four pages of run-on contents — `Artículo 1. Ámbito territorial. Artículo 2. Ámbito funcional. …` — with no dot leaders, so F1 cannot see it, and every entry is sentence-initial, capitalised and perfectly sequential, so F2 welcomes all of them. Measured: **+80 chunks of 28–51 characters**, pure title and zero content, strictly worse retrieval decoys than the packed chunks they replaced.

The separating fact, measured across all 33 chunked prose documents: an index entry's segment is 28–51 characters, while the smallest genuine article F2 recovers is **306** (doc 67, `Art. 2.- Ámbito territorial`). The threshold sits at **150**, near the geometric midpoint, ~3× above the decoys and ~2× below the smallest genuine article, calibrated to neither edge.

Its exact cost was measured by running the candidate against itself with the threshold patched to 1: **75 index segments** dropped from doc 89, and **exactly one** genuine item corpus-wide — doc 67's `Art. 51.- De la jubilación. Según la legislación vigente en cada momento.` (77 chars), a stub that defers to the law and carries nothing retrievable of its own. It fails safe by construction: dropping an anchor never deletes text, the segment simply stays merged into the preceding chunk, which is exactly what the pre-10a chunker did with it. The worst case for a genuinely short article is "no better than before", never "lost".

### 4.2 Blast radius — all 33 chunked prose documents, `main` vs candidate

| doc | chunks | real articles | what happened |
|---|---|---|---|
| 67 | 40 → 60 | 7 → **44** | 37 recovered from `PREÁMBULO`-headed mega-chunks |
| 93 | 69 → 77 | 4 → **15** | 11 recovered, incl. `Artículo 36.- Vacaciones` |
| 81 | 208 → 185 | 90 → 92 | historical Estatuto copy; index removed |
| 53 | 73 → 74 | 50 → 52 | two recovered |
| 75 | 212 → 186 | 89 → 90 | article 37 recovered, 26 index decoys removed |
| 89 | 139 → **141** | 99 → 99 | was +80 decoys before guard 5; now +1 |

27 documents byte-identical. **No document anywhere lost a real article.**

### 4.3 Known residual, accepted

Doc 89 keeps one 245-character chunk packing three index titles (`Artículo 31. Comisión de igualdad Artículo 32. …`). It clears 150 because three titles run together. Left alone deliberately: that text was already in the corpus as index chunks on `main`, so it is not a regression, and the alternatives either shrink the margin against doc 67's genuine 306-character article to 1.2× or risk rejecting real articles that cross-reference others.

### 4.4 Doc 75 re-embed (CP-2)

RDS snapshot **`hr-staging-pre-10a-rechunk`** taken first (available, 16:25:43 UTC, 50 GB, PG 16.13), so the whole step is reversible.

Predicted before touching anything, by running both chunker versions through the real `pipeline.build_chunks` path: baseline **235** (matching the database exactly, which is what makes the prediction trustworthy), candidate **210**. Actual after re-embed: **210**. Distinct articles 90 both ways — article 37 gained, and the article-26 entry that disappeared was an index decoy whose real body has always lived inside the article-25 chunk. Zero chunks contain a dot-leader line; zero chunks have a null embedding.

**Gold tests, retrieval level.** Navarra *periodo de prueba*: the convenio's own `Art. 37.º` still wins at 0.668 over the Estatuto's article 14 at 0.660, both byte-identical to before; the index chunk `Artículo 14. Periodo de prueba.......... 40` that was ranking **fourth at 0.501 is gone**, replaced by real content. *Trabajo a distancia*: article 13 still leads at 0.689 for both profiles; the index chunk that ranked seventh at 0.516 is gone. Gipuzkoa *vacaciones*: top three unchanged, but ranks 4–8 now hold five chunks of the newly recovered article 37. At the probe's `k=8` that looks like crowding; it is not. The answer loop retrieves with a pool of 25 plus a pass per sub-query plus a national-law pass, then precedence-re-ranks. Re-run at `k=25`, every convenio chunk that was in the old top-8 is still present at an identical score.

**Gold tests, answer level** (`eval/gold-answer-run.php`, the real loop on staging's deployed image, so only the chunks changed underneath it): Navarra periodo de prueba → **15/30**, `official_convenio`, cited to the convenio. Gipuzkoa vacaciones → **31 naturales / 26 laborables**, `official_convenio`, cited **only** to the Gipuzkoa convenio — scope isolation holds even with five Estatuto article-37 chunks in the pool. Trabajo a distancia, both profiles → `national_law`, cited to doc 75, grounded in Ley 10/2021. All four match the Sprint 2c gold claims.

---

## 5. What was built

| # | Step | Where |
|---|---|---|
| 1 | `hasZeroProseChunks()` extracted from `proseCell()`, with an equivalence test | `CorpusCoverageService` |
| 2 | `classifyProseGap()` with the D3 document-existence guard | `CorpusCoverageService` |
| 3 | Chunker F1 + F2 + guard 5, 30 unit checks against the real doc-75 source | `hr-ai/app/chunking/chunker.py` |
| 4 | Snapshot, re-chunk + re-embed doc 75, gold tests before and after | staging |
| 5 | Fallback trigger + national-law-only retrieval branch, re-rank skipped | `ChatService::retrieveUnion` |
| 6 | Caveat via `decorate()` at `persistTurn`; migration M1; `EscalationExplainer` entries | `ChatService`, `EscalationExplainer` |
| 7 | `MessageTrace` type, `TracePanel` branch, reason label + filters | `hr-frontend`, `EscalationController` |
| 8 | Seeded profiles, both eval sets, this document | `eval/`, `ChatTestUserSeeder` |
| 9 | Falsification probe | §9 below |

**Migration M1** adds `estatuto_fallback_gap` to the `escalation_cards.reason` CHECK constraint, using the same introspect-drop-readd idiom as its five predecessors, with PRIOR/CURRENT sets and a working `down()`. It is the only migration in the sprint. Applied to staging (18.27 ms).

**The frontend is three small additive changes.** `MessageTrace` gains `prose_gap` and `floor_decision.fallback`. `TracePanel` gains a "Cobertura del convenio" step, placed *before* "Recuperación" because it explains what was searched — on the fallback branch the convenio filter was dropped, and on the expired branch nothing was retrieved at all — and the decision line now says `base: Estatuto (mínimos legales)` when the answer was built that way. Analítica needed **no** change: its escalations-by-fix table is a raw `group by reason, sub_outcome, fix_action, …` over `escalation_cards`, so the new reason and its four sub-outcomes appear as their own rows automatically. The board and History filter dropdowns did need the new value added, or HR could not isolate it.

---

## 6. Invariants

`Sprint10aInvariantTest` — **13 tests**, T1–T12 plus T5b.

The two worth naming. **T6** asserts that every chunk reaching `/synthesise` on the fallback path is `national_law` and that the precedence re-rank recorded that it was skipped — with no convenio side there is nothing to adjudicate, and a rerank block would imply an adjudication that never happened. **T8** asserts that a full-gap convenio which *does* carry a verified reference fact answers from the fact, never from the Estatuto: `structured_reference` outranks `national_law`, and that does not change because prose happens to be empty. T8 is only meaningful because it first asserts the fixture still classifies as `never_ingested`; the fact's source document is a `reference_source`, deliberately outside `KnowledgeMap::PROSE_TYPE_CODES`, and the test asserts that too — attaching a prose document there would flip the classification and quietly hollow the test out.

`Sprint7cAdditivityRegressionTest` is green and byte-for-byte unchanged throughout. That depends on one detail worth stating explicitly: `floor_decision.fallback` is **absent** on every turn that did not take the branch — not `false`, not `null`. An always-present key would have ended the additivity claim.

---

## 7. Eval harness

`eval/fallback-gold.json` (15 questions) + `php artisan estatuto:gold-eval --profile=positive|negative|second-negative`.

Unlike `succession:gold-eval` this is **not** read-only: a chat turn is the unit under test, so each question persists a session, two messages, citations and a trace exactly as an employee asking it would. The command therefore refuses any address that is not a seeded `test-*@example.com` account — a typo pointing it at a real employee would write machine-generated turns into that person's own conversation.

### 7.1 Positive set — `test-fullgap@` (convenio 7, `never_ingested`)

10 of 13 answerable questions answered, all grounded, all with the caveat on the persisted row. **All four article-37 questions answered** (descanso semanal, matrimonio, fallecimiento, festivos) — those were the ones blocked on F2, so they are the direct payoff of the chunker work. Both salary questions escalated `salary_coverage_gap` with no fallback key.

Check-A top score across the set: min 0.5561, median 0.6214, max 0.7123.

Three questions did not answer, and none of them is a fallback defect:

- **Q6** *"¿Qué preaviso me deben dar en un despido objetivo?"* — the sensitive-topic guardrail fires pre-router, on every profile. This is the product behaving correctly; dismissal goes to a human. The plan listed Q6 as "clean", which was written without checking it against the guardrail layer. **The gold set was corrected, not the guardrail** — the correction and its reason are recorded in the fixture itself.
- **Q5** *"¿Cuánto preaviso tengo que dar si me voy?"* and **Q13** *"¿Cuánto dura el permiso por nacimiento?"* — both escalate `low_confidence`, Q13 with `grounded = false`. These are the per-claim grounding gate declining to entail a synthesised answer, an answer-loop behaviour that predates this sprint and was explicitly out of scope. Worth noting for a future sprint rather than patching here.

**Non-determinism, disclosed:** Q4 escalated on the first run and answered on the second, so the honest figure is 9–10 of 13. The loop involves three LLM calls; some flap at the margin is expected. Nothing about the *split* flapped across either run.

### 7.2 Negative sets — the trigger-split test

The plan calls this the most important row in the eval, and it holds absolutely: **not one question was answered from the Estatuto on either profile, and no turn carried a fallback key.**

`test-andalucia@` (convenio 4 — historical text *with* 151 chunks): 10 questions reached the fallback decision and refused it with `estatuto_fallback_gap`; 2 were stopped by an earlier gate. `test-midingest@` (convenio 16 — historical text with *zero* chunks): 12 reached the decision and refused it; 1 stopped earlier.

The earlier gates are the loop working as designed, not near-misses. Q6 is the guardrail again. Q3/Q4 on convenio 4 escalate `reference_fact_coverage_gap` because that convenio *has* a verified periodo-de-prueba fact and the test employee has no job category to scope it to — which is T8's invariant showing up in live data: the reference-fact pre-check still runs ahead of the prose path.

**One harness correction made mid-run, on the record.** My first version asserted that `floor_decision.fallback` must appear only on *answering* turns, and flagged three violations. That assertion was wrong. `stampFallback()` is applied at all four exits of `answerProse()` deliberately: a turn that took the fallback branch and then failed a gate really was searched against national law alone, and a trace that hid that would misdescribe what produced the escalation. Spec §2.3(e) is narrower — it says the *salary* escalations must carry no fallback key, which they do not. The metric was corrected to match the spec and the design, with the reasoning recorded in the code. My first negative-set verdict logic was wrong in the same way, reporting "TRIGGER SPLIT BROKEN" when nothing had been answered; it now asserts the invariant itself (no answer, no fallback key) and reports the reason without judging it.

---

## 8. Seeding (CP-3) and a D5 correction

**D5 named convenio 16 for the positive profile, and D3 disqualifies it.** Convenio 16 holds document 29, a `convenio_text` marked `historical` with zero chunks — so under D3 it is `expired_only`. Seeding the positive eval there would have tested the fail-closed guard, not the fallback.

This is a plan-versus-review ordering artifact rather than an error in either: under the plan's looser predicate (zero *active* prose documents) convenio 16 does read as a full gap. D3 was the review addition that tightened it to any status, and D5 was chosen against the earlier predicate.

Across the whole staging corpus exactly three convenios satisfy D3, and D5 rules out the third:

| id | numero | name | territory |
|---|---|---|---|
| 7 | 99016085012007 | ACCIÓN E INTERVENCIÓN SOCIAL ESTATAL | Estatal |
| 27 | 48001455011981 | LOCALES Y CAMPOS DEPORTIVOS | Vizcaya |
| 28 | DEV-FIXTURE-0001 | *(excluded by D5)* | — |

**Convenio 7** was chosen and approved. It has no document of any type, no salary table, no job category and no reference fact — each of which would otherwise short-circuit the prose turn before the fallback is reached.

**Convenio 16 became a second negative control** (`test-midingest@example.com`), which is a net gain: it is the only real instance in the corpus of the D3 shape, which until now existed solely as a test fixture. The two negative profiles now reach `expired_only` by different routes — one by document-existence with chunks present, one by D3's addition with no chunks at all.

All four eval accounts were verified against real staging data before running anything:

| account | convenio | active prose w/ chunks | prose docs any status | chunks any status | classification |
|---|---|---|---|---|---|
| `test-fullgap@` | 7 | 0 | 0 | 0 | **never_ingested** |
| `test-andalucia@` | 4 | 0 | 1 | 151 | **expired_only** |
| `test-midingest@` | 16 | 0 | 1 | 0 | **expired_only** |
| `test-navarra@` | 22 | 1 | 1 | 75 | **covered** |

Both new accounts are on the `deploy.md` scrub list. The reason for the convenio change is recorded in the seeder comment as well as here, so the next reader finds it at the code.

---

## 9. Falsification probe (step 9) — result: zero

**The question, from plan §D.10.2:** for each of the four allowlisted clarify parameters, is there any question on this corpus where the grounded answer differs depending on that parameter, *and* where the loop does not already produce the conditional itself?

**Method.** Take every answered prose turn on staging — 39 of them, being the ~10 pre-existing plus the 29 produced by this sprint's eval runs. Including the eval turns strengthens the probe rather than diluting it: they are Estatuto answers, and Estatuto articles are exactly where conditionality on contract type and seniority is most likely to appear. Screen each answer with a keyword pass for the four parameters and for conditional markers, then **read every candidate** the screen surfaces, because a keyword match is not evidence that the answer differs by that parameter.

**Screen results.** 5 candidate turns. **37 of 39 answered turns (94.9%) already state a conditional themselves.**

**Reading the 5 candidates:**

| question | flagged | verdict |
|---|---|---|
| ¿cómo funcionan los contratos fijos discontinuos? | contract_time | Definitional — the question is *about* the contract type; the answer does not differ by the asker's own. Not a candidate. |
| ¿cuántos días de vacaciones tengo? | contract_time | **The loop already produces the conditional**, naming both branches: *"si trabajas a tiempo completo… 9 días adicionales; si trabajas a tiempo parcial… 4"*. My screen missed it (its pattern lacked "si trabajas"). And `employment_type` is a required directory field, so the loop could resolve it without asking anyone. |
| ¿puedo trabajar a distancia? | reference_year | False positive — the year came from the citation *Ley 10/2021*. |
| ¿Qué permiso tengo por fallecimiento de un familiar? | absence_type | The absence type was supplied by the question itself. Not a candidate. |
| ¿qué vacaciones tengo? | seniority | False positive — "antigüedad" appears because vacation *choosing order* runs by seniority, which the answer states in full. The entitlement does not vary. |

**Count, per parameter: `contract_time` 0, `seniority_years` 0, `reference_year` 0, `absence_type` 0.**

This confirms the plan's §A argument with measurement instead of reasoning. Two of the four parameters were already directory columns, which the spec's own R1 rule excludes; the remaining two produce nothing on this corpus. Candidate 2 is the strongest single piece of evidence, and it points the opposite way from the spec: faced with a genuinely contract-time-dependent rule, the loop stated both branches and let the employee pick, which is a better outcome than a clarifying round-trip.

**Nothing was built from this**, per the authorization. ADR-0031 stays reserved and unwritten. If the clarify turn returns as Sprint 10d it should be specced against this result, with answer *precision* as the metric rather than escalation conversion — and, per plan §B.4.1, built as a generalisation of the existing `needs_category` turn rather than as a new state machine.

---

## 10. Docs updated

- **New:** `architecture/decisions/0032-estatuto-fallback.md`.
- `architecture.md` §5 — the Sprint 10a entry: trigger, the split and its legal basis, the retrieval branch, the caveat's placement, the four-exit stamp, and the re-chunk as prerequisite.
- `data-model.md` — `escalation_cards.reason` gains `estatuto_fallback_gap`; `floor_decision.fallback` and the top-level `prose_gap` documented, including the absent-not-false rule.
- `deploy.md` — the two new test accounts on the scrub list (D5); the expired-convenio data-pass item for convenios 4, 21 and 16 (D7).
- `roadmap.md` — Sprint 10a entry, which also closes the Sprint 8 "re-chunk the Estatuto" follow-up (on a different trigger than the one Sprint 8 was watching for — Sprint 8's evidence query still reads zero); Sprint 10b ticket for `explicit_request` never being emitted (D6); the clarify deferral with the probe result.
- `spec.md` §8 — the plan-gate addendum (D8): the re-scope, R4 being wrong, the matrix count, the revised eyes-on list, and new item §6.7.
- The stale "39-entry matrix" count corrected to **48** in the living docs and dated, so it cannot rot silently again. Sprint-dated documents keep their original figures, which were true when written.

---

## 11. Staging state — what was changed and how to undo it

Staging currently runs **uncommitted `sprint-10a` code**, injected into the running containers. This is unavoidable while the no-commit rule holds, because `deploy.sh` only deploys pushed SHAs.

| change | how to revert |
|---|---|
| `document_chunks` for doc 75 (235 → 210 rows) | restore snapshot `hr-staging-pre-10a-rechunk` |
| Migration M1 (`escalation_cards.reason` CHECK) | `migrate:rollback` (working `down()`), or the snapshot |
| `hr-ai`: `/app/app/chunking/chunker.py` | image copy preserved at `/tmp/chunker.image.py` in the container; or `docker compose up -d --force-recreate hr-ai` |
| `hr-backend` (pre-Correction-01): `ChatService`, `CorpusCoverageService`, `EscalationExplainer`, `EscalationController`, `EstatutoGoldEval`, `ChatTestUserSeeder` | ⚠ checked while writing the Correction-01 update below: no `/tmp/image-*` backups actually exist on the box (this table's earlier claim was wrong — corrected here so it can't be relied on again). Revert path is `docker compose up -d --force-recreate hr-backend hr-backend-worker` (drops the writable layer, falls back to the Sprint 8 image), then re-inject any of these six files that should stay, from this repo's working tree. |
| **Correction-01, `hr-backend` (this update): `ChatService::FALLBACK_CAVEAT`, `ConversationPresenter::present()`/`sourceLabels()`, `ChatController::message()`** | `docker compose cp`'d straight into the running `hr-backend` container (`/var/www/app/...`), same mechanism as the row above — **not** into `hr-backend-worker`/`hr-backend-scheduler` (neither serves the chat endpoints; confirmed by md5sum that the worker's copy of `ChatService.php` was still Sprint-8-era before this update, so the original Sprint 10a injection never touched it either — precedent followed exactly). No on-container backup of the PRE-Correction-01 bytes was taken before overwriting (same gap as the row above, not repeated going forward). To revert: `ChatController.php`/`ConversationPresenter.php` pre-Correction-01 = this repo's `HEAD` (`git show a54c640:app/Http/Controllers/ChatController.php` etc. — Correction-01 is the first change to either file this sprint); `ChatService.php` pre-Correction-01 = the current working-tree file with `FALLBACK_CAVEAT` reverted to the wording quoted in `correction-01.md`'s Findings section. Verified live via `php artisan tinker` evaluating `App\Services\ChatService::FALLBACK_CAVEAT` and `method_exists(ConversationPresenter::class, 'sourceLabels')` post-copy — opcache (`validate_timestamps=On`, `revalidate_freq=2`) picked up the change without a restart. |
| **Correction-01, `hr-frontend` (this update): the built bundle Caddy serves** | Until this update, staging's frontend bundle had never actually been rebuilt past Sprint 8 (`git log` on `/opt/hr-staging/hr-frontend` showed `c05ed19`, and its `TracePanel.tsx` had no `prose_gap` step) — the whole Sprint 10a frontend diff (steps 7 + Correction-01) was live in this repo but had never reached staging. Fixed the same motion, frontend-shaped: `rsync`'d this repo's current `hr-frontend` working tree (minus `.git`/`node_modules`/`dist`) over `/opt/hr-staging/hr-frontend`, `docker compose build frontend-dist`, `up -d --force-recreate frontend-dist` (one-shot; writes the fresh `dist/` into the shared `frontend-dist` volume Caddy mounts read-only — Caddy itself needs no recreation, confirmed: it serves whatever `index.html` points at off that volume, re-read from disk on every request, and the freshly-built hashed bundle (`index-DtBH2_JM.js`) was live within seconds). Verified by fetching that exact bundle over `http://localhost/` on the box and grepping it: contains `"Basado en"` (the new source line), does not contain `"Fundamentado en"` (the superseded caption). **Housekeeping, not fixed here:** `frontend-dist`'s `cp -rT /dist /out` overlays rather than wipes, so `/srv/frontend/assets/` has ~15 stale hashed bundles from every previous build accumulated across the whole staging history — harmless (only the current `index.html` is ever linked-to and served), out of this correction's scope. Revert: check out `hr-frontend` to `c05ed19` on the box, rebuild, force-recreate `frontend-dist` again — or, once this sprint is committed, a real `deploy.sh` run. |
| **Correction-02, `hr-backend` (this update): `EscalationController::REASON_LABELS` + new `employeeContext()`** | `docker compose cp`'d into the running `hr-backend` container, same mechanism as the Correction-01 row above — not into `hr-backend-worker`/`hr-backend-scheduler` (neither serves admin board endpoints). md5sum-verified byte-identical to the local file both before and after the copy (no backup needed for revert: the pre-Correction-02 file is `git show a54c640:app/Http/Controllers/Admin/EscalationController.php`, or the current working tree with the C2-1/C2-2 diffs reverted). Verified live via `php artisan tinker`: `REASON_LABELS['estatuto_fallback_gap']`, `REASON_LABELS['quality_sample_wrong']`, and `method_exists(..., 'employeeContext')` all read correctly off the running process. |
| **Correction-02, `hr-frontend` (this update): the built bundle Caddy serves** | Same mechanism as Correction-01's frontend deployment: `rsync` the current working tree, `docker compose build frontend-dist`, `up -d --force-recreate frontend-dist`. New hashed bundle `index-CtC_yXbm.js` confirmed live at `http://<STAGING_EIP>/` (`index.html` points at it); fetched and grepped directly: `"Convenio vencido / sin texto vigente"`, `"Muestra de calidad incorrecta"`, `"Antigüedad"`, `"Categoría / grupo"` present, `"Hueco en el texto del convenio"` — zero occurrences. Revert: `rsync` the pre-Correction-02 working tree (or check out `a54c640`), rebuild, force-recreate `frontend-dist` again. |
| Two seeded test employees | on the `deploy.md` scrub list |
| ~90 eval chat turns under test accounts | covered by the pre-go-live traffic wipe already in `deploy.md` |

Any future `deploy.sh` run reverts every code injection automatically (backend AND frontend both — a clean deploy rebuilds `frontend-dist` from the real checkout too). The snapshot should be deleted once this sprint is merged and settled.

---

## 12. What I would flag to a reviewer

**The one thing to check hardest** is the D5 → convenio 7 change in §8. It is the only place I departed from the written authorization, and although the reasoning is recorded in three places, a reviewer should confirm that D3's predicate is what you actually intended — because if D3 was meant to be looser, convenio 16 goes back to being the positive profile and the second negative control disappears.

**Two answer-loop behaviours surfaced that are not this sprint's to fix.** Q5 and Q13 escalate on `low_confidence` against Estatuto articles that plainly contain the answer; the per-claim grounding gate declines to entail the synthesised text. And `explicit_request` is in the reason enum but no code path emits it (D6, now a Sprint 10b ticket).

**Eyes-on is not done.** ⏸ **CP-4** is the remaining checkpoint: spec §6.3–§6.6 plus the new §6.7. If there is only time for one item, run §6.7 — the expired-convenio negative is the item that demonstrates the sprint's central decision, and it is the one whose failure would matter most.

---

## 13. Correction-01 (CP-4 eyes-on, step 1)

**Source.** CP-4 eyes-on, step 1 (employee view, `test-fullgap@`), before merge — still on `sprint-10a`, still uncommitted. Three findings, all employee-presentation, none an answer-loop change. Full authorization saved verbatim at `hr-docs/sprints/sprint-10a/correction-01.md`.

- **E1** — `[Fuente N]` markers rendered literally in the employee answer text.
- **E2** — the fallback caveat exposed internal system state ("todavía no está cargado en el sistema") and rendered raw `**`/`---` markdown.
- **E3** — the employee chat shipped the full FUENTES excerpt block and the "Cómo llegué a esto" trace (router confidence, model name, chunk counts) — admin material, not an employee surface.

### E3 pre-existence — the required finding

**The channel is pre-existing, since Sprint 2b-1. This sprint made what travels through it more sensitive.**

- `ChatController::message()`'s original commit (`b2114a4`, Sprint 2b-1) already does `$result = $chat->handleMessage(...); return response()->json($result);` — the FULL service-layer result, `trace` included, returned to the employee verbatim. Nothing between then and this sprint changed that shape.
- `ChatScreen.tsx`'s original commit (`54a63e4`, same sprint) already renders `<CitationList citations={response.citations} />` and `<TracePanel trace={response.trace} />` directly under the answer — unconditionally, for every answered turn.
- `ConversationPresenter` (Sprint 4, card-detail + `GET /chat/session`) inherited the identical shape for session hydration: `'citations' => $this->citations(...)`, `'trace' => $trace`, with no audience branching at all before this correction — the `employee`/`admin` distinction it already had only ever governed the human-reply author label, never trace/citation visibility.

**So visibility itself is not new. What is new, and worth flagging precisely because the user asked for this even though "it determines nothing about the fix": this sprint's own additions to the trace schema (`prose_gap`, `floor_decision.fallback`, ADR-0032) put more sensitive CONTENT through that pre-existing, always-open channel.** On the `expired_only` escalation path, the pre-correction trace read:

```
trace.prose_gap.classification = "expired_only"
trace.floor_decision.note = "convenio prose exists but is not retrievable —
  the Estatuto fallback is not allowed to substitute for it (ADR-0032)"
```

— sent, pre-correction, straight to the employee's own browser on that turn, and rendered by `TracePanel`'s new (this-sprint) `prose_gap` step as literal Spanish naming "ultraactividad" and the fact that the convenio "existe pero no es recuperable". `Sprint10aInvariantTest::test_t9b_the_employee_never_learns_which_gap_caused_the_escalation` asserts this never leaks — but only by grepping `result['answer']`; it never looked at `result['trace']`, so it could not have caught this. That test is unchanged and still correct on its own terms; it was simply never the only thing standing between an employee and this specific leak. E3's fix (below) closes it as a direct side effect, verified by the new `test_e3_the_employee_live_turn_response_has_no_trace_key` (which primes an *answerable* turn, but the same `unset($result['trace'])` line in `ChatController::message()` runs unconditionally, on every outcome).

### What changed

1. **E1 (frontend, display-only).** `hr-frontend/src/lib/citationMarkers.ts` — `stripSourceMarkers()`, a pure regex transform (`/\[Fuente\s+\d+\]/g` — the exact pattern hr-ai's own `_renumber_markers` uses) plus whitespace/punctuation tidy-up. Applied in `ChatScreen.tsx`'s `AnswerBlock` only, at render time. `chat_messages.content` and every API response still carry the raw markers unchanged — proved by `test_e3_the_stored_row_and_admin_tables_are_untouched_by_the_reshaping` and by the live-turn test asserting `str_contains($result['answer'], '[Fuente 1]')` is still true. Admin views (`CitationList`/`TracePanel` on `HistoryPage`, `EscalationCardDrawer`, `QualitySampleQueue`) render `chat_messages.content` untouched, as before — they never call `stripSourceMarkers()`.
2. **E2 (backend, `decorate()`'s constant only).** `ChatService::FALLBACK_CAVEAT` replaced verbatim with the authorized plain-text wording; separator changed from `"\n\n---\n"` to `"\n\n"` (a paragraph break, not a markdown rule); `**mínimos legales**` bold removed; "todavía no está cargado en el sistema" removed. `decorate()` and its append point (`persistTurn`, after every gate) are byte-for-byte unchanged — only the constant's value moved. T5/T5b (`ChatService::FALLBACK_CAVEAT`-constant-referencing assertions) needed no edit — they compare against the constant, not a literal — and both still pass; their `'mínimos legales'` substring checks hold against the new wording too. `EstatutoGoldEval.php`'s caveat check (`str_contains('mínimos legales') && str_contains('convenio colectivo')`) likewise needed no edit — both phrases survive in the new wording — verified by re-reading, not re-run (the gold eval persists real turns against seeded data and is out of this correction's scope; the new wording is independently proven by the updated unit test below).
3. **E3 (server is the boundary).** `ConversationPresenter::present()` now branches on audience: `AUDIENCE_ADMIN` is untouched (full `trace` + full `citations`, byte for byte); `AUDIENCE_EMPLOYEE` omits `trace` from the array entirely (absent key, not null) and returns `citations: []` plus a new `source_labels` field (deduped document display names only — no `chunk_id`/`page`/`snippet`/`authority_level`). `ChatController::message()` (the live turn) applies the identical reshaping, via the same new `ConversationPresenter::sourceLabels()` helper, to `handleMessage()`'s return value just before `response()->json()` — `handleMessage()` itself, everything it persists (`message_traces`, `message_citations`, `chat_messages`), and every other call site (`Sprint10aInvariantTest`'s `ask()` helper calls `handleMessage()` directly and is completely unaffected) are unchanged. `ChatScreen.tsx`'s `AnswerBlock` no longer imports or renders `CitationList`/`TracePanel`; it renders one `Basado en: <source_labels joined>.` line instead, and only when `source_labels` is non-empty (escalation/needs_category turns already had `citations: []`, so this is a no-op for them). The superseded grey "Fundamentado en…" caption (`authorityCaption`/`AUTHORITY_LABELS` in `ChatScreen.tsx`) is deleted outright — it had no admin equivalent to preserve (confirmed by search: it existed only in this one employee-facing file).

### Tests added

- `hr-frontend/src/lib/citationMarkers.test.ts` — 6 cases (single marker, multiple markers, marker-before-punctuation, no-op on marker-free text, double-digit index, and the negative case: a bracketed number that is *not* a Fuente marker, e.g. `[14]` in "el artículo [14] del Estatuto", must survive). Required standing up `vitest` for `hr-frontend` (no JS test runner existed before this correction) — added as a devDependency, `npm run test` → `vitest run`.
- `hr-backend/tests/Feature/Sprint10aCorrection01Test.php` (new file, 5 tests) — hits the REAL HTTP routes (`postJson('/chat/message', …)`, `getJson('/chat/session', …)`), deliberately not `handleMessage()` directly, because this correction's whole claim is about the response boundary:
  - live turn has no `trace` key, `citations === []`, `source_labels` correct, and the raw `[Fuente 1]` marker still present in `answer` (E1 is frontend-only);
  - the persisted row and `message_citations`/`message_traces` rows are unaffected;
  - session hydration has the same no-`trace`/`source_labels` shape;
  - the admin presenter (`ConversationPresenter::present(..., AUDIENCE_ADMIN)`) on the SAME session still returns full `trace` and a non-empty citation `snippet`, and has no `source_labels` key at all;
  - the caveat constant contains no `**`/`---`/"todavía no está cargado"/"en el sistema", contains the required phrases, and matches the authorized string exactly (`assertSame`).
- **One pre-existing test fixed, not written for this correction:** `Sprint6GuardrailInvariantTest::test_raising_retrieval_floor_flips_an_answer_to_an_escalation` read `$result['trace']['floor_decision']['retrieval_score_floor']` off the live `/chat/message` response — the one other place in the suite that hit the real endpoint. Updated to read the same value back from `MessageTrace` (the admin-visible, fully-populated persisted row) instead; the test's actual guarantee (raising the floor flips answer→escalate) is unchanged and still verified end-to-end through the real HTTP route.

### Regression verification

- `Sprint7cAdditivityRegressionTest` — green, and inspected directly: it exercises no fallback turn at all (`test_prose_turn_is_byte_for_byte_unchanged_and_precheck_falls_through`, `test_salary_turn_is_byte_for_byte_unchanged_and_precheck_never_runs`, `test_a_source_with_no_monthly_column_yields_an_annual_only_answer` — none touch `decorate()`), so the caveat-string exception this correction was granted was never actually exercised; there is accordingly **zero** byte difference in this test's golden traces, fallback or non-fallback.
- Full backend suite: **624/624 passed** (2,886 assertions) on a clean run. (A first run showed 6 failures — 5 were `CoverageLeafResolutionTest` deadlocks from parallel workers racing `RoleSeeder`'s permission inserts, unrelated to this correction and gone on re-run in isolation; the 6th was the `Sprint6GuardrailInvariantTest` fix above, genuinely caused by E3 and fixed as described.)
- Frontend: `tsc -b --noEmit` clean; `vitest run` 6/6; `eslint` clean on every file this correction touched (pre-existing `react-hooks` lint errors in four untouched admin files — `EscalationCardDrawer.tsx`, `GroupsQueue.tsx`, `HistoryPage.tsx`, `AdminShell.tsx` — are unrelated to this correction and were not introduced by it).

### Not touched, by design

`floor_decision`, `Check A`/`Check B`, `/ground`, `persistTurn`'s deterministic append point, everything written to `message_traces`/`message_citations`/`chat_messages`, `EscalationExplainer::MATRIX`, and every non-employee endpoint/presenter branch/component. `ChatService::handleMessage()`'s return *value* is unchanged; only `ChatController::message()` reshapes a copy of it immediately before serialising the HTTP response.

**Then STOP**, per the authorization — Pedram re-runs eyes-on steps 1–2 plus one admin check (trace still fully visible at `admin@`), then continues CP-4 from step 3.

---

## 14. Correction-02 (CP-4 eyes-on, step 6)

**Source.** CP-4 eyes-on, step 6 (escalation board), before merge — still on `sprint-10a`, still uncommitted. Two findings: C2-1 (merge blocker, label collision/coverage) and C2-2 (pre-existing bug, fixed opportunistically). Full authorization saved verbatim at `hr-docs/sprints/sprint-10a/correction-02.md`.

- **C2-1** — `estatuto_fallback_gap` shared its display label ("Hueco en el texto del convenio") with a generic prose-gap reason on the board/filter, and `reference_fact_coverage_gap` was suspected of the same gap in the filter dropdown.
- **C2-2** — the card-detail modal shows no employee context (name/email/territory/category-group/seniority) for `escalation.work` viewers.

### C2-1 — what the label map(s) actually contained (the required finding)

The report was asked for regardless of outcome. Investigated fresh (not from memory), including cross-checking the LIVE staging container's copy of `EscalationController.php` against the local working tree — they were identical, so the finding below is what Pedram actually saw on the board too, not just a local-tree artifact.

- **No literal string collision found.** `EscalationController::REASON_LABELS` — the one map that actually drives the board badge, on both `index()` (list) and `show()` (detail), via `cardSummary()`'s `reason_label` field — already had `'estatuto_fallback_gap' => 'Hueco en el texto del convenio'` as a unique value; nothing else in that map, or in `EscalationExplainer::MATRIX`'s `fix_action`/`found` text for `low_confidence`'s sub-outcomes, produces the identical string.
- **`quality_sample_wrong` (added Sprint 8) had no entry at all** in `REASON_LABELS` — the one genuine "no distinct label" bug in the backend map, silently falling through to the raw reason string on the badge.
- **The frontend filter dropdowns were the real site of the `reference_fact_coverage_gap` gap.** `EscalationBoardPage.tsx`'s `REASON_FILTERS` and `HistoryPage.tsx`'s `REASONS` are two independently hand-copied 8-entry arrays (`'', low_confidence, off_domain, sensitive_topic, explicit_request, salary_coverage_gap, estatuto_fallback_gap, conflict`) — missing **three** of the ten live enum values as filter options entirely: `reference_fact_coverage_gap`, `salary_not_in_chat`, `quality_sample_wrong`. `reference_fact_coverage_gap` DID have a correct, distinct backend label (`'Hueco en datos de referencia'`, added Sprint 7g) — it just could not be selected in either filter dropdown.
- **Analítica had no label lookup of any kind.** `AnalyticsPage.tsx`'s §3 "Escalaciones por corrección" table and §4 clusters table rendered `row.reason`/`c.top_escalation_reason` as raw strings — an admin would see the literal text `estatuto_fallback_gap`, not any label, colliding or otherwise.
- **`GuardrailsPage.tsx`'s own `REASON_LABELS` (5 entries) is a different, narrower, correctly-scoped map** — it labels only `GuardrailPolicy::CONVERTIBLE_REASONS_BASELINE` (`low_confidence`, `salary_coverage_gap`, `off_domain`, `explicit_request`) plus the hardcoded `locked: ['sensitive_topic']`, verified against `GuardrailsController@index`'s actual response shape — confirmed complete for its own scope, not touched.
- The full live `escalation_cards.reason` enum (introspected from the Postgres CHECK constraint, migration `2026_09_11_120000_...`): `low_confidence, sensitive_topic, off_domain, explicit_request, conflict, salary_not_in_chat, salary_coverage_gap, reference_fact_coverage_gap, quality_sample_wrong, estatuto_fallback_gap` — 10 values. (`REASON_LABELS` also carries `sensitive`/`legal_medical`/`other_employee` as legacy/defensive keys that are not, or are no longer, live enum values — harmless, left alone, explicitly excluded from the guard test's collision check for that reason.)

**Conclusion:** the collision as literally described (`estatuto_fallback_gap` and a generic prose-gap reason sharing one string) does not reproduce in the current code on either the local tree or staging. What the audit found instead — real, verified, now fixed — is a mix of a missing backend label, three missing frontend filter options, and a completely unlabelled Analítica surface. The rename to a more specific, self-evidently-distinct label (`'Convenio vencido / sin texto vigente'`) was applied anyway, exactly as authorized, since it directly serves the same goal (an unambiguous badge for the one reason where the system deliberately refuses to fall back to the Estatuto) independent of the precise collision mechanism.

### What changed

1. **`EscalationController::REASON_LABELS`** — added `'quality_sample_wrong' => 'Muestra de calidad incorrecta'`; renamed `'estatuto_fallback_gap'` to `'Convenio vencido / sin texto vigente'` (the suggested wording, verbatim).
2. **New `hr-frontend/src/lib/escalationReasons.ts`** — `ESCALATION_REASON_LABELS` (10 entries), `ESCALATION_REASON_FILTERS` (derived, `+ 'Todos los motivos'`), `escalationReasonLabel()` (raw-string fallback, mirrors the backend's `?? $card->reason` pattern). `EscalationBoardPage.tsx` and `HistoryPage.tsx` now import this instead of each keeping its own array; `AnalyticsPage.tsx` now calls `escalationReasonLabel()` in both tables instead of rendering the raw string.
3. **New `EscalationReasonLabelCoverageTest.php`** — introspects the LIVE Postgres CHECK constraint (`pg_get_constraintdef`, the same idiom the reason-enum migrations themselves use — never a hand-copied list in the test that could itself drift) and asserts: every live enum value has an explicit `REASON_LABELS` entry (catches the `quality_sample_wrong`-shaped bug), and no two live enum values resolve to an identical label (catches the literal C2-1-shaped bug, if it ever recurs). Both assertions verified to actually fail pre-fix: temporarily removing `quality_sample_wrong`'s entry failed the first; temporarily renaming `estatuto_fallback_gap`'s label to `'Baja confianza'` (colliding with `low_confidence`) failed the second — then both reverted and the suite confirmed green.

### C2-2 — what changed

1. **`EscalationController::show()`** — new `employee_context` (object or null) and `employee_context_restricted` (bool) response keys. Gated by `$actor->can('escalation.work')` specifically — narrower than the pre-existing `conversation`/`conversation_restricted` gate two lines above it, which also accepts `history.view_all`. New private `employeeContext(EscalationCard $card): ?array` reads `full_name`, `email`, `territory` (`{id, name}`), `job_category` (`{id, name}`), `convenio_group` (`{id, path_label}` via the existing `ConvenioGroup::pathLabel()`), and `seniority` (`{start_date, years}` or `null` when `start_date` is unset — "where recorded" means exactly this: no derived/guessed date). The extra eager-loaded relations (`employee.territory`, `.jobCategory`, `.convenioGroup`, `.convenioGroup.parent`) are added ONLY to `show()`'s `$card->load([...])` call — `index()`'s own eager-load, and `cardSummary()` (shared by both endpoints), are untouched, so the board's list payload does not grow.
2. **No new access-log write.** Inspected `EscalationController::show()` directly: there was no dedicated access-log call on this read path to begin with (unlike `HistoryController`'s `ConversationAccessLogger`, a different, broader full-history browsing surface, or `QualitySampleController`'s use of the same). There was nothing existing to reuse, and per the explicit constraint, nothing new was added either.
3. **`EscalationCardDrawer.tsx`** — new `EmployeeContextBlock` function component: its own `<section><h4>Empleado</h4>…</section>`, placed right after the existing card-summary section and before `ExplanationBlock` — mirrors that component's own established pattern (a standalone function component per detail concern). Uses the SAME `dl.kv` definition-list markup as the summary block three lines above it, and the SAME `notice notice--neutral` + 🔒 pattern already used for the conversation-restricted case, for the `escalation.work`-missing case. No new CSS class, no new component idiom.

### Tests added

- `EscalationReasonLabelCoverageTest.php` (2 tests, described above).
- `Sprint10aCorrection02EmployeeContextTest.php` (5 tests): `escalation.work` (`hr_agent`) sees the full block with correct territory/category/group/seniority; seniority is `null` when `start_date` is unset while the rest of the block is still present; `history.view_all`-only (`auditor`) still sees the conversation (unaffected, pre-existing gate) but not the employee block; `knowledge_editor` (neither ability) sees neither; the board LIST endpoint's cards never carry `employee_context` or `employee.email` on any card — a server-side proof of "detail modal only", not just an absence in one frontend component.

### Regression verification

- Full backend suite: **631/631 passed** (2,919 assertions) on a clean run — 624 pre-existing (post-Correction-01) + 2 (`EscalationReasonLabelCoverageTest`) + 5 (`Sprint10aCorrection02EmployeeContextTest`).
- Frontend: `tsc -b --noEmit` clean; `vitest run` 6/6 (unchanged — no new frontend unit tests were needed here, the frontend logic is a straight lookup/render); `npm run build` clean; `eslint` on every file this correction touched — the same 4 pre-existing `react-hooks` errors already present on `sprint-10a` before this correction (confirmed by running `eslint` against a `git stash` of this correction's changes) and zero new ones.

### Not touched, by design

The answer/chat loop (Correction-01's territory), `EscalationExplainer::MATRIX` (a different, internal fix-guidance registry keyed `reason.sub_outcome`, out of scope for a display-label bug), `GuardrailsPage.tsx`'s own narrower label map (confirmed correct for its scope), `escalation_events`/the "Actividad" timeline, every card WRITE path (assign/move/reply/resolve — unchanged), the board's list-view card shape (`EscalationCardSummary`/`cardSummary()`), any ability/permission definition (`escalation.work` reused, not created), any access-log table or write call.

### Staging re-injection

`EscalationController.php` — `docker compose cp`'d into the running `hr-backend` container, same mechanism as Correction-01's backend injection; md5sum-verified byte-identical to the local file both before and after the copy. Verified live via `php artisan tinker`: `REASON_LABELS['estatuto_fallback_gap']` reads `'Convenio vencido / sin texto vigente'`, `REASON_LABELS['quality_sample_wrong']` reads `'Muestra de calidad incorrecta'`, `method_exists(EscalationController::class, 'employeeContext')` is `true` — all off the running PHP process, not just off disk. `hr-frontend` — `rsync`'d the current working tree, `docker compose build frontend-dist`, `up -d --force-recreate frontend-dist` (same mechanism as Correction-01's frontend deployment). Verified by fetching the live site's new hashed bundle (`index-CtC_yXbm.js`) and grepping it: `"Convenio vencido / sin texto vigente"`, `"Muestra de calidad incorrecta"`, `"Antigüedad"`, `"Categoría / grupo"` all present; `"Hueco en el texto del convenio"` — zero occurrences, fully superseded, not just shadowed. All staging services healthy (`hr-backend`/`hr-ai` report `(healthy)`; `caddy`/`hr-backend-worker`/`hr-backend-scheduler` up); site returns `200`.

**Then STOP**, per the authorization — no commit, no merge, no further action beyond this.
