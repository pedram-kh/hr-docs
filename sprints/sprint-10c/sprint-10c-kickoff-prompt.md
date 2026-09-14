# Sprint 10c — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-10c/kickoff-prompt.md`

---

You are planning **Sprint 10c** in the hr-platform workspace. Read `hr-docs/sprints/sprint-10c/spec.md` first. Write `hr-docs/sprints/sprint-10c/plan.md`, then **STOP — no code, no prompt changes, no batch runs, no commits.**

Ground rules: inspect the real code, the real corpus, and the live staging DB; cite `path:line` and real document/convenio ids. ADR-0020 is the spine: nothing this sprint proposes may weaken one-human-one-fact verification.

## A. The agent as it actually is

1. Locate the 7b-2 segmentation agent end-to-end: the job (`SegmentReferenceSource` or successor), the hr-ai endpoint, the prompt, the restraint rules, and where topic is determined. Is the topic hardcoded/configured per run, inferred from the source, or constrained by a topic parameter? Cite lines. What exactly must change (if anything) to run it per-topic across the tranche?
2. Show where validity is read (the job-time vs dispatch-time issue, spec §2.4) and propose the capture-at-dispatch fix with the exact fields.
3. Show what the agent does today with a group-dependent clause when the convenio has no approved tree — restraint, refusal record, or convenio-wide extraction? Cite the code and one real 7b-2 output demonstrating it. If enforcement is prompt-only, say so plainly and propose the deterministic backstop.

## B. The corpus, sampled for real

4. For each tranche topic (permisos, vacaciones, preaviso, jornada anual, festivos, descanso, horas extra): pull 3–5 real passages from different ingested convenios and assess extractability — clean scoped figures vs narrative vs group-dependent tables. Produce a per-topic difficulty table with real quotes (short) and predict which topics pass the 0-mis-scope gate easily vs need prompt iteration.
5. Count eligible sources per topic: convenios with ingested active text × passages the topic lexicon anchors. State per-topic expected yield ranges and the R2 group-dependence density estimate (how much yield is locked behind the 5 pending trees — quantified, for the data-pass handoff).
6. Convenios 4/21 (expired): confirm the skip recommendation (spec R5) against how the agent selects sources today — would it pick them up by default?

## C. Queue, ranking, cost

7. Current review-queue state (counts by topic/status) and how it's ordered today. If Analítica demand-ranking isn't wired into the queue ordering, propose the minimal change (ordering/presentation only — no bulk actions).
8. Confirm what the reviewer sees per fact (7g's excerpt work) suffices for fast verification; propose only what's missing, if anything.
9. Cost/runtime estimate: tokens per source (from real 7b-2 runs), sources per topic, total spend and wall-clock for the tranche; resize needed or not (spec R4).

## D. Evals + plan output

10. Propose the per-topic gold-fixture method concretely for the first two topics (permisos, vacaciones): which real passages, how many, what the mis-scope/restraint assertions are, harness location. Later topics follow the pattern after the first two prove it.
11. End with: ordered steps (validity fix first; then topic 1 gate → batch → topic 2 …), the batch ceiling proposal (R3), invariant tests (additivity proof per spec §4.3; dispatch-validity test; the group-restraint backstop if A.3 found prompt-only enforcement), open questions, and ⏸ checkpoints — at minimum: Pedram approves the topic order + batch ceilings before any batch run, and reviews topic 1's eval results before topic 2 starts.

Then **STOP** and wait for review.
