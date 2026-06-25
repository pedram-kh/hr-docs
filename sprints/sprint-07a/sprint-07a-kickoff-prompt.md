# Sprint 7a — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate, plan, and **stop** — no tagging/vocabulary/expiry code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–6 are built and committed (answer engine, Knowledge Center, escalation board + flywheel, access-control, guardrails config). Sprint 7 was split into **7a/7b/7c/7d**; **this is Sprint 7a — Messy-tail document intelligence** (the LLM tagging tier + propose-new-vocabulary + expiry queue + lineage write-side). Read `roadmap.md`'s Sprint 7a entry.

Before anything, read in full:
- `hr-docs/architecture/data-model.md` — `documents.reason` (`unresolved` = "LLM-eligible" / `conflict`) + `raw_unmatched_values`; `tagging_status` (`auto_proposed`/`under_review`/`verified`) + `tagging_confidence`; **the embedding selection rule** (confirm: `tagging_status ≠ under_review` excludes a doc from embedding/retrieval — the load-bearing safety gate); `tag_events.source`/`proposed_by` (the **reserved-but-empty `ai_agent` lane**); `predecessor_document_id` (the **write-side stub** — confirm nothing writes it); `document_review_tasks` (`type ∈ {expiry, tag_review, conflict}`); the topic `status = proposed`/admin-approves pattern
- **ADR-0011** (managed vocabulary growth — variant→alias default), the **Sprint-1 review** (the rescue fields `reason`/`raw_unmatched_values` were laid *for this sprint*; the deterministic parser; the registry import that owns vocabulary), **ADR-0007** (hr-ai never migrates; hr-backend owns writes)
- the **Sprint-3 Knowledge Center** — the review/confirm UI (the bounded-edit confirm = the verify action), the document card + provenance timeline, and the **reserved `--provenance-ai` timeline-dot token** (the AI lane Sprint 3 left empty); ADR-0012/0013 (the **vanilla-CSS token system — NOT Tailwind**)
- how `hr-ai` is called today (the `/extract`, `/synthesise`, `/ground`, `/retrieve` endpoints, the internal-token auth) — so the new tagging proposal is a clean **hr-ai-proposes → hr-backend-persists** division
- `roadmap.md` Sprint 7a (incl. the scope-based-succession rule); `hr-docs/sprints/sprint-07a/spec.md`
- the existing admin shell + design-system tokens

Your task this turn: **inspect the real substrate and plan — write no tagging/vocabulary/expiry code.**

Produce `hr-docs/sprints/sprint-07a/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check — the spine).** Confirm against real schema/code: the `reason`/`raw_unmatched_values` fields and **how many `unresolved` docs actually exist** in the dev corpus (the real backlog this serves); **the embedding gate** — show the *exact* selection query and confirm `tagging_status = under_review` (or `auto_proposed`) is excluded, so an AI-proposed doc is genuinely unretrievable until verified; the **`ai_agent` lane** is reserved but unwritten; `predecessor_document_id` is a stub nothing writes; the `document_review_tasks` shape. Show real lines. This determines what's new vs wiring.
2. **The LLM tagging tier.** The **hr-ai-proposes → hr-backend-persists** division: the hr-ai endpoint that reads a doc's content and returns proposed facets + confidence (reuse the page-text it already has); how hr-backend persists them (`tag_events source=ai_agent`, `tagging_status`, `tagging_confidence`) and where the unresolvable values become `raw_unmatched_values`; **auto-trigger on ingest** for `reason = unresolved` (the queue self-populates). Confirm hr-ai **never migrates**.
3. **Propose-new-vocabulary.** The proposal record + flow (variant→alias offered first, create-new deliberate); the authorization — **AI proposes only (two-step); super_admin may propose-and-approve**; approval writes into the vocabulary tables with provenance and resolves the originating doc. State the additive migration (a proposal table).
4. **Expiry queue + lineage write-side.** The expiry query → `document_review_tasks type=expiry` (a queue); the **`predecessor_document_id` write** on confirmed succession (the stub implemented); scope-based, human-confirmed succession (AI proposes successor/sibling/conflict; human confirms; **never auto-retire** — confirm nothing auto-retires today and keep it that way).
5. **The `--provenance-ai` fuchsia signal.** Define the token (`--provenance-ai: #e879f9`, fuchsia-400) in the **vanilla-CSS token system** (not Tailwind); apply it to **unverified-AI** content only (queue/cards/vocabulary/succession); on **verify**, revert to normal styling with the AI origin kept as a provenance-timeline entry. State exactly where the token is used and the verified→normal transition.
6. **Migrations & build order** across `hr-backend` (the persist side, the vocabulary-proposal table + flow, the expiry/lineage writes, the auto-trigger), `hr-ai` (the read-and-propose endpoint — no migration), `hr-frontend` (the review queue, the verify action reusing Sprint-3 UI, the propose-vocabulary UI, the expiry queue, the fuchsia token). List every **additive migration**. Flag whether an **ADR** is warranted (the propose-approve authorization / AI-inert-until-verified).
7. **Assumptions & open questions** — esp. the hr-ai proposal endpoint shape, whether AI tagging is a sync ingest step or a queued job, the exact embedding-gate confirmation, and the variant-vs-new heuristic.

Hard constraints:
- **The AI is upstream and inert.** Every AI output is a **proposal**; the **embedding gate (`tagging_status ≠ under_review`) keeps it unretrievable until a human verifies** — preserve this gate, never weaken it. **No answer-loop change** (2b frozen). The AI does **document-level facet tagging only** — **not** multi-scope fact-segmentation (that's 7b).
- **The AI never creates vocabulary** (it flags `raw_unmatched_values`); vocabulary growth is the **propose→approve** flow (ADR-0011, variant→alias default). **AI proposes only; super_admin may propose-and-approve.**
- **`hr-backend` owns all writes + schema; additive migrations only. `hr-ai` proposes via an endpoint and NEVER migrates** (ADR-0007). Succession is **scope-based + human-confirmed, never auto-retire**.
- **`--provenance-ai` (fuchsia `#e879f9`) is a vanilla-CSS token** (ADR-0012/0013, not Tailwind) marking **unverified-AI only**; verified content reverts to normal + a history entry.
- Reuse the **design system** + the **Sprint-3 review/confirm UI + provenance timeline** + the `EnsureCan` + audit patterns.

Do not create or modify any file other than `hr-docs/sprints/sprint-07a/plan.md` this turn. After writing it, stop and say it is ready for review.
