# Slice 13d — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-13d/kickoff-prompt.md`

---

You are planning **Slice 13d** in the hr-platform workspace. Read `hr-docs/sprints/sprint-13d/spec.md`, then ADR-0034 (bundled facts), Sprint 7c's review (fact route, composition), Slice 13b's `review.md` (ho-jor-04 root cause) and the roadmap §7 same-validity ticket. Write `hr-docs/sprints/sprint-13d/plan.md`, then **STOP — no code, no commits, no spend.**

Cite `path:line`. This touches a shared service, so classic changes on exactly one class; the plan must prove nothing else moves.

1. **Today's selection, exactly.** Walk `ReferenceFactAnswerService::selectMostRecent()` and the precedence code: how candidates are gathered, how group/category/convenio-wide precedence resolves, what "conflict" means in code today, and what happens for a grouped employee with both a group fact and a convenio-wide fact on one topic (R1 — cite the wt-01/wt-05 pass-with-caveat rows as the behavioural reference).
2. **The logical key.** Show the 7b-2 upsert key and confirm every verified fact on staging carries one (read-only query). If any don't (R3), list them and propose the fallback.
3. **The complementary/contradictory rule** as code: same key + different value + no supersession → contradictory; different keys surviving precedence → complementary. Tests for each branch, including the supersession case (older fact closed → single).
4. **Composition of a set.** How `composeFactWithProse` takes an ordered set; ordering rule (R2 — propose: figure-bearing fact first, rules second; cap at 3 with the rest listed in the trace); citation per fact; grounding untouched. Show synthesis input for the convenio 20 case.
5. **Trace fields** `facts_selected`, `composition`; rendering in the trace panel (both dictionaries).
6. **`facts:same-topic-audit`** — output shape, and that it reproduces 13b's read-only query (1 complementary pair on staging).
7. **Golden traces.** Which of the 22 exercise the fact route; confirm they stay byte-identical; propose ≥2 new fixtures (complementary answered; contradictory escalated) recorded on the *current* code first so the diff is visible.
8. **Gate plan** per spec §5, both engines, with estimated spend (≤ $15), and the single CP-1.
9. Ordered build steps, test inventory, open questions.

Then **STOP** and wait for review.
