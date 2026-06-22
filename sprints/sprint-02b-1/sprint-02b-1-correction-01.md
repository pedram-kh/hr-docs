# Sprint 2b-1 — Correction 01 (pre-commit): silent-convenio fabrication

> Paste the block below into the Sprint 2b-1 Cursor thread. The precedence gold test (Gipuzkoa vacaciones) **passed and was verified against the real convenio**. The silent-fallback gold test (Limpieza Navarra *periodo de prueba*) **failed**: the answer **fabricated** convenio probation figures (15/30 días, 6 meses), attributed them to the convenio (`authority_used = official_convenio`), and cited a single **unrelated** chunk (*Art. 36. Euskera*) — on a topic this convenio is silent about (it defers to the Estatuto, as the 2a recovered-doc probe established). It passed `A=✓ B=✓` because Check A scores off-topic chunks and Check B only checks provenance, not entailment. **This blocks commit.** Diagnose, fix, re-run **both** gold tests, record it, and **STOP — do not commit**.

---

The Sprint 2b-1 build is approved **except for one pre-commit correction**. The silent-convenio case produces a **confidently fabricated legal answer**, which is the exact failure the floor exists to prevent. Fix the root cause, do not break the passing precedence case, and re-verify both.

**Step 0 — diagnose (report before fixing).** For the failing query (`test-navarra@…`, *"¿cuánto dura el periodo de prueba?"*, convenio 22), dump the **8 retrieved chunks** with their `document` (convenio vs `national_law`), `authority_level`, page, score, and first line. Answer one question explicitly: **was the Estatuto's *periodo de prueba* article (Art. 14 ET) in the retrieved set?**
- If **no** → there is also a **retrieval-recall gap** (the on-topic national-law chunk didn't surface for an on-topic query). Note it; the fix below still applies, and the doc-level recall question is flagged for 2b-2.
- If **yes** → it is purely a **synthesis fabrication** (the model had the right chunk and ignored it). The fix below is sufficient.

**Fix 1 — synthesis must not fabricate; abstain or fall back instead (the core fix).** Harden the `/synthesise` system prompt:
- **State a claim ONLY if it is directly supported by a supplied chunk.** Do **not** use general knowledge of Spanish labour law to fill gaps. (The fabricated "6 meses para emprendedores" came from training knowledge, not any chunk.)
- **A citation must point to the chunk that actually states the claim** — never the nearest available chunk. If no supplied chunk states a claim, that claim may not appear in the answer.
- **When the supplied chunks do not answer the question:** if a `national_law` (Estatuto) chunk addresses it, answer from that (`authority_used = national_law`, cited to the Estatuto); **otherwise do NOT answer** — return a low-confidence / abstain signal so `hr-backend` escalates. Never assert a convenio rule the convenio chunks don't contain.
- Reaffirm the precedence rule already in place (convenio governs where it speaks; Estatuto fills gaps) — this fix is its silent-side complement.

**Fix 2 — add a deterministic figure-grounding guard in `hr-backend` (cheap backstop; not the full 2b-2 grounding model).** After synthesis, before surfacing: for each **load-bearing figure** in the answer (a number with a unit — `N días`, `N meses`, `N horas`, currency amounts), verify the **same figure appears in at least one *cited* chunk's text**. If a load-bearing figure is **absent from all cited chunks → escalate** (`reason: low_confidence`, trace note "answer figure not grounded in cited chunk"). Keep it conservative: only fire when a figure is entirely absent from the cited chunks, and the action is **escalate** (the safe direction), never silent edit.
- This must **distinguish the two gold tests**: Gipuzkoa vacaciones — `31` and `26` appear verbatim in the cited convenio chunk → **passes**. Navarra periodo de prueba — `15`/`30`/`6` appear in **no** cited chunk (only an Euskera chunk was cited) → **escalates**. Confirm both.
- This makes Check B *sufficient* for 2b-1's common cases; the full per-claim entailment grounding stays 2b-2.

**Re-run BOTH gold tests after the fix (do not break the pass):**
- **Gipuzkoa vacaciones** — must still answer **31 días**, cited to the convenio, `authority_used` includes `official_convenio`, A=✓ B=✓, figure-guard passes. (Regression: the fix must not make the *passing* case abstain.)
- **Navarra periodo de prueba** — the acceptable outcomes are now **either** (a) a correct **Estatuto-grounded** answer, cited to the Estatuto's Art. 14, `authority_used = national_law`; **or** (b) a clean **escalation** (`low_confidence`) if the Estatuto chunk isn't retrieved. **A fabricated convenio answer is the only failing outcome.** Report which occurred and the trace.

**Record:**
- `architecture.md` §5 — extend the answer-or-escalate note: synthesis abstains/falls-back rather than fabricating; the figure-grounding guard as a deterministic backstop to Check B; full entailment grounding remains 2b-2.
- `data-model.md` §8 — add the figure-grounding result to the `floor_decision` trace block.
- `review.md` — append a Correction-01 section: the diagnostic (the 8 chunks + whether the Estatuto chunk was retrieved), the two fixes, and the re-run results for **both** gold tests.
- `roadmap.md` — under 2b-2, note that the **full per-claim grounding check** must supersede the 2b-1 figure-guard backstop (so the cheap guard isn't mistaken for the real thing later).

Then **STOP — do not commit** until I review the diagnostic + both re-run results.

---

After Cursor reports, paste the diagnostic and both re-run traces back. I'll verify the Navarra outcome is either a correctly-grounded Estatuto answer or a clean escalation — never a fabrication — and that Gipuzkoa still passes. Then we commit 2b-1.
