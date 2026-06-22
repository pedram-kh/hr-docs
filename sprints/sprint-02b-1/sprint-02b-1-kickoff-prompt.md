# Sprint 2b-1 — Cursor kickoff prompt (plan-gate)

> Paste the block below into a **fresh** Cursor thread with the `hr-platform/` workspace open. As with every sprint, it must **plan first and stop** — do not let it build until the plan is reviewed.

---

You are in the `hr-platform` workspace (`hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs`). Sprints 0, 1, and 2a are built (2a pending final commit). **Sprint 2b is split into two slices; this is slice 1 (`sprint-02b-1`) — the narrow answer vertical.** Slice 2 (`sprint-02b-2`) adds the router, full grounding check, salary-in-chat, and history search; after the two slices comes Sprint 3.

Before doing anything, read in full:
- `hr-docs/architecture/architecture.md` (esp. §2 the Laravel↔Python contract, §5 the pipeline, §7 guardrails, §11 privacy)
- `hr-docs/architecture/data-model.md` (esp. §11 scope resolution + the `conversations`/`messages`/`message_traces`/`escalation_cards` tables, and the `document_chunks` retrieval shape from 2a)
- every file in `hr-docs/architecture/decisions/` — esp. **ADR-0006/0007** (embedding self-hosted; **answer synthesis lives in `hr-ai`**; `hr-backend` owns scope + all DB writes + the answer-or-escalate decision), **ADR-0012** (design system), **ADR-0013** (chunks), and the new **ADR-0015** (external answer model + key handling)
- `hr-docs/design-system.md` and `hr-docs/deploy.md` (§1 — the GDPR deploy items)
- `hr-docs/glossary.md`
- `hr-docs/sprints/sprint-02a/review.md` (the `/retrieve` primitive + scope resolver + harness this slice consumes)
- `hr-docs/sprints/sprint-02b-1/spec.md`
- the `AGENTS.md` in each code repo

Your task this turn is to **plan Sprint 2b-1 only — do not write any code, migrations, or config yet.**

Produce a single file `hr-docs/sprints/sprint-02b-1/plan.md`, then **STOP and wait for review.** The plan must cover:

1. **Build order** across repos + dependencies — `hr-ai` gains a synthesis step (consumes 2a `/retrieve`); `hr-backend` gains the pipeline orchestration + provider-key handling + the answer-or-escalate decision + history/trace writes; `hr-frontend` gains the chat UI + the key screen. What 2b-2 will build on.
2. **External answer model + key handling** (ADR-0015): the **pluggable provider in `hr-ai`** (Claude default; EU-endpoint/model as non-secret `hr-ai` config); the **admin "Answer model" screen** (`hr-frontend` → `hr-backend`): set-once, **encrypted at rest**, **masked** (`••••1234`), **rotatable**, never read back; exactly **how `hr-backend` passes the decrypted key to `hr-ai` per synthesis call** over the internal `X-Internal-Token` channel without persisting it in `hr-ai` or ever exposing it to the browser.
3. **The answer pipeline, single prose path, no router** (`architecture.md` §2/§5): auth question → deterministic scope resolve (`hr-backend`) → 2a `/retrieve` → `hr-ai` synthesis (Claude, cited, grounded only in the eligible chunks, returns answer + citations + grounding/confidence signal + trace fragment) → `hr-backend` applies guardrail baseline + floor and **decides** answer-or-escalate → persist. State where each step runs and why (synthesis in `hr-ai`; the decision in `hr-backend`).
4. **The answer-or-escalate floor:** the **`answer_confidence_floor`** named config value (conservative default, never inline, flagged as the Sprint-6-exposable additive-only knob); how 2b-1 judges "grounded" **without** the full grounding model (e.g. citation coverage + retrieval-score threshold), and how a no/weak-retrieval or salary/off-domain question lands in escalation rather than a guess.
5. **The hardcoded guardrail baseline** (`architecture.md` §7): the sensitive-topic rules (harassment, mental health, disciplinary/termination), no legal/medical advice, no other-employee-data; enforced deterministically, firing **regardless of confidence**; sensitive-topic escalates **before** the synthesis call (never sent to the provider).
6. **Citations:** how a retrieved chunk maps to source document + page for display; the rule that an answer with no citations does not pass the floor.
7. **Trace + history:** the `message_traces.trace` contents per turn; population of `conversations`/`messages`; the **role-scoping-ready** shape (enforcement is Sprint 5); creating the `escalation_card` on escalate (decide-and-queue; the board is Sprint 4); auditable from turn one.
8. **The chat UI** (`hr-frontend`): the screens/components built **on the design-system tokens/classes** (ADR-0012) — message list, input, answer + citations + expandable "how I got here" trace view, escalation state via the signature badges, design-system voice. Confirm no bespoke styling / no new colour literals.
9. **Assumptions & questions:** how grounding is approximated in 2b-1 without the full check; streaming vs non-streaming answers; which **seeded test employee** profiles you'll use (directory UI is Sprint 5); how the `escalation_card` is created without a board; anything the spec or the real 2a substrate leaves ambiguous.

Hard constraints (from the docs):
- **Answer synthesis lives in `hr-ai`** (ADR-0007); `hr-backend` resolves scope (no LLM), owns ALL DB writes (incl. `messages`/`message_traces`/`escalation_cards`) and the **answer-or-escalate decision**. `hr-ai` still writes **only** `document_chunks` + S3, never other tables, never migrates.
- **The browser never sees the API key and never calls the provider.** The key is set in the admin screen, **encrypted at rest**, **masked**, **rotatable, never read back**; only `hr-ai` calls the provider, only with a key passed from `hr-backend` per call.
- **Conservative, audit-first:** answer only when above the `answer_confidence_floor` AND grounded with citations; otherwise escalate, never guess. The guardrail baseline is hardcoded, **admins can't weaken it**, and fires regardless of confidence.
- **Chat UI on the design-system tokens** (ADR-0012) — no bespoke styling, no new colour literals.
- GDPR is **deploy-time** (`deploy.md` §1) — referenced, not built.
- Build **nothing** out-of-scope (spec "Out of scope"): no router, no salary-in-chat, no full grounding model, no escalation board, no guardrails config UI, no history-search UI / role-scope enforcement, no lens UI, no analytics.

Do not create or modify any file other than `hr-docs/sprints/sprint-02b-1/plan.md` this turn. After writing it, stop and say it is ready for review.
