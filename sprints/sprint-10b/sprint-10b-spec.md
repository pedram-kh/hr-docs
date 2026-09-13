# Sprint 10b — Spec: Situational decomposition + colloquial lexicon + explicit_request + loop ops-hardening

> Save as: `hr-docs/sprints/sprint-10b/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Depends on: ADR-0011 (closed vocabulary), ADR-0015/0016 (AI never decides), ADR-0023 (additivity), ADR-0030 (analytics). New ADR expected: **ADR-0033** (situational decomposition; plan confirms next free number — 0031 stays reserved).
> Numbering note (resolves the roadmap collision): the two 10-M follow-up tickets Cursor filed under a new "Sprint 10c" roadmap entry are **absorbed into this sprint** as item 4; the 10c name reverts to batch fact segmentation. The plan updates roadmap.md accordingly.

---

## 1. Goal

Employees ask situational, colloquial questions ("mi jefe me ha denegado las vacaciones, ¿puede?", "¿cuándo cobro la paga extra?", "quiero hablar con una persona"). The corpus speaks convenio language. This sprint closes that vocabulary gap at the three points where it costs answers today — retrieval phrasing, pre-classifier routing, and the human-request path — plus two small ops-hardening items in the same code region. Everything is additive; the golden-trace regression and the 10a/10-M eval sets must be unchanged for questions these features don't touch.

## 2. In scope

### 2.1 Situational-question decomposition (the risky item — build last)

- An AI-produced **query decomposition**: from the employee's message, extract the underlying legal question(s) in corpus vocabulary (e.g. "mi jefe me ha denegado las vacaciones, ¿puede?" → "régimen de disfrute y fijación de vacaciones", "facultades de dirección"). 
- **Union, never replace:** decomposed queries join the existing recall-hardened retrieval union as additional sub-queries. The original message always remains a retrieval query. A bad decomposition can add noise the re-rank must beat, but can never *remove* recall. This is the safety-by-construction core of the feature.
- **No new latency by default:** preferred design is extending the existing `/route` call (Haiku already reads every message) to optionally return `decomposed_queries[]`, rather than a new endpoint/call. The plan verifies feasibility against the real router prompt and response contract; a separate call is the fallback design and needs a latency measurement.
- Decomposition **never** introduces scope (territory, convenio, group) — it rephrases the topic only. Deterministic guard: decomposed queries are used for retrieval text only; they touch no scope resolution, no routing precedence, no authority.
- Trace records `decomposed_queries` so Analítica and reviewers can see what the rewrite did.
- **The eval is the deliverable:** gold set of situational phrasings paired with their canonical-form twins, built from (a) the real escalation cards and Analítica clusters on staging, (b) constructed situational variants of the 10a/2c gold questions — stated honestly in the review as constructed where real traffic is thin. Measure: retrieval hit-rate delta on situational forms; **zero change** on canonical forms (the union must be a no-op when the message is already canonical or the decomposition adds nothing new).

### 2.2 Colloquial lexicon for the deterministic pre-classifiers

- Mine the real escalation cards, question clusters, and chat history for employee vocabulary that should have routed to the salary or reference-fact pre-classifiers and didn't ("paga extra", "nómina", "días de asuntos propios", "plus de transporte", …).
- **Per-term destination is a human decision** (ADR-0011 pattern: AI may propose the term list; a human approves each term→destination mapping in the sprint doc before build). This matters because colloquial terms are not all salary terms — e.g. "finiquito" is a settlement *concept* (prose path), not a salary-table row; routing it to the salary path would manufacture wrong escalations. The approved mapping table lives in the spec/plan and ships as a deterministic closed lexicon.
- Regression guard: every existing salary/fact gold question routes identically before/after (the lexicon only adds matches, never changes or removes one).

### 2.3 `explicit_request` — give the reason a producer

- 10a's plan found `explicit_request` exists in the enum and the 7g matrix but **no code path ever emits it**; "quiero hablar con una persona" currently routes `off_domain` (real card 9). 
- Fix: a deterministic phrase lexicon checked **before** the LLM router (same pattern as the other pre-checks): human-request phrasings → escalate `explicit_request` immediately, with its existing matrix explanation and the standard neutral employee message. Closed list, human-approved, plan proposes it from real phrasings.
- Off-domain behavior for everything else unchanged.

### 2.4 Ops hardening (the absorbed 10-M tickets — small, same code region)

- **`/synthesise` truncation retry:** mirror `/ground`'s existing pattern — on `stop_reason == "max_tokens"`, retry once at a doubled budget; a second truncation escalates as today. No prompt change.
- **`parse_error` trace enrichment:** when synthesis output fails to parse, the trace additionally records `stop_reason` and `completion_tokens`. Diagnostics only; no behavior change.

## 3. Out of scope

- Any change to scope resolution, guardrails, authority precedence, publish fence, salary answering, fallback trigger.
- Hybrid/lexical retrieval (post-pilot), multi-turn retrieval context (post-pilot), clarify (10d, gated on evidence), fact segmentation (10c).
- Router model change; effort/thinking controls.
- Any lexicon term whose destination a human has not explicitly approved.

## 4. Acceptance criteria

1. `Sprint10bInvariantTest`: decomposition union is additive (canonical question ⇒ identical retrieval set); decomposed queries cannot reach scope resolution; lexicon adds matches only (regression set routes identically); `explicit_request` fires on the approved phrases and only those; matrix/guard coverage for `explicit_request` (it now has a producer — the 7g completeness guard must still hold); synthesise retry fires once and only on `max_tokens`; enriched `parse_error` fields present on a forced parse failure.
2. Evals run and recorded with gold sets committed under `sprints/sprint-10b/eval/`: situational set (hit-rate delta + canonical no-op), lexicon set (new matches + regression), explicit_request set (positives + near-miss negatives like "¿con quién hablo para pedir vacaciones?" which must NOT fire).
3. Golden-trace regression green; 10a fallback negative sets re-run once, still 0.
4. Eyes-on (§6) passes on staging. ADR-0033 written. Roadmap numbering fixed per header note.

## 5. Risks / plan-gate questions

- **R1 — decomposition noise:** extra sub-queries could dilute precision. Mitigation: precedence re-rank already handles pool widening; the eval must include a no-regression check on the full 10a positive set. Plan verifies pool-size limits.
- **R2 — router contract change:** extending `/route`'s response shape touches a frozen-ish interface; plan must show the change is additive for existing consumers and what happens when the field is absent (old behavior, fail-safe).
- **R3 — lexicon false positives:** the near-miss negative set exists for this; every approved term needs at least one negative in the eval.
- **R4 — thin real traffic:** most staging traffic is our own tests. Gold sets will be part-constructed; the review must label which is which, and the pilot remains the real measurement.
- **R5 — explicit_request phrasing breadth:** too narrow misses real asks, too broad hijacks answerable questions. Start narrow (unambiguous human-request phrases only); widen from pilot data.

## 6. Eyes-on (staging, real browser, chat included)

1. As `test-navarra@`: ask "mi jefe me ha denegado las vacaciones, ¿puede hacerlo?" → answer grounded in the convenio's vacation regime (or a correct escalation — judge the trace: decomposed queries visible, retrieval included convenio vacation chunks).
2. Same account: a canonical question from the 2c gold set → answer identical in substance to before; trace shows decomposition added nothing or its additions changed no citations.
3. A colloquial salary phrasing from the approved lexicon (e.g. "¿cuánto es la paga extra?") → salary path (trace: salary pre-classifier), correct cell or correct salary escalation.
4. "Quiero hablar con una persona de recursos humanos" → immediate neutral escalation; admin card shows `explicit_request` with its matrix explanation; board filter isolates it.
5. Near-miss: "¿con quién hablo para pedir mis vacaciones?" → NOT explicit_request; normal loop.
6. Admin: traces render `decomposed_queries`; Analítica unaffected except any new reason rows.

## 7. Deliverables

- `sprints/sprint-10b/{spec,plan,build-prompt,review}.md`, `eval/`
- `adr/ADR-0033-situational-decomposition.md` (path per repo layout)
- Updates: architecture.md (router/pre-check diagram + decomposition), roadmap.md (numbering fix, 10-M tickets closed here), data-model.md (trace fields: `decomposed_queries`, enriched `parse_error`)
