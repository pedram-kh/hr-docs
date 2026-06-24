# Sprint 4 — Escalation board + the flywheel · Review

> Location: `hr-docs/sprints/sprint-04/review.md`
> Status: **built; pending eyes-on + commit.** Built in the approved §8.3 order, all resolved open questions (Q-A…Q-H) applied exactly. Do not commit until reviewed.

This sprint closes the learning loop: **unanswered → a human works it on a board → the employee gets a clearly-attributed human reply → the answer becomes scoped knowledge → the next asker in that scope is answered automatically.** `hr-backend` owns every write; `hr-ai` and the 2b answer loop are untouched.

---

## 1. The required verification — lossless ruling round-trip (Q-A "A1")

The hard requirement: the agent's typed `resolution_text`, rendered to a generated PDF and pushed through `hr-ai`'s **existing** `/extract` + `/embed` path, must come back **lossless** — the embedded chunk text equals the typed text (whitespace-normalised), with no de-spacing / furniture / two-column mangling from the 2a heuristics. If a clean PDF were mangled, the fallback is A2 (a tiny additive `hr-ai` inline-text branch) — **not** silently shipping a mangled ruling.

**Result: lossless on both fixtures**, run through the live `hr_ai` container's real pipeline (`app.pipeline.build_chunks` → `extract_language_streams` → `chunk_document`), the same code `/embed` calls:

| fixture | typed chars (norm.) | embedded chars (norm.) | chunks | lossless |
|---|---|---|---|---|
| short ruling (vacaciones, accents, a figure "23 días") | 221 | 221 | 1 | **✅ yes** |
| multi-paragraph ruling (permisos retribuidos: a)/b)/c) list, "segundo grado", long clauses) | 811 | 811 | 1 | **✅ yes** |

Both came back **character-identical** after whitespace-normalisation — Spanish accents intact (`días`, `justificación`, `quirúrgica`), figures intact (`23`, `Quince`, `Dos`, `cuatro`), no word-merging, no de-hyphenation artifact, no dropped list markers. A1 holds; **A2 was not needed** and `hr-ai` was not modified.

Why it round-trips (engineered into `RulingPdf`, `hr-backend/app/Support/RulingPdf.php`):

- **Single column, no header/footer** → `extract_columns` sees one stream; the repetition-furniture stripper has nothing to strip.
- **Widened inter-word spacing** (`Tw` word-spacing) → every gap clears `chunk_space_gap_ratio` (0.30), so the de-spacing normaliser (ADR-0013) never merges adjacent words.
- **No end-of-line hyphenation** (`avoidTrailingHyphen`) → the de-hyphenation pass never alters a word.
- **Helvetica + WinAnsiEncoding** → Spanish glyphs encode/extract identically.

`RulingPublisher::publish()` runs this same comparison at publish time (`verifyRoundTrip()`) and reports `{ lossless, expected_len, embedded_len, chunk_count }`; the chunk count is also written into the publish `tag_events` note.

> Reproduction (not committed): rendered the two fixtures with `RulingPdf::render()`, `docker cp`'d the PDFs into `hr_ai`, ran `build_chunks` and compared whitespace-normalised text. Temp files removed after the run.

---

## 2. The build (in the §8.3 order, hard constraints enforced)

### Additive migrations (hr-backend only — two, as planned)

1. `2026_06_24_100001_add_hr_agent_author_to_chat_messages` — adds **`hr_agent`** to the `chat_messages.role` CHECK via the proven introspect-drop-readd idiom (reversible, idempotent — the `…add_salary_coverage_gap…` pattern) **+** a nullable **`author_admin_id`** FK → `admins` (`nullOnDelete`).
2. `2026_06_24_100002_create_escalation_events_table` — the append-only card activity log (`escalation_card_id`, `type`, `old_value`, `new_value`, `actor_id`, `note`, `created_at` only).

No other schema — `escalation_cards` / `escalation_resolutions` / the `internal_hr_ruling` authority value already exist.

### hr-backend — the write surface (every action audited)

- **`EscalationController`** (`app/Http/Controllers/Admin/EscalationController.php`) — `index` (board, filters: status/reason/assignee/`unassigned`/convenio + per-status counts), `show` (card + the **card-scoped** conversation + trace + event log), `update` (assign + status, **server-validated legal transitions**, 422 on illegal), `reply` (human turn), `resolve`. Reads open to any admin; writes in an `ability:escalation.work` route group.
- **`EscalationService`** (`app/Services/EscalationService.php`) — the legal-transition map; the reply (writes an `hr_agent` `chat_messages`, no trace/citations, `replied` event); the resolve/convert flow (below). Every move/assign/reply/resolution/block → `EscalationEvent`.
- **Card-scoped conversation read** — keyed strictly to `card.chat_session_id` (no caller-supplied employee/session param). That keying **is** the access guard this sprint; served by `ConversationPresenter` (admin audience shows the authoring admin name).
- **Employee `GET /chat/session`** (`ChatController@session`) — self-scoped to the caller's own most-recent session; returns ordered messages incl. `hr_agent` ones, attributed **"Recursos Humanos"** (never the admin's name/email). No caller-supplied id.
- **`escalation.work` ability + seed** — `RoleSeeder` grants it to `super_admin` + `hr_agent` (denied to `auditor`, `knowledge_editor`); `TestUserSeeder` seeds an `hr_agent` test user (`SEED_HR_AGENT_EMAIL`, default `agent@example.com`). `IdentityPresenter` exposes `escalation.work` + the admin's numeric `id` (for self-assign).

### hr-backend — resolve → Save-as-knowledge (the flywheel, gates in order)

`POST /admin/escalations/{uuid}/resolve` `{ resolution_text, convert?, topic_id?, confirm_scope_change? }`:

1. **No convert** → write the `EscalationResolution` (text only, `converted_to_document_id = null`), card → Resolved (`resolved_at`).
2. **Convert** → **scope-confirm 409** (`scope_confirmation_required`) unless `confirm_scope_change` — the reused Sprint-3 bounded-edit confirm. Then create/reuse the **draft** `internal_hr_ruling` (inherits the asker's convenio; territory/sector ride it), attach the picked topic, write the "created from escalation #N by [agent]" provenance.
3. **No-override conflict 409** (`publish_blocked`) — `detectConflicts()`: an **active `official_convenio`** in the asker's convenio scope sharing the ruling's **topic** blocks publish; **no topic → scope-only** block (any active official convenio in scope). On block: the ruling **stays `draft`**, a `conflict` **`document_review_tasks`** row is opened on it (the same queue ingest conflicts land in — ADR-0011), a `publish_blocked` `escalation_event` records the conflicting convenio uuids, and the **card returns to In Progress** (routed to a human). Nothing is published.
4. **On pass** → `RulingPublisher::publish()` renders → S3 → `/extract` (`document_pages`) → `/embed` (`document_chunks`, scope resolved by hr-backend) → flips the draft to **`active`**, resolves any open conflict task, links `escalation_resolutions.converted_to_document_id`, logs `converted`, card → **Resolved** (`resolved_at`). `internal_hr_ruling` added to **`ChunksEmbed::IN_SCOPE_TYPES`** (code, not migration).

### hr-ai — untouched (A1)

Not modified. It embeds the generated PDF like any prose doc; it does not migrate. A2 remains the documented fallback only (not needed — see §1).

### hr-frontend (reusing the Sprint-3 admin shell + components)

- **`EscalationBoardPage.tsx`** — Kanban columns (New → Assigned → In Progress → Resolved), filters (status/reason/`mine`), ability-gated actions (`canWorkEscalations()`); an auditor sees a read-only board.
- **`EscalationCardDrawer.tsx`** — card meta + the card-scoped conversation bubbles + the **reused `TracePanel`** (why it escalated) + triage (assign/status) + the reply box + the **Save-as-knowledge** flow (resolution, optional convert, the **required approved-topic picker**, the **scope-confirm modal**, the conflict-block message).
- **`ChatScreen.tsx`** — now **hydrates from `GET /chat/session` on mount, polls (~25 s) + re-hydrates on window focus**; a human reply renders as a distinct, clearly-attributed **`chat-bubble--agent`** bubble ("Respuesta de Recursos Humanos (persona)") — never mistakable for a bot answer, never showing admin identity.
- **`DocumentDetailPanel.tsx`** — for an `internal_hr_ruling`: the badge + "created from escalación #N by [agent]" provenance + a **back-link** to the card (deep-links via `AdminShell` → board focus).
- **`index.css`** — the only new visual primitives: the `internal_hr_ruling` badge and the `chat-bubble--agent` variant (one class/token each, per the design-system rule), plus the board layout classes.

Frontend `tsc -b && vite build` clean; ESLint clean (the one `set-state-in-effect` in `ChatScreen`'s hydrate is the legitimate async-subscription pattern, disabled with a note; the board avoids it via a derived `openUuid`).

---

## 3. Conflict-gate behaviour (the no-silent-override fence)

The fence is **enforced at publish, never advisory**, and **errs toward blocking** (Q-B):

- **With a topic** — blocks iff an active `official_convenio` in the asker's convenio scope shares that topic. A false block routes to a human (safe); a false "no conflict" is the exact harm, so the gate is conservative.
- **No topic** — falls back to a scope-only block (any active official convenio in scope), over-blocking deliberately.
- **On block** — the draft is kept (not published, not retrievable — `retrieval_status = draft`, and `/embed` only runs on pass), a `conflict` review task is opened, the conflicting convenio uuids are recorded in a `publish_blocked` event, and the card goes back to **In Progress**. A retry after the agent narrows scope/topic **reuses the same draft** (no orphan-draft pile-up).
- **Guardrail fires first** — a published ruling on a genuinely sensitive topic is unreachable because the 2b-1 guardrail escalates *before* retrieval (correct; nothing to verify here).

**Deviation from the literal plan wording, resolved toward the spec's eyes-on intent:** the plan's first pass blocked *before* creating any document (so there was no draft and no conflict task). That contradicted the eyes-on line "*stays draft, conflict task created*". This was corrected — the draft is now created first, kept on block, and carries a `conflict` `document_review_tasks` row — so the observable behaviour matches the checklist. The conflict is now surfaced in **both** places: the card's `escalation_events` log **and** the document review queue.

---

## 4. Eyes-on (Pedram runs live)

`php artisan migrate` (applies the two additive migrations) → `php artisan db:seed` (seeds the `hr_agent` test user + `escalation.work`).

1. **Find/seed a `low_confidence` vacaciones card** — ask `test-navarra@…` a question that escalates; confirm a card lands in **New**.
2. **Board** — assign it (→ Assigned), move status (→ In Progress); open it → see the **card-scoped conversation** + the **trace** (why it escalated).
3. **Reply to the employee** — confirm it lands in the employee's chat as a clearly-attributed **"Recursos Humanos"** bubble (distinct from a bot answer), and that the employee sees it on poll/refresh of `ChatScreen`.
4. **Save as knowledge** — the **required topic pick** + the **scope-confirm modal** appear; confirm it inherits the asker's convenio; after publish, verify the published ruling's embedded text is **lossless** (matches what was typed — §1).
5. **Conflict case** — pick a topic that an **active `official_convenio`** in that scope governs → publish is **blocked**: ruling stays **draft**, a `conflict` task is created, the card returns to **In Progress**, nothing is silently published.
6. **Knowledge Center** — the published (non-conflicting) ruling appears badged **`internal_hr_ruling`** with the "created from escalación #N by [agent]" provenance + back-link; a **new employee in that scope** is now answered from it (the flywheel turns).
7. **Auditor** — an `auditor` can **read** the board + open cards + read the conversation/trace, but **cannot** assign/move/reply/resolve (403 — `escalation.work` denied).

---

## 5. Constraints honoured

- **No answer-loop change** beyond making a published ruling retrievable via the **existing** ingestion/precedence path. 2b is frozen; `hr-ai` is untouched (A1, verified lossless).
- **hr-backend owns all writes + schema; additive migrations only** (two). Every status move / assign / reply / resolution / blocked-publish is audited to `escalation_events`. The no-override rule is **enforced at publish** (block → keep draft + conflict task + re-escalate). **Scope-confirm is non-negotiable** (no publish without `confirm_scope_change`).
- **Card-scoped conversation viewing only** — keyed to `card.chat_session_id`, no full-history browser, no role-scoped-access enforcement (Sprint 5). The human reply is clearly attributed, never mistakable for a bot answer, never leaks admin PII to the employee.
- **Design system reused** — only two new visual primitives (the `internal_hr_ruling` badge, the `chat-bubble--agent` variant).
- **Single-convenio scope** — the ruling inherits the asker's convenio (territory/sector ride it); a national/multi-scope ruling is out of scope (Sprint 7).

---

## 6. Follow-ups (not this sprint — on record)

- **Q-F residual (documented boundary):** the fence runs at **ruling-publish**. The **symmetric** check — adding/editing an `official_convenio` re-scans existing rulings for newly-introduced conflicts — is deferred to a later precedence/guardrails sprint (largely latent until a self-service convenio-upload path exists). 2b retrieval precedence stays frozen. Recorded in `architecture.md §8.5` and `roadmap.md §7`.
- **Restrict `convert` by escalation reason** → Sprint-6 guardrails policy (convert is the agent's judgment this sprint).
- **Full-history browser + role-scoped conversation access + history search** → reaffirmed for Sprint 5.
- **Real-time delivery** — the employee surface is hydrate-on-mount + poll/focus (no websockets/notifications this sprint, per Q-D).

---

## Docs updated at close

- **`architecture.md` §8** — expanded into §8.1 board / §8.2 the two-way reply surface + `hr_agent` author kind / §8.3 Save-as-knowledge + publish-time no-silent-override + scope-confirm / §8.4 the A1 ruling-artifact mechanism / §8.5 the Q-F known boundary; the guardrail-fires-first note.
- **`data-model.md`** — `chat_messages` (`hr_agent` + `author_admin_id`, additive); `escalation_resolutions` now written (draft-link semantics); new `escalation_events`; the `internal_hr_ruling` retrievability path; the Group-E two-way note.
- **`roadmap.md`** — Sprint 4 marked DONE (table + section); Sprint 5 reaffirmed (full-history browser + role-scoped access + history search); the symmetric convenio-side re-check + the convert-by-reason restriction parked in §7.
- **READMEs** — `hr-backend` (the board API, the two-way surface, the A1 publisher, the two migrations, the seed) and `hr-frontend` (the board page, card drawer, the employee hydrate/poll, the KC badge/provenance).
