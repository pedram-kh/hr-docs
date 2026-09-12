# Sprint 10-M — review

**Status:** built, evaluated on staging, full suite green, docs updated — awaiting review before merge/deploy. Branch `sprint-10-M` in `hr-backend`, `hr-ai`, `hr-docs`. **Nothing committed, nothing merged.**

---

## 1. What this sprint changes

One config value — `HR_AI_ANSWER_MODEL` (`config('services.hr_ai.answer_model')`), which governs **both** `/synthesise` and `/ground` (they share the same `$providerConfig`; `GroundingService` explicitly reuses "the CAPABLE answer model," never the router model) — from `claude-sonnet-4-5` to `claude-sonnet-5`. `/route` and `/explain` stay on `claude-haiku-4-5` throughout, unaffected. Embeddings/retrieval untouched.

One named, narrow, authorized exception to the model-swap-only fence: `/synthesise`'s `max_tokens` 1024 → 4096 (matches `/ground`'s first-tier budget). Rationale for the record (Pedram's authorization, verbatim): *"the 1024 ceiling was tuned against Sonnet 4.5's completion style; keeping it would confound the model measurement with a budget artifact."*

One independent price-table correction, unrelated to whether the swap ships: `hr-ai/app/providers/claude.py`'s `OCR_PRICING_PER_MTOK["claude-sonnet-5"]` corrected from the pre-staged introductory rate `(2.00, 10.00)` to the current standard rate `(3.00, 15.00)` (Anthropic's intro pricing expired 2026-08-31; checked live 2026-09-12).

## 2. CP-A — model string, pricing, latency direction

Reported and confirmed before any config change. Summary: `claude-sonnet-5`, empirically verified live against the real Anthropic API (not guessed) — HTTP 200, `resolved_model: "claude-sonnet-5"`. Cost at par: both models are $3/$15 per MTok as of today (Sonnet 5's $2/$10 introductory rate expired 2026-08-31). Predicted latency direction was "likely up" (adaptive thinking, default `effort: high`) — **measured latency direction was actually down** on real calls; see §5.

## 3. Step 3 — first attempt: hard-requirement miss, reverted

Full detail in [`step3-halt.md`](step3-halt.md). Summary: with `max_tokens` unchanged at 1024, two of the four gold cases broke. Gipuzkoa vacaciones failed non-deterministically (`parse_error: true` on 2 of 3 real calls; the one call that did parse pulled `national_law` into the authority/citation set alongside `official_convenio`, contradicting the gold claim's "convenio only"). Root cause: Sonnet 5 produces longer completions for this endpoint's real prompt shape than Sonnet 4.5 did, and 1024 was tuned against the old model's terser style — a budget artifact, not a model-quality signal. Stopped, reverted the injection, reported, per the sprint's own gate. Pedram authorized `max_tokens` 1024 → 4096 as a named exception (§1) and gave an explicit decision rule for the re-run.

## 4. Step 3 — re-run with the fix: hard requirements met

Re-injected (model string + `max_tokens=4096` + the pricing fix), verified live, ran the four gold cases twice each, then the positive set and both negative sets once each.

### 4.1 Four gold cases, twice each

| case | run 1 | run 2 | matches baseline? |
|---|---|---|---|
| Navarra, periodo de prueba | answer, `official_convenio`, doc 36 only, 15/30 | answer, `official_convenio`, doc 36 only, 15/30 | ✅ both |
| Gipuzkoa, vacaciones | answer, `official_convenio`, **doc 13 only**, 31/26 | answer, `official_convenio`, **doc 13 only**, 31/26 | ✅ both — decision rule's condition met |
| Navarra, trabajo a distancia | answer, `national_law`, doc 75, Ley 10/2021 | answer, `national_law`, doc 75, Ley 10/2021 | ✅ both |
| Gipuzkoa, trabajo a distancia | escalate, `low_confidence` | answer, `national_law`, doc 75, Ley 10/2021 | ⚠️ flapped once, matched on repeat — same class as 10a's own disclosed Q4 margin non-determinism, not a parse/budget failure (`completion_tokens: 783` on the escalating run, well under the 4096 ceiling) |

**Decision rule applied** (Pedram's instruction): Gipuzkoa vacaciones answered with correct figures, `official_convenio`-only authority, and doc-13-only citations on both runs → **hard requirement met, continued to the positive/negative sets** without needing the claim-by-claim breakdown branch.

### 4.2 Positive set — `test-fullgap@` (13-of-15 non-gated questions)

Ran once (per Step 3.2's default), matching baseline's own methodology:

| | baseline (10a, `claude-sonnet-4-5`) | this run (`claude-sonnet-5`, `max_tokens=4096`) |
|---|---|---|
| answer rate | 10/15 (9–10/15 across 10a's own two samples of item 4) | 10/15 |
| grounded | 10/10 answered | 10/10 answered |
| caveat persisted | 10/10 answered | 10/10 answered |
| fallback key leak (must be 0) | 0 | 0 |
| Check-A top score | min 0.5561 · median 0.6214 · max 0.7123 | min 0.5561 · median 0.6214 · max 0.7123 — **byte-identical**, confirms retrieval is untouched |
| item 6 (sensitive) | guardrail, correct | guardrail, correct |
| item 14/15 (salary) | escalate, `salary_coverage_gap`, no fallback key | escalate, `salary_coverage_gap`, no fallback key |

**Item-level movement, read carefully (not a gate, an observation):**

- **Item 5** *"¿Cuánto preaviso tengo que dar si me voy?"* — escalates `low_confidence` on both models. Unchanged.
- **Item 4** *"¿Me pueden despedir durante el periodo de prueba?"* — known margin-flap (10a's own baseline: escalate then answer across its two samples). Escalated on both Sonnet 5 samples taken this session. **A same-day repeat of this exact question on `claude-sonnet-4-5` (run purely to get the cost/latency numbers in §5) also escalated** — so item 4's flap is not model-specific; it is confirmed to flap on both models, sample sizes are too small (1–2 each) to say either model flaps *more*.
- **Item 13** *"¿Cuánto dura el permiso por nacimiento?"* — this is the one genuinely interesting movement, and it's inconclusive rather than a clean win: 10a's original baseline sample **answered**; this sprint's Sonnet 5 run **answered**; but a same-day repeat on `claude-sonnet-4-5` (run for §5's cost numbers) **escalated**. So item 13 flaps on `claude-sonnet-4-5` too, on this same day. **Retracting my earlier read that Sonnet 5 "fixed" item 13** — the honest statement is that item 13 is margin-non-deterministic on both models, and this sprint did not gather enough samples (1–2 per model) to claim a directional improvement. A future sprint wanting to answer "does Sonnet 5 improve the margin cases" should run each of items 4/5/13 at least 5 times per model, which this sprint's scope did not budget for.

### 4.3 Negative sets — both 15/15 PASS, unchanged

| | `test-andalucia@` (baseline / this run) | `test-midingest@` (baseline / this run) |
|---|---|---|
| answer rate | 0/15 / 0/15 | 0/15 / 0/15 |
| fallback key leak | 0 / 0 | 0 / 0 |
| trigger split | HOLDS / HOLDS | HOLDS / HOLDS |

**Not one question was answered from the Estatuto on either profile, on either model, and no turn carried a fallback key. The hard requirement holds exactly.**

### 4.4 Truncation watch — zero, confirmed by direct query

Every real turn from the fixed re-run (positive + both negative sets + the 8 gold-case turns + the item-4 repeat, 54 turns total) was queried directly from `chat_message_traces` for `parse_error` and `grounding_truncated`/`retried_on_truncation`. **Zero of either**, across all 54. The 2 `parse_error` events that did occur belong unambiguously to the *first* (reverted) attempt at `max_tokens: 1024` — confirmed by message timestamp, both before the fix was injected.

## 5. Cost and latency — real numbers, both models, same questions

Grouped every real `/synthesise` call made this session by the model recorded in its own trace (not by timestamp guessing), summed and averaged:

| model | n (real calls) | avg cost/call | avg latency | avg prompt tokens | avg completion tokens |
|---|---|---|---|---|---|
| `claude-sonnet-4-5` | 16 | $0.03506 | 6581 ms | 10471 | 243 |
| `claude-sonnet-5` | 26 | $0.04617 (**+32%**) | 5259 ms (**−20%, faster**) | 13076 (**+25%**) | 463 (**+90%**) |

Both averages reconcile exactly against the corrected `$3/$15`-per-MTok rate (`10471×3 + 243×15 = $0.03506` for 4.5; `13076×3 + 463×15 = $0.04617` for 5 — confirms the pricing fix is internally consistent). Note `n` differs (16 vs 26 — more Sonnet-5 calls were made over the course of diagnosing and re-running) so the **per-call averages are the fair comparison, not the raw sums**.

**Reading it:** per-token price is identical between the two models today, so the +32% cost delta is entirely a token-count effect: +25% more prompt tokens for the *same* chunk content (Sonnet 5's new tokenizer — Anthropic's own migration notes warn "this may use up to 35% more tokens for the same fixed text" for the Opus-4.7-generation tokenizer change Sonnet 5 inherits) and +90% more completion tokens (genuinely longer, more elaborated answers — visible qualitatively in every transcript this session; the trabajo-a-distancia answers in particular run to 4–6 sentences of additional nuance not present in the shorter Sonnet 4.5 style). **Latency went the other way from the pre-swap prediction**: Sonnet 5 was ~20% *faster* on average despite generating ~90% more output tokens — the "Fast" comparative-latency rating and infrastructure improvements evidently outweigh the extra generation volume for this task shape.

## 5a. Step 4 — golden traces: nothing to regenerate, verified rather than assumed

The prompt's premise was that `Sprint7cAdditivityRegressionTest` "pins byte-for-byte outputs; a new synthesis model changes bytes by definition." **That premise doesn't hold for this specific test, and it's worth being precise about why rather than fabricating a regeneration that wouldn't mean anything:**

Reading `Sprint7cAdditivityRegressionTest.php`'s `bindFakeAi()`: it replaces `ExtractionClient` with an anonymous class whose `route()`, `retrieve()`, `synthesise()`, and `ground()` methods **return hardcoded, scripted values** — no HTTP call, no hr-ai, no Anthropic API, regardless of what `$providerConfig['model']` is passed in. The test's own docblock confirms the intent: *"Captured GREEN against pre-7c **code** (the baseline)"* — the baseline this test pins is hr-backend's own additive-composition logic (retrieval union + precedence rerank, `floor_decision` shape, the salary-SQL path, the 7c reference-fact pre-check falling through), deliberately made independent of real model variance so the regression gate itself doesn't flap. Sprint 07c's own `review.md` calls this exact design choice out: *"scripts hr-ai... deterministically so the prose + salary turns are fully reproducible."*

**Verified, not just read:** ran the test against this sprint's actual working tree (`answer_model` default already changed to `claude-sonnet-5`, `max_tokens` already 4096) — 3/3 pass, 39/39 assertions, **5.3 seconds** total for all three tests. Eight real Anthropic calls at this session's own measured latencies (§5: 5.3–16.7s each) would alone take longer than that; the timing is independent confirmation the test never left the process, on top of the code-reading confirmation.

**Conclusion: no golden baseline needed regenerating, no structural diff exists to review, because none of this sprint's changes are reachable by this test's code path.** This is recorded as a finding, not skipped silently — and carried to `roadmap.md` as a note for the next reader who might otherwise re-plan the same regeneration step against a test that was never sensitive to the thing being measured.

## 5b. Step 5 — full suite, chunker guards

| gate | result |
|---|---|
| `hr-backend` full PHPUnit suite | **631/631 passed**, 2919 assertions |
| `hr-ai` chunker guards (`scripts/chunker_guards_test.py`) | **30/30 passed** (unaffected — this sprint touches no chunking code) |

Both run against the `sprint-10-M` working tree (model default, `max_tokens`, and the `OCR_PRICING_PER_MTOK` fix all in place).

## 6. Follow-ups for the record (no code this sprint, per Pedram's instruction)

- **`/synthesise` has no truncation-retry path; `/ground` does.** `/ground`'s `client.messages.create()` call retries once at a larger budget (`GROUND_MAX_TOKENS` 4096 → `GROUND_MAX_TOKENS_RETRY` 8192) when `stop_reason == "max_tokens"`, treating a truncation as a budget problem rather than evidence of anything about the answer (Correction-04's own reasoning, sprint 10a). `/synthesise` has never had an equivalent — any truncation there is a silent, terminal, no-explanation escalation. This sprint raised the ceiling (§1) rather than adding a retry path (out of the swap-only fence's intent), but the asymmetry is real and pre-existing, independent of this sprint's model choice, and worth a future ticket.
- **A `parse_error: true` trace records nothing about *why*.** No `stop_reason`, no token counts, no block types — diagnosing this sprint's Step 3 halt required a hand-instrumented, temporary diagnostic build (added and removed, never committed) to see that the model was hitting the token ceiling. A permanent, minimal addition to the existing `parse_error` trace shape (e.g. `stop_reason`, `completion_tokens`) would have made this a five-minute read instead of a stop-the-sprint investigation. Also out of scope for this sprint's own fence (a prompt/trace-shape change, not a model swap) — flagging for a future ticket, not building here.

## 7. Docs updated

- **New:** `architecture.md` §2 gains a "Models in use (tooling)" table — all four endpoints' model knobs, current values, and why each is separate; records this sprint's swap and the `max_tokens` exception.
- **New:** `deploy.md` §1 gains the `inference_geo`/AEPD note (Pedram's instruction); a new "Session 8" build-record entry under §7 documents the manual-recreate env-export lesson (found live at Sprint 10a's close, re-applied correctly throughout this sprint's own staging work) and this sprint's `max_tokens` change.
- **New:** `roadmap.md` gains the Sprint 10-M **DONE** entry and a new Sprint 10c follow-ups entry (the two tickets from §6: `/synthesise`'s missing truncation-retry path, and `parse_error` trace enrichment).
- This document (`review.md`) — before/after tables (§4), the Step 4 verdict (§5a), full-suite results (§5b), cost/latency (§5), follow-ups (§6).

## 8. What I would flag to a reviewer

- **The Step 4 finding (§5a) is the one thing worth double-checking independently** — it means this sprint ships with *zero* regression coverage that actually exercises the new model's real output shape end-to-end in a committed test (the eval harness does that, but it's a staging-only measurement, not a repo-committed, CI-run test). That's not a defect introduced by this sprint — it's a pre-existing property of `Sprint7cAdditivityRegressionTest`'s design — but it means "the golden trace is green" was never going to be evidence either way about the model swap, and a reviewer should not read its passing as model-side proof of anything.
- **Item 13's flap (§4.2) is deliberately reported as inconclusive, not as an improvement** — I initially misread it as one, caught it by getting a same-day Sonnet-4-5 comparison sample for the cost numbers, and corrected it in place rather than leaving the more flattering first read standing.
- **Gipuzkoa trabajo a distancia still flaps once every few runs on both models** — matches 10a's own already-disclosed margin non-determinism (three LLM calls in the loop); not this sprint's to fix, noted for continuity.

## 9. Not yet done — the remaining eyes-on and close sequence

- **STOP for review** (this document, as written, is the review gate). Then: Pedram's light eyes-on (two browser questions, tone/length spot-check), then merge `--no-ff`, push, `deploy.sh`, recreate containers with the deploy env exports, verify live model strings, snapshot `hr-staging-post-10M`, delete `hr-staging-pre-10a-rechunk` (keep `post-10a`).
