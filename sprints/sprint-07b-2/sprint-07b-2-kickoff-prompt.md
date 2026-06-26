# Sprint 7b-2 — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate **and the real fixtures**, plan, and **stop** — no segmentation code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–7b-1 are built and committed. **This is Sprint 7b-2 — the AI segmentation agent**, the AI half of Structured Reference Knowledge (7b-1 built the substrate + manual path). Read `roadmap.md` Sprint 7b and `hr-docs/sprints/sprint-07b-2/spec.md`.

7b-2 builds the agent that reads a `reference_source` docx/xlsx and **proposes** scoped facts into the `reference_facts` table 7b-1 created (the reserved `ai_agent` lane). **This is the project's riskiest sprint — but the risk is the AI's scope-assignment quality, not the plumbing** (which reuses 7a's proven propose-pattern). **The eval against the real fixtures is the deliverable**, not the code.

Before anything, read in full:
- **7a** — the `/propose-tags` pattern: the queued `ProposeDocumentTags` job, the hr-ai endpoint that reads content + the closed candidate vocabulary and returns proposals (writes nothing, never migrates), `TagProposalService` persisting `ai_agent` provenance inert-until-verified, the two tested invariants (ADR-0020). **This is the exact template to reuse.**
- **7b-1** — `reference_facts` (the reserved `ai_agent` lane, the `needs_review` state, the **logical key** `(convenio_id, topic_id, job_category_id, validity_start, validity_end)` recorded *for this sprint's upsert*), the `/read-structured` docx/xlsx reader (the content the agent consumes), the distinct-badged leaf + fact card + verify UI + the bounded-edit/scope-409 gate (ADR-0021). **The agent writes into this; the review UI extends this.**
- **the REAL fixtures** (now in-repo — read them, don't assume): `PERIODOS_DE_PRUEBA.docx` (78 paras, prose: `TERRITORY`→`SECTOR` headers governing `Grupo X: value` lines until the next header; cross-province same-group different-value — COEAS Álava G1 *5 meses* vs COEAS Andalucía/Estatal G1 *6 meses*; multi-value Navarra Hostelería G1 90/75/60 by contract type; compound groups *"Grupo 1 y área cinco de Grupo 2"*), `PERI_ODOS_PRUEBA_ACTUALIZADOS_2026.docx` (95 paras, a **version** of the first with genuinely-changed values — Navarra Intervención Social G2 *6 meses*→*4 meses* — province-spelling variance GUIPUZCOA/GIPUZKOA, and different territory coverage), `Tablas_acuerdo_parcial_Alhambra.xlsx` (sheet `Convenios`, 714×28, salary-ish SMI/hours — the routing test). **Read them and let their real structure shape the segmentation + eval design.**
- ADR-0007 (hr-ai reads/returns, never migrates), ADR-0011 (bind into existing vocabulary, never mint), ADR-0020/0021 (the inert-until-verified + authority-bounded + routing invariants — re-proven, not rebuilt)

Your task this turn: **inspect the substrate + the real fixtures, and plan — write no segmentation code.**

Produce `hr-docs/sprints/sprint-07b-2/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check).** The 7a propose-pattern (cite the real `ProposeDocumentTags`/`TagProposalService`/`/propose-tags`); the 7b-1 `reference_facts` shape + the reserved `ai_agent` lane + the logical key + `/read-structured`; confirm what's reuse vs new. Show real lines.
2. **The real fixtures — read and characterized.** What the docx/xlsx actually contain (structure, the header-carry pattern, the cross-province traps, the multi-value lines, the version differences between the two docx, the salary-ish xlsx). This grounds the segmentation prompt and the eval. **Quote real lines from the files.**
3. **The segmentation agent.** The hr-ai `POST /segment-facts` (reads the `/read-structured` content + the closed candidate vocabulary, returns proposed facts with scope/value/validity/confidence/source-locator/uncertainty — writes nothing, never migrates); the queued job + auto-trigger for `reference_source`; the hr-backend persist path (upsert on the logical key, `ai_agent`/`needs_review`); **multi-value → one fact per scope**; **version → tag source-validity + flag obvious same-scope duplicates** (resolution is 7d, not here). The **header-carry** instruction (the highest-leverage prompt detail — scope resets on each TERRITORY/SECTOR header).
4. **The review UX (the safety).** How each proposed fact shows its **source line**, assigned scope, confidence, uncertainty; verify / fix-then-verify / reject; uncertain-first sort; fuchsia until verified. Extend the 7b-1 fact card / queue.
5. **The eval methodology (the deliverable).** How the agent is run on the three fixtures and scored: the accuracy table (proposed / correct scope+value+validity / mis-scoped / correctly-flagged-uncertain), the mis-scoped facts enumerated. How "human-catchable error rate (acceptable)" vs "drowning (iterate)" is judged. Where fixtures live (`sprint-07b-2/fixtures/`).
6. **Migrations & build order** across hr-ai (`/segment-facts`, no migration), hr-backend (the persist service + queued job + any confidence/uncertainty/source-locator columns on `reference_facts` — additive), hr-frontend (the review-queue extensions). List every additive migration. Flag whether a short ADR is warranted.
7. **Assumptions & open questions** — esp. the segmentation prompt strategy, how scope is resolved to FK ids (the candidate-vocabulary context), the confidence/uncertainty representation, the obvious-duplicate detection (logical-key collision), and anything the real fixtures make non-obvious.

Hard constraints:
- **The inherited safety net is preserved, not rebuilt:** AI facts are `ai_agent`/`needs_review`/**not answerable**; authority only `structured_reference` (422 higher); **zero salary rows** from the reference path; **no vocabulary minted** (bind-only, flag uncertainty); fuchsia until verified; the agent **never verifies its own output**.
- **hr-ai reads/returns, never migrates** (ADR-0007); **hr-backend owns all writes + schema; additive migrations only**.
- **No answering-from-facts (7c): the answer engine is NOT touched** (2b frozen). **No semantic-conflict resolution (7d):** propose-with-source-validity + flag-obvious-duplicates only.
- **Multi-value → one fact per scope; version → tag + flag, don't resolve.**
- **The eval against the REAL fixtures is the deliverable** — the plan must make the accuracy report a first-class output, not an afterthought.
- Reuse the **7a propose-pattern** + the **7b-1 reference_facts/review UI** + the design system + `EnsureCan` + append-only `tag_events`.

Do not create or modify any file other than `hr-docs/sprints/sprint-07b-2/plan.md` this turn (you may also copy the three fixtures into `sprint-07b-2/fixtures/` if helpful for the eval — that's the only other write permitted). After writing the plan, stop and say it is ready for review.
