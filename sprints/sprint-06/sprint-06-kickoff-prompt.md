# Sprint 6 — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate, plan, and **stop** — no guardrail-config code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–5 are built and committed (answer engine, Knowledge Center, escalation board + flywheel, access-control layer; Sprint-5 Correction-01 fixed the conflict fence). **This is Sprint 6 — guardrails configuration UI** — the admin layer on top of the hardcoded safety baseline (pillar §7).

Before anything, read in full:
- `hr-docs/architecture/architecture.md` §7 (the **two guardrail layers** — hardcoded baseline vs admin-configurable) and §5 (the answer-or-escalate decision: **Check A** `RETRIEVAL_SCORE_FLOOR`, **Check B** citations, **Check C** confidence-as-*tiebreaker*; "both floors are named config in `config/hr.php`, Sprint-6-exposable but additive-only — raise, never lower")
- the real **`GuardrailService`** (the hardcoded baseline patterns — sensitive / legal-medical / other-employee — firing *before* any `hr-ai` call) and **`RouterService`** (off-domain classification + the deterministic salary pre-classifier)
- **`config/hr.php`** — the actual `RETRIEVAL_SCORE_FLOOR` / `ANSWER_CONFIDENCE_FLOOR` constants and any other guardrail-relevant config
- where **`/synthesise`** is assembled in `hr-backend` (so tone guidance can be injected into the prompt without touching `hr-ai`)
- **ADR-0002** (guardrail vocabulary), **ADR-0007** (hr-backend owns writes), **ADR-0015/0016**; the Sprint-3/5 **bounded-edit + server-enforced + audited + ability-gated** patterns (`EnsureCan`, the audit-log writers); the Sprint-4 note that "restrict `convert` by escalation reason is a Sprint-6 guardrails-policy matter"
- `roadmap.md` Sprint 6; `hr-docs/sprints/sprint-06/spec.md`
- the existing admin shell (Knowledge Map, Escalation board, Directory/History/Admins) + the design system

Your task this turn: **inspect the real substrate and plan — write no guardrail-config code.**

Produce `hr-docs/sprints/sprint-06/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists today (reality check).** Show the **real** `config/hr.php` floor constants (values + where they're read in the answer-or-escalate decision); the **real** `GuardrailService` hardcoded patterns (what's matched, where it fires); the `RouterService` off-domain path; and **where `/synthesise` is assembled** (the injection point for tone). Confirm there is **no** guardrail-config table yet (so this sprint is the first to make guardrails data). Show real code/lines.
2. **The config store.** The shape of the additive **`guardrail_config`** (a typed single-row config vs a key/value settings table — recommend one), **global** (no per-scope), hr-backend-owned, with a **hardcoded floor per field** it validates against. How the answer loop reads it **cheaply** (cached/lightweight, not a heavy per-turn query). The audit-on-change writer (reuse the existing append-only audit pattern) and the **`guardrails.manage`** ability (which roles).
3. **The five knobs + how the engine reads each (`stricter_of(baseline, admin)`).** For each — (1) thresholds raise-only, (2) blocked topics add-only, (3) off-domain boundary+message narrow-only, (4) tone constraints, (5) convert-by-reason restrict-only — state **exactly** where the engine applies the admin layer **on top of** the baseline (the `max`/`union`/`stricter` point), and confirm the baseline stays the floor. For tone: the injection point in the synthesis prompt and **how tone guidance is constrained so it cannot instruct the model to bypass a gate** (it styles; it can't unlock).
4. **The raise-only invariant — server enforcement.** The validation that **rejects** (not clamps) a threshold below the hardcoded floor, with the clear message; the impossibility of removing a baseline pattern; the `stricter_of` application. Specify the **tests** that POST below-floor / weakening values and assert rejection/no-effect — this is the acceptance proof.
5. **The UI.** The Guardrails console (reuse the admin shell): each knob with its value + **inline hardcoded floor**, reject-below-floor validation, the change-history view, `guardrails.manage`-gated writes, `auditor` read-only.
6. **Migrations & build order.** Across `hr-backend` (the config store + floors + audit + `guardrails.manage` + the engine read-points + the synthesis tone injection) and `hr-frontend` (the console). List every **additive migration**. Flag whether a new **ADR** is warranted (likely — the raise-only safety-config model, cf. ADR-0018).
7. **Assumptions & open questions** — esp. the config-store shape, the tone-can't-bypass mechanism, whether convert-by-reason lives in this config or beside the escalation model, and any place the real `GuardrailService`/`config/hr.php` shape makes the `stricter_of` wiring non-obvious.

Hard constraints:
- **Additive / raise-only, enforced server-side.** No setting can weaken the baseline; the engine applies **`stricter_of(baseline, admin)`** always; a below-floor value is **rejected with a clear message** (not clamped). The hardcoded `GuardrailService` patterns stay in **code** and are **not** editable from the UI.
- **No answer-loop rewrite** — Sprint 6 feeds *config* into the existing Check-A/B/C + guardrail/router decision; it does not change how the decision is computed (2b frozen). **`hr-ai` is unchanged** — tone guidance reaches `/synthesise` through the **existing** `hr-backend` prompt assembly; no new hr-ai endpoint, no hr-ai migration.
- **`hr-backend` owns all writes + schema; additive migrations only.** Every config change **audited**; writes gated by `guardrails.manage`; `auditor` read-only. Server is the boundary (the UI shows the floor, the server enforces it).
- Reuse the **design system** + the admin shell + the `EnsureCan` + audit-writer patterns.

Do not create or modify any file other than `hr-docs/sprints/sprint-06/plan.md` this turn. After writing it, stop and say it is ready for review.
