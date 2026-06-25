# Sprint 7a — Review (build + acceptance proof)

**Messy-tail document intelligence — the LLM tagging tier + propose-new-vocabulary + the expiry/lineage write-side (ADR-0020).**
Built per the approved plan's §6.2 order, every resolved open question applied exactly. The whole sprint is **AI proposes → human confirms** with **no answer-loop change**: every AI output is an inert proposal that the embedding gate (`tagging_status != under_review`) keeps unretrievable until a human verifies. Status: **built; pending eyes-on + commit** (not committed — awaiting your review).

> The reality-check the plan caught is the spine of the build: the gate is `tagging_status != under_review` **only** — `auto_proposed` **is** embedded (the dev corpus: 42 `auto_proposed` docs → 3494 chunks). So the safe state for an AI proposal is to **leave the doc `under_review`** (where messy-tail docs already land at ingest), never `auto_proposed`. The gate itself is **untouched**.

---

## 1. What was built

### hr-ai (read-and-propose, no migration — ADR-0007)

- **`POST /propose-tags`** (guarded by `require_internal_token`) — reads the document's page text + the **closed candidate vocabulary** hr-backend passes (full territory/sector/document_type lists + a convenio shortlist by parser hint) and **returns** `{ facets:[{facet, value_id, value, confidence}], topics, raw_unmatched_values, overall_confidence, trace_fragment }`. The model **binds to real ids** in the closed vocabulary, never free text; anything it cannot bind becomes a `raw_unmatched_value` (with a variant hint). Implemented in `app/providers/base.py` (the `propose_tags` interface + `TagProposalResult`/`VocabularyCandidate` dataclasses) and `app/providers/claude.py` (the Spanish facet-tagging prompt, candidate-vocabulary block, and output validation/filtering against the closed set). **hr-ai writes nothing, no DB access**; same key-in-the-body, never-persisted posture as `/synthesise`·`/route`·`/ground`.

### hr-backend (the persist side — owns all writes + schema)

1. **Two additive migrations + the ability seed (lockstep).**
   - `create_vocabulary_proposals_table` — the propose→approve record (facet, proposed value, variant-of + similarity, resolution, source doc/task, status, proposed-by-source/admin, approved-by, resolved-vocab, note).
   - `seed_vocabulary_approve_permission` — idempotent data migration granting **`vocabulary.approve`** to `super_admin`; `RoleSeeder` updated in lockstep.
   - **No migration** for the `ai_agent` `tag_events` lane (enum value existed), `predecessor_document_id` (column existed — 7a adds the **writer**), or the `expiry` task type (enum existed — 7a adds the **writer**). No hr-ai migration.
2. **`ExtractionClient::proposeTags()`** — the internal HTTP call to `/propose-tags` (the `internal-token` posture, parallels the other hr-ai calls).
3. **`TagProposalService` — where both invariants live, by construction.** Fetches the doc's page text, builds the candidate vocabulary (incl. the convenio shortlist), calls hr-ai, and persists the result as **`ai_agent` `tag_events` + unverified `ai_agent` `document_topics` + `tagging_confidence`**, merging the AI's variant hints into the originating task's `raw_unmatched_values`. It **never touches `tagging_status`** (the doc stays `under_review`) and **never writes the scope FK columns**.
4. **`ProposeDocumentTags` queued job + the ingest auto-trigger.** `DocumentIngestor` dispatches the job **after the DB commit** for a `reason = unresolved` ingest (the background posture of embed — never blocks/fails ingest). The job defends the gate (re-checks the doc is still `under_review` before proposing). A manual **`POST /admin/documents/{uuid}/resuggest`** (`knowledge.edit`) re-runs it.
5. **`VocabularyProposalService` + `VocabularyProposalController`** — propose (`knowledge.edit`) / approve · reject (`vocabulary.approve`; super_admin may **propose-and-approve** in one call) / suggest-variant. `suggestVariant` is **deterministic** normalized similarity (no model dependency) — pre-selecting "fold into alias" above threshold, defaulting to "propose new" only when nothing is close. Approval writes the alias or new value with `admin_manual` provenance and **resolves the originating doc's `raw_unmatched_value`**; convenios are **registry-only** (alias-fold only, never created here).
6. **`reviews:scan-expiry` command + the succession endpoint.** `php artisan reviews:scan-expiry [--days=90] [--dry-run]` materializes `expiry` `document_review_tasks` for active prose **within 90 days of `validity_end` or already past** (incl. the Sprint-3 `date_expired_active` staleness docs). `ReviewQueueController::resolveExpiry` (`POST /admin/review/expiry/{taskId}/resolve`) writes **`predecessor_document_id`** on a human-confirmed `link_successor` — **same-convenio candidates only, never auto-retire** (also `dismiss`/`escalate`). The verify path is the **unchanged Sprint-3 `confirm()`**.
7. **`IdentityPresenter`** exposes `vocabulary.approve` (+ `guardrails.manage`) so the frontend can gate affordances; the server enforces regardless. `DocumentController@index/@show` add `is_ai_proposed` + `tagging_confidence` for the fuchsia marking + queue sort.

### hr-frontend (reuse the Sprint-3 review/confirm UI)

- **`ReviewQueuePage.tsx`** — the messy-tail hub, three tabs: **AI tagging** (the `under_review` backlog sorted by `tagging_confidence`, **fuchsia-marked**, opening the existing `DocumentDetailPanel`), **Vocabulary proposals** (the variant→alias-first chooser; propose vs approve per ability), **Expiry** (near-expiry docs + the same-convenio successor handoff). New **Review** nav item in `AdminShell.tsx`.
- **Verify reuses the Sprint-3 Confirm-tags button + bounded edit** — no new verify mechanism. `DocumentDetailPanel` gains the fuchsia AI banner, the `AiSuggestionsSection` (accept/adjust AI facets → confirm), and a "Re-suggest with AI" button.
- **`ProposeVocabularyForm.tsx`** — the reusable propose/approve form (variant suggestion fetch; super_admin propose-and-approve).
- **The `--provenance-ai` token re-valued to fuchsia `#e879f9`** (was violet `#7c3aed`) in the vanilla-CSS token system (ADR-0012/0013, **not** Tailwind), applied to the three present unverified-AI surfaces (queue / AI-proposed facets / proposed-vocabulary list; the AI-succession marker is skipped — deferred to 7d). New `api.ts` types + calls for every endpoint.

### Docs

ADR-0020 (the inert-until-verified + vocabulary-approval decision), `architecture.md` §6 + §10 + build-order (the built LLM tier, the two invariants, the Review module), `data-model.md` (the `vocabulary_proposals` table, the now-written `predecessor_document_id`, the lit `ai_agent` lane, the rescue-path first-writer note), `roadmap.md` (7a **DONE**; AI succession-proposal explicitly carried to 7d), and all three READMEs.

---

## 2. The two safety invariants (the whole sprint — enforced + proven)

**Invariant 1 — AI proposals keep the document `under_review`** (never `auto_proposed`/`verified`). `auto_proposed` is the clean-parse **retrievable** state; writing it on an AI guess would make a wrong guess instantly answerable — the exact harm. `TagProposalService` leaves `tagging_status` untouched; only the human verify action flips `under_review → verified`. So the embedding gate holds the doc at **0 chunks** until a human confirms.

**Invariant 2 — the AI writes ONLY `ai_agent` provenance** — never the authoritative FK columns (`convenio_id`/`territory_id`/`sector_id`/`document_type_id`/validity/`retrieval_status`). The AI is a **strict proposer**: `ai_agent` `tag_events` + unverified `document_topics` + `tagging_confidence` only. The scope FKs change only by the human verify/reassign action (we deliberately do **not** pre-fill FKs "to make verify one-click" — it muddies the cleanest safety property).

**The embedding gate is preserved, never weakened.** 7a's AI respects `tagging_status != under_review`; it does not modify it. Tightening it to `= verified` was rejected (it would change which 42 `auto_proposed` docs are currently retrievable — a behaviour change to the frozen answer loop).

---

## 3. Acceptance proof — the invariant tests (server is the boundary)

Run against the dedicated Postgres test DB (`hr_platform_test`; the schema is Postgres-specific — pgvector/enums), configured in `phpunit.xml`. Drives the service + endpoints **directly** (not the UI), with a stubbed provider returning a fixed proposal envelope.

```
php artisan test --filter=Sprint7aTagProposalInvariantTest --testdox

Sprint7a Tag Proposal Invariant (Tests\Feature\Sprint7aTagProposalInvariant)
 ✔ Invariant 1 ai proposal keeps document under review and zero chunks
 ✔ Invariant 2 ai writes only ai agent provenance never the fk columns
 ✔ Non super admin can propose but not approve vocabulary
 ✔ Super admin propose and approve writes new sector
 ✔ Variant to alias is offered and folds into existing value
 ✔ Scope based succession writes predecessor and never auto retires
 ✔ Cross convenio succession is rejected

OK (7 tests, 33 assertions)
```

Mapped to the non-negotiable acceptance criteria:

| Required proof | Test | Result |
|---|---|---|
| **Invariant 1** — after the AI proposes, the doc is still `under_review` and has **0 `document_chunks`** (genuinely unretrievable); a human verify makes it embeddable | `invariant_1_ai_proposal_keeps_document_under_review_and_zero_chunks` | ✅ post-propose `tagging_status === under_review`, chunk count 0, `isEmbeddable() === false`; after the human verify it flips to embeddable |
| **Invariant 2** — after the AI proposes, the doc's `convenio_id`/`territory_id`/`sector_id`/`document_type_id`/validity are **unchanged** from their pre-proposal state; only `ai_agent` `tag_events` exist | `invariant_2_ai_writes_only_ai_agent_provenance_never_the_fk_columns` | ✅ all scope FKs identical before/after; `≥1` `ai_agent` `tag_event`; **0** non-`ai_agent` rows written by the proposal |
| AI proposes vocabulary, human approves — **`knowledge_editor` can propose, cannot approve** | `non_super_admin_can_propose_but_not_approve_vocabulary` | ✅ propose 200/201; approve → 403 |
| **super_admin propose-and-approve** writes a new value | `super_admin_propose_and_approve_writes_new_sector` | ✅ one action → a new `sector` row + `admin_manual` provenance + the proposal `approved` |
| **variant→alias is the default** and folds into the existing value | `variant_to_alias_is_offered_and_folds_into_existing_value` | ✅ a near-duplicate is offered as a fold; approving `alias` adds the alias to the existing value, no new value created |
| **succession writes `predecessor_document_id`** (same-convenio) and **never auto-retires** | `scope_based_succession_writes_predecessor_and_never_auto_retires` | ✅ `predecessor_document_id` set on confirm; the predecessor's `retrieval_status` is **unchanged** (not auto-historical) |
| **cross-convenio succession is rejected** | `cross_convenio_succession_is_rejected` | ✅ a different-convenio successor → rejected; no `predecessor_document_id` written |

> Full backend suite: `php artisan test` → **40 passed, 145 assertions** (the 7 above + Sprints 4/5/6 matrices + framework examples) — no regressions from the additive build. Frontend: `tsc --noEmit` clean.

> Test-harness note (same as Sprint 6): the `AnswerModelSetting` row is pinned to `id = 1` in `setUp` because Postgres sequences are not rolled back between `RefreshDatabase` tests and `id` isn't fillable — a harness artifact, not production behaviour. So the unchanged `TagProposalService` resolves a configured answer model when proposing.

---

## 4. Why the two invariants are true by construction

- **Invariant 1** — `TagProposalService` simply never assigns `tagging_status`. There is no code path in the proposal that sets it to `auto_proposed`/`verified`; the only writer of `verified` is the Sprint-3 `confirm()` (a human action). The embedding selection query (`tagging_status != under_review`) is unchanged, so an unverified doc is structurally absent from the retrievable set.
- **Invariant 2** — the proposal persists into `tag_events` (`source = ai_agent`) and unverified `document_topics` (`source = ai_agent`) and `tagging_confidence` only. It does not assign `documents.convenio_id`/`territory_id`/`sector_id`/`document_type_id`/`validity_*`/`retrieval_status`. The scope-FK writers are the bounded-edit confirm/reassign (human) paths.
- **Vocabulary** — `vocabulary.approve` is a super_admin-only spatie ability; the propose route rides `knowledge.edit`. The AI's `proposed_by_source` is always `ai_agent` with no approver — it cannot reach the approval writer. Convenios are excluded from `createValue`.
- **Succession** — `resolveExpiry` validates the successor shares the predecessor's `convenio_id` before writing `predecessor_document_id`, and never writes `retrieval_status` on either document. AI *suggestion* of candidates is not built (deferred to 7d).

---

## 5. Eyes-on checklist (Pedram runs live)

Apply the migrations + ability seed to the dev DB first (`php artisan migrate`; the `vocabulary.approve` seed is idempotent and `RoleSeeder` grants it to super_admin). Ensure the queue worker is running (`php artisan queue:work`) and hr-ai is up.

- [ ] **Ingest an unresolved doc** (a no-`numero` / scan) → the queued `ProposeDocumentTags` job auto-tags it; it appears in **Review → AI tagging**, **fuchsia-marked**, with the AI's proposed facets + confidence.
- [ ] **Confirm it is NOT answerable** — ask a question that should hit it → it **escalates** (the doc has **0 chunks**; it is unretrievable while `under_review`).
- [ ] **Verify the proposal** (open the card → Sprint-3 **Confirm**, accept/adjust the AI facets) → it turns **normal** (no longer fuchsia), becomes embeddable/answerable, and the **AI origin shows as the `ai_agent` dot in the provenance timeline** (history, not a live alert).
- [ ] **Propose-new-vocabulary** (a genuinely-new sector value): **variant→alias is offered first**; as a non-super_admin you can **propose only**; as super_admin you can **propose-and-approve**. Approving a new value resolves the originating doc's unmatched value.
- [ ] **Expiry queue** — run `php artisan reviews:scan-expiry`; a near-expiry (or past-`validity_end`-but-active) doc appears in **Review → Expiry**. Confirm a **same-convenio successor** → `predecessor_document_id` is written and the **old doc is NOT auto-retired** (its `retrieval_status` is unchanged). A cross-convenio successor is not offered/rejected.
- [ ] **Throughout: fuchsia = unverified-AI only.** Confirmed/verified content is **never** fuchsia; the colour never bleeds onto a verified doc, an approved vocabulary value, or a confirmed succession.

How to peek live:

```sql
-- the AI lane, lit (a proposal's provenance)
SELECT entity_id, field, new_value, source, confidence, created_at
FROM tag_events WHERE source = 'ai_agent' ORDER BY id DESC LIMIT 20;

-- the lineage write-side, now written
SELECT id, title, convenio_id, predecessor_document_id
FROM documents WHERE predecessor_document_id IS NOT NULL;

-- the propose→approve record
SELECT id, facet, proposed_value, status, proposed_by_source, resolution
FROM vocabulary_proposals ORDER BY id DESC LIMIT 20;
```

---

## 6. Notes / decisions worth flagging

- **AI succession-proposal is deferred to 7d** (per the resolved §7.7). 7a built only the **human-confirmed** lineage write-side (the expiry queue + the `predecessor_document_id` write). Suggesting *which* doc succeeds/coexists/conflicts by meaning needs 7d's semantic-comparison machinery; doing it in 7a would crudely pre-empt it. 7d reuses this "inert until verified" spine.
- **Document-level facet tagging only.** `/propose-tags` returns one document's facets — not multi-scope fact segmentation (that is 7b, which reuses the same inert-until-verified spine).
- **No answer-loop change (2b frozen).** `/retrieve`·`/route`·`/synthesise`·`/ground`·`ChatService` are untouched; `/propose-tags` is a separate upstream call. The embedding gate is unchanged.
- **The AI never creates vocabulary** — it flags `raw_unmatched_values` + a variant hint; growth is the propose→approve flow (variant→alias default; approve is human).
- **Nothing committed** — awaiting your review.
