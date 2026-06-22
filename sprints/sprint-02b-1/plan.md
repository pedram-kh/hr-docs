# Sprint 2b-1 — Implementation Plan

> Location: `hr-docs/sprints/sprint-02b-1/plan.md`
> Status: **draft — awaiting review. No code / migrations / config written yet.**
> Implements: `sprint-02b-1/sprint-02b-1-spec.md`. Read alongside `architecture.md` (§2, §5, §7, §11), `data-model.md` (§8, §9, §11), all ADRs (esp. **ADR-0007, ADR-0012, ADR-0013, ADR-0015**), `design-system.md`, `deploy.md` §1, and `sprint-02a/review.md` (the `/retrieve` primitive + scope resolver this slice consumes).
> Scope: add the answer synthesis step to `hr-ai`; add pipeline orchestration, provider-key handling, and answer-or-escalate decision to `hr-backend`; build the employee chat UI and admin key screen in `hr-frontend`. **No router, no salary-in-chat, no full grounding model, no board, no history-search UI.**

---

## 0. Guiding constraints (held throughout)

- **`hr-backend` is the system of record and pipeline orchestrator.** It resolves scope (no LLM), calls `hr-ai /retrieve` and the new `hr-ai /synthesise`, **owns every DB write** (`chat_sessions`, `chat_messages`, `message_citations`, `message_traces`, `escalation_cards`, `answer_model_settings`), and makes the answer-or-escalate decision. `hr-ai` still writes only `document_chunks` + S3, never migrates, never touches any other table (ADR-0007, ADR-0010).
- **The browser never sees the API key and never calls the provider.** The key is set via an admin screen, encrypted at rest by `hr-backend`, shown masked, rotatable, never read back. Only `hr-ai` calls the external provider — and only with a key decrypted and forwarded per-call by `hr-backend` over the internal `X-Internal-Token` channel.
- **Sensitive-topic questions escalate before the synthesis call.** The guardrail baseline fires deterministically in `hr-backend` on the raw question text; a matching question never reaches `hr-ai /synthesise` (and so is never sent to the external provider).
- **Conservative, audit-first.** Answer only when above the floor AND grounded with citations. Otherwise escalate, never guess.
- **Design system, no exceptions.** Chat UI composed entirely from the token/class vocabulary in `design-system.md` (ADR-0012). No bespoke styling, no new colour literals, no per-screen one-offs.
- **GDPR items are deploy-time** (`deploy.md` §1): EU endpoint, signed DPA, zero-retention. Referenced here, not built.
- **Docs move with code.** `data-model.md` (population of `chat_sessions` / `chat_messages` / `message_citations` / `message_traces` / `escalation_cards` plus the role-scoping-ready note) and `architecture.md` §5/§7 (pipeline/guardrail shape) are updated in the same change. `roadmap.md` is updated to record the 2b-1 / 2b-2 split.

---

## 1. Build order across repos + dependencies

Three phases, two of which can overlap once their interfaces are pinned.

### Phase 1 — `hr-ai` synthesis layer (no external dependency; pin the contract first)

1. **Provider interface + Claude adapter.** `app/providers/base.py` abstract `AnswerProvider`; `app/providers/claude.py` Anthropic adapter. Non-secret config (`ANSWER_PROVIDER`, `ANSWER_MODEL`, `ANSWER_ENDPOINT`) lives in `hr-ai/app/config.py` and `.env`. The adapter receives the decrypted key per call and uses it for the Anthropic request; it never stores it.
2. **`POST /synthesise` endpoint** (internal-token guarded, same pattern as `/retrieve`). Receives `{ question, chunks, provider_api_key, provider_config }`, calls the provider, returns `{ answer, citations, grounding_signal, confidence, trace_fragment }`. Zero persistence of the key (in-memory only for the lifetime of the handler).
3. **Synthesis prompt design.** A structured prompt that constrains Claude to answer only from the supplied chunks, to cite each claim with an inline marker (`[Fuente N]`) tied to a numbered chunk list, and to return a machine-parseable JSON envelope (answer text + array of cited chunk indices). The prompt is a constant in `app/providers/claude.py`, not inline in the handler.

### Phase 2 — `hr-backend` pipeline + key handling (depends on Phase 1 interface being pinned)

4. **Migration: `answer_model_settings` table** (hr-backend owns). Single-row configuration: `id`, `provider` varchar, `api_key_encrypted` text nullable, `key_last_four` varchar(4) nullable, `configured_at` timestamp nullable, `updated_by` FK → admins nullable. Laravel `Crypt::encryptString` / `decryptString` handles at-rest encryption (uses the app key — which lives in `.env`, never committed).
5. **`AnswerModelSetting` model + admin API routes.** `POST /api/admin/answer-model` (set or rotate key — encrypts, stores last four chars, never echoes back); `GET /api/admin/answer-model/status` (returns `{ configured: bool, masked_key: "••••1234" | null, provider, configured_at }` — the raw key is **never** in any response); `DELETE /api/admin/answer-model/key` (clear key / de-configure). These routes require `super_admin` middleware.
6. **`ChatService` orchestrator + `POST /api/chat/message` route.** The full pipeline (detailed in §3). Scope resolver reused from 2a's `ExtractionClient` / scope-resolution logic. The `ExtractionClient` gains a `synthesise()` method that forwards the decrypted key in the request body.
7. **Guardrail baseline service** (§5). Deterministic keyword/regex check run on the raw question before any downstream call.
8. **`answer_confidence_floor` constant** (§4). Named constant in `hr-backend/app/Services/ChatService.php` (or a `config/hr.php` entry); `RETRIEVAL_SCORE_FLOOR` alongside it. Never inline.
9. **DB write methods** (`ChatRepository` or equivalent): create/find `chat_session`, write user + assistant `chat_message`, write `message_citations` (batch), write `message_traces` (one row per assistant turn), write `escalation_card` on escalate.
10. **Test seeders.** Three seeded employee profiles (§9) required for acceptance-criteria verification; also a seeded `super_admin` for the key screen.

### Phase 3 — `hr-frontend` chat UI + admin key screen (depends on Phase 2 API routes being live)

11. **Admin "Answer model" screen** (`/admin/settings/answer-model`). Compose `.field` + `.input` + `.btn-primary` / `.btn-ghost` from the design system. Status display (configured ✓ / not configured). Masked key display. Set and rotate flows. Phase 2 step 5 is the API surface.
12. **Employee chat screen** (`/chat`). `ChatScreen`, `MessageList`, `MessageBubble`, `AnswerBlock`, `CitationList`, `TracePanel`, `EscalationBlock`, `ChatInput` — all on design-system tokens/classes (§8).

### What 2b-2 builds on

2b-2 inserts the **router** step between scope resolution and `/retrieve` in the existing `ChatService` pipeline: the router classifies the query (salary lookup / prose / off-domain) and routes to either the salary SQL path (new) or the existing `/retrieve` + `/synthesise` path. The synthesis endpoint, key handling, guardrail baseline, trace/history writes, and chat UI are all unchanged. 2b-2 also replaces the citation-coverage grounding approximation with a standalone grounding call and adds history-search UI.

---

## 2. External answer model + key handling (ADR-0015)

### Pluggable provider in `hr-ai`

`app/providers/base.py` defines `AnswerProvider`:

```python
class AnswerProvider(ABC):
    @abstractmethod
    async def synthesise(
        self, question: str, chunks: list[ChunkInput],
        api_key: str, config: ProviderConfig
    ) -> SynthesisResult: ...
```

`app/providers/claude.py` implements this for Anthropic. `ProviderConfig` carries only non-secret fields (`provider`, `model`, `endpoint`) — these come from `hr-ai`'s own config (not from `hr-backend`). The `api_key` argument arrives per call, is used to build the Anthropic client for that call, and is never assigned to any instance variable, never logged, and never written to disk.

`app/config.py` adds three non-secret entries:
- `ANSWER_PROVIDER` — string, default `"claude"` (the `hr-ai` `.env` can override to swap providers; no `hr-backend` change needed)
- `ANSWER_MODEL` — string, e.g. `"claude-opus-4-5"` (the EU-available model to use)
- `ANSWER_ENDPOINT` — string, EU Anthropic API endpoint URL

These are non-secret because they do not give access to anything on their own. The API key is the secret; it lives in `hr-backend`.

### Admin "Answer model" screen (hr-frontend → hr-backend)

The screen is a single form in the admin console under Settings. Its contract with `hr-backend`:

| Action | HTTP | Response |
|---|---|---|
| Check status | `GET /api/admin/answer-model/status` | `{ configured, masked_key, provider, configured_at }` |
| Set / rotate key | `POST /api/admin/answer-model` body `{ api_key }` | `{ configured: true, masked_key: "••••<last4>" }` |
| Remove key | `DELETE /api/admin/answer-model/key` | `{ configured: false }` |

Rules enforced at every layer:
- `hr-backend` controller **never** includes the raw key in any JSON response (not even in a 201 confirmation).
- `hr-backend` logs the key-set action to `employee_audit_log`-equivalent (a log entry, not a data row) with the admin's identity and timestamp — but **not** the key value.
- `hr-frontend` **never** stores the key value in component state beyond the controlled input. On submit, the field is cleared.
- The masked display `••••1234` is constructed from `key_last_four` stored alongside the encrypted value — `hr-backend` can reconstruct it without decrypting.

### How `hr-backend` passes the decrypted key to `hr-ai`

The `X-Internal-Token` header on every `hr-ai` request is the **authentication** token (proves the call is from `hr-backend`, not from a browser). The API key travels as a separate field in the **body** of the `/synthesise` request, never in a header:

```json
POST /synthesise
X-Internal-Token: <shared secret>
{
  "question": "...",
  "chunks": [...],
  "provider_api_key": "<decrypted_key>",
  "provider_config": { "provider": "claude", "model": "...", "endpoint": "..." }
}
```

The decryption happens in `ChatService::handleMessage()` immediately before the `synthesise()` call and the value is not bound to any variable with a longer lifetime than the call stack. `hr-ai` receives it, passes it to the provider adapter, and discards it when the handler returns. It is never written to any `hr-ai` log, database, or file.

---

## 3. The answer pipeline — single prose path, no router

**Every step runs in `hr-backend`'s `ChatService` except the two shaded `hr-ai` calls.**

```
POST /api/chat/message  { question, session_uuid? }
  │  (Sanctum bearer token → authenticated employee)
  │
  ▼
[hr-backend] 1. SCOPE RESOLUTION (deterministic SQL — no LLM)
  employee.convenio_id, employee.territory_id, employee.job_category_id,
  employment_type, question_date (today unless caller supplies override)
  → scope_filters: { convenio_id, include_national_law: true,
                     retrieval_status: ['active'], as_of_date }
  WHY HERE: carries legal weight; must be deterministic (ADR-0007)
  │
  ▼
[hr-backend] 2. GUARDRAIL BASELINE CHECK (deterministic regex — §5)
  If triggered → write trace fragment (guardrail fired), persist both messages,
  write escalation_card { reason: sensitive_topic | off_domain }, return
  escalation response. No further steps. Provider never called.
  │
  ▼
[hr-ai]      3. /retrieve  (2a primitive — reused unchanged)
  { query: question, convenio_id, include_national_law, retrieval_status,
    as_of_date, k: 8 }
  → { chunks: [{ chunk_id, document_id, page_from, page_to, content,
                 score, authority_level }], eligible_total }
  WHY IN hr-ai: BGE-M3 embedding + pgvector full-recall exact scan (ADR-0006/0013)
  │
  ▼
[hr-backend] 4. PRE-SYNTHESIS FLOOR CHECK
  If eligible_total == 0 OR top chunk score < RETRIEVAL_SCORE_FLOOR:
  → escalate (reason: low_confidence). No synthesis call. Record trace.
  │
  ▼
[hr-ai]      5. /synthesise  (new this sprint)
  { question, chunks (from step 3), provider_api_key (decrypted), provider_config }
  → { answer, citations: [{ chunk_id, document_id, page_from, page_to }],
      grounding_signal: { grounded, citation_count, top_chunk_score },
      confidence, trace_fragment }
  WHY IN hr-ai: the LLM/reasoning ecosystem lives in Python (ADR-0007);
                synthesis is the only LLM call in the prose path
  │
  ▼
[hr-backend] 6. ANSWER-OR-ESCALATE DECISION
  Pass condition (ALL must be true):
    • grounding_signal.grounded == true
    • grounding_signal.citation_count >= 1
    • confidence >= ANSWER_CONFIDENCE_FLOOR
  Fail → escalate (reason: low_confidence)
  Pass → surface the cited answer
  WHY IN hr-backend: the decision carries legal weight; hr-backend owns
                     the guardrail baseline and the confidence floor (ADR-0007)
  │
  ▼
[hr-backend] 7. DB WRITES (always — regardless of answer or escalate)
  chat_session   — create if new, update last_activity_at
  chat_message   — role: user (the question)
  chat_message   — role: assistant (the answer text or escalation message)
  message_citations — one row per cited chunk (only on answer turns)
  message_traces — one row per assistant message (full structured trace)
  escalation_card — created on every escalate outcome
```

**Why synthesis is in `hr-ai` and the decision is in `hr-backend`:** ADR-0007 assigns LLM calls to `hr-ai` (the Python ecosystem owns the model SDKs and the embedding/retrieval toolchain). The answer-or-escalate decision, however, carries legal weight — it applies the confidence floor and the hardcoded guardrail baseline, both of which must be reproducible and auditable at the `hr-backend` level without dependency on `hr-ai`'s internals. The split means `hr-ai` can be swapped (provider swap is a config change) without touching the decision logic.

---

## 4. The answer-or-escalate floor

### `answer_confidence_floor` — the named config value

Defined as a named constant in `hr-backend` (e.g. `config/hr.php` or a constant on `ChatService`) — **never** an inline literal anywhere in the pipeline code:

```php
// Sprint-6-exposable knob — additive-only (admin may raise, never lower below
// this default). Do NOT modify this value inline; use the Sprint-6 config UI.
const ANSWER_CONFIDENCE_FLOOR = 0.65;
```

A second named constant governs the pre-synthesis retrieval gate:

```php
// Minimum cosine similarity for any chunk to be considered meaningful retrieval.
// Below this, the eligible set is treated as no retrieval → escalate.
const RETRIEVAL_SCORE_FLOOR = 0.40;
```

Both are flagged in comments as the Sprint-6-exposable, additive-only knobs. The Sprint-6 guardrails UI will surface them as admin-configurable values with a **floor** equal to the current defaults (an admin can raise them, never lower them below the hardcoded defaults).

### How "grounded" is approximated in 2b-1

The full grounding model (a separate verification LLM call per claim, 2b-2) is out of scope. 2b-1 approximates grounding with two overlapping checks:

**Check A — Retrieval score threshold (pre-synthesis, in `hr-backend`).**  
If the top-scoring chunk from `/retrieve` is below `RETRIEVAL_SCORE_FLOOR` (0.40), the eligible set is treated as effectively no retrieval. The question escalates before synthesis. This catches off-topic questions that happen to retrieve distantly related chunks at low similarity.

**Check B — Citation coverage (post-synthesis, in `hr-backend`).**  
The synthesis prompt instructs Claude to cite each factual claim with an inline `[Fuente N]` marker and return the list of cited chunk indices in a structured JSON envelope. `hr-ai` parses this and populates `grounding_signal.citation_count`. An answer with zero citations fails the floor. An answer that cites chunks not in the provided set is rejected (hr-backend validates that every citation `chunk_id` was in the input list).

**Check C — Confidence signal (post-synthesis, in `hr-backend`).**  
The synthesis prompt asks Claude to return a self-assessed confidence (0–1) in the structured envelope. `hr-ai` passes it through; `hr-backend` applies `ANSWER_CONFIDENCE_FLOOR`.

The three checks are composited as the AND condition in §3 step 6. Any failure → escalate. **No guessing** — the design-system voice applies: "I'm not sure about this, so I'm passing it to a person."

### How salary questions and off-domain questions land in escalation

In 2b-1 there is no router. A salary or off-domain question is treated as a prose question and goes through `/retrieve`. Salary questions return very few (or zero) relevant prose chunks, because salary figures live in `salary_tables`, never in `document_chunks` (ADR-0006). Their top chunk score will be below `RETRIEVAL_SCORE_FLOOR`, or the synthesised answer will have too few citations, and both paths lead to escalation. This is the **safe failure mode**: the employee is told "I can't reliably answer this, I'm escalating" — which is true. The 2b-2 router will give them a proper salary answer instead.

Off-domain questions (not HR related) also return low-similarity chunks and escalate via the same floor. The `off_domain` `reason` on the `escalation_card` is set by the guardrail baseline check (§5) if the question clearly violates the domain; otherwise it falls through to `low_confidence`.

---

## 5. Hardcoded guardrail baseline

Implemented as a deterministic, no-LLM check in `hr-backend` **before** any call to `hr-ai`. Location: a dedicated `GuardrailService` class — not inline in `ChatService`. It receives the raw question text and returns `{ fired: bool, reason: GuardrailReason | null }`.

### Sensitive topics — escalate before synthesis (never sent to the provider)

These patterns trigger **immediate escalation** regardless of confidence, retrieval quality, or any other signal:

| Topic | Signal patterns (Spanish; case-insensitive) |
|---|---|
| Harassment / acoso | `acoso`, `hostigamiento`, `intimidación`, `abuso`, `denunci` (partial for denuncia/denunciar) |
| Mental health | `salud mental`, `ansiedad`, `depresión`, `burnout`, `estrés severo`, `baja psicológica`, `psicólogo`, `psiquiatra` |
| Disciplinary / termination | `expediente disciplinario`, `sanción disciplinaria`, `despido`, `despedi`, `terminación`, `carta de despido`, `procedimiento disciplinario` |

These patterns are **constants** in `GuardrailService`, not configurable by admins. The rule is intentionally broad-side-conservative: a false positive (escalating a benign question that contains "despido" in a hypothetical context) is far less dangerous than a false negative (synthesising advice about a live disciplinary case). The Spring-6 guardrails UI cannot weaken these patterns — it is additive only.

### No legal / medical advice

Patterns: `consejo legal`, `asesoramiento jurídico`, `abogado`, `demanda judicial`, `recurso legal`, `diagnóstico médico`, `consejo médico`, `tratamiento médico`. Trigger reason: `off_domain`. These questions are escalated; the answer does not attempt legal or medical interpretation even if relevant chunks exist.

### No other-employee-data

Pattern: a question that names a different employee (heuristic: proper-name-like token + context like `salario`, `contrato`, `jornada`, `baja`). This is a best-effort heuristic in 2b-1; the enforcement at the database layer (role-scoped access to `chat_messages`) is Sprint 5. For now the guardrail catches explicit "¿cuánto gana [Nombre]?" style questions. Trigger reason: `off_domain` (protecting another employee's data).

### What "fires regardless of confidence" means in code

`GuardrailService::check()` is called as the **second** step in `ChatService::handleMessage()`, before `/retrieve` and before `/synthesise`. If it returns `fired: true`, the pipeline short-circuits: the trace fragment is written (with `guardrail_check.fired = true, reason = <reason>`), the `chat_message` pair is persisted, and the `escalation_card` is created. The downstream calls to `hr-ai` are never made. There is no code path by which a guardrail-firing message reaches the provider.

---

## 6. Citations

### Chunk → source document + page

The `/retrieve` response carries `{ chunk_id, document_id, page_from, page_to, content, score }` per chunk. After the pipeline decides to surface an answer, `hr-backend` fetches document metadata from the `documents` table (join on `document_id`): `title`, `uuid` (for the external reference), `convenio_id` → `convenios.name`, `authority_level`, `retrieval_status`.

A citation for display contains:
- `document_title` — from `documents.title`
- `document_uuid` — for any future deep-link to the document card
- `page_from` / `page_to` — from the chunk
- `authority_level` — rendered as the appropriate badge (`badge-national`, etc.)
- `snippet` — the first ~120 characters of the chunk `content` (a preview, not the full chunk)

### DB persistence — `message_citations`

One row per cited chunk in the answered assistant turn:

```
message_citations.message_id     → chat_messages.id (the assistant turn)
message_citations.document_id    → documents.id
message_citations.chunk_id       → document_chunks.id  (nullable FK)
message_citations.page_number    → chunk.page_from  (the canonical citation page)
```

### The no-citation rule

An answer with `citation_count == 0` **does not pass the floor**. This is enforced in `hr-backend` step 6 (the AND condition). The prompt design also instructs Claude that it must not make factual claims without citing a provided source — but the code-side check is the hard gate, not the prompt. The prompt failure mode (Claude ignores instructions) results in zero parsed citations, which triggers escalation rather than an unsupported answer. The rule: **uncited answers never reach the employee.**

---

## 7. Trace + history

### `message_traces.trace` — structure per turn

The `trace` JSONB column on `message_traces` (one row per assistant `chat_message`) stores the full structured pipeline record. It mirrors the `retrieval:probe` output shape from 2a:

```json
{
  "profile": {
    "employee_id": "<uuid>",
    "convenio_id": 42,
    "convenio_numero": "20000785011981",
    "territory_id": 7,
    "job_category_id": 15
  },
  "scope_filters": {
    "convenio_id": 42,
    "include_national_law": true,
    "retrieval_status": ["active"],
    "as_of_date": "2026-06-22"
  },
  "router_decision": null,
  "guardrail_check": {
    "fired": false,
    "reason": null
  },
  "retrieval": {
    "eligible_total": 395,
    "k_requested": 8,
    "returned": 8,
    "top_score": 0.633,
    "chunks": [
      { "chunk_id": 123, "document_id": 45, "page_from": 3, "page_to": 4,
        "score": 0.633, "snippet": "Artículo 7.º Vacaciones…" }
    ]
  },
  "synthesis": {
    "provider": "claude",
    "model": "claude-opus-4-5",
    "citation_count": 2,
    "confidence": 0.82,
    "grounding_signal": { "grounded": true, "citation_count": 2, "top_chunk_score": 0.633 },
    "prompt_tokens": 1240,
    "completion_tokens": 310,
    "synthesis_ms": 2180
  },
  "floor_decision": {
    "answer_confidence_floor": 0.65,
    "retrieval_score_floor": 0.40,
    "outcome": "answer",
    "escalation_reason": null
  }
}
```

When the **guardrail fires**, the `retrieval` and `synthesis` blocks are absent (the pipeline did not reach those steps). When the **pre-synthesis floor fires** (no/weak retrieval), the `synthesis` block is absent. The `floor_decision.outcome` is always present.

`router_decision` is `null` throughout 2b-1 (no router). 2b-2 will populate it.

### Population of `chat_sessions` / `chat_messages`

**Sessions.** `hr-backend` looks up the most recent `chat_session` for the authenticated employee where `last_activity_at > now() - 24h`. If found, the new turn is appended to that session; if not, a new session is created. This gives a "continuing conversation" UX without requiring any session-management UI in 2b-1. No session list, no session history search (Sprint 5).

**Messages.** Two rows per turn:
1. `role: user`, `content: <question text>` — written first.
2. `role: assistant`, `content: <answer prose | escalation message>` — written after the pipeline resolves.

The `session_id` FK on both messages provides the history shape.

### Role-scoping-ready shape

The `chat_sessions` table already carries `employee_id`. `chat_messages` join to `chat_sessions`. `message_traces` and `message_citations` join to `chat_messages`. `escalation_cards` carry both `employee_id` and `source_message_id`.

An `hr_agent` query will read escalated sessions via `escalation_cards.chat_session_id` — that join is possible today. Enforcement (the middleware/policy gate that prevents an `hr_agent` from reading non-escalated sessions) is Sprint 5 — but nothing in 2b-1's schema or write logic precludes it.

### Escalation card — decide-and-queue

On every escalate outcome, `hr-backend` creates one `escalation_card` row:

```
escalation_cards
  uuid             → generated
  chat_session_id  → current session
  source_message_id → the user chat_message.id
  employee_id      → authenticated employee
  reason           → sensitive_topic | low_confidence | off_domain
  status           → new
  assigned_to      → null  (board is Sprint 4)
  topic_id         → null  (auto-classification is Sprint 6)
  created_at       → now
```

The card exists and is queryable from turn one; it just has no HR-facing board to land on until Sprint 4. The trace in `message_traces` (linked via `source_message_id → chat_messages.id`) carries full context so the escalation is not information-poor when the board arrives.

---

## 8. Chat UI (`hr-frontend`)

All components are assembled from the design-system token/class vocabulary defined in `hr-docs/design-system.md` and implemented in `hr-frontend/src/index.css`. **No new colour literals, no bespoke per-screen styling.** New visual needs (e.g. a chat-bubble layout not yet in the system) are first added as a token or class to `index.css`, then used. The component list below states which design-system primitives each one composes.

### Screens

**`/chat` — Employee chat screen**

The primary employee surface. Layout: full-height page, message list scrolling in the centre, fixed-bottom input bar.

**`/admin/settings/answer-model` — Admin "Answer model" screen**

A single settings card within the existing admin console shell. Not the first admin screen, but the first to demonstrate the key-handling flow.

### Components

#### `ChatScreen`
Container. Renders `MessageList` + `ChatInput`. Owns the message array in local state; calls `POST /api/chat/message` on submit; appends both the user message and the response to the list.

#### `MessageList`
Scrollable flex column. `ref`-scrolled to bottom on new message. Each entry is a `MessageBubble`.

#### `MessageBubble`
Two visual treatments: **user** (right-aligned, `--accent-weak` background, `--text`, `--radius-lg`) and **assistant** (left-aligned, `.card` surface). Both use `--text-base` type, `--space-4` padding. Assistant bubbles render either `AnswerBlock` or `EscalationBlock` depending on the response type.

#### `AnswerBlock`
The primary answered-turn display:
- Answer prose (`--text-base`, `--text`).
- `CitationList` immediately beneath the prose.
- A `<details>` expander labelled "How I got here" that reveals `TracePanel`.

Design-system elements: `.card`, `--text-base`, `--text-sm`, `--text-muted`.

#### `CitationList`
A numbered list of source citations. Each citation:
- Document title in `--text-sm` weight 500.
- Page reference in `--text-xs --text-muted` (e.g. "p. 3–4").
- Authority badge: `.badge-national` for `national_law`; `.badge-verified` for `official_convenio`; `.badge-review` for `internal_hr_ruling`.
- A subtle left-border rule in `--border`.

No navigation action in 2b-1 (document-card link is Sprint 3).

#### `TracePanel`
Inside the `<details>` expander. Rendered as a `.well` surface with `--text-sm`. Uses the `.timeline` component to walk each pipeline step: Scope resolved → Retrieval (chunks + scores) → Synthesis (model, confidence) → Decision. Each step is a `.timeline-item` with the step name, key metadata, and outcome. Scores are rendered `tabular-nums`. The guardrail step appears only if it fired.

This is the "how I got here" view referenced in the spec. It does not expose the raw API key, the `provider_api_key` field, or any other secret.

#### `EscalationBlock`
For escalated turns. `.card` with `--warning-bg` tint. Badge: `.badge-review` with text "Escalado a Recursos Humanos". Plain prose message in design-system voice: "No estoy seguro/a de la respuesta a esta pregunta, así que la estoy pasando a una persona del equipo de RR. HH."

No trace expander in escalation (the escalation card carries context for HR; the employee does not need the pipeline detail here).

#### `ChatInput`
`.field` wrapper, `.input` textarea (single-line for 2b-1, auto-grow optional), `.btn-primary` send button. Disabled while a response is in-flight (prevent double-submit). Keyboard: Enter submits; Shift+Enter for newline (future). Placeholder: "Escribe tu pregunta sobre convenio, jornada, vacaciones…"

#### `AnswerModelScreen` (admin)
One `.card` with a `.field` for the API key input (type `password`, autocomplete `off`) and status display. Three states:
- **Not configured** — empty `.field` + `.btn-primary` "Guardar clave". Copy: "No hay ninguna clave configurada."
- **Configured** — masked display `••••<last4>` in `--text-muted` + `.btn-ghost` "Rotar clave" (opens re-entry field). Copy: "Proveedor configurado ✓ Clave: ••••1234".
- **In-flight** — button disabled, spinner (the existing `.btn` disabled state).

### Design-system compliance checklist

- [ ] Every colour reference uses a `var(--…)` token — no hex literals in component files.
- [ ] Every interactive element has a visible `:focus-visible` outline (2px `--accent`).
- [ ] Buttons and row actions have hit targets ≥ 36px.
- [ ] Badge text + icon (not colour-only) distinguishes escalation state from answer state.
- [ ] Typography: answer prose is `--text-base` / `line-height: 1.6`; metadata is `--text-sm`; labels/badges are `--text-xs`.
- [ ] Motion: transitions wrapped in `@media (prefers-reduced-motion: no-preference)`.

---

## 9. Test employee profiles (seeded for acceptance criteria)

Sprint 5 brings the directory UI; 2b-1 uses seeders. Three profiles suffice:

| Seed employee | Convenio | Use |
|---|---|---|
| `test-gipuzkoa@example.com` | `20000785011981` — Limpieza Gipuzkoa | Primary acceptance-criteria profile. 395 eligible prose chunks (from 2a harness). Salary question escalates (coverage gap). Spanish question: vacaciones / jornada → should answer. Euskara question: same → should answer (language is not a filter). |
| `test-andalucia@example.com` | `71103505012022` — COEAS Andalucía | The 2026 prose doc is `historical`; only national law chunks return for a current-date query → likely escalates (good for testing floor). Salary question returns real rows (Probe B from 2a). |
| `test-any@example.com` | Any active convenio | For guardrail testing: send a harassment / mental-health / disciplinary question → must escalate before synthesis. |

Each seed employee must have a corresponding `employee` row with `convenio_id`, `territory_id`, `job_category_id`, and `status: active`. A seeded `super_admin` (with `email: admin@example.com`) is required for the answer-model key screen.

---

## 10. `hr-ai` `/synthesise` — endpoint specification

```
POST /synthesise
X-Internal-Token: <shared_secret>
Content-Type: application/json
```

**Request body:**
```json
{
  "question": "¿Cuántos días de vacaciones tengo y cómo se calcula la jornada anual?",
  "chunks": [
    {
      "chunk_id": 123,
      "document_id": 45,
      "page_from": 3,
      "page_to": 4,
      "content": "Artículo 7.º Vacaciones. Durante el disfrute de las vacaciones...",
      "score": 0.633,
      "authority_level": "official_convenio"
    }
  ],
  "provider_api_key": "<decrypted_key_from_hr_backend>",
  "provider_config": {
    "provider": "claude",
    "model": "claude-opus-4-5",
    "endpoint": "https://api.anthropic.com/v1/messages"
  }
}
```

**Response body (success):**
```json
{
  "answer": "Según el Artículo 7 del convenio [Fuente 1], tienes derecho a 30 días naturales de vacaciones...",
  "citations": [
    { "chunk_id": 123, "document_id": 45, "page_from": 3, "page_to": 4 }
  ],
  "grounding_signal": {
    "grounded": true,
    "citation_count": 1,
    "top_chunk_score": 0.633
  },
  "confidence": 0.81,
  "trace_fragment": {
    "provider": "claude",
    "model": "claude-opus-4-5",
    "prompt_tokens": 1240,
    "completion_tokens": 295,
    "synthesis_ms": 2050
  }
}
```

**Response body (provider error):**
```json
{ "error": "provider_error", "detail": "<provider message, no key echoed>" }
```

`hr-backend` treats a `provider_error` response as an escalation trigger (reason: `low_confidence` — the pipeline could not produce an answer). `hr-ai` never logs the `provider_api_key` field — it is stripped from any debug/access logging before the request body is recorded.

### Synthesis prompt design

The prompt is a **constant** in `app/providers/claude.py`, not dynamically assembled in business logic:

```
SYSTEM: Eres un asistente de recursos humanos especializado en derecho laboral
español. Responde ÚNICAMENTE basándote en las fuentes proporcionadas. Cada
afirmación factual debe ir seguida de [Fuente N], donde N es el número de la
fuente de la lista. Si la respuesta no puede fundamentarse completamente en las
fuentes, indícalo explícitamente en lugar de hacer suposiciones.

USER: Pregunta: {question}

Fuentes disponibles:
[Fuente 1] (p. {page_from}–{page_to}): {content}
[Fuente 2] ...

Responde en el mismo idioma que la pregunta. Devuelve tu respuesta en el
siguiente formato JSON:
{
  "answer": "<texto de la respuesta con marcadores [Fuente N]>",
  "cited_sources": [<lista de índices N de las fuentes citadas>],
  "confidence": <número entre 0 y 1>
}
```

`hr-ai` parses the JSON response, maps `cited_sources` indices back to the `chunk_id` / `document_id` / `page_from` / `page_to` from the input list, and returns the structured `SynthesisResult`. If Claude does not return valid JSON, `hr-ai` returns `{ grounding_signal: { grounded: false, citation_count: 0 }, confidence: 0.0 }`, which triggers escalation in `hr-backend`.

---

## 11. `hr-backend` migration — `answer_model_settings`

New migration: `2026_06_22_200001_create_answer_model_settings_table.php`

```
answer_model_settings
  id               bigint PK
  provider         varchar   default 'claude'
  api_key_encrypted text NULL   — Laravel Crypt::encryptString
  key_last_four    varchar(4) NULL
  configured_at    timestamp NULL
  updated_by       bigint FK → admins NULL
  created_at       timestamp
  updated_at       timestamp
```

Single-row table: the app reads/writes the row with `id = 1` (or `firstOrCreate([])`). The `Crypt` façade uses the `APP_KEY` environment variable — the key itself is never committed. Rotation is: decrypt-old / discard, encrypt-new, update `key_last_four` and `configured_at`. Read-back is never offered via any API endpoint.

---

## 12. Documentation changes (in the same change as the code)

- **`data-model.md`** — add a "Population (Sprint 2b-1)" note to:
  - `chat_sessions`: creation/lookup logic; session-continuation window.
  - `chat_messages`: two rows per turn; role values; content for escalation vs. answer.
  - `message_citations`: batch-written on answer turns only; chunk_id FK; `page_number = page_from`.
  - `message_traces`: structure of `trace` JSONB (as per §7 above); `router_decision` is null until 2b-2.
  - `escalation_cards`: 2b-1 creates with `status = new`, `assigned_to = null`; board is Sprint 4.
  - Add note: **role-scoping-ready shape** — `hr_agent` can join via `escalation_cards.chat_session_id`; enforcement is Sprint 5.
- **`architecture.md` §5`** — record the `/synthesise` step: request/response shape; that synthesis is in `hr-ai` and the decision is in `hr-backend`; that `router_decision` is null in 2b-1.
- **`architecture.md` §7`** — record the hardcoded baseline implementation pattern (deterministic regex, short-circuits before provider call).
- **`deploy.md` §1`** — reference only (already added in ADR-0015). Confirm the three checkboxes are stated as deploy-time, not sprint deliverables.
- **`roadmap.md`** — record the 2b-1 / 2b-2 slice split (as the 2a/2b split was recorded in the 2a plan).
- **READMEs** — `hr-ai` (new `/synthesise` endpoint, provider interface, non-secret config keys); `hr-backend` (new chat API route, `ChatService`, `GuardrailService`, `answer_model_settings` migration, two new named config constants).

---

## 13. Assumptions & open questions

**A. Grounding approximation in 2b-1 (known limitation, not a gap).**  
Citation-coverage + retrieval-score threshold is a proxy, not a semantic grounding check. A well-written answer that uses a chunk's content without formally citing it will be escalated (citation count = 0). This is the correct failure mode: too conservative is better than too permissive for a legal-weight tool. The full grounding model (2b-2) verifies each claim against its cited chunk.

**B. Streaming vs. non-streaming answers.**  
2b-1 uses **non-streaming** (request → full response → render). Streaming is a UX improvement (progressive rendering of the answer) but it complicates citation parsing (the structured JSON envelope must be complete before `hr-backend` can evaluate citations and make the floor decision). Streaming is deferred. The chat UI shows a loading state (button disabled, "Pensando…" text) while the response is in-flight.

**C. Test employee profiles.**  
The directory UI is Sprint 5. 2b-1 uses the three seeded profiles in §9. The seed data is dev-only (like the `data/all-files` corpus path — not committed to the main corpus). The seeders run in the dev environment only.

**D. Session management.**  
No session list, no session picker, no session naming in 2b-1. The "most recent session within 24h, else new session" heuristic is sufficient for acceptance criteria. Sprint 5 adds history search and session management.

**E. The escalation card has no board.**  
Cards are created with `status = new` and sit in the DB. Sprint 4 provides the board. An auditor querying `escalation_cards` directly can see them from the first escalation. This is not a gap — the spec explicitly calls this "decide-and-queue only."

**F. Ambiguity: what if `/retrieve` returns chunks but all below `RETRIEVAL_SCORE_FLOOR`?**  
Escalate (floor pre-check fires). `eligible_total` may be > 0 (there are eligible chunks) but none are similar enough to be meaningful retrieval. The floor treats this the same as no retrieval. The trace records `retrieval.eligible_total` (the pre-filter count) and `retrieval.top_score` (the similarity score), so the distinction is visible in the audit trail.

**G. Ambiguity: provider errors (rate limit, auth failure, timeout).**  
`hr-ai` catches all `anthropic` client exceptions and returns `{ error: "provider_error", detail: "..." }`. `hr-backend` treats this as an escalation (reason: `low_confidence`) and does not expose the provider detail to the employee. The provider detail is logged server-side (without the key) for ops visibility.

**H. Ambiguity: concurrent sessions from the same employee.**  
Not addressed in 2b-1. The "most recent session" lookup uses `ORDER BY last_activity_at DESC LIMIT 1`. In practice, ~1,500 employees are unlikely to have concurrent sessions from multiple devices in 2b-1. Sprint 5 can add session disambiguation if needed.

**I. Language of the answer.**  
The synthesis prompt instructs Claude to respond in the same language as the question. A Euskara question returns a Euskara answer where possible; a Spanish question returns Spanish. This is correct per ADR-0006 (language is not a filter — retrieval returns both language chunks; the synthesis model responds in the question language). No special handling needed in `hr-backend`.

**J. What the `retrieval:probe` harness outputs vs. what the pipeline produces.**  
The 2a harness output shape (profile + scope + chunks + scores) was explicitly designed to mirror `message_traces.trace`. The trace §7 above uses the same field names as the harness output. 2b-1's trace is a strict superset (adds guardrail, synthesis, and floor-decision blocks).

---

## 14. What 2b-2 builds on

| Component | 2b-1 state | 2b-2 addition |
|---|---|---|
| `hr-ai /synthesise` | Exists; prose-only input | Used unchanged; router determines the path that feeds it |
| `hr-ai /retrieve` | 2a primitive; used unchanged | Used unchanged (also used post-routing for prose) |
| `hr-backend ChatService` | Single prose path; `router_decision: null` | Router step inserted between scope resolve and `/retrieve`; salary SQL path added |
| `hr-backend GuardrailService` | Hardcoded baseline | The same class; Sprint 6 adds config-driven additive rules |
| `answer_confidence_floor` | Named constant, dev-set | Sprint 6 exposes it via admin UI |
| Chat UI | Message list, answer + citations + trace | Salary answer display, history search (Sprint 5), session list |
| Grounding | Citation-coverage proxy | Full per-claim grounding check (separate LLM verification call) |
