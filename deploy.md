# HR Platform — Deployment / Go-Live Checklist

> Canonical location: `hr-docs/deploy.md`
> Status: **living document.** Standing checklist of things that must be true **before the platform serves real employees** — distinct from what each sprint builds. Items here are *deploy-time*, not code to write in a sprint; a sprint may **add** an item here when it introduces something that needs operational/compliance handling at go-live.
> Read with `roadmap.md` (the pre-go-live phase) and `architecture.md` §11 (privacy).

This file exists so deploy-time obligations are **on the record and can't be lost to a chat compaction or a sprint hand-off**. Nothing here blocks a build; everything here blocks (or gates) go-live.

---

## 1. External LLM answer model — GDPR / data-processing (added: Sprint 2b)

The employee chat (Sprint 2b) sends the **employee's question + retrieved convenio text** to an external LLM provider (default **Claude API**) to synthesise the answer. That means personal data of Sedena staff is processed by a third party on every answer, which triggers EU/Spanish GDPR obligations. **All three must be satisfied before go-live:**

- [ ] **EU / approved endpoint.** Use the provider's EU-region (or otherwise approved-for-EU) API endpoint, so data isn't processed in a region without an adequate-protection basis. *(Sprint 2b-1 wired this as non-secret config: `HR_AI_ANSWER_MODEL` / `HR_AI_ANSWER_ENDPOINT` on `hr-backend` — passed to `hr-ai /synthesise` per call. These **must** point at an EU-available model/endpoint before go-live.)*
- [ ] **EU / approved endpoint for the ROUTER and GROUNDING calls (added: Sprint 2b-2).** 2b-2 added two more provider calls that see the employee's **question** (`/route`, ADR-0016) and the **question + answer + cited convenio text** (`/ground`, the per-claim grounding check). `/ground` uses the answer model (same `HR_AI_ANSWER_ENDPOINT`, already covered above). `/route` uses a **separate router model** (`HR_AI_ROUTER_MODEL`) and a **`HR_AI_ROUTER_ENDPOINT`** that **defaults to the answer endpoint** — *but if it is set to a distinct endpoint, that endpoint must independently satisfy all three obligations here (EU region, signed DPA, zero-retention).* Verify the router endpoint is EU before go-live, or leave it defaulted to the (already-vetted) answer endpoint.
- [ ] **Signed Data Processing Agreement (DPA).** A signed DPA must be in place with the answer-model provider — the contract (legally required when a third party processes personal data on your behalf) committing them to: use the data only to provide the service (**no training on it**), keep it secure, not retain it, and act only on Sedena's instructions as controller. Anthropic offers a standard DPA — sign it. *(Covers the answer, router, and grounding calls when they share a provider/endpoint; a distinct router endpoint needs its own DPA.)*
- [ ] **Zero data retention enabled.** Turn on the provider's zero-retention / no-logging option so the question + convenio text are not stored by the provider after the answer is returned. (DPA + zero-retention go together; applies to the router + grounding calls too.)

**Why:** Spain's data-protection authority (**AEPD**) and Sedena's **comité de empresa (works council)** will expect a signed DPA and a clear data-flow story; a missing DPA is a common go-live blocker (see `roadmap.md` Sprint 9 — privacy/GDPR hardening, and the works-council sign-off that can gate deployment).

**Note (not a deploy item — design fact, built in Sprint 2b-1, extended 2b-2):** the API key is **never** in the browser and **never** in plaintext — it's set once in the admin "Answer model" screen, encrypted at rest (`Crypt`, app-key, in `answer_model_settings`), shown masked (`••••1234`, reconstructed from `key_last_four` without decrypting), and rotatable but not readable back. The frontend never calls the provider directly; only `hr-backend` does, decrypting the key in `ChatService` for the turn and passing it in the request **body** (never a header) to `/route`, `/synthesise`, and `/ground` (the same key path for all three — ADR-0015/0016), never persisted by `hr-ai`.

---

## 2. Secrets & configuration (placeholder — expand as built)

- [ ] Answer-model API key configured (admin "Answer model" screen; encrypted at rest).
- [ ] `X-Internal-Token` (the `hr-backend` ↔ `hr-ai` shared secret) set from environment on both services, not the dev default.
- [ ] Email/OTP transactional provider (Postmark) configured with SPF/DKIM; **MailHog is dev-only** (ADR-0003).
- [ ] The scoped `hr_ai` Postgres role (write only on `document_chunks`) is provisioned in the production database (Sprint 2a migration).
- [ ] S3-compatible storage pointed at the production bucket (not local MinIO); storage adapter env set (ADR-0009).

## 3. Data bootstrap (placeholder)

- [ ] The corpus is loaded into **production** through the **admin upload UI** — *not* a folder committed to git (`hr-backend/data/all-files` is a **dev convenience only**; real HR documents must not live in the repo).
- [ ] Registry import (`registry:import`) run against production.
- [ ] Chunk embed (`chunks:embed`) and salary import (`salary:import`) run against production (they populate the prod database; dev vectors do **not** carry over).
- [ ] Employee directory loaded (CSV bulk upload / manual), per ADR-0004 (Sprint 5).

## 4. Pre-go-live cleanup — privacy / confidentiality hygiene (added: Sprint 5)

Some dev seed/test fixtures (and at least one doc string) carry **real-world identifiers** that must not ship to production. This is a privacy/confidentiality hygiene item, **not a functional bug** — nothing misbehaves, but real names and the internal project codename must be scrubbed before the platform serves real employees (and before the works council / AEPD see the repo).

- [ ] **Scrub test fixtures and doc strings of real identifiers before go-live.** Some test/seed data — and at least one doc string — contain real-world identifiers (a real company name in a test escalation's source question; the internal project codename, which by convention must **never** appear in code or docs). Before go-live: **(a)** replace any real company/person names in seed + test fixtures with **obviously-fake placeholders**; **(b)** `grep` the codebase + docs for the internal codename and any real client identifiers and remove them; **(c)** confirm **no real `@`-domain emails or personal data** sit in committed fixtures. *(The specific offending values are intentionally not reproduced here — that would defeat the scrub; locate them with the grep in (b).)*

## 5. Corpus coverage — unanswerable / expired convenios (added: Sprint 2c)

The Sprint 2c re-chunk used the **registry** as the arbiter of the active set; doing so surfaced two go-live coverage risks. Neither blocks a build; both must be **resolved or explicitly accepted** before serving employees in the affected province×sector cells.

- [ ] **Scanned, no-extractable-text convenios → currently unanswerable.** Some registry-`active` convenios are **image-only PDFs** with no extractable text, so they produce **no chunks** and the chat cannot answer from them: **`CONVENIO DEPORTE NAVARRA 2025–2028` (id 89)** and **`PACTO CULTURA NAVARRA` (id 91)** (both also `under_review`). They need **OCR** before go-live, or an **explicit scope exclusion** so the gap is acknowledged rather than silent. *(Same family as any other scan in the corpus.)*
- [ ] **Expired convenios with no loaded active successor → coverage gaps.** The registry holds these convenio **prose texts only as `historical`** (validity ended 2024/2025) with **no `active` successor loaded**, so their province×sector has **no current document** to answer from: Andalucía **COEAS** (id 29), Vizcaya **Intervención Social** (id 18 + family 13/16), Huesca **Hostelería** (id 33), Salamanca **Oficinas** (id 51), Gipuzkoa **Intervención Social** (id 45), Navarra **Hostelería** (id 75) and **Oficinas-despachos prose** (id 93, where only a salary-table PDF id 94 is active), Deporte **Estatal** (id 69), Asturias **Deportes** (id 52), Navarra **Deporte** (id 80). Two further successors exist but are stuck `under_review` (not yet trustworthy): **COEAS Estatal** (id 72), **COEAS Madrid** (id 24). Before go-live: **load/activate the current text**, **clear the `under_review` ones**, or **accept the gap** per cell. (Feeds Sprint 8 coverage-gap detection — see `roadmap.md`.)

## 6. Operational readiness (placeholder — see roadmap pre-go-live phase)

- [ ] Monitoring + alerting (service-down, escalation-rate spikes).
- [ ] Rate limits (abuse / cost protection — especially the external answer API).
- [ ] Backups + a **tested** restore.
- [ ] Containerisation / deployment pipeline.

---

> **Maintenance rule:** when a sprint introduces something that needs handling at go-live (a new secret, a new external dependency, a new compliance obligation), add a checkbox here **in the same change**, the same way docs move with code elsewhere. Don't let deploy-time obligations live only in a sprint review.
