# Slice 13c — Spec: General-knowledge lane, made real

> Save as: `hr-docs/sprints/sprint-13c/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Origin: Sprint 13 shipped the lane safe-but-inert (0/28 positives reached it; F.8 relaxation left off). Small slice, one gate, one checkpoint.

---

## 1. The problem, measured

Sprint 13's lane: web-sourced-only, 5 catalogue pages, opens only after Check A misses or entailment fails. Result: **0 of 28 explanatory positives** got a lane answer end-to-end, and the 225-turn negative set was therefore uninformative — the lane never ran. The employee-facing promise ("explanatory questions get an explanation, badged, with an escalate button") is not delivered. Questions like "¿qué es una excedencia?" and "¿qué es el finiquito?" escalate to HR today.

Sprint 13's gate also left the right safety instrument built and idle: the **forced-lane harness** (`lane-forced.php`) makes the lane draft for every question and runs `GeneralLanePostCheck` + `audit()` on the draft. That — not end-to-end negatives — is the evidence that decides whether model knowledge can be allowed.

## 2. The principle

**The lane explains; it never grants.** It may say what an excedencia *is*; it may never say how long *yours* is. The post-check enforces that as code (no figures, durations, amounts, entitlement language), and the pre-screen keeps entitlement questions out before any draft.

## 3. In scope

- **Model knowledge on** (F.8 relaxation) as the default source after the corpus: source order corpus → national law → model knowledge → catalogue page (web) if the topic has one. Web stays allowlisted-official-only, PII-scrubbed, as built.
- **Positive set rebuilt from questions the corpus cannot answer:** ≥ 20 explanatory questions verified by a read-only Check A run to return no material *and* not to be entitlement-shaped (definitions and how-it-works: excedencia, finiquito, IT, ERTE, preaviso, nómina line items, convenio vs Estatuto, etc.). Frozen and hashed.
- **Forced-lane harness as the standing gate:** every negative (the 25 from Sprint 13 + ≥ 15 new entitlement-shaped ones) forced through the lane ×3; every draft must be blocked by the post-check or contain nothing the audit flags. Also run on every positive: drafts must pass the post-check and answer the question.
- **Length and shape:** ≤ 120 words, ends with the badge and the escalate button; if the corpus later gains a fact on the topic, the corpus answers (existing precedence).
- **Guardarraíles toggle** already exists; add the **catalogue** as an editable list there (super_admin), so HR can add an official page without a deploy — content decisions stay human.
- **Pre-screen review:** the explanatory-vs-entitlement pre-screen gets its own fixture set (≥ 30 each way) and must classify ≥ 95% of entitlement questions as entitlement.

## 4. Out of scope

Open web search; PDF sources (Guía Laboral — separate ticket); the lane answering anything with a figure; changing the post-check's vocabulary beyond what the forced-lane results require (any change is a decision, recorded).

## 5. Acceptance criteria — the gate

1. **Positives:** ≥ 15 of 20 answered from the lane end-to-end on staging, badge present, escalate button present, each ≤ 120 words, ×2.
2. **Forced-lane negatives ×3:** 0 drafts pass the post-check; 0 audit hits on anything that did.
3. **End-to-end negatives ×2** (the Sprint 13 set): 0 lane answers, 0 must-escalate answered — now *informative*, because the lane actually runs.
4. **Pre-screen fixtures:** ≥ 95% entitlement recall; false-explanatory rate reported.
5. Whitelist ×1, gold-2c ×1, situational ×1: no regression.
6. Golden traces byte-identical (lane off = classic unchanged); lane-on fixtures added.
7. Latency p50 for lane answers reported; cost per lane answer reported.
8. Spend ≤ $25.

## 6. Checkpoint

**CP-1 (single):** gate results + five lane answers live on staging (excedencia, finiquito, IT, ERTE, preaviso) — Pedram reads them as an employee would: is each a clear explanation, is the badge unmissable, does nothing read like a promise about *my* rights?

## 7. Risks / plan-gate questions

- **R1:** model-knowledge drafts drifting into figures despite the prompt — the post-check is the guard; the plan reports the block rate on positives (too high = the prompt needs work, not the check).
- **R2:** the pre-screen's edge: "¿cómo funciona el periodo de prueba?" (explanatory) vs "¿cuánto dura mi periodo de prueba?" (entitlement) — fixtures must cover the minimal pairs.
- **R3:** an explanatory question whose honest explanation *needs* a figure ("¿qué es el SMI?") — the plan proposes the lane's behaviour (answer without the figure and point to the source, or escalate).
- **R4:** model knowledge is not grounded against anything; the answer must state that in the badge and the trace records `authority: general_knowledge` — no citation is fabricated.
