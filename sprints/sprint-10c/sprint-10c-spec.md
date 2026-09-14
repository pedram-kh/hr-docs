# Sprint 10c — Spec: Batch fact segmentation across topics

> Save as: `hr-docs/sprints/sprint-10c/spec.md`
> Status: SPEC — plan gate next. No code, no batch runs, until the plan is reviewed and build is authorized.
> Depends on: ADR-0020 (inert until verified — the spine of this sprint), ADR-0021/0022 (fact substrate + segmentation agent), ADR-0028 (group scope), 7c (routed fact answers). New ADR only if the plan surfaces a real decision (next free number: 0034).

---

## 1. Goal

The 7b-2 segmentation agent is proven (0 mis-scoped on real fixtures, restraint rules, eval-as-deliverable) but has only ever been pointed at one topic: **periodo de prueba**. Every other topic — including ones real questions already hit (permiso por matrimonio, fallecimiento, nacimiento; vacaciones; preaviso; festivos; descanso; horas extra) — has zero reference facts, so those questions ride the prose path or escalate. This sprint runs the agent across the corpus for the next tranche of topics, **one topic at a time, each gated by its own eval**, producing `needs_review` facts for HR to verify in the ranked queue. Every fact answered verbatim instead of synthesized is an accuracy upgrade, not just coverage.

Facts come *from* convenio text: **full-gap convenios get nothing from this sprint** — that remains the data pass's problem. This sprint multiplies the value of the text we already have.

## 2. In scope

### 2.1 Topic tranche, priority-ordered by real demand

From Analítica clusters and the real 39 questions, the proposed order (plan confirms against current cluster weights): **permisos** (matrimonio / fallecimiento / nacimiento are all real observed questions), **vacaciones**, **preaviso**, **jornada anual**, **festivos**, **descanso semanal**, **horas extraordinarias**. The plan may re-order or drop topics whose corpus expression turns out unsuitable for fact extraction (e.g. too narrative to yield scoped verbatim facts) — that finding is a legitimate eval result, not a failure.

### 2.2 The per-topic gate (the eval is the deliverable, again)

For each topic, in order:
1. **Gold-fixture eval first:** a hand-built gold set from real convenio passages for that topic (the 7b-2 method), measuring mis-scope rate, restraint (no extraction where the source is ambiguous or group-dependent without resolvable groups), and validity capture. **Gate: 0 mis-scoped facts on the gold set** — the 7b-2 standard. Wrong-but-flagged is tolerable; wrong-but-confident is not.
2. Only after the gate passes: the batch run for that topic across all convenios with ingested text.
3. Record per-topic yield (facts proposed, per convenio) and push to the review queue.
4. Next topic. One topic at a time — a prompt weakness found at topic N must not already be baked into topics N+1…

### 2.3 Group-scope interaction (the known hard part)

Many of these topics vary by group (vacaciones days, preaviso by category). Rules:
- Where an approved `convenio_groups` tree exists: the agent may propose group-scoped facts against real nodes (7f's exact matcher).
- Where no approved tree exists: **restraint** — the agent must not invent group scope; it either proposes a convenio-wide fact only when the source is genuinely group-independent, or records a refusal (7f's recorded-refusal lane). The plan must show how the current agent behaves here and what enforcement exists.
- The 5 pending group trees are a data-pass dependency: their approval directly unlocks yield. Quantify this in the plan so HR sees the payoff.

### 2.4 Ops hardening (carried 7e follow-up)

`SegmentReferenceSource` reads validity at job time; the handoff's open item says capture it **at dispatch**. A batch of queued jobs spanning a validity change must not stamp facts with post-change validity. Fix as part of this sprint, before any batch runs.

### 2.5 Review-queue throughput (bounded)

Hundreds of new `needs_review` facts will land on a queue that already holds 82. In scope: ranking the queue by Analítica demand (if not already), and making per-fact verification fast (source excerpt beside the fact — confirm what 7g already provides suffices). **Out of scope: any bulk-approve mechanism.** ADR-0020's one-human-one-fact verification is the safety spine; throughput comes from ordering and presentation, never from batching consent.

## 3. Out of scope

- Any change to 7c routing, answer composition, authority, validity resolution (7d supersession stands).
- Bulk or auto-verification; any weakening of ADR-0020.
- New topics beyond the tranche without their own gate.
- Full-gap convenios (data pass).
- Vocabulary/topic-lexicon expansion (10b shipped the mechanism; new anchors only if a topic's eval shows a routing gap, and then as an explicit finding).

## 4. Acceptance criteria

1. Per-topic eval results recorded in `review.md`: gold-set size, mis-scope count (**must be 0** per gate), restraint cases, refusal counts. Any topic that fails its gate is reported with the failing cases and either a fixed-and-re-measured prompt or a documented drop — never a quiet retry until green.
2. Batch yields recorded per topic × convenio; queue state before/after; the dispatch-time validity fix tested (a queued job spanning a validity change stamps dispatch-time validity).
3. All existing suites green; 10a/10b golden sets and the fallback negative sets unchanged (segmentation writes only `needs_review` facts, which 7c cannot serve — additivity should hold trivially; prove it anyway).
4. Eyes-on (§6). Cost of the batch runs reported (real token spend).

## 5. Risks / plan-gate questions

- **R1 — agent generality:** the 7b-2 prompt was tuned on periodo de prueba's expression style. Other topics (vacaciones with adicionales, permisos with kinship-degree tables) have different shapes. The per-topic gate exists for exactly this; the plan should predict which topics look hardest from real corpus sampling.
- **R2 — group-dependence density:** if most vacaciones/preaviso clauses are group-dependent and only 1 convenio has an approved tree, yield may be low until the data pass approves trees. Measure and report; don't force convenio-wide facts out of group-dependent sources.
- **R3 — queue flooding:** proposing 400 facts nobody verifies helps no one. The plan should estimate yield and propose a sane per-topic batch ceiling or ranking so HR's effort lands on high-demand facts first.
- **R4 — cost/runtime:** estimate tokens per source × sources per topic; state whether staging needs a resize (7b-2 did not, but volume is larger now).
- **R5 — validity edge:** convenios 4/21 (expired, ultraactividad unresolved) — segment or skip? Recommendation: skip until the data pass resolves their status; a fact stamped from text whose applicability is unresolved is exactly the wrong-but-confident shape. Plan confirms.

## 6. Eyes-on (staging, real browser)

1. Review queue: open a newly proposed fact from the first completed topic — source excerpt visible beside it, scope readable, verify one real fact end-to-end (HR-style).
2. Ask, as a test employee whose convenio just gained that now-verified fact, the matching question → answered verbatim from the fact (`structured_reference` in the admin trace), where prose or escalation happened before.
3. Ask the same question as an employee whose convenio has only `needs_review` facts for the topic → behavior unchanged (inert until verified, live).
4. Cobertura: the topic's coverage cells reflect the new facts appropriately (needs_review vs verified distinction).

## 7. Deliverables

- `sprints/sprint-10c/{spec,plan,build-prompt,review}.md`, per-topic `eval/` fixtures
- Docs: roadmap (replace the stub), data-model.md only if trace/columns change (none expected), deploy.md cost note
- A handoff list for the data pass: pending group trees ranked by unlocked yield, and the 4/21 skip decision recorded
