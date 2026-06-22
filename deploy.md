# HR Platform — Deployment / Go-Live Checklist

> Canonical location: `hr-docs/deploy.md`
> Status: **living document.** Standing checklist of things that must be true **before the platform serves real employees** — distinct from what each sprint builds. Items here are *deploy-time*, not code to write in a sprint; a sprint may **add** an item here when it introduces something that needs operational/compliance handling at go-live.
> Read with `roadmap.md` (the pre-go-live phase) and `architecture.md` §11 (privacy).

This file exists so deploy-time obligations are **on the record and can't be lost to a chat compaction or a sprint hand-off**. Nothing here blocks a build; everything here blocks (or gates) go-live.

---

## 1. External LLM answer model — GDPR / data-processing (added: Sprint 2b)

The employee chat (Sprint 2b) sends the **employee's question + retrieved convenio text** to an external LLM provider (default **Claude API**) to synthesise the answer. That means personal data of Sedena staff is processed by a third party on every answer, which triggers EU/Spanish GDPR obligations. **All three must be satisfied before go-live:**

- [ ] **EU / approved endpoint.** Use the provider's EU-region (or otherwise approved-for-EU) API endpoint, so data isn't processed in a region without an adequate-protection basis.
- [ ] **Signed Data Processing Agreement (DPA).** A signed DPA must be in place with the answer-model provider — the contract (legally required when a third party processes personal data on your behalf) committing them to: use the data only to provide the service (**no training on it**), keep it secure, not retain it, and act only on Sedena's instructions as controller. Anthropic offers a standard DPA — sign it.
- [ ] **Zero data retention enabled.** Turn on the provider's zero-retention / no-logging option so the question + convenio text are not stored by the provider after the answer is returned. (DPA + zero-retention go together.)

**Why:** Spain's data-protection authority (**AEPD**) and Sedena's **comité de empresa (works council)** will expect a signed DPA and a clear data-flow story; a missing DPA is a common go-live blocker (see `roadmap.md` Sprint 9 — privacy/GDPR hardening, and the works-council sign-off that can gate deployment).

**Note (not a deploy item — design fact):** the API key is **never** in the browser and **never** in plaintext — it's set once in the admin "Answer model" screen, encrypted at rest, shown masked (`••••1234`), and rotatable but not readable back (Sprint 2b). The frontend never calls the provider directly; only `hr-backend` does.

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

## 4. Operational readiness (placeholder — see roadmap pre-go-live phase)

- [ ] Monitoring + alerting (service-down, escalation-rate spikes).
- [ ] Rate limits (abuse / cost protection — especially the external answer API).
- [ ] Backups + a **tested** restore.
- [ ] Containerisation / deployment pipeline.

---

> **Maintenance rule:** when a sprint introduces something that needs handling at go-live (a new secret, a new external dependency, a new compliance obligation), add a checkbox here **in the same change**, the same way docs move with code elsewhere. Don't let deploy-time obligations live only in a sprint review.
