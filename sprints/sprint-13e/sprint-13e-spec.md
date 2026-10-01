# Slice 13e — Spec: Decline, not escalate, for off-domain questions

> Save as: `hr-docs/sprints/sprint-13e/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Origin: staging test 2026-10-01 — non-HR questions ("¿cuál es la definición de un trabajo?") created escalation cards. Small slice, one gate, one checkpoint. Pre-pilot.

---

## 1. The problem

Every "can't answer" today ends in an escalation card on HR's board. For questions that are not about work at all, that card is noise: HR spends time closing it, and in the first pilot week a board full of noise teaches HR to ignore the board — the one failure the flywheel can't survive.

## 2. The principle

**Escalate when uncertain; decline when confidently not HR.** Declining is only allowed for `off_domain`, never for `low_confidence`, `sensitive_topic`, coverage gaps, or any reason that could mean "HR-related but we couldn't answer". The employee always keeps a one-tap path to a human.

## 3. In scope

- **New terminal outcome `decline`** for reason `off_domain`, from either source: the pre-model guard (baseline + admin list) and the planner's judgement (`planner_off_domain`). Behaviour: the employee sees the off-domain message (the existing Guardarraíles-editable text), the turn is persisted with `outcome=decline`, **no escalation card is created**, the review button is present as on every answer ("¿Quieres que lo revise RR. HH.?" → creates a card with reason `employee_requested_review`, carrying the decline).
- **Confidence floor for planner-judged off-domain:** the planner's off-domain verdict declines only above a confidence threshold (plan proposes, from the existing planner output); below it, the turn escalates `low_confidence` as today. A hesitant "probably not HR" must not be silently dropped.
- **Never decline:** anything the sensitive-topic guard caught (those escalate as today), anything with an accepted normalization topic, anything the corpus returned material for. Rule-enforced; the planner cannot route those to decline.
- **Analytics:** `decline` counted separately in Analítica (declines by day, top declined questions via the existing clustering) so HR sees the volume without cards; a decline rate spike is a signal to review the off-domain list.
- **Historial:** declined turns visible with outcome *Declinada · Fuera de alcance*; trace shows which source declined (guard vs planner) and the confidence.
- **Status labels + i18n** both dictionaries; enum guard test; statusLabels for the new outcome.
- **Classic engine:** same behaviour for the guard-sourced `off_domain` (shared code path); planner-sourced applies to agent only. Goldens: classic turns that today escalate `off_domain` will change outcome — re-record disclosed, nothing else moves.

## 4. Out of scope

Changing the off-domain baseline patterns or the pre-screen allow-list (separate tickets); any change to what counts as sensitive; multi-language.

## 5. Acceptance criteria — the gate

1. **Off-domain set (new, ≥ 20 non-HR questions in Spanish, colloquial):** ≥ 18 decline, 0 escalate-with-card, 0 answers. The 2 allowed misses must escalate, never answer.
2. **Never-decline set:** whitelist-temptation ×1, lane negatives ×1 (cov+miss), gold-2c ×1, situational ×1 — **0 declines** anywhere; outcomes identical to CP-2/13c baselines.
3. **Borderline set (≥ 10 HR-adjacent questions: "recomiéndame un restaurante para la cena de empresa", "¿cómo pido vacaciones en la app?"):** reported, not gated — shows where the confidence floor sits.
4. Review button on a declined turn creates a card with `employee_requested_review` carrying the decline — live, both desktop and phone.
5. Goldens: the 25 + lane set byte-identical except the disclosed `off_domain` re-records; full suites green.
6. Spend ≤ $8.

## 6. Checkpoint

**CP-1 (single):** gate results + three live declines on staging read by Pedram as an employee (message tone, button present), one borderline that escalated, and the Analítica decline counter.

## 7. Risks / plan-gate questions

- **R1:** the planner's off-domain confidence — is it calibrated enough to gate on? Plan reports its distribution on the borderline set before proposing the floor.
- **R2:** declines hiding real HR questions phrased oddly — the review button is the safety net; the plan proposes a weekly "declined questions" view for HR so misjudgements surface.
- **R3:** the off-domain message copy — currently written for a guard hit; may need a line for "if this is about your work conditions, tap review".
