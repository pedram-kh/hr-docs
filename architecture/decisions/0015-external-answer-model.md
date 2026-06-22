# ADR-0015 — External LLM answer model (synthesis in hr-ai, key owned by hr-backend)

**Status:** accepted

## Context
Sprint 2b synthesises the employee-facing answer from the chunks retrieved in 2a. The **embedding** model is self-hosted (ADR-0006) because there the privacy win dominates and the quality gap is small. The **answer** model is the opposite trade: the quality gap between a frontier hosted model and a self-hostable one is **large**, and answer quality is the whole employee-facing payoff. The decision is therefore to use an **external API** for synthesis — accepting that the employee's question + the retrieved convenio text leave Sedena's servers on each answer, which is a GDPR-relevant data flow.

ADR-0007 already assigns **answer synthesis to `hr-ai`**, and `hr-backend` owns the system of record, all DB writes, and the answer-or-escalate decision (legal weight). The platform's security line also forbids the UI/browser ever handling a raw API key.

## Decision
- **Synthesis stays in `hr-ai`** (ADR-0007). The answer model is a **pluggable provider** behind one interface in `hr-ai`; **default Claude**. Non-secret provider config (which model, the EU endpoint) is `hr-ai` config; swapping providers is an adapter/config change, no rewrite.
- **`hr-backend` owns the API key.** An admin sets it **once** in an "Answer model" settings screen → it is **encrypted at rest** (Laravel app-key encryption), **shown masked** (`••••1234`), and **rotatable** (replace, never read back). It is never stored in plaintext, never committed, never echoed to the browser. (A running app cannot safely rewrite its own `.env` at runtime, so the secret is stored encrypted in the DB and read server-side — set-and-mask, not env-rewrite.)
- **The key crosses only the internal trust boundary.** `hr-backend` decrypts it and passes it to `hr-ai` over the existing internal `X-Internal-Token` channel **per synthesis call**; `hr-ai` uses it for that call and **never persists it**. The **browser never sees the key and never calls the provider directly** — only `hr-ai` calls the provider, only with a key handed to it by `hr-backend`.
- **`hr-backend` still owns the answer-or-escalate decision.** `hr-ai` synthesises and returns the answer + citations + a grounding/confidence signal + trace fragment; `hr-backend` applies the hardcoded guardrail baseline and the confidence floor and decides whether to surface the answer or escalate. `hr-ai` writes no DB rows (ADR-0007/0010 unchanged).
- **GDPR/data-processing requirements are deploy-time**, tracked in `deploy.md` §1 (EU endpoint, signed DPA, zero-retention) — not code.

## Consequences
- Best-in-class answer quality; the privacy cost is bounded by (a) the system never sending more than the question + retrieved chunks, and (b) the EU-endpoint + DPA + zero-retention controls in `deploy.md`.
- One provider interface — switching providers, or falling back to a self-hosted answer model if compliance ever demands, is an adapter swap, not a redesign.
- Key handling has **no plaintext surface and no browser exposure**; rotation is supported; the threat surface reduces to the encrypted DB value + the Laravel app key + the internal channel.
- Embedding (self-hosted, ADR-0006) and answering (external, here) are decided **independently for the right reason each** — privacy-dominant for embedding, quality-dominant for answering.
- An external model that is confidently wrong is dangerous, so this ADR is inseparable from the **answer-or-escalate floor + grounding + mandatory citations** that gate every answer (Sprint 2b-1).
