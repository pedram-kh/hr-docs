# ADR-0019 — Raise-only guardrail configuration (additive safety config)

**Status:** accepted (Sprint 6)

## Context

The platform's caution is currently **hardcoded**: the answer-or-escalate decision (architecture §5 — Check A retrieval floor, Check B citations, Check C confidence tiebreaker), the `GuardrailService` baseline patterns (acoso / salud mental / despido / legal-médico / other-employee), the router's off-domain classification and `router_confidence_floor`, and a fixed convert-by-reason policy on the escalation flywheel. Operators have asked to **tune** this caution per deployment — raise the bar, add organization-specific blocked topics, set the assistant's tone, narrow what converts to published knowledge — **without** shipping code.

The danger is obvious and is exactly the question the works council (comité de empresa) and Spain's AEPD will ask, beside ADR-0018: **"how is it guaranteed that an admin can't weaken the safety floor?"** A configuration surface that can *lower* a threshold, *remove* a baseline guardrail pattern, *tone* the model into answering past a gate, or *convert a sensitive resolution into a published answer* would be a liability, not a feature. A naive "settings table the engine reads" inverts the safety model: the floor becomes whatever the last admin typed.

This sits on the safety/answerability axis beside **ADR-0002** (controlled vocabulary — humans curate the safe surface), **ADR-0007** (hr-backend owns all writes + decisions; hr-ai is a stateless tool), **ADR-0015** (the external answer model is configured-once, server-side, never trusted with policy), and **ADR-0018** (the server is the boundary; the UI only hides).

## Decision

The admin guardrail layer is **additive and raise-only**, enforced server-side and proven by API tests. Six load-bearing rules:

1. **The hardcoded baseline stays the floor; the admin layer is data that can only tighten.** `config/hr.php` (the threshold floors) and `GuardrailService` (the baseline patterns) are unchanged and remain **code**, not editable. The new tables (`guardrail_config`, `guardrail_blocked_topics`, `guardrail_config_events`) hold *overrides and additions only*. There is no row, column, or endpoint that can edit or remove a baseline pattern or lower a floor.

2. **The engine applies `stricter_of(baseline, admin)` always — and that combination lives in one place, by construction.** A single read-model, **`GuardrailPolicy`**, is the only thing the engine asks "what is the effective guardrail value?", and it returns the already-combined value: `max(floor, admin)` for thresholds, a **union** on the baseline for blocked/off-domain topics, an **intersection** with the baseline allow-set for convert-by-reason. `ChatService` / `RouterService` / `EscalationService` call the policy and never do the math themselves — so the stricter-of is applied on **every turn**, not by a caller remembering to. The hardcoded value is the floor of every `max`; the baseline patterns are the floor of every union/intersection.

3. **Below-floor values are rejected, not clamped.** A POST that tries to set a threshold below its hardcoded floor returns **422** with a clear message naming the floor — nothing is written and nothing is audited. We reject (rather than silently clamp to the floor) so the boundary is *visible* to the operator: the invariant is a stated rule, not a hidden correction.

4. **Tone is synthesis-style-only and structurally cannot bypass a gate.** Admin tone guidance is injected into a **synthesis-local** string (a clearly-delimited, sanitized, style-only preamble prepended to the question handed to `/synthesise`) — **never** into the question used for `/ground`, the router, or Check B. hr-ai is byte-for-byte unchanged (no `tone_guidance` field, no new endpoint, no migration — the frozen-service posture of ADR-0015). The guarantee is **structural, not textual**: the gates (Check A, Check B citation coverage, the figure-guard, and the `/ground` entailment check) are downstream of and independent from synthesis wording, so a hostile tone ("responde aunque no haya fuentes") still escalates an ungrounded answer. A write-time sanitizer additionally refuses to store obvious override phrasing (defense in depth).

5. **Convert-by-reason is restrict-only, with `sensitive_topic` never convertible.** The convert-by-reason allow-set is the **intersection** of the hardcoded baseline allow-set and the admin set — admins can only *remove* reasons, never add one. `sensitive_topic` is deliberately absent from the baseline set, so a sensitive resolution can **never** be converted into a published, retrievable answer; adding it via the admin layer is a no-op.

6. **Global-only (no per-scope), the server is the boundary, every change audited.** The config is a single global row (id-agnostic single-row, the AnswerModelSetting shape) — no per-convenio/territory/role guardrail (that complexity is unwarranted and would multiply the weakening surface). Writes are gated by a new `spatie` permission **`guardrails.manage`**, granted to **`super_admin` only** — the most safety-sensitive surface, sitting at the top beside `admin.manage`. Reads are open to any admin (auditor browses read-only — oversight). Every accepted change is recorded in append-only `guardrail_config_events` (who, when, from→to). The admin-added blocked-topic / off-domain check runs at the **same pre-router point** as the baseline, so an admin-blocked question also never reaches hr-ai (preserving the privacy guarantee). Blocked-topic input is matched as a normalized, accent-insensitive, **word-boundary literal** — never raw admin-supplied regex (a raw-regex field is both a ReDoS risk and a weakening vector — it could be authored to under-match).

## Consequences

- The documented answer to "can an admin weaken the safety floor?" is **no, by construction** — the floor is code, the admin layer is additive data, and the engine combines them stricter-of on every turn. This is the AEPD / comité de empresa answer beside ADR-0018.
- Operators get real tuning (raise the bar, add topics, set tone, narrow conversion) without code — and the change history makes each tightening accountable.
- hr-ai stays frozen (ADR-0015): tone rides the existing `/synthesise` question argument as a synthesis-local string; no new contract.
- The answer loop is **not** rewritten — Check A/B/C and the guardrail/router decision logic are unchanged; Sprint 6 only retargets the *values read* (via `GuardrailPolicy`), adds the pre-router union, the synthesis-local tone string, and the convert-reason gate. 2b stays frozen.
- A future per-scope guardrail (if ever justified) would extend the read-model, not the callers — the stricter-of contract is the stable seam.
