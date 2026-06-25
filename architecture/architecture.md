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

### Knowledge Center — bounded edit, append-only provenance, read-only sandbox (Sprint 3)

The lens hierarchy (§4) and document card are realized in the admin console as **Knowledge → Map**. Three rules govern this surface:

- **Bounded edit, never restructure.** The card edits only a fixed set of facets — convenio, document_type, topics, validity, retrieval_status, tagging_status — and only via **FK pickers into the existing controlled vocabulary**. Territory and sector are **derived** from the convenio and are not editable. The UI never creates, renames, merges, re-scopes, or approves vocabulary; that stays in the deliberate `registry:import` path (ADR-0011). The topic picker offers only `approved` topics; AI topic *proposal* is Sprint 7.
- **Append-only provenance.** Every label edit is a `DB::transaction` that writes the lifecycle column **and** appends an `admin_manual` row to `tag_events` (old→new, actor_id). History is never UPDATE/DELETE-d. A **scope-affecting** save (convenio reassign, retrieval_status flip, validity edit — each moves *which employees receive the document*) requires an explicit `confirm_scope_change` (the UI shows a system-behavior warning; the API returns 409 without it). Sprint 3 is the **first writer of `document_topics`** (human topic-tagging).
- **Read-only sandbox; the answer loop is frozen.** "Test a question against this document" reuses the answer pipeline scoped to one document via an additive, read-only hr-ai `POST /sandbox-retrieve` (ranks `document_chunks WHERE document_id = :id`) orchestrated by an **independent `SandboxService`** (retrieve → /synthesise → /ground, same per-call answer-model key path). It **persists nothing** — no chat/escalation rows. `ChatService` and the employee `/retrieve` are **not** modified (the 2b answer loop carries legal weight and is durably closed); the small orchestration duplication in `SandboxService` is accepted on purpose. hr-ai remains read-only here (writes only `document_chunks` + S3, never migrates).

Coverage gaps (deploy.md §5) are surfaced as first-class map markers, derived from existing data (active-but-0-chunk = unanswerable; expired-with-no-active-successor = a hole; convenio-text named like a salary "tabla" = suspected mistag). A `date_expired_active` *staleness* signal is kept **distinct** from a hole (it is `--neutral`, not `--warning`/`--danger`) so an answerable scope is never mislabeled as a gap.

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
> - **Figure-grounding guard (deterministic backstop to Check B, Correction-01).** Before surfacing an answer that passed A∧B, `hr-backend` extracts every **load-bearing figure** (a number with a unit — *N días/meses/horas/años/semanas*, currency) and verifies each appears in at least one **cited** chunk's text (digit *or* spelled-out Spanish form, so "treinta días" grounds "30 días"). A figure absent from all cited chunks in both forms → escalate (`low_confidence`, trace note *"answer figure not grounded in cited chunk"*). It is conservative — it fires only when a figure is entirely absent, and the action is always escalate (the safe direction), never a silent edit. This is a cheap backstop, **not** the full per-claim entailment grounding model (that arrives in 2b-2, which demotes this to a pre-check).
>
> **Sprint 2b-2 completed the answer surface (the router + salary-in-chat + the per-claim grounding gate + recall hardening).** The full path is: scope resolve → **guardrail baseline** → **router** → {salary SQL | prose | off-domain} → persist (`router_decision` now populated).
> - **2 — Router (`hr-ai POST /route`, ADR-0016).** A small/fast model (`ROUTER_MODEL`, same ADR-0015 key path) labels the question `salary` \| `prose` \| `off_domain` and, for a **compound** question, returns decomposed `subqueries`. A **deterministic salary pre-classifier** in `hr-backend` (`RouterService`, the patterns moved out of `GuardrailService`) short-circuits obvious salary to the SQL path with no LLM call. **Fail-safe:** low confidence / provider error / no key → the safe **prose** path; a confident `off_domain` escalates. Runs *after* the guardrail baseline (sensitive / other-employee never reach it). Decision + confidence + source land in `trace.router_decision`.
> - **3a — Salary-in-chat (`SalaryAnswerService`, SQL; ADR-0006).** A salary route resolves the job category (profile → else a **constrained single-turn pick** from the convenio's `convenio_job_categories`, FK-validated, unverified, shown as *"según tu indicación"*) and the **as-of year** (most-recent `salary_tables` row with `year ≤ as-of`; a future-only table → escalate, never quote a not-yet-effective figure), then returns the **exact typed `salary_table_rows` cell** stating category + year and citing the salary-table source document (`message_citations.chunk_id = null`). No SQL row → escalate **`salary_coverage_gap`** (supersedes Correction-02's `salary_not_in_chat`). Salary answers are SQL-grounded by construction and **skip** `/ground`.
> - **5 — Per-claim grounding gate (`hr-ai POST /ground`, the real gate).** For a prose answer, the **capable answer model** (not the cheap router — entailment is subtle) decomposes the answer into atomic claims, **classifies each substantive vs provenance**, and checks each *substantive* claim for entailment against the **cited** chunks; **table-aware** (digit-presence ≠ entailment — Q5's lesson). Any ungrounded substantive claim → escalate (`low_confidence`), the failing claim recorded in the trace. The gate is **Check A ∧ Check B ∧ figure-guard pre-check ∧ entailment**; the figure-guard short-circuits an absent figure *before* spending the `/ground` call.
>   - **Substantive vs provenance (Correction-01, 2b-2).** A *substantive* claim is the answer content — a rule, figure, entitlement, duration, condition, or scope; these **must** be grounded. A *provenance/attributive* claim only states **where** the answer comes from ("según tu convenio", "esto está en el Estatuto"); provenance is the **citation's** job ([Fuente N] markers + authority badges + the *Fundamentado en…* footer), so a pure provenance sentence is **not** subject to entailment. Two coordinated halves: (a) **synthesis** is instructed to state substantive content and let the citation carry the origin — it does **not** write provenance meta-sentences (precedence is expressed by *which source each substantive claim cites*, not by prose); (b) **`/ground`** exempts only **pure** provenance statements. **Precision guard:** anything carrying substantive content is substantive even under an attributive wrapper ("tu convenio te da **31 días**" → the *31 días* is substantive and must be entailed); anything not explicitly tagged provenance defaults to substantive, so a fabricated figure can never escape the gate by being mislabelled. This removed an over-escalation where a true-but-unentailed meta-sentence ("esta cifra está en tu convenio") sank an otherwise-grounded answer, without re-opening the fabrication hole.
>   - **Every substantive claim must carry a citation (Correction-02, 2b-2).** Synthesis must attach a `[Fuente N]` to **each** substantive claim (not just one citation per paragraph), and if **no** provided chunk supports a claim it must **omit** the claim rather than state uncitable content. Rationale (traced from a compound over-escalation): synthesis was stating on-topic, *retrievable* facts (e.g. a convenio's "§9.3 vacaciones adicionales" sub-clause, present in a retrieved-and-unioned chunk) **without citing them** → those claims arrived at `/ground` with `supporting_source: null` and were correctly gated → a genuinely answerable turn escalated. The fix is in **synthesis** (bind a citation to every substantive sentence; drop the uncitable). **Union-check rejected (and why):** an alternative was to have `/ground` verify each claim against the **full retrieved union** rather than the **cited** subset. We rejected it — it would sever the claim-from-citation binding and break the audit chain (a surfaced fact would no longer be traceable to the specific source the answer points the employee to). The gate **keeps checking each claim against its own cited chunk**; the answer is what must improve its citations, not the gate that must relax its scope.
> - **Recall hardening (`hr-backend`, §6/Q10).** The prose path issues `/retrieve` for the question **plus each decomposed sub-query plus a national-law-only pass**, then unions (dedupe by `chunk_id`, keep max score). This keeps a compound question from diluting into a baseline flip (Q10) and surfaces the on-topic Estatuto article for a silent-convenio topic. `/retrieve` is unchanged. The LLM decomposition is primary; a deterministic conjunction/clause pre-split is the built-in safety net when the router returns no sub-queries for a compound question.
> - **Citation numbering (§7).** `hr-ai` renumbers the in-text `[Fuente N]` markers to the **cited subset** (1..M, in citation order) so a marker always resolves 1:1 to the displayed FUENTES list.
>
> **Correction-03 (2b-2, pre-commit) — three retrieval/routing fixes upstream of the gate.** The per-claim entailment gate is **structurally blind** to these classes: each defect lives in *which chunk reaches synthesis* (ranking) or *which path a turn takes* (routing), both upstream of `/ground` — a wrong-authority figure is still genuinely entailed by its cited chunk, so no grounding-prompt change can catch it. All three are in `hr-backend`.
>   - **Widened-pool precedence re-rank (Fix 1, the baseline-over-convenio class).** *Bug (review.md §15):* a governing convenio chunk that ranks low by raw cosine (Navarra 7721, *"37 días laborables"*, buried at a chunk tail behind tiempo-parcial text → ~#15) was discarded at the old `k=8` before any re-rank, while the Estatuto vacaciones chunk (7305, *"30 días naturales"*) ranked ~#3 and reached synthesis — so the answer gave the **national baseline for a convenio-governed topic**. *Fix:* the recall-hardening union retrieves a **wider pool per pass** (`retrieval_pool_k`, ~25) *before* truncation; then a **precedence re-rank** — for each governing (`official_convenio`/`internal_hr_ruling`) chunk that shares a **topic** with a `national_law` chunk in the pool (topic = shared anchor term from an HR topic lexicon: vacaciones, jornada, permisos, excedencia, periodo de prueba, trabajo a distancia, …), lift its effective score just above the highest same-topic baseline, so the convenio's governing chunk **displaces** the baseline in the truncated set. **Both directions hold:** where a convenio chunk on the topic exists it now reaches synthesis and the answer cites the convenio (verified: Navarra vacaciones answers **37 días laborables cited to 7721**, *never* 30/Estatuto across 20+ runs); where the convenio is genuinely **silent** (no convenio chunk carries the topic anchor — e.g. trabajo a distancia) there is nothing to promote, so the `national_law` chunk is **left untouched to fill the gap** (verified: trabajo a distancia still `national_law`). The **synthesis cap grows with the number of sub-queries** (`SYNTHESIS_CHUNK_CAP + 2·|subqueries|`) so a **compound** union isn't truncated below its parts' recall (the buried grant lands ~#12 once vacaciones + periodo-de-prueba chunks share the pool); a single-topic question is unchanged at the base cap. This re-rank is the **interim compensation** for the buried-grant chunking artifact; the **durable fix is the article-boundary re-chunk parked in roadmap §7**.
>   - **Aggregation / vague-total escalates (Fix 2).** A *"¿cuántos días libres me dan al año en total?"* asks to **sum** across leave types (vacaciones + festivos + permisos + asuntos propios) — an arithmetic aggregation, not a single grounded fact; summing leave types is unsupported synthesis even with the right figures in hand. A **narrow deterministic detector** (a *generic* leave phrase — "días libres"/"días de descanso" — **AND** a total/aggregation marker) escalates the turn (`low_confidence`) **before retrieval**; a concrete single-topic question ("¿cuántas vacaciones tengo?", "¿qué permisos tengo?") never trips it.
>   - **Cross-path salary+prose compound (Fix 3).** The deterministic salary pre-classifier previously short-circuited the **whole** turn to the SQL path on any salary keyword, **silently dropping** a prose clause in the same question (Q10). Now, when a salary pattern matches **but** the question also has a clear non-salary clause (deterministic split → ≥1 salary clause **and** ≥1 substantive non-salary clause, e.g. *"¿cuánto cobra un peón y cuántas vacaciones tiene?"*), the turn is flagged `cross_path` and **escalated-with-note** ("la parte salarial puedo consultarla en las tablas, pero … vacaciones … necesita una persona") so the prose half is **surfaced, never silently dropped** — the implemented minimum bar; full per-clause decomposition (salary→SQL ∧ prose→prose, compose both) is the richer follow-up.
>
> **Sprint 2c — article-boundary re-chunk (the durable buried-grant fix; ADR-0017).** A **substrate** change: only the chunker (`hr-ai/app/chunking/chunker.py`) changed; the answer loop (router, retrieval union, **precedence re-rank**, grounding, synthesis) and `hr-backend`/`hr-frontend` are untouched, and `hr-ai` still writes only `document_chunks` + S3 and never migrates (ADR-0007). 2a *packed* small consecutive articles up to a token target; that packing **buried** the Navarra Art. 9.º *Vacaciones* grant ("37 días laborables") inside a chunk dominated by a neighbouring article (Art. 8.ºbis), so it embedded weakly and ranked ~#15 against a vacaciones query — the Correction-03 artifact. 2c makes **each detected article its own chunk and removes cross-article packing**; only an article exceeding the size cap (`chunk_token_cap` = **800** tok; preamble/fallback target **512**) is sub-split — on a sub-clause/paragraph/sentence boundary, **never mid-sentence** — with its `Artículo N.º <título>` header **carried onto every sub-chunk** so it still resolves to (and cites as) its article. The pre-Article-1 preamble and anchor-less annexes keep the size-capped paragraph fallback. Because packing no longer masks loose detection, the header **detector is now load-bearing**, run with **three precision guards**: (1) **line-anchored** — a header must start its line, so an inline "…del artículo 22…" cannot anchor; (2) **case-aware** — real headers are capitalised (`Artículo`/`Art.`/`ART`); a lowercase candidate is rejected unless it also passes the number check; (3) **monotonic-number** — a lowercase candidate is accepted only if its number is ≥ the running article number, so "artículo 22 del ET" inside Art. 40 can never spawn a chunk. The detector covers the surveyed variants (`Artículo N` · `Art. N.º` · `Artículo N.—/–/-` · the Salamanca uppercase `ART N.-` · `N. artikulua` (Euskara) · `Disposición adicional/transitoria/final/derogatoria` · a defensive spelled-out clause), and a decimal/letter sub-clause (`9.1`, `13.a`) stays with its parent article while a `bis` header gets its own chunk. It **composes with — never replaces** — the 2a extraction front-end: geometry de-spacing, repetition/margin-band furniture stripping, positive-evidence two-column detection, the Spanish-function-word language gate, and language tagging all run **before** chunking and are unchanged (the chunker still receives one already-separated language stream at a time). With the clean vacaciones chunk now ranking into the synthesis cap on its **own raw score — before** the precedence boost — the Correction-03 re-rank is reclassified from **interim load-bearing to defence-in-depth** (it stays; no answer-loop change). Re-chunk was applied to the **registry-active convenio set only** (the Estatuto national-law baseline, id 73, is deliberately held out — see `roadmap.md` and ADR-0017).

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

- **Hardcoded baseline** (admins cannot weaken): no legal/medical advice, no answers about other employees' data, immediate human escalation for sensitive topics (harassment, mental health, disciplinary/termination). *(Salary is no longer blocked here — as of 2b-2 it is answered in chat from `salary_tables` via SQL, see below.)*
- **Admin-configurable, additive only**: off-domain refusal ("I only handle HR topics"), blocked topics, the confidence threshold below which the bot escalates instead of answering, tone constraints.

> **Sprint 2b-1 built the hardcoded baseline as `GuardrailService` (`hr-backend`), not inline.** It runs deterministically as **step 2 of the pipeline — before `/retrieve` and before any `hr-ai` call**, so a firing question never reaches the external provider (a safety guarantee *and* a privacy bonus: a harassment/mental-health message never leaves our servers). It fires **regardless of confidence or retrieval quality**. The sensitive-topic / legal-medical / other-employee patterns are intentionally **conservative (broad)** — they will escalate some legitimately-answerable questions (e.g. "preaviso por despido en mi convenio"); for a legal-weight tool a false escalation is far cheaper than synthesising advice on a live disciplinary matter, so the patterns are deliberately not narrowed. The admin-configurable layer (raise-only) is Sprint 6.
>
> **Salary routing (2b-2, supersedes the Correction-02 salary guard).** The deterministic salary patterns (`salario`, `sueldo`, `nómina`, `retribución`, `tablas salariales`, `cuánto gano/cobra…`; the bare verb "paga" deliberately *not* matched) moved from `GuardrailService` to **`RouterService`** as a high-precision pre-classifier. They no longer **escalate** — they **route** an obvious salary question to the SQL salary path (answered from `salary_tables`, exact + year-aligned per ADR-0006), without an LLM call. A genuine coverage gap escalates **`salary_coverage_gap`** (the renamed reason; `salary_not_in_chat` is retired — it would now lie, since salary *is* in chat). The named-third-party privacy rule still fires *first* in the guardrail baseline, so "¿cuánto gana Pedro García?" stays `other_employee_data` and never reaches the salary path. *Known residual (accepted):* the 2a wage-table prose chunks remain in the index; a non-salary prose question could still retrieve one, but the **table-aware per-claim grounding gate** now rejects an answer that leans on digit-presence in a tabular chunk (Q5's lesson). The deeper 2a fix (excluding wage-table pages from prose chunking) is parked.

---

## 8. Escalation flywheel

When the pipeline can't answer confidently — or the topic is sensitive, off-domain, or the asker requests it — an `escalation_card` is created carrying the conversation context and trace. HR works it on a board (New → Assigned → In Progress → Resolved). On resolution, HR is prompted to **turn the answer into a knowledge article**: a new `documents` row, `authority_level = internal_hr_ruling`, that **inherits the original asker's scope** (scope confirmed before publish). This makes the system smarter with use. Authority rule: an internal ruling must not override an official convenio for the same scope — on conflict, re-escalate.

### 8.1 The board (Sprint 4)

`hr-backend` owns every write; `EscalationController` exposes the board. Reads (`GET /admin/escalations`, `GET /admin/escalations/{uuid}`) are open to any admin; writes (assign/move/reply/resolve) are gated by the **`escalation.work`** ability (super_admin + hr_agent; denied to auditor and knowledge_editor). Status moves are **server-validated** against a legal-transition map, and **every** status change, assignment, reply, resolution, conversion, and blocked publish is appended to **`escalation_events`** (append-only — never an UPDATE/DELETE of history; the same posture as `tag_events`).

**Card-scoped conversation viewing only.** The card detail serves the messages of `card.chat_session_id` and the escalating turn's trace — keyed strictly to the card's own session, with **no caller-supplied employee/session parameter**. That keying *is* the access guard this sprint. There is no full-history browser and no role-scoped-access enforcement yet (Sprint 5).

### 8.2 The two-way surface — a human reply into the employee's chat

The chat becomes two-way via a new message-author kind. `chat_messages.role` gains **`hr_agent`** (additive CHECK migration) plus a nullable **`author_admin_id`** FK → `admins`. An `hr_agent` message is a **human turn**: no `message_traces`, no `message_citations` (it is not a synthesised answer). The reply endpoint writes the `hr_agent` message into the card's session and audits a `replied` event.

The employee sees it through `GET /chat/session` (employee-auth, **self-scoped** to the caller's own most-recent session). The chat screen hydrates on mount and **polls (~25 s) + re-hydrates on window focus** — no websockets, no notifications this sprint. A human reply is attributed as **"Recursos Humanos"** with a distinct bubble (`chat-bubble--agent`) — **never the admin's name/email/PII**, and never mistakable for a bot answer. (The admin board view shows the authoring admin's name — internal attribution.)

A follow-up question continues the same session (the card-scoped view shows it, since it is session-bound); a follow-up that independently escalates creates a **new** card. No auto-reopen/threading.

### 8.3 Save-as-knowledge — publish-time enforcement of the no-silent-override rule

On resolve, HR may convert the resolution into a published `internal_hr_ruling`. Two non-negotiable gates fire, in order:

1. **Scope-confirm (409).** Publishing inherits the asker's convenio (territory + sector ride it) and changes who is answered, so it cannot publish without `confirm_scope_change` — reusing the Sprint-3 bounded-edit confirm pattern. The ruling is **single-convenio scoped**; a national/multi-scope ruling is out of scope (Structured Reference Knowledge, Sprint 7).
2. **No-override conflict fence (409).** A conservative **scope+topic** block: publish is blocked when an **active `official_convenio`** in the asker's convenio scope shares the ruling's assigned topic. The agent assigns a topic at publish (the Sprint-3 approved-topic picker) — this sharpens the gate and seeds the topic lens. With **no topic**, the gate falls back to a **scope-only** block (any active official convenio in scope → block) — it over-blocks, the *safe* failure direction (a false block routes to a human; a false "no conflict" is the exact harm). On a block: nothing is published, a `publish_blocked` event is recorded, and the card is returned to **In Progress** (routed to a human). The rule is **enforced at publish — never advisory**.

The guardrail baseline still fires first: a published ruling on a genuinely sensitive topic is unreachable, because the 2b-1 guardrail escalates *before* retrieval. (Whether to restrict `convert` by escalation reason is a Sprint-6 guardrails-policy matter — noted, not built.)

### 8.4 A1 — how a published ruling becomes retrievable (hr-ai untouched)

The ruling is made retrievable through the **existing** ingestion path, with **no change to hr-ai or the 2b answer loop** (both frozen/durably-closed). `hr-backend` renders the agent's `resolution_text` to a clean, deterministic PDF (`RulingPdf` — single column, no header/footer, WinAnsi encoding, widened word-spacing, no end-of-line hyphenation), stores it to S3, sets `storage_path`, then runs the same `/extract` (→ `document_pages`, the citation surface) and `/embed` (→ `document_chunks`, scope resolved by hr-backend) calls every prose document uses. `internal_hr_ruling` was added to `ChunksEmbed::IN_SCOPE_TYPES` (a code change, not a migration). The PDF is engineered so the 2a heuristics (de-spacing, repetition-furniture, two-column detection) cannot mangle it; **publish verifies the round-trip is lossless** (embedded chunk text == typed text, whitespace-normalised) and reports it — if a clean PDF were ever mangled, the documented fallback is A2 (a tiny additive hr-ai inline-text branch). The ruling's chunks carry `authority_level = internal_hr_ruling` and flow through the unchanged retrieve/synthesise/ground path.

### 8.5 Known boundary (Q-F) — the symmetric convenio-side re-check

The publish gate covers conflicts **at publish time**. A convenio added or edited **later** that conflicts with an already-published ruling is **not** auto-demoted at retrieval (2b precedence is frozen; precedence treats a ruling as governing, like a convenio). This is an accepted, documented boundary. The symmetric check — publishing/editing an `official_convenio` should re-scan existing rulings for new conflicts — belongs in a later precedence/guardrails sprint and is largely latent until a self-service convenio-upload path exists.

---

## 9. Authentication & sessions

Passwordless **email OTP** (not SSO — there is no identity provider to federate with; ADR-0003). Email is both the auth key and the profile-lookup key. Codes are 6-digit, short-TTL, single-use, hashed at rest, rate-limited per email and per attempt. Sessions use Laravel Sanctum bearer tokens with a **~24h ("daily") TTL** (ADR-0005). Delivery via **Postmark**. Admins use the same OTP flow.

**Status enforcement (Sprint 5).** Both OTP paths refuse an account whose `status` is not `active` — a deactivated **admin or employee** is treated as not-registered (no code sent, no code redeemable). Deactivation also **revokes the account's outstanding Sanctum tokens**, and the `EnsureActiveAccount` middleware refuses any authenticated request from an inactive account — so access ends immediately, not in up-to-24h, and a deactivated employee can no longer chat.

Because no HRIS/AD sync exists (ADR-0004), the directory is a first-class part of this platform: full manual CRUD + CSV bulk upload + search/filter, every profile change written to `employee_audit_log` (one row per changed field, in the same transaction — `EmployeeAuditLogger`), and a `profile_last_reviewed_at` staleness signal set by an explicit **mark-reviewed** attestation (an edit does *not* bump it). Scope fields are **FK pickers into existing vocabulary only** (no vocabulary creation — that stays in `registry:import`/`salary:import`, ADR-0011), with a convenio-scoped `job-categories` endpoint for the category picker. **Editing an employee's email** changes how they log in (it is the login key): the UI warns *and* the server requires `confirm_email_change` (returns **409 `email_change_confirmation_required`** otherwise). **CSV bulk upload** follows the registry-import discipline — a two-phase **validate → report → apply**: a per-row pass/fail report, valid rows applied each in its own transaction with its audit write (not whole-file-atomic — one bad row cannot kill a 1,500-row bootstrap), and **failures are reported, never silently dropped**. Directory reads and writes are both gated on **`directory.manage`** (super_admin + hr_agent — agents do the day-to-day "transferred province" corrections; directory PII is a lower privacy tier than conversation content).

---

## 10. Admin console modules

1. **Knowledge Center** — folder/batch upload (preserving the existing province-folder grouping), the two-tier tagging agent, the lens hierarchy (§4), document cards, the "test a question" sandbox, and the expiry review queue with successor lineage.
2. **Escalation Board** — §8.
3. **User & Directory Management** — §9.
4. **Guardrails Configuration** — §7.
5. **Analytics** — deflection rate, top questions, unanswered questions, escalation volume by topic, satisfaction, and coverage-gap detection (province × sector combinations that have employees but no current document).

---

## 11. Privacy

Employee questions can reveal sensitive personal facts, so: GDPR-grade handling, encryption, retention limits, and **role-scoped access to conversations** — an `hr_agent` sees only its cards' escalated conversations, not every employee's full history. Spain's AEPD and works-council (comité de empresa) considerations may apply before deployment. Roles via `spatie/laravel-permission`: `super_admin`, `hr_agent`, `knowledge_editor` (no chat access), `auditor` (read-only).

**Role-scoped conversation access — the load-bearing model (Sprint 5, ADR-0018).** Broad conversation access is a **deliberately-granted ability held by a designated few**, not automatic for any role. The new spatie permission **`history.view_all`** (granted to `super_admin` + `auditor` only, distinct from `escalation.work`) gates the full-history browser/search and every cross-employee conversation read. The enforced access matrix:

| Role | Full History (`history.view_all`) | Card-scoped conversation | Directory | Admin/role mgmt |
|---|---|---|---|---|
| `super_admin` | ✓ (read + may act) | ✓ | ✓ | ✓ (`admin.manage`) |
| `auditor` | ✓ read-only | ✓ (view) | — | — |
| `hr_agent` | ✗ (403) | ✓ — only its cards | ✓ (`directory.manage`) | — |
| `knowledge_editor` | ✗ (403) | ✗ (403) | — | — |

- **The server is the boundary; the UI only hides.** Every history/conversation/directory/admin endpoint checks its ability server-side (`EnsureCan`) and 403s without it — proven by API tests that hit endpoints directly, not through the UI. Hiding a nav item is not access control.
- **The `hr_agent` card-scoped boundary (Sprint 4) is unchanged** — keyed strictly to `card.chat_session_id`, no caller-supplied id. Sprint 5 only **tightens the `knowledge_editor` read**: the card-detail **conversation payload** now requires `escalation.work` OR `history.view_all`, so a `knowledge_editor` sees the card meta but not the messages (the board listing/meta stays broadly readable).
- **Every conversation access is logged** to `conversation_access_log` — including a `super_admin`'s read; no role is exempt. `conversation_view` on opening a conversation, a lighter `history_search` on a search run (snippets brief by design). This accountability record is what makes broad oversight defensible to AEPD / comité; Sprint 9 hardens it (retention/erasure + reporting over the log).
- **Status enforcement** (both OTP paths + token revocation + `EnsureActiveAccount`) makes deactivation remove access immediately — see §9.

History is **read-only over existing data** (no answer-loop change); acting on a conversation routes through the `escalation.work`-gated escalation endpoints, which `auditor` lacks. `admin.manage` (creating admins / granting `history.view_all`) is the most privileged action — `super_admin` only.

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
