# Sprint 2b-2 — Cursor kickoff prompt (plan-gate)

> Paste the block below into a **fresh** Cursor thread with the `hr-platform/` workspace open. It must **plan first and stop** — no building until the plan is reviewed.

---

You are in the `hr-platform` workspace (`hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs`). Sprints 0, 1, 2a, and **2b-1** are built and committed (2b-1 incl. Correction-01 anti-fabrication + figure-guard, and Correction-02 salary-topic guard). **This is Sprint 2b-2 — the second and final 2b slice: complete the answer surface.** After it comes Sprint 3.

Before doing anything, read in full:
- `hr-docs/architecture/architecture.md` (esp. §2 the Laravel↔Python contract, §5 the pipeline, §7 guardrails)
- `hr-docs/architecture/data-model.md` (esp. §6 the salary tables, §8/§9 the chat/trace/escalation tables incl. the `router_decision` trace field that is `null` in 2b-1)
- every file in `hr-docs/architecture/decisions/` — esp. **ADR-0006** (salary is SQL, never vectors; a figure must be exact), **ADR-0007** (synthesis in `hr-ai`; scope + all writes + the decision in `hr-backend`), **ADR-0012/0013/0015**, and the new **ADR-0016** (the router)
- `hr-docs/sprints/sprint-02a/review.md` (the salary SQL path + `convenio_job_categories`) and `hr-docs/sprints/sprint-02b-1/` — the `review.md`, **Correction-01**, **Correction-02**, and the **adversarial-probe** results (Q5 salary-from-table bug, Q10 compound-query recall flip)
- `hr-docs/sprints/sprint-02b-2/spec.md`
- `hr-docs/glossary.md`, `hr-docs/deploy.md` §1, and the `AGENTS.md` in each code repo

Your task this turn is to **plan Sprint 2b-2 only — do not write any code, migrations, or config yet.**

Produce a single file `hr-docs/sprints/sprint-02b-2/plan.md`, then **STOP and wait for review.** The plan must cover:

1. **Build order** across repos + dependencies — what `hr-ai` gains (the router call; the per-claim grounding check; both reuse the ADR-0015 provider), what `hr-backend` gains (routing orchestration; the salary SQL answer path; the category-resolution flow; superseding the Correction-02 guard and the figure-guard), what `hr-frontend` gains (salary answers + the constrained category-pick UI + citation-numbering fix). What this slice consumes from 2a (`/retrieve`, the salary SQL) and 2b-1.
2. **The router (ADR-0016):** the small-LLM classification call (salary / prose / off-domain), reusing the ADR-0015 provider + `hr-backend`-owned key (server-side, never the browser); **runs after the hardcoded guardrail baseline** (sensitive / other-employee escalate first — the router never sees them); **fail-safe** (uncertainty/error → safe prose+floor path or escalate, never silent misroute); the decision + confidence written to the `router_decision` trace field. Whether the deterministic salary patterns (Correction-02) remain as a high-precision pre-classifier feeding the router.
3. **Salary-in-chat (SQL-grounded):** route a salary question to the 2a salary SQL → resolve **job category + as-of year** → query `salary_tables`/`salary_table_rows` → an **exact, structurally-aligned** answer citing the salary table, stating category + year. **Coverage gap → escalate honestly.** This supersedes the Correction-02 blanket salary escalation.
4. **Category resolution (single-turn — spec §B):** profile category if present (Sprint-5 directory manages it; seeded profiles set it) → if absent/ambiguous, a **constrained pick from the convenio's actual `convenio_job_categories`** (not free text), treated as **unverified** and **shown in the answer** → **never** let a self-declared category silently ground a cited salary figure → no table at all → escalate. **Single-turn only** — the fuller agentic multi-turn clarifier is parked (record it in `roadmap.md` §7).
5. **Full per-claim grounding check:** replaces 2b-1's citation-coverage + figure-guard proxy. Per-claim entailment against the cited chunks; **must catch a non-numeric unsupported claim** and be **table-aware** (never fooled by digit-presence on tabular data — Q5's lesson). Ungrounded claim → escalate (or drop-and-re-evaluate — propose the granularity). The figure-guard may remain a fast pre-check; the entailment check is the real gate.
6. **National-law recall hardening (incl. compound queries):** the requirement is that a **silent-convenio** topic reliably reaches the relevant Estatuto article, and a **compound** question doesn't dilute retrieval into a baseline flip (Q10). Propose the mechanism (national-law retrieval depth, a dedicated national-law pass, **query decomposition** of compound questions, re-ranking) — and how you'll prove it (Q10 → both sub-topics grounded from the convenio; trabajo a distancia still → `national_law`).
7. **Citation-marker numbering:** in-text `[Fuente N]` ↔ the displayed FUENTES list, 1:1.
8. **Trace + regression:** how `router_decision` and the grounding result land in the trace; and how you'll run the **regression set** (the three gold tests + the nine passing adversarial probes) to confirm no behavior regressed and that the salary guard's blanket escalation is correctly superseded.
9. **Assumptions & questions:** the router model choice (default: same Claude provider, smaller/faster model); the grounding mechanism + escalate-vs-drop granularity; how `as-of year` is chosen for salary (most-recent table ≤ as-of, or exact); anything the spec or the 2a/2b-1 substrate leaves ambiguous.

Hard constraints (from the docs):
- **Salary is SQL, never read from a vector/prose chunk** (ADR-0006); a salary figure must be **exact and year-aligned** (the Q5 bug). **Synthesis in `hr-ai`** (ADR-0007); `hr-backend` resolves scope, owns ALL writes + the answer-or-escalate decision; `hr-ai` writes **only** `document_chunks` + S3, never migrates.
- **The router runs after the guardrail baseline and is fail-safe;** the browser never sees the key and never calls a provider; the router uses the ADR-0015 key path.
- **Self-reported facts never silently ground a cited answer** (the category-pick is unverified + shown).
- **Conservative, audit-first:** answer only when grounded; otherwise escalate. The hardcoded guardrail baseline (sensitive / other-employee) is unchanged and still fires first.
- GDPR is **deploy-time** (`deploy.md` §1); if the router uses a distinct model/endpoint it must still be EU and is noted there.
- Build **nothing** out-of-scope (spec "Out of scope"): no history-search UI / role-scope enforcement (→ Sprint 5), no agentic multi-turn clarifier (parked), no lens UI (Sprint 3), no escalation board (Sprint 4), no guardrails config UI (Sprint 6), no LLM tagging tier (Sprint 7), no analytics (Sprint 8); do not do the parked 2a wage-table-chunk cleanup.

Do not create or modify any file other than `hr-docs/sprints/sprint-02b-2/plan.md` this turn. After writing it, stop and say it is ready for review.
