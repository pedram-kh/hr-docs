# ADR-0032 — The Estatuto answers only when we never had the convenio; an expired convenio escalates, because under ultraactividad it still governs

**Status:** accepted (Sprint 10a)

## Context

Some employees are bound to a convenio whose text is not in the corpus. Today every prose question from them escalates, no matter how ordinary. "How many days of holiday do I get?" has a correct answer under Spanish law — the Estatuto de los Trabajadores sets a statutory floor of thirty calendar days — and the platform holds the Estatuto, embedded and retrievable, as `national_law` (authority level 2). It simply never reaches those employees, because scope resolution filters retrieval to their convenio and there is nothing there.

The obvious move is to answer from the Estatuto whenever the convenio has no retrievable prose. That move is wrong, and the reason is the whole point of this ADR.

**A collective agreement does not stop applying when it expires.** Under ET art. 86.4 (*ultraactividad*), an expired convenio generally remains in force until a new one is negotiated. Its terms are, by construction, at least as good as the statutory floor — that is what collective bargaining is for. So for an employee whose convenio text exists in the system but is not being served, answering from the Estatuto would hand them the statutory *minimum* while the agreement that actually governs them says something better. The answer would be grounded, cited, confident, and worse than the truth, in the direction that carries legal weight.

That failure is invisible in the trace. Both cases look identical from inside the retrieval loop: zero eligible prose chunks for this convenio. The distinction is not in what retrieval returns; it is in *why* it returned nothing, and that lives in the documents table, not the chunks table.

On the staging corpus at the time of writing, both cases are real and neither is rare. Convenio 7 (Acción e Intervención Social Estatal) has no document of any kind. Convenios 4 and 21 have convenio texts that expired on 2025-12-31 with no successor sourced. Convenio 16 has a convenio text that was loaded and never chunked.

## Decision

### 1. The trigger is a split, not a threshold

`CorpusCoverageService::classifyProseGap(int $convenioId)` returns exactly one of three values, and the answer loop branches on it:

| classification | meaning | behaviour |
|---|---|---|
| `covered` | at least one **active** prose document with **at least one chunk** | unchanged — today's behaviour in full |
| `never_ingested` | **no prose document of ANY retrieval status**, and no chunks of any status | the Estatuto fallback fires |
| `expired_only` | anything else — a prose document exists but is not being served | **escalate** `estatuto_fallback_gap`; the Estatuto must not substitute |

`never_ingested` is the narrow case and is deliberately hard to reach. It is not "nothing is retrievable"; it is "there is no evidence this convenio's text was ever in the system". The moment a document exists — historical, under review, active-but-unchunked, a scan with no text layer — the fallback is off. We may be wrong about *why* the text is not being served, and every wrong guess in that direction ends with an employee told the statutory minimum when their own agreement says better.

### 2. The mid-ingest case fails closed

A prose document that exists with zero chunks is `expired_only`, not `never_ingested`. This is the case that would otherwise arrive by accident: a document uploaded thirty seconds ago, or one whose embed job failed, is indistinguishable from "never had it" if you only look at chunks. Requiring the absence of the *document* as well as the absence of *chunks* closes that window. The cost is that a genuinely-never-ingested convenio which happens to carry a stray document row does not get the fallback, and that is the direction we want to be wrong in.

### 3. Absence of retrievable text is decided by hr-backend reading its own database, never by whether retrieval happened to return something

The classification is a database read performed before retrieval, not an inference from an empty `/retrieve` response. An empty response can mean the query embedded poorly, the ANN index missed, or the service was briefly down — none of which are "we do not have this convenio's text". Deciding on the response would make the fallback fire on a transient failure, which is the worst possible trigger for it.

### 4. hr-ai is unchanged (ADR-0007)

The fallback path passes `convenio_id: null` to the existing `/retrieve`, which has always returned national law alone for a null convenio — the same behaviour the recall-hardening national-law pass already depends on. No new endpoint, no new parameter, no schema change on the hr-ai side. The fallback is a hr-backend decision expressed through an existing hr-ai capability.

### 5. A fallback answer is national law alone, and says so to the employee

On the fallback path every chunk reaching `/synthesise` is `national_law`; `authority_used` is exactly `['national_law']`. The precedence re-rank is skipped and records that it was skipped: its entire job is to adjudicate convenio-versus-baseline, and on this path there is no convenio side, so running it would leave a trace implying an adjudication that never happened.

The answer carries a caveat stating that it is based on the statutory minimum and that the employee's own convenio may improve on it. The caveat is appended at `persistTurn`, after every gate, and is asserted against `chat_messages.content` rather than the API payload — the persisted row is what the employee actually reads and what HR later reviews.

### 6. The employee never learns which gap they fell into

`estatuto_fallback_gap` is an HR-facing reason. The employee sees the same single neutral escalation message as every other reason (ADR-0029). Telling them "your convenio is expired" would be leaking an internal corpus state that they can do nothing about, and that HR has not yet confirmed.

### 7. Every gate before the prose path keeps precedence

The fallback lives on the prose path only, at the end of the existing order. A salary question escalates `salary_coverage_gap` and never reaches it — the Estatuto sets no salary figures. A verified reference fact still answers first: `structured_reference` outranks `national_law`, and that does not change because prose happens to be empty. The sensitive-topic guardrail still fires pre-router. None of these were modified; the fallback was added underneath them.

## Consequences

**What gets better.** Employees on a never-ingested convenio get correct, grounded, cited answers to ordinary statutory questions instead of a blanket escalation. Measured on the Sprint 10a gold set: 10 of 13 answerable questions answered, all grounded, all carrying the caveat, against a prior answer rate of zero.

**What we accept.** An employee whose convenio is expired-but-governing still gets nothing, and now generates a card. That is the intended trade: the card names the cause and the fix, so the gap gets closed by sourcing the text rather than papered over by answering from the wrong source. On the same gold set, all 13 answerable questions escalated for both expired-convenio profiles, and not one was answered from the Estatuto.

**What this depends on.** The Estatuto's chunks have to be good enough to answer from. They were not: the Estatuto was held out of the Sprint 2c article-boundary re-chunk, and its index pages were being retrieved as though they were content. Sprint 10a re-chunked and re-embedded it, which is a prerequisite of this decision rather than a side errand — see the sprint review for the before/after.

**The reason enum grows by one.** `estatuto_fallback_gap`, with four sub-outcomes distinguishing the causes HR would act on differently (expired with no successor, tagging under review, pending embed, scan with no text). Each carries a deterministic fix action and deep link, per ADR-0029.

**What we did NOT do.** No confidence threshold, no "answer from the Estatuto if the convenio's chunks score below X". A threshold would reintroduce exactly the ambiguity this ADR removes, and would make the safety property depend on a tunable number rather than on a fact about the corpus.

## Alternatives considered

**Answer from the Estatuto whenever prose retrieval is empty.** Simplest, and wrong for the reason above: it answers the ultraactividad case with the statutory minimum. Rejected on legal correctness, not on engineering grounds.

**Answer from the Estatuto but caveat harder for expired convenios.** Puts the burden on the employee to know that "your convenio may say more" means "your convenio almost certainly says more, and it legally governs you". A caveat is not a substitute for not answering.

**Show the expired convenio's own historical text.** Tempting, since under ultraactividad it is very likely still what governs. Rejected for this sprint: serving `historical` chunks would overturn the retrieval-status contract the whole corpus model rests on, and "very likely still governs" is a legal judgment the platform must not make on its own (ADR-0015/0016). The right fix is for a human to confirm the status and re-activate or supersede the document — which is exactly what the escalation card asks for.

## References

- ET art. 86.4 (ultraactividad); ET arts. 14, 34, 35, 37, 38, 48, 49, 53 (the statutory floors the gold set exercises)
- ADR-0007 (hr-ai owns no schema), ADR-0015/0016 (AI never decides), ADR-0017 (article-boundary chunking), ADR-0029 (escalation explanations), ADR-0030 (coverage service)
- `hr-docs/sprints/sprint-10a/plan.md` §C.6.3 (the split), §E.3 (invariants); `review.md` (measurements)
- ADR-0031 is reserved and unwritten: the clarifying-question turn was deferred to a future sprint, and the falsification probe recorded in the Sprint 10a review is the evidence it should be specced against.
