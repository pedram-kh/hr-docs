# HR Platform — Architecture

> Canonical location: `hr-docs/architecture/architecture.md`
> Status: **draft for review**
> Read this together with `data-model.md` (the schema) and the `decisions/` ADRs (the *why* behind each major choice). Where this doc and an ADR overlap, the ADR is the authority on the decision; this doc is the authority on how the pieces fit together.

---

## 1. What we are building

An HR knowledge platform with a conversational front-end for an organisation of ~1,500 employees. It is **not** "a chatbot" — the chat window is one surface over a larger system whose job is to give every employee the answer that is correct **for them**, and to give HR the tools to keep that knowledge accurate and auditable.

The defining problem: the correct answer to an HR question varies by **province, sector, applicable collective agreement (convenio), employee job category, and the date the question concerns**. A confidently wrong answer about labour conditions carries legal weight. So the system is built around getting *scope* right before it ever generates an answer, and around making every answer traceable and defensible.

Primary goals: reduce HR response time, improve answer consistency, and increase the support capacity of the HR team — without an HRIS/Active Directory integration (none is available for this project).

---

## 2. Services & repositories

Four independent git repos, cloned side by side into one local `hr-platform/` folder opened as a single Cursor workspace (see ADR-0008).

| Repo | Stack | Responsibility |
|---|---|---|
| `hr-frontend` | React + Vite + TypeScript | Employee chat UI and the admin console. Talks to `hr-backend` over HTTP. |
| `hr-backend` | Laravel (PHP) | **System of record.** Auth (email OTP), user/directory CRUD, roles & permissions, knowledge-base management, escalation board, audit/provenance logging, chat history, the API for the frontend. **Owns the entire relational schema and all migrations.** Resolves employee scope (deterministic) and calls `hr-ai` for retrieval/reasoning. |
| `hr-ai` | Python + FastAPI | The RAG and reasoning pipeline: embeddings, vector search, the router, answer synthesis, the LLM tagging tier. Reads the registry/scope tables; reads & writes `document_chunks` (the vector table). **Never runs migrations.** |
| `hr-docs` | Markdown | This constitution: architecture, data model, glossary, ADRs, and all sprint specs/plans/reviews. |

Why the Laravel + Python split (full reasoning in ADR-0007): Laravel is excellent for auth, CRUD, roles, and business logic; the RAG/embedding ecosystem lives in Python. Each language does what it is strongest at. The cost is one extra service and a defined contract between them.

### The Laravel ↔ Python contract (high level)

1. The frontend sends an authenticated chat message to `hr-backend`.
2. `hr-backend` resolves the asker's **scope** from their profile (convenio, province, job category, employment type) + the question date. This is deterministic — no LLM — because it carries the legal weight.
3. `hr-backend` calls `hr-ai` with the query **plus the resolved scope filters**.
4. `hr-ai` retrieves, reasons, and returns the answer, citations, confidence, and a structured trace.
5. `hr-backend` persists the message, citations, and trace, and decides (with `hr-ai`'s confidence/guardrail signal) whether to answer or escalate.

---

## 3. Storage — three layers by nature of data

Data is separated by what it *is*, not lumped into one store (see ADR-0006). The single most important storage principle: **salary tables are structured data and are never embedded as vector chunks** — embeddings mangle tabular numbers, and a salary figure must be exact.

1. **PostgreSQL (relational)** — the system of record: registry, vocabulary, documents, people, escalations, chat, traces, audit. Owned and migrated by `hr-backend`.
2. **pgvector (in the same Postgres)** — `document_chunks`: prose chunks + embeddings for RAG, with denormalised scope columns for pre-filtering. Read & written by `hr-ai`. At this corpus size a dedicated vector DB (Pinecone/Weaviate) would be over-engineering.
3. **S3-compatible object storage** — original source files and per-page images. Accessed through a single storage adapter so the bucket/provider can change (incl. MinIO for local dev) without touching callers or schema (ADR-0009).

Structured salary data lives in relational tables (`salary_tables`, `salary_table_rows`) and is **queried**, never retrieved by similarity.

---

## 4. The faceted model (not a tree)

Documents do **not** live at a single position in a fixed hierarchy. Each document carries independent **facet** values — province, sector, convenio, validity window, document type, topic (see ADR-0001). A strict tree breaks on cross-cutting documents: the Estatuto de los Trabajadores applies to everyone (no single province), and a state-level convenio (COEAS Estatal) spans all provinces. Forcing those into one tree means either a wrong parent or duplication that drifts.

Instead: store facets once; render **lenses** on top. A lens is a `GROUP BY` ordering of the facets — "by province" nests province → sector → convenio → documents; "by sector" nests sector → province → convenio → documents; "by validity"; "by topic". The same document appears as a leaf in every lens, reached by a different path, never duplicated.

### Hierarchy UI behaviour (applies to every lens)

This behaviour is **generic** — written once as a single reusable hierarchy component that takes a **lens definition** (the level ordering) as input. It is not reimplemented per lens.

- **Two visual forms**, user-switchable: a **branching graph** (centred apex with drawn links to children, top-down) and an **indented list** (outline). Both render the same data and share expand state.
- **Two-level default**: the apex and its immediate children are shown; everything deeper is revealed on demand.
- **Click to expand / collapse**: any branch node expands one more level on click and collapses on click again. "Expand all" drills to the full tree; "collapse all" reverts. The full tree can be large — the graph form is for focused drill-down (rooted at a chosen node, horizontally scrollable); the list form is for scanning everything.
- **Leaf opens a card**: clicking a final document opens its card (in **both** forms). Branch nodes never open a card; leaves never expand.
- **Lazy load on expand**: in production, a branch fetches its children when first opened rather than rendering the whole corpus up front.

The view form, expand state, and card placement are pure front-end state — they do **not** affect the schema.

### The document card

Each document's card shows: title and scope breadcrumb; facet tags **with inline provenance** (a confirmed tag looks different from an unverified AI-proposed one); validity window with an expiry countdown; retrieval status (active / historical / draft); authority level; version lineage (predecessor/successor); the change-log timeline; and a "test a question against this document" action. Provenance and history come from `tag_events`; lifecycle fields come from `documents`.

---

## 5. The retrieval pipeline

A **controlled pipeline**, not an autonomous multi-agent swarm. Legal sensitivity pushes us toward determinism wherever possible and toward logging every step. Only two steps are real LLM calls; the rest is deterministic glue. Every step writes to the structured trace (`message_traces.trace`), so the trace is a by-product of the pipeline, not an extra feature — and it doubles as an eval/QA dataset.

1. **Scope resolver** *(deterministic, in `hr-backend`)* — from the verified email → profile → convenio, province, job category, employment type, + question date. Produces the eligibility filter. No LLM.
2. **Router** *(small LLM, in `hr-ai`)* — classifies the query: salary-table lookup, prose-policy question, or off-domain.
3a. **Structured lookup** *(deterministic)* — SQL over `salary_tables` / `salary_table_rows` filtered by the employee's job category. Used for salary/numeric questions.
3b. **Vector search** *(deterministic retrieval)* — over `document_chunks`, **pre-filtered by the denormalised scope columns** before similarity ranking.
4. **Synthesise** *(LLM)* — compose a cited answer; record retrieved chunks + scores in the trace.
5. **Guardrail + grounding check** — is the answer grounded in retrieved sources and above the confidence threshold? Pass → answer the employee. Fail / sensitive / off-domain → escalate.

Eligibility rule (from `data-model.md` §11): a document is eligible if it matches the employee's convenio **or** is `national_law` (universal), **and** `retrieval_status = active`, **and** the question date falls within its validity window. `historical` documents answer time-scoped questions but are never cited as current.

> **Sprint 2a built the deterministic substrate (steps 1, 3a, 3b only).** The router (2), synthesise (4), and guardrail (5) — every LLM step — are **2b**.
> - **3b — Vector primitive (`hr-ai POST /retrieve`).** Request: `{ query, convenio_id?, include_national_law, retrieval_status[], as_of_date?, k }`. `hr-ai` embeds the query (BGE-M3/1024), applies the scope `WHERE` on the denormalized `document_chunks` columns, ranks by cosine with a **forced exact (flat) scan** so the ANN/HNSW layer can never drop an eligible chunk (full recall — a legal-weight requirement), and returns `{ chunks:[{ document_id, chunk_index, page_from, page_to, content, score, authority_level, … }], eligible_total }`. It does **no** routing or answering.
> - **3a — Salary primitive (deterministic SQL in `hr-backend`).** `salary_tables` for the convenio + year → `salary_table_rows` filtered by job category. Never embedded (ADR-0006). A convenio with no rows yet returns a **visible coverage-gap** signal, not a blank (ADR-0014).
> - **1 — Scope resolver (`hr-backend`).** Resolves convenio/territory/sector/validity/job-category from the profile and **passes** scope to `hr-ai`; `hr-ai` never derives scope (ADR-0007).
> - The **`retrieval:probe`** command exercises all three for a profile + question + date (resolved scope, eligible chunks with scores + source, eligible salary rows), seeding 2b's `message_traces.trace` shape.
>
> **Sprint 2b-1 built the narrow answer vertical (steps 4 + 5, prose only — no router yet).** The single prose path is: scope resolve → **guardrail baseline** (deterministic, `hr-backend`, fires *before* any `hr-ai` call) → `/retrieve` → **pre-synthesis floor** (Check A) → `/synthesise` → **answer-or-escalate decision** (`hr-backend`) → persist. The router (step 2) and salary-in-chat (3a) arrive in 2b-2.
> - **4 — Synthesise (`hr-ai POST /synthesise`, ADR-0015).** Request: `{ question, chunks:[{ chunk_id, document_id, page_from, page_to, content, score, authority_level }], provider_api_key, provider_config:{ provider, model, endpoint } }`. The provider is **pluggable** (default Claude); the decrypted key arrives in the **body** (never a header) per call and is never persisted/logged by `hr-ai`. Returns `{ answer, citations:[{ chunk_id, document_id, page_from, page_to, authority_level }], grounding_signal:{ grounded, citation_count, top_chunk_score }, confidence, authority_used:[…], trace_fragment }`. On a provider failure it returns `{ error:"provider_error", … }` (key never echoed) so `hr-backend` escalates cleanly.
>   - **Synthesis abstains rather than fabricates (Correction-01).** The prompt forbids using general knowledge of Spanish labour law to fill gaps: a claim may appear **only** if a supplied chunk states it directly, and a citation must point to the chunk that *actually* states the claim (never the nearest lookalike). When the supplied chunks don't answer the question, synthesis either answers from a `national_law` chunk that does (cited to the Estatuto) **or** abstains — returning empty `cited_sources` + low confidence so `hr-backend` escalates. It never asserts a convenio rule the convenio chunks don't contain.
> - **Authority precedence (legal-weight, the core failure mode it prevents).** `hr-backend` orders **convenio chunks before `national_law`** in the payload and labels each with its `authority_level`; the synthesis prompt encodes the rule explicitly — *the employee's convenio governs the topics it addresses; the Estatuto (`national_law`) is only the baseline that applies where the convenio is silent.* On genuine conflict the convenio's answer is surfaced as governing (the Estatuto noted as general baseline), or the turn escalates (`low_confidence`) if the model cannot tell which governs — **never blend, never silently present the baseline as the answer.** The trace records `authority_used` so an auditor sees *answered from the convenio* vs *answered from the baseline*. (This is the safe floor; full *norma más favorable* / territory-hierarchy adjudication remains later work.)
> - **5 — Answer-or-escalate decision (`hr-backend`, ADR-0007).** Deterministic, legal-weight, owns the call. The load-bearing gates are **Check A** (top retrieval score ≥ `RETRIEVAL_SCORE_FLOOR`) and **Check B** (citations present *and* every cited `chunk_id` was in the provided set — hallucinated citations rejected). **Check C** (the model's self-reported confidence vs `answer_confidence_floor`) is **not** a primary gate — LLM self-confidence is poorly calibrated (the ADR-0015 risk); it is kept in the trace and used only as a tiebreaker, never to pass an answer A/B did not already support. Answer only when A **and** B pass; otherwise escalate (`low_confidence`), never guess. A no/weak-retrieval, salary, or off-domain question lands in escalation without a router because Check A (or B) fails. Both floor constants are **named config** (`config/hr.php`), conservative, Sprint-6-exposable but additive-only (raise, never lower).
> - **Figure-grounding guard (deterministic backstop to Check B, Correction-01).** Before surfacing an answer that passed A∧B, `hr-backend` extracts every **load-bearing figure** (a number with a unit — *N días/meses/horas/años/semanas*, currency) and verifies each appears in at least one **cited** chunk's text (digit *or* spelled-out Spanish form, so "treinta días" grounds "30 días"). A figure absent from all cited chunks in both forms → escalate (`low_confidence`, trace note *"answer figure not grounded in cited chunk"*). It is conservative — it fires only when a figure is entirely absent, and the action is always escalate (the safe direction), never a silent edit. This is a cheap backstop, **not** the full per-claim entailment grounding model (that remains 2b-2).

---

## 6. AI-assisted tagging (advisory, never autonomous)

Tagging and placement are the **same action** under the faceted model: assigning facet values *is* placing the document into every relevant lens. The tagging agent has **two tiers**:

- **Deterministic parser** (the clean majority) — Sedena's filenames already encode the facets, e.g. `01100635012017_OCIO_EDUCATIVO_ALAVA_20232026_Tablas_2026` → province `01` (Álava), convenio number, sector, validity, doc type. A rules/regex parser extracts these at ~100% reliability and near-zero cost.
- **LLM tier** (the messy tail) — for documents without structured filenames (resolved-escalation articles, ad-hoc uploads, scans): read the content and **propose** facets with a **confidence score**.

Non-negotiable rules (ADR-0002):
- The agent **proposes**, a human **confirms**. It never silently decides on low confidence — those go to a review queue (`documents.tagging_status`).
- Scoping facets are a **closed controlled vocabulary** (FK into vocabulary tables) — invalid tags are structurally impossible. The agent can never create a new province/sector/convenio value.
- Topics are a **managed vocabulary** seeded from the FAQ categories — the agent may *propose* a new topic (`status = proposed`); only an admin approves it.
- **Conflict detection beats confidence**: the agent cross-checks parsed tags against the `convenios` registry. A filename that parses to one province but sits in another province's folder, or a convenio number absent from the registry, is a *conflict* → review, even if each field was individually confident. A confidently-wrong tag is the dangerous kind.
- Every tag decision is written to `tag_events` with source, actor, confidence, and prior value.

---

## 7. Guardrails

Layered (ADR-0002 covers the vocabulary side; guardrails are configured in the admin console):

- **Hardcoded baseline** (admins cannot weaken): no legal/medical advice, no answers about other employees' data, immediate human escalation for sensitive topics (harassment, mental health, disciplinary/termination).
- **Admin-configurable, additive only**: off-domain refusal ("I only handle HR topics"), blocked topics, the confidence threshold below which the bot escalates instead of answering, tone constraints.

> **Sprint 2b-1 built the hardcoded baseline as `GuardrailService` (`hr-backend`), not inline.** It runs deterministically as **step 2 of the pipeline — before `/retrieve` and before any `hr-ai` call**, so a firing question never reaches the external provider (a safety guarantee *and* a privacy bonus: a harassment/mental-health message never leaves our servers). It fires **regardless of confidence or retrieval quality**. The sensitive-topic / legal-medical / other-employee patterns are intentionally **conservative (broad)** — they will escalate some legitimately-answerable questions (e.g. "preaviso por despido en mi convenio"); for a legal-weight tool a false escalation is far cheaper than synthesising advice on a live disciplinary matter, so the patterns are deliberately not narrowed. The admin-configurable layer (raise-only) is Sprint 6.

---

## 8. Escalation flywheel

When the pipeline can't answer confidently — or the topic is sensitive, off-domain, or the asker requests it — an `escalation_card` is created carrying the conversation context and trace. HR works it on a board (New → Assigned → In Progress → Resolved). On resolution, HR is prompted to **turn the answer into a knowledge article**: a new `documents` row, `authority_level = internal_hr_ruling`, that **inherits the original asker's scope** (scope confirmed before publish). This makes the system smarter with use. Authority rule: an internal ruling must not override an official convenio for the same scope — on conflict, re-escalate.

---

## 9. Authentication & sessions

Passwordless **email OTP** (not SSO — there is no identity provider to federate with; ADR-0003). Email is both the auth key and the profile-lookup key. Codes are 6-digit, short-TTL, single-use, hashed at rest, rate-limited per email and per attempt. Sessions use Laravel Sanctum bearer tokens with a **~24h ("daily") TTL** (ADR-0005). Delivery via **Postmark**. Admins use the same OTP flow.

Because no HRIS/AD sync exists (ADR-0004), the directory is a first-class part of this platform: full manual CRUD + CSV bulk upload + search/filter, every profile change written to `employee_audit_log`, and a `profile_last_reviewed_at` staleness signal. Editing an employee's email changes how they log in — surfaced in the UI.

---

## 10. Admin console modules

1. **Knowledge Center** — folder/batch upload (preserving the existing province-folder grouping), the two-tier tagging agent, the lens hierarchy (§4), document cards, the "test a question" sandbox, and the expiry review queue with successor lineage.
2. **Escalation Board** — §8.
3. **User & Directory Management** — §9.
4. **Guardrails Configuration** — §7.
5. **Analytics** — deflection rate, top questions, unanswered questions, escalation volume by topic, satisfaction, and coverage-gap detection (province × sector combinations that have employees but no current document).

---

## 11. Privacy

Employee questions can reveal sensitive personal facts, so: GDPR-grade handling, encryption, retention limits, and **role-scoped access to conversations** — an `hr_agent` sees escalated conversations, not every employee's full history. Spain's AEPD and works-council (comité de empresa) considerations may apply before deployment. Roles via `spatie/laravel-permission`: `super_admin`, `hr_agent`, `knowledge_editor` (no chat access), `auditor` (read-only).

---

## 12. Build order (sprints — high level)

0. Scaffold the three code repos, Postgres + pgvector, the schema migrations from `data-model.md`, email OTP end to end.
1. Knowledge ingestion: folder upload, deterministic filename parser, vocabulary-validated tagging, the lens hierarchy + cards.
2. Scoped RAG chat: chunking, the retrieval pipeline, citations + trace, history.
3. Escalation board + resolution-to-article flow.
4. Admin: user CRUD, bulk upload, search/filter, audit log, roles.
5. Guardrails config UI (baseline hardcoded back in sprint 2).
6. Analytics + expiry review queue + the LLM tagging tier.

The LLM tagging tier comes last — the deterministic parser covers the clean majority, so the system ships value without it.
