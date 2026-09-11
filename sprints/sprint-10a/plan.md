# Sprint 10a — Plan (plan gate)

> Status: **PLAN — awaiting review.** No application code, migrations or tests written. Nothing committed.
> Spec: `hr-docs/sprints/sprint-10a/spec.md`. Depends on ADR-0015/0016, 0023, 0028, 0030.
> Everything below was checked against the **real code on `main`** and the **live staging DB** (`hr-staging-db`, read-only queries run through the running `hr-backend` container on 52.211.251.235, 2026-09-11). Every file reference is `path:line` against the working tree.

---

## 0. Verdict up front

The two halves of this sprint are **not equally ready**, and the difference is not a matter of effort.

**The Estatuto fallback (§2.2) is sound and buildable**, with two corrections the evidence forces:
- the trigger must distinguish *never-ingested* from *expired-and-not-renewed*, because on staging **every employee-bearing "zero prose chunk" convenio is the expired kind** (§C.6.3). Falling back to the Estatuto for an expired convenio is a legally-weighted wrong answer, not a safe one.
- **Estatuto art. 37** — the single article the spec names for the permisos gold set — is the one substantive article that did **not** get its own chunk (§C.8). It needs a targeted fix before the fallback path can be evaluated honestly.

**The clarifying-question turn (§2.1) cannot be built as specified.** Four independent findings, each sufficient on its own:
1. **Zero of the 11 real escalation cards on staging would be converted by a clarifying question** (§A.2). Not "few" — zero.
2. **No entry in the 44-key `explanation_facts` matrix has a missing question parameter as its cause** (§A.1). The matrix is exhaustive over the taxonomy, and every entry resolves to corpus, directory scope, guardrail, provider, or arithmetic.
3. **Two of the four allowlisted parameters are already directory columns.** `employees.employment_type` is a *required* field, populated 14/14 on staging; `employees.start_date` exists and is nullable. By the spec's own R1 rule ("if the missing parameter is directory scope, it is not clarifiable"), `contract_time` and `seniority_years` come out of the allowlist (§A.1.3).
4. **There is no code that would consume a clarified value.** The answer loop never reads `start_date` or `employment_type`; the only way a clarified parameter could reach the answer is through the `/synthesise` prompt — which makes the LLM the thing that acts on it, and then `/ground` has to entail a branch-selected figure against a chunk that states a *conditional* rule. The most likely outcome of a clarified re-run is **escalation anyway**, at double the latency and cost (§A.3.3).

My recommendation is in §E.0: **build the fallback now as Sprint 10a; take the clarify turn back to spec** with a different framing (generalising the existing `needs_category` turn, §B.4.1) and a different success metric (answer *precision*, not escalation conversion). I am not asking to drop it — I am asking not to build it against a gold set that is empty.

One correction to the spec's premises while we are here: **R4 is factually wrong.** The clarify turn would not be "the first non-terminal assistant turn in employee chat." `needs_category` (`ChatService.php:285-294`) has been exactly that since Sprint 2b — constrained options, no escalation card, no directory write, re-run with a typed parameter, with working frontend UI. That is good news: the model and the UI pattern already exist. It is also why the clarify turn should be built *as* that pattern rather than as a new state machine.

---

## 1. How this was inspected

- **Code:** working tree at `/Users/pedram/Desktop/PROJECT/JV/HR-AI`, all four repos.
- **Staging DB:** read-only `DB::select` executed inside the running `hr-backend` container (`docker compose -f docker-compose.staging.yml exec`), DB password resolved at call time from `${SSM_PREFIX}/rds/master-password` via the EC2 instance profile — same mechanism as `hr-docs/infra/compose/entrypoint.sh`. No writes, no schema changes, no chat turns issued.
- **Staging state at inspection:** EC2 `running` (t3.large), RDS `available`. 106 documents, 3 626 chunks, 27 convenios, 14 employees, 11 escalation cards, 20 message traces.

---

## A. Answer-loop integration (hr-backend)

### A.1 The decision code and the escalation taxonomy

#### A.1.1 Where the decision is made

One orchestrator: `ChatService::handleMessage()` — `hr-backend/app/Services/ChatService.php:125-310`.

| Stage | Code | Lines |
|---|---|---|
| Scope resolve (profile + date → trace) | `handleMessage` | `130:147` |
| Guardrail baseline (pre-hr-ai, unconditional) | `GuardrailService::check` | `152:163` (svc `GuardrailService.php:82`) |
| Admin guardrail layer (additive, raise-only) | `GuardrailPolicy::blockedTopicMatch` | `171:186` |
| Reference-fact pre-check (7c) | `ReferenceFactRouter::detectTopic` | `196:201` |
| Router (salary pre-classifier + LLM `/route`) | `RouterService::classify` | `215:225` |
| Off-domain escalate | | `228:244` |
| Salary SQL path | `SalaryAnswerService::answer` | `246:306` |
| Prose path | `answerProse` | `770:997` |
| Aggregation guard (pre-retrieval) | `isVagueAggregationTotal` | `787:799` |
| Retrieval union (recall-hardened) | `retrieveUnion` | `801:824`, `1008:1071` |
| **Check A** (retrieval floor) | | `826:839` |
| `/synthesise` | | `856:884` |
| **Check B** (citations present ∧ in-set) | | `909:917` |
| **Figure-guard** (deterministic pre-check) | `checkFigureGrounding` | `920:926`, `1316:1367` |
| **`/ground`** (per-claim entailment — the real gate) | `GroundingService::check` | `946:960` |
| Floor assembly + outcome | | `965:988` |
| Persist (message, citations, trace, card) | `persistTurn` | `1506:1602` |

The gate is `$decisionPass = $checkB && $figureGuard['grounded'] && $groundingResult['grounded']` (`ChatService.php:963`). Check C (model confidence) is recorded but `'used_as_gate' => false` (`:970`).

#### A.1.2 The actual escalation reasons

There is no PHP enum. Reasons are string literals constrained by a Postgres `CHECK` on `escalation_cards.reason`, last amended at `database/migrations/2026_09_10_100005_add_quality_sample_wrong_to_escalation_cards_reason.php:21`:

`low_confidence`, `sensitive_topic`, `off_domain`, `explicit_request`, `conflict`, `salary_not_in_chat` (retired, no path emits it), `salary_coverage_gap`, `reference_fact_coverage_gap`, `quality_sample_wrong` (outside the answer loop).

The 7g matrix is `EscalationExplainer::MATRIX` — `hr-backend/app/Support/EscalationExplainer.php:35-89`. It holds **44 keys**, not 39; the docs' 39 is the pre-Sprint-8 count (44 − 5 `quality_sample_wrong.*`). The guard test walks it: `tests/Unit/EscalationExplainerGuardTest.php:43-50`.

#### A.1.3 The candidate clarification matrix — the honest result

The spec asks for a table `reason × parameter → question text (es)`. Here is the analysis that produces it, over every answer-loop-reachable sub-outcome. The question for each is narrow: **is a missing *question parameter* (a fact about the question, not the asker's directory record, not the corpus) ever the cause?**

| `reason.sub_outcome` (`EscalationExplainer.php`) | What is actually missing | Question parameter? |
|---|---|---|
| `sensitive_topic.pattern_baseline` `:37` | nothing — policy refusal | No |
| `sensitive_topic.admin_blocked_topic` `:38` | nothing — policy refusal | No |
| `off_domain.legal_medical` `:39` | nothing — policy refusal | No |
| `off_domain.other_employee_data` `:40` | nothing — policy refusal | No |
| `off_domain.router_off_domain` `:41` | router classification / vocabulary | No |
| `off_domain.admin_off_domain` `:42` | policy refusal | No |
| `explicit_request.explicit_request` `:44` | nothing — employee asked for a human | No |
| `low_confidence.no_retrieval` `:46` | corpus (no eligible chunk) | No |
| `low_confidence.weak_retrieval` `:47` | corpus / phrasing → **Sprint 10b** (lexicon, decomposition) | No |
| `low_confidence.citations_failed` `:48` | synthesis produced no in-set citation | No |
| `low_confidence.figure_not_grounded` `:49` | the figure is not in the cited chunk | No |
| `low_confidence.entailment_failed` `:50` | `/ground` rejected a claim | No |
| `low_confidence.grounding_truncated` `:51` | transient provider/`max_tokens` | No |
| `low_confidence.aggregation` `:52` | question asks to *sum* leave types → **10b/10c** | No |
| `low_confidence.cross_path` `:53` | salary+prose compound → **10b** | No |
| `low_confidence.answer_model_not_configured` `:54` | admin settings | No |
| `low_confidence.provider_error` `:55` | infrastructure | No |
| `low_confidence.unspecified` `:56` | catch-all | No |
| `conflict.fact_vs_convenio` `:58` | genuine conflict — **must** escalate | No |
| `salary_coverage_gap.no_convenio` `:60` | directory (convenio) | No — ADR-0028 |
| `salary_coverage_gap.no_table` `:61` | corpus (no xlsx) | No |
| `salary_coverage_gap.future_only` `:62` | date, but derived from `Carbon::today()` | No |
| `salary_coverage_gap.category_unresolved` `:63` | job category — **already handled** by `needs_category` (`ChatService.php:285`) | No — directory, and solved |
| `salary_coverage_gap.no_row_for_category` `:64` | corpus | No |
| `reference_fact_coverage_gap.no_convenio` `:66` | directory | No — ADR-0028 |
| `reference_fact_coverage_gap.no_reference_data` `:67` | corpus / review queue | No |
| `reference_fact_coverage_gap.only_needs_review` `:68` | 82 unverified facts → go-live data pass | No |
| `reference_fact_coverage_gap.out_of_validity` `:69` | corpus validity | No |
| `reference_fact_coverage_gap.employee_group_unknown` `:70` | directory (group) | No — ADR-0028, spec says never |
| `reference_fact_coverage_gap.group_structure_not_approved` `:71` | review queue (group tree) | No |
| `reference_fact_coverage_gap.subarea_not_recorded` `:72` | directory (sub-area) | No — ADR-0028 |
| `reference_fact_coverage_gap.group_split_since_fact_bound` `:73` | fact binding | No |
| `reference_fact_coverage_gap.same_validity_conflict` `:74` | conflict — must escalate | No |

**The candidate matrix is empty.** Not sparse — empty. Every clarifiable-looking entry resolves to directory scope, which the spec itself rules out.

This is not an accident of the taxonomy; it follows from the architecture. `reference_facts` is keyed on `(convenio_id, job_category_id, topic_id, group_label, validity)` — verified against the live schema, which has **no seniority, no absence-type, and no contract-time column**. The prose path is topical retrieval plus synthesis. **There is no step in the loop that takes a question parameter as an input**, so no step can fail for want of one.

And the allowlist itself does not survive contact with the schema:

| Spec §2.1 parameter | Reality | Verdict |
|---|---|---|
| `contract_time` (full/part) | `employees.employment_type`, **required** at `EmployeeDirectoryController.php:250` (`Rule::in(['full_time','part_time'])`), populated **14/14** on staging | Directory-owned and already known → **not clarifiable** |
| `seniority_years` | derivable from `employees.start_date` (`EmployeeDirectoryController.php:251`, nullable). Populated **0/14** on staging | Directory-owned; the fix is the go-live CSV pass, not asking the employee → **not clarifiable** |
| `reference_year` | derived from `Carbon::today()` (`ChatService.php:127`) | Deterministic already; the only ambiguous case is future salary, which is excluded → **not clarifiable** |
| `absence_type` | genuinely a question parameter; no directory column | **The only survivor** — but see below |

`absence_type` is the one honest candidate, and it has no supporting evidence: the one permisos question in the staging corpus, *"¿Cuántos días de permiso tengo por matrimonio según mi convenio?"* (cluster 12), **answered successfully** (escalation rate 0). No card exists where an unspecified absence type was the blocker.

Asking the employee for `seniority_years` would also be a **regression in safety, not an improvement**: it replaces an authoritative directory field with a self-asserted one. The spec's R1 mitigation (echo + trace) makes the assumption *visible*, but visibility is not correctness when a correct value already exists in the row next door.

### A.2 The real escalation cards on staging

All 11 cards, top cluster first. Cluster ranking from `question_clusters` (run_date 2026-09-11): the top cluster is **"¿Cuánto dura el periodo de prueba en mi convenio?"** — 5 members, escalation rate 0.60, `top_escalation_reason = reference_fact_coverage_gap`, exactly as the handoff says.

| # | Question | Reason · sub-outcome | What was actually missing | Would a clarification convert it? |
|---|---|---|---|---|
| 4 | ¿cuál es mi periodo de prueba? | `reference_fact_coverage_gap.subarea_not_recorded` | employee 13's **sub-area** in the directory | **No — not clarifiable.** Fix link `#view=directory&emp=c7f99e3b…` is correct |
| 5 | ¿cuál es mi periodo de prueba? | same | same | **No — not clarifiable** |
| 1 | ¿Cuánto dura el periodo de prueba en mi convenio? | `low_confidence` (pre-7g, no facts) | convenio 18 had no verified fact on the topic | No — corpus |
| 11 | ¿Cuántos días libres tengo en total al año sumando todos mis permisos y vacaciones? | `low_confidence.aggregation` | nothing missing — the question asks to **sum** across leave types; guard fires pre-retrieval (`ChatService.php:787`) | No — decomposition, **Sprint 10b/10c** |
| 10 | ¿Cuánto cobro este mes según mi tabla salarial? | `salary_coverage_gap.no_table` | convenio 28 has no salary table | No — corpus |
| 6 | Quiero denunciar un caso de acoso laboral… | `sensitive_topic.pattern_baseline` | nothing — correct refusal | No |
| 7 | Estoy sufriendo acoso por parte de un compañero… | `sensitive_topic.pattern_baseline` | nothing — correct refusal | No |
| 8 | ¿Cuál es la capital de Mongolia? | `off_domain.router_off_domain` | nothing — correct refusal | No |
| 9 | Quiero hablar con una persona de Recursos Humanos | `off_domain.router_off_domain` | nothing — employee asked for a human | No |
| 2 | pregunta de prueba fence 7d | `low_confidence` | test junk | n/a |
| 3 | pregunta de prueba fence 7d | `low_confidence` | test junk | n/a |

**Convertible by one clarifying question: 0 of 11 (0 of 9 excluding test junk).**

Per the spec's instruction: cards **4 and 5** are the ones whose missing piece is directory scope. I confirm the existing outcome is right — the fix is "record the sub-area for this employee in the Directorio," the card carries the correct deep link, and the go-live data pass already owns it. Making that clarifiable would let an employee assert their own group scope, which is precisely what ADR-0028 exists to prevent.

Two caveats on this evidence, stated plainly:
- **n = 9 real cards** is a small sample, all synthetic test traffic from four accounts. It is not production traffic. But it is *all the evidence that exists*, it is what the spec asked me to build the gold set from, and it points one way with no dissent.
- The sample cannot rule out that clarification would help on questions that **answer today with a vague conditional**. It can only show that clarification converts no *escalation*. That distinction is the basis of the re-scope proposal in §E.0.

### A.3 Injection, echo, and caveat points

Even though I am recommending against building the clarify half now, the integration points are mapped, because the fallback half needs the caveat point and any future clarify work needs the rest.

#### A.3.1 Where a clarified parameter would be injected (typed, not free text)

The precedent is exact and already works. `selected_job_category_id` is validated at `ChatController.php:37-38` (`['nullable','integer']`), passed as a typed 4th argument to `handleMessage` (`ChatController.php:41-46`), and consumed by `SalaryAnswerService::answer($employee, $asOfDate, $selectedJobCategoryId)` at `ChatService.php:270`. It is FK-validated against the convenio's own categories inside the service; anything out of scope resolves to null and escalates rather than guessing (`ChatController.php:34-37`).

A clarified parameter would follow the same shape: a new validated request field (`clarification: {parameter, value, matrix_key}`), a typed 5th argument on `handleMessage`, and a typed value threaded into the path that needs it. **The problem is the last step: no path needs it** (§A.1.3).

#### A.3.2 Where the echo line and the caveat go

Both belong in `persistTurn` (`ChatService.php:1506-1602`), which is already the single deterministic write point, and which already demonstrates the pattern: `ChatService.php:1527` overrides the employee-visible text unconditionally on every escalate turn —

```1519:1527:hr-backend/app/Services/ChatService.php
        // Sprint 7g Item 1 (ADR-0029): the SINGLE override point. Every escalate
        // call site above still passes its own per-reason internal copy (kept as
        // call-site documentation of why THAT path escalates) — it is discarded
        // here and replaced with the one fixed neutral message, unconditionally,
        // regardless of $escalationReason. This is what the guard/scan test
        // relies on: there is exactly one place in the codebase an employee-
        // visible escalation string can originate from.
        $employeeAnswer = $escalate ? self::EMPLOYEE_ESCALATION_MESSAGE : $answer;
```

This is after synthesis, after all gates, before persistence, in deterministic code — exactly what the spec requires, and it is guard-testable the same way 7g's is. The caveat is a one-line extension of `:1527`:

```php
$employeeAnswer = $escalate
    ? self::EMPLOYEE_ESCALATION_MESSAGE
    : self::decorate($answer, $trace);   // echo line prepended, caveat appended
```

with `decorate()` a pure function of `$trace['floor_decision']['clarification']` and `['fallback']`. Placing it here means the caveat lands on the **persisted** message body, so the §4.1 invariant ("no fallback answer can persist without it") is enforced by construction rather than by convention, and the admin view and the employee view cannot diverge.

Note the ordering constraint this creates: the caveat must be appended **after** `checkFigureGrounding` (`:926`) and `/ground` (`:949`), both of which run against `$answer` as synthesised. Decorating earlier would feed the gates a string the model did not produce. Placing it in `persistTurn` gets this right for free.

#### A.3.3 Why the clarified re-run would probably escalate anyway

Worth stating explicitly because it is the load-bearing objection. Suppose the employee answers "5 años". The value can only influence the answer by entering the `/synthesise` prompt. Then:

- the model selects a branch of a conditional rule ("con 5 años: 30 días");
- the **figure-guard** (`ChatService.php:920-926`) checks the figure appears in a cited chunk — it does, the chunk lists all brackets, so this passes;
- **`/ground`** (`:949`) checks per-claim entailment. The claim "*te corresponden* 30 días" is not entailed by a chunk that says "*con 5 o más años de antigüedad*, 30 días" unless the seniority premise is itself in the cited text. It is not — it came from the employee.

So the likely outcome is `low_confidence.entailment_failed`. To avoid it the answer would have to restate the condition ("con 5 años de antigüedad, el convenio establece 30 días") — which is **what synthesis already produces today without asking**. That is the crux: the clarification buys a turn of latency and a self-asserted premise, and the grounded answer it can safely produce is the conditional one it could have given in the first place.

I would want this falsified before building, not after. §E.0 proposes the cheap way to falsify it.

---

## B. Chat / message model

### B.4 The turn model, and the pending state that already exists

#### B.4.1 What is there today

There is no `Conversation` model — conversations are `chat_sessions` (`app/Models/ChatSession.php`, migration `2026_06_20_131017`), messages are `chat_messages` (`2026_06_20_131018`).

- `chat_messages`: `id`, `session_id`, `role` enum `user|assistant|hr_agent` (base migration `:11-16`; `hr_agent` + `author_admin_id` added additively at `2026_06_24_100001_add_hr_agent_author_to_chat_messages.php:26-38`), `content`, timestamps. **No status column.**
- Turns are ordered by `chat_messages.id` (`ChatSession.php:35-38`). **No `parent_id`, no sequence column.**
- Sessions are resolved by caller-supplied uuid or most-recent within `hr.session_window_hours` (24h) — `ChatService.php:1471-1491`.
- `message_traces`: `id`, `message_id`, `trace` **jsonb**, timestamps (`2026_06_20_131020`), cast `'trace' => 'array'` (`MessageTrace.php:20-22`). One row per assistant turn.

**The non-terminal turn already exists.** `needs_category` (`ChatService.php:285-294`) is a completed assistant message whose `floor_decision.outcome = 'needs_category'`, with **no escalation card, no citations**, carrying a constrained option list in the response payload (`persistTurn(..., 'needs_category', null, $result['categories'])` at `:294`). The employee answers by re-sending the original question with `selected_job_category_id`. The frontend renders it (`ChatScreen.tsx:193-224`) and dispatches on it (`:352-364`).

This is the clarify turn, already built, already shipped, already safe. **Spec R4 should be corrected**: the design question is not "can the model support a pending turn" but "should `clarify` be a generalisation of `needs_category`" — and it should.

#### B.4.2 The state machine (as it would be)

Modelled on `needs_category`, the pending clarification is **ephemeral and derived**, not stored as mutable state:

```
user turn ──▶ loop ──▶ outcome ∈ {answer, escalate, needs_category, clarify}

clarify:  assistant message persisted (the question text)
          trace.floor_decision = { outcome: 'clarify', clarification: {parameter, matrix_key, options} }
          no citations, no escalation card

next POST /chat/message in the same session:
  ├─ carries clarification.value + clarification.matrix_key
  │     └─▶ re-run the SAME frozen loop with the typed parameter
  │           └─▶ outcome ∈ {answer, escalate}   ← never clarify again
  └─ carries no clarification (employee asked something new)
        └─▶ ordinary new turn; the pending clarification simply expires
```

**Expiry is by construction, not by a job.** "Pending" is not a stored flag; it is "the most recent assistant turn in this session has `floor_decision.outcome = 'clarify'` and the current request did not answer it." A new question is just a new turn — the old clarify turn stays in history as a completed message, exactly as an unanswered `needs_category` turn does today. This is the cheapest correct answer to R4 and it needs **no** expiry column, **no** scheduler, **no** TTL.

**One-clarification-max** is enforced by the same derivation: the re-run is entered only when the request carries a `matrix_key`, and the re-run code path cannot emit `clarify` (a single boolean argument threaded to the decision point, asserted by the invariant test).

#### B.4.3 Trace fields

Additive keys under the existing `floor_decision` jsonb, so no schema change and no effect on any existing trace:

- `floor_decision.clarification = {parameter, value, matrix_key}` — spec §2.1.
- `floor_decision.fallback = "estatuto_gap"` — spec §2.2.
- `floor_decision.outcome` gains `'clarify'` (today: `answer | escalate | needs_category`, documented at `hr-docs/architecture/data-model.md:514`).

Because `floor_decision` is a jsonb blob and both keys are **absent** on every existing path, `Sprint7cAdditivityRegressionTest` (`tests/Feature/Sprint7cAdditivityRegressionTest.php`) stays green without modification: it asserts on specific keys inline (`:232-235`, `:117-150`, `:157-177`) against a bound fake `ExtractionClient` (`:266-338`), not on a whole-blob equality. **Nothing I propose adds a key to a non-fallback, non-clarify trace.**

#### B.4.4 Migrations

**hr-ai: none.** ADR-0007 holds — hr-ai never migrates, and §C.7 shows `/retrieve` needs no schema or parameter change either.

**hr-backend:**

| # | Migration | Needed by | Notes |
|---|---|---|---|
| M1 | `add_estatuto_fallback_gap_to_escalation_cards_reason` | §2.2 "escalations from a fallback attempt carry a distinct reason" | Same additive `CHECK`-swap pattern as `2026_09_10_100005_…:21`. New value `estatuto_fallback_gap`. |
| M2 | *(clarify only)* `add_clarification_to_escalation_cards_reason` | only if a clarified re-run needs a distinct reason | Defer with the clarify half. |

That is **one migration** for the fallback sprint. No `chat_messages` change, no `message_traces` change, no new table. The trace fields are jsonb; the pending state is derived.

### B.5 Frontend

#### B.5.1 What renders a chat turn today

Employee chat: `/app` → `EmployeeShell` (`src/App.tsx:13-19`, `src/shells/EmployeeShell.tsx:19-20`) → `ChatScreen` (`src/pages/chat/ChatScreen.tsx:226`).

| Turn | Component | Lines |
|---|---|---|
| Assistant answer | `AnswerBlock` | `ChatScreen.tsx:105-116` |
| Escalation notice | `EscalationBlock` | `:180-188` |
| **Constrained pick (`needs_category`)** | `CategoryPickBlock` | `:193-224` |
| Human HR reply | `HumanReplyBlock` | `:170-177` |
| Dispatch | inline in `ChatScreen` | `:342-366` |
| Citations | `CitationList` | `src/pages/chat/CitationList.tsx:42-67` |
| Trace / `floor_decision` | `TracePanel` | `src/pages/chat/TracePanel.tsx:103-115` |

Admin views reuse the same `TracePanel`: `HistoryPage.tsx:266`, `EscalationCardDrawer.tsx:323`, `QualitySampleQueue.tsx:329`.

Types: `ChatResponse` at `src/lib/api.ts:618-630`, `MessageTrace.floor_decision` at `:596-615`, `sendChatMessage()` at `:632-645`.

#### B.5.2 The clarify UI, in the token system

ADR-0012/0013: **one stylesheet**, `src/index.css`, tokens in `:root` / `[data-theme="dark"]`, components reference only `var(--…)`, no raw hex, **no Tailwind**, no per-component CSS files. Spacing `--space-1..8` (4px base), radius `--radius-sm|md|lg` (`hr-docs/design-system.md:92-93`).

The proposal is deliberately boring: **reuse `CategoryPickBlock` wholesale**, renamed and generalised.

- **Enum parameters → option chips.** Exactly today's markup: a `role="group"` wrapper with an `aria-label`, children `button.btn.btn-secondary.category-pick-option` (`ChatScreen.tsx:207-218`, CSS `index.css:1251-1260`). Generalise `.category-pick` → `.chat-clarify-options` with `.category-pick` kept as an alias so the salary pick is untouched. `.seg`/`.seg-btn` (`index.css:1428-1451`) is the alternative but implies mutually-exclusive filter state; chips read better as a one-shot answer.
- **Numeric parameters → `input.input[type=number]`**, the pattern at `GuardrailsPage.tsx:189-200` (min/max/step/placeholder), plus a `.btn.btn-primary` submit. Only needed if a numeric parameter survives the re-scope — on current evidence none does.
- No new tokens, no new colours. The clarify bubble is a `card chat-bubble chat-bubble--assistant` like every other assistant turn.

**One real bug this work must fix, not inherit.** A hydrated `needs_category` turn is silently coerced to `'answer'` on reload because the live option list is gone:

```69:71:hr-frontend/src/pages/chat/ChatScreen.tsx
    // A hydrated needs_category turn has no live category list; render its prose
    // as a normal answer (the single-turn pick is ephemeral — Sprint 5 adds it).
    outcome: m.outcome === 'needs_category' ? 'answer' : (m.outcome ?? 'answer'),
```

`ConversationPresenter` reads `outcome` from the trace (`app/Services/ConversationPresenter.php:57-68`) but does not carry the options. If a `clarify` turn shipped with this behaviour, an employee who refreshes mid-clarification loses the question's answer UI and sees a bare sentence — the "reachability" failure class from the standing lessons. Fix: persist the option list in `floor_decision.clarification.options` (it is deterministic matrix output, not free text) and have `ConversationPresenter` surface it. That also fixes the salary pick, additively.

#### B.5.3 Admin trace rendering

`TracePanel.tsx:103-115` renders `floor_decision` and maps `outcome` to a Spanish label; a `clarify` outcome and a `fallback` field need one branch each there, plus the `MessageTrace` type at `api.ts:596-615`. `TracePanel` is shared, so admin history, the escalation drawer and the quality queue all pick it up at once.

---

## C. Estatuto fallback

### C.6 The full-gap query, and why `fullGapConvenios()` is the wrong thing to reuse

#### C.6.1 Where the logic lives

`app/Support/CorpusCoverageService.php`. The prose classification is **inline inside the private `proseCell()`** (`:167-280`), called from `grid()` at `:114`. There is **no** exported `hasZeroProseChunks()` today.

```174:195:hr-backend/app/Support/CorpusCoverageService.php
        $activeDocs = DB::table('documents')
            ->where('convenio_id', $convenioId)
            ->whereIn('document_type_id', $proseIds)
            ->where('retrieval_status', 'active')
            ->select('id', 'uuid', 'document_type_id')
            ->get();
        // …
        foreach ($activeDocs as $doc) {
            $chunkCount = (int) DB::table('document_chunks')->where('document_id', $doc->id)->count();
            if ($chunkCount > 0) {
                $activeWithChunks[] = $doc;
            } else {
                $activeZeroChunk[] = $doc;
            }
        }
```

`$proseIds` = `KnowledgeMap::proseTypeIds()` (`:169`, `app/Support/KnowledgeMap.php:18`) = `convenio_text`, `national_law`, `partial_agreement`. "Zero prose chunks" = never entering the `$activeWithChunks !== []` branch (`:197`).

**`fullGapConvenios()` (`:569-595`) must not be reused.** It requires **all four** cells ✗ — prose ∧ salary ∧ facts ∧ rulings (`:573-574`). That is the Cobertura "brecha total" list, a different predicate. Measured on staging: **12** convenios have zero active prose chunks, but only **10** are all-gap on prose+salary+facts. Convenios 4 and 21 have verified reference facts and would be *excluded* by `fullGapConvenios()` while being exactly the population §2.2 describes. The handoff's "9 full-gap convenios" is the four-cell number and should not be used as the trigger population.

#### C.6.2 The reuse plan (R5)

Extract, don't duplicate:

1. Add `public function hasZeroProseChunks(int $convenioId): bool` to `CorpusCoverageService`, containing the `:174-195` query verbatim.
2. Rewrite `proseCell()` to call it, so `grid()`/Cobertura/`corpus:coverage` and the answer loop are provably the same predicate.
3. The answer loop calls `hasZeroProseChunks()` — never its own query.
4. Invariant test: for every convenio on the fixture corpus, `hasZeroProseChunks($id) === ! $grid[$id]['prose']['covered']`. That test is the anti-drift device; it is what makes R5 a closed risk rather than a promise.

Note `CorpusCoverageService` has **no caching** (`KnowledgeMap::proseTypeIds()` deliberately dropped its static cache — `KnowledgeMap.php:23-44`), so the answer-loop call is two indexed counts per turn. Acceptable; measure in the review.

#### C.6.3 The finding that changes the trigger

I checked all three plausible definitions of "zero prose chunks" against staging:

| Definition | 12 gap convenios |
|---|---|
| A — `CorpusCoverageService.proseCell()`: active prose **docs** with >0 chunks | 0 for all 12 |
| B — what `/retrieve` actually sees: `document_chunks.convenio_id = X AND retrieval_status='active'` | 0 for all 12 |
| C — any chunk carrying that `convenio_id`, regardless of status | **non-zero for 6 of them** |

A and B agree exactly today — good, and worth an invariant test to keep it that way. But **C is the story**:

| Convenio | Employees | A/B chunks | C (any) | Why |
|---|---|---|---|---|
| 4 · OCIO EDUCATIVO Y ANIMACION ANDALUCIA | **2** | 0 | **151** | doc 89, `historical`, validity **2022-01-01 → 2025-12-31**, **no successor** |
| 21 · HOSTELERIA NAVARRA | **1** | 0 | **52** | doc 51, `historical`, validity **2022-01-01 → 2025-12-31**, **no successor** |
| 28 · DEV FIXTURE — placeholder | **1** | 0 | 0 | `DEV-FIXTURE-0001`, scheduled to be scrubbed pre-production |
| 5, 7, 11, 14, 16, 17, 24, 26, 27 | 0 | 0 | 0–141 | no employees |

**Every employee-bearing zero-prose convenio on staging is a convenio whose text expired on 2025-12-31 and was not renewed.** The text exists, is chunked, and is one status flag away from being live.

This matters legally, which is the only reason it matters. Under ET art. 86.4, an expired convenio generally remains in **ultraactividad** until a new one is negotiated. Answering such an employee with "the Estatuto sets the legal minimum" — even with the caveat — asserts that the legal floor is what applies, when the expired convenio's better terms probably still do. That is a **confidently wrong answer with legal weight in the direction the whole system is built to avoid**, and the caveat ("tu convenio puede mejorar estas condiciones") makes it worse by implying the convenio is unknown rather than expired.

**Recommendation — split the trigger:**

| Sub-state | Predicate | Behaviour |
|---|---|---|
| `never_ingested` | zero prose chunks **and** no chunk of any status carries this `convenio_id` | **Fallback fires.** Estatuto answer + caveat. |
| `expired_only` | zero *active* prose chunks **but** ≥1 historical chunk exists | **No fallback.** Escalate as today, new reason `estatuto_fallback_gap`, fix action *"Source the current text (or confirm ultraactividad) for this convenio."* |

This is deterministic, it reuses the same extracted method plus one more count, and it keeps the fail-closed posture. It also has a cost I should not hide: **on today's staging corpus it reduces the fallback's live population to convenio 28, the DEV fixture** — which is why §C.9 says the fallback eval needs seeding.

I recommend surfacing this to the client/HR too: two convenios covering three test employees (and, in production, however many real employees sit under those two agreements) have **expired collective agreements with no successor in the corpus**. That is a data-pass item worth more than the fallback itself.

### C.7 Restricting `/retrieve` to `national_law` (R2)

**Confirmed: no hr-ai change of any kind is needed** — no new parameter, no schema change, no prompt change. The existing request already expresses it.

```119:125:hr-ai/app/main.py
class RetrieveRequest(BaseModel):
    query: str
    convenio_id: int | None = None
    include_national_law: bool = True
    retrieval_status: list[str] = ["active"]
    as_of_date: date | None = None
    k: int = 8
```

```100:119:hr-ai/app/chunks_db.py
                WHERE ( ($2::bigint IS NOT NULL AND convenio_id = $2)
                        OR ($3::boolean AND authority_level = 'national_law') )
                  AND retrieval_status = ANY($4::varchar[])
```

With `convenio_id = null` the first disjunct is false and only `authority_level = 'national_law'` matches. National-law chunks are identified by the denormalized `document_chunks.authority_level` column (migration `2026_06_20_131011_create_document_chunks_table.php:16-42`), copied from `documents.authority_level` at embed time (`app/Console/Commands/ChunksEmbed.php:144-154`).

**The recall-hardened union is entirely hr-backend-side and is not touched.** `ChatService::retrieveUnion()` (`:1008-1071`) already performs precisely this call as its second pass:

```1039:1048:hr-backend/app/Services/ChatService.php
        // National-law-only pass (convenio_id = null) — surfaces the on-topic
        // Estatuto article for a silent-convenio topic even when convenio chunks
        // dominate the scoped top-k (the Art. 14 ET recall gap).
        $nl = $this->safeRetrieve([
            'query' => $question,
            'convenio_id' => null,
            'include_national_law' => true,
            'retrieval_status' => ['active'],
            'as_of_date' => $asOf,
            'k' => $nlK,
        ]);
```

So the fallback path is: run **only** this pass (with `k = $poolK`, and the sub-queries also at `convenio_id = null`), skip pass 1 and skip `precedenceRerank()` (`:1059-1068`) — the re-rank is a convenio-vs-baseline contest with no convenio side, so it is a no-op by definition and skipping it is clearer than relying on that.

Because the change is *which branch of `retrieveUnion` runs*, the recall-hardened union for normal prose is reached by an untouched code path. `orderByAuthority()` (`:857`, `:1223-1233`) is a no-op on a homogeneous set. `/synthesise` (`main.py:694-741`) and `/ground` (`main.py:811-850`) take no scope parameter — they receive pre-selected chunks with per-chunk `authority_level` — so they need no change either. `authority_used` will come back `['national_law']` naturally from synthesis (`:906`, `:983`).

**Two guards the build must add**, because the safety properties that normally hold implicitly stop holding when the convenio side is empty:
1. **The salary path must be structurally unreachable from the fallback** (spec §2.2). It already is by ordering — the salary pre-classifier and `SalaryAnswerService` run at `:247-306`, before `answerProse` at `:309` — but "unreachable by ordering" is the kind of thing that quietly stops being true. Assert it directly (§E.3 T7).
2. **Check A's floor is now the only thing between the employee and a weak Estatuto match**, with no convenio chunks competing. Verify against the real floor in the eval rather than assuming; record the observed top-score distribution in `review.md`.

### C.8 Estatuto chunk quality (R3) — measured, not assumed

**Headline: the premise is out of date. The Estatuto on staging is already article-chunked. A full re-chunk is not a prerequisite. One targeted defect is.**

The 2c holdout was real but applies to **doc id 73 in the local corpus** (`sprint-02c-rechunk/sprint-02c-rechunk-build-prompt.md:16`, `roadmap.md:209`). Staging was built by one clean ingest on **2026-09-06**, after 2c, with `ChunksEmbed`'s `IN_SCOPE_TYPES` including `national_law` (`app/Console/Commands/ChunksEmbed.php:34`). The active Estatuto on staging is **doc 75, "ESTATUTO TRABAJADORES julio2025", 235 chunks**, all embedded 2026-09-06 18:21 (docs 80 and 81 are historical editions, out of scope).

Measured on the live chunks:

| Metric | Estatuto (doc 75) | Re-chunked active convenios (comparison) |
|---|---|---|
| Chunks | 235 | 1 518 |
| Mean / median / p90 chars | 1 877 / 1 657 / 3 654 | 1 460 / — / — |
| Min / max chars | 67 / 4 613 | — |
| Chunks starting `Artículo N` | 168 (71.5%) | 983 (64.8%) |
| Null embeddings | **0** | — |

Article-boundary behaviour is present and correct:
- **89 of the Estatuto's 92 articles have their own body chunk.**
- Oversized articles are sub-split **with the header carried onto continuations** — the `_article_header_line` behaviour introduced in 2c (`hr-ai/app/chunking/chunker.py:257-263`). Verified on art. 49 (chunks 117, 118) and art. 53 (chunks 126, 127), both continuations opening with their own `Artículo N.` header.
- Spot checks: **art. 14 Periodo de prueba** → chunk 54, own chunk, 2 296 chars. **Art. 38 Vacaciones anuales** → chunk 89, own chunk, 2 014 chars.

**Two real defects, both narrow:**

**(D1) Article 37 has no chunk of its own — and it is the article the spec names.** The three articles missing an own-chunk are **26, 27 and 37**. Arts. 26 (*Del salario*) and 27 (*SMI*) are irrelevant: the spec excludes the salary path and SMI from the fallback. But **art. 37 is *"Descanso semanal, fiestas y permisos"*** — the permisos article, explicitly listed in spec §2.3 as fallback gold-set material. It is buried inside chunk 84: **4 613 chars (the document's largest), spanning pages 66-74, opening with art. 36 *Trabajo nocturno***. That is the exact Correction-03 buried-grant shape.

Root cause, confirmed in the text: the header sits mid-line — `"…durante la jornada de trabajo. Artículo 37. Descanso semanal, fiestas y permisos. 1. Los trabajadores tendrán derecho…"` — so precision guard 1 rejects it:

```104:111:hr-ai/app/chunking/chunker.py
def _at_line_start(text: str, s: int) -> bool:
    """Guard 1 — the anchor must start a line (allowing leading indentation),
    not appear mid-sentence (kills inline `…del artículo 22…` wrapped onto a new
    line only if it is genuinely line-leading)."""
```

The guard is doing its job (it exists to reject inline cross-references); the PDF's line breaks simply did not survive extraction at that spot. Today this is invisible because convenio chunks and the precedence re-rank keep the fallback out of the frame. On a national-law-**only** path, "¿cuántos días de permiso por matrimonio?" retrieves a 4 613-char chunk whose subject is night work — a weak embedding match that will either miss Check A or produce an answer `/ground` rejects.

**(D2) 32 of 235 chunks (13.6%) are table-of-contents chunks** — pages 1-11, dot-leader lines — and **25 of them open with `Artículo N.`**, so they are indistinguishable from real article chunks by their first line. Example, chunk 6: `"Artículo 14. Periodo de prueba.................................. 40 Artículo 15. Duración del contrato…"`. These are pure topical keyword density with zero substance: near-ideal decoys for a short question, currently out-competed by real convenio chunks. Remove the convenio side and they compete head-on for the synthesis cap.

**Assessment.** A national-law-only path **can** work on today's chunks for most topics — vacaciones (art. 38), periodo de prueba (art. 14), preaviso (arts. 49/53) all have clean own-chunks. It **cannot** work honestly for permisos, which is a third of the spec's own gold set, and it carries a systematic 13.6% decoy rate.

**This is a prerequisite step of the sprint, but a small one — not a corpus re-chunk.** Scope: **one document, 235 chunks**. Two fixes:
- **F1 — TOC exclusion.** Drop dot-leader index lines before anchor detection (`\.{6,}\s*\d+\s*$` on a line), or skip pages preceding the first body article. Deterministic, testable on the real PDF, and it improves every path that touches the Estatuto, not just the fallback.
- **F2 — art. 37 anchor.** Relax guard 1 to accept a **sentence-initial** header (preceded by `.`/`;` + whitespace) when the candidate is capitalised **and** monotonic — guards 2 and 3 already carry the precision load in that case. This must be validated against the 2c false-positive corpus (`…según el artículo 22 del Estatuto…`, lowercase, mid-sentence) before it goes anywhere near a re-embed.

**Cost and risk, stated honestly.** 235 chunks is minutes of embedding on the current t3.large — **`resize-for-ingest.sh` is not needed**, and I want to be clear about that rather than book a resize out of habit. The *risk* is not cost, it is blast radius: the Estatuto is the universal baseline for **every** convenio path, which is exactly why 2c held it out. So this needs a ⏸ checkpoint and the national-law gold tests as a gate (§E.5), not because it is expensive but because it is the one change in this sprint that can regress an answer for an employee whose convenio is fine.

### C.9 Test employees for the eval and eyes-on

| Convenio | Employees | Retrievable chunks | Salary tables | Verified facts | Usable for the fallback eval? |
|---|---|---|---|---|---|
| 28 · DEV FIXTURE | `employee@hr-staging.internal` | 0 | 0 | 0 | Only genuine `never_ingested` case — **but it is `DEV-FIXTURE-0001`, on the pre-production scrub list** |
| 4 · OCIO ANDALUCIA | `test-andalucia@`, `test-andalucia-nocat@` | 0 | 0 | 1 | **No** — `expired_only`; under the split trigger it must *not* fall back |
| 21 · HOSTELERIA NAVARRA | `test-hosteleria-navarra@` | 0 | 0 | 3 | **No** — `expired_only`; also still answers periodo de prueba via the 7f facts |
| 22 · LIMPIEZA (`test-navarra@`) | 1 | 75 | 2 | 0 | **Partial-gap control** — spec §6.5 |
| 13 · LIMPIEZA GIPUZKOA | 3 | 177 | 0 | 0 | Covered control |

**Seeding is required.** Three employees to add in the eval fixture (hr-backend seeder, staging only, clearly marked test data):

1. `test-fullgap@example.com` — bound to a **real registry convenio with no prose document at all**: convenio **7** (`ACCIÓN E INTERVENCIÓN SOCIAL ESTATAL`), **16** (`HOSTELERIA Y TURISMO HUESCA`) or **27** (`LOCALES Y CAMPOS DEPORTIVOS`) — all have 0 chunks of any status and 0 employees today. Recommend **16**, a hostelería convenio, so the questions read naturally. This is the primary fallback subject.
2. `test-expired-convenio@example.com` — on convenio **4** (or reuse `test-andalucia@`) — the **negative** control that must *not* fall back.
3. `test-partialgap@example.com` — reuse `test-navarra@example.com` (convenio 22) for §6.5.

Do **not** build the eval on convenio 28: it is a fixture that will be deleted before production, and an eval whose subject is scheduled for deletion measures nothing durable.

---

## D. Evals

Harness location: `hr-docs/sprints/sprint-10a/eval/`, following 7b-2 / 7d / 8: gold JSON committed, an artisan command that runs it read-only, results tabulated into `review.md`.

### D.10.1 Fallback gold set (buildable now)

15 questions against `test-fullgap@example.com` (convenio 16, seeded), each with the expected Estatuto article and the expected grounded content. Every one is answerable from an article that **has its own clean chunk today** — except the permisos block, which is gated on fix F2 and is the reason F2 is in scope.

| # | Question (es) | Expected source | Status today |
|---|---|---|---|
| 1 | ¿Cuántos días de vacaciones me corresponden al año? | art. 38 (chunk 89) | clean |
| 2 | ¿Pueden sustituirme las vacaciones por dinero? | art. 38.1 | clean |
| 3 | ¿Cuánto puede durar mi periodo de prueba? | art. 14 (chunk 54) | clean |
| 4 | ¿Me pueden despedir durante el periodo de prueba? | art. 14.2 | clean |
| 5 | ¿Cuánto preaviso tengo que dar si me voy? | art. 49 (chunks 117-118) | clean |
| 6 | ¿Qué preaviso me deben dar en un despido objetivo? | art. 53 (chunks 126-127) | clean |
| 7 | ¿Cuál es la jornada máxima anual? | art. 34 | clean |
| 8 | ¿Cuántas horas extraordinarias puedo hacer al año? | art. 35 | clean |
| 9 | ¿Cuánto descanso semanal me corresponde? | art. 37.1 | **blocked on F2** |
| 10 | ¿Cuántos días de permiso por matrimonio? | art. 37.3 | **blocked on F2** |
| 11 | ¿Qué permiso tengo por fallecimiento de un familiar? | art. 37.3 | **blocked on F2** |
| 12 | ¿Cuántos días festivos al año? | art. 37.2 | **blocked on F2** |
| 13 | ¿Cuánto dura el permiso por nacimiento? | art. 48 | clean |
| 14 | ¿Cuánto cobro de salario base? | — | **must escalate** (salary path, no fallback) |
| 15 | ¿Cuál es el SMI este año? | — | **must escalate** (excluded) |

Metrics (spec §2.3): (a) answer rate vs today's ~0; (b) 100% grounded (`floor_decision.grounding.grounded`); (c) caveat present on 100% of persisted fallback answers — asserted on `chat_messages.content`, not the API payload; (d) zero convenio-specific claims — manual read of all answers, recorded; (e) Q14/Q15 escalate with reason `salary_coverage_gap` and **no** `floor_decision.fallback` key.

Plus a **negative set**: the same 13 answerable questions asked as `test-expired-convenio@` (convenio 4) must **all** escalate with `estatuto_fallback_gap` and no fallback. This is the trigger-split test, and I consider it the most important row in the whole eval.

### D.10.2 Clarify gold set — I cannot build one, and here is the honest accounting

The spec says: build it from the real escalation cards, top cluster first. I did that in §A.2. **The gold set is empty: 0 of 9 non-junk cards would be converted.** The top cluster (periodo de prueba, 60% escalation) is `reference_fact_coverage_gap.subarea_not_recorded` — directory scope, which the spec's own R1 rules out.

Writing a synthetic gold set of questions I invent, that a clarification happens to fix, would be measuring the implementation against itself. Per the standing rule ("the eval is the deliverable, gold set on real hard fixtures"), that is not an eval.

**What I propose instead — a 30-minute falsification probe before any build** (§E.0). Take the ~12 answered prose turns on staging plus the four highest-headcount convenios, and ask, for each of the four allowlisted parameters: *is there any question, on this corpus, where the grounded answer differs depending on that parameter, and where the loop does not already produce the conditional?* Record the count. If it is zero, the clarify half is confirmed unbuildable today and goes back to spec with real evidence rather than my argument. If it is not zero, those questions **are** the gold set — and it will be a gold set of *answer-precision* cases, not escalation conversions, which means spec §2.3(a) needs rewriting too.

### D.10.3 Additivity

`Sprint7cAdditivityRegressionTest` unchanged and green. Extend the golden set with the 2-3 traces spec §2.3 asks for, exercising **partial-gap** escalation behaviour (convenio 22, `test-navarra@`): each must show no `floor_decision.fallback` key and a byte-identical trace shape.

---

## E. Plan output

### E.0 The scoping recommendation

| Option | What it is | My view |
|---|---|---|
| **A (recommended)** | **Sprint 10a = Estatuto fallback only.** Build steps 1-7 below. Run the falsification probe (§D.10.2) as a half-day spike inside this sprint and write the result into `review.md`. Clarify returns as **Sprint 10d** with a spec grounded in that result. | Ships real value in one risk. Keeps the "one risk at a time" rule. Turns the clarify question from an argument into a measurement. |
| B | Build both as specced. | Builds a matrix with no entries and an eval with no gold set. I would be writing a guard test asserting that an empty matrix is complete. |
| C | Build clarify with a synthetic gold set. | Measures the implementation against itself. Contradicts the standing eval rule. |

If the answer is A, the ADR situation changes too: **ADR-0032 (Estatuto fallback)** is written this sprint and should record the `never_ingested` / `expired_only` split as its central decision. **ADR-0031 (clarifying turn)** is deferred; the number stays reserved.

Everything below assumes A. Steps marked *(clarify)* are the deferred half, listed so the ordering is on record.

### E.1 Ordered build steps — foundation first, one risk at a time

| # | Step | Why here | Risk |
|---|---|---|---|
| 1 | **Extract `hasZeroProseChunks()`** from `proseCell()` (`CorpusCoverageService.php:174-195`); rewrite `proseCell()` to call it; add the equivalence test. No behaviour change. | Foundation. Closes R5 before anything depends on it. | none |
| 2 | **Add `classifyProseGap(): 'covered'\|'expired_only'\|'never_ingested'`** on the same service. Pure query, no caller yet. | The trigger split (§C.6.3) as an isolated, testable unit. | none |
| 3 | **Estatuto chunk fixes F1 + F2** in `hr-ai/app/chunking/chunker.py`; unit-test against the real Estatuto PDF **and** the 2c false-positive corpus; **no re-embed yet**. ⏸ **CP-1** | The one change with universal blast radius. Proven in isolation first. | **High** — 2c's stated reason for the holdout |
| 4 | **Re-chunk + re-embed doc 75 only** (235 chunks); run the national-law gold tests (`trabajo a distancia → national_law`, Gipuzkoa 31/26, Navarra periodo de prueba 15/30) **before and after**. ⏸ **CP-2** | Gated on step 3 passing. No resize needed. | **High** — the gate is the gold tests |
| 5 | **Fallback trigger + retrieval branch** in `ChatService::answerProse` / `retrieveUnion`: national-law-only pass, skip `precedenceRerank`, `floor_decision.fallback = "estatuto_gap"`. Answer path only — **no caveat yet**. | First behaviour change, on a now-trustworthy substrate. | Medium |
| 6 | **Caveat + `estatuto_fallback_gap`** — `decorate()` in `persistTurn` (`:1527`), migration M1, `EscalationExplainer` entries for the new reason, fix links. | The safety wrapper, in the single override point. | Low |
| 7 | **Frontend + admin trace**: `floor_decision.fallback` in the `MessageTrace` type (`api.ts:596-615`) and `TracePanel` (`TracePanel.tsx:103-115`); Analítica separates the new reason. | Visible last, once the trace is real. | Low |
| 8 | **Seed the three eval employees** (§C.9); run both eval sets; write `review.md`. | | |
| 9 | **Falsification probe** for the clarify half (§D.10.2); record in `review.md`. | Half-day. Produces the evidence Sprint 10d needs. | none |
| — | *(clarify)* matrix, `clarify` outcome, re-run guard, echo line, UI, `ConversationPresenter` options fix | **Deferred to 10d** | |

### E.2 Migrations

**hr-ai: none** (ADR-0007; §C.7 confirms no schema or parameter change).

**hr-backend: one.**

| M1 | `2026_09_1X_XXXXXX_add_estatuto_fallback_gap_to_escalation_cards_reason.php` | Additive `CHECK` swap on `escalation_cards.reason`, adding `estatuto_fallback_gap`. Copies the `PRIOR`/`CURRENT` const pattern from `2026_09_10_100005_…:21` so the down-migration is exact. |

No `chat_messages` change (no status column — the pending state is derived, §B.4.2). No `message_traces` change (jsonb, additive keys). No new tables.

### E.3 Invariant tests — `Sprint10aInvariantTest`

Per spec §4.1, restricted to the fallback half, plus the R5 and trigger-split guards the evidence forces:

| T | Assertion |
|---|---|
| T1 | **One definition of truth.** For every convenio in the fixture corpus, `hasZeroProseChunks($id) === ! $service->grid()[$id]['prose']['covered']`. Fails the build if a second definition drifts. (R5) |
| T2 | **Fallback fires only at zero active prose chunks.** Convenio with ≥1 active prose chunk → no `floor_decision.fallback`, trace byte-identical to pre-sprint. |
| T3 | **Partial gap → old behaviour.** Convenio with chunks but silent on the topic → today's escalation, no fallback. |
| T4 | **`expired_only` never falls back.** Convenio whose only prose chunks are `historical` → escalate `estatuto_fallback_gap`, **no** fallback. (§C.6.3) |
| T5 | **Caveat present on every persisted fallback answer.** Asserted on `chat_messages.content` (the persisted row), not the API payload — the caveat must survive reload and admin view. |
| T6 | **Authority.** Every fallback answer has `floor_decision.authority_used === ['national_law']`; no `official_convenio` or `structured_reference` chunk reaches `/synthesise` on that path. |
| T7 | **Salary unreachable from fallback.** A salary question from a full-gap employee escalates `salary_coverage_gap` with no `fallback` key — asserted directly, not inferred from call ordering. |
| T8 | **Reference-fact pre-check unchanged.** A full-gap convenio *with* a verified fact (convenio 21's shape) still answers from the fact, never from the Estatuto — authority precedence holds when prose is empty. |
| T9 | **Every new reason has an explanation.** `estatuto_fallback_gap` appears in `EscalationExplainer::MATRIX`; `EscalationExplainerGuardTest` stays green. |
| T10 | **Golden trace.** `Sprint7cAdditivityRegressionTest` green, extended with the partial-gap traces from §D.10.3. |

*(clarify, deferred to 10d: matrix completeness, one-clarification-max, no directory write, echo line present.)*

### E.4 Open questions for the reviewer

1. **Scope call — Option A?** (§E.0) Build the fallback now, take clarify back to spec behind the falsification probe. This is the decision the rest of the plan hangs on.
2. **The `expired_only` split** (§C.6.3) — do you accept that an expired-and-not-renewed convenio must **not** fall back? It costs most of the fallback's live population on today's staging data and makes seeding mandatory. I believe the ultraactividad argument makes it non-optional, but it is a product/legal call and it is yours.
3. **Fix F2's guard relaxation** (§C.8) — accepting sentence-initial article headers touches the detector 2c hardened. Acceptable with the false-positive corpus as a gate, or would you rather special-case the three known articles (26/27/37) and leave the guard alone? F2 is more general; the special case is smaller. My preference is F2 **with** the 2c corpus as a hard gate.
4. **Which convenio to seed** for the `never_ingested` test employee — my recommendation is **16 (HOSTELERIA Y TURISMO HUESCA)**; alternatives 7 and 27. Also: does a seeded employee on a real registry convenio create a Cobertura/Analítica reporting artefact you would rather avoid?
5. **`explicit_request`** is in the matrix (`EscalationExplainer.php:44`) but no path emits it — card 9 ("Quiero hablar con una persona de Recursos Humanos") was classified `off_domain.router_off_domain` instead. Out of scope for 10a; worth a follow-up ticket?
6. **Expired convenios as an HR item.** Convenios 4 and 21 have agreements that expired 2025-12-31 with no successor in the corpus. Should that go to the go-live data pass now rather than waiting for this sprint to close?

### E.5 ⏸ Checkpoints where Pedram must act

| ⏸ | When | What you do | Why it needs you |
|---|---|---|---|
| **CP-0** | Now | Answer §E.4 Q1-Q4 (at minimum Q1 and Q2) | Scope and a legal-shaped product call |
| **CP-1** | After build step 3 | Review the F1/F2 detector diff and the false-positive results **before** anything is re-embedded | This is the change 2c deliberately did not make |
| **CP-2** | After build step 4 | Confirm the national-law gold tests pass before/after the doc-75 re-embed | The Estatuto is the baseline for every convenio; a regression here is invisible to route/API tests |
| **CP-3** | Build step 8 | Approve the three seeded test employees on staging | Test data on a real registry convenio |
| **CP-4** | After `review.md` | **Eyes-on in a real browser on staging** per spec §6, adjusted: §6.1-6.2 (clarify) are **not applicable** under Option A; §6.3-6.6 (fallback, salary-still-escalates, partial-gap control, admin trace) apply, plus a **new §6.7**: the `expired_only` employee (`test-andalucia@`) must escalate, not fall back | Standing rule, and §6.7 is the trigger-split proof |

**No `resize-for-ingest.sh` is needed this sprint.** Re-embedding 235 chunks on the t3.large is minutes. I am flagging that explicitly because the kickoff assumed a resize for any re-chunk — the assumption is right in general and wrong for a single document.

---

## STOP

Plan complete. No application code, migrations, tests or seeders written; nothing committed. Awaiting review — in particular the Option A scope call (§E.0) and the `expired_only` decision (§C.6.3, §E.4 Q2).
