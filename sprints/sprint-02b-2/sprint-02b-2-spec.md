# Sprint 2b-2 — The complete answer surface (router + salary-in-chat + grounding + recall)

> Location: `hr-docs/sprints/sprint-02b-2/spec.md`
> Reviewer: Claude (architecture) · eyes-on test: Pedram
> Read first: `architecture.md` §2/§5/§7, `data-model.md` §8/§9 + the salary tables (§6), the 2a `/retrieve` + salary SQL paths, **ADR-0006/0007** (salary is SQL not vectors; synthesis in `hr-ai`; decision in `hr-backend`), **ADR-0012/0013/0015**, and the new **ADR-0016** (the router); `sprint-02b-1/review.md` + its **Correction-01** (anti-fabrication + figure-guard) and **Correction-02** (salary-topic guard) + the **adversarial-probe** results.
> **This is the second and final 2b slice; after it comes Sprint 3.** It completes what 2b-1 started.

## Goal
Make the employee answer surface **complete and correct across every question type**. 2b-1 proved one prose path end-to-end and was deliberately narrow (no router; salary escalates; grounding approximated by citation-coverage + a figure-guard). 2b-2 finishes it: the **router** sends each question to the right path, **salary-in-chat** gives exact SQL-grounded salary answers (the structured source — the antidote to the Q5 misaligned-table bug), the **full per-claim grounding check** catches unsupported claims the 2b-1 proxies can't, and **recall hardening** stops compound/silent questions from flipping a governed topic to the national baseline (the Q10 failure). After this slice, every question type is answered correctly or escalated honestly.

## Scope context (vs 2b-1)
2b-1 = single prose path, no router, salary blanket-escalates (Correction-02), grounding = citation-coverage + figure-guard proxy. 2b-2 adds routing, the salary SQL answer path, real grounding, and recall hardening — and **supersedes** two 2b-1 stopgaps: the salary-escalation guard (now salary *answers* via SQL) and the figure-guard (now the per-claim grounding check is the real gate).

## In scope

### A. The router (small LLM) — ADR-0016
- A small/fast LLM call (reusing the ADR-0015 provider + `hr-backend`-owned key, server-side) classifying each question **salary-lookup / prose-policy / off-domain**, **after** the hardcoded guardrail baseline (sensitive / other-employee still escalate first — the router never sees them).
- **Fail-safe:** router uncertainty or error → the safe path (prose + the answer-or-escalate floor, or escalate) — **never a silent misroute**. Decision + confidence in the trace (`router_decision` — already a trace field, `null` in 2b-1).
- off-domain → escalate. Salary → the SQL path (B). Prose → the 2b-1 synthesis path (now with the real grounding check, C).

### B. Salary-in-chat (SQL-grounded)
- A salary question routes to the **2a salary SQL path**: resolve the employee's **job category** + **as-of year** → query `salary_tables` / `salary_table_rows` → compose an **exact, structurally-aligned** answer (the figure bound to its category and year by construction — what the embedded-table prose chunk destroyed in Q5). Cite the `salary_table` (document + year) and **state the category + year explicitly** so there's no ambiguity.
- **Coverage-gap honesty:** if the employee's category/year has **no** `salary_table_rows` (the visible 2a gaps) → **escalate honestly** ("I don't have your salary table for that year yet"), never guess. This is the "check `salary_tables`, answer-or-escalate" logic — landing on the structured source, never the lossy chunk.
- **Supersedes the Correction-02 salary guard** (which escalated all salary questions); salary now answers from SQL or escalates on a genuine gap.
- **Category resolution (single-turn) — the agreed behavior:** salary lookup needs the employee's `job_category`.
  1. Use the **employee profile's** category when present (the directory that manages it is Sprint 5; seeded test profiles must set it) — the bot should rarely need to ask.
  2. If the profile category is **absent or ambiguous**, the bot may ask the employee to **pick from the categories that actually exist in their convenio** (`convenio_job_categories`) — a constrained choice, **not** a free-text answer. The chosen category is treated as **unverified** and is **shown in the answer** ("Para la categoría *Oficial de primera*…") so any mismatch is visible to the employee.
  3. **Never** accept a self-declared category as ground truth that silently grounds a salary figure — a self-reported fact must never become an authoritative, cited answer (a wrong category → a cited-but-wrong salary band = a dispute generator).
  4. If **no salary table exists at all** for the convenio/year (a coverage gap) → **escalate** — asking the employee can't conjure a document the system doesn't have, so don't loop; hand to a person.
- **This slice stays single-turn** (question → answer / disambiguating-pick / escalate). A fuller **agentic multi-turn clarifying** chatbot (asks follow-ups, gathers missing facts, then answers) is a deliberate, larger design decision **parked for after 2b** (roadmap §7) — not smuggled in through the category dependency, because a clarifying loop is a new place for scope/precedence/guardrails to go wrong and deserves its own scoping.

### C. Full per-claim grounding check
- **Replaces** 2b-1's citation-coverage + figure-guard proxy. For each claim in a synthesized prose answer, verify it is actually **supported (entailed) by a cited chunk** — not merely that a citation exists (2b-1 Check B) or that a digit appears somewhere (the figure-guard).
- **Must catch the non-numeric unsupported claim** (a prose assertion the cited chunk doesn't support — the gap the figure-guard structurally can't see) and **must be table-aware** (never fooled by "the digits are in the chunk" on tabular data — Q5's lesson; salary now goes via SQL, but the grounding check must not regress to digit-presence).
- A claim that fails grounding → **escalate** (or drop-and-re-evaluate; plan decides granularity), conservative direction. The figure-guard may remain a fast pre-check feeding this; the **entailment check is the real gate**.

### D. National-law retrieval recall hardening (incl. compound queries)
- Fix the recall gap the probe surfaced (Q10 + the Art. 14 ET miss): for a topic the convenio is **silent** on, the relevant **Estatuto article must reliably reach the eligible set**; and a **compound** question must not dilute retrieval such that a **governed** topic flips to the baseline.
- The **requirement** (not the mechanism — plan decides among: higher national-law retrieval depth, a dedicated national-law pass, **query decomposition** of compound questions, re-ranking): re-running **Q10** (vacaciones + periodo de prueba, Navarra) grounds **both** sub-topics from the **convenio** (no baseline flip), and the silent-fallback (trabajo a distancia) still resolves to `national_law`.

### E. Citation-marker numbering
- In-text `[Fuente N]` markers map **1:1** to the displayed FUENTES list (the 2b-1 paper-cut where the text said `[Fuente 4]` with two sources shown). Small, but trust-relevant in an audit-first tool.

## Out of scope (do NOT build — later sprints)
- **History-search UI + role-scoped access enforcement** → **moved to Sprint 5** (decided), where the roles that govern *who can search whose conversations* are enforced. 2b-1 already shipped history *storage* (role-scoping-ready); the *search UI* belongs with the access control that scopes it — building it earlier would mean retrofitting that control later.
- Lens hierarchy admin UI → Sprint 3. Escalation board → Sprint 4. Guardrails config UI / admin-tunable thresholds → Sprint 6. LLM tagging tier → Sprint 7. Analytics → Sprint 8.
- The deferred **2a wage-table-chunk cleanup** (parked; the salary guard + salary-in-chat make it lower-risk, but a non-salary question could still retrieve a table chunk — the per-claim grounding check (C) is the backstop).

## Acceptance criteria
1. The router classifies salary / prose / off-domain after the guardrail baseline; **fail-safe** on uncertainty/error (safe default, never silent misroute); decision + confidence in the trace.
2. A salary question returns an **exact SQL-grounded** answer (right category + year, cited to the salary table) **or** escalates honestly on a coverage gap — **never** a figure read from a prose chunk. **Q5 re-run:** answered correctly from SQL with the **right year** (the 2024 figure is the real 2024 figure), or escalated on gap.
3. The per-claim grounding check catches a **non-numeric** unsupported claim (verified with a constructed case) and is table-aware; ungrounded claim → escalate.
4. **Recall:** Q10 (compound) grounds **both** sub-topics from the convenio (no baseline flip); trabajo a distancia still → `national_law`.
5. Citation markers map **1:1** to the displayed sources.
6. **Regression:** all three gold tests + the nine passing adversarial probes still behave; the salary guard's blanket escalation is correctly **superseded** by salary-in-chat (salary answers / gap-escalates, not always-escalates); the guardrail baseline (sensitive / other-employee) still fires before the router.
7. Nothing out-of-scope; `hr-ai` still writes only `document_chunks` + S3, never migrates; `roadmap.md` updated (2b complete); `deploy.md` updated if the router uses a distinct model/endpoint (still EU, same key envelope).

## Eyes-on / stress-test gates
- **Salary gold test:** test-gipuzkoa "¿salario base de un limpiador?" → the **exact SQL figure for the right category + year**, cited to the salary table — and explicitly verify the **year alignment Q5 got wrong** (2024 row = the real 2024 figure). Plus a coverage-gap category → escalate.
- **Router:** a salary q → SQL; a policy q → prose; an off-domain q → escalate; a deliberately ambiguous q → fail-safe (not a confident misroute).
- **Grounding:** a constructed non-numeric over-claim → escalate.
- **Recall:** Q10 compound → both grounded from the convenio.
- **Regression:** the three gold tests + the nine passing probes.

## Open decisions (resolve at the plan / with Pedram)
- **Router model:** same provider (Claude) via ADR-0015 infra with a smaller/faster model (default), or a different small model?
- **Grounding mechanism:** LLM entailment per claim (default, conservative) vs a lighter approach — and its escalate-vs-drop granularity.

---

### Scoping decisions (settled)
- **History-search → Sprint 5** (coupled to role-scoped access enforcement). 2b stays two slices; 2b-2 is the coherent "complete the answer surface" slice.
- **Agentic multi-turn clarification → parked (roadmap §7)**, to be scoped deliberately after 2b. 2b-2 salary stays single-turn (profile category → constrained disambiguating pick → escalate).

## Definition of done
All criteria pass; eyes-on on the running chat (the salary year-alignment check is the one that matters most); docs updated in the same change — `architecture.md` §5 (router + salary path + grounding), `data-model.md` (router_decision populated, grounding result in the trace), `roadmap.md` (2b complete; history-search relocation if confirmed), `deploy.md` if the router endpoint differs, `ADR-0016` honoured; the `hr-ai`/`hr-backend` READMEs. Cursor writes `sprint-02b-2/review.md` and **stops** — no commit.
