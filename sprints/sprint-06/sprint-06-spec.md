# Sprint 6 — Guardrails configuration UI

> Location: `hr-docs/sprints/sprint-06/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `architecture.md` §7 (Guardrails — the **two layers**: the hardcoded baseline vs the admin-configurable layer) and §5 (the answer-or-escalate decision — Check A `RETRIEVAL_SCORE_FLOOR`, Check B citations, Check C confidence-as-tiebreaker; both floors are **named config in `config/hr.php`**, "Sprint-6-exposable but additive-only — raise, never lower"); the **`GuardrailService`** (the hardcoded baseline — sensitive/legal-medical/other-employee patterns, fires *before* any `hr-ai` call) and **`RouterService`** (off-domain classification); ADR-0002 (guardrail vocabulary), ADR-0007 (hr-backend owns writes), ADR-0015/0016; `roadmap.md` Sprint 6; the Sprint-3/5 bounded-edit + server-enforced + audited patterns; the Sprint-4 note that "restrict `convert` by escalation reason is a Sprint-6 guardrails-policy matter."
> **The admin layer on top of the hardcoded baseline.** The baseline shipped in 2b — this sprint builds the surface that lets an admin **tighten** it, and **only** tighten it. This is the *first* place guardrails become **data** (admin-editable) rather than code constants.

## Goal
Give an admin a console to **tune the bot's caution** — raise the escalate-instead-of-answer thresholds, add blocked topics, configure the off-domain boundary and message, set tone constraints, and decide whether certain escalation reasons may be converted to knowledge — with one absolute invariant: **every setting can only make the bot more cautious; nothing here can ever weaken the hardcoded safety baseline.** The engine reads the admin layer on top of the baseline and always applies the **stricter** of the two.

## The load-bearing invariant (the spine of this sprint)
**Additive / raise-only, enforced server-side.** No combination of admin settings can make the bot answer something the hardcoded baseline would have escalated. Concretely:
- A threshold can be raised **above** the `config/hr.php` floor, **never** set below it — an attempt is **rejected with a clear message** (decision: reject, not silent-clamp), so the admin *sees* they hit the floor.
- A blocked-topic / sensitive list can be **added to**, never **subtracted from** the hardcoded baseline (the baseline patterns stay in code and are not editable here).
- The off-domain boundary can be **narrowed** (more refusal), the answerable domain never **widened** past the baseline.
- Enforcement is **server-side** (the API rejects an out-of-bounds value; the UI also shows the floor, but the server is the boundary — same discipline as Sprint 5). Proven by tests that POST below-floor values and assert rejection.

## In scope

### A. The config store (first time guardrails become data)
- An additive **`guardrail_config`** store (a settings table or a typed single-row config — the plan picks the shape) holding the admin layer **globally** (decision: **global-only this sprint** — no per-convenio/territory). Every field has a **hardcoded floor/baseline** it is validated against.
- **hr-backend owns it** (ADR-0007); the answer-or-escalate decision + guardrail/router read it and apply **`stricter_of(baseline, admin)`** at decision time. The hardcoded baseline remains the authority; the admin layer can only tighten.
- **Every config change is audited** (who/old/new/when — reuse the append-only audit pattern), and gated by a new ability (e.g. `guardrails.manage`, `super_admin` + the role you choose).

### B. The configurable knobs (all additive-only)
1. **Escalation thresholds** — expose `RETRIEVAL_SCORE_FLOOR` (Check A) and `ANSWER_CONFIDENCE_FLOOR` (Check C tiebreaker) as admin-settable, **clamped at the hardcoded floor from below** (raise → escalate more → safer). The engine uses `max(baseline, admin)`. (Note honestly in the UI that Check C is a *tiebreaker, not a primary gate* — raising it tightens, but Check A/B are the real gates.)
2. **Blocked topics** — admin adds topics/keywords the bot must **escalate** rather than answer; **adds to** the baseline sensitive list, can't remove from it. (Vocabulary-side per ADR-0002.)
3. **Off-domain boundary + message** — configure the "I only handle HR topics" refusal scope and its wording (narrow-only).
4. **Tone constraints** — admin-set tone/style guidance injected into the synthesis prompt (e.g. "formal usted, concise"). Additive guidance; cannot instruct the model to answer something the gates would escalate.
5. **Convert-by-reason policy** — which escalation `reason`s an HR agent may turn into knowledge (the Sprint-4-parked question): e.g. allow `low_confidence`/`salary_coverage_gap`, **disallow** converting a `sensitive_topic` resolution into an answerable ruling. Default conservative; admin can only **restrict further**, not loosen.

### C. The admin UI
- A **Guardrails** console section (reuse the Sprint-3/5 admin shell): each knob with its current value, its **hardcoded floor shown inline** (so the admin sees the boundary), inline validation (reject-below-floor with the clear message), an audit/history view of changes, and a save that's server-validated.
- Read-open to admins who can view; **write gated by `guardrails.manage`**. `auditor` may view (read-only), consistent with their oversight role.

## Out of scope (do NOT build)
- **Weakening the baseline** — structurally impossible (reject-below-floor); the hardcoded `GuardrailService` patterns stay in code and are **not** editable here. The UI sits *on top*.
- **Per-convenio / per-territory guardrails** — global-only this sprint (decision). (A future sprint if ever needed; it would change the config data model.)
- **Rewriting the answer-loop decision** — Sprint 6 feeds *config* into the existing Check-A/B/C + guardrail/router logic; it does **not** change how the decision is computed (2b frozen). `hr-ai` is read-only here (it receives tone guidance in the synthesis prompt via hr-backend; it doesn't migrate or change its endpoints).
- The LLM tagging tier (Sprint 7), analytics (Sprint 8), GDPR hardening (Sprint 9).

## Acceptance criteria
1. A `guardrail_config` store exists (additive migration), global, hr-backend-owned, with a hardcoded floor per field; every change audited; writes gated by `guardrails.manage`.
2. The five knobs work: thresholds (raise-only), blocked topics (add-only), off-domain boundary+message (narrow-only), tone constraints, convert-by-reason policy (restrict-only).
3. **The raise-only invariant is enforced server-side and tested**: POSTing a threshold *below* the hardcoded floor is **rejected** with a clear message (not clamped, not accepted); removing a baseline sensitive pattern is impossible; the engine applies `stricter_of(baseline, admin)`. Proven by tests that attempt to weaken and assert rejection/no-effect.
4. The engine actually *reads* the admin layer at decision time (a raised threshold causes more escalation; a blocked topic escalates; tone guidance reaches synthesis) — shown by a before/after trace.
5. `auditor` can view guardrail config read-only; non-privileged roles cannot change it (server-enforced).
6. All writes hr-backend; additive migration only; no baseline-pattern edit; no answer-loop rewrite; `hr-ai` unchanged (receives tone guidance via the existing synthesis call only).

## Eyes-on
Open the Guardrails console. Raise `RETRIEVAL_SCORE_FLOOR` → confirm a borderline question that previously answered now escalates (before/after). **Try to set it *below* the floor → see the rejection message** (the invariant, visible). Add a blocked topic → confirm a question on it escalates. Set a tone constraint → confirm answers reflect it. Set convert-by-reason to disallow `sensitive_topic` → confirm an HR agent can't convert such a card. As `auditor`, confirm read-only; as a non-privileged admin, confirm no write.

## Risks / notes
- **The invariant is the whole sprint.** The failure mode is a knob (or a clever combination, e.g. tone guidance phrased to override) that *weakens* safety. Every knob must be provably one-directional. Tone constraints especially: they inject text into the synthesis prompt, so the plan must ensure tone guidance **cannot** instruct the model to answer something the gates would escalate (it styles the answer; it doesn't bypass Check A/B or the guardrail).
- **Stricter-of, always.** The engine must apply `max`/`union`/`stricter` of baseline-and-admin, never replace the baseline with the admin value. The baseline is the floor; the admin layer only ratchets up.
- **Reject-below-floor (decision), not silent-clamp** — the admin sees the boundary; no "I set 0.2 but it acts like 0.4" mystery.
- **Read the config cheaply.** The answer loop is hot; reading guardrail config per turn should be a cached/lightweight read, not a heavy query each request — the plan states how.
- **`hr-ai` stays frozen.** Tone guidance reaches synthesis through the existing `hr-backend → /synthesise` prompt assembly; no new hr-ai endpoint, no hr-ai migration.

## Definition of done
All criteria pass; Pedram eyes-on; docs updated — `architecture.md` §7 (the now-built admin layer: the knobs, the **stricter-of(baseline, admin)** rule, the reject-below-floor enforcement, the `guardrails.manage` gate; tone-can't-bypass-gates), `data-model.md` (the `guardrail_config` store + its per-field floors + the audit), `roadmap.md` (Sprint 6 done), an **ADR** if the raise-only config model warrants one (likely — it's a safety-load-bearing decision, cf. ADR-0018), the relevant READMEs. Cursor writes `hr-docs/sprints/sprint-06/review.md` (incl. the invariant-enforcement tests as acceptance proof) and **stops — no commit until I review**.
