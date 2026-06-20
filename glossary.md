# HR Platform — Glossary

> Canonical location: `hr-docs/glossary.md`
> Shared vocabulary for the project. Use these terms consistently in code, comments, UI copy, and docs. When a term here maps to a database table or column, the name is given in `code` style.

---

**Convenio (colectivo)** — a Spanish collective labour agreement. Defines working conditions (hours, pay, leave, etc.) for a sector within a scope (a province, or state-wide). The central scoping object. Stored in `convenios`; identified by its official `numero`.

**Numero** — the official convenio code, e.g. `71103505012022`. Its first two digits are the province code (`01` = Álava, `99` = Estatal/state-wide). Used as the canonical convenio identifier and as a parser key on filenames.

**Estatal** — state-wide / national scope (province code `99`). A convenio can be provincial or estatal.

**Estatuto de los Trabajadores** — Spain's national labour law. The universal baseline that applies to every employee regardless of convenio. Stored as a document with `authority_level = national_law`.

**Facet** — an independent tag dimension on a document: province, sector, convenio, validity window, document type, topic. Documents carry facet *values*; they are not placed in a single fixed tree. The basis of the whole model (see ADR-0001).

**Scoping facet** — a facet that determines *which law applies* to an employee: province, sector, convenio, validity, job category. These are a **closed controlled vocabulary** — legally load-bearing, extendable only by deliberate admin action.

**Descriptive facet** — a facet that describes but does not legally scope a document, e.g. `topic`. A **managed vocabulary**: the AI may propose values, an admin approves them.

**Controlled vocabulary** — facet values stored in their own tables, referenced by foreign key. Invalid tags are structurally impossible, not merely discouraged. "Closed" = no new values except by admin action; "managed" = AI can propose, admin approves (see ADR-0002).

**Lens** — a way of grouping the faceted documents for display, i.e. a `GROUP BY` ordering of facets (by province, by sector, by validity, by topic). All lenses render through one reusable hierarchy component. A document is a leaf in every lens.

**Scope / scoping** — the act of determining, from an employee's profile + the question date, which documents are eligible to answer their question. Deterministic, done in `hr-backend`; it carries legal weight and is never left to LLM reasoning.

**Job category** — the sub-classification within a convenio (e.g. Director/a, Técnico/a, Animador/a). Resolves the "split value" problem where one convenio lists different hours/pay per category. Stored in `convenio_job_categories`; referenced by `employees.job_category_id`.

**Provenance** — the recorded origin and history of a tag: who set it (filename parser / AI / admin), when, with what confidence, and the prior value. Stored append-only in `tag_events`. The basis of auditability and legal defensibility.

**Tagging agent** — the two-tier system that assigns facet values to documents: a deterministic filename parser for the clean majority, and an LLM tier for the messy tail. It **proposes**; a human confirms. It never creates controlled-vocabulary values.

**Retrieval status** — a document's lifecycle state: `draft` (excluded from employee retrieval), `active` (citable as current), `historical` (answers time-scoped questions but never cited as current).

**Authority level** — a document's standing: `national_law` (Estatuto), `official_convenio`, or `internal_hr_ruling` (from a resolved escalation). On conflict for the same scope, an internal ruling must not override an official convenio — re-escalate.

**Version lineage** — the predecessor/successor relationship between document versions (e.g. a convenio's 2020–2023 edition → its 2024–2027 edition). Stored via `documents.predecessor_document_id`; drives the expiry/successor-handoff flow.

**Trace** — the structured, step-by-step record of how an answer was produced (profile detected, scope filters applied, router decision, retrieved chunks + scores, guardrail result, confidence). Stored in `message_traces.trace`. Serves the employee (trust), the admin (debugging), and compliance (audit); doubles as an eval dataset.

**Escalation card** — a unit of work created when the pipeline can't answer confidently, the topic is sensitive/off-domain, or the asker requests a human. Carries conversation context + trace; worked on the escalation board.

**Flywheel** — the loop where a resolved escalation becomes a tagged knowledge article, making the system answer that question next time.

**Email OTP** — passwordless authentication: a one-time code sent to the user's email. Not SSO (there is no external identity provider). Email is both the auth key and the profile-lookup key.

**Daily session** — a session lasting ~24h before re-authentication is required. The chosen balance between security and not making users fetch a code every visit (ADR-0005).

**Coverage gap** — a province × sector combination that has employees assigned but no current document — surfaced by analytics as a prioritised gap for HR to fill.
