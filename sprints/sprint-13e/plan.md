# Slice 13e — Plan: decline instead of escalate for off-domain questions

> Status: **PLAN GATE — awaiting your review. No code, no commits.**
> Spend so far: **$0.187**, one planner-only probe of 12 questions on staging (`eval/results/plan-gate-probe-raw.log`).
> Staging access: read-only `SELECT`s (`eval/probes/readonly-state.php`, output in `eval/results/plan-gate-readonly-state.log`) and the probe. Nothing else was written on the box except the docs tree, synced to `/opt/hr-staging/hr-docs/sprints/sprint-13e/`.
> Files written here: this plan, `eval/off-domain-24.json`, `eval/borderline-10.json`, `eval/MANIFEST.sha256`, `eval/FREEZE-NOTE.md`, `eval/probes/{planner-probe,readonly-state}.php`, `eval/probes/sync-docs.sh`.
> Inputs read: `sprint-13e-spec.md` (your kickoff calls it `spec.md`), `sprint-13e-kickoff-prompt.md`, ADR-0019/0029/0037, Sprint 13 plan §A.1/A.2/§B.5/open question 6, `sprint-13d/plan.md` (format), `sprint-13c/plan.md` §8 (gate stages) and `eval/results/s3b*`/`s4*` (baselines), plus the code cited inline.

---

## 0. Verdict up front: five findings change the slice

**F1. The "existing off-domain message" is not what an employee sees today, and the default text would be false for a decline.**
- ADR-0029's override replaces every escalate answer with `EMPLOYEE_ESCALATION_MESSAGE` at `TurnPersister.php:86,97`.
- The Guardarraíles "off-domain message" (`GuardrailPolicy::offDomainMessage()`, `GuardrailPolicy.php:102-107`) has therefore been **dead copy since 7g**. It is `null` on staging (`readonly-state.log`, `guardrail_config`).
- The code-side default, `ChatService::ESCALATION_MESSAGE` (`ChatService.php:53-55`), says *"la estoy pasando a una persona del equipo de Recursos Humanos"*. After a decline nobody is reviewing, so that sentence would be a lie.
- The decline therefore needs **new default copy**, with the admin text honoured when set (§2.3). Q5.

**F2. Today's guard-sourced `off_domain` is not "genuinely non-HR".** It is two privacy/risk rules plus the admin list:
- Baseline `legal_medical` (`GuardrailService.php:43-48`, fired at `:90-94`).
- Baseline `other_employee_data` (`:57-65`, fired at `:100-104`).
- Admin `off_domain` rows (`GuardrailPolicy.php:80-99`).

"¿Necesito un abogado por mi despido?" and "¿cuánto gana Pedro García?" are things HR wants to see. The spec says to decline "baseline + admin list". I recommend declining **only the admin list** and leaving the two baseline rules as escalations, exactly as today (§4, rule D9; **Q1**). The spec-literal alternative is costed in §6.

**F3. The planner's `off_domain` is its doubt bucket, and it emits no confidence.**
- The `escalate` schema has `category` and `reason` only (`hr-ai/app/planner/tools.py:100-119`). The tool description says *"o dudes"* and the system prompt says *"Si dudas, usa escalate"* (`tools.py:104`, `:179`).
- Staging already shows the damage. "¿Me corresponde un coche de empresa?" was labelled `off_domain` after 4 steps, and so was "Buenas tardes" (`readonly-state.log`, `planner_off_domain_samples`). The first is a real HR question.
- Declining on the planner's word alone fails R2. The floor therefore needs a signal the planner does not give (§3).

**F4. The router cannot be a numeric floor, but its label is a useful independent vote.**
- 7/7 `off_domain` verdicts in the probe were ≥ 0.95. The five `prose` verdicts were 0.85–0.95.
- On older classic traces it also called "¿En qué comunidad autónoma trabajo?" and "Quiero hablar con una persona de RR. HH." `off_domain` at 0.95.
- So: no router-only decline, no numeric calibration to be had from it. What I propose is *agreement of two independent models plus deterministic vetoes* (§3.5).

**F5. Four consumers would silently mis-handle an unknown `decline` outcome, and the review endpoint refuses it.**
- `ChatController::requestReview` returns 422 unless the outcome is `answer` (`ChatController.php:161-167`).
- `ChatScreen` would render a decline as an `AnswerBlock`, with thumbs (`ChatScreen.tsx:476-491`).
- `TracePanel` would label it "Escalated" (`TracePanel.tsx:145-146`).
- Historial would badge it "Respondida" (`HistoryPage.tsx:150-156`; `HistoryController::index` `answered` = "no card", `:89-95`).
- Analítica would drop it from every figure. `summarize()` counts only four outcomes (`DeflectionAnalytics.php:120-146`).

Section 5 lists the fix for each.

Good news:
- **No migration.** The outcome lives in `trace.floor_decision.outcome` (no column). `analytics_daily_rollups.outcome` is `string(32)`. `employee_requested_review` already exists as a reason. So no RDS snapshot (`deploy.md`).
- **No hr-ai change** (Option B in §3). The planner prompt and tools are untouched, so `prompt_version` (`sha256:9f0d13fb…`, probe) is unchanged and the 13b/13c baselines stay valid.
- **No other turns change.** Non-decline traces and payloads stay byte-identical (§6).

Other headline points:
- Naming: `normalization.verdict = 'declined'` already exists (13b, `TurnState.php:50-57`). That is a different thing: the planner's normalization was declined. The new trace block is called `decline` and the outcome string is `decline`. A test pins that the two are never conflated (§8.4).
- Admin rules can shadow each other. `blockedTopicMatch` returns the first match (`GuardrailPolicy.php:84-97`), so an `off_domain` row listed before a `blocked_topic` row hides the sensitive hit. The gate must look at **all** rows (rule D2).
- `explicit_request` runs *after* the admin layer (`PreModelGuards.php:94-101`). "Quiero hablar con una persona de RR. HH., es sobre el gimnasio" would be declined by an admin `gimnasio` row. The gate checks it (rule D3).
- Staging has few off-domain cards: 5 `off_domain` (all router/classic) and 3 planner `off_domain` out of 668. Production volume is unknown. Plan for the feature being invisible in the data until real traffic, which is why R2's weekly view (§5.3) ships with it.
- Spend: ≈ **$7.2** at the gate plus the $0.19 already spent, **≈ $7.4** in all (§8.2). The never-decline net is 70 % of it.

---

## 1. Today's off-domain paths, exactly (item 1)

All four create the card in **one place**: `TurnPersister::persist` (`TurnPersister.php:127-155`, `EscalationCard::create` at `:139-149`). It is reached whenever `$outcome->outcome === 'escalate'` (`:86`). The card's `reason` is `$outcome->escalationReason` (`:128`).

| # | Path | Fires where | Pre-model? | `escalation_reason` | Card created by | Employee sees |
|---|---|---|---|---|---|---|
| A | Baseline guard: `legal_medical`, `other_employee_data` | `PreModelGuards.php:34-48` (`TurnOutcome('escalate', ESCALATION_MESSAGE, [], $trace, $guard['reason'])`) | **yes**, both engines (`ChatService.php:209`, `AgentChatService.php:85-89`) | `off_domain` (`guardrail_check.rule` = `legal_medical` / `other_employee_data`) | `TurnPersister.php:139-149` | `EMPLOYEE_ESCALATION_MESSAGE` (override `:97`) |
| B | Admin list, `kind=off_domain` | `PreModelGuards.php:57-72`; message `offDomainMessage() ?? ESCALATION_MESSAGE` at `:68-70` | **yes**, both engines | `off_domain` (`guardrail_check.layer=admin`, rule `admin_off_domain`) | same | same; the admin copy is discarded by the override |
| C | Classic LLM router `off_domain` | `ChatService.php:260-277` (`note: 'router classified off_domain'`) | no (after the guard, one `/route` call) | `off_domain` | same | same |
| D | Planner `escalate` | `AgentChatService.php:484-501`: `floor_decision.escalation_reason = 'planner_escalated'`, `agent.planner_escalation = {category, reason}`, `TurnOutcome('escalate', EMPLOYEE_ESCALATION_MESSAGE, …, 'planner_escalated')` | no (after round 1 at the earliest) | **`planner_escalated`** (the category `off_domain` lives only in the trace) | same | `EMPLOYEE_ESCALATION_MESSAGE` |

Notes:
- **The planner path never writes `escalation_reason = off_domain`.** The explainer key is `planner_escalated.off_domain` (`EscalationExplainer.php:209`, registry `:928-935`), not `planner_off_domain`. So "off_domain cards" and "planner off-domain cards" are different counts today.
- On any `PlannerUnavailableException` the agent falls back to classic (`AgentChatService.php:137-143`), which can take path C.
- The planner escalate runs *after* the round's real tool calls, and a forced tool verdict wins over it (`AgentChatService.php:438-499`, "additive: more escalation, never less").
- Staging today (`readonly-state.log`): engine override = `agent`; admin blocked-topics list empty; 668 cards, of which `off_domain` 5 (all router) and `planner_escalated` 6 (3 `off_domain`, 3 `unanswerable`).

**Where decline attaches.** Three call sites change and nothing else produces the outcome:
- B: `PreModelGuards.php:57-72`.
- A: `PreModelGuards.php:34-48`, only if Q1 goes the spec's way.
- D: `AgentChatService.php:484-501`, when `category === 'off_domain'`.

C is untouched (Q2). One card-creation function remains, and `decline` never reaches it.

---

## 2. The `decline` outcome (item 2)

### 2.1 Where it is represented

| Place | Today | Change |
|---|---|---|
| Outcome enum | a string in a docblock, `TurnOutcome.php:19` | new `TurnOutcome::OUTCOMES = ['answer','escalate','needs_category','ask','decline']`; the constructor rejects anything else |
| Persisted turn | `chat_messages.content` = the answer; `message_traces.trace` | `content` = decline copy (§2.3); no citations; **no card** |
| Trace `floor_decision` | `{outcome, escalation_reason, …}` | `{path: 'pre_model_guard' \| 'agent_planner', outcome: 'decline', decline_reason: 'off_domain', note}`. **No** `escalation_reason` key, so every consumer that reads it sees null |
| Trace `decline` block | n/a | `{granted, source, reason, checks:[{id,pass}], confirm:{label,confidence,floor,source}, matched_pattern?, planner:{category,reason}?, gate_version}`, present **only** on turns where the gate was evaluated (off-domain category or guard) |
| Response payload | `TurnPersister.php:157-171` | `outcome:'decline'`, `escalated:false`, `escalation_uuid:null`, `escalation_reason:null`; **no new keys**, so other outcomes stay byte-identical |
| Presenter | `ConversationPresenter.php:70-72` passes `outcome` through; `escalated` = `outcome==='escalate'` | none needed; reload renders correctly once the frontend knows the outcome |

### 2.2 No card is written, structurally

`TurnPersister.php:86`: `$escalate = $outcomeLabel === 'escalate'`. The card block (`:127-155`) is inside `if ($escalate)`. A `decline` therefore never enters it, with no new branch. The persister gains one *assertion* (§4.2, L2) and one answer-copy rule (§2.3), nothing that creates rows.

Test: a `decline` turn leaves `escalation_cards` unchanged and dispatches no `GenerateEscalationExplanationText` job (`Queue::fake`).

### 2.3 What the employee sees

- **Single source of decline copy**, mirroring ADR-0029's discipline. The persister decides the string, not the call site. A decline answer is `GuardrailPolicy::offDomainMessage() ?? ChatService::DECLINE_MESSAGE`. The admin text is honoured again, which makes the Guardarraíles field live for the first time since 7g.
- **Proposed default** (`DECLINE_MESSAGE`), written to survive a greeting ("Buenas tardes" is declined today by the router at 1.0):
  > *Soy el asistente de RR. HH. y solo puedo ayudarte con dudas laborales y de tu convenio. Esta consulta queda fuera de lo que puedo responder.*
- **R3 line** (frontend, i18n es+en, **not** persisted, so admin edits of the base text can never remove it):
  > *Si crees que sí es una duda de trabajo, pulsa «¿Quieres que lo revise RR. HH.?» y una persona de RR. HH. lo verá.*
- The review button is the existing `ReviewButton` (`ChatScreen.tsx:163-193`), rendered by a new `DeclineBlock`. No thumbs. Q5 asks you to approve the wording.
- Guard test: extend `EmployeeEscalationMessageScanTest` so a decline shows exactly the policy text or the constant, with no reason token, rule name, pattern, id or third-party name.

### 2.4 Review button on a declined turn → `employee_requested_review` with the decline attached

Change surface:
1. `ChatController.php:161-167`: allow `answer` **and** `decline`; `escalate`, `ask` and `needs_category` still 422.
2. The card is created exactly as today (`:170-191`), with `reviewed_message_id` = the declined assistant message. Idempotence and the unique partial index are unchanged. **"Carrying the decline"** is then true in two ways:
   - The card's `reviewed_message` (`EscalationController.php:454-456`) is the decline text, and HR opens the turn's trace in Historial.
   - The card's `explanation_facts` are built from the decline trace. `EscalationExplainer::detectSubOutcome` (`:208`) maps `employee_requested_review` → `declined_reviewed` when `floor_decision.outcome === 'decline'`, and `answer_reviewed` otherwise. That adds **one** MATRIX key (71 → 72) and one registry builder next to `:917-926`. Its text states the source, what was asked and the router vote, read from `trace.decline`.
3. Tests: `Sprint13ReviewRequestTest` gains decline → card created with the right `reviewed_message_id` and sub-outcome; a second tap returns the same card; escalate/ask → 422 still. `EscalationExplainerTest` covers the new key. `statusLabels.test.ts` hand-copies MATRIX keys (count 71 today, a disclosed copy-vs-copy guard), so it gets key 72 in es and en.
4. Live proof: CP-1 item 3, desktop and phone, reading the card on the Escalations board.

---

## 3. Confidence floor, R1 (item 3)

### 3.1 What the planner emits today for off-domain verdicts

- `escalate{category, reason}` only, no number (`tools.py:100-119`). **There is nothing to put a floor on.**
- The only numbers it emits are `normalize_question.confidence` (`tools.py:151-158`), which means "how sure I am of my reading for retrieval", not "this is off-domain".

### 3.2 The probe (planner-only: one `/plan` round 1 + one `/route`, no tools, no answers, rolled back)

12 questions on staging as `test-gipuzkoa@example.com`: the 10 borderline plus two off-domain anchors. Raw output is in `eval/results/plan-gate-probe-raw.log`. Planner `prompt_version` was `sha256:9f0d13fb…` throughout. **Spend $0.187.**

| id | question | round-1 planner calls | escalate category | `normalize.confidence` | router (label, conf) |
|---|---|---|---|---:|---|
| BL-01 | ¿Me corresponde un coche de empresa? | normalize, convenio_search | none | 0.3 | prose 0.92 |
| BL-02 | Buenas tardes | normalize | none | 1.0 | **off_domain 1.0** |
| BL-03 | ¿Puedo llevar a mi perro a la oficina? | normalize, escalate | **off_domain** | 0.95 | off_domain 0.95 |
| BL-04 | ¿La empresa me paga el parking? | normalize (topic 9), convenio_search | none | 0.4 | prose 0.95 |
| BL-05 | ¿Puedo fumar en el descanso? | normalize (topic 2), convenio_search | none | 0.4 | prose 0.95 |
| BL-06 | ¿Puedo tener otro trabajo por las tardes? | normalize (topic 11), convenio_search | none | 0.6 | prose 0.95 |
| BL-07 | ¿Cómo declaro la renta… dos pagadores? | normalize | none | 0.95 | off_domain 0.95 |
| BL-08 | Mi encargado me ha gritado… | normalize | none | 0.2 | prose 0.85 |
| BL-09 | ¿Cuántos días de vacaciones en Italia? | normalize, escalate | **off_domain** | 0.0 | off_domain 0.95 |
| BL-10 | Estoy pensando en irme a otra empresa… | normalize | none | 0.0 | off_domain 0.95 |
| OD-01 (anchor) | what is the definition of job? | normalize, escalate | **off_domain** | 0.0 | off_domain 0.95 |
| OD-02 (anchor) | ¿Cuál es la capital de Australia? | normalize, escalate | **off_domain** | 0.0 | off_domain 1.0 |

**Distribution**
- Planner round 1: `escalate` in 4/12, always `off_domain`. 8/12 went on to a tool or to `normalize` alone.
- Router: `off_domain` 7/12 (0.95 ×5, 1.0 ×2); `prose` 5/12 (0.85–0.95).
- `normalize.confidence` on the four planner off-domain verdicts: 0.0, 0.0, 0.0 and 0.95 (the dog). It is 0.0 on BL-10 and 0.2 on BL-08 too. It carries **no** off-domain signal.

**What the probe can and cannot say.**
- It is round 1 only. In 8/12 the planner has not yet judged. Staging history shows "Buenas tardes" and "coche de empresa" reaching `off_domain` at round 3–4 (`readonly-state.log`). The full-loop distribution is what gate stage S2 measures.
- n is small. I am reporting a pattern, not a calibration.

### 3.3 The floor I propose

Because the planner provides no confidence (3.1) and the router's saturates (3.2), **the floor is an agreement rule, not a number on the planner's output**:

> **R1 floor.** A planner `off_domain` becomes a decline only if *all* hold: (a) an independent `/route` call on the bare question returns `label = off_domain` with `confidence ≥ HR_DECLINE_ROUTER_FLOOR` (default **0.90**), and (b) the workplace-vocabulary veto (rule D7, §4.1) does not fire. Anything else keeps today's behaviour.

- The 0.90 value is a guard against a *degraded* router answer, not a tuning knob: every `off_domain` vote observed was ≥ 0.95. The router's own fail-safe (`RouterService.php:143-257`, below `router_confidence_floor` 0.50 it returns `prose`) means a failed or garbled call can never confirm.
- It is a separate config key from the router's existing floor (`config/hr.php:43`), so changing one cannot move the other.
- It costs one extra router call (~$0.001), only when the planner already said `off_domain` **and** every deterministic check passed. Misses and sensitive turns never reach it.
- **Below floor → today's escalation, unchanged** (`planner_escalated`, category `off_domain`). The trace gains `decline.denied_by`, so HR can see why. The spec words this as "`low_confidence` escalation as today"; today's path is actually `planner_escalated`, and moving it to `low_confidence` would change card reasons, the explainer matrix and all the counts, for no gain. **Q3.**

### 3.4 Offline replay (no model, no spend)

Applying the proposed rule to the probe rows (with the veto list of §4.1):

| outcome of the rule | questions |
|---|---|
| **decline** (planner `off_domain` ∧ router `off_domain` ≥ 0.90 ∧ no veto) | OD-01, OD-02 |
| planner `off_domain`, **denied by veto**, stays today's escalate | BL-03 (`oficina`), BL-09 (`vacaciones`) |
| planner did not escalate in round 1, so no verdict to test | BL-01, 04, 05, 06, 08 (all router `prose`) and BL-02, 07, 10 (router `off_domain`). BL-02 and BL-07 would decline if the planner escalates at round 2; BL-10 would be vetoed (`empresa`) |

Two cautions:
- The veto catches 7 of the 10 borderline questions **by construction** (I wrote the list after seeing them). The generalisation check is the other direction: the same list replayed over the 24 off-domain questions **fires on 0** (§7).
- **BL-03 (the dog) is the case that matters most.** It is the system prompt's own normalization example (`tools.py:188`). Planner and router agree it is off-domain, and my prior is that HR wants to see it (`hr_wants_to_see: true`). The veto saves it only because the word is `oficina`. That is the R2 risk in miniature, and it is why the weekly declined view and the review button are part of the slice, not extras.

### 3.5 Alternatives, costed (Q4)

| Option | Idea | Verdict |
|---|---|---|
| **B (recommended)** | planner `off_domain` + router agreement + veto | no hr-ai change, planner `prompt_version` and every 13b/13c baseline stay valid |
| A | add `confidence` to the `escalate` schema | changes the tools hash, so `prompt_version` moves. `Sprint13cPlannerPromptParityTest` and all the frozen planner baselines go stale; re-baselining whitelist/gold-2c/situational/lane (≈ $5.1) plus a probe to see whether the model's number means anything. **No** evidence it would be calibrated; the 13b `confidence` is not. Keep as the fallback if CP-1 shows B declining wrong things |
| C | planner-only, no floor | fails R1 by F3 |

---

## 4. The never-decline rule (item 4)

The rule is one pure predicate and one funnel. A decline that the predicate did not allow **cannot be persisted**, and this is tested, not trusted.

### 4.1 The predicate: `DeclineGate::decide(DeclineFacts): DeclineDecision`

A decline is granted **iff every line below passes**. The first four are your spec. D3 and D6–D9 are my additions, each with its reason.

| id | Passes when | Source of the fact | Why |
|---|---|---|---|
| **D1** | `reason === 'off_domain'`: the planner's `category` enum value, or the guard's `reason`. **The only reason that can reach the gate.** Any other string is denied before anything else is read | `AgentChatService.php:487` (`$call['input']['category']`); `PreModelGuards.php:57-59` | the rule "declining must be impossible for any other reason" |
| **D2** | no sensitive-topic hit: baseline `GuardrailService::check` does not fire `sensitive_topic` (`GuardrailService.php:30-37`), **and no enabled admin `blocked_topic` row matches** (new `GuardrailPolicy::blockedTopicMatches()` returns *all* matches; the existing first-match method is untouched) | re-run on the bare question inside the gate | the pre-model guard already ran, but admin rows can shadow (§0); and the planner path should not rely on a call that happened elsewhere |
| **D3** | not an explicit request to speak to a person (`RouterService::matchesExplicitRequest`, `RouterService.php:344`) | deterministic | `explicit_request` runs after the admin layer (`PreModelGuards.php:94-101`) |
| **D4** | no accepted normalization topic or canonical (`TurnState::normalizedTopicId() === null && normalizedCanonical() === null`, `TurnState.php:60-73`) | `state->normalization` | spec |
| **D5** | no corpus material: no `state->material[*]` entry with status `MATERIAL` or `TERMINAL`; and no stashed `NO_MATERIAL` whose outcome has `floor_decision.check_a_retrieval === true`. That last case is `entailment_failed` / `abstained`, where chunks cleared Check A (`ConvenioSearchTool.php:125-152`, `ProsePath.php:156-174`). A `check_a_failed` miss **is not material**, because that is exactly what an off-domain question produces | `TurnState.php:43`, set at `AgentChatService.php:576` | spec. Defined on results, not on "a tool ran": the staging off-domain turns ran 3–4 steps |
| **D6** | the previous assistant turn in the session was not `ask` or `needs_category` | `AgentChatService.php:209`-style trace query | a reply to our own clarifying question must never be declined |
| **D7** | the workplace-vocabulary veto does not fire: no whole-word, accent-insensitive match of the closed Spanish list below, nor any `TopicLexicon::matchTopicKeys` anchor (`TopicLexicon.php:94`) | question text | F3, BL-03/09. Fails toward today's card |
| **D8** | *(planner source only)* the router confirmation of §3.3 | one `/route` call, only when D1–D7 pass | R1 |
| **D9** | *(guard source only)* the guard rule is `admin_off_domain`. The baseline `legal_medical` and `other_employee_data` are **not** declinable (**Q1**) | `guardrail_check.rule` | F2 |

Closed D7 list, draft: *trabajo, trabajar, trabajador/a, empleo, empleado/a, empresa, oficina, jefe/a, encargado/a, supervisor, compañero/a, contrato, nómina, sueldo, salario, convenio, estatuto, vacaciones, permiso(s), baja, turno(s), horario, jornada, descanso, despido, finiquito, antigüedad, uniforme, ascenso, plantilla, rrhh, recursos humanos, seguridad social, horas extra, sindicato, cotización*. It is Spanish only on purpose, because OD-01 is English and must still decline. It lives in code with a unit test per word. A false veto only means "today's card".

### 4.2 Why it is a rule and not a convention: four layers

PHP cannot make an unforgeable token, so I am not claiming one. What exists is a single funnel, an independent re-check at the persistence boundary, and tests that fail the build if either is bypassed.

- **L1 — funnel.**
  - `new TurnOutcome('decline', …)` **throws**. The only way to build a decline is `TurnOutcome::decline(DeclineGrant $g, …)`.
  - `DeclineGrant` has a private constructor and one static factory, which accepts only a `DeclineDecision` with `granted === true`.
  - `DeclineDecision` is produced only by `DeclineGate::decide()`.
  - There is no other route to the string `decline` as an outcome.
- **L2 — persistence boundary (fail-closed).**
  - `TurnPersister::persist` re-validates every decline from the trace *as written*: `trace.decline.reason === 'off_domain'`, every `trace.decline.checks[*].pass === true`, and the source is one of the two known ones.
  - If any of that fails, the turn is **demoted to a normal escalate**: `EMPLOYEE_ESCALATION_MESSAGE`, card reason `low_confidence`, and a `decline_demoted` log line. This is the existing "more escalation, never less" principle (`AgentChatService.php:481-483`).
  - L2 also catches a future code path that builds a decline some other way.
- **L3 — architecture test.**
  - A source scan fails if the outcome literal `'decline'` appears in `app/` outside `TurnOutcome`, `DeclineGate`, `TurnPersister` and the analytics readers.
  - It also fails on any `new TurnOutcome(` with a non-literal first argument. It has the same shape as `EmployeeEscalationMessageScanTest` and the 7g guard tests.
- **L4 — truth table.**
  - `DeclineGate` is pure. A test enumerates every boolean combination of D1–D9 and asserts a grant **only** for the all-pass row of each source (D8 applies to the planner source, D9 to the guard source).
  - A data-provider test iterates every value of the live `escalation_cards.reason` CHECK enum (the `EscalationReasonLabelCoverageTest` pattern) plus the planner category enum, and asserts every value other than `off_domain` is denied.

### 4.3 Where the rule runs

- **Planner source.**
  - A new boundary `pre_call:escalate` and a rule `OffDomainDeclineRule` (`Verdict::forceDecline`, a fourth force type beside `forceEscalate/forceAsk/forceFinish`, `Verdict.php:61-88`). It is registered in `AgentServiceProvider.php:72-100`.
  - `processRound` runs it on the first `escalate` call (`AgentChatService.php:484-501`). `TurnState::applyForced` maps it (`TurnState.php:156-176`, `terminationReason = 'declined'`).
  - The router confirmation is gathered by `AgentChatService` *before* the rule runs, so the rule stays a pure function of `TurnState`, like every other rule.
  - A denial leaves the existing escalate code path exactly as it is, plus a `decline` trace block. Only turns whose category is `off_domain` get that block, so every other planner escalation trace is unchanged.
- **Guard source.** `PreModelGuards.php:57-72` calls `DeclineGate::decide()` with `source = guard_admin`. On denial it falls through to today's escalate.
- **Kill switch.** `HR_DECLINE_ENABLED` (default **true**). `false` removes decline everywhere and restores today's behaviour byte-for-byte. Tested with the old golden fixtures.

### 4.4 Tests required for each exclusion

Every one is a failing-first test that asserts **no decline** and **today's outcome**. Planner-path tests use the scripted-planner harness (`Sprint13EscalateWrapperTest`, `Sprint13RuleEngineInvariantTest` style).

| Exclusion | Tests |
|---|---|
| D1 reason | each planner category (`unsafe`, `unanswerable`, `needs_human_judgement`, `other`) → escalate; the full-enum provider; the L1/L2/L3 structural tests |
| D2 sensitive | baseline pattern + planner `off_domain`; admin `blocked_topic` row listed **after** an `off_domain` row (the shadowing case); `Sprint6GuardrailInvariantTest` untouched and green |
| D3 explicit request | "quiero hablar con una persona de RR. HH., es sobre el gimnasio" with an admin `gimnasio` off-domain row → not a decline |
| D4 normalization | accepted topic; accepted canonical with topic null; a rejected/declined normalization does **not** block (so the exclusion is not over-wide) |
| D5 corpus material | `MATERIAL`; `TERMINAL`; `NO_MATERIAL` with `check_a_retrieval=true` (entailment/abstention); a `check_a_failed` miss does **not** block (the off-domain case) |
| D6 pending ask | previous turn `ask`; previous turn `needs_category` |
| D7 veto | one case per list word, plus a `TopicLexicon` anchor, plus English OD-01 still declines |
| D8 confirmation | router `prose`; router `off_domain` at 0.89; router error → fail-safe `prose`; salary label; router unreachable |
| D9 guard source | `legal_medical`, `other_employee_data` → escalate (unless Q1 flips this, then the tests invert); `admin_off_domain` → decline |
| Whole | never-decline gate sets, §8.2 S3 |

---

## 5. Analítica and Historial (item 5)

### 5.1 Analítica

- `DeflectionAnalytics::summarize` (`:120-146`) and `fromRollup` (`:262-293`) gain a `declined` figure. **`declined` is excluded from the deflection-rate denominator**, like `needs_category` and `ask`: it is neither an answer nor an escalation (Q6).
  - **Disclosed discontinuity:** today's off-domain escalations count against the rate. Once they decline, the rate rises with no change in answer quality. The Analítica note says so, as it does for `needs_category` (`es.ts:1229`).
- `declinedByDay`: a new method on `DeflectionAnalytics`, live from `liveTurns` and from `analytics_daily_rollups` (`outcome='decline'` rolls up already, `rollupRowsForDate` `:198`). `AnalyticsController::deflection` (`:30-62`) returns it.
- **Top-declined questions** (R2's weekly view): `QuestionClusteringService::declinedRanking($from, $to, $limit)`.
  - It reads `question_cluster_members → chat_messages → the next assistant turn's trace` at read time, so there is **no new column and no migration**.
  - Ranked by declined count, then recency. It is added to `AnalyticsController::clusters` (`:84-108`) and shown as a "Declinadas esta semana" section with a link to Historial filtered `outcome=declined`.
  - The existing `escalation_rate` stays card-based (`QuestionClusteringService.php:102`). A declined turn has no card, so it correctly shows 0 there and is not ranked as unanswered.
- `stats:deflection` prints `declined` (`StatsDeflection.php:52-55`).
- Tests: `DeflectionAnalytics` rollup-vs-live equality with declines (the rollup's own correctness test, Sprint 8 Step 3), denominator excluded, by-day, ranking.

### 5.2 Historial

- `HistoryController::index`: add outcome bucket `declined` (a session containing a `decline` turn, the same shape as `asked`, `:66-99`), a `declined` flag on `listRow` (`:203-225`), and fix the `answered` badge so a decline-only session is not labelled "Respondida" (`HistoryPage.tsx:150-156`).
- `HistoryPage`: filter option `outcomeDeclinedOnlyOption`; badge **"Declinada · Fuera de alcance"** (`declinedBadge`); `ConversationBubble` (`:312-318`) shows the same badge on a declined assistant turn.
- `TracePanel.tsx:145-157`: today any unknown outcome falls through to "Escalated". It is replaced by a single `outcomeLabel(t, outcome)` helper typed `Record<ChatOutcome, string>`.
  - A decline renders as *Declinada (fuera de alcance)* with the new meta line: `fuente: planificador · router off_domain 0.95 (≥ 0.90) · 9 comprobaciones superadas` or `fuente: lista de Guardarraíles · patrón «…»`.
  - A *denied* decline (planner said `off_domain`, floor/veto refused) renders the denial reason on the existing escalation step.
  - `agentTrace.ts` gets the matching step type.

### 5.3 Dictionaries and the enum guard

- Both `es.ts` and `en.ts` get every key, in the same commit. `en.ts` is typed `: Dict` (a widened `typeof es`), so a missing key is a `tsc -b` failure, not a runtime gap.
- Keys: `chat.declineReviewHint`; `historyPage.{declinedBadge, outcomeDeclinedOnlyOption}`; `tracePanel.{outcomeDecline, decline*}`; `analyticsPage.{kpiDeclinedLabel, kpiDeclinedSub, declinedByDayHeading, declinedRankingHeading, declinedRankingNote}`; `statusLabels.outcome` (a new `Record<ChatOutcome,string>`); and the subOutcome entry `employee_requested_review.declined_reviewed` (`es.ts:638` neighbours).
- **Enum guard test.**
  - Backend: pins `TurnOutcome::OUTCOMES` to a literal list, so adding an outcome fails until the test is edited.
  - Frontend: `ChatOutcome` gains `'decline'` (`api.ts:590`). The outcome label map is `Record<ChatOutcome, string>` (a compile-time exhaustiveness guard), and `statusLabels.test.ts` asserts coverage and no label collisions in both dictionaries.
  - `ChatScreen`, `TracePanel` and `HistoryPage` all go through the same helper.
  - Same disclosed trade-off as 11a: the vitest list is a copy of the backend list. The compile-time record is the real guard.

---

## 6. Classic (item 6)

- **Guard-sourced `off_domain` is shared code.**
  - `PreModelGuards::check` is called by both engines (`ChatService.php:209`, `AgentChatService.php:85`) and persisted by the same `TurnPersister`.
  - So the admin-list decline works identically in classic and agent, with no second implementation.
- **Not shared:** the classic router `off_domain` (path C, `ChatService.php:260-277`). Under the recommendation it **stays an escalation** (Q2).
  - Reason: this is where staging's older traces show 0.95 `off_domain` on genuine HR questions (F4).
  - Making it decline would need its own confirmation signal, its own probe and its own floor. A ticket, not this slice.
- **Which goldens change.** The 25 in `Sprint13GoldenTraceTest.php` are classic-engine.

| Golden | Recommended (Q1 = admin list only, Q2 = no) | Spec-literal (baseline declines too) |
|---|---|---|
| `04_admin_blocked_topic` (`:124-135`, `gimnasio` off-domain row) | **re-recorded**: `floor_decision.outcome` escalate→decline, `escalation_reason` removed, `decline` block added, answer text = the new copy, payload `escalated:false`, `escalation_uuid:null` | same |
| `02_legal_medical`, `03_other_employee_data` | byte-identical | re-recorded |
| `06_router_off_domain` | byte-identical | byte-identical |
| the other 21, and the 13c lane-on fixtures | **byte-identical** | byte-identical |

  The `…flag_off` fixture preserves today's `04` content so the kill-switch test can assert byte-for-byte parity.
- **Disclosed re-record.** `assertGoldenTrace` records and fails on first write by design (`Sprint13GoldenTraceTest.php:52,1060`). The `04` diff goes in the review as a before/after, and I do not accept it blind. The test file is otherwise untouched.
- **Why other fixtures stay identical.** The `decline` trace block and the new payload values exist only on decline turns. No existing key is added to other outcomes (the 13d precedent: nest and emit only when involved).
- **Classic review button.** It is the same `/review` endpoint and component, so classic declines get it free.
- **Agent goldens.** There are none for the planner path; `Sprint13EscalateWrapperTest` and the new scripted-planner tests cover it.

---

## 7. Fixtures (item 7)

Both are written, frozen and hashed (`eval/MANIFEST.sha256`, `eval/FREEZE-NOTE.md`). **Please review them before build.**

- **`eval/off-domain-24.json`** (sha256 `f58b30e5…5f880e`).
  - 24 questions, Spanish and colloquial, genuinely not about work: trivia, cooking, sport, tech, language, leisure, maths, philosophy, plus the one English staging trigger (OD-01).
  - Each carries an explicit `expect {outcome: decline, must_not_answer, no_card}`.
  - OD-01 and OD-02 were seen once by the probe (disclosed). The other 22 have never reached a model.
  - Veto replay: **0 of 24** contain a D7 word (offline, deterministic).
- **`eval/borderline-10.json`** (sha256 `64db4d2b…b4fc`).
  - 10 questions an employee could plausibly ask about their job but a planner might call "not HR". Each has an author prior `hr_wants_to_see`, written before any model saw it.
  - Reported, not gated; the prior is what the floor is judged against, not a gate.
- **Never-decline set** (spec §5.2), existing frozen banks, no new files:
  - whitelist-temptation ×1 (`sprint-13/eval/whitelist-temptation.json`, 16 turns)
  - gold-2c ×1 (`gold-2c.json`, 4 turns)
  - situational ×1 (`situational.json`, 12 turns)
  - lane negatives ×1, profiles cov and miss (`general-lane.json`, 25 questions × 2 = 50 turns)
  - Baselines: 13c S4 (`results/s4-report.md`: whitelist 16/16, gold-2c 3/4 with `2c-periodo-prueba-navarra` failing at baseline, situational 12/12) and 13c S3b (`results/s3b-rows.jsonl`).
- **Canary I will read:** in the 13c baseline `ln-19` (*"¿Tengo derecho a cobrar el 100% durante la baja?"*) ended `planner_escalated` in 3 of 4 turns. If the planner called it `off_domain`, it is exactly the false-decline the rule exists to prevent. It is protected twice: `cobrar` makes the router label `salary`, and `baja` is a D7 word. The gate rows will gain `planner_category` so I can show it.

---

## 8. Gate plan, build, tests, open questions (item 8)

### 8.1 Gate harness changes (additive)

- `answer:gate` already scores `expect.outcome` and `must_not_answer` generically (`AnswerGate.php:260-337`). It needs only:
  - a new `expect.no_card` check (fails if the row has an `escalation_uuid`)
  - a `declines` counter in the summary
  - `planner_category` and `decline.granted/denied_by` on each row
- Frozen-bank verification is unchanged (`MANIFEST.sha256`, `--allow-unfrozen` only for dev).
- A spend guard is copied from 13c's ops scripts: a per-stage stop-loss, checked before each call.

### 8.2 Stages and spend (≤ $8)

Agent engine, staging, `HR_DECLINE_ENABLED=true`, planner and router as deployed. Per-turn costs from 13c's measured rows ($0.074 average on the lane negatives; planner round ≈ $0.016 as measured in the probe).

| Stage | What | Turns | Est. $ | Cap | Pass rule (spec §5) | Abort rule |
|---|---|---:|---:|---:|---|---|
| S0 | plan-gate probe (done) | 12 | **0.19** | | | |
| S1 | off-domain-24 ×2 | 48 | **1.4** | 2.0 | ≥ 18/24 `decline` **in each run**; 0 escalate-with-card; 0 answers (§5.1) | any answer; any card |
| S2 | borderline-10 ×1 | 10 | **0.7** | 1.0 | reported, not gated (§5.3) | none |
| S3 | never-decline: whitelist ×1 (16), gold-2c ×1 (4), situational ×1 (12), lane negatives ×1 cov+miss (50) | 82 | **5.1** (0.37 + 0.29 + 0.76 + 3.69) | 5.6 | **0 declines**, outcomes identical to the 13c/CP-2 baselines (§5.2) | any decline |
| | **Total** | | **≈ 7.4** incl. S0 | **8.0** | | headroom ≈ $0.6 |

- If S3 runs hot, the lever is lane negatives `miss` limited to the questions that reached the corpus-miss path in 13c S3b. That saves up to ≈ $1.8 and loses the spec's literal "cov+miss". I would stop and ask rather than pick it.
- Criteria 4 (review button live on desktop and phone), 5 (goldens) and 6 (spend) map to: CP-1 item 3; the free test suite; the ops-script guard.

### 8.3 Build steps, in order

1. **Pure core (no behaviour change):** `TurnOutcome::OUTCOMES`/`decline()`/`DeclineGrant`, `DeclineFacts`/`DeclineDecision`/`DeclineGate`, D7 list, `GuardrailPolicy::blockedTopicMatches()`; truth-table + exclusion unit tests.
2. **Planner path:** `Verdict::forceDecline`, `TurnState::applyForced` mapping, `OffDomainDeclineRule`, `pre_call:escalate`, router-confirm collector, `processRound` wiring, config `HR_DECLINE_ENABLED` / `HR_DECLINE_ROUTER_FLOOR`; scripted-planner tests.
3. **Guard path:** `PreModelGuards` admin-off-domain decline + denial fall-through.
4. **Persister:** decline branch (no card, single copy source), L2 fail-closed demotion, `DECLINE_MESSAGE`; scan-test extension; re-record golden `04` (+ the flag-off fixture).
5. **Review path:** `requestReview` accepts `decline`; `EscalationExplainer` `declined_reviewed` (MATRIX 72 + registry); `EscalationController` card summary.
6. **Historial backend:** `declined` bucket/flag.
7. **Analytics backend:** `summarize`/`fromRollup`/`declinedByDay`/`declinedRanking`, controller, `stats:deflection`.
8. **Frontend:** `ChatOutcome`, `DeclineBlock`, `outcomeLabel` helper, `TracePanel`/`agentTrace`, `HistoryPage`, `AnalyticsPage`, es+en, `statusLabels` + tests, `tsc -b` + vitest.
9. **Gate harness:** `no_card`, counters, `planner_category`, stop-loss; sync docs; verify manifest.
10. **ADR-0038** (amends ADR-0029: decline has its own single copy source; ADR-0019: the off-domain message is live again) and a `deploy.md` note.
11. **Deploy to staging** (no migration, so no RDS snapshot; branch deploy as in 13d) → S1 → S2 → S3 → **CP-1**. Abort rules above stop the run automatically, not at a stage boundary.

### 8.4 Tests (all free unless marked)

- `DeclineGateTest` (truth table, every D-rule, English OD-01, all-enum provider).
- `DeclineStructuralTest` (L1 ctor throws; L2 demotion; L3 source scan; `decline` ≠ `normalization.verdict 'declined'`, one test pins that the strings never meet).
- `Sprint13eDeclineAgentTest` (scripted planner: grant, each denial, router-confirm variants, flag off).
- `Sprint13eDeclineGuardTest` (admin off-domain row; shadowing; explicit request; baseline rules unchanged).
- `Sprint13ReviewRequestTest` (extended), `EmployeeEscalationMessageScanTest` (extended), `EscalationExplainerTest` (+1 key), `DeflectionAnalyticsTest` (+decline, rollup = live).
- `Sprint13GoldenTraceTest`: 04 re-recorded, others untouched; flag-off parity.
- Frontend vitest: outcome label coverage both dictionaries; `statusLabels.test.ts` key 72; `DeclineBlock` renders the hint and button; `HistoryPage` badge.
- Existing suites that must stay green untouched: `Sprint6GuardrailInvariantTest`, `Sprint13RuleEngineInvariantTest`, `Sprint13cPlannerPromptParityTest`, `Sprint10bInvariantTest`.

### 8.5 CP-1 — the single checkpoint (after S3)

You read, in the app, as an employee and as HR:
1. All 24 off-domain turns in Historial with traces (source, router vote, checks); any non-decline is read, not tuned.
2. The 10 borderline outcomes against my `hr_wants_to_see` priors, especially every borderline decline.
3. **Review button on a declined turn, desktop and phone** → the card on the Escalations board is `employee_requested_review` with the decline visible.
4. The guard scenario by hand: add `bitcoin` as an off-domain topic in Guardarraíles, ask, see the decline and the admin copy, then remove the row. A second case combines the pattern with "quiero hablar con una persona".
5. The "Declinadas esta semana" view and the by-day figure.
6. One planner `off_domain` **denied** (a veto trace) and the kill switch (`HR_DECLINE_ENABLED=false` → the capital question escalates as before).

### 8.6 Open questions

| # | Question | Recommendation |
|---|---|---|
| **Q1** | Decline baseline `legal_medical` / `other_employee_data`? | **No.** Admin list only (D9). They are risk/privacy rules; declining "¿necesito un abogado por mi despido?" or "¿cuánto gana X?" hides what HR wants. Yes = golden 02/03 re-recorded, the `EmployeeEscalationMessageScanTest` expectations change, and the privacy case would tell an employee "out of scope" instead of reaching HR |
| **Q2** | Extend decline to the classic router `off_domain` (path C)? | **No**, for this slice (F4); ticket it with its own probe |
| **Q3** | Below floor, keep `planner_escalated` or move to `low_confidence` as the spec words it? | **Keep `planner_escalated`**; `low_confidence` adds a sub-outcome (MATRIX +1) and shifts card counts for no gain |
| **Q4** | Floor signal: B (router agreement + veto), A (add `confidence` to `escalate`) or C? | **B** (§3.5). A is the fallback if CP-1 shows B wrong |
| **Q5** | Approve the proposed default copy and the R3 line (§2.3)? | Wording is yours; the structure (admin text honoured, R3 line in frontend i18n) is what I need |
| **Q6** | `declined` excluded from the deflection denominator? | **Yes**, with the disclosed discontinuity note |
| **Q7** | Keep my extra rules D3, D6, D7, D8, D9 beyond the spec's four? | **Yes**; each has evidence in §0/§3. D7 is the most opinionated; its list is yours to edit |
| **Q8** | Is BL-03 (dog in the office) one HR wants to see (my prior) or one you are happy to decline? | The rule keeps it only through the word `oficina`; tell me which, and I will move it between sets |
| **Q9** | S3 headroom is ≈ $0.6; accept the abort-and-ask rule instead of pre-trimming lane negatives? | **Yes** |

Then I **stop** and wait for your review.
