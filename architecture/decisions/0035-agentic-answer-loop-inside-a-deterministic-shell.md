# ADR-0035 — An agentic answer loop inside a deterministic shell: the model proposes, the rules dispose; escalation is additive; the general lane stays gated; classic and agent are switchable at runtime

**Status:** accepted (Sprint 13, CP-2 passed 2026-09-30)

## Context

Through Sprint 12 every employee question went through one fixed pipeline (`ChatService::handleMessage()`, the **classic** engine): guards → router → one of four paths (salary table, verified reference fact, prose retrieval + synthesis + grounding, or escalation) → `persistTurn()`. It is correct and deterministic, and it has two structural limits that showed up in the Sprint 10b/10c/11 traffic: (1) the router picks **one** path up front, so a question that needs a second look — a corpus miss that a different source could answer, an ambiguous question that needs one clarification, a situational question whose first retrieval misses — can only escalate; (2) there is no place to add a new *capability* (a web-sourced explanation, a clarifying question) without threading it through the fixed chain.

Sprint 13 (`sprints/sprint-13/plan.md`) adds a second engine, **agent**, that lets a model choose among tools over a bounded number of rounds. The risk is exactly the one this product cannot afford: a model that chooses tools is a model that can choose to answer something the corpus does not support, quote a figure that is not in the salary table, or invent an entitlement. The decision below is how the agent gets its flexibility without giving up the guarantees the classic engine has by construction.

## Decision

### 1. The loop lives inside a deterministic shell — the model proposes, the rules dispose

The agent engine is a bounded loop (≤ 4 planner rounds, ≤ 6 tool calls, ≤ 2 clarifications per conversation, 45 s wall clock) in **hr-backend** (`App\Services\Agent\AgentChatService`); hr-ai gets one stateless endpoint for the planner (`/plan`) and one for the general lane (`/general-knowledge`). This keeps ADR-0007 (hr-backend decides and writes; hr-ai is stateless) and ADR-0015 (the key is passed per call).

Everything the planner can do is a **tool call it proposes**; everything that decides whether the call runs and whether the turn ends is a **rule** in a registry (`RuleEngine`), evaluated at fixed boundaries — `turn_start`, `pre_call`, `pre_call:<tool>`, `post_call:<tool>`. A rule returns one verdict: `allow`, `deny`, `rewrite`, `force_escalate`, `force_finish` or `force_ask`. The **first non-allow verdict at a boundary wins.** The planner's own words never end a turn with an answer: an answer exists only because a tool produced material and a post-call rule (or the finisher, which composes only that material) closed the turn. Concretely:

- The deterministic routes classic already has are **binding rules**, not suggestions: the pre-model guards, the salary route, the verified-fact route, the expired-convenio rule (`national_law` may not bypass it), the period-support guard, the Check-A/Check-B/figure/entailment gates.
- **Round 0** runs the deterministic routes before any planner call. When Round 0 produces a terminal outcome and the question is a single question, the turn is **settled without a planner call** — byte-comparable with classic by construction. Amended at CP-2 (trace review, staging session 69): a *salary* Round-0 outcome settles the turn on a follow-up as well. The salary route is decided by the question text alone (`RouterService::matchesSalary()`), classic answers it identically on every turn, and `SalaryIntentPreCallRule` already forbids every prose tool on a pay question, so a planner round could only re-choose the same `salary_lookup` (it did, at ≈ 1.8 s and ≈ $0.03, and reached the identical outcome). Compound questions and reference-fact follow-ups still go to the planner, because their meaning can depend on earlier turns. `Sprint13RoundZeroSettlesTest` pins this: every Round-0-settled turn records exactly one step (`round0`), makes zero planner calls, and equals classic; the two cases that must still reach the planner are pinned as well.
- Two rules were added at CP-2 because the eval showed the planner will otherwise reach for prose on a pay question: `SalaryIntentPreCallRule` (a pay-intent question may not go to `convenio_search`/`national_law`; it routes to `salary_lookup` or escalates) and `FigureNotFromTablePostCallRule` (a prose answer containing a euro amount that `salary_lookup` did not produce this turn is escalated as `figure_not_from_table`). A salary figure is still never read from prose (ADR-0006/0027).
- Every verdict a rule returns is recorded on the trace (`rule_verdict` steps: rule id and verdict). Gate metrics distinguish **corrections** (`rule_overrides`: a deny/rewrite, or a force from a rule that is not a `*_post_call` terminator) from **mandated terminations** (`forced_terminations`), so "how often did a rule have to fix the planner" is measurable and not confused with normal turn endings.

### 2. Escalation is additive: the agent can only escalate more than classic, never answer more

A rule may turn a would-be answer into an escalation; **no rule turns an escalation into an answer.** Where the agent departs from classic it departs toward an escalation (more `low_confidence`, a `planner_escalated`, a budget exhaustion, a `salary_coverage_gap` instead of a quoted figure). Consequences that are enforced, not hoped for:

- The four terminal path classes are the **same classes** classic uses (`SalaryPath`, `ReferenceFactPath`, prose, escalation) — extracted verbatim into non-persisting units that both engines call, under the expanded golden-trace suite (22 fixtures, one per path shape, byte-identical on classic before and after; still 22/22 at close).
- Exhausting any budget goes through `TurnPersister` like every escalation: fixed employee text, a card with deterministic facts from `trace.agent.steps`, no partial answer.
- If the planner is unavailable (provider error, no key, parse failure on round 1), the turn is run **classic wholesale** — nothing Round 0 did was persisted, so there is no double write.
- The hard-violation gate for CP-2 was therefore defined on *what an employee could have seen wrongly*: a figure not from the table, a forbidden ask, a lane answer that leaked a quantity/entitlement, an Estatuto figure to an employee whose convenio lacks the topic. Result over the whole step: **0 hard violations** (whitelist-temptation agent 48/48 after the CP-2 fixes; lane negatives 225/225; Estatuto positive/negative/second-negative 15/15 on both engines).

### 3. The general lane (F.8): safe by construction, inert in practice, OFF by default

`general_knowledge` is the one tool whose material is **not** from the corpus: a web-sourced explanation from a curated catalogue of official pages. It is gated so it cannot become a side door for the things the product must never improvise:

- **Both** `HR_GENERAL_LANE_ENABLED` (env, default **false**) **and** the Guardarraíles admin toggle must be true (restrict-only: `$baseline && ($admin ?? true)`); when off the tool is not in the planner's tool list and a call is denied.
- **Pre-conditions (F.8, amended at CP-1):** it opens only after a Check-A miss, or after a corpus answer that cleared Check A, Check B and the figure guard and failed **only** the per-claim entailment gate (`Rules\CorpusMiss`, a whitelist — anything not positively matched never opens the lane); never for salary, SMI, a verified-fact route, the aggregation shape or an Estatuto-fallback answer; and the question must pass a pre-screen (no `cuánto`, `tengo derecho`, `me corresponde`, …).
- **PII scrub in hr-backend** before anything leaves; web fetches carry no question text; the answer must be web-sourced.
- **A deterministic post-check discards the answer** on any of nine patterns (`GeneralLanePostCheck`: digits, spelled-out figures with a quantity noun, fractions, durations, money/percentages, second-person and generic entitlement language including `corresponde(n)`, bounds, English leakage) and force-escalates `general_lane_blocked`. It never trusts the prompt. The same `scan()` guards `ask_employee`'s proposed question text. An independent, deliberately broader **audit** (`GeneralLanePostCheck::audit()`, built from the same vocabulary as the post-check so the two cannot drift) is what the forced-lane harness uses to prove the post-check against real model output.
- **The F.8 relaxation stays OFF.** The lane opens only where the corpus *failed*; letting a synthesis abstention open it (the one shape that would actually answer questions like "¿qué es una excedencia?") is a separate decision, deferred until a positive set built from questions the corpus cannot answer exists (roadmap §7). Measured: **0/225 negative and 0/28 positive turns surfaced a lane answer end to end** — the lane is safe but inert, which is the correct state to merge it in.

### 4. Classic and agent are switchable at runtime, with classic the default

- `AnswerEngineDispatcher` chooses the engine per turn: env `HR_ANSWER_ENGINE` (default **`classic`**) with a runtime DB override (`answer_engine_settings`) set or cleared by `php artisan answer-engine:set {classic|agent|--clear} --admin=<super_admin email>` — no deploy needed, audited (who flipped it), refused for a non-super-admin. Flipping back is instant and needs no data migration: both engines write the same `chat_messages`/trace shape, with `trace.engine` and `trace.agent` present only on agent turns (the UI renders them only when present).
- **Merge default is classic.** The agent engine is enabled per environment by the override. Staging runs the override `agent` with the lane off; production stays classic until a pilot decision.
- The trace makes the difference visible to reviewers (Historial "how I got here" shows the planner, each round, each tool call, each rule verdict by rule id and what it did, and the budget), and `ask`-outcome turns show the review button.

## Consequences

- **Cost and latency.** The agent costs ≈ 10–30 % more per retrieving answer than classic (planner call plus denied rounds; list pricing: facts $0.078 vs $0.063) and is ≈ 1–2 s slower at the median on quiet runs (p50 10.3 s vs 9.1 s on the 10-question latency set; not slower at p95). Turns Round 0 settles cost the same as classic.
- **Shared prompt caveat.** `/synthesise`'s `SYSTEM_PROMPT` is shared by both engines, so a change to it moves classic too; the CP-1 prompt fix was therefore evaluated on both.
- **The shared weak point is not the agent's.** The colloquial-topic miss (canonical questions ≈ 90 % pass, colloquial ≈ 18 %, both engines) is in `ReferenceFactRouter::detectTopic`'s lexicon. It is ticketed as the next slice before the pilot (roadmap §7) and is a deliberate shared change, not agent work.
- **Deferred, on purpose.** Planner context does not yet include Round 0's seeded material on the turns that still go to the planner (compound and reference-fact follow-ups) — the planner re-derives it. No eval case exercises it; ticketed with the lane follow-up. `HR_GENERAL_LANE_ENABLED` staying false, the F.8 relaxation staying off, the post-check's subjunctive `corresponda` staying allowed ("el convenio que corresponda" is how a safe answer points the employee to their convenio) are recorded decisions, not omissions.
- **Reversibility.** Everything is additive: `answer-engine:set agent --clear` returns to classic; removing the lane env or the toggle disables the lane; the reason-enum migration and `answer_engine_settings` table are additive.

## Alternatives considered

- **Planner on every turn (uniform trace).** Rejected: Round 0 gives classic's byte-identical, cheaper answer on the deterministic routes, and it is where the salary/fact parity holds by construction (plan §F.11).
- **Let the model write the final answer directly from tool results.** Rejected: the answer text comes only from the existing path classes (salary table, verified fact, grounded synthesis) so every existing gate applies unchanged.
- **Open the lane on synthesis abstention now.** Rejected until measured on a positive set the corpus cannot answer (roadmap §7).
- **Make agent the default at merge.** Rejected: the pilot decision and the colloquial-lexicon slice come first.

## References

`sprints/sprint-13/plan.md` (§B tools, §C.9 loop bounds, §D.11 rules, §F decisions), `sprints/sprint-13/review.md` (CP-0 … CP-2 and the close-out), `ADR-0006`/`0027` (salary figures come from the table), `ADR-0007`, `ADR-0015`, `ADR-0033` (decomposition), `ADR-0034` (fact granularity).
