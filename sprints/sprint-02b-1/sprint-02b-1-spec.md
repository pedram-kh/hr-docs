# Sprint 2b-1 — The answer vertical (one path, end to end, safe)

> Location: `hr-docs/sprints/sprint-02b-1/spec.md`
> Reviewer: Claude (architecture) · eyes-on test: Pedram
> Read first: `architecture.md` §2 (the Laravel↔Python contract), §5 (the pipeline), §7 (guardrails), §11 (privacy); `data-model.md` §11 + the chat/trace tables; **ADR-0006/0007** (synthesis in `hr-ai`, scope in `hr-backend`), **ADR-0012** (design system), **ADR-0013** (chunks), and the new **ADR-0015** (external answer model); `design-system.md`; `deploy.md` §1; `sprint-02a/review.md` (the `/retrieve` primitive + scope resolver this consumes).
> **2b is two slices, then Sprint 3.** This is **slice 1: the narrow vertical.** Slice 2 (`sprint-02b-2`) adds the router, the full grounding check, salary-in-chat, citations breadth, and history search. Update `roadmap.md` to record the 2b-1/2b-2 split (as the 2a/2b split was recorded).

## Goal
Prove the **entire answer spine end to end on one real path**: an authenticated employee asks a prose-policy question in a real chat UI → `hr-backend` resolves scope deterministically → retrieves via 2a's `/retrieve` → **Claude** synthesises a **cited** answer from only the eligible chunks → the **answer-or-escalate floor** + the **hardcoded guardrail baseline** decide whether to surface it or escalate → the full turn + structured **trace** is stored in **auditable history**. Narrow on breadth (no router — single prose path), deep on the spine. After this slice, a real employee can get a real, scoped, cited, safe answer — or an honest "I'm not sure, I'm escalating this."

## Scope context (vs 2a)
2a built the retrieval **substrate** (chunks, salary rows, the `/retrieve` primitive, the scope resolver) with no chat, no answer model, no UI. 2b-1 adds the first **employee-facing surface**: the chat UI (built fresh on the design system — ADR-0012), answer synthesis via the external provider (ADR-0015), the answer-or-escalate floor, mandatory citations, the per-turn trace, chat-history storage, and the hardcoded guardrail baseline. It **consumes** 2a's `/retrieve` and scope resolver and adds an `hr-ai` synthesis step.

## In scope

### A. External answer model + key handling (ADR-0015)
- **Synthesis in `hr-ai`** as a pluggable provider, **default Claude**; non-secret provider/model/EU-endpoint settings are `hr-ai` config.
- **`hr-backend` admin "Answer model" screen:** set the key **once** → **encrypted at rest** → **shown masked** (`••••1234`) → **rotatable** (replace, never read back); UI shows *configured ✓ / not configured*. The key is decrypted by `hr-backend` and passed to `hr-ai` **per synthesis call** over the internal `X-Internal-Token` channel; `hr-ai` never persists it; the **browser never sees it** and never calls the provider.
- GDPR (EU endpoint, signed DPA, zero-retention) is **deploy-time** — reference `deploy.md` §1, do not re-describe or build.

### B. The chat UI (`hr-frontend`, on the design system)
- A **fresh employee chat screen** built entirely on the design-system tokens/classes (ADR-0012) — this is the from-scratch screen that proves the design language. Message list, question input, the answer with its **citations** and an **expandable "how I got here"** view; the escalation state uses the signature badges; copy follows the design-system voice (plain, active, honest about scope).
- No bespoke per-screen styling, no new colour literals — compose the existing classes; new visual needs add a token/class to the system first.

### C. The answer pipeline — single prose path, no router (`architecture.md` §2/§5)
1. Authenticated employee (email-OTP session, Sprint 0) sends a question to `hr-backend`.
2. `hr-backend` **resolves scope deterministically** (no LLM): the employee's convenio + `national_law` universality, `retrieval_status` (`active`; `historical` only for an explicitly time-scoped ask), question date (2a scope resolver / `data-model.md` §11).
3. `hr-backend` calls 2a's **`/retrieve`** (prose, scope-prefiltered, full-recall) → eligible chunks.
4. `hr-backend` calls a new **`hr-ai` synthesis step** with `{ question, eligible chunks, decrypted key, provider/model config }` → Claude composes a **cited** answer grounded **only** in those chunks, returning `{ answer, citations, grounding/confidence signal, trace fragment }`.
5. `hr-backend` applies the **guardrail baseline** (D) + the **answer-or-escalate floor** (E) and **decides**: surface the cited answer, **or** return the escalation message and create the escalation record.
- **No router** (2b-2): this is the prose path only. **No salary-in-chat** (2b-2): salary questions, lacking a router, simply fail the floor and escalate — which is safe. **No standalone grounding model** (2b-2): 2b-1 uses the floor.

### D. Hardcoded guardrail baseline (admins cannot weaken — `architecture.md` §7)
- No legal/medical advice; never answer about **other** employees' data; **immediate escalation** for sensitive topics (harassment, mental health, disciplinary/termination). Hardcoded in `hr-backend` (the config UI is Sprint 6); these fire **regardless of confidence**.
- Sensitive-topic questions **escalate *before* the synthesis call** — so a harassment/mental-health message is **never sent to the external provider** (a privacy bonus, not just a safety one).

### E. The answer-or-escalate floor (conservative, audit-first)
- Surface an answer **only when** retrieval returned eligible chunks **above** the `answer_confidence_floor` **AND** the synthesised answer is **grounded in and cites** those chunks. Otherwise **do not guess** — return *"I'm not sure about this, so I'm passing it to a person"* and **mark/queue the turn for escalation** (decide-and-queue; the board is Sprint 4).
- `answer_confidence_floor` is a **single named config value** (never inline), **conservative** default, dev-set in 2b-1, and **explicitly flagged** as *the value the Sprint-6 guardrails UI will expose, additive-only* (an admin may raise the floor / add caution, never weaken the baseline).

### F. Citations + trace + history (built in from turn one — pillar 4.1)
- **Citations:** every surfaced answer cites **source document + page** (from the chunk `page_from/to` + document metadata). **No uncited claims** — an answer that can't cite its sources doesn't pass the floor.
- **Trace:** the structured `message_traces.trace` written **per turn** — profile/scope resolved, retrieval filter + chunks + scores, the synthesis call, the guardrail result, the floor decision, the grounding/confidence signal. The trace is the pipeline's by-product (`architecture.md` §5), doubles as eval data, and mirrors the 2a harness output shape.
- **History:** every turn (question, answer, citations, trace, escalation flag) stored permanently in `conversations`/`messages` and retrievable. **Auditable from the first answer.** Store with a **role-scoping-ready** shape (an `hr_agent` will see escalated conversations, not everyone's history) — *enforcement* of role-scoped access is Sprint 5; the schema must not preclude it.
- On escalate, create the **escalation record** (`escalation_card` carrying conversation context + trace). This is **decide-and-queue only** — the board that *works* it is Sprint 4.

## Out of scope (do NOT build — 2b-2 or later)
- The **router** (salary-SQL vs prose vs off-domain) — 2b-2. (2b-1 is the prose path; salary/off-domain safely escalate via the floor.)
- The **full grounding/confidence model** — 2b-2 (2b-1 uses the floor).
- **Salary answers in chat** (the SQL path wired into chat) — 2b-2.
- The **escalation board** + resolve-to-article flywheel — Sprint 4 (2b decides-and-queues only).
- The **guardrails config UI** / admin-tunable threshold — Sprint 6 (2b-1 has the named config value, dev-set).
- **Chat-history search UI** and **role-scoped access enforcement** — Sprint 5 (2b-1 stores auditably with a role-scoping-ready shape).
- Lens hierarchy admin UI — Sprint 3. Analytics — Sprint 8.
- The directory UI — Sprint 5; **2b-1 uses seeded/test employee profiles** (roadmap notes test users suffice until Sprint 5).

## Acceptance criteria
1. An authenticated (seeded) employee asks a **prose-policy** question in the chat UI and receives a **scoped, cited** answer synthesised by Claude; citations resolve to source document + page.
2. Scope is resolved **deterministically in `hr-backend`** (no LLM), retrieval uses 2a `/retrieve` (scope-prefiltered, full-recall), and synthesis is grounded **only** in the eligible chunks.
3. The **floor** works: a question with no/weak eligible chunks (or a salary/off-domain question, no router) returns the escalation message and creates the escalation record — **does not guess**. Verified with a real out-of-scope question.
4. The **guardrail baseline** fires: a sensitive-topic question (e.g. harassment) escalates **immediately, regardless of confidence, without calling the provider**; no legal/medical advice; no other-employee-data answers.
5. The **API key** is set via the admin screen, stored **encrypted**, shown **masked**, **rotatable**, **never** returned to the browser; the provider is called **only** by `hr-ai`, with a key passed from `hr-backend`.
6. Every turn writes the **complete structured trace** and is stored in **auditable history** with a role-scoping-ready shape.
7. The chat UI is built on the **design-system** tokens/classes (ADR-0012) — no bespoke styling, no new colour literals.
8. `answer_confidence_floor` is a **single named config value** (not inline), conservative default, flagged as the Sprint-6-exposable, additive-only knob; the provider is **pluggable**, Claude default.
9. Nothing out-of-scope is built; `hr-ai` still writes only `document_chunks` + S3 and never migrates; `deploy.md` §1 is referenced; `roadmap.md` is updated for the 2b-1/2b-2 split.

## Eyes-on / stress-test gates (carry into build-authorization)
- A **real prose question** against the Gipuzkoa convenio (in Spanish, and one in Euskara) returns a grounded, language-appropriate **cited** answer; citations resolve to the right pages; the trace is complete.
- A deliberately **out-of-scope** question and a **sensitive-topic** question both **escalate** (and the sensitive one never reaches the provider).
- The turn appears in **history** with its trace; the **key screen** round-trips (set → masked → rotate) and the raw key never appears in any response or log.

## Definition of done
All criteria pass; eyes-on on the **running chat** (not screenshots); docs updated in the same change — `data-model.md` (population of `conversations`/`messages`/`message_traces`/`escalation_cards`; the role-scoping-ready note), `architecture.md` §5/§7 if the pipeline/guardrail shape needs recording, `deploy.md` §1 referenced, `roadmap.md` updated for the split; **ADR-0015** honoured. Cursor writes `sprint-02b-1/review.md` and **stops** — no commit.
