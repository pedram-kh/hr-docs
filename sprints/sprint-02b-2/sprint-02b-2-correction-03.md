# Sprint 2b-2 — Correction 03 (pre-commit): baseline-over-convenio precedence + cross-path compound

> Paste the block below into the Sprint 2b-2 Cursor thread. The confidence round surfaced a **baseline-over-convenio** class bug (logged in `review.md` §15): on compound/vague queries the governing convenio chunk ranks below the synthesis cap, so synthesis answers from the **Estatuto baseline** instead of the **convenio grant** — grounded, but to the **wrong authority**, which the entailment gate is structurally blind to. Plus a **cross-path compound** limitation (Q10) where the salary pre-classifier silently drops the prose half. Fix both as the **interim** correction ahead of the scheduled article-boundary re-chunk (roadmap §7). Re-verify against the corpus. **Do not commit until I review.**

---

Two pre-commit fixes. Neither is caught by the entailment gate (the defects are in retrieval/ranking and routing, upstream of `/ground`), so both need **corpus-grounded re-verification**, not just gate-pass.

## Fix 1 — precedence re-rank over a widened candidate pool (the baseline-over-convenio class)

Root cause (review.md §15): for a vacaciones query the convenio's governing chunk (Navarra 7721, "37 días laborables") ranks **#15**, outside the **k=8** synthesis cap, while the Estatuto vacaciones chunk (7305, "30 días naturales") ranks #3 and reaches synthesis — so the answer gives the **national baseline** for a **convenio-governed** topic.

- **Widen the candidate pool before truncation.** Retrieve a larger top-N (≈20–25) per pass, then re-rank, **then** truncate to the synthesis cap — so a governing convenio chunk sitting at ~#15 can be promoted into the set instead of being discarded before re-ranking ever sees it. (Today's re-rank operates on k=8, which is why 7721 never had a chance.)
- **Precedence re-rank:** when both `official_convenio` and `national_law` chunks cover the **same topic** in the widened pool, boost `official_convenio` above `national_law` so the convenio's governing chunk displaces the baseline in the truncated set.
- **Safety in both directions:**
  - **Convenio governs** (a convenio chunk on the topic exists) → it now reaches synthesis → answer cites the convenio.
  - **Convenio genuinely silent** (no convenio chunk on the topic, e.g. trabajo a distancia) → nothing to boost → the national_law chunk correctly fills the gap. The re-rank must **not** suppress national_law when the convenio is silent.
- This is the **interim** fix; it compensates for the buried-grant chunking artifact. The durable fix is the **article-boundary re-chunk** scheduled in roadmap §7 — do **not** attempt re-chunking here.

## Fix 2 — aggregation / vague-total queries escalate (Q3 shape)

A "¿cuántos días libres me dan al año **en total**?" asks for an arithmetic aggregation across vacaciones + festivos + permisos + asuntos propios — **not a single grounded fact**. Summing leave types into one total is unsupported synthesis even with the right figures in hand.
- Detect the aggregation/vague-total shape (e.g. "en total / en conjunto / todo / todos los días libres" across leave types) → **escalate** (or return a narrow prompt to pick one leave type). Keep the detector **narrow** so ordinary single-topic questions (vacaciones, permisos) are unaffected.

## Fix 3 — cross-path (salary + prose) compound is not silently half-dropped (Q10)

Today the deterministic salary pre-classifier fires on any salary keyword and short-circuits the **whole** turn to the SQL path, dropping a prose clause in the same question.
- **If the salary pre-classifier matches but the question also contains a clear non-salary clause** (a conjunction + a second topic / a second question mark) → do **not** short-circuit; hand to the LLM router for **cross-path decomposition**: salary clause → SQL path, prose clause(s) → prose path, then compose both answers (salary figure + grounded prose), escalating only the half that fails (e.g. a salary coverage gap) while still answering the other half.
- **Minimum bar if full cross-path decomposition is too large here:** at least **detect** the cross-path compound and **escalate-with-note** ("puedo darte la parte salarial por las tablas, pero tu pregunta sobre vacaciones necesita una persona") so the prose half is **never silently dropped**. State in `review.md` which you implemented.

## Re-verify (corpus-grounded, both directions — the fix is wrong if any fails)
1. **Baseline-over-convenio fixed:** Navarra vacaciones on **compound/vague phrasings** (e.g. "vacaciones y periodo de prueba", "¿qué vacaciones tengo?") now answers **37 días laborables** cited to **convenio chunk 7721**, `authority_used` = `official_convenio` for the vacaciones figure — **not** 30 días cited to the Estatuto. Confirm 7721 now reaches synthesis. Run 3–5× for consistency.
2. **Silent topic still falls back:** trabajo a distancia (gipuzkoa/navarra) still → `national_law` (the re-rank did not suppress the baseline where the convenio is genuinely silent).
3. **Aggregation escalates:** "¿cuántos días libres me dan al año en total?" → escalate (or narrow-prompt), not a computed total.
4. **Cross-path compound:** "¿cuánto cobra un peón y cuántas vacaciones tiene?" on a **salary-answerable** convenio (not Gipuzkoa's coverage gap — use the COEAS/Cantabria salary-answerable profile) → both halves answered (salary from SQL + vacaciones grounded), **or** escalate-with-note — never salary-only with the prose half silently dropped.
5. **No regression:** the three gold tests (Gipuzkoa 31/26, Navarra prueba 15/30, trabajo a distancia → national_law), and the round-2 PASS set (esp. Q1 false-premise rejection → 37, Q5 permisos, Q7 finiquito → sensitive_topic, Q9 jornada citation match). Confirm the entailment gate, figure-guard, and guardrail baseline all still behave.

## Record
`architecture.md` §5 (the widened-pool precedence re-rank + aggregation-escalate + cross-path decomposition; note these are retrieval/routing fixes upstream of the gate, and that the re-rank is interim ahead of the §7 re-chunk), `data-model.md` §8 (the `retrieval.passes` / re-rank fields + any cross-path `router_decision` shape), `review.md` §15 → Correction-03 with the both-directions corpus re-verify results. Then **STOP — do not commit until I review.**

---

After Cursor reports, paste it back. I'll verify against the corpus that Navarra vacaciones compound now returns **37 cited to 7721** (the literal inversion of the bug), that silent topics still fall to national_law, and that nothing regressed — then eyes-on → commit 2b-2 → the scheduled article-boundary re-chunk task.
