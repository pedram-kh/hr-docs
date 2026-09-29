# Sprint 13 — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-13/kickoff-prompt.md`

---

You are planning **Sprint 13** in the hr-platform workspace (`hr-backend`, `hr-ai`, `hr-frontend`, `hr-docs`). Read `hr-docs/sprints/sprint-13/spec.md` first, then ADR-0020, the 7c/10a/10b review docs and the existing eval harnesses. Write `hr-docs/sprints/sprint-13/plan.md`, then **STOP — no code, no commits.**

This is the largest engine change since 7c. The plan must show, with `path:line` citations, exactly what stays untouched (composition, synthesis, grounding, verification rules) and exactly where the new loop plugs in. Where the spec and the code disagree, say so.

## A. Current pipeline, mapped

1. Trace today's `/chat` path end to end: pre-model guards → router → retrieval paths (salary SQL, reference fact, prose, Estatuto fallback) → composition → synthesis → grounding → decision. Cite each step. This is the `classic` engine; it must remain runnable unchanged behind `HR_ANSWER_ENGINE=classic`.
2. List every existing escalation rule with where it fires today (pre-model, post-retrieval, post-grounding). These become the rule engine at tool boundaries — none may be lost.

## B. The tools

3. For each tool in spec §3: which existing service it wraps, its input/output contract, and what the pre-call and post-call rule checks are. `salary_lookup`, `reference_fact`, `convenio_search`, `national_law` must be thin wrappers over today's code — no logic changes.
4. `ask_employee`: the whitelist (spec §5) as code, the per-conversation counter, the `profile_incomplete` escalation path with the missing field named on the card. Propose how the conversation window is built (how many turns, what is excluded — R5).
5. `escalate` as a tool: how a planner-initiated escalation is recorded (reason `planner_escalated`, the planner's stated reason kept as a trace field), and how rule-forced escalations override it.
6. `general_knowledge` (spec §4): the allowlisted-domain config, the PII scrub before any outbound call (R4) with a test, the **post-check for figures/durations/amounts/entitlement language in Spanish** (R3) with its pattern list and tests, the badge, the trace authority level, the feature flag, and the Guardarraíles toggle (super_admin, `guardrails.manage`). Confirm how web fetching would be done from `hr-ai` (library, timeouts, no JS execution).

## C. The planner

7. Model: Sonnet 5 via the existing `HR_AI_ANSWER_MODEL` knob or a new `HR_AI_PLANNER_MODEL` — argue which. Structured tool-call output (native tool use), not free text.
8. Propose the tool descriptions the planner sees (R1) — these are the routing quality lever. Show how they're evaluated (the gate, §9).
9. Loop bounds (R2): N tool rounds, 2 clarifications; the `tool_budget_exhausted` path.
10. Determinism where possible: temperature, fixed tool ordering in the prompt, and what is logged so a run can be replayed.

## D. Shell, trace, UI

11. The rule engine at tool boundaries: where it lives, how a rule verdict overrides the planner, tests proving the planner cannot suppress a forced escalation.
12. Trace: the new step types, how Historial and the trace panel render them (R6 — estimate steps per answer and storage growth), and the enum extension for the five new reasons (+ labels in both dictionaries + the enum guard test).
13. Employee UI: the "¿Quieres que lo revise RR. HH.?" control on every answer (`employee_requested_review`), the general-lane badge, clarifying questions as assistant turns. Mobile included.

## E. The gate and the plan

14. Confirm each existing harness can run against both engines with one flag flip; propose the two new sets (general-lane negatives ≥20 / positives ≥10; whitelist-temptation set) and how they're built from real corpus content.
15. Ordered build steps (shell + rule engine + flag first; wrappers second; planner third; general lane last), tests, open questions, and ⏸ checkpoints per spec §11.

Then **STOP** and wait for review.
