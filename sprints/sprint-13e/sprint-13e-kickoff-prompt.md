# Slice 13e — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-13e/kickoff-prompt.md`

---

You are planning **Slice 13e** in the hr-platform workspace. Read `hr-docs/sprints/sprint-13e/spec.md`, then: the off-domain guard (Sprint 6 / ADR-0019 — baseline patterns + admin list), the planner's `escalate` tool and `planner_off_domain` sub-outcome (Sprint 13 plan §B.5, F.6 decision), the escalation card creation path, and `statusLabels`. Write `hr-docs/sprints/sprint-13e/plan.md`, then **STOP — no code, no commits, no spend.**

Cite `path:line`. Declining must be impossible for any reason other than `off_domain`; the plan must show that as a rule, not a convention.

1. **Today's off-domain paths, exactly.** Where the guard fires (pre-model), where the planner's off-domain lands (`planner_off_domain`), and where each creates the card. Cite the card-creation call for both.
2. **The `decline` outcome.** Where it's represented (outcome enum, persisted turn, trace `floor_decision`), what the employee sees (existing off-domain message; propose the R3 line about the review button), and that **no card** is written. Show the review button on a declined turn creating `employee_requested_review` with the decline attached.
3. **Confidence floor (R1).** What the planner emits today for off-domain verdicts; run the ≥ 10 borderline questions offline through the planner-only probe (≈ $0.3, authorised) and report the distribution; propose the floor. Below floor → `low_confidence` escalation as today.
4. **Never-decline rule.** A rule-engine check that declines only when: reason is `off_domain`, no sensitive-topic hit, no accepted normalization topic, no corpus material. Tests for each exclusion.
5. **Analytics + Historial.** Decline counter and top-declined view in Analítica; outcome label and trace rendering in Historial; both dictionaries; enum guard test.
6. **Classic.** Guard-sourced `off_domain` is shared — show which goldens change (expect only `off_domain` turns) and re-record disclosed.
7. **Fixtures.** The ≥ 20 off-domain set (Spanish, colloquial, genuinely non-HR), the ≥ 10 borderline set, frozen + hashed. I review them before build.
8. **Gate plan** per spec §5 with spend (≤ $8), the single **CP-1**, ordered build steps, tests, open questions.

Then **STOP** and wait for review.
