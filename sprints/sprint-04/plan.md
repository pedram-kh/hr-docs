# Sprint 4 — Escalation board + the flywheel · Plan

> Location: `hr-docs/sprints/sprint-04/plan.md`
> Status: **plan for review — no board/API/UI code written this turn.** This is the plan-gate output.
> Read first (and confirmed against the real substrate): `data-model.md` §8–§10, `roadmap.md` Sprint 4/5, ADR-0001/0007/0011, `sprint-03/review.md`, `design-system.md`, and the live code cited inline below.

This sprint closes the learning loop: **unanswered → a human works it → the employee gets answered → the answer becomes scoped knowledge → the next asker is answered automatically.** The data plumbing already exists (cards are created on every escalate since 2b-1; `escalation_resolutions.converted_to_document_id` is the flywheel link). Sprint 4 builds the **board that works the cards**, the **agent reply into the employee's chat**, and the **resolve → Save-as-knowledge** flow.

---

## 1. What already exists (reality check — verified against schema + code)

### 1.1 Cards are created on escalate — confirmed (where, with what fields)

`ChatService::persistTurn()` creates the card inside the single turn transaction, **only on the `escalate` outcome** (`hr-backend/app/Services/ChatService.php:1028`):

```1029:1037:hr-backend/app/Services/ChatService.php
            if ($escalate) {
                $card = EscalationCard::create([
                    'chat_session_id' => $session->id,
                    'source_message_id' => $userMessage->id,
                    'employee_id' => $employee->id,
                    'reason' => $escalationReason ?? 'low_confidence',
                    'status' => 'new',
                ]);
            }
```

So a real card carries: `chat_session_id`, `source_message_id` (the **`user`** message, not the assistant turn), `employee_id`, `reason`, `status = 'new'`. The model fills `uuid` on `creating` (`EscalationCard.php:23`). **Not set at creation:** `assigned_to` (null), `topic_id` (null — never populated yet), `resolved_at` (null). `reason` is one of the CHECK-constraint values `low_confidence | sensitive_topic | off_domain | explicit_request | conflict | salary_not_in_chat (retired) | salary_coverage_gap` (migrations `…210000` + `…100001`). This matches the data-model "decide-and-queue" note (§9). **Nothing creates a card outside this path** — the board only reads and moves cards (confirmed: no other `EscalationCard::create` in the codebase).

### 1.2 `escalation_resolutions` + `converted_to_document_id` — exists, no writer yet

Migration `…131022_create_escalation_resolutions_table.php`: `card_id` (FK, cascade), `resolved_by` (FK → admins, **required**), `resolution_text` (required), `converted_to_document_id` (nullable FK → documents). **There is no `EscalationResolution` model and no code writes this table yet** — Sprint 4 is its first writer (the same posture as Sprint 3 being the first writer of `document_topics`). We will add the `EscalationResolution` model.

### 1.3 The `chat_messages` author model — **"from a person" is a NEW author kind → additive migration**

`chat_messages` has **no** author/role column for a human. The `role` is a two-value CHECK enum, written only as `user` / `assistant` (`…131018_create_chat_messages_table.php:14`):

```11:17:hr-backend/database/migrations/2026_06_20_131018_create_chat_messages_table.php
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->timestamps();
        });
```

There is also **no column for which admin authored a message**. So an HR-agent reply needs an **additive migration** (two parts, see §8):
1. Add `hr_agent` to the `role` CHECK constraint — using the exact introspect-drop-readd idiom already proven for the escalation `reason` enum (`…100001_add_salary_coverage_gap…php`). Additive + idempotent + reversible.
2. Add a nullable `author_admin_id` FK → admins on `chat_messages` (NULL for `user`/`assistant`; set for `hr_agent`). This is how a human reply is attributed and distinguished from a bot answer in the same session.

This is the heaviest substrate change and the spec's flagged risk.

### 1.4 How a prose document becomes retrievable today (the chunk/embed path)

`chunks:embed` (`hr-backend/app/Console/Commands/ChunksEmbed.php`) is the ingestion path:
- **Selection:** `document_type ∈ {convenio_text, national_law, partial_agreement}` **AND** `retrieval_status ∈ {active, historical}` **AND** `tagging_status ≠ under_review` (`ChunksEmbed.php:30,34-38`).
- hr-backend **resolves scope** (`resolveScope()` — convenio/territory/sector ride the convenio, plus validity/retrieval_status/authority_level) and **passes it** to hr-ai `/embed`.
- hr-ai `/embed` (`hr-ai/app/main.py:193`) re-extracts the **PDF** column-aware (PyMuPDF), de-spaces, article-chunks, embeds BGE-M3/1024, and writes `document_chunks` with the denormalized scope columns copied verbatim. Idempotent re-embed.

**Two real gaps for an `internal_hr_ruling` (both must be resolved to make a ruling retrievable):**
- **(a) Selection excludes it.** `internal_hr_ruling` is **not** in `IN_SCOPE_TYPES`, so `chunks:embed` will never pick it up. Fix = add `internal_hr_ruling` to that list (an hr-backend **code change, not a migration**). The document-type code `internal_hr_ruling` already exists in `DocumentTypeSeeder`/`document_types` (data-model §4).
- **(b) There is no PDF to re-extract.** The existing `/embed` re-extracts a PDF from `storage_path`. An agent's ruling is **typed prose** (`resolution_text`), not a PDF — so "embed it like any prose doc" has no source artifact. **This is the central open question** (§8 Q-A): how the typed text becomes an extractable source without re-architecting retrieval or migrating hr-ai.

### 1.5 How `authority_level` precedence is applied at retrieval (2b) — and the no-override residual

At retrieval, `authority_level` is denormalized onto each chunk and used **only** to order convenio-over-baseline:
- `ChatService::orderByAuthority()` treats **only `national_law`** as the baseline; **everything else (incl. `internal_hr_ruling`) is "governing"** and ordered first (`ChatService.php:725`).
- `precedenceRerank()` promotes a *governing* chunk above a same-topic `national_law` chunk; `internal_hr_ruling` is treated **identically to `official_convenio`** here (`ChatService.php:599` — the only special-cased level is `national_law`).
- The synthesis prompt encodes convenio-over-national-law; it does **not** encode ruling-loses-to-convenio.

**Consequence (load-bearing for §5):** at retrieval/precedence time an `internal_hr_ruling` does **not** automatically lose to an `official_convenio`. The data-model authority rule ("`internal_hr_ruling` must not override an `official_convenio` for the same scope → re-escalate on conflict") is therefore **enforced at publish time, not at retrieval** (exactly what the spec asks). We do **not** touch the frozen 2b retrieval/precedence. The publish-time conflict gate (§5) is what guarantees a published ruling can never coexist with a conflicting convenio answer in the same scope. The residual (a convenio doc added/edited *later* that conflicts with an already-published ruling) is a known boundary — see §8 Q-F.

### 1.6 Net: new vs. wiring

| Capability | State today | Sprint 4 work |
|---|---|---|
| Cards exist, statuses, reason/scope | ✅ created on escalate | **wiring** — read + move + audit |
| `escalation_resolutions` table | ✅ schema only, no writer/model | **new writer** (+ model) |
| Card-scoped conversation + trace | ✅ data (`chat_messages`, `message_traces`) | **wiring** — card-scoped read endpoint + reuse `TracePanel` |
| Human reply into employee chat | ❌ no author kind, no attribution col | **new** — additive migration + write path + UI + employee session-load |
| Ruling → retrievable | ⚠️ embed path exists but excludes the type + needs a source artifact | **wiring + small decision** (Q-A) |
| Publish-time no-override enforcement | ❌ not enforced (only retrieval ordering) | **new** — publish conflict gate |
| Knowledge Center leaf/badge/provenance | ✅ Hierarchy + DocumentDetailPanel | **wiring** — badge + provenance + back-link |

---

## 2. The board (read + move)

### 2.1 Backend — a new `EscalationController` (admin-gated, audited)

New routes under the existing `admin` group (`routes/api.php`), gated by a new ability (`escalation.work`, §7):

- `GET /admin/escalations` — list, filterable by `status`, `reason`, `convenio_id`, `territory_id`, `assigned_to` (and an `unassigned=true` shortcut). Returns the **column-grouped** payload (counts per status) or a flat list the frontend buckets — flat list + status is simpler; the board buckets client-side.
- `GET /admin/escalations/{uuid}` — the card detail (§3).
- `PATCH /admin/escalations/{uuid}` — move status and/or assign. Validates legal transitions (§2.3). **Audited** (§2.4).

**Card list payload** (each card): `uuid`, `reason`, `status`, `created_at` (→ age, computed client-side), `assigned_to` (admin uuid + name or null), the **source question** (`source_message.content`), the **employee scope** derived for display — `convenio` (numero+name), `territory` (name+level), `sector` (name) via `employee → convenio → territory/sector` (the same scope-rides-on-convenio derivation used everywhere), and the employee's display name. `topic_id` is null today, so a topic chip is shown only if/when set.

Eager-load to avoid N+1: `card.employee.convenio.territory`, `card.employee.convenio.sector`, `card.sourceMessage`, `card.assignedTo`. We add the missing relations to `EscalationCard` (`employee`, `session`, `sourceMessage`, `assignedTo`, `resolution`) — model-only, no schema.

### 2.2 Frontend — reuse the Sprint-3 admin shell

- Add a **Board** (Escalations) view to `AdminShell.tsx` nav (`View = 'documents' | 'map' | 'settings' | 'board'`), gated on the board ability (mirror how edit affordances gate on `canEditKnowledge`).
- A **Kanban** of five columns (New → Assigned → In Progress → Resolved → Closed). Each card = a `.card` with the `reason` as a badge (reuse `.badge-*`; `low_confidence`/`off_domain` → `--warning`, `sensitive_topic`/`conflict` → `--danger`, `explicit_request`/`salary_coverage_gap` → `--neutral`/`--info`), the source question (truncated), scope facets (reuse `.facet` chips), assignee, age. Filters bar (status/reason/scope/assignee) above the board.
- Card actions inline: **Assign to me / reassign**, **move status** (a select or drag — start with a status select for simplicity/a11y; drag is optional polish), **open** (→ card detail drawer, reusing the `.panel` drawer pattern from `DocumentDetailPanel`).
- The board is **implicitly the gap surface** (a cluster of `low_confidence` on one scope/topic is a visible coverage gap) — no separate analytics build (Sprint 8).

### 2.3 Status transitions (sane, server-enforced)

Allowed moves: `new → assigned/in_progress/closed`; `assigned → in_progress/new/closed`; `in_progress → resolved/assigned/closed`; `resolved → closed/in_progress` (reopen); `closed → in_progress` (reopen). Assigning a `new` card auto-advances to `assigned` (unless already further). `resolved` is set by the resolve/convert flow (§5), which also stamps `resolved_at`. `closed` = terminal "done, no further action." Illegal transitions → 422. (Exact set is small and reviewable; finalize at build.)

### 2.4 Every move is audited

Status moves, assigns, replies (§4), and resolutions (§5) are written to an append-only **`escalation_events`** table (additive migration, §8) — mirroring the `tag_events` provenance spine: `escalation_card_id`, `type` (`status_change | assigned | replied | resolved | converted | publish_blocked`), `old_value`/`new_value`, `actor_id` (admin), `note`, `created_at`. (Alternative considered: reuse `tag_events` with `entity_type='escalation_card'` — rejected because `tag_events.facet` is a document-facet vocabulary; a dedicated table reads cleanly as the card's activity timeline. Flagged Q-E for review.)

---

## 3. Card detail — card-scoped conversation + trace (NOT a history browser)

`GET /admin/escalations/{uuid}` returns:
- The card (reason, status, assignee, scope, timestamps, resolution if any).
- **The conversation for that card's session only** — `ChatMessage::where('session_id', $card->chat_session_id)->orderBy('id')` with each assistant message's `message_citations` and **`message_traces`**. **The query is keyed strictly to `card.chat_session_id`** — there is no `employee_id`/session parameter the caller can pass to browse another conversation. This card-scoped query **is** the access guard this sprint.
- The card's source message is highlighted (the question that escalated).

**Frontend:** open the card → a drawer (reuse the `.panel`/`DocumentDetailPanel` drawer pattern) showing the conversation as chat bubbles (reuse the `chat-bubble` classes) and, on the assistant turn, the **existing `TracePanel`** (`hr-frontend/src/pages/chat/TracePanel.tsx`) rendering the `message_traces.trace` (floor decision, retrieval, grounding, authority_used) so the agent sees *why* it escalated. No new trace rendering is built — `TracePanel` is reused as-is.

**Boundary (stated explicitly):** this is **not** an "any employee's history" browser. The agent sees this conversation *because it escalated to a card they can work*. The open-ended full-history view and the role rules for who-sees-whose-chats are **Sprint 5** (`roadmap.md` Sprint 5; `data-model.md` §8 "role-scoping-ready shape, no enforcement is built in 2b-1"). Sprint 4 must not become a general privacy surface.

---

## 4. Agent reply into the employee's chat (the two-way surface — the heaviest part)

### 4.1 The message-author model (additive)

A human HR-agent reply is a **`chat_messages` row in the employee's existing session** with `role = 'hr_agent'` and `author_admin_id` set (the new column). This distinguishes it structurally from a bot `assistant` answer (which has `author_admin_id = null` and carries a `message_traces`/`message_citations` row). An `hr_agent` message has **no trace and no citations** — it is a human turn, not a synthesized answer. (See §8 for the migration.)

- `POST /admin/escalations/{uuid}/reply` `{ content }` → in one transaction: create the `hr_agent` `ChatMessage` in `card.chat_session_id`, bump `chat_sessions.last_activity_at`, append an `escalation_events` `replied` row (audit). Returns the created message. The reply does **not** itself change card status (reply and resolve are distinct actions — spec C). Optionally the agent moves the card to `in_progress` as a separate action.
- `ChatController::message` rejects admins, so admins never post as `user`/bot — the reply is a separate admin-gated endpoint. Good separation.

### 4.2 How the employee sees a human reply (and the required employee session-load)

**Problem found:** the employee chat is **in-memory only** — `ChatScreen.tsx` holds `items` in React state and there is **no GET endpoint to load a session's messages** (confirmed: `api.ts` has only `sendChatMessage`; no history fetch). So today an employee would never see a reply that lands server-side.

**Minimal fix (no ticketing/notifications):**
- Add `GET /chat/session` (employee-auth) returning the employee's most-recent session within the window (the same `resolveSession` logic) with its ordered messages, including `hr_agent` messages (author display name/role, but **not** the admin's email/PII beyond "Recursos Humanos"). 
- `EmployeeShell`/`ChatScreen` **hydrates from this on mount** and **re-fetches** on a light interval (e.g. poll every ~20–30s while the tab is open) and/or on window focus. No websockets, no push — polling is the minimal honest mechanism for this sprint.
- A `hr_agent` message renders as a **clearly attributed** bubble: a distinct style + a badge like **"Respuesta de Recursos Humanos (persona)"**, visually separate from the bot `AnswerBlock` and from the escalation notice. It must never be mistaken for a bot answer (spec hard constraint). Add a `chat-bubble--agent` variant + a badge using existing tokens (e.g. `--info`/`--accent`), no new primitive beyond a class.

### 4.3 Can the employee reply back, and what it does to the card

- **Yes** — the employee keeps typing in the same session via the existing `POST /chat/message`. That runs the normal answer loop (it may answer, or escalate again creating a *new* card — that is acceptable and minimal).
- **Effect on the card:** because the card is bound to `chat_session_id`, the **card detail (§3) naturally shows the continued conversation** (all later messages in that session), so the agent watching the board sees follow-ups. We do **not** build auto-reopen, threading, or a notification system this sprint. (Flagged Q-G: whether a follow-up should re-link to the *same* card vs. spawn a new one — minimal default: normal loop, new card only if it independently escalates.)

This is the minimal two-way shape: one new author kind + attribution column, one reply endpoint, one employee session-load endpoint, polling refresh. No ticketing, no notifications.

---

## 5. The flywheel — resolve → Save as knowledge

Two distinct actions on a card (both audited), sharing one endpoint family:

### 5.1 Resolve (always) — write the resolution + move to Resolved

`POST /admin/escalations/{uuid}/resolve` `{ resolution_text, convert: bool, confirm_scope_change?: bool }`:
- Always writes an `escalation_resolutions` row (`card_id`, `resolved_by` = acting admin, `resolution_text`, `converted_to_document_id` = null unless converting). (First writer of this table; add the `EscalationResolution` model.)
- Moves the card to **`resolved`** and sets `resolved_at` (data-model §9). Appends an `escalation_events` `resolved` row.
- If `convert = false`, done (resolution stored, no document).

### 5.2 Save as knowledge (convert) — create the `internal_hr_ruling` document

When `convert = true`, in addition:
- Create a `documents` row: `authority_level = 'internal_hr_ruling'`, `document_type = internal_hr_ruling`, **`convenio_id` = the asker's convenio** (inherit scope — territory/sector ride the convenio, exactly the data-model rule; `escalation_resolutions` note: "inherits the original asker's scope"), `language = 'es'`, `validity_start = today` / `validity_end = null` (open), `tagging_status = 'verified'` (the agent owns it — no separate approver, per decision), `ingested_by` = acting admin. Initial `retrieval_status = 'draft'` (not yet answerable).
- Link `escalation_resolutions.converted_to_document_id` → the new document.
- Append a `tag_events` row on the document: `facet='document'`, `note='created from escalation #{id} by {agent}'`, `source='admin_manual'`, `actor_id` — this is the provenance the Knowledge Center renders (§6).

### 5.3 The scope-confirm gate (reuse the Sprint-3 pattern)

Publishing a ruling makes it an answer for **everyone in that scope**. Reuse the Sprint-3 bounded-edit fence exactly: if the publish step is requested without `confirm_scope_change=true`, return **409** with the scope-affecting message (the same shape as `DocumentController::updateLifecycle`/`reassignFacet`). The UI shows the confirm modal ("this becomes an answer for everyone in {convenio/territory/sector}"). **Non-negotiable** — no publish without confirmation.

### 5.4 Publish-time no-silent-override enforcement (the load-bearing legal fence)

Before flipping the ruling to `active`, run a **conflict gate** in `hr-backend` (this is the enforcement the data-model authority rule demands, done at publish, not advisory):

- **Detect** an active `official_convenio` in the **same scope + topic**: query `documents WHERE convenio_id = {asker.convenio_id} AND authority_level = 'official_convenio' AND retrieval_status = 'active'` that **share a topic** with the ruling. Topic source: the topic(s) the agent assigns to the ruling at publish (reuse the approved-topic picker from Sprint 3) and/or the card's `topic_id`. 
- **On conflict → block / re-escalate, never silently win:** do **not** set `active`; keep the document `draft`, surface a clear message ("An official convenio already governs {topic} for {scope}; an internal ruling cannot override it — routing for adjudication"), append an `escalation_events` `publish_blocked` row, and **re-escalate** by creating a `document_review_tasks` row `type='conflict'` (reuse the existing conflict-task machinery, ADR-0011 "confidence never beats a conflict; human-adjudicated") and/or moving the card back to `in_progress`. The card is **not** marked resolved-converted on a blocked publish.
- **On no conflict (and scope confirmed):** set `retrieval_status = 'active'`, make it retrievable (§5.5), move the card to `resolved` + `resolved_at`.

**Flagged Q-B (conflict granularity):** "conflict" needs a concrete, conservative operationalization. The plan's default is **scope (convenio) + shared-topic presence** as a *block trigger* (conservative: presence of an official-convenio doc on the same topic blocks the silent publish and routes to a human). A semantic/answer-level check (e.g. running the sandbox/retrieval to see whether the convenio already answers, then comparing) is richer but heavier and risks false "no conflict". Recommend the conservative topic-level block for this sprint; flag for review.

### 5.5 How the published ruling becomes retrievable (reuse, don't re-architect)

Once `active`, the ruling must enter the **existing** ingestion + precedence path (no answer-loop change):
- Add `internal_hr_ruling` to `ChunksEmbed::IN_SCOPE_TYPES` (hr-backend code change; the type then satisfies the `active` + `tagging_status≠under_review` selection).
- **Q-A — the source-artifact decision (highest-priority open question).** The existing `/embed` re-extracts a **PDF**; a ruling is typed prose. Two minimal options, neither re-architecting retrieval:
  - **(A1) Render the ruling text to a stored artifact and reuse the pipeline unchanged.** hr-backend writes the ruling body to S3 (a generated PDF, or a `.txt`/`.md`), sets `storage_path`, runs `/extract` (pages) + `chunks:embed --document={uuid}`. A generated **PDF** keeps **hr-ai literally untouched** (honors "hr-ai stays as-is") but adds a PDF-render dependency in hr-backend.
  - **(A2) A tiny additive hr-ai text path** (e.g. `/embed` accepts inline `text` and skips PDF re-extraction, chunking the provided prose on the same article/paragraph splitter). This is an **additive hr-ai code change but no DB migration** (hr-ai still never migrates). Lighter than rendering PDFs, but technically touches hr-ai.
  - **Recommendation:** A1 (render → S3 → reuse `/extract`+`/embed`) to satisfy the hard constraint "hr-ai stays as-is"; A2 is the fallback if PDF rendering is deemed heavier than a 10-line hr-ai text branch. **Decide at review.**
- Precedence: the ruling's chunks carry `authority_level='internal_hr_ruling'`; at retrieval they are ordered as governing (§1.5). The publish-time conflict gate (§5.4) is what guarantees no silent override; we do **not** change 2b precedence.

---

## 6. Knowledge Center integration

A published `internal_hr_ruling` is a normal `documents` row with a convenio scope, so it **already** appears as a **leaf** in the Sprint-3 lens hierarchy (territory / sector / validity lenses via its convenio; topic lens once a topic is tagged). Reuse `Hierarchy.tsx` + `KnowledgeMapPage.tsx` unchanged.

Work to do (component-level, reuse-first):
- **Badge:** show an `internal_hr_ruling` authority badge on the document card. `DocumentDetailPanel` already renders `authority_level`; add a badge style/label for `internal_hr_ruling` (a new `.badge-*` class + a token role; the chat UI already has the Spanish label "una resolución interna de RR. HH." in `ChatScreen.tsx`). Minimal new visual primitive, consistent with the design-system rule (add a class/token first).
- **Provenance "created from escalation #N by [agent]" + back-link.** Extend `DocumentController::show()` to include the linked escalation when present (join `escalation_resolutions WHERE converted_to_document_id = doc.id` → card uuid + escalating employee + agent + date). The `tag_events` "created from escalation #N" row (§5.2) already renders in the existing provenance timeline; add the explicit **link back to the card** in the card payload so the panel can deep-link to the board's card detail.
- The hierarchy/coverage-gap derivations (`KnowledgeMap`) are unaffected; a ruling with chunks is answerable, with 0 chunks would (correctly) flag `unanswerable` — which is honest until embed runs.

---

## 7. Roles / access this sprint

- **Use the Sprint-0 seeded roles** (`super_admin`, `hr_agent`, `knowledge_editor`, `auditor`; `RoleSeeder.php`). Add **one new ability** `escalation.work` (the pattern of Sprint-3's `knowledge.edit`): granted to `super_admin` + `hr_agent`; denied to `knowledge_editor`. `auditor` may **read** the board (browse + open cards) but not assign/move/reply/resolve — mirrors the auditor's read-only posture (Q-H: confirm auditor read access; default = yes, read-only).
- Gate the board write routes (assign/move/reply/resolve) with the `ability:escalation.work` middleware (reuse the `EnsureCan` middleware from Sprint 3). Surface the ability on `/me` (`IdentityPresenter` already returns `abilities`; add `escalation.work`) so the frontend gates the Board affordances.
- **Seed an `hr_agent` test admin.** `TestUserSeeder` currently seeds only `super_admin` / `knowledge_editor` / `auditor` — add an env-driven `SEED_HR_AGENT_EMAIL` assigned the `hr_agent` role so the board is testable eyes-on as the intended worker.

**Boundary (stated, so Sprint 4 isn't mistaken for the access sprint):** Sprint 4 does **not** build role-scoped *conversation* access enforcement, the directory/user management, or the any-employee history browser — those are **Sprint 5** (`roadmap.md` Sprint 5; pillar §4.2). The **card-scoped view (§3) is the only privacy guard** until Sprint 5 enforces role-scoped access.

---

## 8. Build order, additive migrations, assumptions & open questions

### 8.1 Additive migrations (hr-backend owns all schema)

1. **`chat_messages` author kind + attribution** — (a) add `hr_agent` to the `role` CHECK constraint via the introspect-drop-readd idiom (`…100001_add_salary_coverage_gap…php` pattern; reversible, idempotent); (b) add nullable `author_admin_id` FK → admins. *(Confirmed needed — §1.3.)*
2. **`escalation_events`** — new append-only audit table for status moves / assigns / replies / resolutions / publish-blocks (§2.4). *(Or reuse `tag_events` — Q-E.)*

No migration is needed for: `escalation_resolutions` (exists — we add the model + first writer), the `internal_hr_ruling` `authority_level`/`document_type` (already in the enums/seed), `escalation_cards` (status/reason enums already cover the flow).

### 8.2 Non-migration code changes

- `ChunksEmbed::IN_SCOPE_TYPES` += `internal_hr_ruling` (§5.5).
- `EscalationCard` relations (`employee`, `sourceMessage`, `assignedTo`, `session`, `resolution`); new `EscalationResolution` + `EscalationEvent` models.
- `IdentityPresenter` abilities += `escalation.work`; `RoleSeeder` grants it; `TestUserSeeder` seeds `hr_agent`.
- The ruling source-artifact mechanism (Q-A: A1 render-to-S3, recommended; or A2 additive hr-ai text path).

### 8.3 Build order

**hr-backend (writes + audited APIs):**
1. Migrations (8.1) + models/relations.
2. Board read/move APIs (`EscalationController` list/show/patch) + `escalation_events` audit + ability gating + seed `hr_agent`.
3. Card-detail read (card-scoped conversation + trace).
4. Reply API + the employee `GET /chat/session` load endpoint.
5. Resolve/convert: `escalation_resolutions` writer → scope-confirm 409 gate → **publish conflict gate** → make-retrievable wiring (embed selection + Q-A artifact) → card → resolved.

**hr-frontend (reuse design system + Sprint-3 shell/components):**
6. Board view in `AdminShell` (Kanban columns, filters, assign/move, ability-gated).
7. Card-detail drawer (conversation bubbles + reused `TracePanel`).
8. Reply UI on the card; employee chat hydrate-from-session + polling + the attributed `hr_agent` bubble.
9. Save-as-knowledge flow (resolution + convert + topic picker + scope-confirm modal + conflict-block message).
10. Knowledge-Center badge + provenance back-link on `DocumentDetailPanel`.

**Eyes-on (per spec):** seed/find a `low_confidence` vacaciones card → assign/move/open → see conversation + trace → reply (confirm the employee sees the attributed human reply) → Save as knowledge (scope-confirm appears; inherits asker scope) → try the conflict case (a ruling contradicting an official convenio for the same scope is **blocked/re-escalated**) → confirm the published ruling appears in the Knowledge Center (badged, linked to the card) and a **new** employee in that scope is answered from it.

### 8.4 Assumptions

- The `topic` for the conflict gate / KC topic lens comes from the agent's approved-topic pick at publish (reuse Sprint-3 picker) and/or the card's `topic_id`. If neither is set, the conflict gate falls back to scope-only with a conservative warning (flagged).
- "No separate human approver" (the agent owns the publish) — per the spec decision; the scope-confirm step is the only gate besides the conflict gate.
- Employee reply visibility via **polling** is acceptable for this sprint (no websockets/notifications).
- The ruling is single-convenio scoped (inherits the asker's convenio). A national/multi-scope ruling is out of scope (the scope-rides-on-convenio limitation, data-model §5; multi-scope structured knowledge is Sprint 7).

### 8.5 Open questions (esp. the chat author model + conflict-at-publish)

- **Q-A (highest).** Ruling-text → retrievable source artifact: **A1** render to S3 + reuse `/extract`+`/embed` (hr-ai untouched, adds a PDF/text-render in hr-backend) vs **A2** a tiny additive hr-ai text-embed branch (no migration, but touches hr-ai). Recommend A1.
- **Q-B.** Publish conflict-detection granularity: conservative **scope+shared-topic block** (recommended) vs a semantic/sandbox-retrieval comparison. How is "same topic" sourced if the card/ruling has no topic?
- **Q-C.** Chat author kind shape: add `hr_agent` to the `role` enum **+** `author_admin_id` (recommended) vs a separate `author_type` column. Recommend the former (smaller, reuses `role`).
- **Q-D.** Employee sees the human reply via **session-load + polling** (recommended minimal) — confirm no websockets/notifications this sprint.
- **Q-E.** Card audit table: dedicated **`escalation_events`** (recommended) vs reuse `tag_events`.
- **Q-F.** Retrieval-precedence residual: a convenio doc added/edited *after* a ruling is published could conflict without auto-demotion at retrieval (2b is frozen). Acceptable boundary for Sprint 4 (publish gate covers publish-time; later conflicts ride the existing review-task/expiry path)? Or note for a later precedence sprint.
- **Q-G.** An employee follow-up after a human reply: minimal default = normal loop (a fresh escalation spawns a *new* card). Confirm vs. re-linking follow-ups to the same card.
- **Q-H.** Board **read** access for `auditor` (read-only) — default yes; confirm.

---

**This plan writes no board/API/UI code.** It is ready for review. On approval, the build proceeds in the §8.3 order, honoring the hard constraints: no answer-loop change beyond making a published `internal_hr_ruling` retrievable via the existing ingestion/precedence path; `hr-backend` owns all writes + additive-only schema; every move/reply/resolution/publish audited; the no-override rule enforced **at publish**; card-scoped conversation viewing only (no full-history browser, no role-scoped-access enforcement — Sprint 5); design-system + Sprint-3 components reused; the human reply clearly attributed, never mistakable for a bot answer.
