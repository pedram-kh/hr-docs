# ADR-0033 — Situational/colloquial questions get a second, parallel decomposition array, and the pre-pilot eval is deliberately constructed, not real

**Status:** accepted (Sprint 10b)

## Context

Sprint 10b closes three small gaps found while mining real staging chat traffic against the router and guardrail layers (`sprints/sprint-10b/plan.md` §0/§A-§D): an `explicit_request` escalation reason that has existed in the reason enum and the 7g `EscalationExplainer` matrix/registry since Sprint 4/7g but has never had a producer; one colloquial salary term ("plus de transporte") missing from `RouterService::SALARY_PATTERNS`; and `/synthesise` lacking the truncation-retry hardening `/ground` already has (Correction-04). This ADR is about the fourth and largest item: **situational decomposition**, and about a finding that shapes how every item in this sprint is measured.

**The finding that shapes the measurement.** Every real question found on staging is already phrased in corpus vocabulary — because every real account on staging is a self-authored canonical test question, not an organic employee message. There is no real situational or colloquial phrasing to mine, positive or negative, for any item in this sprint. That is not evidence the problem doesn't exist in real use; it is evidence that staging, as populated today, cannot tell us either way. Sprint 10b builds the capability anyway, on the strength of one instance the real traffic **does** contain (card 9 — an off-topic-labelled `explicit_request` message) and on the general shape of how employees phrase legal questions, which nothing about "staging has no real messages yet" makes less true. The eval that proves the capability works has to be **constructed** — and every table in `review.md` says so, explicitly, next to every row.

**The situational-decomposition problem itself.** The router's existing `subqueries` mechanism (ADR-0016) solves compound questions — "¿cuánto cobro y cuántas vacaciones tengo?" splits into two retrievable sub-topics. It does not solve a *single-topic* question phrased around a situation instead of a legal term — "mi jefe me ha denegado las vacaciones, ¿puede hacerlo?" is one topic (vacation-request refusal), phrased with no word the corpus's own vocabulary would surface on a vector search tuned to convenio/law text. Retrieval can miss this not because the corpus lacks the answer, but because the query and the answer are phrased in different registers.

## Decision

### 1. `decomposed_queries` is a second, parallel array — never a `subqueries` variant

`subqueries` **splits** a compound question into its constituent topics; `decomposed_queries` **rephrases** a (possibly single-topic) question's underlying legal concept into corpus vocabulary, for retrieval only. They answer different questions ("how many topics are in this message?" vs. "how would a lawyer phrase this same single topic?") and a message can need one, the other, both, or neither — a compound *and* situational question is a real, if rare, shape ("mi jefe me ha denegado las vacaciones y además no me paga las extras, ¿qué hago?"). Collapsing them into one field would force an arbitrary choice about which transformation a given entry represents; keeping them parallel costs one more array key and buys that this never has to be decided.

Concretely: `RouterResult` (hr-ai) and the router's `decision()` array (hr-backend) both gain a `decomposed_queries: list[string]` field alongside the existing `subqueries`, defaulting to `[]` everywhere — additive by construction, so every existing construction site is unaffected without being touched.

### 2. The router prompt is extended additively; the model proposes, hr-backend still decides nothing new

`/route`'s system prompt (`hr-ai/app/providers/claude.py::ROUTER_SYSTEM_PROMPT`) gains one additional instruction and one additional JSON schema key. The router still only ever proposes a `label`/`confidence`/`subqueries`/`decomposed_queries` — hr-backend's deterministic gates (the floor checks, Check A/B, `/ground`) are completely unaware that a retrieval pass came from a decomposition rather than the literal question text. This preserves ADR-0015/0016 (the AI never decides; it proposes candidates that a deterministic gate accepts or rejects) exactly as `subqueries` already does — decomposition is not a new kind of AI authority, it is the same kind `subqueries` already has, applied to a different transformation.

### 3. The retrieval union treats `decomposed_queries` exactly like `subqueries` — same join, same cap, same deterministic guard

`ChatService::retrieveUnion()` merges `decomposed_queries` into the SAME query list `subqueries` already joins (`array_merge([$question], $subqueries, $decomposedQueries)`), and the synthesis cap (`SYNTHESIS_CHUNK_CAP + COMPOUND_CAP_PER_SUBQUERY * (count($subqueries) + count($decomposedQueries))`) grows **symmetrically** with both — a situational rephrasing earns the same recall headroom a compound question already gets, so decomposition never costs recall relative to today. On a question with no decomposition (the overwhelming majority, including every question this sprint doesn't touch), both counts are zero and the cap is byte-for-byte identical to pre-10b behaviour.

The deterministic guard that makes this safe to add: for every pass in the union, **only the retrieval `query` text varies**. `convenio_id`, `include_national_law`, `retrieval_status`, `as_of_date`, and `k` are all fixed from the turn's own resolved scope, read once before the union loop begins — never re-derived from, or influenced by, a decomposed query's text. A decomposed query can add or fail to add chunks to the candidate pool; it cannot smuggle a different convenio, a different validity date, a different retrieval-status filter, or a different pool size. The second `retrieveUnion()` call site (the reference-fact answer path, `ChatService.php` ~line 452) receives the new parameter defaulted to `[]` and never wires anything into it — that path short-circuits before the router is ever called, so there is no decomposition to thread through.

### 4. The additivity assertion is on the chunk-id set, not the answer text

A decomposition that changes the retrieved evidence without changing the synthesised answer's substance is not a failure by itself — chunks can be redundant. But an assertion that only checks the final answer text would miss exactly the failure mode worth watching for: a decomposition that adds *noise*, changing which chunks were cited, without changing what the employee reads. `Sprint10bInvariantTest`'s additivity assertion therefore compares the retrieval union's **chunk-id set** before and after a decomposition is added, not the answer string.

## The pre-pilot rationale (why this ships without a real-traffic eval)

This sprint's eval tables are, and are labelled as, **constructed**: hand-written situational phrasings of already-canonical gold questions, not mined organic employee language. That is a deliberate choice, not a shortcut taken for lack of time:

- **Readiness-before-pilot, not readiness-proven-by-pilot.** The goal of this sprint is to have the mechanism built, wired additively, and passing its own invariants BEFORE the first real pilot cohort's messages exist to measure it against. Waiting for real situational traffic to justify building the capability would mean the capability isn't available for the cohort whose messages would justify it — a chicken-and-egg the constructed eval exists to break.
- **The pilot is the real measurement.** Nothing in this ADR claims the constructed eval proves the feature helps real employees. It proves the mechanism is wired correctly, additive, and does not regress anything upstream or downstream of it (the golden-trace gate, the 10a fallback path, the salary/reference-fact paths). Whether real employees actually phrase questions this way, and whether `decomposed_queries` actually recovers chunks a canonical phrasing would have missed, is exactly what the first real pilot cohort's traffic will show — and should be the subject of a follow-up measurement sprint once that traffic exists, not a claim made on constructed data now.
- **Every table says which kind of evidence it is.** `review.md`'s eval tables are labelled real vs. constructed per row, following the same discipline this ADR applies to its own Context section above. A constructed positive proves the pattern CAN fire; it does not prove employees WILL phrase things that way. Only real traffic can prove the second thing, and Sprint 10b does not have any yet.

## Roadmap numbering

The 10-M ops-hardening tickets (the `/synthesise` retry, the `parse_error` enrichment) close with this sprint. **"10c" reverts to its original meaning, fact segmentation** — ops hardening had temporarily occupied that slot in the roadmap's working numbering and is now accounted for here instead.

## Consequences

**What gets better.** A situational/colloquial single-topic question has a deterministic, additive path to recovering the corpus-vocabulary chunk a canonical phrasing would retrieve directly — without touching how any existing question routes, retrieves, or synthesises. `explicit_request` gets its first producer, closing a 7g-era gap between the reason enum and reality. `/synthesise` gets the same truncation resilience `/ground` already has.

**What we accept.** The eval proving this is constructed, not real, and is presented as such. We do not yet know whether real employees phrase questions the way the constructed set guesses they might. We accept building ahead of that evidence because the alternative — waiting for a pilot cohort that doesn't yet exist — has no path to ever building it in time for that same cohort.

**What this depends on.** A real pilot cohort's traffic, measured against this mechanism, as the actual test of whether situational decomposition helps. That measurement is out of scope for this sprint and is recorded here as the natural follow-up.

## Alternatives considered

**Wait for real situational traffic before building anything.** Rejected for the chicken-and-egg reason above — the capability would never be ready for the cohort that would generate the evidence to justify it.

**Fold `decomposed_queries` into `subqueries` as a flag or a type field on each entry.** Rejected: `subqueries` and `decomposed_queries` answer different questions (topic count vs. phrasing register) and a compound-and-situational question needs both independently; a single array with a discriminant field would still need every consumer (the union join, the cap formula, the trace) to branch on it, buying nothing over two plain arrays.

**Apply a confidence threshold to whether a decomposed query joins the union.** Rejected on the same grounds as ADR-0032 §5's rejected alternative: a threshold reintroduces a tunable number where a structural guarantee (any decomposed query can only ADD retrieval candidates, on a re-rank that can only lose ties) already makes the un-thresholded version safe.

## References

- ADR-0007 (hr-ai owns no schema), ADR-0015/0016 (AI never decides), ADR-0016 (the question router and `subqueries`), ADR-0023 (7c additivity — the golden-trace gate this sprint's changes are proven against), ADR-0029 (escalation explanations — `explicit_request`'s existing, untouched matrix entry), ADR-0032 (the Estatuto fallback — shares `retrieveUnion()`/`answerProse()` with this sprint's changes)
- `hr-docs/sprints/sprint-10b/plan.md` §A-§D (the four items), §E (invariants and eval design); `build-prompt.md` (D1-D6, the resolved decisions); `review.md` (measurements, labelled real vs. constructed)
