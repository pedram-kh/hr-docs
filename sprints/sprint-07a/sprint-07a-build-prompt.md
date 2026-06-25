# Sprint 7a — Cursor build-authorization prompt

> Paste into the Sprint 7a Cursor thread (the one that wrote `plan.md`). The plan + substrate verification are approved — and the embedding-gate finding (the gate excludes **only** `under_review`, not `auto_proposed`) reshapes the safety design correctly. This authorizes the **build**. Every §7 question is resolved below. Build in the §6.2 order, write **ADR-0020**, update the named docs, write `review.md`, and **STOP — do not commit.**

---

The Sprint 7a plan (`hr-docs/sprints/sprint-07a/plan.md`) is approved. The reality-check is exact and it caught the load-bearing nuance: **the embedding gate is `tagging_status != under_review` only — `auto_proposed` IS embedded (42 docs → 3494 chunks).** Build it in the §6.2 order. All open questions are resolved as follows.

## The two safety invariants (non-negotiable — build + TEST)

These are the whole sprint. Both must be enforced and proven by test:

1. **AI proposals keep the document at `under_review` — NEVER write `auto_proposed` (or `verified`).** `auto_proposed` is the clean-parse retrievable state; writing it on an AI proposal would make a wrong AI guess instantly retrievable — the exact harm. Since `unresolved` docs are already `under_review` at ingest, the AI simply **leaves `tagging_status` untouched**; only the human **verify** action flips it (`under_review → verified`). **Test:** after the AI proposes, assert the doc is still `under_review` and has **0 `document_chunks`** (genuinely unretrievable); after a human verifies, assert it becomes eligible for embedding.
2. **The AI writes ONLY `ai_agent` provenance — NEVER the authoritative FK columns** (`convenio_id`/`territory_id`/`sector_id`/`document_type_id`/validity). It writes `ai_agent` `tag_events` + unverified `document_topics` + `tagging_confidence` only. The authoritative scope FKs change **only** by the human verify/reassign action. **Test:** after the AI proposes, assert the doc's `convenio_id` etc. are unchanged from their pre-proposal state (the AI is a strict proposer; no AI write can alter scope/eligibility).

The embedding gate (`tagging_status != under_review`) itself is **not touched** — it stays exactly as is; 7a's AI respects it, never modifies it. (Tightening it to `= verified` is rejected — that would change which 42 `auto_proposed` docs are currently retrievable, a behaviour change to the frozen answer loop.)

## Resolved open questions (apply exactly)

- **§7.1 — proposal prompt scope:** pass the **full** territory/sector/document_type lists (tiny, closed) + a **convenio shortlist** (by any parser hint) so the model binds to real IDs, not free text. Approved.
- **§7.2 — queued job, not synchronous.** LLM tagging is slow + ingest is batch; it must **not block or fail ingest** (matches the `embed` background posture). A manual "re-suggest" endpoint may exist, but auto-trigger is a queued job.
- **§7.3 — CONFIRMED: AI keeps `under_review`, never `auto_proposed`** (invariant 1 above).
- **§7.4 — CONFIRMED: strict-proposer — AI writes only `ai_agent` `tag_events`, NOT the FK columns** (invariant 2 above). Do **not** pre-fill FK columns "to make verify one-click" — it muddies the cleanest safety property; the human verify writes the FKs.
- **§7.5 — variant-vs-new:** use the existing **`VocabularyResolver`** (deterministic alias/name matching) for the variant→alias suggestion + a **soft AI hint**; UI pre-selects "fold into alias" above the resolver's match threshold, defaults to "propose new" only when nothing is close. No new model dependency.
- **§7.6 — expiry window:** "within **90 days** of `validity_end` **OR** already past `validity_end` while still `active`." Feed the Sprint-3 **`date_expired_active`** staleness docs into the same queue (they need the same human attention).
- **§7.7 — DEFER AI succession-*proposal* to 7d.** 7a builds **only the human-confirmed lineage write-side**: the expiry queue surfaces near-expiry docs; a human confirms "this succeeds that" → `predecessor_document_id` is written (same-convenio candidates only); **never auto-retire**. The AI *suggestion* of succession candidates (successor/sibling/conflict by meaning) requires the semantic-comparison machinery that **7d** builds — doing it in 7a would duplicate or crudely pre-empt 7d. So: 7a = wire the stub + human-confirmed handoff; AI succession-proposal rides 7d.

## Build (the §6.2 order, hard constraints enforced)

- **Additive migrations only (hr-backend):** (1) `create_vocabulary_proposals_table`; (2) `seed_vocabulary_approve_permission` (the `vocabulary.approve` ability → super_admin, spatie seed + `RoleSeeder` lockstep). **No** migration for the `ai_agent` lane (enum value exists), `predecessor_document_id` (column exists — add a writer), or the `expiry` task type (enum exists — add a writer). **No hr-ai migration.**
- **hr-ai (read-and-propose, no migration):** `POST /propose-tags` (guarded by `require_internal_token`) — extract-and-return the proposed-facets envelope from the page text hr-backend passes; **writes nothing**, no DB access (ADR-0007). Parallels `classify`/`ground`.
- **hr-backend (persist side):** `ExtractionClient::proposeTags()`; `TagProposalService` (persists `ai_agent` `tag_events` + unverified `document_topics` + `tagging_confidence`, **keeps `under_review`**, **never writes FK columns** — invariants 1+2); `ProposeDocumentTags` **queued job** + auto-trigger in `DocumentIngestor` for `reason = unresolved` + a manual re-suggest endpoint; `VocabularyProposalService` + controller (propose [`knowledge.edit`] / approve [`vocabulary.approve`, super_admin propose-and-approve] / reject; variant→alias default; writes aliases or new vocab + provenance; resolves the source doc); `reviews:scan-expiry` command (materialize `expiry` tasks, the 90-day-or-past window) + the succession endpoint writing `predecessor_document_id` (human-confirmed, same-convenio, never auto-retire). The **verify path is the unchanged Sprint-3 `confirm()`**.
- **hr-frontend (reuse Sprint-3 UI):** the **review queue** (the `under_review` + `unresolved` backlog sorted by `tagging_confidence`, fuchsia-marked, opening the existing `DocumentDetailPanel`); **verify** reuses the **Sprint-3 Confirm-tags** button + bounded-edit (accept/adjust AI facets → confirm — no new verify mechanism); the **propose-vocabulary UI** (variant→alias-first chooser, propose vs approve per ability); the **expiry queue** + succession-handoff confirm; the **fuchsia token** re-value + apply.

## The fuchsia signal (`--provenance-ai`)
- **Re-value the existing token to fuchsia `#e879f9`** (it's currently violet `#7c3aed`) in the **vanilla-CSS token system** (ADR-0012/0013, **not** Tailwind). Apply to the four **unverified-AI** surfaces (review queue, the AI-proposed facets on the card, the proposed-vocabulary list, the [7a: not-yet-present] AI-succession marker — skip since deferred to 7d).
- **Fuchsia = "unverified AI — needs a human" ONLY.** On **verify/approve**, the live UI reverts to **normal confirmed styling**; the AI origin persists **only** as the `ai_agent` dot in the **provenance timeline** (history, not a live alert). The colour must never bleed onto confirmed content.

## Hard constraints (carry)
- **The two safety invariants above are tested** (AI stays `under_review` + 0 chunks; AI never writes FK columns). The embedding gate is preserved, never weakened.
- **No answer-loop change (2b frozen)** — no change to `/retrieve`/`/route`/`/synthesise`/`/ground`/`ChatService`; `/propose-tags` is a separate upstream call.
- **Document-level facet tagging ONLY — no multi-scope fact-segmentation** (that's 7b). `/propose-tags` returns one document's facets.
- **The AI never creates vocabulary** (flags `raw_unmatched_values` + variant hints); growth is propose→approve (variant→alias default); approve is human (`vocabulary.approve`; super_admin propose-and-approve).
- **`hr-backend` owns all writes + schema; additive migrations only; `hr-ai` never migrates** (ADR-0007). **Succession scope-based + human-confirmed, never auto-retire.**
- Reuse the **design system** + the **Sprint-3 review/confirm UI + provenance timeline** + `EnsureCan` + the append-only audit pattern.

## Eyes-on (report in review.md; Pedram runs live)
Ingest an `unresolved` doc (a no-numero/scan) → the queued job **auto-tags** it (fuchsia, in the review queue); confirm it's **not answerable** (ask a question → escalates, because 0 chunks). **Verify** the proposal (Sprint-3 Confirm) → it turns normal, becomes embeddable/answerable, and the AI origin shows in its provenance timeline. **Propose-new-vocabulary** (a genuinely-new sector value) → variant→alias offered first; as non-super_admin propose only; as super_admin propose-and-approve. **Expiry queue** → a near-expiry doc appears; confirm a successor → `predecessor_document_id` is written and the old doc is **not** auto-retired. Throughout: **fuchsia = unverified-AI only** (verified content is not fuchsia).

## Docs at close
**ADR-0020 — AI-proposed tagging is inert until verified; managed vocabulary approval authorization** (the `under_review`-is-the-inert-state gate semantics, the AI-is-a-strict-proposer rule, the `vocabulary.approve` authorization + super_admin propose-and-approve, succession scope-based/human-confirmed/never-auto-retire — **7b reuses this "inert until verified" spine**). `architecture.md` (the LLM tagging tier built: auto-propose-on-ingest, the `ai_agent` lane lit, the two invariants, the propose-vocabulary flow, the expiry queue + lineage write-side, the fuchsia semantics). `data-model.md` (the `vocabulary_proposals` table; the now-written `predecessor_document_id`; the `ai_agent` lane now populated). `roadmap.md` (Sprint 7a done; 7b/7c/7d remain; **AI succession-proposal explicitly carried to 7d**). The relevant READMEs. Write `hr-docs/sprints/sprint-07a/review.md` (the build + the **two-invariant tests as acceptance proof** + the eyes-on checklist). Then **STOP — do not commit until I review.**
