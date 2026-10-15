# Slice 12c — Plan

> Status: **PLAN — awaiting review. No code, no commits, no staging writes.**
> Scope: embed the confirmed document only, fix the Review page display, and propose (not build) a fix for the false sector conflicts.
> Read-only staging pass for the lists below: 2026-10-14, `SET TRANSACTION READ ONLY` inside a transaction that was rolled back. Nothing was embedded and no container was changed.

## 0. Findings the implementation has to respect

| # | Finding | What it forces |
|---|---|---|
| **F1** | `DocumentController::confirm` (lines 297–324) sets `tagging_status = verified`, writes one `tag_events` row, and resolves open review tasks. It does not embed. | The new work is a job dispatched after that transaction commits. |
| **F2** | `chunks:embed` with no `--document` is the global selection: prose type in `convenio_text`, `national_law`, `partial_agreement`, `internal_hr_ruling`, `retrieval_status` in `active`/`historical`, `tagging_status != under_review` (`ChunksEmbed.php` lines 34–42). A dry-run today selects **35** documents. `--document=<uuid>` adds a uuid filter after those predicates, so it embeds at most that one. | The job calls the single-document embed. It never calls the command without `--document`. |
| **F3** | hr-ai `replace_document_chunks` (`chunks_db.py` lines 30–41) deletes that document's chunks and inserts the new set in one transaction. A second **finished** run does not duplicate. Two runs of the **same** document at the **same** time can. | Serialize embed jobs. Do not add a second worker. |
| **F4** | The staging worker is `queue:work --tries=3 --timeout=120` (`docker-compose.staging.yml` line 159). A job's own `$timeout` overrides that 120s (`Worker::timeoutForJob`). `retry_after` on the database connection is 90s (`config/queue.php`). `ExtractionClient::embed` gives up at 900s. Measured embeds: document 96, 49 pages, 7m 21s; document 76, 74 pages, 10m 4s. | Without a job-level timeout, the worker kills the embed at 2 minutes and the `--tries=3` default runs it again. |
| **F5** | Employee retrieval keeps a chunk only when the convenio matches, or the chunk is `national_law`, and `retrieval_status` is `active` (`chunks_db.py` lines 106–110; `RetrievalUnion.php` lines 111 and 145). A historical chunk is invisible in chat. An `official_convenio` chunk with `convenio_id` null matches neither arm. | Historical and unbound convenio documents do not become answers by being embedded. |
| **F6** | The confirm button is disabled once `tagging_status` is `verified` (`DocumentDetailPanel.tsx` lines 307–310). A failed embed cannot be retried from that button as it stands. | The retry has to be a deliberate control on the failed card. |
| **F7** | PHPUnit and `.env.example` use `QUEUE_CONNECTION=sync`. Staging sets `QUEUE_CONNECTION=database` and already has one worker. | Tests fake the queue. Staging is where "the click returns immediately" is real. |

## 1. Embed on confirm

### 1.1 What the click does

When `confirm` succeeds, and only then, dispatch `EmbedDocument` for that document id. The dispatch is after the transaction closes, so the worker cannot observe the document still `under_review`. The HTTP response returns as soon as the job row is inserted. The request does not call hr-ai.

Queue the job only when all of these hold, checked again inside the job (the row can change while it waits):

- document type is `convenio_text`, `partial_agreement`, `national_law`, or `internal_hr_ruling` (the same four as `ChunksEmbed::IN_SCOPE_TYPES`, which is wider than `KnowledgeMap::PROSE_TYPE_CODES` — that list omits `internal_hr_ruling`; the job follows the embed command, not the coverage map);
- `retrieval_status` is `active` (see §1.2);
- `tagging_status` is not `under_review`;
- at least one `document_pages` row has non-blank text;
- the scope gate in Decision D1.

The job loads that one document and calls a new `DocumentEmbedder::embed`, which is the body of today's per-document loop: `resolveScope` (`ChunksEmbed.php` lines 144–154) and `ExtractionClient::embed` for that id. `ChunksEmbed` is refactored to call the same method inside its existing loop. Its selection query, including historical documents and the no-`--document` dry-run, stays as it is. The job has no path that builds that query.

`under_review` stays excluded in both places. `confirm` is what clears it, and only after the commit. If the job is dispatched by hand for a document that is still `under_review`, `DocumentEmbedder` returns without calling hr-ai.

Salary types, `reference_source`, and `summary` are not in the four types, so confirm does not queue them.

### 1.2 Historical documents: skip

A historical document is not embedded on confirm.

Chat only retrieves `active` chunks (F5). Embedding a historical convenio text spends the one worker and hr-ai for 7–10 minutes and cannot change an employee answer. The operator command is unchanged: `chunks:embed --document=<uuid>` still embeds a historical document when someone runs it on purpose, and a global dry-run still lists historical prose. Confirm is the "make this answerable" action, so it does not.

Draft is skipped for the same reason. The request said active.

### 1.3 Decision D1 — unbound convenio text: skip. Reject this if you want the three gates only.

Recommended extra gate, on top of the three in the request: do not queue an `official_convenio` document whose `convenio_id` is null. Do queue `national_law` with a null convenio, because that is how national law is retrieved.

Why: an unbound `official_convenio` chunk is stored and then never returned (F5). The four active prose documents still `under_review` are all unbound: **14** (partial agreement, 12 pages with text), **31** (convenio text, 2), **55** (convenio text, 78), **90** (partial agreement, 11). Confirming 55 under the three gates alone would block the worker for about ten minutes and chat would still not cite it.

This gate is not in `chunks:embed`. The command can still embed an unbound document when run by hand. The job will not.

### 1.4 Decision D2 — flipping a verified document to active queues the same job. Reject this if confirm is the only trigger you want.

`updateLifecycle` can set `retrieval_status` from `historical` or `draft` to `active` on a document that is already verified. If confirm skips historical (§1.2), that flip is the hole: the document becomes the current text and still has no chunks until someone runs the command.

Recommended: when `updateLifecycle` commits a change that makes the document eligible under §1.1, dispatch the same `EmbedDocument`. No other lifecycle edit queues it. A flip from active to historical does not delete chunks; chat stops seeing them because retrieval filters on `active`.

### 1.5 One at a time, safe to run twice

`EmbedDocument`:

- `public int $timeout = 1200`, so the worker's 120s ceiling does not apply to this class. 1200s sits above the client's 900s, so a hung embed fails as an exception the job can record, rather than as a worker kill. The client timeout stays 900s. At the measured pace (about 6.5s per page after ~2 minutes of fixed cost), 900s covers a document the size of 76 with headroom, and a much larger PDF fails onto the card with "Embedding took too long and was stopped."
- `public int $tries = 1`. The worker's `--tries=3` must not run a ten-minute failure three times.
- `ShouldBeUnique` with `uniqueId` = the document id, so a double-click while the job is queued does not insert a second row. The lock releases when the job finishes, including failure, so a retry can queue.
- `WithoutOverlapping` on one shared lock name (`embed-documents`), `expireAfter` 1200. Not per document. Embed jobs for different documents run one after another. The lock lives in the database cache (`CACHE_STORE` defaults to `database`; the worker does not override it), so a second worker cannot run an embed beside the first. OCR and tag-proposal jobs do not take this lock.
- The staging worker count stays one. `DB_QUEUE_RETRY_AFTER` stays 90. With one worker a long job is not stolen; the overlap case is a second worker or a deploy that starts a new worker while the old PHP is still inside hr-ai. The shared lock covers that. Do not raise the global retry and do not raise the worker `--timeout` (that would let a stuck OCR job run for twenty minutes).

Idempotency of the chunks themselves is the existing delete-then-insert. The job does not insert beside it. A second confirm, or a retry after failure, calls `embed` again for that uuid. Sequential runs replace. They do not append.

The confirm button stays disabled when tags are verified and embedding succeeded or is in progress. When the card is in the failed state, the same `confirm` endpoint is callable again: tags are already verified, so the action only queues the job. The button label in that state is "Retry embedding". A second click while a job is pending is a no-op because of the unique lock.

### 1.6 Status on the card

No migration. The jobs table, the cache table, and `tag_events` already exist.

The job appends `tag_events` rows with `facet = embed`:

| `new_value` | `note` | When |
|---|---|---|
| `pending` | empty | dispatched |
| `searchable` | chunks written | `embed` returned |
| `failed` | a short code: `timed_out`, `service_unreachable`, `failed` | the exception was caught |

The exception message goes to the log (`document_id`, uuid, exception). It does not go on the card. The card maps the code through i18n:

- `timed_out` → "Embedding took too long and was stopped."
- `service_unreachable` → "The embedding service did not respond."
- `failed` → "Embedding failed."
- pending older than 20 minutes with no later row → "Embedding did not finish." (worker killed, `failed()` never ran)

`show` adds a computed `embed` object: `state`, `chunk_count`, `error_code`. `chunk_count` is the live count already behind `chunk_health`, not the note on an old event. The chunk-health block stays behind `VITE_SHOW_CHUNK_HEALTH`. This line is separate and always shown for the four prose types.

- pending → "Embedding…"
- searchable, or no embed event but `chunk_count > 0` → "Searchable · N chunks" (documents 76 and 96, and the rest of the already-embedded corpus, show this with no new event)
- failed → the plain sentence

The panel polls `show` every 5 seconds while the state is pending and stops on searchable or failed. Closing the panel does not lose the state; it is the latest `tag_events` row.

`confirm`'s JSON gains `embed_queued: true|false` so the card can show "Embedding…" before the first poll. The response does not wait for the job.

### 1.7 Documents that are active, verified, and prose, with 0 chunks

Predicate: `retrieval_status = active`, `tagging_status = verified`, type in the four prose codes, no `document_chunks` row.

**The list is empty** (read 2026-10-14, transaction rolled back).

Documents 76 and 96 were that set this morning. They were embedded by hand with `--document` and are not in it now. There is no one-off to run, and there is nothing for a deploy to sweep. The job is dispatched only from `confirm` and, if D2 stands, from the lifecycle flip. Deploying this slice does not embed any existing document.

The four active `under_review` prose documents (14, 31, 55, 90) are not in this list. They are unverified and unbound. This slice does not embed them.

## 2. Review page display

Frontend only. No API change for (a)–(c). For (d) the payload already has `kind` on both reason sites (`ConvenioGroupController.php` lines 154 and 226). The screen stops rendering `f.reason`. The Spanish sentence stays on the API for the log.

**(a) Step banner.** `.notice` is `display: flex` (`index.css` lines 1237–1246). The AI banner is one `<p class="notice notice--ai">` whose children are an icon, loose text, and several `<strong>` (`DocumentDetailPanel.tsx` lines 238–244). Flex lays each of those out as its own column. Fix: wrap the sentence in one element so the flex row is icon + text, and set `.notice--ai { display: block; }` so this banner cannot regress into columns. Other `.notice` callouts keep the icon row.

**(b) Groups detail.** `GroupsQueue.tsx` renders `div.groups-queue.two-pane` and `div.pane-detail` (lines 86–129). There is no CSS for `.two-pane`, `.pane-detail`, `.node-head`, or `.node-actions`. The bare `<button>`s are at lines 152, 292, 425, 437, 457, 460, 463, 469, 482, 490, 587, and 600. The detail pane has no background and no width constraint, so the table and the native buttons spill onto the page background.

Fix, scoped under `.groups-queue` so other `.panel` uses are untouched:

- `.two-pane`: grid, list column fixed, detail column `minmax(0, 1fr)`.
- `.pane-detail`: `min-width: 0`, `overflow: auto`, `max-height` inside the viewport, background `var(--canvas)`.
- `.node-actions`: flex, wrap, gap.
- Primary actions (propose, approve, save) get `btn btn-primary`. The rest get `btn btn-ghost`.

**(c) Glued group name.** `NodeCard` prints `<strong>{node.label}</strong>` immediately followed by `<code>{node.code_normalized}</code>` (lines 360–363), and `.node-head` is not a flex row. "GRUPO 1" plus "1" paints as "GRUPO 11". Fix: `.node-head` is a wrapping flex row with a gap, and the code keeps its matcher-key title so the key is still visible as its own chip. The chip is not removed; the concatenation is.

**(d) Reason language.** `f.reason` is rendered raw at `GroupsQueue.tsx` lines 219 and 574. Those strings are written in Spanish in `FactGroupBindingPlanner::resolveLabel` (lines 158–247). The stable key is `kind`.

Add `groupsQueue.reasons.<kind>` in `en.ts` and `es.ts` for the kinds a reviewer can see: `complement`, `no_structure`, `unrecognized`, `unknown_group`, `ambiguous_split_group`, `unknown_sub_area`. English templates interpolate `group_label`, which is already on the row. Spanish templates are the same sentences the planner writes today, so the Spanish screen does not get worse. `convenio_wide` already has its own notice and is filtered out of the unbindable list; no new key. `exact` and `compound` have a null reason.

`diff.note` ("Aprobar el nodo no vincula nada por sí solo…", `ConvenioGroupController.php` lines 230–231) is also Spanish on this panel. It is not a Reason cell. Left as-is unless you want it in the same pass.

## 3. False sector conflicts — proposal only, not built

Rule 3 (`DocumentTagger.php` lines 91–94) fires when a parsed sector id differs from the convenio's sector. The convenio is found only by numero (`DocumentTagger.php` lines 36–39). So "the convenio was matched by number" is the only way a sector conflict can exist. Stopping the conflict in that case is the same as not raising rule 3.

A conflict forces `under_review` (line 176). With no conflict and no unresolved facet, the same file lands `auto_proposed`. `auto_proposed` is not `under_review`, so a later global `chunks:embed` would select it. This slice's job would not, because nobody clicked confirm. The confirm button is still available on an `auto_proposed` document.

Open and resolved sector-conflict tasks on staging today (8 conflict tasks total; the other 2 are authority conflicts on draft rulings 99 and 100 and are out of this proposal):

| Document | Task | Status | Type | Convenio already bound |
|---|---|---|---|---|
| 29 | parsed sector HOSTELERIA disagrees with registry | open | historical convenio text | 16 · 22000175012004 · sector HOSTELERIA Y TURISMO HUESC |
| 40 | parsed sector DEPORTE disagrees with registry | open | historical convenio text | 20 · 31008235012003 · sector GESTIÓN DEPORTIVA |
| 73 | parsed sector DEPORTE disagrees with registry | open | historical convenio text | 9 · 99015105012005 · sector INSTALACIONES DEPORTIVAS Y GIMNASIO |
| 87 | parsed sector COEAS disagrees with registry | open | historical convenio text | 11 · 99100055012011 · sector OCIO EDUCATIVO Y ANIMACION SOCIOCUL |
| 76 | parsed sector COEAS disagrees with registry | resolved | active, already verified and embedded | 11 · same numero |
| 96 | parsed sector COEAS disagrees with registry | resolved | active, already verified and embedded | 17 · 28102145012018 |

Zero sector conflicts on a document with no convenio. Zero sector conflicts whose parsed token is anything but COEAS, DEPORTE, or HOSTELERIA. Neither option rewrites these six rows. A re-ingest would. This slice does not re-ingest.

**Option A — do not raise rule 3 when the numero matched.** Against this corpus that is the six rows above, all already bound to the convenio the filename named. The four open ones are historical, so even a re-ingest that landed them `auto_proposed` would not answer in chat. The risk is the next file: a filename whose numero is a real convenio and whose sector word is a genuinely different sector would pass tagging with no review stop. This corpus has no such file. Those future files would sit `auto_proposed` with 0 chunks until someone confirms them (this job) or an operator runs `chunks:embed`.

**Option B — a reviewed alias list.** A PHP constant, not a table: `HOSTELERIA`, `DEPORTE`, `COEAS`. Rule 3 is skipped only when the parsed sector name is on that list. Any other disagreeing sector still blocks. Against this corpus the blast is the same six rows, because those three tokens are the whole set. The next unknown abbreviation still blocks. The same `auto_proposed` / not-embedded consequence applies, but only to the listed tokens.

**Option C — skip the conflict when the parsed token is contained in the registry sector name.** This does not cover the corpus. HOSTELERIA is contained in HOSTELERIA Y TURISMO HUESC (document 29 only). DEPORTE is not a substring of GESTIÓN DEPORTIVA or of INSTALACIONES DEPORTIVAS Y GIMNASIO. COEAS is not a substring of OCIO EDUCATIVO Y ANIMACION SOCIOCUL. Not recommended.

I have not chosen between A and B.

## 4. Files

No migration. No hr-ai change. `replace_document_chunks` is already the idempotent write.

| File | Change |
|---|---|
| `hr-backend/app/Services/DocumentEmbedder.php` | New. One document: scope array + `ExtractionClient::embed`. Refuses `under_review`. Does not query the global set. |
| `hr-backend/app/Jobs/EmbedDocument.php` | New. Eligibility gate, unique-per-document, shared overlap lock, `$timeout = 1200`, `$tries = 1`, `tag_events` + log. |
| `hr-backend/app/Console/Commands/ChunksEmbed.php` | The per-document body calls `DocumentEmbedder`. Selection, dry-run output, and the suspicious-document warning stay. |
| `hr-backend/app/Http/Controllers/Admin/DocumentController.php` | `confirm` dispatches after commit and returns `embed_queued`. `show` adds the computed `embed` object. `updateLifecycle` dispatches only if Decision D2 stands. |
| `hr-backend/tests/Feature/Slice12cEmbedOnConfirmTest.php` | New. Tests in §5. |
| `hr-frontend/src/pages/admin/DocumentDetailPanel.tsx` | Banner wrapper. Embed line, poll, retry label. |
| `hr-frontend/src/pages/admin/GroupsQueue.tsx` | Button classes. Reason via `t.groupsQueue.reasons[kind]`. |
| `hr-frontend/src/index.css` | `.notice--ai`, `.groups-queue` layout, `.node-head`, `.node-actions`. |
| `hr-frontend/src/i18n/en.ts` | Embed status strings. `groupsQueue.reasons`. |
| `hr-frontend/src/i18n/es.ts` | Same keys. |
| `hr-frontend/src/lib/api.ts` | `embed` on the document detail type. `embed_queued` on the confirm response. |
| `hr-frontend/src/i18n/groupReasons.test.ts` | New. Every visible kind has an en and an es string. |
| `hr-frontend/src/pages/admin/groupsQueueDisplay.test.ts` | New. Stylesheet contract for the banner and the groups pane, same approach as `panelSeparation.test.ts`. |

Not changed: `routes/api.php` (retry reuses `POST /documents/{uuid}/confirm`), `ExtractionClient` (900s stays), compose, queue config, `FactGroupBindingPlanner` (Spanish reasons stay for the API).

## 5. Tests

`Slice12cEmbedOnConfirmTest` (queue faked for dispatch assertions; the job's `handle` run against a mocked `ExtractionClient`):

1. **Returns without embedding.** Confirm of an eligible active convenio text dispatches `EmbedDocument` once, the response is `embed_queued: true`, and the client is not called.
2. **Not queued.** No dispatch for a salary document, a `reference_source`, a summary, a draft, a historical prose document, a prose document with no page text, or (D1) an `official_convenio` document with `convenio_id` null. An active `national_law` with text and a null convenio is queued. `partial_agreement` and `internal_hr_ruling` are queued when the other gates pass.
3. **One document, never the global set.** `handle` on one of three eligible documents calls `embed` once, with that document's id and the scope fields from `resolveScope`. The other two ids are absent.
4. **Safe to run twice.** `handle` twice calls `embed` twice with the same document id. There is no second write path in the job. The no-duplicate guarantee underneath is `replace_document_chunks` (delete then insert in one transaction); the staging proof checks the live count.
5. **`under_review` gate.** `handle` on a document still `under_review` does not call the client. Confirm sets `verified` before the job is pushed: after the controller returns, the saved document is `verified` and the pushed job is for that id.
6. **Unique and serial.** The job's `uniqueId` is the document id. The overlap middleware's lock name is the shared `embed-documents`, not the document id. A second dispatch while the first is queued pushes one job.
7. **Failure stays visible and is not retried.** A throwing client writes `tag_events` `facet=embed`, `new_value=failed`, `note` a code from the fixed set, and a log row with the exception. `$tries` is 1. The exception is not the card text.
8. **D2.** A lifecycle update to `active` on an already-verified eligible document dispatches the job. A lifecycle update that does not make it eligible does not.

Existing `Sprint7eOcrInvariantTest` dry-run helper keeps working because the command's selection line and its "selected for embedding" output stay.

Frontend:

- `groupReasons.test.ts`: the six kinds in §2(d) exist in both dictionaries and use the label placeholder.
- `groupsQueueDisplay.test.ts`: `.notice--ai` is `display: block`; `.groups-queue .two-pane` is a grid; `.groups-queue .pane-detail` sets `min-width: 0`, `overflow`, and a background; `.groups-queue .node-head` is flex with a gap.

`npx vitest run` and the backend feature test above are the local runs. The full backend suite is not required to approve the plan; it runs before any commit, which this slice does not make until you ask.

## 6. Proving it on staging

No `chunks:embed`. No second worker. No instance resize. The existing worker is what picks the job up.

The four unbound documents (14, 31, 55, 90) are the wrong proof: under D1 confirm does not embed them, and if it did, chat would not cite them. Documents 76 and 96 already answer.

Procedure:

1. Upload a one-page PDF through the admin ingest screen. The filename carries the numero of a convenio that already has a test employee, plus one of `COEAS` / `DEPORTE` / `HOSTELERIA`, so today's tagger (rule 3 still in place) parks it `under_review` with `convenio_id` set. The page contains one sentence that no other document contains.
2. As that test employee, ask that sentence. Record that the answer does not cite the new uuid.
3. Open the document and click Confirm tags. Do not run a command. The request returns, and the card shows "Embedding…".
4. Wait on the card. A one-page file should finish well under the 15 minutes the measured pace implies. If it is still "Embedding…" at 20 minutes, stop and report. Do not start another embed.
5. The card shows "Searchable · N chunks". Ask the same sentence again. The answer cites that document uuid, and nobody has run `chunks:embed`.
6. POST the same confirm endpoint a second time (the button is disabled once the card is searchable; the endpoint still accepts a verified document and queues again). When the card returns to "Searchable", N is unchanged.
7. Set that fixture's retrieval status to historical with the existing lifecycle control, so the test sentence leaves the employee path.

The empty list in §1.7 is the check that this proof did not quietly embed a backlog: before and after, the active + verified + prose + 0-chunk query still returns no rows other than the fixture while it is in flight.

## 7. Not in this slice

- Building option A or B. Waiting on the choice.
- Re-ingesting documents 29, 40, 73, or 87.
- Embedding any existing document on deploy.
- A migration, a second worker, a higher worker timeout, or a higher `retry_after`.
- Translating `diff.note`.
- Changing which documents `chunks:embed` selects when an operator runs it.
