# Sprint 2b-2 — Correction 01 (pre-commit): entailment gate over-escalates on attributive meta-claims

> Paste the block below into the Sprint 2b-2 Cursor thread. All §10 staged gates passed; the salary year-alignment was cross-checked against the real xlsx. One pre-commit quality fix: the per-claim entailment gate **over-escalates answerable questions**, with run-to-run variance, because synthesis emits **attributive meta-sentences** ("esta cifra está establecida en tu convenio") that are true but not literally entailed by any chunk — so the gate flags them and escalates a correct answer. The fix must be **precise**: remove the meta-claim problem **without** re-opening the fabrication hole. Re-verify **both** directions. **Do not commit until I review.**

---

Sprint 2b-2 is approved **pending one pre-commit quality fix**. The entailment gate (`/ground`) escalates some **answerable** questions (e.g. navarra-jornada escalated while gipuzkoa-jornada answered; Q10 answered standalone but P10 escalated) with run-to-run variance. Root cause: synthesis adds **attributive/provenance meta-sentences** (e.g. "esta cifra está establecida en tu convenio", "según el Estatuto…") which are *true* but not literally *entailed* by any cited chunk, so `/ground` flags them ungrounded and escalates a correct answer. This is the safe direction (never fabricates) but undercuts deflection and is inconsistent. Fix it precisely.

**The distinction to encode (do not blur it):**
- A **substantive claim** = the actual answer content: a rule, figure, entitlement, duration, condition, or scope. These **must** be grounded — unchanged.
- An **attributive / provenance claim** = a statement about *where* the answer comes from ("according to your convenio", "this is established in the Estatuto", "tu convenio establece que…"). Provenance is the **citation's** job (the `[Fuente N]` markers + the authority badges + the "Fundamentado en…" footer already convey it). These are **not** substantive claims to entail.

**Fix 1 — synthesis stops generating attributive meta-sentences (primary fix, in `hr-ai` synthesise prompt).** Instruct synthesis to **state the substantive answer and let the citation carry the provenance** — do **not** write sentences asserting which document a claim comes from. The authority/precedence framing the answer still needs (convenio governs / Estatuto baseline) is expressed through **which source each substantive claim cites**, not through a prose meta-sentence. This removes the ungroundable sentence at the source.

**Fix 2 — `/ground` judges substantive claims only (reinforcement, in `hr-ai` ground prompt).** When decomposing the answer into claims, **a provenance/attributive sentence is not a substantive claim** and is not subject to entailment. **Precision guard (critical):** this exemption applies **only** to pure provenance statements — a claim that asserts a *rule, figure, entitlement, duration, condition, or scope* is **always** substantive and must be grounded, even if phrased with an attributive wrapper ("tu convenio te da **31 días**" → the "31 días" is substantive and must be entailed; only a bare "esto está en tu convenio" with no substantive content is exempt). Do **not** let a fabricated substantive claim escape by being labelled "meta."

**Re-verify BOTH directions (the fix is wrong if either fails):**
1. **Consistency (the over-escalation is gone):** navarra-jornada, gipuzkoa-jornada, and the Q10 / P10 compound case — ask each **2–3 times** — answerable questions now **answer consistently** (no run-to-run flip to escalation), grounded and cited.
2. **Fabrication still caught (the hole did not re-open):** the constructed **non-numeric over-claim** from gate (b) ("seguro médico gratuito / coche de empresa") **still escalates**; **P10's ungrounded "9 días adicionales"** substantive over-claim **still escalates**. If either now passes, the substantive/meta line was drawn too loosely — tighten it.
3. **Gold tests intact:** Gipuzkoa vacaciones (31/26, `official_convenio`), Navarra periodo de prueba (15/30, `official_convenio`), trabajo a distancia (`national_law`) — all still answer and pass the gate.

**Record:** `architecture.md` §5 (the substantive-vs-provenance distinction in synthesis + grounding), `data-model.md` §8 if the grounding trace block gains a claim-type, and `review.md` (Correction-01: the finding, the precise fix, the both-directions re-verify). Then **STOP — do not commit until I review** the re-verify (over-escalation gone AND fabrications still caught).

---

After Cursor reports, paste it back. I'll confirm the answerable cases answer consistently **and** the two fabrication cases still escalate — that both-directions check is the whole point — then we commit and **2b is complete**.
