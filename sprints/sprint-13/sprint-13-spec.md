# Sprint 13 — Spec: Agentic answer loop inside a deterministic shell

> Save as: `hr-docs/sprints/sprint-13/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Biggest engine change since 7c. The pilot will run on this version (Pedram's decision, 2026-09-28). Sprint 9 (GDPR) still follows this sprint and precedes the pilot.
> Depends on: 7c (reference-fact routing), 10a (Estatuto fallback, escalation reasons), 10b (decomposition), 10-M (models), the eval harnesses (2c gold cases, 10a negative sets, 10b situational set, 10c topic gold sets).

---

## 1. Goal

Replace the fixed classifier → route → retrieve pipeline with a **planner model choosing tools**, so ambiguous, long and multi-part questions route better and the system can ask a bounded clarifying question — **without giving up any of the guarantees the current pipeline has**: rules decide escalation, answers come only from sources, every step is traceable, and a wrong routing produces an escalation, never an invented answer.

Principle: **the model proposes; the rules dispose.**

## 2. Architecture

```
question
  → [RULES: pre-model guards]     sensitive topics, off-domain, explicit request → escalate before any model call
  → PLANNER (Sonnet 5)            sees: question, employee scope, conversation window, tool list → chooses tool(s)
  → for each tool call:
       [RULES: pre-call check]    is this tool allowed for this question/scope? (e.g. ask_employee whitelist)
       tool runs
       [RULES: post-call check]   escalation rules on the result (unverified fact, conflict, expired convenio, no salary table, low confidence)
  → PLANNER may loop (bounded)    max N tool rounds, max 2 clarifying questions per conversation
  → COMPOSITION + SYNTHESIS       unchanged: authority order, Sonnet 5 writes from retrieved material only
  → [RULES: grounding check]      unchanged: every claim entailed by sources, else escalate
  → [RULES: general-lane check]   if answered from the general lane: no figures/durations/amounts/entitlement language, else escalate
  → decision: answer | ask | escalate
```

**Escalation is both a tool and a rule.** The planner may call `escalate` when it judges a question unsafe or unanswerable (additive — more escalation, never less). Rule-driven escalations fire regardless of the planner's choices. The planner cannot suppress, skip or override a rule verdict.

## 3. Tools (v1)

| Tool | What it does | Source of truth | Notes |
|---|---|---|---|
| `salary_lookup` | SQL lookup in imported salary tables for the employee's convenio/category/year | salary_tables | unchanged from today, incl. `needs_category` |
| `reference_fact` | verified reference fact for scope + topic | reference_facts (verified, in-validity) | unchanged 7c semantics |
| `convenio_search` | semantic retrieval over the employee's convenio + applicable documents | chunks | today's prose path, incl. decomposition sub-queries |
| `national_law` | retrieval over Estatuto / national law in corpus | chunks (national_law authority) | 10a fallback machinery, now callable directly |
| `general_knowledge` | **new lane, see §4** | model knowledge + allowlisted official web sources | explanatory only, badged |
| `ask_employee` | ask one clarifying question | — | whitelist §5, max 2 per conversation |
| `escalate` | hand to HR with a reason | — | additive; rules can also force it |

Authority order for composition is unchanged: convenio > salary table > verified fact > HR ruling > national law > general knowledge. A lower authority never overrides a higher one; a contradiction escalates.

## 4. The general-knowledge lane (new)

Used only when no other tool returns material for an **explanatory** question.

- **Allowed:** definitions and how-things-work-in-general ("¿qué es una excedencia?", "¿qué significa IT?", "¿cómo funciona el periodo de prueba en general?").
- **Forbidden (rule-enforced post-check):** any figure, duration, amount, percentage, or entitlement language ("tienes derecho", "te corresponden", "puedes exigir"). If the answer contains these → discard and escalate with reason `general_lane_blocked`.
- **Sources, in order:** national-law corpus first (that's `national_law`, not this lane); then the model's own knowledge; then **allowlisted official web sources only** (BOE, mites.gob.es, seg-social.gob.es, sepe.es — list is config, client-approvable). No open web.
- **Privacy:** any outbound web query carries **no employee identity, no convenio, no scope, no PII** — the question text only, after a PII scrub. Recorded as a Sprint 9 GDPR item.
- **Presentation:** visible badge *"Información general — no procede de tu convenio ni de la normativa cargada"*, source shown (Estatuto / conocimiento general / URL), plus the escalate button (§6).
- **Trace:** distinct authority level `general_knowledge`; web sources listed with URL.
- **Controls:** its own feature flag; a toggle in Guardarraíles (super_admin) so HR can switch the lane off without touching anything else.
- **Off-domain guardrail still applies first:** mortgages, tax returns, personal legal disputes stay out (existing patterns + list).

## 5. `ask_employee` — whitelist and bounds

The planner may ask the employee only about things that clarify the **question**, never things that change the **rights**:

- **Allowed:** job category (with the existing "según tu indicación" caveat), which of several sub-questions they mean, the year/period they're asking about, whether they mean full-time or part-time when the text differs.
- **Never:** professional group, convenio, territory, seniority, contract type, salary already received. These come from the Directory; if missing, the question escalates (reason `profile_incomplete`), and the escalation card names the missing field so HR fixes the profile — the flywheel, not a workaround.
- **Bounds:** max 2 clarifying questions per conversation; after that, escalate. The planner sees the conversation window (last N turns) so a follow-up like "¿y en 2025?" inherits context — this is the multi-turn context previously parked.

## 6. Employee-facing changes

- **"¿Quieres que lo revise RR. HH.?" on every answer** — one tap creates an escalation carrying the answer given, so HR sees what the employee doubted. Reason `employee_requested_review`.
- General-lane badge (§4). Clarifying questions render as normal assistant turns.

## 7. Trace and audit

Every planner decision and every tool call is a trace step: tool, inputs (scrubbed), result summary, pre-check verdict, post-check verdict. The path may vary between runs; the **record** is complete and replayable. The trace panel and Historial render the new steps. Escalation reasons extend the shared enum (+ labels, + enum guard test): `general_lane_blocked`, `profile_incomplete`, `employee_requested_review`, `planner_escalated`, `tool_budget_exhausted`.

## 8. Feature flag and rollback

`HR_ANSWER_ENGINE = classic | agent`. Classic is today's pipeline, untouched. Agent is default on staging once CP-2 passes. The flag is a runtime switch, no deploy needed. Both engines share composition, synthesis, grounding and the employee UI.

## 9. The gate (replaces a shadow run)

Before merge, the agent engine must **match or beat classic** on every existing harness, run on the same staging data:
- 2c gold cases (must answer, correct figures)
- 10a negative sets (must escalate; 0 false answers — hard requirement)
- 10b situational set (s1–s3, decomposition cases)
- 10c topic gold sets (fact-routed answers still served via `reference_fact`)
- **New:** general-lane negative set — ≥ 20 entitlement questions that must never be answered from the general lane; ≥ 10 explanatory questions that may.
- **New:** `ask_employee` whitelist set — questions that tempt a group/convenio question; the planner must escalate, never ask.
- Cost and latency per answer reported side by side (informational — cost is not a gate; Pedram's decision).

## 10. Out of scope

Changing composition/synthesis/grounding logic; new document types; the employee UI beyond §6; any change to verification rules (ADR-0020); open web search; caching; production infra.

## 11. Checkpoints

- **CP-1:** first working loop on staging behind the flag — one salary question, one fact question, one prose question, one clarifying-question flow, one forced escalation, one general-lane answer with badge — each with its full trace visible in Historial. Pedram reviews the trace readability and the general-lane badge.
- **CP-2:** the §9 gate results, agent vs classic, side by side, plus eyes-on of §6 on desktop and phone.

## 12. Risks / plan-gate questions

- **R1:** planner prompt quality — how the tool descriptions are written decides routing quality; plan proposes them and how they're evaluated.
- **R2:** loop bounds — plan proposes N and shows the budget-exhausted path escalates cleanly.
- **R3:** the general-lane post-check — regex/pattern list for figures and entitlement language in Spanish; false positives escalate (acceptable), false negatives are the risk — plan proposes and tests.
- **R4:** PII scrub before any outbound web call — plan proposes mechanism and test.
- **R5:** conversation window — how many turns the planner sees, and what it must never see (other employees, admin notes).
- **R6:** trace volume — several steps per answer; plan checks Historial rendering and storage growth.
