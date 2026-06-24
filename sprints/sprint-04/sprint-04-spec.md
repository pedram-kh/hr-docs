# Sprint 4 — Escalation board + the flywheel

> Location: `hr-docs/sprints/sprint-04/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `data-model.md` — `escalation_cards` (status enum `new|assigned|in_progress|resolved|closed`, `reason`, `assigned_to`, `topic_id`, the decide-and-queue note), `escalation_resolutions` (`resolution_text`, `converted_to_document_id`), `chat_sessions`/`chat_messages`/`message_traces`, the `documents` `authority_level` enum + the **authority rule** (`internal_hr_ruling` must not override an `official_convenio` for the same scope → re-escalate on conflict), and the Sprint 3 bounded-edit/append-only-provenance + scope-gate patterns; `roadmap.md` Sprint 4; ADR-0001/0007/0011; the Sprint 3 review (the Knowledge Center the published article lands in).
> **The flywheel sprint.** Most of the *data* plumbing exists — cards are already created on every escalation (since 2b-1); `escalation_resolutions.converted_to_document_id` is the flywheel link; `internal_hr_ruling` + its no-override rule exist. Sprint 4 builds the **board that works the cards**, the **agent reply into the employee's chat**, and the **resolve → Save-as-knowledge** flow.

## Goal
Close the learning loop: **unanswered → a human works it → the employee gets answered → the answer becomes scoped knowledge → the next asker is answered automatically.** Give HR agents a Kanban board to triage and resolve the escalations the engine already produces, reply to the employee who asked, and (optionally) turn the resolution into a scope-confirmed `internal_hr_ruling` knowledge article that lands in the Sprint-3 Knowledge Center.

## In scope

### A. The Kanban board (work the cards)
- A board of `escalation_cards` in columns by **status: New → Assigned → In Progress → Resolved → Closed**. Cards already exist (created by the answer loop on every escalate); the board **reads and moves** them — it does not create them.
- Each card shows: the originating **`reason`** (`sensitive_topic` / `low_confidence` / `off_domain` / `explicit_request` / `conflict` / `salary_coverage_gap`), the employee's scope (convenio/territory/sector), the **source question**, status, assignee, age.
- **Card actions:** assign (to self/another admin), move status, open the card detail.
- **Status writes** are `hr-backend`, audited (who moved what, when).
- Implicitly the **gap-analysis** surface — a cluster of `low_confidence` cards on one topic/scope *is* a visible coverage gap (no separate analytics build; that's Sprint 8).

### B. Card detail — the card-scoped conversation + trace (NOT a history browser)
- Opening a card shows **the full conversation attached to that card** (`chat_session` → messages) **and the trace** (`message_traces` — why it escalated: the floor decision, retrieval, grounding, authority). Read it to understand what the employee asked and what the engine did.
- **Strictly card-scoped:** an agent sees this conversation **because it escalated to a card they can work** — this is **not** an "any employee's history" browser. The open-ended full-history view + the role rules that govern who-sees-whose-chats are **Sprint 5**. Sprint 4 must not become a general privacy surface.

### C. Agent reply to the employee (the conversation becomes two-way)
- From the card, the agent can **reply into the employee's chat thread** — a human message that lands in that `chat_session`, marked as **from a person** (not the bot), so the original asker actually gets helped.
- The employee sees the human reply in their chat (clearly attributed as an HR-agent answer, distinct from bot answers); the thread can continue.
- The reply is a `chat_messages` write (a new author kind for "hr_agent"/human) in the employee's session, `hr-backend`-owned, audited. Resolving a card and replying are linked but distinct actions (an agent may reply without converting to knowledge, or convert without replying).

### D. The flywheel — resolve → Save as knowledge
- On the card, alongside **Send** (C), a **"Save as knowledge"** action: the agent's resolution becomes a new `documents` row, **`authority_level = internal_hr_ruling`**, with an `escalation_resolutions` row linking card → `converted_to_document_id`. (`resolution_text` is stored either way; conversion is optional.)
- **Inherits the asker's scope by default** (convenio/territory/sector from the originating employee), and **scope must be confirmed before it can answer** — the same fence as the Sprint-3 bounded edit (a clear confirmation that this becomes an answer for *everyone in that scope*). **No separate human approver** (per decision) — the agent owns it — **but the scope-confirm step is non-negotiable.**
- **No silent override (the load-bearing legal fence):** an `internal_hr_ruling` must **never** override an `official_convenio` for the same scope. On publish, check for a conflicting official-convenio answer in the same scope/topic; on conflict, **re-escalate / block publish with a clear message**, never silently let the ruling win. (This is the `authority_level` rule already in the data model — Sprint 4 *enforces* it at publish time.)
- Once published (scope-confirmed, no conflict), the ruling is **`active`** and becomes a leaf in the **Knowledge Center** (Sprint 3), badged `internal_hr_ruling`, its provenance/timeline showing **"created from escalation #N, by [agent], on [date]"** with a link back to the card. The **next** employee asking that question in that scope is answered from it automatically (it enters the same retrieval the answer engine already runs — confirm the ruling is chunked/embedded like any prose doc, or define how it becomes retrievable).
- On resolve/convert, the card moves to **Resolved** (and `resolved_at` set); `closed` is the terminal "done, no further action."

## Out of scope (do NOT build)
- **Role-scoped conversation access + the full any-employee chat-history browser → Sprint 5.** Sprint 4 uses the **Sprint-0 seeded roles / test users** to assign cards; it does not build the directory, role management, or the "see everyone's history" view. Card-scoped conversation viewing only.
- **Analytics / coverage dashboards → Sprint 8** (the board *implicitly* shows gaps; no metrics build here).
- **Guardrails config UI → Sprint 6.** **Structured Reference Knowledge / AI tagging → Sprint 7.**
- No change to the **answer loop** (2b is frozen/durably-closed) beyond the additive path that makes a published `internal_hr_ruling` retrievable (which reuses existing ingestion — define it, don't re-architect retrieval).

## Acceptance criteria
1. The board renders all `escalation_cards` by status, with reason/scope/question/assignee/age; assign + move-status + open work, all `hr-backend`-audited.
2. A card opens its **card-scoped** conversation + trace; it is **not** possible to browse an unrelated employee's history from here.
3. An agent can **reply into the employee's chat**; the reply lands in that session as a clearly-attributed **human** message (distinct from the bot), visible to the employee.
4. **Save as knowledge** creates an `internal_hr_ruling` document + `escalation_resolutions` link, **inheriting the asker's scope**, **requiring scope confirmation** before it can answer, and **blocking/​re-escalating on official-convenio conflict** (no silent override). The published ruling appears in the Knowledge Center, badged, traceable to its card, and becomes retrievable for the next asker in scope.
5. Resolve/convert moves the card to Resolved (`resolved_at` set); status transitions are sane.
6. All writes `hr-backend`; any new schema (e.g. the human-author message kind, a card-activity/audit field) is an **additive migration** — state it in the plan.
7. Nothing out-of-scope: no role-scoped-access enforcement, no full-history browser, no analytics, no answer-loop change beyond making a ruling retrievable.

## Eyes-on
Seed/find an escalated card (e.g. a `low_confidence` vacaciones escalation). On the board: assign it, move it, open it → see the conversation + the trace (why it escalated). **Reply to the employee** → confirm the reply shows in the employee's chat as a human answer. **Save as knowledge** → the scope-confirm step appears, inherits the asker's scope, and (try the conflict case) a ruling that would contradict an official convenio for the same scope is **blocked/re-escalated**. Confirm the published ruling appears in the Knowledge Center (badged `internal_hr_ruling`, linked to the card) and that a **new** employee asking that question in that scope now gets answered from it.

## Risks / notes
- **The agent-reply surface is the heaviest part** — it makes the employee chat two-way (human ↔ employee in the same thread). The plan must define the message-author model, how the employee is shown a human reply vs a bot answer, and whether the employee can reply back (and if so, does that re-open/append to the card). Keep it minimal; don't build a full ticketing/notification system.
- **Making a ruling retrievable:** an `internal_hr_ruling` is prose — confirm it flows through the existing chunk/embed path (so the answer engine finds it) and that its `authority_level` makes it lose to a convenio on conflict at *retrieval/precedence* time, consistent with the no-override rule. Don't re-architect retrieval; reuse 2b's precedence.
- **Access is test-user-based this sprint** (Sprint 0 roles); the card-scoped view is the privacy guard until Sprint 5 enforces role-scoped access. State this boundary clearly so Sprint 4 isn't mistaken for the access-control sprint.

## Definition of done
All criteria pass; Pedram eyes-on; docs updated — `architecture.md` (the board, the agent-reply two-way surface, the Save-as-knowledge flow + the publish-time no-silent-override enforcement + scope-confirm), `data-model.md` (any additive field: the human message-author kind, card activity/audit, the resolution→document link in use), `roadmap.md` (Sprint 4 done; the full-history browser + role-scoped access reaffirmed for Sprint 5), the relevant READMEs. Cursor writes `hr-docs/sprints/sprint-04/review.md` and **stops — no commit until I review**.
