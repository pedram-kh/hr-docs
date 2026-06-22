# HR Platform — Roadmap

> Canonical location: `hr-docs/roadmap.md`
> Status: **living document.** This is the full, sequenced scope of the project. It **supersedes the high-level build order in `architecture.md §12`** (which predates the Sprint 1 split). When they disagree, this file wins; `architecture.md §12` should be reduced to a pointer here.
> Read with `architecture.md` (the *what* and *why*), `data-model.md` (the schema), and the `decisions/` ADRs.

This document exists because the full scope nearly got lost to a chat compaction. It is the durable, countable answer to "how big is this project" — so the scope lives in the repo, not in anyone's memory.

---

## 1. Where we are

| Sprint | Scope | Status |
|---|---|---|
| **Sprint 0** | Foundation & walking skeleton (3 services, 24-table schema + pgvector, email-OTP login into empty shells) | ✅ **committed** |
| **Sprint 1** | Knowledge-ingestion backbone (territories restructure, registry import, PDF ingestion via `hr-ai /extract`, deterministic filename parser, conflict detection, tag provenance, basic admin documents table) | ✅ **committed** (spec, plan, build, review, corrections 01–02) |
| **Design-system adoption** | Vanilla-CSS token system, light-default + dark toggle, Inter, two existing screens refactored (ADR-0012) | 🔄 **built; pending real-app eyes-on + commit** |
| **Sprint 2a** | Ingestion-to-vectors: article-aware bilingual chunking, BGE-M3 / `vector(1024)` into `document_chunks`, structured salary-row extraction, the scope-prefilter `/retrieve` primitive + `retrieval:probe` harness | ✅ **built** (pending final commit) |
| **Sprint 2b-1** | The narrow answer vertical: `hr-ai /synthesise` (pluggable Claude) with the **authority-precedence** rule, `hr-backend` pipeline + answer-or-escalate floor + hardcoded guardrail baseline + external-key handling (ADR-0015), chat UI + admin "Answer model" screen, citations + trace + history | 🔄 **built; pending eyes-on (real key) + commit** |

Sprint 2b is **split into two slices** (an honest-scoping split, like 1→1+corrections and RAG→2a/2b): **2b-1** is the single prose answer path (this slice); **2b-2** adds the router, full per-claim grounding check, salary-in-chat, and history search. Everything below 2b-2 is **ahead of us**.

---

## 2. How to read the sequence

- **Sizes** are rough and relative (S / M / L), not time estimates — we don't estimate in time, and sprints split when honest scoping reveals they're overloaded (Sprint 1 became 1 + two corrections; RAG splits into 2a/2b below). **The count grows as we get more honest, and that is the discipline working, not scope creep.**
- **Three pillars are cross-cutting, not single sprints** — they are built *into* other sprints and called out in §4 so they're never mistaken for one-off features: **auditable history**, **privacy/GDPR**, and **the learning-loop/flywheel**.
- The **LLM tagging tier stays late** — the deterministic parser already covers the clean majority, so the system ships value without it (ADR-0011 foundations are already in place from Sprint 1).

---

## 3. The forward sequence

### Sprint 2a — Ingestion-to-vectors  · size **L**
Turn ingested documents into retrievable knowledge.
- Chunking strategy for prose convenios + the Estatuto (article-aware; **never blend Euskara + Spanish into one chunk** — bilingual docs kept clean; ADR-0006).
- Embeddings: **BGE-M3 / `vector(1024)`** written into `document_chunks` by `hr-ai`, with the denormalised scope columns populated for pre-filtering.
- **Structured salary-row extraction** from the `Tablas` PDFs into `salary_tables` / `salary_table_rows` — so salary questions become **SQL lookups, never vector matches** (ADR-0006). This also populates `convenio_job_categories` (deferred from Sprint 1).
- The scope-prefilter query path: given a resolved scope, return the eligible chunk/row set.
- Historical/`draft` documents excluded from current retrieval; `active` only.
- **Out:** the chat surface, the answer LLM, the router (that's 2b).

### Sprint 2b — Scoped RAG chat + answer loop  · size **L**  · *split into 2b-1 + 2b-2*
The first surface a real employee uses. Split into two slices so the answer vertical proves end-to-end before the router/grounding complexity lands.

**Sprint 2b-1 — the narrow answer vertical (built).**
- The employee **chat UI**, built fresh on the design system (proves the design language on a from-scratch screen): message list, input, answer + citations + expandable **"how I got here" trace**, escalation state via the signature badges.
- The **single prose path, no router** (`architecture.md §5`): deterministic **scope resolver** (`hr-backend`) → **hardcoded guardrail baseline** (fires before any `hr-ai` call) → **pre-filtered vector search** (2a `/retrieve`) → **cited synthesis** (`hr-ai /synthesise`, pluggable Claude) → **answer-or-escalate floor** (`hr-backend`) → answer **or escalate**.
- **Authority precedence in synthesis** (legal-weight): convenio chunks ordered before `national_law`, the precedence rule encoded in the prompt, `authority_used` recorded in the trace — the convenio governs where it speaks; the Estatuto is the baseline only where it is silent (ADR-0015).
- **External answer model + key handling** (ADR-0015): pluggable provider in `hr-ai`; the admin "Answer model" screen sets the key once, encrypted at rest, masked, rotatable, never read back; the key is passed decrypted per call, never reaches the browser.
- **Citations** back to source document + page (uncited answers never reach the employee); **the structured trace** written per turn (pillar §4.1); full **chat history** stored, role-scoping-ready, auditable.
- **Hardcoded guardrail baseline** lands here (no legal/medical advice, sensitive-topic auto-escalation) — the *config UI* for additive rules is later (Sprint 6).

**Sprint 2b-2 — the router + full grounding (ahead).**
- The **router** (small LLM): salary-table lookup vs prose-policy vs off-domain.
- **Salary-in-chat** (the 2a salary SQL surfaced through the pipeline). This **supersedes** the 2b-1 *salary-topic guard* (Correction-02): instead of escalating salary questions with `salary_not_in_chat`, 2b-2 answers them from the structured `salary_tables` via SQL (exact figures), so the deterministic guard is retired once the grounded salary path exists.
- **Parked — 2a wage-table-chunk cleanup (accepted residual from Correction-02):** convenio PDFs ingested in 2a embedded their wage-table pages as prose `document_chunks`. The 2b-1 salary guard stops *salary questions* from reaching them, but a non-salary question could still retrieve a table chunk. The deeper fix — excluding wage-table pages from prose chunking at ingest (ADR-0006: salary is never a vector chunk) — is not done yet and should be addressed alongside salary-in-chat.
- The **full per-claim grounding check** (beyond 2b-1's citation-coverage + retrieval-score proxy). This must **supersede** 2b-1's deterministic *figure-grounding guard* (Correction-01) — the cheap number-with-unit backstop is a stopgap that only checks load-bearing figures, **not** a per-claim entailment check, and must not be mistaken for the real grounding model.
- **History search** UI.

### Sprint 3 — Lens-hierarchy admin UI  · size **L**
The rich Knowledge-Center surface deferred from the original Sprint 1.
- The **one reusable hierarchy component** driven by a lens definition (ADR-0001): **branching-graph** and **indented-list** forms, two-level default, expand/collapse, lazy load, leaf-opens-card.
- The **lenses**: by province, by sector, by validity, by topic.
- The full **document card**: facet tags with inline provenance, validity + expiry countdown, retrieval status, authority level, version lineage, the change-log timeline, and the **"test a question against this document"** sandbox.
- This is the admin-side payoff of the faceted model built in Sprint 1.

### Sprint 4 — Escalation board + the flywheel  · size **L**
The Kanban board **and** the core of the learning loop (pillar §4.3).
- The **board**: cards auto-created on agent-fail / sensitive topic / off-domain / user-request, each carrying the full conversation + trace; status **New → Assigned → In Progress → Resolved**.
- On resolve, the **"turn this into a knowledge article"** flow: a new `documents` row, `authority_level = internal_hr_ruling`, that **inherits the asker's scope by default and forces scope confirmation** before publish; an internal ruling never silently overrides an official convenio for the same scope (re-escalate on conflict).
- Implicitly the **gap-analysis** surface.
- *Dependency:* uses the roles seeded in Sprint 0 for access; the full directory UI is Sprint 5 (test users suffice to assign cards until then).

### Sprint 5 — User & directory management + roles  · size **M**
The first-class directory (ADR-0004 — no HRIS/AD sync).
- Full **CRUD**: manual create / edit / search / filter **plus** CSV bulk upload (bootstrap).
- The **profile-change audit log** (`employee_audit_log`) — what a profile said at the moment of any answer = dispute defense — and the `profile_last_reviewed_at` staleness signal.
- The four **roles** wired through the UI (`super_admin`, `hr_agent`, `knowledge_editor` no-chat, `auditor` read-only) via `spatie/laravel-permission`, including **role-scoped conversation access** (pillar §4.2 — an `hr_agent` sees escalated chats, not everyone's history).
- Editing an email = changing how that person logs in (surfaced in the UI).

### Sprint 6 — Guardrails configuration UI  · size **M**
The admin layer on top of the hardcoded baseline (which shipped in 2b).
- Admin-defined, **additive-only** rules: off-domain refusal, blocked topics, the confidence threshold below which the bot escalates instead of answering, tone constraints.
- Admins can **add to but never weaken** the baseline.

### Sprint 7 — LLM tagging tier + vocabulary growth + expiry queue  · size **M**
The messy-tail intelligence, built on foundations already laid.
- The **LLM tagging tier** for documents without clean filenames (resolved-escalation articles, ad-hoc uploads, scans) — reads content, **proposes** facets with confidence (ADR-0011 rescue path, using the `reason`/`raw_unmatched_values` fields already built in Sprint 1).
- The **propose-new-vocabulary** UI (agent proposes a value should exist → human approves; strong default "variant → alias", ADR-0011).
- The **expiry review queue** with successor lineage (a queue, not a popup) — surfacing documents approaching `validity_end` and confirming the successor handoff.

### Sprint 8 — Analytics + coverage gaps + quality sampling  · size **M**
The HR-facing measurement layer.
- Deflection rate, top questions, unanswered questions, escalation volume by topic, satisfaction.
- **Coverage-gap detection** (province × sector with employees but no current document — free from the faceted model).
- **Monthly accuracy sampling**: a random sample of conversations reviewed by HR — the quality-audit mechanism that catches drift without re-checking everything.

### Sprint 9 — Privacy / GDPR hardening  · size **M**  · *recommended as a real sprint*
The dedicated pass for what's threaded earlier (pillar §4.2).
- Encryption-at-rest for chat data, retention limits, enforcement + audit of role-scoped conversation access.
- AEPD considerations; **comité de empresa (works council) sign-off** preparation — which can *gate deployment*, which is why this is a real sprint and not an afterthought.

### Pre-go-live phase — Operational "between" layer  · size **M–L**  · *the run-it-for-real last mile*
Not a feature sprint; the work that makes it safe to run for 1,500 people unattended (your "between the two extremes" call).
- Monitoring + alerting (know when a service is down or escalation rates spike), rate limits (abuse/cost protection), backups + a tested restore, deployment, app containerisation.
- Deliberately **defers** heavy enterprise polish (deep dashboards beyond Sprint 8, advanced guardrail tooling) to a later phase once it's live and proven.

---

## 4. Cross-cutting pillars (built *into* sprints, not standalone)

**4.1 Auditable history & the trace.** Built into **2b** from the first answer — every chat turn and its structured trace stored permanently, searchable, reviewable. Not a late add; retrofitting "log everything auditably" is painful, so it's a property of the chat from day one. Doubles as the eval dataset.

**4.2 Privacy / GDPR.** Threaded early (role-scoped conversation access designed into **2b** and enforced in **5**) **and** given a dedicated hardening pass (**Sprint 9**). GDPR can't be bolted on at the end — but it also earns a real sprint because AEPD / comité-de-empresa can gate go-live.

**4.3 The learning-loop / flywheel.** The "system gets smarter with use" pillar you emphasised. Its core is **Sprint 4** (resolved escalation → human-approved, scoped knowledge article). It's *fed* by auditable history (4.1) and *measured* by analytics + coverage gaps (Sprint 8). Realised across those sprints, not isolated in one — but it is a first-class pillar, not a footnote.

---

## 5. The count, honestly

Two sprints are **done** (0, 1) plus the near-complete design system. Ahead: **2a, 2b, 3, 4, 5, 6, 7, 8, 9, and the pre-go-live phase** — roughly **ten more units of work**, so the full two-sided, production-ready platform is on the order of **11–12 sprints plus a pre-go-live phase**.

This is higher than the early "~6" figure, and that's the honest correction: the early number predated every close-up scoping pass. It grew because we **split overloaded sprints rather than cram them** (Sprint 1; RAG into 2a/2b), inserted the design system, and gave the flywheel, privacy, and operational work **real homes instead of footnotes**. None of that is scope creep — it's the scope becoming visible.

Where *your* "done" line sits is a real choice: a usable, accurate, auditable platform (employee chat + admin console + flywheel) is reached around **Sprint 5–6**; everything after that is making it measurable (8), compliant (9), and operable at scale (pre-go-live). The "between" posture you chose means we build through the operational layer but **defer** deep-maturity polish — so the realistic target is the full sequence above **minus** the heavy enterprise extras, which stay parked until it's live and proven.

---

## 6. Two sequencing calls (recommended here — flip either if you disagree)

1. **Privacy/GDPR = a real sprint (Sprint 9), not just a phase-2 section.** Recommended because works-council sign-off can gate deployment, and role-scoped access must be designed in earlier regardless. *Flip:* fold the hardening into the pre-go-live phase if Sedena's compliance path turns out lighter.
2. **The learning loop = realised across Sprint 4 (core) + 8 (measurement), not a single isolated sprint.** Recommended because its pieces naturally live in the escalation board and analytics. *Flip:* if you want a dedicated "knowledge-gap mining" surface beyond what Sprint 4/8 give, that becomes its own sprint.

---

## 7. Open items still parked (on record, not lost)

- One person being **both admin and employee** (Sprint 0 non-blocker) — matters when employee identity meets the chat (Sprint 2b); decide there.
- **Bilingual chunking** for Euskara content — handled as part of 2a's chunking rule (don't blend languages); deeper eu handling can be revisited if retrieval quality needs it.
- **Scope precedence** between territory levels (does a provincial convenio override a regional one?) — deferred from Sprint 1; decide when retrieval eligibility is built (2a/2b).
- **Sedena branding** — neutral-blue placeholder accent in one token (ADR-0012); swap when a brand colour is chosen.
