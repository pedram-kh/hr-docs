# Sprint 7g — Review

> Location: `hr-docs/sprints/sprint-07g/review.md`
> Spec: [`sprint-07g-spec-and-prompt.md`](sprint-07g-spec-and-prompt.md)
> Branch: `sprint-7g` in all four repos. **Item 0 merged to `main` separately, first** (a security fix that changes the deploy path itself — the sprint's own exception to the feature gate). **Items 1–5 are on `sprint-7g`, not merged** — feature-gated, stop before merge.
> Status: **all six items complete.** `Sprint7cAdditivityRegressionTest` and the 7c/7f reference-fact suites are green after every item; nothing in this sprint changed when or whether anything escalates, retrieves, synthesises, grounds, or answers.

---

## 0. Overarching constraint — how it was held

The spec's own words: *"Nothing here changes when or whether anything escalates, retrieves, synthesises, grounds, or answers; `Sprint7cAdditivityRegressionTest` and the 7c/7f reference-fact suites must stay green after every item."* Concretely: every item below is additive (new columns, a new command, a new comparison inside an existing scan, a rewritten shell script, new frontend state) — nothing in `RouterService`, `ChatService`'s decision points, `ReferenceFactAnswerService`'s matching, `GuardrailService`, or hr-ai's `/route`/`/retrieve`/`/synthesise`/`/ground` decision logic was touched. The **only** hr-ai change in the whole sprint is one additive field (§2 below); hr-ai's decision-making code is untouched. The full hr-backend suite was run after each item and grew monotonically as tests were added — **419 → 424 → 431 → 435**, all green, at every step — with no regression in the pinned golden-trace or reference-fact suites at any point.

| Repo | HEAD after this sprint's work | Notes |
|---|---|---|
| `hr-backend` | `a65dd1a` (`sprint-7g`) | Items 1, 3, 4, 2 (Item 5 has no backend commit) |
| `hr-ai` | `43b23ff` (`sprint-7g`) | Item 1 only (one additive field — see §2) |
| `hr-frontend` | `f099e76` (`sprint-7g`) | Items 1 (UI), 2 (UI + hash-routing) |
| `hr-docs` | `d2fc3c0` (`sprint-7g`) + this review's own commit | Item 0 (merged to `main` separately), Item 5, this review |

---

## Item 0 — Deploy keys, then private repos (security; merged first, separately, on `main`)

**Status: done, merged, verified twice on real infrastructure.** Full build record: `deploy.md` §7 Session 5 (not duplicated here). Summary: four read-only, per-repo GitHub deploy keys generated and installed on the staging box (`/opt/hr-staging/keys/`, `~/.ssh/config` aliases, `IdentitiesOnly yes`); `deploy.sh`/`deploy-run.sh` switched from anonymous HTTPS to the aliased SSH URLs; verified green **twice** — once with all four repos still public, once immediately after Pedram flipped all four to private in the GitHub console. No PAT was ever created or considered. This item is the sprint's one exception to "stop before merge": it changes the deploy path itself, so it had to land on `main` before the other five items could safely proceed on a branch that also needs to deploy to staging for their own proofs.

---

## Item 1 — Escalation explanations (ADR-0029)

**Status: done, on `sprint-7g` (hr-backend `9263565`, hr-ai `43b23ff`, hr-frontend `01953b3`).** Full design rationale: `architecture.md` §8.6, `ADR-0029`. Schema: `data-model.md` §9.

### 1.1 Employee side — one fixed message, asserted by a full-enum scan

`ChatService::EMPLOYEE_ESCALATION_MESSAGE` replaces every reason-specific employee-visible string at the single point `persistTurn()` writes the answer. `EmployeeEscalationMessageScanTest` drives every reason the guardrail baseline, router, salary path, reference-fact path, and composition path can produce and asserts the text is identical across all of them and contains no reason token, document id, convenio name, or another person's name.

### 1.2 HR side, deterministic facts

`EscalationExplainer::MATRIX` — **39 entries**, counted directly against the source rather than transcribed from the build commit's own (slightly off) count of 38: the pre-retrieval guardrail baseline + `off_domain` (6), `explicit_request` (1), the low-confidence floor split by sub-gate (11), the fact-vs-convenio conflict (1), `salary_coverage_gap` split by cause (5), `reference_fact_coverage_gap` split by the 7c/7f outcomes (9), and the publish-time 409 reasons (6) — 6+1+11+1+5+9+6 = 39. `EscalationExplainerGuardTest` walks the matrix and fails the build if any entry lacks a registry builder — proven: the guard test is part of the 435-strong green suite. `EscalationExplainerTest` runs one data-provider case per matrix entry (39/39, confirmed against `cases()`'s own array — one case per entry, no duplicates).

### 1.3 HR side, the AI paragraph

`EscalationExplanationService` calls hr-ai's existing `/synthesise` with `ROUTER_MODEL` (Haiku). `EscalationExplanationGuard` verifies every fact value is represented and no new number/name was introduced, with `EscalationExplanationServiceTest` covering the happy path, the guard-rejection fallback, and a provider-error fallback — all three land on `explanation_text = null` → `factsToSentences()` except the happy path. `fix_action`/`fix_surface`/`fix_link` are read from `explanation_facts` in every case, never from the model's output.

**The one disclosed hr-ai change (hr-ai `43b23ff`).** `EscalationExplanationService` needs a per-call cost (the spec requires "cost logged per card"), and hr-backend has never known provider pricing — duplicating a pricing table there would be a layering violation. `ClaudeProvider.synthesise()`'s `trace_fragment` gained `cost_usd` (mirroring `propose_groups()`/`/ocr-page`'s existing computation) plus one new `OCR_PRICING_PER_MTOK` entry, `claude-haiku-4-5` ($1.00 / $5.00 per MTok, checked against Anthropic's published rate card). This is additive to an existing response shape; every existing `/synthesise` caller already ignores unknown `trace_fragment` keys, and no decision logic in `synthesise()` changed.

### 1.4 Storage + backfill

Five additive nullable columns on `escalation_cards` (not four, as the spec's own shorthand said — `explanation_facts` and `explanation_text` are two separate columns so the deterministic layer can be complete while the AI layer is still null; noted as a deliberate, disclosed deviation from the spec's own count, not a silent one). `escalations:backfill-explanations` computes both layers for every pre-7g card; `EscalationsBackfillExplanationsTest` covers it.

### 1.5 Frontend

`EscalationCardDrawer.tsx` gained an "Explicación" block — the "Resumen IA" paragraph (explicitly labelled) or its client-side `factsToSentences()` mirror when `explanation_text` is null — and a "Corregir" button opening `fix_link` in a new tab when one exists. `tsc -b` and `vite build` both clean.

### 1.6 Tests

`EscalationExplainerTest` (39), `EscalationExplainerGuardTest`, `EscalationExplanationGuardTest`, `EscalationExplanationServiceTest`, `EscalationsBackfillExplanationsTest`, `EmployeeEscalationMessageScanTest`, plus updates to `Sprint6GuardrailInvariantTest` and `Sprint7cCompositionTest` for the new fixed employee message. **Full hr-backend suite at this commit: 419/419 green**, `Sprint7cAdditivityRegressionTest` included.

---

## Item 2 — Review surfaces at real corpus size

**Status: done, on `sprint-7g` (hr-backend `a65dd1a`, hr-frontend `f099e76` + `01953b3` for the hash-routing half).**

### 2.1 Which tabs already paginated, and what changed

| Tab | Before 7g | Change |
|---|---|---|
| AI tagging (Documents-derived) | `paginate(50)` (Sprint 7e fix) | frontend pager/total added, mirroring Documents |
| Reference facts | `paginate(50)` (Sprint 7b-2) | frontend pager/total added; **+ `id` + `source_excerpt` first line** in the row |
| Vocabulary proposals | `->get()` (no pagination) | **backend: `->paginate(50)->through(...)`** (additive) + frontend pager/total |
| Expiry | `->get()` (no pagination) | **backend: `->paginate(50)->through(...)`** (additive) + frontend pager/total |
| Groups | N/A — a per-convenio tree, not a flat list | **not paginated** (see §2.4 below); fact lists gain `id` + `source_excerpt` |

The two backend changes are the standard `->paginate(50)->through(fn ...)` envelope already used by Reference-facts and Documents — no new pagination convention introduced. `ReviewQueueController::expiry()` — the one with real logic in its map closure (successor candidates, AI-proposal fields) — kept that closure unchanged, just moved inside `->through()`.

### 2.2 `source_excerpt` propagation

`FactGroupBindingPlanner::plan()` (the one pure function three different Groups-tab endpoints each independently map over) now carries `source_excerpt` through in its returned array; `ConvenioGroupController`'s three call sites (`unbindable_facts` in `show()`, `would_bind_facts` in `nodeRow()`, `would_bind`/`needs_manual_binding` in `bindingDiff()`) each pick it up with one added line. `ReferenceFactController::listRow()` gained `id` as its first key.

### 2.3 Deep links

`#view=<view>&tab=<tab>&fact=<uuid>&emp=<uuid>&convenio=<id>`, additive on top of the pre-existing bare `#doc=<uuid>` compat form (Sprint 7e). `AdminShell` parses on mount and on `hashchange`; `ReviewQueuePage`/`GroupsQueue`/`DirectoryPage` each gained an `initial*` prop threaded from the parsed hash. This is Item 1's `fix_link` scheme.

### 2.4 A judgment call, flagged here as the spec asked

The spec names "all four Review tabs (AI tagging, Reference facts, Groups, Vocabulary proposals, Expiry)" — five names for what it calls four tabs, because Groups is structurally different (a per-convenio tree, naturally bounded by convenio size, not an unbounded flat list the way the other four are). **Decision taken:** paginate AI tagging / Reference facts / Vocabulary proposals / Expiry; give Groups the `id`/`source_excerpt` inline treatment (the part of the spec that plainly does apply — "Same for the Groups tab's fact lists") but not a pager, since there is no flat list there to page through. Recorded here as a judgment call, not silently resolved.

### 2.5 Tests

`Sprint7gReviewPaginationTest` (4 tests, 23 assertions): vocabulary-proposals pagination shape + total across two pages (55 rows → 50 + 5); expiry-queue pagination shape + total (52 rows → 50 + 2); the reference-facts list carries `id` + `source_excerpt`; the Groups tab's `would_bind_facts`/`would_bind` both carry `source_excerpt`. One pre-existing test (`Sprint7dSuccessionProposalTest`) needed a one-line assertion-path update (`tasks.0...` → `tasks.data.0...`) for the new paginated envelope on `/admin/review/expiry` — the only pre-existing test the pagination change touched.

**Frontend verification:** `npx tsc -b` clean, `npx vite build` clean (`dist/assets/index-CDpwJvwo.js`, 464.79 kB). `npx eslint` on the four changed/new files reports the same 5 pre-existing `react-hooks/set-state-in-effect` errors that exist on the pre-7g code at the same relative positions (confirmed via `git stash`/re-lint) — no new lint issue introduced by this item.

**Full hr-backend suite at this commit: 435/435 green.**

---

## Item 3 (F-1) — checksum-dedupe must not silently re-type a document

**Status: done, on `sprint-7g` (hr-backend `7584424`).**

**The bug.** A checksum (`content_hash`) match on re-ingest is the strongest possible identity signal — the bytes are identical to an already-ingested document — yet `DocumentIngestor::ingest()` previously overwrote `document_type_id`/`convenio_id`/`validity_start`/`validity_end` **unconditionally** on a hash match, using whatever the new call's filename parse or `--as-reference` flag happened to produce. Re-ingesting the exact same salary `.xlsx` with `--as-reference` would silently flip it to `reference_source` — never embedded again, never salary-queryable again — with no gate and no audit trail.

**The fix.** `detectScopeChanges()` mirrors the **existing** manual-edit scope gate (`DocumentController::reassignFacet()`/`updateLifecycle()`) exactly: a dedupe hit that would change one of the four scope-affecting facets reports *"ya existe como documento N (tipo X)"* and changes **nothing**, unless the caller is explicit — `confirm_scope_change` (HTTP, `DocumentController::upload()` — 409 + `scope_affecting` when any file in a batch hits the gate unconfirmed) or `--retype` (CLI, `IngestFolder`, which reports a distinct `retype-blocked` count rather than folding it into `errors`, since the gate firing is correct behaviour, not a failure). An explicit confirm/`--retype` applies the change and records an `admin_manual` `TagEvent` for every changed facet — never `filename_parse`, since a human explicitly asked for it.

**Verified:** re-ingesting the same document unconfirmed leaves `document_type_id`/`convenio_id`/`validity_start`/`validity_end` identical and `updated_at` untouched, with no second document created. `Sprint7gChecksumDedupeTest` covers the service-level block/confirm/idempotent-reingest cases, the HTTP-level 409-then-confirm round-trip, and a CLI folder-ingest smoke test.

**Full hr-backend suite at this commit: 424/424 green.**

**Operational note (recorded in `deploy.md` §7 Session 6):** this changes `documents:ingest-folder`'s behaviour on a re-run where a file's bytes are unchanged but the surrounding context (folder path, `--as-reference`) implies a different type/scope — a case that previously "fixed itself" silently now reports `retype-blocked` and needs `--retype` to actually apply.

---

## Item 4 (F-2) — Binding-aware duplicate detection

**Status: done, on `sprint-7g` (hr-backend `a217cf7`). Proven against the real staging corpus, then reverted; re-verified again during this review's own write-up (see below) — both runs agree.**

### 4.1 The fix

`FactResolutionService::relateByBindingOrTokens()` — when **both** facts in a candidate pair have at least one bound `ConvenioGroup` node (Sprint 7f, ADR-0028), the comparison uses the **nodes**: same node, or one is the parent/child of the other (ancestor/descendant) → a candidate version pair; sibling nodes (two different sub-areas of the same split group) → **not** a duplicate, full stop — binding is authoritative once it exists, so this never falls through to the token pass. Falls back to the pre-7g `GroupLabel::relate()` token-overlap heuristic, unchanged, only when at least one fact in the pair is genuinely unbound.

### 4.2 Unit proof

`Sprint7gBindingAwareDuplicatesTest` (7 cases): bound siblings of a split group are not flagged despite token overlap; the dry-run reports zero pairs for that case; bound ancestor/descendant and same-node pairs still flag; two unrelated top-level groups don't flag; mixed bound/unbound and fully-unbound pairs still fall back to the unchanged token pass (the real Navarra Acción e Intervención Social re-group case). **Full hr-backend suite at this commit: 431/431 green.**

### 4.3 Real-staging proof — re-run for this review, with the container reverted immediately after

The commit's own build session already produced and reverted this proof once; it was **reproduced from scratch** while writing this review (fresh dry-runs, a fresh `docker cp`, a fresh checksum-verified revert) so the exact numbers here are first-hand, not transcribed from memory. Staging was running `hr-backend@e242608` (Sprint 7f close — i.e. the genuine pre-Item-4 state) throughout.

**Convenio 21 (Navarra Hostelería)'s real group tree** (the corpus's only convenio with a bound, split group — the scenario Item 4 exists for):

| node | label | parent |
|---:|---|---|
| 31 | Grupo 1 | — (top) |
| 32 | Grupo 2 | — (top, split) |
| 34 | Grupo 2 › área 5 | 32 |
| 35 | Grupo 2 › resto áreas | 32 |
| 33 | Grupo 3 | — (top) |

**The facts bound to those nodes** (fact 44 and fact 34 are *compound* — one sentence binds two nodes each):

| fact | `group_label` | bound node(s) |
|---:|---|---|
| 45 | Grupo 2 (resto áreas) | 35 |
| 44 | Grupo 1 (todas las áreas) y Grupo 2 (área 5) | 34, 31 |
| 35 | Grupo 2 excepto área cinco | 35 |
| 34 | Grupo 1 y área cinco de Grupo 2 | 34, 31 |
| 46 | Grupo 3 (todas las áreas) | 33 |
| 36 | Grupo 3 | 33 |

**BEFORE** (`hr-backend@e242608`, the pre-7g code, `facts:scan-duplicates --dry-run`, run live via SSH through the container's own resolved environment):

```
Scanned 84 fact(s); would flag 10 pair(s) for human resolution.
  43→10   55→27   52→32   45→44   35→34   85→83
  81→13   54→27   46→36   44→35
```

Three of those ten are the false positives this item exists to fix — `45→44`, `44→35`, `35→34` — every one of them a pair whose bound nodes are **siblings** under the split Grupo 2 (34 vs 35), flagged only because the old token pass sees the shared digit "2" in both labels. Because the old algorithm reports at most one candidate per fact, each of facts 44/45/34/35 got **locked onto a sibling** as its "best match" and never reached its **true** same-node pair.

**AFTER** (the single isolated `FactResolutionService.php` change from commit `a217cf7`, applied via `docker cp` into the running container — no other file touched, no migration, no restart of anything but PHP's config cache):

```
Scanned 84 fact(s); would flag 9 pair(s) for human resolution.
  43→10   55→27   52→32   45→35   46→36   85→83
  81→13   54→27   44→34
```

- **`45→35`** — fact 45 and fact 35 are both bound to node 35 (the **same** node). This is a **new, correct** true-positive that the old algorithm never reached (it had spent fact 45's one match slot on sibling fact 44 instead).
- **`44→34`** — fact 44 and fact 34 are both bound to `{34, 31}` (the **same** node set — a genuine, compound-scope version pair). Same story: new and correct, previously masked by the sibling false match to 35.
- **`35→34` is gone entirely** — node 34 and node 35 are siblings, so this pair is never generated under the binding-aware rule, not even as a candidate.
- **`46→36`** (same node 33) is **unaffected** — it was already correct under the old token pass (by coincidence of both labels being literally "Grupo 3"), and stays correct under the new rule.
- **Every unbound-fact pair is unaffected**, byte-for-byte: `43→10`, `81→13`, `55→27`, `54→27`, `52→32`, `85→83` — including the real **Navarra Acción e Intervención Social** pair (`54→27`/`55→27`, convenio 18, the case ADR-0024's original token pass was built to catch) still flags exactly as before, because both facts on each side of that pair are unbound.

Net: **10 pairs → 9 pairs**, but the headline is not the count — it's that the fix simultaneously **removed** a sibling-crossing false-positive chain and **corrected** the two facts it had wrongly matched onto their real same-node predecessors, without touching a single unbound-fact pair.

**Revert, checksum-verified both times:** the container's `FactResolutionService.php` was restored from the pristine on-disk checkout (`md5sum` before: `109918ed…`; after the AFTER run: `a61297c2…`; after revert: `109918ed…` again — exact match) and a closing dry-run confirmed the container is back to flagging the original 10 pairs. `docker compose ps` confirms `hr-staging-hr-backend-1`/`-worker-1` both stayed `Up`/`healthy` throughout — this was a file-level swap, not a container recreate, so there was no downtime to revert from.

---

## Item 5 (F-3) — `otp.sh` — stop matching a Message-ID fragment

**Status: done, on `sprint-7g` (hr-docs `d2fc3c0`). Re-verified end-to-end during this review's own write-up.**

**The bug.** Staging's `MAIL_MAILER=log` writes each mail's full raw MIME dump — headers (`From`/`To`/`Subject`/`Date`/**`Message-ID`**/…) followed by the rendered HTML body — to `storage/logs/laravel.log`. The pre-7g script did `grep -oE '[0-9]{6}' | head -n 1` over the whole dump from the `Subject:` line down, so it returned the **first** run of 6+ consecutive decimal digits it found — which, because a hex Message-ID is ~40% decimal digits, is frequently a fragment of the Message-ID sitting in the header block **above** the real code, not the code itself. Documented live-staging instance: `Message-ID: <798002088274730d69836be853d485a0@hr-platform.local>` yielded `798002` while the real, rendered code below it was `795253`.

**The fix.** Anchors on the mailable's own markup instead of "any 6 digits anywhere": the login-code email renders the code in exactly one place, a `<p style="... letter-spacing: 6px ...">` paragraph, and nothing else in that specific line reaches 6 consecutive digits (the style attribute's own numbers — `32px`, `700`, `6px`, `24px`, `0` — are each shorter and digit-separated). Combined with a second fix — an `awk` state machine keyed on Monolog's one-line-per-record log prefix that keeps only the **last** record whose own `To:`/`Subject:` both match the target email/subject (order-independent), so two accounts requesting codes close together can no longer cross-contaminate.

**Re-verified for this review, live, on real staging** (the fixed script fetched from `origin/sprint-7g@d2fc3c0` onto the box, run, then reverted back to the pre-7g version — `git status` clean afterward):

```
$ otp.sh admin@hr-staging.internal
[otp] requesting a login code for admin@hr-staging.internal...
[otp] requested.
[otp] reading the code from hr-backend's log (MAIL_MAILER=log)...
[otp] code found: 066899
[otp] verifying...
{"token":"24|oklRv8m...","token_type":"Bearer","identity":{"account_type":"admin", ...}}
[otp] done.
```

The raw log tail at the time of this run held **three** login-code emails to the same address in sequence — `795253`, `669024`, `066899` — and the script correctly selected the newest (`066899`), which then verified successfully and returned a real Sanctum bearer token. This exercises both halves of the fix at once: the right **field** (the OTP line, not a header) and the right **message** (the newest one for this recipient, not just the newest occurrence of the subject text anywhere in the tail).

---

## Docs at close

- `architecture.md` §8.6 (escalation explanations — deterministic facts, the AI paragraph, the deep-link scheme) + the §2 deploy-key note (Item 0, landed with Session 5) + the §10 Review-tabs pagination note.
- `data-model.md` §9 (`escalation_cards`' five new columns, documented individually with their nullability rationale).
- `deploy.md` §7 Session 6 (Items 1–5 build-record summary, including the dedupe-guard operational note and the Review-pagination note) alongside Session 5 (Item 0, already landed).
- **ADR-0029** — Escalation explanations: deterministic facts, AI prose over them, employees never see the reason.
- `roadmap.md` — Sprint 7g marked **DONE**, with a full entry between 7f and Sprint 8.
- This file.

## Verdict

All six items are built, tested, and — where the item makes a real-world behavioural claim (Items 4 and 5) — proven against the live staging corpus with the box left in its original state afterward, checksum-confirmed. The hr-backend suite grew from 419 to 435 tests across the sprint with zero regressions at any step, `Sprint7cAdditivityRegressionTest` included at every measurement. The one judgment call not explicitly pre-confirmed with Pedram — pagination on four tabs vs. the id/excerpt treatment on all five named surfaces including Groups (§2.4) — is flagged above rather than silently resolved. Ready for review → merge per the sprint's feature gate.
