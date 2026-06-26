# Sprint 7b-2 — The AI segmentation agent (Structured Reference Knowledge, the AI half) — PLAN

> Location: `hr-docs/sprints/sprint-07b-2/plan.md`
> Status: **plan for review — no segmentation code written this turn.** (Only this plan + the three fixtures copied into `sprint-07b-2/fixtures/`.)
> Read-first done (in full): the 7b-2 spec + kickoff; `roadmap.md` §Sprint 7b; the **7a** build (`ProposeDocumentTags` job, `TagProposalService`, hr-ai `/propose-tags`, `claude.propose_tags`, ADR-0020); the **7b-1** build (`reference_facts` table + model, `ReferenceFactController`, hr-ai `/read-structured`, the Knowledge-Center fact card, ADR-0021); ADR-0007/0011; the **real fixtures** (extracted paragraph-by-paragraph) and the convenio registry `01_listado_convenios.xlsx` (the closed vocabulary the agent must bind into).
> Scope guard: this is the **risky proposer**. The plumbing reuses 7a's proven propose-pattern and writes into 7b-1's proven `reference_facts`. **The eval against the three real fixtures is the deliverable.** No answering-from-facts (7c, 2b frozen); no conflict *resolution* (7d). Multi-value → one fact per scope; version → tag + flag, never resolve.

---

## 0. TL;DR — the one decision that makes or breaks this sprint

The entire substrate already exists and is tested. **The only new cognitive work is: read a multi-scope `reference_source` → split it into per-scope facts → bind each fact's scope to a real `convenio_id` (with optional `job_category_id`/`topic_id`).** Everything else (inert-until-verified, authority floor, routing, fuchsia, verify UI, the `tag_events` provenance, the logical key) is reused verbatim from 7a/7b-1.

The highest-leverage prompt detail (confirmed by reading the actual files): **scope is carried by a TERRITORY header and then a SECTOR header, and the value lines beneath inherit that scope until the next header. The agent must carry context down and RESET it on each new header.** Get this wrong and one mis-attribution becomes a confident, exact, wrong answer — the worst failure mode (COEAS Álava G1 = *5 meses* silently scoped to Andalucía, which is *6 meses*).

A second, non-obvious finding from reading the substrate: **`/read-structured`'s section split is style-dependent and unreliable across the two docx** (§2.4). The agent therefore must re-derive the TERRITORY→SECTOR→group hierarchy **from the text content itself**, not trust the reader's page/section boundaries. This shapes both the request shape and the prompt.

---

## 1. What exists (reality check — reuse vs new)

### 1.1 The 7a propose-pattern (the exact template — REUSE)

**The queued job** dispatched after ingest, defensive about the gate, never rethrows into ingest:

```26:59:hr-backend/app/Jobs/ProposeDocumentTags.php
class ProposeDocumentTags implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId) {}

    public function handle(TagProposalService $tagger): void
    {
        $document = Document::find($this->documentId);
        if ($document === null) {
            return;
        }
        // ... defensive gate check ...
        try {
            $summary = $tagger->propose($document);
            Log::info('tag proposal completed', ['document_id' => $document->id, 'summary' => $summary]);
        } catch (\Throwable $e) {
            // Never rethrow into ingest; the doc stays in the human queue.
            Log::warning('tag proposal job failed (doc left in human queue)', [...]);
        }
    }
}
```

**The persist service** — `TagProposalService` is the only writer; hr-ai returns an envelope, hr-backend writes append-only `ai_agent` provenance and never touches the authoritative FK columns:

```110:142:hr-backend/app/Services/TagProposalService.php
    private function persist(Document $document, array $result): array
    {
        $facets = $result['facets'] ?? [];
        // ...
        DB::transaction(function () use ($document, $facets, $topics, $rawUnmatched, $overall) {
            foreach ($facets as $f) {
                // ...
                TagEvent::create([
                    'entity_type' => 'document',
                    'entity_id' => $document->id,
                    'facet' => $f['facet'],
                    'old_value' => null,
                    'new_value' => $display,
                    'source' => 'ai_agent',
                    'actor_id' => null,
                    'confidence' => $f['confidence'] ?? null,
                    'note' => 'AI proposal (inert; awaiting human verify)',
                ]);
            }
```

**The closed candidate vocabulary** — hr-backend passes the closed lists so the model binds to real ids, never invents:

```248:261:hr-backend/app/Services/TagProposalService.php
    private function buildCandidateVocabulary(Document $document): array
    {
        return [
            'document_types' => DocumentType::orderBy('id')->get()->map(...)->all(),
            'territories' => Territory::orderBy('id')->get()->map(...)->all(),
            'sectors' => Sector::orderBy('id')->get()->map(...)->all(),
            'topics' => Topic::where('status', 'approved')->orderBy('name')->get()->map(...)->all(),
            'convenios' => $this->convenioShortlist($document),
        ];
    }
```

**The hr-ai side validates every returned id against the closed set** — a hallucinated id can never reach hr-backend (ADR-0011 by construction):

```692:722:hr-ai/app/providers/claude.py
        valid_ids: dict[str, set[int]] = {
            "convenio": {c.id for c in candidate_vocabulary.get("convenios", [])},
            "territory": {c.id for c in candidate_vocabulary.get("territories", [])},
            "sector": {c.id for c in candidate_vocabulary.get("sectors", [])},
        }
        # ...
        for f in envelope.get("facets") or []:
            # ...
            elif facet in ("convenio", "territory", "sector"):
                vid = f.get("value_id")
                if isinstance(vid, int) and vid in valid_ids[facet]:
                    facets.append({"facet": facet, "value_id": vid, "confidence": conf})
```

**The hr-ai endpoint never writes / never migrates** (ADR-0007); on provider failure it returns a 200 error envelope so the caller leaves the doc in the queue:

```300:318:hr-backend/app/Services/ExtractionClient.php
    public function proposeTags(int $documentId, string $pageText, array $candidateVocabulary, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)->acceptJson()
            ->post("{$this->base()}/propose-tags", [
                'document_id' => $documentId,
                'page_text' => $pageText,
                'candidate_vocabulary' => $candidateVocabulary,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);
        if (! $response->successful()) {
            return ['error' => 'propose_unavailable', 'detail' => "hr-ai /propose-tags failed ({$response->status()})"];
        }
        return $response->json();
    }
```

**7b-2 mirrors all five pieces** — a `SegmentReferenceSource` job, a `ReferenceFactProposalService` persist path, an `ExtractionClient::segmentFacts()` client method, an hr-ai `POST /segment-facts` endpoint, and a `claude.segment_facts` provider method with closed-vocab validation. This is **reuse of an established shape**, not new architecture.

### 1.2 The 7b-1 `reference_facts` shape + the reserved `ai_agent` lane (REUSE — the agent writes here)

The table already reserves the AI lane and the inert-until-verified state; the column **physically cannot hold** a higher authority (INVARIANT 1):

```59:74:hr-backend/database/migrations/2026_06_26_120001_create_reference_facts_table.php
            $table->enum('authority_level', ['structured_reference'])->default('structured_reference');
            // `ai_agent` is RESERVED but unwritten in 7b-1 (manual path only writer).
            $table->enum('source', ['admin_manual', 'ai_agent'])->default('admin_manual');
            $table->enum('status', ['needs_review', 'verified'])->default('needs_review');
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('source_locator')->nullable();
```

**The logical key 7b-1 recorded *for this sprint's upsert*** is a model constant:

```33:40:hr-backend/app/Models/ReferenceFact.php
    /** The only authority level a reference fact may express (INVARIANT 1). */
    public const AUTHORITY_LEVEL = 'structured_reference';

    /**
     * The logical-key columns the 7b-2 AI writer upserts on (see class docblock).
     */
    public const LOGICAL_KEY = ['convenio_id', 'topic_id', 'job_category_id', 'validity_start', 'validity_end'];
```

The manual create path is the exact template the AI persist path parallels (it just sets `source = ai_agent` and an `ai_agent` provenance row instead of `admin_manual`):

```86:103:hr-backend/app/Http/Controllers/Admin/ReferenceFactController.php
        $fact = DB::transaction(function () use ($data, $adminId) {
            $fact = ReferenceFact::create([
                'convenio_id' => $data['convenio_id'],
                'job_category_id' => $data['job_category_id'] ?? null,
                'topic_id' => $data['topic_id'] ?? null,
                'value' => $data['value'],
                'raw_values' => $data['raw_values'] ?? null,
                'validity_start' => $data['validity_start'] ?? null,
                'validity_end' => $data['validity_end'] ?? null,
                'authority_level' => ReferenceFact::AUTHORITY_LEVEL,
                'source' => 'admin_manual', // ai_agent reserved for 7b-2
                'status' => 'needs_review',  // inert until a human verifies
                'source_document_id' => $data['source_document_id'] ?? null,
                'source_locator' => $data['source_locator'] ?? null,
                'created_by' => $adminId,
            ]);
```

The verify action (`ReferenceFactController::verify`, lines 188–207) is unchanged — it is the human gate the agent must **never** call on its own output.

### 1.3 `/read-structured` — the content the agent consumes (REUSE)

hr-ai already reads docx (python-docx) + xlsx (openpyxl) into `{ format, pages:[{page_number,label,text,locator}] }`, writes nothing, never migrates:

```269:285:hr-ai/app/main.py
@app.post("/read-structured", dependencies=[Depends(require_internal_token)])
def read_structured_endpoint(req: ReadStructuredRequest) -> JSONResponse:
    """Read a non-salary .docx/.xlsx → structured per-section/per-sheet content
    (Sprint 7b-1, ADR-0021). hr-ai READS and RETURNS ... It does NOT
    segment or assign scope (that is the 7b-2 AI)..."""
    from .read_structured import read_structured
    try:
        result = read_structured(req.storage_key, req.document_uuid, req.format)
        return JSONResponse(result)
```

At ingest, the 7b-1 path already stored this content as display `document_pages` for a `reference_source` doc (and `ReferenceFactController::sourceContent` serves it). The agent consumes that same content.

### 1.4 The reality-check on the reader's section split (NON-OBVIOUS — shapes the design)

`_read_docx` splits sections **only at Heading-styled paragraphs**:

```89:97:hr-ai/app/read_structured.py
        style = (getattr(block.style, "name", "") or "")
        is_heading = style.lower().startswith("heading") or style.lower().startswith("título")
        if is_heading and current["lines"]:
            flush()
            current = {"heading": text, "lines": [text]}
        else:
            if is_heading:
                current["heading"] = text
            current["lines"].append(text)
```

But the two fixtures use **different styling** (verified by extracting `pStyle` directly, §2):
- `PERIODOS DE PRUEBA.docx` — territory headers are **`Título 1`** styled → the reader **does** split per territory.
- `PERÍODOS PRUEBA ACTUALIZADOS 2026.docx` — territory headers are **plain `Normal` paragraphs** (no heading style) → the reader returns the **whole file as essentially one section** (no per-territory boundaries).

**Consequence:** the agent must NOT depend on `pages[]`/`label` as a reliable scope structure. It must re-derive TERRITORY→SECTOR→group from the text. This is exactly why the header-carry instruction (not the reader) is the load-bearing piece. (We will pass the reader's `pages` for the `locator` anchors, but the prompt treats the concatenated text as the source of truth — see §3.)

### 1.5 The closed vocabulary the agent binds into (the registry — confirmed real)

`01_listado_convenios.xlsx` → 28 convenios, one per `(PROVINCIA, sector)`. The agent resolves each fixture scope to one of these `convenio_id`s. Cross-referenced spot-checks (this is what makes the eval scorable):

| fixture scope | registry convenio (numero) | notes |
|---|---|---|
| ESTATAL · COEAS | `OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL` ESTATAL `99100055012011` | **COEAS = "Ocio Educativo y Animación Sociocultural"** (IT-complement col literally says "COEAS …") |
| ÁLAVA · COEAS | `OCIO EDUCATIVO Y ANIMACION SOCIOCUL` ALABA `01100635012017` | distinct convenio from Estatal — the cross-province trap binds correctly here |
| ANDALUCÍA · COEAS | `OCIO EDUCATIVO Y ANIMACION ANDALUCIA` `71103505012022` | regional (prefix 71), per `TerritoryCatalog` |
| NAVARRA · Acción/Intervención Social | `ACCIÓN E INTERVENCIÓN SOCIAL` NAVARRA `31101815012021` | the version-trap convenio (G2 6→4) |
| NAVARRA · Hostelería | `HOSTELERIA NAVARRA` `31003805011981` | the multi-value trap (90/75/60) |
| GUIPUZCOA/GIPUZKOA · Intervención Social | `INTERVENCION SOCIAL GIPUZKOA` `20100025012011` | province-spelling variance resolves via aliases |
| VIZCAYA/BIZKAIA · Intervención Social | `INTERVENCION SOCIAL` VIZCAIA `48006185012006` | Vizcaya↔Bizkaia is a `TerritoryCatalog` alias |

The territory spelling variants are already covered by the controlled vocabulary's curated aliases:

```24:37:hr-backend/app/Support/TerritoryCatalog.php
            ['code' => '01', 'name' => 'Álava', 'level' => 'provincial', 'aliases' => ['Araba', 'Alava', 'ALABA', 'ARABA']],
            ['code' => '20', 'name' => 'Gipuzkoa', 'level' => 'provincial', 'aliases' => ['Guipúzcoa', 'Guipuzcoa', 'GUIPUZCOA']],
            // ...
            ['code' => '48', 'name' => 'Vizcaya', 'level' => 'provincial', 'aliases' => ['Bizkaia', 'Vizcaia', 'VIZCAIA']],
            ['code' => '71', 'name' => 'Andalucía', 'level' => 'regional', 'aliases' => ['Andalucia', 'ANDALUCIA']],
```

**Two binding gaps the eval will hit (real, must be handled as uncertainty, never guessed):**
1. **Job categories ("Grupo N") are mostly unseeded.** `convenio_job_categories` is populated **only from salary tables** — `registry:import` deliberately does not create them ("*Does NOT populate convenio_job_categories (deferred — comes from salary tables)*"). The periodo convenios have no salary import, so **`job_category_id` will usually be null** and the group identity (Grupo 1/2/3) must live in `value`/`raw_values`. This has a sharp consequence for the logical key (§7.1 — the central open question).
2. **Statutory-fallback scopes have no convenio.** The 2026 file's *"ÁMBITO ESTATAL (cuando no hay convenio territorial específico)"* and *"Consideración General (Estatuto de los Trabajadores)"* blocks are **not** convenio-scoped → no `convenio_id` exists → the agent **cannot place them** (scope rides the convenio, the known 7b-1 limitation). These must be **flagged uncertain / skipped**, never force-bound to a convenio.

### 1.6 Reuse vs new — the ledger

| Piece | Status | Detail |
|---|---|---|
| inert-until-verified spine (ADR-0020) | **reuse** | `status=needs_review`, human-only `verify()` |
| authority floor (INVARIANT 1) | **reuse** | enum + FormRequest 422; agent only ever proposes `structured_reference` |
| `document_type` routing (INVARIANT 2) | **reuse** | agent runs only on `reference_source`; never salary |
| `reference_facts` table + model + logical key | **reuse** | `ai_agent` lane lit for the first time |
| `tag_events` append-only provenance (`entity_type='reference_fact'`) | **reuse** | add `ai_agent` rows |
| `/read-structured` reader + display `document_pages` | **reuse** | the agent's input |
| fuchsia `--provenance-ai` token | **reuse** | first fact use (was always-false in 7b-1) |
| fact card + verify UI (`ReferenceFactPanel`) | **extend** | source-line, confidence, uncertainty, fuchsia |
| review queue (`ReviewQueuePage`) | **extend** | a "Reference facts" tab, uncertain-first |
| `EnsureCan` / `knowledge.edit` gating | **reuse** | verify stays gated |
| hr-ai `POST /segment-facts` | **NEW** | the cognitive endpoint (reads+returns) |
| `SegmentReferenceSource` job + auto-trigger | **NEW** | mirrors `ProposeDocumentTags` |
| `ReferenceFactProposalService` (persist + upsert) | **NEW** | mirrors `TagProposalService` |
| additive columns: `confidence`, `uncertainty`, `source_excerpt`, `proposal_batch_id`, `duplicate_of_id` | **NEW (additive)** | §6.1 |

---

## 2. The real fixtures — read and characterized

Extracted directly (paragraph index + `pStyle` shown). These are the ground truth for the segmentation prompt **and** the eval gold set.

### 2.1 `PERIODOS DE PRUEBA.docx` — 78 paragraphs, prose, no tables. The header-carry + cross-province + multi-value traps.

Structure: **`Título 1` = TERRITORY**, then a SECTOR header (sometimes `Subtítulo`, sometimes a plain paragraph), then `Párrafo de lista` value lines. Real lines:

```
  0 [Título1]| ESTATAL
  1 [Subtítulo]| ENSEÑANZA Y FORMACIÓN NO REGLADA
  2 [lista]| Grupos 1 y 2: Un mes
  ...
  7 [Subtítulo]| COEAS ESTATAL
  8 [lista]| Grupo 1: Seis meses          ← Estatal COEAS G1 = 6
 13 [Título1]| ALAVA
 14 | COEAS ALAVA                          ← SECTOR as a PLAIN paragraph (no style)
 15 [lista]| Grupo 1: Cinco meses         ← Álava COEAS G1 = 5   (THE CROSS-PROVINCE TRAP)
 19 [Título1]| ANDALUCÍA
 20 | COEAS ANDALUCÍA
 21 [lista]| Grupo 1: Seis meses          ← Andalucía COEAS G1 = 6
```

- **Header-carry**: `ESTATAL → ALAVA → ANDALUCÍA → GUIPUZCOA → VALENCIA → VIZCAYA → NAVARRA`. Each value line inherits the most-recent TERRITORY + SECTOR. **Reset on each header.**
- **The cross-province trap (the core risk)**: `COEAS Álava G1 = Cinco meses` (p.15) vs `COEAS Estatal/Andalucía G1 = Seis meses` (p.8/p.21). Same sector+group, different value by province. A single mis-attribution = a confident wrong exact answer.
- **Sector headers are not reliably styled** — `COEAS ESTATAL` is `Subtítulo` (p.7) but `COEAS ALAVA` (p.14), `COEAS ANDALUCÍA` (p.20), `LIMPIEZA` (p.27) are **plain paragraphs**. The agent cannot key sector detection on style; it must read "a short ALL-CAPS / sector-named line that introduces a group block."
- **The multi-value trap** (Navarra Hostelería) — one scope, contract-type breakdown, split across **four** lines here:

```
 61 | HOSTELERÍA NAVARRA
 62 [lista]| Grupo 1 y área cinco de Grupo 2:        ← compound group expression
 63 [lista]| Indefinido, fijos ordinarios y fijos discontinuos: 90 días
 64 [lista]| Temporal +3 meses: 75 días
 65 [lista]| Temporal –3 meses: 60 días
 66 [lista]| Grupo 2 excepto área cinco:
 67 [lista]| Indefinido, fijos ordinarios y fijos discontinuos: 60 días
```

  → **one fact** for `{Hostelería Navarra, Grupo 1 (+área 5 de G2)}` with `value = "90 días (indefinidos) / 75 (temporal >3m) / 60 (temporal ≤3m)"` and the breakdown in `raw_values`. **Not three facts.**
- **Compound group** `"Grupo 1 y área cinco de Grupo 2"` (p.62) and `"Grupo 2 excepto área cinco"` (p.66) do not map to a single `convenio_job_category` → `job_category_id = null`, group identity in `value`, **flag uncertain** on the scope-granularity.
- **Other multi-value**: Navarra Acción e Intervención Social `Grupos 1 y 2: Seis Meses` (p.51) — a group *range* in one line.

### 2.2 `PERÍODOS PRUEBA ACTUALIZADOS 2026.docx` — 95 paragraphs. The version trap.

**Structurally different from file 1** (the §1.4 finding): TERRITORY headers are **plain paragraphs** (no `Título` style); SECTOR headers are list paragraphs ending in a colon; values are **numeric** ("5 meses", "90 días …") and multi-value lives on **one line**. Real lines:

```
  0 | ÁLAVA                                            ← plain paragraph (no heading style!)
  2 [lista]| Ocio Educativo y Animación Sociocultural:  ← "COEAS" spelled out
  3 [lista]| Grupo 1: 5 meses.                          ← Álava COEAS G1 = 5 (matches file 1)
  8 | NAVARRA
 20 [lista]| Intervención Social:
 21 [lista]| Grupo 1: 6 meses.
 22 [lista]| Grupo 2: 4 meses.                          ← CHANGED: file 1 had "Grupos 1 y 2: Seis Meses"
 10 [lista]| Grupo 1 (todas las áreas) y Grupo 2 (área 5): 90 días (indefinidos), 75 días (temporales > 3 meses) y 60 días (temporales hasta 3 meses).   ← multi-value on ONE line
 28 | GIPUZKOA                                          ← spelling variance vs file 1 "GUIPUZCOA"
 43 | BIZKAIA                                           ← spelling variance vs file 1 "VIZCAYA"
 46 | COMUNIDAD DE MADRID                               ← territory only in file 2
 71 | CANTABRIA                                         ← territory only in file 2
 86 | ÁMBITO ESTATAL (CUANDO NO HAY CONVENIO TERRITORIAL ESPECÍFICO)   ← NOT convenio-scoped
 94 | Consideración General (Estatuto de los Trabajadores): ...        ← statutory fallback, no convenio
```

- **The version trap (genuinely-changed value)**: `Navarra · Intervención Social · Grupo 2` = *Seis Meses* (file 1, in "Grupos 1 y 2") → **4 meses** (file 2, p.22). Same logical scope, different value → the **obvious-duplicate flag** must fire (signal, not resolution — 7d).
- **Province-spelling variance**: `GUIPUZCOA` (file 1) vs `GIPUZKOA` (file 2); `VIZCAYA` (file 1) vs `BIZKAIA` (file 2) — both resolve to the same territory via `TerritoryCatalog` aliases, so the **convenio binds identically across versions** (which is what makes the duplicate detectable).
- **Different territory coverage**: file 2 adds Madrid, Cantabria, Castilla y León (Salamanca), Ámbito Estatal; file 1 has Valencia/Vizcaya coverage file 2 phrases differently. Validity tagging (file 2 → 2026) keeps both sets distinct.
- **Naming variance in sectors**: file 1 `GESTIÓN DEPORTIVA NAVARRA` vs file 2 `Entidades Privadas Gestoras de Servicios Deportivos` (both → `GESTIÓN DEPORTIVA NAVARRA` `31008235012003`). The agent must alias-resolve sector names, not string-match.
- **Unbindable**: p.86 (`ÁMBITO ESTATAL …no hay convenio…`) and p.94 (`Estatuto de los Trabajadores`) → no convenio → **flag uncertain / skip** (§1.5 gap 2).

### 2.3 `Tablas acuerdo parcial_Alhambra.xlsx` — sheet `Convenios`, 715×28. The routing test.

This is **overwhelmingly a salary workbook**: per-convenio blocks (`COEAS Estatal`, `COEAS Álava`, `COEAS Navarra`, `COEAS Madrid I/II`, `COEAS Andalucía I/II`, …), each a `GRUPO / CATEGORÍA / year-columns / €/h` salary grid. Real lines:

```
  1 | NUEVO SMI 2025 | 16576 | SMI: | 13300 | 13510 | 14000 | 15120 | 15876 | 16576
  4 | Ámbito temporal: vigencia del 01/10/2020 al 31/12/2023 | hs/año: | 1742
  5 | hs/sem: | 38.5
  9 | GRUPO | CATEGORÍA | COEAS II (2016) | 01/10/20 - 01/10/21 | ... | €/h (actual) | ...
 10 | Grupo I: personal directivo | Director/a gerente | 20092.1 | 20998 | 21217.43 | ...
 51 | COEAS Álava
 108| COEAS Navarra
 237| COEAS Andalucía
```

- **The routing invariant (INVARIANT 2)**: tagged `reference_source` → the reference path; **zero `salary_table_rows`**. The agent must **not** turn `SMI 2025: 16576` or the year/€-h grid into salary, and must not emit salary-shaped facts.
- **Expected agent behaviour here = near-silence.** Almost every cell is salary (out of scope for this agent). The only legitimately non-salary reference facts derivable are **jornada** (`hs/año: 1742`, `hs/sem: 38.5`) and **vigencia windows** per convenio block — and even those are borderline. **The success criterion for this fixture is: zero salary rows, and either zero facts or a handful of correctly-scoped jornada/vigencia facts — never a salary figure dressed as a fact.** This is the cleanest demonstration that routing rides the tag, not the content.

---

## 3. The segmentation agent (the new cognitive work)

### 3.1 Division of labour (ADR-0007 — identical posture to 7a)

```
reference_source ingested (7b-1)                  hr-ai POST /segment-facts (NEW, no migration)
─ stored + /read-structured → document_pages  ──▶ reads the pages text it ALREADY has
        │ (auto-trigger, queued)                   + the CLOSED candidate vocabulary
        ▼                                           returns an ARRAY of proposed facts
 SegmentReferenceSource job ──▶ ReferenceFactProposalService (hr-backend — NEW)
                                  upserts reference_facts on the LOGICAL KEY,
                                  source=ai_agent / status=needs_review,
                                  + ai_agent tag_events, + duplicate flag
                                  ── never answerable; never salary; never mints vocab ──
```

### 3.2 hr-ai `POST /segment-facts` (reads + returns, writes nothing, never migrates)

Request (mirrors `/propose-tags`: key in body, internal-token, scoped closed vocabulary):

```jsonc
{
  "document_id": 123,
  "document_uuid": "…",
  "source_format": "docx",                 // routes the prompt framing (prose vs grid)
  "pages": [ { "page_number", "label", "text", "locator" } ],   // from /read-structured
  "candidate_vocabulary": {
    "convenios":  [ { "id", "numero", "name", "aliases",
                      "territory": { "id","name","aliases" },
                      "sector":    { "id","name","aliases" },
                      "job_categories": [ { "id","name","group_code" } ] } ],
    "topics": [ { "id", "name" } ]         // approved topics only (e.g. "periodo de prueba")
  },
  "provider_api_key": "…",                 // per call, never stored/logged (ADR-0015)
  "provider_config": { "provider","model","endpoint" }
}
```

> Note the **convenio-centric** vocabulary shape (vs 7a's flat lists): because scope *rides the convenio*, the model must pick a `convenio_id` whose `(territory, sector)` matches the carried header — so each convenio carries its derived territory+sector+job_categories inline. hr-backend builds this; the model binds to `convenio.id` (and optionally a nested `job_category.id`).

Response — an **array of proposed facts**, each self-describing:

```jsonc
{
  "facts": [
    {
      "convenio_id": 42,                   // bound (territory/sector derive — not sent back)
      "job_category_id": null,             // bound IF a real category matches the group, else null
      "topic_id": 5,                       // "periodo de prueba" (approved topic) or null
      "value": "Grupo 1: 5 meses",         // the human-readable rule for this scope
      "raw_values": { "group": "Grupo 1", "by_contract_type": {…}, "source_text": "Grupo 1: Cinco meses" },
      "validity_start": null, "validity_end": null,   // from the source's own version (§3.5)
      "confidence": 0.83,                  // the agent's scope-assignment confidence
      "uncertainty": null,                 // or { "field":"scope", "reason":"compound group 'área cinco de G2'" }
      "source_locator": "section:2",       // the reader locator
      "source_excerpt": "ALAVA › COEAS ALAVA › Grupo 1: Cinco meses"  // the EXACT line, for the reviewer
    }
  ],
  "trace_fragment": { "model":"…", "notes":"…" }
}
```

On provider/parse failure → `{ "facts": [], "error": "provider_error" }` (200) so the doc just stays unsegmented in the queue (the 7a fail-safe, `claude.propose_tags` lines 676–690). hr-ai validates **every** `convenio_id`/`job_category_id`/`topic_id` against the closed set before returning (the §1.1 `claude.py` 692–722 discipline, extended to per-convenio job categories) — a hallucinated id can never reach hr-backend.

### 3.3 The prompt strategy (the substance — the header-carry instruction is the highest leverage)

System prompt, in Spanish, encoding the real structure read in §2:

1. **Header-carry (THE rule).** "The document is organized as TERRITORY headers, then SECTOR headers, then value lines. Each value line's scope is the **most-recently-seen TERRITORY + SECTOR**. **Reset SECTOR on each new SECTOR header and reset both on each new TERRITORY header.** Do not let a value line inherit a province from a previous block." (This is the single instruction most cascading errors come from — §0.)
2. **Headers are not reliably styled** — recognize a TERRITORY by name (a Spanish province/region, possibly ALL-CAPS, possibly with spelling variants) and a SECTOR by name (a known sector or a short line introducing a `Grupo …` block), **from the text**, because the reader's section split is unreliable (§1.4).
3. **Bind, never mint (ADR-0011).** Resolve `(carried territory, carried sector)` to exactly one `convenio_id` in the candidate list (match on name + aliases; `COEAS` ≡ "Ocio Educativo y Animación Sociocultural"; Gipuzkoa≡Guipúzcoa; Bizkaia≡Vizcaya). **If no convenio matches (e.g. a statutory-fallback block), emit the fact with the scope you can determine and `uncertainty.field="scope"` — or omit it — but NEVER force a wrong convenio.**
4. **One fact per scope (multi-value rule).** A `Grupo X` block (even when its contract-type values span several lines, file 1 p.62–65) is **one** fact; put the breakdown in `value` + `raw_values`. A group *range* ("Grupos 1 y 2", "Grupos 3,4,5 y 6") is one fact for that range (job_category null, range in value).
5. **Job category.** Bind `job_category_id` only when a candidate category clearly matches the group; otherwise leave it null and keep the group label in `value`/`raw_values` (categories are mostly unseeded — §1.5).
6. **Err low on authority, flag on uncertainty (the roadmap rule).** Authority is fixed (`structured_reference`, hr-backend forces it). Where scope is unclear, **flag uncertain rather than guess** — a flagged-uncertain fact is a success (the human decides); a confidently mis-scoped fact is the failure.
7. **Source excerpt is mandatory** — every fact carries the exact source line(s) with its header trail, so the reviewer checks "did the source really say Álava?" against the quote (§4).
8. **No salary** — ignore wage/€-hour/SMI grids entirely (the xlsx); jornada (`hs/año`, `hs/sem`) and vigencia windows are the only non-salary reference facts permissible there, and only when confidently convenio-scoped.

The **user prompt** concatenates the pages text with explicit `[locator]` markers so the model can cite `source_locator`. We deliberately feed the **full concatenated text** (not per-section calls) so the header-carry reset logic has the whole sequence — the files are small (78/95 paragraphs).

### 3.4 hr-backend persist — `ReferenceFactProposalService` (the only writer; upsert on the logical key)

Mirrors `TagProposalService` (§1.1). For each returned fact, inside one transaction:
- **Upsert on `ReferenceFact::LOGICAL_KEY`** `(convenio_id, topic_id, job_category_id, validity_start, validity_end)` scoped to `source='ai_agent'` and the **same source document** — re-running the agent on the same file is idempotent (no duplicate proposals). `updateOrCreate` keyed on the logical key (the salary `SalaryImport` idempotency posture, generalized).
- Set `source='ai_agent'`, `status='needs_review'`, `authority_level=structured_reference` (forced — INVARIANT 1), `source_document_id`, `source_locator`, plus the new additive columns `confidence`, `uncertainty`, `source_excerpt`, `proposal_batch_id` (§6.1).
- Append an `ai_agent` `tag_events` row (`entity_type='reference_fact'`, `facet='reference_fact'`, `note='AI segmented (inert; awaiting human verify)'`, `confidence` set) — the append-only provenance, exactly the `TagProposalService` shape.
- **Obvious-duplicate flag** (§3.5): if a fact's logical key collides with an existing fact **of a different `value`** (a different source version), set `duplicate_of_id` + an `uncertainty.field="version"` marker and a `tag_events` note. **Flag only — never resolve, never delete, never pick a winner** (7d).
- **Never** touch the answer engine; **never** write a salary row; **never** call `verify()`; **never** mint vocabulary (every id was closed-set-validated by hr-ai).

INVARIANT-by-construction: `status` is hardcoded `needs_review`; the only writer of `verified` is the human `ReferenceFactController::verify()`. `authority_level` is forced to the floor. There is no code path from this service to `salary_table_rows`.

### 3.5 Version / validity (propose-with-source-validity; flag, don't resolve)

- Each fact is tagged with **its source file's validity**. hr-backend resolves the validity from the source `documents` row (the human sets it at ingest, e.g. the 2026 file → 2026 validity); the agent does not invent dates. If the source has no validity set, `validity_start/end` stay null and the duplicate flag relies on `(convenio,topic,job_category)` + value difference.
- **Obvious-duplicate detection = logical-key collision with a different value.** Example the eval will exercise: Navarra Intervención Social G2 = *6 meses* (file 1) and *4 meses* (file 2) bind to the **same** `convenio_id 31101815012021` + topic *periodo de prueba* + (job_category null) — a logical-key match with a differing value → flagged as a possible version/update. **This is a signal surfaced to the human, not a resolution.** Semantic comparison / version-wins is **7d**; we do not build conflict detection twice.

### 3.6 The queued job + auto-trigger

- `SegmentReferenceSource implements ShouldQueue` (mirrors `ProposeDocumentTags`, §1.1) — `tries=1`, defensive (re-checks the doc is still `reference_source` and re-reads content), never rethrows into ingest.
- **Auto-trigger**: in `DocumentIngestor`, after the commit, when a `reference_source` doc finishes ingest (and its `document_pages` are stored), dispatch `SegmentReferenceSource($documentId)`. (7b-1 deliberately *skipped* the 7a unresolved-auto-propose for reference sources because they are multi-scope; 7b-2 adds this dedicated trigger.)
- A manual **"Re-segment"** admin action (`POST /admin/reference-sources/{uuid}/segment`, gated `knowledge.edit`) re-runs the same job (idempotent via the upsert) — the eval harness uses this to re-run after prompt iteration.

---

## 4. The review UX (the safety is the UX)

Extend the 7b-1 `ReferenceFactPanel` + add a fact-review tab to the 7a `ReviewQueuePage` (which today has `tagging | vocabulary | expiry`, lines 16–34). **Nothing about the verify mechanism changes** — `verify()` is the unchanged human gate.

### 4.1 The fact card additions (the structural defense against rubber-stamping)
- **The source line, inline.** Render `source_excerpt` (the exact quoted line with its `ALAVA › COEAS ALAVA › Grupo 1: …` header trail) prominently in the card. The reviewer confirms "did the source really say Álava?" against the quote — not by re-reading the file. (Today the card shows a source *link*; the agent path adds the source *line*.)
- **Assigned scope, confidence, uncertainty.** Show the bound convenio + derived territory/sector (the existing `Scope` section, `ReferenceFactPanel` 123–143) plus a `confidence` chip and, when set, the `uncertainty` reason ("compound group", "scope unclear", "possible version of #N").
- **Fuchsia until verified.** `is_ai_proposed = source==='ai_agent' && status==='needs_review'` (already computed in `ReferenceFactController::card`, line 334 — always false in 7b-1, **now lit**). Apply the `--provenance-ai` accent (the token already exists: `index.css` `--provenance-ai: #e879f9`). On verify → reverts to normal; the AI origin persists only as the `ai_agent` dot in the provenance timeline (the existing `.timeline-dot.src-ai_agent`).
- **Verify / fix-then-verify / reject.** Verify = existing button. Fix-then-verify = the existing bounded edit (scope edits hit the 409 `confirm_scope_change` gate, `ReferenceFactPanel` `FactEditForm`) then verify. **Reject** = a new action that discards the proposal (soft-delete or `status='rejected'` — see §7) with an appended `tag_events` note; it never silently disappears.

### 4.2 The queue (uncertain-first)
- A new **"Reference facts"** tab in `ReviewQueuePage`: `reference_facts` where `source='ai_agent' AND status='needs_review'`, **sorted uncertain-first** (uncertainty set, then ascending `confidence`) — the ones most needing human judgement at the top. Duplicate-flagged facts surface their `duplicate_of` sibling for side-by-side comparison.
- Fuchsia rows; each row shows convenio/territory/sector, the value, confidence, and the uncertainty/duplicate flags.

---

## 5. The eval methodology (THE deliverable — first-class, not an afterthought)

The sprint's primary output is **`hr-docs/sprints/sprint-07b-2/review.md` containing a measured accuracy report**, not a pass/fail. Fixtures live in `hr-docs/sprints/sprint-07b-2/fixtures/` (already copied this turn: the two docx + the xlsx).

### 5.1 How the agent is run
1. Ingest each fixture as a `reference_source` (file 1 → validity e.g. pre-2026; file 2 → 2026; xlsx → its window) → `/read-structured` → `document_pages`.
2. Auto-trigger (or "Re-segment") → `/segment-facts` → `ReferenceFactProposalService` persists `ai_agent`/`needs_review` facts.
3. Score the persisted facts against a **hand-built gold set** derived from §2 (the per-scope correct value + validity, enumerated from the real lines + the registry mapping in §1.5).

### 5.2 The accuracy table (per docx) — the deliverable shape

| Fixture | Facts proposed | Correct (scope + value + validity) | Mis-scoped | Correctly flagged-uncertain | Missed (gold not proposed) |
|---|---|---|---|---|---|
| `PERIODOS DE PRUEBA.docx` | … | … | … | … | … |
| `PERÍODOS …2026.docx` | … | … | … | … | … |
| `Tablas …Alhambra.xlsx` | (expect ~0 facts) | — | — | — | **0 salary rows = pass** |

Plus a **mis-scoped enumeration** (the heart of the report): for each error — *which source line, what scope the agent assigned, what was correct*. E.g. "p.15 `Grupo 1: Cinco meses` → assigned `COEAS Andalucía` (wrong); correct `COEAS Álava`." This is what the reviewer reads.

Targeted spot-checks (the eyes-on traps) reported explicitly:
- **Cross-province**: COEAS Álava G1 fact = *5 meses* scoped to Álava; COEAS Andalucía/Estatal G1 = *6 meses* scoped correctly. (The single most important check.)
- **Multi-value**: Navarra Hostelería G1 is **one** fact with the 90/75/60 breakdown inside, not three.
- **Compound group**: "Grupo 1 y área cinco de Grupo 2" → job_category null + flagged uncertain (not silently mis-bound).
- **Version**: file 2's Navarra Intervención Social G2 = 4 meses is **flagged as a possible duplicate/version** of file 1's 6 meses, not silently doubled.
- **Routing**: the xlsx yields reference facts only and **zero salary rows**.
- **Unbindable**: the 2026 statutory-fallback blocks are flagged uncertain / skipped, not force-bound.

### 5.3 The judgement: "human-catchable" vs "drowning"
The explicit acceptance lens (from the spec): a high mis-scope rate that the **review UX makes catchable** (every error surfaces with its source line + low confidence, reviewer rejects in seconds) is **acceptable** for a human-gated tool. A mis-scope rate where errors are **confident and plausible** (high confidence, no uncertainty flag, would be rubber-stamped) is the **failure signal → iterate** the prompt / the candidate-vocabulary context / the header-carry instruction *before* declaring done. The report states which regime we are in, with the numbers. Iterating against this eval is **expected, not a failure**, for this one sprint.

---

## 6. Migrations & build order

### 6.1 Additive migrations (hr-backend only — ADR-0007)

**One migration: `add_segmentation_fields_to_reference_facts`** — additive columns on `reference_facts`:

| column | type | why |
|---|---|---|
| `confidence` | `decimal(4,3)` null | the agent's scope-assignment confidence (queue sort) |
| `uncertainty` | `jsonb` null | `{ field, reason }` — the flag-uncertain signal (sorts first) |
| `source_excerpt` | `text` null | the exact source line(s) + header trail (the review-UX defense) |
| `proposal_batch_id` | `uuid` null | groups one agent run for the eval + re-segment idempotency |
| `duplicate_of_id` | `foreignId → reference_facts` null `nullOnDelete` | the obvious-duplicate flag (signal only — 7d resolves) |

- **No new authority/source/status values** — `ai_agent` and `needs_review` already exist in the enums (§1.2). Adding a `rejected` status (for the reject action, §7) is a small enum alter if chosen — flagged as an open question (alternative: a `rejected_at` timestamp / soft delete, no enum change).
- **No hr-ai migration** — `/segment-facts` is read-and-return (ADR-0007).
- **No change** to the logical key columns themselves (they exist); see §7.1 for the null-`job_category` collision question.

### 6.2 Build order
1. **hr-ai** — `POST /segment-facts` in `main.py`; `claude.segment_facts` (the §3.3 prompt + closed-vocab validation extended to per-convenio job categories); the `SegmentFactsRequest` model + a `base.py` interface/dataclass (mirror `propose_tags`). Writes nothing.
2. **hr-backend** — the additive migration (§6.1); `ExtractionClient::segmentFacts()`; `ReferenceFactProposalService` (build candidate vocabulary convenio-centrically; call hr-ai; **upsert on the logical key**; append `ai_agent` provenance; duplicate flag).
3. **hr-backend** — `SegmentReferenceSource` queued job + the `DocumentIngestor` auto-trigger for `reference_source`; the manual `POST /admin/reference-sources/{uuid}/segment` (gated `knowledge.edit`); the reject endpoint.
4. **hr-frontend** — extend `ReferenceFactPanel` (source line, confidence, uncertainty, fuchsia, reject); add the "Reference facts" uncertain-first tab to `ReviewQueuePage`; `api.ts` types/calls.
5. **Eval** — run on the three fixtures, build the gold set from §2, produce the accuracy table; **iterate the prompt against it**.
6. **Tests** — a `Sprint7b2SegmentationInvariantTest` re-proving (not rebuilding) the inherited invariants with a **stubbed** provider returning a fixed facts envelope: (1) AI facts land `ai_agent`/`needs_review`/not-verified; (2) authority can only be `structured_reference`; (3) a `reference_source` segmentation writes **zero** `salary_table_rows`; (4) re-running is idempotent (upsert on the logical key); (5) the agent never calls verify; (6) a logical-key collision with a different value sets `duplicate_of_id` (flag, not merge).
7. **Docs** — `architecture.md`, `data-model.md` (the now-written `ai_agent` lane + the additive fields), `roadmap.md` (7b-2 done → **7b complete**; reaffirm 7c/7d/7e), the ADR (§6.3), and `review.md` (build + **the eval accuracy report** + eyes-on). **Stop — no commit until reviewed.**

### 6.3 ADR — warranted? **Yes, a short one (ADR-0022).**
A short ADR beside ADR-0021 is justified: it records **the segmentation agent + the multi-value/version boundary** — the decisions future sprints lean on: (a) **one fact per scope** (the answerable unit is the scope; multi-value breakdown lives inside `value`/`raw_values`); (b) **propose-with-source-validity + flag-obvious-duplicates, resolution deferred to 7d**; (c) the **header-carry segmentation contract** + the **upsert-on-logical-key** idempotency; (d) reaffirming the inherited invariants are *re-proven, not rebuilt*. Proposed: **ADR-0022 — The reference-fact segmentation agent (one-fact-per-scope; source-validity + duplicate-flag; resolution is 7d).** It cites ADR-0007/0011/0020/0021.

---

## 7. Assumptions & open questions

1. **The logical-key / null-`job_category` collision (the most important).** Groups ("Grupo 1/2/3") mostly can't bind to a `convenio_job_category` (categories are salary-derived and unseeded — §1.5), so `job_category_id` is usually null. But the logical key is `(convenio_id, topic_id, job_category_id, validity_start, validity_end)` — so **two different group-facts for the same convenio+topic+validity collide on the key** (all have job_category null), and the upsert would clobber one with the other. **Recommendation:** treat the group label as part of identity by binding `job_category_id` when possible **and** adding the group token to the upsert match (e.g. a `raw_values.group` discriminator, or a derived `scope_key`); the cleanest additive option is a nullable `group_label` column folded into the de-dup match. **This must be resolved before the persist path is written** — it directly determines whether the agent can store all of file 1's per-group facts. Flagged as the #1 thing to confirm.
2. **Scope resolution to FK ids — who binds?** Recommendation: the **model binds** (returns `convenio_id` from the candidate list, validated against the closed set hr-ai-side, exactly like 7a), because the territory/sector→convenio mapping needs the COEAS≡Ocio-Educativo and alias reasoning. Alternative: hr-ai returns territory+sector names and hr-backend resolves the convenio deterministically (`VocabularyResolver`). Recommend model-binds-with-closed-set-validation (proven in 7a); revisit if the eval shows binding errors a deterministic resolver would avoid.
3. **Confidence + uncertainty representation.** Recommendation: a single `confidence` decimal (queue sort) + a structured `uncertainty` jsonb `{ field, reason }` (so "scope unclear" vs "compound group" vs "possible version" are distinguishable and sortable). Confirm vs a flat boolean.
4. **Obvious-duplicate detection scope.** Recommendation: logical-key collision **with a differing `value`**, across the same convenio+topic+(group), flagged via `duplicate_of_id` + `uncertainty.field='version'`. It is a **signal**, not resolution (7d). Confirm we do not want any value-comparison beyond exact-key + different-value here.
5. **Reject representation.** Recommendation: add a `rejected` status (small enum alter) so a rejected proposal is auditable and excluded from the queue without deletion; alternative is a `rejected_at` soft-delete (no enum change). Confirm preference.
6. **Topic resolution.** All fixture facts are *periodo de prueba*. Recommendation: bind `topic_id` to the approved "periodo de prueba" topic when present; if that topic isn't approved yet, leave `topic_id` null (the agent never mints a topic — ADR-0011) and flag. Confirm the topic exists/should be seeded (additive) for the eval.
7. **Validity source.** Recommendation: hr-backend derives `validity_start/end` from the source `documents` row (human-set at ingest); the agent does not parse dates from prose. Confirm (the alternative — the agent proposing validity from text like "vigencia 01/10/2020 al 31/12/2023" in the xlsx — is more error-prone and is deferred).
8. **xlsx expected output.** Recommendation: the Alhambra xlsx should yield **zero or a small handful** of jornada/vigencia facts and **zero salary rows**; treat any salary-shaped fact as an eval failure. Confirm the bar (zero facts is an acceptable, even preferred, result for the routing test).
9. **Full-text vs chunked prompt.** Recommendation: feed the **full concatenated** pages text (78/95 paragraphs is small) so the header-carry reset logic sees the whole sequence. Confirm (a per-section call would break header-carry, given §1.4).
10. **`/read-structured` reliance.** Confirmed (§1.4) the section split is style-dependent; the agent re-derives hierarchy from text. No change to the reader is needed this sprint, but flagged: if the eval shows the reader's flattening of file 2 loses paragraph boundaries the agent needs for `source_locator`, a small additive reader improvement (emit per-paragraph locators) may be warranted — out of scope unless the eval demands it.

---

## 8. Hard-constraint compliance check

- **Inherited safety net preserved, not rebuilt.** AI facts land `ai_agent`/`needs_review`/not-answerable (status hardcoded; verify is human-only); authority only `structured_reference` (enum + 422); zero salary rows (no code path to `salary_table_rows`; routing rides the `reference_source` tag); no vocabulary minted (closed-set id validation, hr-ai-side); fuchsia until verified (`is_ai_proposed` lit); the agent never calls `verify()`. ✔
- **hr-ai reads/returns, never migrates (ADR-0007); hr-backend owns all writes + schema; additive migrations only.** `/segment-facts` writes nothing; one additive migration. ✔
- **No answering-from-facts (7c); 2b frozen.** No retrieval/answer-engine change. ✔
- **No semantic-conflict resolution (7d).** Propose-with-source-validity + flag-obvious-duplicates only (`duplicate_of_id`, no winner picked). ✔
- **Multi-value → one fact per scope; version → tag + flag, don't resolve.** ✔ (§3.3 rule 4, §3.5)
- **The eval is the deliverable.** The accuracy report (per-fixture table + mis-scoped enumeration + catchable-vs-drowning judgement) is a first-class, primary output in `review.md`; fixtures live in `sprint-07b-2/fixtures/`. ✔
- **Reuse 7a propose-pattern + 7b-1 reference_facts/review UI + design system + `EnsureCan` + append-only `tag_events`.** Every new piece mirrors an existing one (§1.6). ✔

---

**Status: ready for review.** No segmentation code written this turn. Only this plan and the three fixtures (copied into `hr-docs/sprints/sprint-07b-2/fixtures/`). On approval, build in the §6.2 order, then produce `review.md` with the eval accuracy report and stop for eyes-on — **no commit until reviewed.**
