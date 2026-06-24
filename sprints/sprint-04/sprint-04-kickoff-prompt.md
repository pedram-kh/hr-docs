# Sprint 4 — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate, plan, and **stop** — no board/API code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–3 are built and committed (the answer engine is durably closed; the Knowledge Center shipped in Sprint 3). **This is Sprint 4 — the escalation board + the flywheel** — the learning loop (pillar §4.3).

Before anything, read in full:
- `hr-docs/architecture/data-model.md` — `escalation_cards` (status enum, `reason`, `assigned_to`, `topic_id`, the **decide-and-queue** note: cards are already created on every escalate since 2b-1), `escalation_resolutions` (`resolution_text`, `converted_to_document_id` — the flywheel link), `chat_sessions`/`chat_messages` (the author model) / `message_traces` (the escalation trace), the `documents` `authority_level` enum + the **authority rule** (`internal_hr_ruling` must NOT override an `official_convenio` for the same scope → re-escalate on conflict), and the Sprint-3 bounded-edit / append-only-provenance / scope-confirm patterns
- **ADR-0001 / 0007 / 0011**, `roadmap.md` Sprint 4 (and Sprint 5, to honour the boundary)
- the **Sprint 3 review** (the Knowledge Center a published `internal_hr_ruling` lands in; the lens hierarchy + document card it shows up in) and how 2b makes a prose doc retrievable (chunk/embed + precedence)
- `hr-docs/sprints/sprint-04/spec.md`
- the existing `hr-frontend` (the employee chat UI, the Sprint-3 admin shell + Knowledge Map) and the design system (`design-system.md`, ADR-0012/0013)

Your task this turn: **inspect the real substrate and plan — write no board/API/UI code.**

Produce `hr-docs/sprints/sprint-04/plan.md`, then **STOP and wait for review.** Cover:

1. **What already exists (reality check).** Confirm against the real schema/code: cards are created on escalate (where, with what fields); the `escalation_resolutions` table + `converted_to_document_id`; the `chat_messages` **author model** (is there already an author/role column, or is "from a person" a new author kind requiring an additive migration?); how a prose `documents` row becomes retrievable today (the chunk/embed path) and how `authority_level` precedence is applied at retrieval (2b). Show real columns. This determines how much is new vs. wiring.
2. **The board (read + move).** Endpoints to list cards by status (filters: status/reason/scope/assignee), the card payload, assign + status-transition writes (audited), and the column UI. Reuse the Sprint-3 admin shell.
3. **Card detail — card-scoped conversation + trace.** Serving the attached `chat_session` messages + `message_traces` for *that* card only — explicitly **not** a general history browser (that + role-scoped access is Sprint 5). State how access is bounded this sprint (test-user/Sprint-0 roles; card-scoped view as the guard).
4. **Agent reply into the employee's chat (the two-way surface — the heaviest part).** The message-author model (how a human HR-agent message is stored + distinguished from a bot answer in the same `chat_session`); how the employee sees a human reply vs a bot answer in the chat UI; whether the employee can reply back and what that does to the card. Define the **minimal** shape — no ticketing/notification system. Flag any additive migration (author kind/column).
5. **The flywheel — resolve → Save as knowledge.** Alongside Send: create an `internal_hr_ruling` `documents` row + `escalation_resolutions` link, inheriting the asker's scope; the **scope-confirm** gate (reuse the Sprint-3 confirm pattern); **publish-time enforcement of the no-silent-override rule** (detect a conflicting `official_convenio` for the same scope/topic → block/re-escalate, never silently win); and **how the published ruling becomes retrievable** (reuse the existing chunk/embed + precedence — don't re-architect). Resolve/convert → card to Resolved (`resolved_at`).
6. **Knowledge Center integration.** How the published ruling shows as a Sprint-3 leaf/card: the `internal_hr_ruling` badge, the provenance "created from escalation #N by [agent]" + link back to the card. Reuse Sprint-3 components.
7. **Roles/access this sprint.** Use the Sprint-0 seeded roles / test users to assign + work cards; do **not** build role-scoped conversation access or the directory (Sprint 5). State the boundary.
8. **Build order, additive migrations, assumptions & open questions** across `hr-backend` (board/card/reply/resolve/publish APIs + audited writes), `hr-frontend` (board, card detail, reply UI, save-as-knowledge flow, Knowledge-Center badge), and any **additive** migration (human author kind, card activity/audit, etc.). Flag anything the real schema leaves ambiguous (esp. the chat author model and the conflict-detection-at-publish mechanism).

Hard constraints:
- **No answer-loop change** beyond making a published `internal_hr_ruling` retrievable via the **existing** ingestion/precedence path (2b is frozen/durably-closed). `hr-ai` stays as-is (it embeds the new ruling like any prose doc); it does not migrate.
- **`hr-backend` owns all writes + schema; additive migrations only.** Every status move / reply / resolution / publish is **audited**; the no-override rule is **enforced at publish**, not advisory.
- **Card-scoped conversation viewing only** — no full-history browser, no role-scoped-access enforcement (Sprint 5). The published ruling **requires scope confirmation** and **cannot silently override a convenio**.
- Reuse the **design system** + the Sprint-3 admin shell / Knowledge-Center components; the employee-facing human-reply must be clearly attributed (not mistaken for a bot answer).

Do not create or modify any file other than `hr-docs/sprints/sprint-04/plan.md` this turn. After writing it, stop and say it is ready for review.
