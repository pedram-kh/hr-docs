# Sprint 7d — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate, plan, and **stop** — no code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–7c are built and committed (the answer engine incl. the 7c composition layer, Knowledge Center, escalation flywheel, access control, guardrails, the LLM tagging tier, the full Structured Reference Knowledge type). **This is Sprint 7d — semantic conflict detection + fact version resolution + AI succession-proposal.** Read `roadmap.md` Sprint 7d and `hr-docs/sprints/sprint-07d/spec.md`.

7d is **one mechanism, three jobs**: compare two pieces of knowledge by meaning and surface the relationship to a human — applied to (A) the ruling-publish conflict fence, (B) the flagged fact-duplicate queue, (C) the expiry/succession queue. The comparison engine **already exists** (`/retrieve` embeds and ranks by cosine within a scope); 7d reuses it. **Nothing auto-resolves, auto-retires, auto-publishes, or touches the frozen answer loop. The fence only ever gets stricter.**

Before anything, read in full and cite real lines:
- **The Sprint-4 publish fence** — `EscalationService::resolve`/the conflict check, the topic-additive `orWhereDoesntHave('topics')` fail-closed shape (Sprint-5 Correction-01), the `publish_blocked` event + reasons, the return-to-In-Progress. `architecture.md` §8.3 + §8.5 (the Q-F symmetric boundary).
- **The embed/rank primitives** — `hr-ai POST /retrieve` (BGE-M3, scope `WHERE`, forced-exact flat scan, `{chunks, eligible_total}`), `POST /sandbox-retrieve` (ranks within one document), `POST /embed`. Confirm which can serve "compare this text against the scope's official-convenio chunks" **read-only**, or whether one additive read-only compare endpoint is cleaner. hr-ai never migrates.
- **7a** — `reviews:scan-expiry`, the expiry task table, the human-confirmed `predecessor_document_id` write-side (same-convenio only, never auto-retire), the `ProposeDocumentTags` propose-pattern, ADR-0020. The note "AI succession-proposal deferred to 7d."
- **7b-2** — `duplicate_of_id` + `uncertainty.field='version'`, the exact logical-key collision detection in `ReferenceFactProposalService`, the `rejected` status, and the **version-trap partial miss** in `sprint-07b-2/review.md` (Navarra Intervención Social: file-1 "Grupos 1 y 2 = 6 meses" vs file-2 "Grupo 2 = 4 meses" not linked because `group_label` differs).
- **7c** — `ReferenceFactAnswerService`'s two-verified rule (most-recent validity else escalate) and the golden-trace regression test (must stay green).
- The Reference-facts review tab + the Expiry tab (7a/7b-2 frontend) — the surfaces 7d extends. ADR-0011/0015/0016/0019/0020/0021/0022.

Your task this turn: **inspect the real substrate and plan — write no code.**

Produce `hr-docs/sprints/sprint-07d/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check).** The real fence code and its reasons; the real expiry task shape + lineage write-side; the real duplicate-flag detection; which hr-ai primitive serves the comparison. Show real lines. State clearly what is reuse vs new.
2. **(A) The semantic fence — additive, fail-toward-caution.** Where the semantic pass runs (at publish, after the existing check), what is embedded/compared (the ruling text vs active `official_convenio` chunks in scope; `national_law` informational only), the **two bands** (`semantic_conflict_threshold` → block `semantic_overlap`; `semantic_review_band` → show passages + require explicit acknowledgement), named conservative raise-only config, what the block/acknowledge event records (chunk ids + scores). **Prove `fence = existing_block OR semantic_block`** — propose the test that every previously-blocked case still blocks. The §8.5 reverse re-check at the **flag-only minimum bar** (and flag it as the first deferral candidate if it balloons).
3. **(B) Fact resolution.** The side-by-side surface for `duplicate_of_id` pairs; the three actions (**supersede** = close the older's validity to `newer.validity_start − 1`, both kept, lineage recorded, never delete; **coexist**; **reject**), each append-only `tag_events`; the extended **semantic/scope duplicate pass** for same-convenio+topic facts with differing labels (deterministic normalized-token overlap vs embedding — pick, prefer deterministic where sufficient) that surfaces the re-grouped version pair as a **flag**; how resolved data flows to the 7c answer rule (through data only).
4. **(C) Succession proposal.** The queued job on expiry items → hr-ai reads-and-returns the compared passages + relationship (successor / coexisting sibling / conflict) + score + uncertainty, **same-convenio candidates only** → hr-backend persists an inert `ai_agent` proposal on the expiry task → human confirm runs the existing 7a write-side; reject records. The AI never writes `predecessor_document_id`/`retrieval_status`. The **gold eval**: real expiry pairs (a successor pair, a sibling pair, a conflict pair from the corpus), scored right / uncertain / confidently-wrong; confidently-wrong-successor is the failure metric.
5. **Additivity & the frozen loop.** Exactly what changes and what does not: no `/retrieve`/`/synthesise`/`/ground`/`ChatService` change; the 7c golden-trace regression stays green; the fence never opens.
6. **Migrations & build order** across hr-backend (new event reasons, expiry-task proposal fields, fact resolution provenance, the fence + resolution + succession services), hr-ai (at most one additive read-only compare endpoint; no migration), hr-frontend (fence block/acknowledge UI with passages; the fact resolution surface; the expiry proposal UI). List every additive migration. Recommend the build order (suggest A first — the safety gate — then B, then C) and flag **ADR-0024**.
7. **Assumptions & open questions** — esp. the threshold values and how to calibrate them on real data (propose a quick calibration: run the comparison over the existing published rulings vs their scopes and report the score distribution), embedding vs deterministic for the duplicate pass, the reverse-recheck scope, what "same subject" means for sibling-vs-conflict, and anything the real fence code makes non-obvious.

Hard constraints:
- **The fence only gets stricter**: `existing OR semantic`; thresholds conservative, raise-only; block-or-acknowledge, never silent-pass. Prove it with a test.
- **Human-adjudicated everything**: no auto-resolve/link/retire/demote/publish; every AI relationship is an inert `ai_agent` proposal (ADR-0020); supersede closes validity, never deletes; succession same-convenio only, predecessor never auto-retired (7a rule).
- **Answer loop untouched** (2b/7c frozen); resolution reaches chat via corrected data only; the golden-trace regression stays green.
- **hr-ai reads/returns, never migrates** (ADR-0007); hr-backend owns writes; additive migrations only; vocabulary bind-only (ADR-0011).
- Reuse `/retrieve`/the embed machinery, the 7a propose-pattern + expiry write-side, the 7b-2 duplicate flag + review tab, the design system, `EnsureCan`, append-only `tag_events`/`escalation_events`.

Do not create or modify any file other than `hr-docs/sprints/sprint-07d/plan.md` this turn. After writing it, stop and say it is ready for review.
