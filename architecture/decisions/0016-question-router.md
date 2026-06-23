# ADR-0016 — Question router (small LLM, fail-safe)

**Status:** accepted

## Context
Sprint 2b-1 ships a single prose answer path with no router: every question goes to vector retrieval + synthesis, salary questions are escalated by a deterministic guard (Correction-02), and "off-domain" is inferred weakly from a low retrieval score. Sprint 2b-2 completes the answer surface, which needs a question to reach the **right** path: a **salary** question must hit the structured `salary_tables` SQL path (exact, aligned figures — ADR-0006), a **prose-policy** question must hit vector retrieval + synthesis, and an **off-domain** question must escalate. The deterministic salary regex is high-precision but blunt on phrasing; the retrieval-score off-domain proxy is weak. A classifier that *reads* the question discriminates these better.

The choice was an **LLM router vs a deterministic classifier**. Decision: **small LLM router** — flexibility and better off-domain/salary-vs-policy discrimination outweigh the added latency/cost, which are bounded by using a small/fast model and by keeping the deterministic guards as fast pre-empts for the clear cases.

## Decision
- The router is a **small/fast LLM classification call** that labels each question **salary-lookup / prose-policy / off-domain**, reusing the **ADR-0015 pluggable-provider + `hr-backend`-owned-key** infrastructure (the key is never in the browser; the router call is made server-side). Router model/endpoint are non-secret config, may differ from the answer model (a smaller model is fine).
- **It runs *after* the hardcoded guardrail baseline** (ADR-0007 / Sprint 2b-1 §7). So sensitive-topic / other-employee questions are escalated *before* the router — the router never sees them (the same privacy protection the answer model has).
- **Fail-safe is mandatory.** On router uncertainty (low confidence) or error, default to the **safe path** — prose retrieval + the answer-or-escalate floor (which can still escalate), or escalate outright — **never a silent misroute**. The router decision + confidence are recorded in the trace.
- **It supersedes the Correction-02 deterministic salary-escalation:** a salary question now **routes to the SQL path** (answered from `salary_tables`, or escalated on a coverage gap) instead of being blanket-escalated. The deterministic salary patterns may remain as a high-precision pre-classifier feeding the router (the salary path) — an implementation choice for the plan.
- **Off-domain → escalate** (a cleaner signal than the 2b-1 retrieval-score proxy).

## Consequences
- Better routing accuracy on the fuzzy salary-vs-policy-vs-off-domain boundary than regex/score heuristics; the structured salary path finally becomes reachable in chat.
- **Cost/latency:** one extra small LLM call per question, before the answer call. Bounded by a small/fast model and by deterministic guards short-circuiting the obvious cases (sensitive / other-employee / unmistakable salary) before the router runs.
- **Another external-provider dependency on the question text** — but within the existing GDPR envelope: the question already goes to the answer model, and `deploy.md` §1 (EU endpoint, signed DPA, zero-retention) covers the provider. The router sees the **question**, not necessarily the retrieved chunks; sensitive questions never reach it (guardrail fires first).
- **Misroute risk is bounded by fail-safe:** the worst case of an uncertain/failed route is the safe prose+floor path or an escalation, not a wrong-path answer.
- Pairs with the **full per-claim grounding check** (2b-2), which supersedes 2b-1's figure-guard + citation-coverage proxy — together they make the complete answer surface trustworthy.

## Implementation (Sprint 2b-2)
- **The deterministic salary pre-classifier feeds the router (not the LLM).** The Correction-02 salary patterns moved from `GuardrailService` into `RouterService` and run as a **high-precision pre-empt**: a match routes straight to the SQL salary path with **no `/route` LLM call** (cheaper, deterministic, and immune to a flaky classifier on the unmistakable cases — "¿cuánto gana un peón?"). Only when no pattern matches does the small-model `/route` run. Consequence: the router LLM is invoked on the *fuzzy* questions, where it adds value, and skipped on the obvious ones — the latency/cost concern above is realised in practice. A non-match that the LLM later labels `salary` still reaches the same SQL path.
- **Fail-safe is realised as a confidence floor** (`hr.router_confidence_floor`, default 0.50): below it, or on a provider/transport error, or with no answer-model key, the turn defaults to the **prose** path (which itself can escalate via the answer-or-escalate floor). A confident `off_domain` escalates; a confident `salary` routes to SQL.
- **Compound decomposition** rides on the router: `/route` returns `subqueries` for a compound question, consumed by `hr-backend`'s recall-hardening union (the Q10 fix). Because that fix *depends* on the router emitting sub-queries, `RouterService` carries a **deterministic conjunction/clause pre-split as a built-in safety net** for when the LLM returns a single-topic classification on a compound question — the LLM decomposition is primary, the deterministic split is the backstop.
- The router decision (`label`, `confidence`, `source`) lands in `trace.router_decision`. If the router endpoint differs from the answer endpoint, the distinct EU endpoint is recorded in `deploy.md` §1.
