# Sprint 5 — Correction-01: publish conflict fence fails open on topic select

> Location: `hr-docs/sprints/sprint-05/correction-01.md`
> Reviewer: Claude · eyes-on: Pedram
> Touches the **load-bearing legal fence** (the Sprint-4 publish no-silent-override guard). Small change, maximum care. Same loop: this spec → Cursor builds → re-runs the three-case trace as proof → Pedram eyes-on → commit.

## The bug (proven, read-only diagnosis)
`EscalationService::detectConflicts(convenioId, topicId)` (`hr-backend/app/Services/EscalationService.php:311–322`) decides whether publishing an `internal_hr_ruling` would silently override an active `official_convenio` in the asker's scope. Today:

```php
Document::query()
  ->where('convenio_id', $convenioId)
  ->where('authority_level', 'official_convenio')
  ->where('retrieval_status', 'active')
  ->when($topicId !== null,
     fn ($q) => $q->whereHas('topics', fn ($t) => $t->where('topics.id', $topicId)))
  ->get(...);
```

A non-empty result blocks the publish (re-escalate, keep draft). The problem is the `when($topicId)` branch: when a topic **is** selected, it narrows the block to convenio docs **tagged with that exact `topic_id`**. But the convenio side is almost never tagged — **convenio 22's active docs (95/97/98) have 0 `document_topics` rows; only 2 of 100 documents carry any topic tag corpus-wide** (the empty-honest topic lens). So the `whereHas('topics', …)` filter **eliminates the genuine conflict from the result set**, the fence reports "no conflict," and the ruling **publishes on top of a governing convenio** — the exact harm the fence exists to prevent.

Traced result (real queries, nothing persisted):

| Case | Inputs | Result | Outcome |
|---|---|---|---|
| No-topic (contrast) | conv 22, `topicId=null` | 3 docs | **BLOCK** (safe) |
| Topic, untagged convenio (reality) | conv 22, `topicId=2`, docs untagged | **0 rows** | **ALLOW** ❌ |
| Topic, convenio doc tagged | conv 22, `topicId=2`, doc 95 tagged | 1 row | BLOCK |

**Root cause:** the topic match is **asymmetric** — the ruling is force-tagged at publish, but the convenios it would conflict with are not, so the topic-equality join is almost always empty. The selected topic **replaces** the scope block instead of **adding** to it, so the fence **fails open** (toward allowing). For a safety fence, the safe failure direction is **closed** (toward blocking → human review).

## The fix (make topic additive, fail closed)
Topic must **tighten** the block where the convenio is actually tagged, and **fall back to the safe scope-only block** where it is not — never eliminate a governing convenio from the check just because it lacks a tag. New rule:

> **Block when there is any active `official_convenio` in the asker's convenio scope, AND (it shares the ruling's topic, OR it has no topic tags at all).**

So an **untagged-but-governing** convenio still blocks. Concretely, the `topicId` branch changes from "convenio shares this topic" to "convenio shares this topic **OR** the convenio doc has no `document_topics` rows":

```php
->when($topicId !== null, fn ($q) => $q->where(function ($w) use ($topicId) {
    $w->whereHas('topics', fn ($t) => $t->where('topics.id', $topicId))
      ->orWhereDoesntHave('topics');   // untagged-but-governing convenio still blocks
}))
```

Behaviour across both worlds:
- **Sparse topics (today):** every governing convenio is untagged → `orWhereDoesntHave('topics')` catches them → behaves like the safe scope-only block (over-blocks, routes to human). ✅
- **Rich topics (post-Sprint-7):** convenios are tagged → the topic genuinely narrows the block to real same-topic conflicts, and a *tagged* convenio on a *different* topic correctly does **not** block. ✅
- **Can never fail open** because a convenio wasn't tagged. ✅

This is the conservative, fail-closed shape, and it composes cleanly with the future semantic-similarity evolution already on the roadmap (Sprint 7) — that replaces the *topic proxy* with *meaning*, but until then this keeps the fence safe.

## Scope (tight)
- **Only** `EscalationService::detectConflicts` (and, if the block message distinguishes "same-topic conflict" vs "scope conflict", a label tweak so the surfaced reason is accurate — e.g. "an active official convenio governs this scope" when the match was via `orWhereDoesntHave`). No other publish-path logic changes.
- **No** schema change, **no** answer-loop change, **no** change to how the ruling is force-tagged at publish, **no** change to the no-topic path (already safe). `hr-ai` untouched.

## Acceptance proof — re-run the exact three-case trace (the required verification)
Reproduce the diagnosis trace against the **fixed** code, read-mostly (use a rolled-back transaction for the tagging case; persist no published ruling). Report the table:

| Case | Inputs | Expected after fix |
|---|---|---|
| No-topic | conv 22, `topicId=null` | **BLOCK** (unchanged) |
| Topic, untagged convenio | conv 22, `topicId=2`, docs untagged (reality) | **BLOCK** ✅ (was ALLOW — the fix) |
| Topic, convenio doc tagged **same** topic | conv 22, `topicId=2`, doc 95 tagged topic 2 (rolled-back tx) | **BLOCK** |
| Topic, convenio doc tagged **different** topic | conv 22, `topicId=2`, doc 95 tagged topic 7 only (rolled-back tx) | **ALLOW** ✅ (genuine no-conflict: convenio is tagged, on another topic — proves the fix still allows a real non-conflict, i.e. it's not just "block everything") |

The fourth case is important: it proves the fix is a *precise tightening*, not a blunt "always block" — a convenio that **is** tagged, on a **different** topic, must still allow the publish. Block-everything would be safe-but-useless; this must preserve the genuine-no-conflict path.

Add/extend a feature test (`detectConflicts` or the publish endpoint) covering all four cases so the fail-open can't regress.

## Definition of done
The three-case trace shows the untagged-conflict case now **BLOCKs** and the tagged-different-topic case still **ALLOWs**; the feature test covers all four; docs updated — `architecture.md` (the Sprint-4 conflict-fence description: note it is **topic-additive / fail-closed**, blocking on an untagged-but-governing convenio, pending the Sprint-7 semantic evolution) and `correction-01.md` (this file, with the proof table). Cursor writes the proof into the correction doc and **stops — no commit until Pedram reviews + eyes-on**.

---

## ✅ Result (built + proven) — fail-closed, tightening preserved

**Change shipped** (`EscalationService::detectConflicts`, `hr-backend/app/Services/EscalationService.php`): the `topicId` branch is now additive — a governing convenio blocks when it **shares the ruling's topic OR carries no topic tags at all**:

```php
->when($topicId !== null, fn ($q) => $q->where(function ($w) use ($topicId) {
    // Shares the ruling's topic, OR has no topic tags at all
    // (untagged-but-governing convenio → still blocks; fail-closed).
    $w->whereHas('topics', fn ($t) => $t->where('topics.id', $topicId))
        ->orWhereDoesntHave('topics');
}))
```

The block message is now accurate for each match type: when the match was via `orWhereDoesntHave` (untagged convenio) the `publish_blocked` note reads *"an active official convenio governs this scope (untagged for this topic) … (topic-additive fail-closed block, Correction-01)"* rather than claiming a same-topic conflict. The no-topic path is unchanged.

### Live four-case trace against the FIXED code (real DB, conv-22, ruling topic = `vacaciones`/2)
Run through the actual Eloquent `detectConflicts` via reflection; tagging cases wrapped in `DB::beginTransaction()/rollBack()` — **nothing persisted** (verified: conv-22 tag count `0` afterwards). Reality confirmed first: conv-22's active `official_convenio` docs `95/97/98` each have **0** `document_topics` rows; only **2** docs carry any tag corpus-wide.

| Case | Inputs | Conflicting docs | Result |
|---|---|---|---|
| 1. No-topic | conv 22, `topicId=null` | `95,97,98` | **BLOCK** (unchanged) |
| 2. Topic, untagged convenio (reality) | conv 22, `topicId=2`, docs untagged | `95,97,98` | **BLOCK** ✅ — **was ALLOW pre-fix** |
| 3. Topic, convenio tagged **same** topic | conv 22, `topicId=2`, doc 95 tagged topic 2 (rolled-back tx) | `95,97,98` | **BLOCK** |
| 4. Topic, **all** gov docs tagged **different** topic | conv 22, `topicId=2`, docs 95/97/98 tagged topic 1 `jornada` (rolled-back tx) | `—` (0) | **ALLOW** ✅ — genuine no-conflict, proves precise tightening |

> **Note on case 4 (important for live data):** convenio 22 has *three* governing docs in scope. The genuine-ALLOW path only holds when **every** governing doc is tagged off-topic — if any sibling is still untagged, `orWhereDoesntHave` correctly keeps the block. Observed directly:
>
> | Extra case | Inputs | Conflicting docs | Result |
> |---|---|---|---|
> | 4b. Only **one** gov doc tagged different topic | conv 22, `topicId=2`, **only** doc 95 tagged topic 1; 97/98 still untagged | `97,98` | **BLOCK** — siblings keep it fail-closed |
>
> This is the fence working as intended: the ALLOW in case 4 is a *real* no-conflict (the whole governing scope is tagged elsewhere), not a tag-coverage accident. The feature test isolates a single governing doc per case to assert the branch directly.

### Feature test (regression guard)
`hr-backend/tests/Feature/Sprint5Correction01FenceTest.php` — four cases, one governing doc, `RefreshDatabase` isolation (tagging rolled back per test):

```
1) no-topic                            → BLOCK   (test_case1_no_topic_blocks_scope_only)
2) topic + untagged governing convenio → BLOCK   (test_case2_topic_with_untagged_governing_convenio_blocks)  ← the fix
3) topic + convenio tagged same topic  → BLOCK   (test_case3_topic_with_convenio_tagged_same_topic_blocks)
4) topic + convenio tagged other topic → ALLOW   (test_case4_topic_with_convenio_tagged_different_topic_allows)
```

`php artisan test` → **20 passed, 55 assertions** (full suite, no regression); the four Correction-01 cases pass with 5 assertions.

### Touched (tight scope, as specified)
- `hr-backend/app/Services/EscalationService.php` — `detectConflicts` query + accurate `publish_blocked` note + docblock/fence comment. No schema, no answer-loop, no force-tag-at-publish, no other publish-path change. `hr-ai` untouched.
- `hr-backend/tests/Feature/Sprint5Correction01FenceTest.php` — new (regression guard).
- `hr-docs/architecture/architecture.md` — conflict-fence note updated to *topic-additive / fail-closed*.

---

## Cursor build prompt (paste after this spec is in the thread)

In the `hr-platform` workspace. This is **Sprint 5 — Correction-01**, a fix to the **load-bearing legal fence** (the publish no-silent-override guard). Read `hr-docs/sprints/sprint-05/correction-01.md` first. Maximum care, tight scope.

**Build:**
1. In `hr-backend/app/Services/EscalationService.php::detectConflicts`, change the `topicId` branch so topic is **additive** to the scope block, not a replacement: block when there's any active `official_convenio` in the convenio scope **AND (it shares the ruling's topic OR it has no `document_topics` rows)**. Use the nested `where(function($w){ $w->whereHas('topics', …topics.id=$topicId)->orWhereDoesntHave('topics'); })` shape. The no-topic path (`topicId === null`) is unchanged.
2. If the block/re-escalate message names the conflict type, make it accurate for the untagged-match case (a scope-level conflict, not a same-topic one).
3. Add/extend a feature test covering all **four** cases in the proof table (no-topic→block; topic+untagged-convenio→**block**; topic+convenio-tagged-same→block; topic+convenio-tagged-different→**allow**), using rolled-back transactions for the tagging cases so nothing persists.

**Then re-run the three/four-case trace** against the fixed code and write the result table into `correction-01.md`.

**Hard constraints:**
- Touch **only** `detectConflicts` (+ the message label if needed) + the test. No schema change, no other publish-path change, no answer-loop change, no change to the force-tag-at-publish step, `hr-ai` untouched.
- The fix must **fail closed** (an untagged-but-governing convenio blocks) and must **still allow** a genuine no-conflict (a convenio tagged on a *different* topic) — prove both.
- Update `architecture.md`'s conflict-fence note to "topic-additive / fail-closed (blocks an untagged-but-governing convenio), pending the Sprint-7 semantic evolution."

Persist no published ruling. After writing the proof into `correction-01.md`, **stop — do not commit.**
