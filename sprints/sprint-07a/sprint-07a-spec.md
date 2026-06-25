# Sprint 7a — Messy-tail document intelligence

> Location: `hr-docs/sprints/sprint-07a/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `data-model.md` — `documents.reason` (`unresolved` = "parser had nothing, **LLM-eligible**" / `conflict` = human-adjudicated) + `raw_unmatched_values` (the literal unresolved strings, "fold-into-aliases vs propose-new-value", ADR-0011); `tagging_status` (`auto_proposed`/`under_review`/`verified`) + `tagging_confidence` ("drives the review queue") + **the embedding gate** (`tagging_status ≠ under_review` → an AI-proposed-but-unverified doc is **structurally not retrievable**); `tag_events.source`/`proposed_by` (the **`ai_agent` lane — reserved, empty until now**); `predecessor_document_id` (the **stub nothing writes**); `document_review_tasks` (`type ∈ {expiry, tag_review, conflict}`); the topic `status = proposed`/admin-approves pattern. Also: **ADR-0011** (managed vocabulary growth — variant→alias default), the Sprint-1 review (the rescue fields were laid *for this sprint*), the Sprint-3 Knowledge Center (the review/confirm UI + the **reserved `--provenance-ai` timeline-dot token**), the scope-based-succession rule (`roadmap.md` Sprint 7a); ADR-0012/0013 (the vanilla-CSS token system — **not Tailwind**).
> **The rescue path Sprint 1 pointed at.** Sprint 1 deliberately laid `reason`/`raw_unmatched_values` and reserved the `ai_agent` provenance lane "deferred to the LLM-tagging-tier sprint." This is that sprint. **Low-risk by construction:** the AI works *upstream*, every proposal is **inert** (the embedding gate keeps an unverified doc unretrievable), and **nothing here touches the answer loop.**

## Goal
Give the messy tail of the corpus — documents the deterministic filename-parser couldn't resolve (`reason = unresolved`: scans, no-numero PDFs, ad-hoc uploads, resolved-escalation articles) — a path to being correctly tagged: the AI **reads and proposes** facets with confidence (auto, on ingest), genuinely-new vocabulary routes through a **propose→approve** flow, **expiring** documents surface in a **review queue**, and the **version-lineage write-side** (a stub since Sprint 1) is implemented. Every AI output is a **human-reviewable proposal**, visually marked, and **answerable only after a human verifies it**.

## The governing safety property (already enforced at the data layer)
**Nothing the AI proposes becomes answerable until a human verifies it** — and this is enforced *structurally*, not by convention: the embedding selection already excludes `tagging_status = under_review`, so an AI-proposed document is **not retrievable** until a human moves it to `verified`. 7a's AI can be wrong without harm — its output is inert. The sprint must preserve this gate, never weaken it.

## In scope

### A. LLM tagging tier (the rescue path)
- For **`reason = unresolved`** documents (and resolved-escalation/ad-hoc docs without clean filenames), `hr-ai` reads the content and **proposes** facets — convenio / territory(derived) / sector(derived) / document_type / validity — each with a **confidence score**, writing them as `tag_events` with **`source = ai_agent`** + `tagging_status = auto_proposed` + `tagging_confidence` (min across facets).
- **Auto on ingest** (decision): an `unresolved` document is auto-proposed at ingest so the **review queue self-populates**; the admin **reviews**, doesn't trigger. (A manual "re-suggest" action may exist, but the default is automatic.)
- The proposal **binds into existing vocabulary only** (FK resolution); where the AI can't resolve a value to existing vocabulary, it records it as a **`raw_unmatched_value`** for the propose-new-vocabulary flow (B) — it does **not** invent vocabulary.
- The doc lands at `under_review` (or `auto_proposed`) → **the embedding gate keeps it unretrievable** until a human verifies. Reuse the **Sprint-3 review/confirm UI** (the bounded-edit confirm becomes the verify action).
- `hr-ai` does this as an **upstream tagging job** — it does **not** enter the answer loop, and (per ADR-0007) it still **never migrates**; all writes to `documents`/`tag_events` are **`hr-backend`'s** (hr-ai proposes via an internal endpoint, hr-backend persists). The plan states the exact division.

### B. Propose-new-vocabulary (managed growth, ADR-0011)
- When a `raw_unmatched_value` is genuinely new (not a variant of existing vocabulary), a **propose → approve** flow: a proposal record (the value, facet, source doc, who/what proposed) → a human **approves** → it enters the controlled vocabulary (generalizing the topic `status = proposed`/admin-approves pattern).
- **Strong default: variant → alias.** The flow first offers "fold into an existing value's `aliases`" (the safe, common case — `Bizkaia`/`Vizcaya`); "create a genuinely new value" is the deliberate, less-default action (ADR-0011).
- **Authorization (decision):** the **AI can only *propose*** (two-step — a human always approves AI-proposed vocabulary); a **`super_admin` may propose-and-approve** in one action (a human is still the approver — themselves). A non-super_admin proposes; a super_admin (or the appropriate role) approves. Vocabulary *creation* stays the most-guarded action (ADR-0011) — the AI never creates vocabulary, only flags the need.
- Approving a value writes it into the vocabulary (the `registry:import`-owned tables) with provenance; the originating document's `raw_unmatched_value` is then resolvable.

### C. Expiry review queue + lineage write-side
- **Expiry queue:** surface documents approaching `validity_end` (the query already defined: `validity_end` + `retrieval_status = active`) as **`document_review_tasks type = expiry`** — a **queue, not a popup**. Each task: the expiring doc, its window, and the **successor-handoff** confirmation.
- **Lineage write-side (the stub):** implement writing **`predecessor_document_id`** (currently always null — "version history isn't actually tracked until it's built"). When a successor is confirmed, the link is written, with provenance.
- **Succession is scope-based, never topic-based, never automatic** (the roadmap rule): a doc supersedes another **only** as a newer version of the **same scope (same convenio)** — never because they share a topic; different-scope docs **coexist** (retiring one would delete live info for a population). The AI may **propose** *successor / coexisting-sibling / conflict* (by comparing meaning); a **human confirms** the *retire-or-coexist-or-escalate*. **Never auto-retire on ingest** (confirmed: nothing does today — preserve that).

### D. The visible AI signal (`--provenance-ai`, fuchsia)
- **AI-originated, *unverified* content is marked with the reserved `--provenance-ai` token = fuchsia-400 (`#e879f9`)** — a vanilla-CSS **token** (ADR-0012/0013), not a Tailwind class; this **fills the slot Sprint 3 reserved** for the (then-empty) AI lane. Applies across the review queue, the Knowledge Center cards/leaves, the proposed-vocabulary list, and the AI-proposed-succession marker.
- **Fuchsia means exactly one thing: "unverified AI — your attention needed."** Once a human **verifies**, the content adopts the **normal confirmed styling**; its AI origin is preserved as a **provenance-timeline entry** (the fuchsia dot in the *history*), **not** a live alert. So fuchsia in the live UI always = the work that still needs a human; verified-AI-origin content does **not** stay fuchsia (or the color stops meaning "needs attention").

## Out of scope (do NOT build)
- **Structured Reference Knowledge** (7b — the multi-scope *fact-segmentation* agent + the new non-vectorized type). 7a's AI does **document-level facet tagging only** — it tags *a document*, it does **not** split a multi-scope file into per-scope facts. Keep that line sharp.
- **The composition layer** (7c) and the **semantic conflict fence** (7d).
- **Any answer-loop change.** The AI is upstream; proposals are inert (the embedding gate). 2b frozen.
- **Auto-approval of vocabulary or auto-verification of tags** — a human always verifies/approves (the AI never makes anything answerable or creates vocabulary).
- Salary tagging (that's the salary path).

## Acceptance criteria
1. An `unresolved` document is **auto-proposed on ingest**: AI facets written as `ai_agent` provenance + `auto_proposed`/`under_review` + confidence; the doc appears in the review queue; **it is NOT embedded/retrievable** (the gate holds) until verified.
2. A human can **verify** an AI proposal (reusing the Sprint-3 confirm UI) → it becomes `verified` → then embeds/retrievable. Provenance shows the AI origin.
3. **Propose-new-vocabulary** works: variant→alias offered first; create-new is deliberate; **AI proposes only (two-step)**, **super_admin may propose-and-approve**; approval writes the value with provenance and resolves the originating doc. No AI-created vocabulary.
4. **Expiry queue** lists docs nearing `validity_end` as `document_review_tasks type = expiry`; confirming a successor **writes `predecessor_document_id`** (the stub is now implemented); succession is scope-based and human-confirmed, **never auto-retire**.
5. **`--provenance-ai` (fuchsia `#e879f9`) marks unverified-AI content** across the queue/cards/vocabulary/succession; verified content reverts to normal styling with the AI origin in the timeline. It's a vanilla-CSS token, not Tailwind.
6. All writes `hr-backend` (hr-ai proposes via an internal endpoint, never migrates); additive migrations only (a vocabulary-proposal table; possibly an AI-proposal/job record — state in plan); the embedding gate is preserved; nothing out-of-scope (no fact-segmentation, no composition, no answer-loop change).

## Eyes-on
Ingest an `unresolved` document (a no-numero/scan) → see it **auto-tagged by the AI** (fuchsia, in the review queue), and confirm it is **not answerable** (test a question → escalates, because it's not embedded). **Verify** the proposal → it turns normal, embeds, and now answers (with the AI origin in its provenance timeline). Trigger a **propose-new-vocabulary** (a genuinely-new sector value) → see variant→alias offered first; as a non-super_admin, propose only; as super_admin, propose-and-approve. Open the **expiry queue** → confirm a near-expiry doc appears; confirm a successor → check `predecessor_document_id` got written and the old doc wasn't auto-retired. Throughout, confirm **fuchsia = unverified-AI only**.

## Risks / notes
- **The embedding gate is the load-bearing safety property** — the plan must confirm an AI-proposed doc is genuinely excluded from embedding/retrieval until verified, and that verifying flips it on. This is *the* thing that makes auto-propose-on-ingest safe.
- **hr-ai proposes, hr-backend persists.** hr-ai reads content + returns proposed facets/confidence; hr-backend writes `documents`/`tag_events`/vocabulary. hr-ai never migrates (ADR-0007). State the endpoint + the division precisely.
- **Variant-vs-new is the judgment call** — the propose flow must make "fold into an existing alias" the easy default and "create new" the deliberate one, so the vocabulary doesn't bloat with near-duplicates (ADR-0011). The AI should *suggest* "this looks like a variant of X" where it can.
- **Fuchsia must mean one thing.** Don't let verified-AI-origin content keep the alert color — the value of the signal is that fuchsia = needs-a-human. Verified → normal + history dot.
- **Succession stays scope-based + human-confirmed.** Don't let the lineage write-side introduce any auto-retire-on-ingest path (the roadmap rule; today nothing auto-retires — preserve it).
- **7a tags documents; 7b segments facts.** Keep the boundary sharp so this sprint doesn't drift into the (harder, separate) multi-scope fact-segmentation.

## Definition of done
All criteria pass; Pedram eyes-on; docs updated — `architecture.md` (the LLM tagging tier now built: auto-propose-on-ingest, the `ai_agent` lane lit, the embedding gate as the safety property, the propose-new-vocabulary flow + authorization, the expiry queue + lineage write-side + scope-based succession, the `--provenance-ai` fuchsia semantics), `data-model.md` (the vocabulary-proposal table + any AI-proposal record; the now-written `predecessor_document_id`; the `ai_agent` lane now populated), `roadmap.md` (Sprint 7a done; 7b/7c/7d remain), an **ADR** if the propose-approve authorization or the AI-tagging-is-inert-until-verified rule warrants one (consider — it's a managed-growth + safety decision), the relevant READMEs. Cursor writes `hr-docs/sprints/sprint-07a/review.md` and **stops — no commit until I review**.
