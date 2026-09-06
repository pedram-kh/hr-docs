# Sprint 7d — Plan: semantic conflict detection + fact version resolution + AI succession-proposal

> Location: `hr-docs/sprints/sprint-07d/plan.md`
> Spec: `sprint-07d-spec.md` · Roadmap: `roadmap.md:145-148` · Reviewer: Claude (architecture) · eyes-on: Pedram
> **Status: plan only — no code written this turn.** Every claim below is cited to a real line in the current tree.

**The thesis in one line.** 7d is one mechanism (embed two pieces of text, rank by cosine inside a scope, show a human the passages) pointed at three places: the publish fence (A, a safety gate that may only get stricter), the flagged fact-duplicate queue (B, housekeeping), and the expiry queue (C, growth). The mechanism already exists in `hr-ai`; 7d adds the callers, the surfaces, and the human decisions — and nothing else.

---

## 1. What exists (reality check)

### 1.1 The Sprint-4 publish fence — the exact code 7d extends

`EscalationService::resolve` is the publish path. The order of operations is load-bearing for 7d, so here it is verbatim in sequence:

1. **Convert-by-reason policy** (Sprint 6 / ADR-0019, restrict-only) — fires first, before any document exists:

```151:155:hr-backend/app/Services/EscalationService.php
        if (! $this->policy->canConvertReason($card->reason)) {
            $this->log($card, 'convert_blocked', $card->reason, null, $actor, 'convert blocked by guardrails policy — reason not in the convert-by-reason allow-set');

            return ['outcome' => 'convert_blocked', 'reason' => $card->reason, 'allowed' => $this->policy->convertAllowedReasons()];
        }
```

2. **The draft ruling is created/reused** (`retrieval_status = 'draft'`, `storage_path = ''`, `authority_level = 'internal_hr_ruling'`) — `EscalationService.php:176-245`. Critically, the draft is **not embedded** at this point: `storage_path` is empty and no `document_chunks` row exists.

3. **The no-override fence** — the interim topic-additive/fail-closed block:

```254:255:hr-backend/app/Services/EscalationService.php
        $conflicts = $this->detectConflicts($convenioId, $topicId);
        if ($conflicts->isNotEmpty()) {
```

```344:360:hr-backend/app/Services/EscalationService.php
    private function detectConflicts(int $convenioId, ?int $topicId)
    {
        return Document::query()
            ->where('convenio_id', $convenioId)
            ->where('authority_level', 'official_convenio')
            ->where('retrieval_status', 'active')
            ->when(
                $topicId !== null,
                fn ($q) => $q->where(function ($w) use ($topicId) {
                    // Shares the ruling's topic, OR has no topic tags at all
                    // (untagged-but-governing convenio → still blocks; fail-closed).
                    $w->whereHas('topics', fn ($t) => $t->where('topics.id', $topicId))
                        ->orWhereDoesntHave('topics');
                }),
            )
            ->get(['id', 'uuid', 'title']);
    }
```

The Sprint-5 Correction-01 shape is the `orWhereDoesntHave('topics')` at `:356-357` — an untagged-but-governing convenio still blocks. The candidate universe is exactly `convenio_id` + `authority_level = 'official_convenio'` + `retrieval_status = 'active'` (`:347-349`).

4. **On block:** an open `conflict` review task on the draft, an accurate-reason `publish_blocked` event, and the card returns to In Progress:

```262:288:hr-backend/app/Services/EscalationService.php
                DocumentReviewTask::firstOrCreate(
                    ['document_id' => $document->id, 'type' => 'conflict', 'status' => 'open'],
                    [
                        'reason' => 'conflict',
                        'raw_unmatched_values' => $conflicts->map(fn ($d) => ['facet' => 'authority', 'value' => $d->uuid])->all(),
                    ],
                );

                // Accurate reason: a true same-topic conflict vs. a scope-level
                // block on an untagged-but-governing convenio (Correction-01).
                if ($topicId === null) {
                    $note = 'no topic assigned — scope-only conflict fence blocked publish (an official convenio is active in scope)';
                } elseif (DocumentTopic::whereIn('document_id', $conflicts->pluck('id'))->where('topic_id', $topicId)->exists()) {
                    $note = 'official convenio governs this topic in the asker\'s scope — internal ruling cannot override it';
                } else {
                    $note = 'an active official convenio governs this scope (untagged for this topic) — internal ruling cannot override it (topic-additive fail-closed block, Correction-01)';
                }
                $this->log($card, 'publish_blocked', null, $conflicts->pluck('uuid')->implode(','), $actor, $note);

                // Route to a human: the card returns to in_progress (NOT resolved).
                if ($card->status !== 'in_progress') {
                    $old = $card->status;
                    $card->status = 'in_progress';
                    $card->resolved_at = null;
                    $card->save();
                    $this->log($card, 'status_change', $old, 'in_progress', $actor, 're-opened: publish blocked by no-override fence');
                }
```

5. **Only if the fence is clean** does the artifact get rendered and embedded — `$this->publisher->publish(...)` at `EscalationService.php:299`, which is `RulingPublisher::publish` (render PDF → S3 → `/extract` → `/embed` at `RulingPublisher.php:61`) → then `retrieval_status` flips to `active` (`EscalationService.php:302`).

**Two consequences that shape (A):**

- **The ruling has no chunks at fence time.** The fence runs at `:254`, `/embed` runs at `:299`. So the semantic pass cannot "reuse the ruling's freshly-embedded chunks" (the spec's optimistic phrasing) — it must **embed `resolution_text` as a query**. That is exactly what `/retrieve` already does with its `query` field.
- **The semantic pass must be a second, independent check placed after `detectConflicts`**, and it only ever *adds* a block. Because `detectConflicts` short-circuits (`isNotEmpty()` → return `publish_blocked`), a semantic pass added after it is structurally `existing OR semantic`; it is not possible for it to un-block anything.

**Surfacing.** `EscalationController::resolve` maps the outcome to a 409 with a machine-readable `code`:

```255:261:hr-backend/app/Http/Controllers/Admin/EscalationController.php
        if (($result['outcome'] ?? null) === 'publish_blocked') {
            return response()->json([
                'code' => 'publish_blocked',
                'message' => 'No se puede publicar: existe un convenio oficial vigente para este ámbito y tema. '
                    .'Una resolución interna no puede prevalecer sobre el convenio — se ha devuelto la tarjeta a una persona.',
                'conflicts' => $result['conflicts'],
            ], 409);
        }
```

`resolution_text` is capped at 20 000 chars (`EscalationController.php:206`) — relevant to (A) §2.3. Writes are gated `ability:escalation.work` (`routes/api.php:196-200`).

**The doc, and the existing test.** `architecture.md:191-198` (§8.3) describes the fence as *"the interim shape pending the Sprint-7 semantic-similarity evolution"*; `architecture.md:204-206` (§8.5) records the Q-F boundary. `Sprint5Correction01FenceTest` already locks four fence cases by calling the private `detectConflicts` through reflection (`tests/Feature/Sprint5Correction01FenceTest.php:75-83`), cases at `:94-134`:

| # | Input | Expected today |
|---|---|---|
| 1 | no topic | BLOCK (scope-only) |
| 2 | topic + untagged governing convenio | BLOCK (Correction-01) |
| 3 | topic + convenio tagged same topic | BLOCK |
| 4 | topic + convenio tagged **different** topic | **ALLOW** |

Case 4 is the hole 7d closes: the old fence allows it, and it is precisely where a semantic pass can legitimately add a block.

### 1.2 The expiry queue + the lineage write-side (7a)

There is **no dedicated expiry table**. The queue is `document_review_tasks` rows with `type = 'expiry'`:

```35:51:hr-backend/app/Console/Commands/ReviewsScanExpiry.php
    // Prose authority types (salary tables are not prose; they are not chunked).
    private const PROSE_TYPES = ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling'];

    public function handle(): int
    {
        $leadDays = (int) $this->option('days');
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($leadDays);

        $documents = Document::query()
            ->with('documentType')
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::PROSE_TYPES))
            ->where('retrieval_status', 'active')
            ->whereNotNull('validity_end')
            ->where('validity_end', '<=', $horizon) // within the lead window OR already past
            ->orderBy('validity_end')
            ->get();
```

The command writes an idempotent `open` task with `reason = null`, `due_date = validity_end` (`:70-80`) and explicitly never touches status or lineage (`:25-27`).

Table shape (the only two migrations that touch it):

```11:19:hr-backend/database/migrations/2026_06_20_131024_create_document_review_tasks_table.php
        Schema::create('document_review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->enum('type', ['expiry', 'tag_review', 'conflict']);
            $table->enum('status', ['open', 'resolved', 'dismissed'])->default('open');
            $table->date('due_date')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
```

```24:27:hr-backend/database/migrations/2026_06_21_100005_add_reason_and_raw_value_to_document_review_tasks.php
        Schema::table('document_review_tasks', function (Blueprint $table) {
            $table->enum('reason', ['unresolved', 'conflict'])->nullable()->after('type');
            $table->jsonb('raw_unmatched_values')->nullable()->after('reason');
        });
```

Note `type` and `reason` are Laravel `enum` → Postgres CHECK. Adding a value requires a CHECK drop-and-re-add (7b-2 had to do exactly that for `reference_facts.status`, `2026_06_26_120002_...php:42-46` + `:125-129`). **7d avoids that** — see §6.

**The write-side.** `ReviewQueueController::resolveExpiry` is the sole writer of `predecessor_document_id` in the whole backend:

```171:185:hr-backend/app/Http/Controllers/Admin/ReviewQueueController.php
        DB::transaction(function () use ($successor, $predecessor, $retire, $adminId, $task) {
            // Write the stub: the successor names the predecessor it supersedes.
            $successor->predecessor_document_id = $predecessor->id;
            $successor->save();

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $successor->id,
                'facet' => 'predecessor',
                'old_value' => null,
                'new_value' => (string) $predecessor->uuid,
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => "succeeds document {$predecessor->uuid} (same convenio)",
            ]);
```

Same-convenio is enforced with a 422 (`:146-158`); retirement is opt-in and additionally 409-gated on `confirm_scope_change` (`:160-168`, applied at `:187-203`); the same-convenio candidate list is already computed on the read side (`:44-53`). `Sprint7aTagProposalInvariantTest:296-302` pins *"predecessor is NEVER auto-retired."* Routes: read open, write `ability:knowledge.edit` (`routes/api.php:136-146`).

**The propose-pattern to mirror** is `ProposeDocumentTags` / `SegmentReferenceSource`: `ShouldQueue`, `$tries = 1`, an int id in the constructor, a defensive type guard, and a `try/catch` that logs and **never rethrows** (`app/Jobs/ProposeDocumentTags.php:34-58`, `app/Jobs/SegmentReferenceSource.php:29-59`). ADR-0020's spine: the AI stays inert, writes only `ai_agent` provenance, never the authoritative FK columns (`TagProposalService.php:25-36`).

### 1.3 The duplicate flag (7b-2) and the version trap

The flag is set only during AI segmentation persist, and only on an **exact** scope-key collision with a differing value:

```180:189:hr-backend/app/Services/ReferenceFactProposalService.php
                $dupe = $this->findScopeDuplicate($fact);
                if ($dupe !== null) {
                    $fact->duplicate_of_id = $dupe->id;
                    if ($fact->uncertainty === null) {
                        $fact->uncertainty = ['field' => 'version', 'reason' => "posible versión de #{$dupe->id} (mismo ámbito, valor distinto)"];
                    }
                    $fact->save();
                    $duplicatesFlagged++;
                    $this->logEvent($fact->id, 'duplicate', null, (string) $dupe->id, 'AI flagged possible version/duplicate (signal only — resolution is 7d)');
                }
```

```237:259:hr-backend/app/Services/ReferenceFactProposalService.php
    private function findScopeDuplicate(ReferenceFact $fact): ?ReferenceFact
    {
        $candidates = ReferenceFact::query()
            ->where('id', '!=', $fact->id)
            ->where('status', '!=', 'rejected')
            ->where('convenio_id', $fact->convenio_id)
            ->when($fact->topic_id === null, fn ($q) => $q->whereNull('topic_id'), fn ($q) => $q->where('topic_id', $fact->topic_id))
            ->when($fact->job_category_id === null, fn ($q) => $q->whereNull('job_category_id'), fn ($q) => $q->where('job_category_id', $fact->job_category_id))
            ->orderByDesc('id')
            ->get();

        $thisGroup = $this->normalizeGroup($fact->group_label);
        foreach ($candidates as $c) {
            if ($this->normalizeGroup($c->group_label) !== $thisGroup) {
                continue; // a different group is a different fact, not a duplicate
            }
            if (trim((string) $c->value) !== trim((string) $fact->value)) {
                return $c; // same scope, different value → a likely version
            }
        }

        return null;
    }
```

`normalizeGroup` is trim/collapse/case-fold only (`:262-269`). That is the exact cause of the documented miss:

> **Version trap — ⚠ PARTIAL.** File 2's Navarra Intervención Social **G2 = 4 meses** is bound to the correct convenio (`31101815012021`) but is **NOT** flagged as a version of file 1's *6 meses* — because file 1 carried that value under the compound group *"Grupos 1 y 2"*, and the duplicate detector keys on exact `group_label`, so a re-split version slips the check.
> — `hr-docs/sprints/sprint-07b-2/review.md:168`

Schema: `group_label` and `duplicate_of_id` were added in `2026_06_26_120002_add_segmentation_fields_to_reference_facts.php:53` and `:72-73`; `status` is `needs_review | verified | rejected` (`:42-46`); `duplicateOf()` is a self `belongsTo` documented as *"A SIGNAL for the human, never a resolution … that is Sprint 7d"* (`app/Models/ReferenceFact.php:95-104`). There is **no supersede / validity-closing code for facts anywhere** — confirmed absent; 7d writes the first.

The queue surface already renders the flag as a badge but offers no action:

```92:95:hr-frontend/src/pages/admin/ReviewQueuePage.tsx
                <td className="flags">
                  {r.is_ai_proposed && <span className="ai-pill">AI</span>}
                  {r.uncertainty && <span className="badge badge-conflict">⚠ {r.uncertainty.field}</span>}
                  {r.is_possible_duplicate && <span className="badge badge-conflict">≈ version</span>}
                </td>
```

### 1.4 The 7c answer-side rule (must stay untouched)

```161:190:hr-backend/app/Services/ReferenceFactAnswerService.php
    /**
     * The two-verified-match safe rule: prefer the most-recent validity_start (a
     * newer version supersedes; null = oldest/unknown). If the most-recent group
     * still holds >1 fact with DIFFERING values → ambiguous, escalate (7d
     * resolves). Returns [fact|null, selection].
```

A same-validity differing-value pair escalates (`:140`, `:186-187`). 7d does not change this branch; it gives the human the tool to close the older fact's validity so the pair stops being same-validity — **resolution reaches chat through corrected data only.** The golden-trace gate that must stay green is `tests/Feature/Sprint7cAdditivityRegressionTest.php:114` (prose turn byte-for-byte) and `:154` (salary turn byte-for-byte).

### 1.5 Which hr-ai primitive serves the comparison

| primitive | embeds arbitrary text? | scope filter | authority filter | read-only | verdict for 7d |
|---|---|---|---|---|---|
| `POST /retrieve` (`hr-ai/app/main.py:347-373`) | yes (`query`, `main.py:90-96`) | `convenio_id`, `retrieval_status`, `as_of_date` | **no** | yes | close, but see the fail-open below |
| `POST /sandbox-retrieve` (`main.py:376-393`) | yes | `document_id` only | n/a (one doc) | yes | usable per-document; N calls |
| `POST /embed` (`main.py:294-309`) | n/a | n/a | n/a | **writes** `document_chunks` (`chunks_db.py:30-77`) | not a comparison primitive |

The ranking core is exact and full-recall by construction — the planner is forced flat so the scope filter is applied before ranking and the top-k is exact:

```98:118:hr-ai/app/chunks_db.py
            await conn.execute("SET LOCAL enable_indexscan = off")
            await conn.execute("SET LOCAL enable_bitmapscan = off")
            rows = await conn.fetch(
                """
                SELECT id, document_id, chunk_index, page_from, page_to, content,
                       retrieval_status, authority_level, convenio_id,
                       (embedding <=> $1::vector) AS distance
                FROM document_chunks
                WHERE ( ($2::bigint IS NOT NULL AND convenio_id = $2)
                        OR ($3::boolean AND authority_level = 'national_law') )
                  AND retrieval_status = ANY($4::varchar[])
                  AND ($5::date IS NULL OR validity_start IS NULL OR validity_start <= $5::date)
                  AND ($5::date IS NULL OR validity_end IS NULL OR validity_end >= $5::date)
                ORDER BY embedding <=> $1::vector
                LIMIT $6
                """,
```

Scores are cosine similarity on unit vectors (`normalize_embeddings=True`, `hr-ai/app/embeddings.py:40-51`; BGE-M3/1024-dim, `hr-ai/app/config.py:36-41`), returned as `score = round(1.0 - distance, 6)` (`main.py:369-370`) — so a threshold in `[0,1]` is directly comparable to `hr.retrieval_score_floor` (`config/hr.php:20`). There is no query/passage prefix convention to respect (`embeddings.py:54-55`).

**The decisive gap: `/retrieve` has no `authority_level` filter.** It returns the top-k across *all* authority levels in the convenio, and `authority_level` is only present as an output field. Filtering client-side **after** top-k is a fail-open in a safety gate: a convenio that also has three published `internal_hr_ruling` documents can crowd the single overlapping `official_convenio` chunk out of the top-k, and the fence would see "no overlap." That is precisely the failure mode the sprint forbids.

**Recommendation — one additive read-only endpoint, `POST /compare-scope`.** It is the only option where the authority filter is *in the SQL*, which makes the block decision **k-independent**: the highest-scoring eligible chunk is rank 1 of an exactly-filtered, exactly-ordered set, so no `k` choice can hide it. Shape (mirroring `/sandbox-retrieve`'s additive posture verbatim):

```
POST /compare-scope           # read-only, SELECT-only, no LLM, no write, no migration
{ texts: [str, ...],          # 1..N probes (the ruling's paragraphs, or a doc's chunk texts)
  convenio_id: int,
  authority_levels: [str],    # e.g. ["official_convenio"] — applied in the WHERE
  retrieval_status: [str] = ["active"],
  exclude_document_ids: [int] = [],
  as_of_date: date|null,
  k: int = 10 }
→ { matches: [ { text_index, chunks: [{id, document_id, chunk_index, page_from, page_to,
                                      content, authority_level, score}], eligible_total } ],
    max_score: float }
```

It reuses `embed_texts` (`embeddings.py:40-51`) and the `chunks_db.retrieve` SQL body with two added predicates (`authority_level = ANY($n)`, `document_id <> ALL($n)`), inside the same forced-flat-scan transaction. hr-ai still writes nothing and still never migrates (`hr-ai/AGENTS.md:10-11`).

**Fallback if the reviewer prefers hr-ai byte-for-byte untouched:** loop `/sandbox-retrieve` over the active `official_convenio` documents the fence already enumerates (`detectConflicts` returns exactly that set). It is correct and needs zero hr-ai change, at the cost of `probes × documents` HTTP calls, each re-embedding the same probe. Recorded as the documented fallback, not the recommendation.

### 1.6 Reuse vs new — the honest ledger

**Reuse, unchanged:** the `<=>` ranking + forced flat scan + `embed_texts`; `ExtractionClient` as the only hr-ai caller (`retrieve` at `:142-154`, `sandboxRetrieve` at `:163-179`); `detectConflicts` (called, not edited); the 409 `code` envelope + `ApiError.body` plumbing (`hr-frontend/src/lib/api.ts:86-97`); `document_review_tasks` (`type='expiry'` for C, `type='conflict'` for the §8.5 flag); `ReviewQueueController::resolveExpiry` as the confirm write-side; the queued-propose-job pattern; append-only `tag_events` / `escalation_events`; `duplicate_of_id` + `uncertainty`; `EnsureCan` (`app/Http/Middleware/EnsureCan.php:20-31`); the design tokens (`.notice`, `.notice--ai`, `.badge-conflict`, `.well`, `.checkbox`, `.modal`, `.fact-create-grid`, `--provenance-ai`).

**New:** one hr-ai read-only endpoint; three hr-backend services (`SemanticFenceService`, `FactResolutionService`, `SuccessionProposalService`) + one queued job + two artisan commands (calibration, reverse-recheck scan); three additive migrations; three frontend surfaces; two test classes; ADR-0024.

---

## 2. (A) The semantic fence — additive, fail-toward-caution

### 2.1 Where it runs

Inside `EscalationService::resolve`, **immediately after** the existing check, in the branch where the existing check found nothing. Sketch (structure, not final code):

```php
$conflicts = $this->detectConflicts($convenioId, $topicId);   // unchanged, line 254
$semantic  = $conflicts->isEmpty()
    ? $this->fence->compare($document, $convenioId, $resolutionText)  // new, read-only
    : null;                                                            // already blocked; no network call

if ($conflicts->isNotEmpty() || $semantic?->blocks()) { … block … }
```

Three properties fall out **by construction**, before any test is written:

1. `detectConflicts` is not edited, not narrowed, not conditioned. Its result is still the first term of the disjunction.
2. The semantic pass is only *consulted* when the existing check is empty, so it can only turn ALLOW into BLOCK — never BLOCK into ALLOW.
3. On the common path (an untagged governing convenio → the Correction-01 block, which is most of the real corpus per `roadmap.md:148`) there is **no added latency**: the existing SQL short-circuits before the network call.

### 2.2 The candidate set — deliberately wider than the topic-narrowed one

The semantic pass ignores topics entirely. Candidates = active `official_convenio` chunks in the asker's `convenio_id` — i.e. the *unnarrowed* superset of `detectConflicts`'s candidates. That is what lets it catch Sprint-5 case 4 (convenio tagged on a different topic → old fence allows) while remaining incapable of shrinking the old block.

- `national_law` is fetched **informationally only** (a separate `authority_levels: ["national_law"]` call, or omitted entirely in v1) and can **never** contribute to a block or to the acknowledgement requirement. The Estatuto is the universal baseline, not a same-scope override target; blocking on it would block essentially every ruling.
- `exclude_document_ids` carries the draft's own id. It has no chunks today (§1.1), so this is belt-and-braces against a future reorder of publish/fence.
- `retrieval_status: ["active"]` and `as_of_date = today` mirror what the answer loop considers governing.

### 2.3 What is embedded — multi-probe, because truncation is a fail-open

`resolution_text` may be up to 20 000 chars (`EscalationController.php:206`). `embed_texts` calls `SentenceTransformer.encode` with no explicit `max_seq_length` handling (`hr-ai/app/embeddings.py:44-49`), so a long ruling is **silently truncated** and its tail is never compared — a silent fail-open in a safety gate.

Mitigation, and it happens to also make the fence stricter: hr-backend splits `resolution_text` into paragraph-sized probes (blank-line split, merged to ~120–600 chars, hard cap `semantic_probe_max` probes) and the fence takes **`max` over all probes × all chunks**. Any single probe above threshold blocks. A one-paragraph ruling degenerates to exactly one probe, i.e. the simple case is unchanged.

### 2.4 The two bands

| band | condition on `max_score` | outcome |
|---|---|---|
| **block** | `≥ hr.semantic_conflict_threshold` | 409 `publish_blocked`, `reason = semantic_overlap`; draft stays draft; open `conflict` task; card → In Progress; overlapping passages returned |
| **acknowledge** | `≥ hr.semantic_review_band` and `< hr.semantic_conflict_threshold` | 409 `publish_requires_acknowledgement`; passages returned; publish proceeds **only** on a re-POST with `acknowledge_semantic_overlap = true`, which records `publish_acknowledged_overlap` |
| **pass** | `< hr.semantic_review_band` | proceeds as today |
| **comparison unavailable** | hr-ai error/timeout | treated as the **acknowledge** band with `reason = semantic_compare_unavailable` — never a silent pass, and never a hard publish outage (see §7 Q4) |

The acknowledgement re-POST reuses the existing double-submit shape exactly: `confirm_scope_change` already works this way (`EscalationController.php:217-222`) and the frontend already has the modal for it (`EscalationCardDrawer.tsx:415-437`).

### 2.5 Config — conservative, and the raise-only direction is **inverted**

New keys in `config/hr.php`, alongside the existing floors (`config/hr.php:20`, `:43`, `:56`):

```php
'semantic_conflict_threshold' => (float) env('HR_SEMANTIC_CONFLICT_THRESHOLD', 0.82),  // block at/above
'semantic_review_band'        => (float) env('HR_SEMANTIC_REVIEW_BAND',        0.70),  // acknowledge at/above
'semantic_compare_k'          => (int)   env('HR_SEMANTIC_COMPARE_K',            10),  // passages shown, not a gate
'semantic_probe_max'          => (int)   env('HR_SEMANTIC_PROBE_MAX',            12),
```

**The values above are placeholders and must not be shipped unmeasured** — see the calibration command in §7 Q1. They are stated so the shape is reviewable, not because 0.82 means anything yet.

⚠ **The non-obvious thing the real code makes visible.** ADR-0019's raise-only mechanism is `max(config_floor, admin)` (`GuardrailPolicy.php:151`, doc at `:14-25`) because for every existing knob **higher = more caution**. For a similarity threshold that *triggers a block*, the direction is reversed: **lowering** it blocks more. Wiring these keys into the Sprint-6 `guardrail_configs` read-model via `maxFloor` would let an admin *loosen the fence* while the code comment claims it can only tighten.

**Therefore: 7d does not expose these in the guardrails UI.** They are code config with conservative defaults, and the spec's "raise-only" intent is honoured as **"block-more-only"** — recorded in ADR-0024 as: *any future admin exposure of a block-triggering similarity threshold must be `min(baseline, admin)`, not `max`.* Exposure itself is a Sprint-6-family follow-up, not 7d.

### 2.6 What the event records

`escalation_events.type` is a **free `string`** (`2026_06_24_100002_create_escalation_events_table.php:24-25`) — no migration is needed for the new types `publish_blocked` (reason refined) and `publish_acknowledged_overlap`. But the table has only `old_value` / `new_value` / `note` text columns, so chunk ids + scores would have to be stuffed into prose. One additive nullable `jsonb detail` column makes the audit machine-readable and greppable:

```json
{ "reason": "semantic_overlap",
  "max_score": 0.871,
  "threshold": 0.82, "review_band": 0.70,
  "probe_count": 4,
  "matches": [ {"chunk_id": 41207, "document_id": 88, "document_uuid": "…",
                "chunk_index": 12, "page_from": 9, "score": 0.871, "probe_index": 2} ] }
```

Append-only is preserved (insert-only, no UPDATE of history) — the same posture as `tag_events` (`architecture.md:179`).

### 2.7 Proving `fence = existing_block OR semantic_block`

New `tests/Feature/Sprint7dFenceNeverOpensTest.php`, with the semantic comparison injected as a fake (the endpoint is called through `ExtractionClient`, so a bound test double needs no network):

1. **Regression, the load-bearing test.** Re-run all four `Sprint5Correction01FenceTest` cases through the *new* combined fence with the semantic fake forced to `max_score = 0.0` ("semantic sees nothing"). Cases 1–3 must still BLOCK, case 4 must still ALLOW. This is the literal statement of *"every publish the old fence blocked, the new fence still blocks"* — the old block cannot depend on the semantic result.
2. **Semantic adds.** Case 4 (convenio tagged on a different topic → old fence ALLOWS) with the fake at `0.95` → **BLOCK** with `reason = semantic_overlap`, and `escalation_events.detail.matches` non-empty.
3. **The disjunction matrix.** For the four combinations of `existing ∈ {block, allow}` × `semantic ∈ {block, allow}`, assert `blocked == (existing || semantic)`. This is the algebraic claim, tested directly rather than inferred.
4. **The band.** Fake at a value strictly between the two thresholds → 409 `publish_requires_acknowledgement`, draft still `draft`, `retrieval_status` unchanged, zero chunks written; re-POST with `acknowledge_semantic_overlap = true` → publishes and records `publish_acknowledged_overlap` with the scores.
5. **Never silent-pass on failure.** Fake throws → outcome is the acknowledge path with `semantic_compare_unavailable`, never a clean publish.
6. **Threshold monotonicity.** Lowering `semantic_conflict_threshold` may only turn ALLOW into BLOCK; raising it may never un-block a case the *existing* fence blocked (guards against someone later "optimising" the short-circuit away).
7. **No short-circuit cost.** When `detectConflicts` is non-empty the compare fake is asserted **never called** (locks the ordering, so a refactor can't start paying network latency on the hot blocked path).

### 2.8 The §8.5 reverse re-check — flag-only minimum bar

When an `official_convenio` becomes active in a scope that has published `internal_hr_ruling` documents, run the same comparison **in reverse** (probes = the new convenio's chunk texts; candidates = that convenio's active `internal_hr_ruling` chunks) and, above `semantic_review_band`, **flag**:

- a `document_review_tasks` row on the **ruling**, `type = 'conflict'`, `reason = 'conflict'`, with the matched chunk ids/scores in the existing `raw_unmatched_values` jsonb plus a `"kind": "semantic_reverse_recheck"` discriminator — **zero migration**, and it reuses the very queue the forward fence already writes into (`EscalationService.php:262-267`);
- **never** a `retrieval_status` change, never a demotion, never a retrieval touch. 2b precedence stays frozen (`architecture.md:206`).

Triggers: a queued job dispatched after an `official_convenio` ingest/lifecycle-activation, plus `php artisan rulings:scan-semantic-conflicts` for the backfill (mirroring `reviews:scan-expiry`'s idempotent-upsert shape).

**Explicitly the first deferral candidate.** If (A) core + (B) + (C) consume the sprint, this ships as the artisan command only (no ingest hook, no UI beyond the existing conflict-task row) — or slips whole. Its absence leaves today's documented boundary exactly as documented, so deferring it regresses nothing.

---

## 3. (B) Fact resolution

### 3.1 The side-by-side surface

In the Reference-facts tab, every fact with `duplicate_of_id != null` gets a **pair view** rendering `fact` and `fact.duplicateOf` in two columns — reusing `.fact-create-grid` (`hr-frontend/src/index.css:578-582`, already a `1fr 1fr` grid) inside `.panel--wide` (`:573-576`), with `.well` (`:295-300`) for each `source_excerpt` and `.badge-conflict` for the differing fields. Per side: convenio (+ derived territory/sector), `group_label`, `job_category`, `topic`, `value` + `raw_values`, `validity_start`/`validity_end`, `status`, `source`, `source_document` link + `source_locator`, `confidence`, `uncertainty`. Differing fields are highlighted; identical fields are muted — the human's job is to see *what* differs.

Reads stay open; the three actions are gated `ability:knowledge.edit`, matching the existing fact write routes (`routes/api.php:169-178`).

### 3.2 The three actions — `POST /admin/reference-facts/{uuid}/resolve-duplicate`

Body: `{ action: 'supersede'|'coexist'|'reject', ... }`. All three run in one transaction and write append-only `tag_events` (`entity_type = 'reference_fact'`, `source = 'admin_manual'`, `actor_id` = the admin) — no schema change needed for the events themselves, since `entity_type` and `facet` are free strings (`2026_06_20_131023_create_tag_events_table.php:14-28`).

**`supersede`** (`newer`, `older` explicit in the body — never inferred, because `validity_start` may be null on either side):
- guard: both facts share `convenio_id` + `topic_id`; `newer.validity_start` is non-null; `newer.validity_start > older.validity_start` (or `older.validity_start` is null). Otherwise **422** — the direction of a supersede is never guessed.
- `older.validity_end = newer.validity_start − 1 day`, **only if** currently null or later than that date (closing may never *extend* a window). Idempotent.
- `older.superseded_by_id = newer.id`; `older.resolution = 'superseded'`; `newer.resolution = 'supersedes'`; `resolved_by` / `resolved_at` on both.
- `newer.duplicate_of_id` retained as the lineage link (it already points at `older`); the flag is *resolved*, not erased.
- `older.status` stays `verified` for its window. **Nothing is deleted, ever.** A 2025 question still gets the 2025 value — that is the point.
- events: on `older`, `facet = 'validity_end'` with `old_value`/`new_value` = the dates; on both, `facet = 'resolution'`.

**`coexist`** — both genuinely apply (different sub-scopes). `resolution = 'coexists'` on both, `resolved_by`/`resolved_at` set, `duplicate_of_id` retained as history, and the pair leaves the unresolved-duplicate queue. Validity untouched. Event `facet = 'resolution'`, `new_value = 'coexists'`, with the human's note.

**`reject`** — the duplicate is wrong. Reuses the **existing** `rejected` status and its route semantics (`ReferenceFactController::reject`, `:232-251`); `resolution = 'rejected_duplicate'`. No validity change, no delete.

### 3.3 The extended duplicate pass — deterministic, and it closes the documented miss

**Recommendation: deterministic normalized-token overlap. No embedding for (B).** Reasoning from the actual failure: `findScopeDuplicate` requires `normalizeGroup(a) === normalizeGroup(b)` (`ReferenceFactProposalService.php:250-252`), and `"grupos 1 y 2" !== "grupo 2"`. Extracting the group's **digit-token set** — `{1,2}` vs `{2}` — and testing for a **non-empty intersection** catches the Navarra Intervención Social case exactly, is auditable, costs no LLM call, costs no embedding, and is trivially unit-testable on the real strings. An embedding pass would be fuzzier, slower, and no better at this specific job; for a *flag* into a human queue, deterministic is sufficient, and "prefer deterministic where it suffices" is the standing instruction (ADR-0015/0016 spirit; `roadmap.md:168-169`).

Rules for the new pass (a service method + `php artisan facts:scan-duplicates`, so it runs over **existing** facts, not only at segmentation time):

- candidate set: same `convenio_id` **and** same `topic_id`, `status != 'rejected'`, `duplicate_of_id IS NULL` on the newer side (never clobber a flag the exact key already set), `resolution IS NULL` (never re-flag a resolved pair).
- group relation: `EXACT` (already covered by 7b-2), `OVERLAP` (digit-token sets intersect but differ — the new catch), `DISJOINT` (skip), `UNKNOWN` (no digits on either side → skip; conservative).
- values differ (`trim` compare, as today).
- → set `duplicate_of_id`, and `uncertainty = {field: 'version', reason: '…grupos solapados…'}` if empty; write a `tag_events` row `facet = 'duplicate'` whose note names the pass (`'semantic/scope duplicate pass (group-token overlap)'`) so the two detectors stay distinguishable in the audit trail without a new column.
- **flag only.** Never link-and-resolve, never merge, never retire.

**Gold test:** the 7b-2 fixtures — file-1 Navarra Intervención Social *"Grupos 1 y 2 = 6 meses"* vs file-2 *"Grupo 2 = 4 meses"* (convenio `31101815012021`) must come out flagged as a pair, and the 8 pairs the exact key already caught must stay caught with no new false pairs (`sprint-07b-2/review.md:168`). Fixtures and the scorer already exist in `sprint-07b-2/eval/`.

⚠ **7f interaction — deliberate.** The digit-token extraction resembles `ReferenceFactAnswerService::factMatchesGroup` (`:208-215`), which 7f will replace with structured group scope. Two guards: (i) put the tokeniser in **one** shared helper so 7f replaces one implementation, not two; (ii) this pass only ever writes a **flag on a review queue**, never an answer-time match — so it cannot make group-scoped facts answerable and cannot trip 7f's hard precondition (`roadmap.md:161`). A supersede that closes a validity window is likewise answer-affecting only through the *existing* verified-fact query, which is unchanged.

### 3.4 How resolution reaches the answer

Through data, nothing else. After a supersede, the older fact falls out of `ReferenceFactAnswerService`'s validity predicate (`:85-86`) for present-day questions, so `selectByValidity` (`:161-190`) sees one candidate and returns `'single'` instead of `'ambiguous_conflict'` — the escalation stops because the data was fixed, not because a branch was added. **No line of `ReferenceFactAnswerService`, `ReferenceFactRouter`, or `ChatService` changes in 7d.**

---

## 4. (C) Succession proposal

### 4.1 The flow (7a's propose-pattern, verbatim)

```
reviews:scan-expiry  →  document_review_tasks(type='expiry', status='open')      [unchanged]
        ↓  queued ProposeSuccession(taskId)          (new job, $tries=1, never rethrows)
hr-ai POST /compare-scope   (read-only; probes = the expiring doc's chunk texts,
                             candidates = same-convenio documents' chunks)
        ↓
SuccessionProposalService  →  task.ai_proposal (jsonb, inert, source ai_agent, fuchsia)
        ↓  human clicks Confirm
ReviewQueueController::resolveExpiry(action='link_successor', successor_uuid=…)   [unchanged 7a write-side]
```

Dispatch: at the end of `ReviewsScanExpiry` for each newly-created task, plus a manual `POST /admin/review/expiry/{taskId}/propose-succession` (`ability:knowledge.edit`) for re-runs — mirroring `resuggest` (`DocumentController.php:297-309`) and `segment` (`ReferenceFactController.php:260-268`).

### 4.2 Candidates — same convenio only

The read side already computes the exact set, and 7d reuses it rather than re-deriving it:

```49:53:hr-backend/app/Http/Controllers/Admin/ReviewQueueController.php
                $candidates = Document::where('convenio_id', $doc->convenio_id)
                    ->where('id', '!=', $doc->id)
                    ->orderByDesc('validity_start')
                    ->limit(20)
                    ->get(['uuid', 'title', 'validity_start', 'validity_end', 'retrieval_status'])
```

Cross-convenio and topic-based succession are structurally impossible here — the candidate query is convenio-keyed, and the write-side independently 422s on a convenio mismatch (`:146-158`). Two independent gates, as 7a intended.

### 4.3 The relationship label — deterministic from score + validity

**Recommendation: no LLM in (C).** The relationship is derived in hr-backend from the comparison output plus the documents' own validity windows:

| proposal | condition | rationale |
|---|---|---|
| `successor` | `max_score ≥ hr.succession_overlap_threshold` **and** `candidate.validity_start > expiring.validity_start` (both non-null) | a newer version of the same scope — requires *both* high overlap and strictly-later validity, so a high-overlap sibling can't be mislabelled a successor |
| `conflict` | `max_score ≥ hr.succession_overlap_threshold` and validity ordering is absent, equal, or overlapping | same scope, same subject, no version ordering → needs adjudication |
| `coexisting_sibling` | `max_score ≤ hr.succession_sibling_ceiling` | same scope, different subject → both stay active |
| `uncertain` | anything in between, or `< 2` comparable chunks either side | no relationship claimed; `uncertainty` states why |

Why deterministic: it is auditable, needs no `AnswerModelSetting` key configured (7a/7b-2 both *skip* when no key is set — `ReferenceFactProposalService.php:60-63`; (C) works regardless), and it keeps the highest-consequence label (`successor`) behind a **conjunction** rather than a model's judgement. The cost is honest and stated: a *contradiction* that is not also a version bump is labelled `conflict` rather than explained — which is the correct conservative outcome for a queue whose only job is to route to a human. LLM classification is recorded as §7 Q5, deferrable and additive later.

The proposal is still `ai_agent`-provenanced (BGE-M3 similarity is AI-derived) and gets the fuchsia inert treatment (`--provenance-ai`, `index.css:29-36`; ADR-0020's rule that fuchsia marks unverified-AI only).

### 4.4 The stored proposal (inert)

`document_review_tasks.ai_proposal` (jsonb, nullable):

```json
{ "relationship": "successor",
  "candidate_document_id": 72, "candidate_document_uuid": "…", "candidate_title": "…",
  "max_score": 0.88, "mean_top3": 0.81,
  "validity": {"expiring": ["2021-01-01","2025-12-31"], "candidate": ["2026-01-01",null]},
  "passages": [ {"expiring_chunk_id": 41207, "candidate_chunk_id": 51882,
                 "expiring_excerpt": "…", "candidate_excerpt": "…", "score": 0.88} ],
  "uncertainty": {"field": "relationship", "reason": "…"} | null,
  "proposed_at": "…", "source": "ai_agent" }
```

Plus `ai_proposal_status` (`proposed` | `confirmed` | `rejected`) and `ai_proposed_at`.

**Invariants (to be tested, not asserted):** the job writes **only** these three columns on the task. It never writes `predecessor_document_id`, never `retrieval_status`, never `validity_*`, never `documents.*` at all. Confirm → the untouched 7a write-side runs (`resolveExpiry`, `:171-185`), so the predecessor is still never auto-retired and retirement still needs both `retire_predecessor` **and** `confirm_scope_change` (`:160-168`). Reject → `ai_proposal_status = 'rejected'` + a `tag_events` row on the expiring document (`facet = 'succession_proposal'`, `source = 'admin_manual'`); nothing else written.

### 4.5 The UI

`ExpiryRow` (`hr-frontend/src/pages/admin/ReviewQueuePage.tsx:292-359`) gains, above the existing successor `<select>`, a fuchsia `.notice--ai` (`index.css:2050-2054`) block: the relationship label, the score, the two compared passages side by side (`.fact-create-grid` + `.well`), the uncertainty line, and two buttons — **Confirm** (pre-selects the proposed successor in the existing `<select>` and calls the existing `resolveExpiry` with `action='link_successor'`; the retire checkbox stays default-off) and **Reject proposal**. The proposal never pre-checks "also retire."

### 4.6 The gold eval

Reusing 7b-2's eval discipline (`sprint-07b-2/eval/README.md`, `score_eval.py`), a small real-pair set from the corpus — the build turn confirms each pair by SQL (`documents` grouped by `convenio_id` having >1 prose doc with differing validity windows) and records the ids. Candidates already named in `deploy.md:62`:

- **successor pair** — the Vizcaya Intervención Social family (id 18 + 13/16), or COEAS Estatal (id 72) as the `under_review` successor to its historical predecessor;
- **coexisting sibling pair** — a convenio holding a prose text plus a salary-table PDF (Navarra Oficinas-despachos: prose id 93 + salary PDF id 94) or a prose text plus a `partial_agreement`;
- **conflict pair** — two same-convenio prose documents with overlapping validity and differing content on the same subject.

Scored **right / uncertain / confidently-wrong** per pair. **Confidently-wrong-successor is the failure metric** and must be 0 on the fixtures (or explicitly flagged in `review.md` with the reason), because that is the one output that would tempt a human to retire a live document — the 7a warning (`roadmap.md:112`). Reported alongside the (A) calibration distribution in `review.md`.

---

## 5. Additivity & the frozen loop

### 5.1 Changed

| repo | file | change |
|---|---|---|
| hr-ai | `app/main.py`, `app/chunks_db.py` | one additive read-only endpoint + one SELECT function; no write, no migration |
| hr-backend | `app/Services/EscalationService.php` | one injected service + one `if` after line 255; `detectConflicts` **not edited** |
| hr-backend | `app/Http/Controllers/Admin/EscalationController.php` | two new 409 codes + the `acknowledge_semantic_overlap` input |
| hr-backend | `app/Services/ExtractionClient.php` | one `compareScope()` method |
| hr-backend | new | `SemanticFenceService`, `FactResolutionService`, `SuccessionProposalService`, `ProposeSuccession` job, `RecheckRulingsForConvenio` job, 2–3 artisan commands, 1 controller method on `ReferenceFactController`, 1 on `ReviewQueueController` |
| hr-backend | `config/hr.php` | new named thresholds |
| hr-backend | 3 additive migrations | §6 |
| hr-frontend | `EscalationCardDrawer.tsx`, `ReviewQueuePage.tsx`, `ReferenceFactPanel.tsx` (or a new `FactDuplicatePanel.tsx`), `lib/api.ts`, `index.css` | three surfaces, existing tokens |
| hr-docs | `architecture.md` §8.3/§8.5, `data-model.md`, `roadmap.md`, ADR-0024, `sprint-07d/review.md` | docs |

### 5.2 Not changed — the frozen loop

Untouched, byte for byte: `hr-ai` `POST /retrieve`, `/synthesise`, `/ground`, `/route`, `/embed`, `/sandbox-retrieve`; `chunks_db.retrieve`, `retrieve_by_document`, `replace_document_chunks`; `ChatService`; `RouterService`; `GroundingService`; `SalaryAnswerService`; `ReferenceFactRouter`; `ReferenceFactAnswerService` (including `selectByValidity` and `factMatchesGroup`); `GuardrailService`; `GuardrailPolicy`; `RulingPublisher`; `EscalationService::detectConflicts`; `document_chunks` (no new column, no re-embed, no re-chunk); the precedence re-rank; `hr.retrieval_score_floor` and every existing floor.

The gate: `php artisan test --filter Sprint7cAdditivityRegressionTest` stays green (`:114` prose byte-for-byte, `:154` salary byte-for-byte), plus the full existing suite — notably `Sprint5Correction01FenceTest` (all four cases unchanged), `Sprint7aTagProposalInvariantTest`, `Sprint7b1ReferenceFactInvariantTest`, `Sprint7b2SegmentationInvariantTest`, `Sprint6GuardrailInvariantTest`, `Sprint5AccessMatrixTest`.

### 5.3 The fence never opens

Three independent reasons, in descending strength: it is **structural** (the semantic pass runs only when the existing check is empty, so it is a pure disjunction — §2.1); it is **tested** (§2.7 cases 1, 3, 6, 7); and it is **documented** as a numbered rule in ADR-0024.

---

## 6. Migrations & build order

### 6.1 Every migration (all additive, all nullable, no backfill, no CHECK rewrite)

| # | table | columns | for |
|---|---|---|---|
| **1** | `escalation_events` | `detail` jsonb **nullable** | (A) machine-readable chunk ids + scores on `publish_blocked` / `publish_acknowledged_overlap` |
| **2** | `reference_facts` | `resolution` varchar(32) nullable · `superseded_by_id` bigint nullable FK→`reference_facts` nullOnDelete · `resolved_by` bigint nullable FK→`admins` nullOnDelete · `resolved_at` timestamp nullable · index on `resolution` | (B) resolution provenance + supersede lineage |
| **3** | `document_review_tasks` | `ai_proposal` jsonb nullable · `ai_proposal_status` varchar(16) nullable · `ai_proposed_at` timestamp nullable | (C) the inert proposal on the expiry task |

**Deliberately *not* migrated:**
- `escalation_events.type` — already a free `string` (`2026_06_24_100002_…:24-25`), so the new event types need nothing.
- `document_review_tasks.type` / `reason` — the §8.5 reverse flag reuses `type='conflict'` / `reason='conflict'`, avoiding an enum CHECK drop-and-re-add (the 7b-2 `status` precedent, `2026_06_26_120002_…:125-129`).
- `reference_facts.resolution` is a plain **nullable varchar**, not a Laravel `enum`, for exactly the same reason — a future value must not require a CHECK rewrite. Allowed values are validated in the FormRequest.
- `tag_events` — `entity_type` and `facet` are free strings (`2026_06_20_131023_…:14-28`); `source` already has `admin_manual` and `ai_agent`.
- `guardrail_configs` — no semantic threshold column, per §2.5.
- `document_chunks` — nothing. hr-ai does not migrate (ADR-0007; `hr-ai/AGENTS.md:11`).

### 6.2 Build order (A → B → C, with a calibration step first)

**Step 0 — the primitive + calibration (before any threshold is chosen).** hr-ai `POST /compare-scope`; `ExtractionClient::compareScope`; `php artisan fence:calibrate-semantic` (read-only, writes nothing) running the comparison for **every already-published `internal_hr_ruling`** against its convenio's active `official_convenio` chunks, reporting the per-ruling max/p90/median and the block/acknowledge count at candidate threshold pairs. Thresholds are set from that output, conservatively, and the distribution is pasted into `review.md`. *This step is a hard prerequisite: shipping an unmeasured threshold on a safety gate is the one thing worse than the current blunt fence.*

**Step A — the fence (the safety gate, first).** `SemanticFenceService` (probe split, compare, band decision); the `EscalationService` hook; migration 1; the two 409 codes; `Sprint7dFenceNeverOpensTest` (§2.7) green **before** any UI; then the block/acknowledge UI. Then — budget permitting — the §8.5 reverse re-check as command + queued job.

**Step B — fact resolution.** Migration 2; `FactResolutionService` (supersede/coexist/reject); the resolve-duplicate route; the deterministic group-token duplicate pass + `facts:scan-duplicates`; the Navarra gold test; then the side-by-side surface.

**Step C — succession proposal.** Migration 3; `SuccessionProposalService` + `ProposeSuccession`; the propose route + the `ReviewsScanExpiry` dispatch; the invariant test (AI writes only the three proposal columns); the gold eval; then the `ExpiryRow` UI.

**Docs & close.** `architecture.md` §8.3 (fence → semantic-additive with bands + acknowledgement) and §8.5 (boundary closed at the flag level); `data-model.md` (the three migrations, the new event types/`detail`, the fact resolution provenance); `roadmap.md` (7d DONE); **ADR-0024**; `sprint-07d/review.md` with the fence-never-opens proof, the calibration distribution, and the succession gold eval. Stop — no commit until review.

### 6.3 ADR-0024 (flagged)

**`hr-docs/architecture/decisions/0024-semantic-comparison-human-adjudicated.md`** — *"Semantic comparison as a human-adjudicated, fail-toward-caution mechanism."* Matching the house format (`# ADR-00NN — Title`, `**Status:** accepted (Sprint 7d)`, `## Context` → `## Decision` (numbered load-bearing rules) → `## Consequences`), per ADR-0023's skeleton. The numbered rules to record:

1. **The fence is additive and can only get stricter:** `fence = existing_block OR semantic_block`; the semantic pass is consulted only when the existing check is empty; a similarity miss can never open a gate the crude check closed. Proven by `Sprint7dFenceNeverOpensTest`.
2. **Two bands, never a silent pass:** certain overlap blocks, plausible overlap requires explicit acknowledgement, comparison failure falls to the acknowledge band. The matched passages are always shown.
3. **A block-triggering threshold tightens by *decreasing*.** Any future admin exposure must be `min(baseline, admin)`, inverting ADR-0019's `max` — and is out of scope here.
4. **Supersede closes validity; it never deletes.** A verified fact is never destroyed; the historical answer stays correct for its window.
5. **Succession is proposed, never applied.** Same-convenio only, inert `ai_agent` proposal, human confirm runs the 7a write-side, predecessor never auto-retired, AI never writes lineage or status.
6. **Deterministic where determinism suffices:** group-token overlap for the duplicate pass, score+validity conjunction for the relationship label — no LLM in either.
7. **hr-ai reads and returns.** One additive read-only endpoint; no write beyond `document_chunks` (which 7d does not touch); no migration (ADR-0007).

---

## 7. Assumptions & open questions

**Q1 — the thresholds are unknown, and that is the biggest risk (needs a decision on the calibration gate).**
Nothing in the corpus tells us today what cosine similarity a genuine "the convenio already governs this point" overlap produces. The only comparable number is `hr.retrieval_score_floor = 0.40` (`config/hr.php:20`), which is a *retrieval-is-meaningful* floor, not a *same-point* threshold, and is a poor prior for this. **Proposal:** `php artisan fence:calibrate-semantic` per §6.2 Step 0 — read-only, over every already-published ruling vs its scope's active `official_convenio` chunks; report min/median/p90/max per ruling and the block/acknowledge counts at candidate pairs; choose thresholds from the distribution, biased low (block more), and paste the table into `review.md`. **Open:** the corpus may hold too few published rulings for a real distribution. If so, the fallback is to calibrate on *synthetic* probes — sample real convenio chunk texts as probes (a paraphrase of the convenio should score high; an unrelated convenio's chunk should score low) — which gives an upper and lower anchor without needing rulings. Confirm which you want if the ruling count is small.

**Q2 — embedding vs deterministic for the (B) duplicate pass.** Recommending deterministic digit-token overlap (§3.3): it closes the exact documented miss, needs no model, and is unit-testable on the real strings. Accepted cost: it will not catch a version pair whose labels share no digits (e.g. *"Personal titulado"* vs *"Grupo 1"*) — a class not present in the documented miss. **Open:** accept that gap for 7d, or add an embedding pass as a second, lower-priority flag source? Recommendation: accept the gap; revisit after 7f gives structured group scope, which dissolves most of the problem.

**Q3 — the reverse re-check's scope (§2.8).** Recommending the narrowest useful trigger: *an `official_convenio` becoming active in a convenio that has ≥1 active `internal_hr_ruling`*. **Open:** should an *edit* of an already-active convenio (a re-embed) also trigger it, or only activation? Recommendation: activation + the manual command only — an edit-trigger multiplies the surface for a flag-only feature, and this is already the declared first deferral candidate.

**Q4 — comparison unavailable: acknowledge or hard block?** Recommending the acknowledge band with `semantic_compare_unavailable` (§2.4): never a silent pass, but hr-ai downtime doesn't become a publish outage. The strictly-stricter alternative is a hard block. Note the risk honestly: an acknowledgement prompt that appears *because the comparison failed* trains the human to click through, which is the mechanism by which acknowledgement bands go stale. Mitigation: the copy names the cause explicitly and differently from a real near-passage, and the event records `semantic_compare_unavailable` so `review.md` can count how often it fires. **Your call.**

**Q5 — "same subject" for sibling-vs-conflict (C).** In the recommended deterministic scheme, "same subject" *is* high chunk-level cosine overlap, and the successor/conflict split is carried entirely by validity ordering. This will mislabel one real shape: two documents about the same subject with *contradictory* content and no validity ordering come out `conflict` (correct) but with no explanation of *what* contradicts — the human reads the passages. **Open:** is that acceptable for 7d, or do you want the LLM relationship-classification variant (an optional `provider_api_key` on the compare endpoint, reusing the `/segment-facts` key-in-body pattern)? Recommendation: deterministic for 7d; LLM classification is cleanly additive later and does not change any stored shape.

**Q6 — what the real fence code makes non-obvious.** Four items, all already folded into the plan, restated because each one would be a silent bug if missed:
1. **The draft ruling has no chunks at fence time** (fence at `EscalationService.php:254`, `/embed` at `:299`) — the spec's "reuse its freshly-embedded chunks" is not available; `resolution_text` must be embedded as a query.
2. **`/retrieve` cannot filter by authority level**, so client-side filtering after top-k is a genuine fail-open in a safety gate — this is the whole reason for the one additive endpoint (§1.5).
3. **Long `resolution_text` is silently truncated** by the embedder (up to 20 000 chars allowed, `EscalationController.php:206`) — hence multi-probe (§2.3).
4. **"Raise-only" inverts for a block threshold** (§2.5) — the single most likely way a future change could quietly open the fence.

**Q7 — smaller assumptions, stated so they can be corrected.**
- The acknowledgement is a **per-attempt** re-POST flag, not a stored per-card grant — a second publish attempt asks again. (Assumed; it is the stricter reading and matches `confirm_scope_change`.)
- Supersede requires the human to say **which** fact is newer; the API never infers direction from ids or `created_at` (422 if the validity ordering doesn't support the claim).
- The §8.5 reverse flag reuses `type='conflict'` with a `"kind"` discriminator inside `raw_unmatched_values` rather than a new enum value (§6.1). If you'd rather have an explicit `type='semantic_conflict'`, that costs one CHECK drop-and-re-add migration — say so and I'll add it.
- `national_law` is fetched informationally in (A) and never blocks. In v1 it may simply be omitted from the fence UI entirely if it adds noise.
- (C)'s candidate cap of 20 is inherited from the existing read side (`ReviewQueueController.php:52`); the proposal compares against the same 20, not a wider set.

---

## 8. Hard-constraint self-check

| constraint | how this plan satisfies it |
|---|---|
| Fence only gets stricter — `existing OR semantic`, proven by test | §2.1 (structural short-circuit), §2.7 (7 cases incl. the disjunction matrix and the four Sprint-5 regression cases) |
| Thresholds conservative, raise-only, block-or-acknowledge, never silent-pass | §2.4, §2.5 (incl. the inverted-direction finding), §2.4 unavailable→acknowledge |
| No auto-resolve/link/retire/demote/publish; every AI relationship an inert `ai_agent` proposal (ADR-0020) | §3.2 (all three actions human-invoked), §4.4 (three columns only, inert), §2.8 (flag only, never demote) |
| Supersede closes validity, never deletes | §3.2 (`validity_end = newer.validity_start − 1`, both kept, `older` stays `verified` for its window) |
| Succession same-convenio only, predecessor never auto-retired | §4.2 (two independent gates), §4.4 (confirm runs the unchanged 7a write-side, retire still double-gated) |
| Answer loop untouched; 7c golden-trace green | §5.1/§5.2 (explicit not-changed list), §5.3; resolution reaches chat via data only (§3.4) |
| hr-ai reads/returns, never migrates; hr-backend owns writes; additive migrations only | §1.5 (SELECT-only endpoint), §6.1 (3 additive nullable migrations, no CHECK rewrite) |
| Vocabulary bind-only (ADR-0011) | 7d creates no topic, convenio, sector, territory or job category anywhere |
| Reuse `/retrieve`/embed machinery, the 7a propose-pattern + expiry write-side, the 7b-2 flag + review tab, the design system, `EnsureCan`, append-only events | §1.6 ledger |

---

## 9. Definition of done (for the build turn, not this turn)

All six spec acceptance criteria pass; `fence:calibrate-semantic` output recorded and thresholds set from it; `Sprint7dFenceNeverOpensTest` + the (C) invariant test green; `Sprint7cAdditivityRegressionTest` and the whole existing suite still green; the Navarra Intervención Social version pair surfaces as a flag; the succession gold eval reports right/uncertain/confidently-wrong with confidently-wrong-successor at 0 (or flagged); Pedram eyes-on per the spec's three walkthroughs; `architecture.md` / `data-model.md` / `roadmap.md` updated; ADR-0024 written; `sprint-07d/review.md` written. **No commit until review.**

---

**Ready for review. No code written; no file created or modified other than this plan.**
