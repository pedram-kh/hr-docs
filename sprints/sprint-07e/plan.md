# Sprint 7e — Plan: OCR for scanned documents

> Location: `hr-docs/sprints/sprint-07e/plan.md`
> Spec: `sprint-07e-spec.md` · Kickoff: `sprint-07e-kickoff-prompt.md` · Roadmap: `roadmap.md:159-165` · Cross-ref: `deploy.md` §4a/§5
> **Status: plan only — no OCR code, no engine chosen. Every claim below is cited to a real line in the current tree or a live query against staging.**

**Housekeeping done first.** `main`'s two committed-but-undeployed fixes (`otp.sh` file-mode, `FenceCalibrateSemantic`) are deployed to staging via `deploy.sh`/`deploy-run.sh`; health confirmed. ADR-0025 (`staging-deploy-topology`) already exists from that work, so this sprint's ADR is **0026** (confirmed by listing `hr-docs/architecture/decisions/` — 0001–0025 are taken).

**The thesis in one line.** 7e adds exactly one fallback — OCR a text-less page at `/extract` time — and everything downstream must still work through the **existing** post-extraction pipeline. The one fact that makes this non-trivial, found by reading the real code rather than assuming it: **`hr-ai POST /embed` never reads `document_pages.text`.** It re-extracts column geometry straight from the original PDF bytes (`pipeline.py:23`, `extract_columns.py:166-201`). A scanned page has zero PyMuPDF text blocks, so writing OCR text into `document_pages.text` alone would satisfy the citation/viewer surface and (as a side effect) unblock Sprint-7a tagging — but would do **nothing** for chunking/embedding. The narrow integration problem this plan solves is therefore not "where do I put OCR text" but "how does OCR text reach the one pipeline that doesn't read `document_pages` at all."

---

## 1. What exists (reality check)

### 1.1 `/extract` — the real PyMuPDF flow, verbatim

```1:51:hr-ai/app/extract.py
"""PDF extraction: per-page text + per-page image (ADR-0010).

Input is the S3 key of an uploaded original PDF. We read it from S3, extract
text per page and render each page to a JPEG written back to S3, and return the
per-page data. hr-backend writes the documents/document_pages rows from the
response — hr-ai never touches the database.

Sprint 1 is PDF-only. Image-only (scanned) pages yield empty text (no OCR this
sprint); the page image is still produced so the source view works, and
hr-backend visibly flags a document whose text is entirely empty.
"""

import fitz  # PyMuPDF

from .config import settings
from .storage import get_object_bytes, put_object_bytes


def page_image_key(document_uuid: str, page_number: int) -> str:
    return f"documents/{document_uuid}/pages/{page_number:04d}.jpg"


def extract_pdf(storage_key: str, document_uuid: str) -> dict:
    pdf_bytes = get_object_bytes(storage_key)
    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    try:
        zoom = settings.extract_image_dpi / 72.0
        matrix = fitz.Matrix(zoom, zoom)

        pages = []
        for index in range(doc.page_count):
            page = doc.load_page(index)
            page_number = index + 1

            text = page.get_text("text") or ""

            pixmap = page.get_pixmap(matrix=matrix)
            image_key = page_image_key(document_uuid, page_number)
            put_object_bytes(image_key, pixmap.tobytes("jpeg"), "image/jpeg")

            pages.append(
                {
                    "page_number": page_number,
                    "text": text.strip(),
                    "image_key": image_key,
                }
            )

        return {"page_count": doc.page_count, "pages": pages}
    finally:
        doc.close()
```

The doc string at `:7-9` already names this sprint's job in one sentence, written back in Sprint 1. Concretely:
- **Text layer:** `page.get_text("text")` — a scanned page returns `""`.
- **Page image:** JPEG at `extract_image_dpi = 150` (`hr-ai/app/config.py:42`), written to S3 at `page_image_key` = `documents/{uuid}/pages/{page:04d}.jpg`. This is **exactly** the S3 object the viewer/citation surface reads (`DocumentController.php:132` `image_path`), and it already exists for a scanned page today — OCR can and must reuse it (same key, no new render) rather than re-rendering from the PDF a second time.
- **Detection of "this page has no text":** there is no explicit flag — it's implicit in `text.strip() == ""`, computed downstream by the caller (below), never inside `extract_pdf` itself.
- **Route + request shape:**
```60:62:hr-ai/app/main.py
class ExtractRequest(BaseModel):
    storage_key: str
    document_uuid: str
```
```353:367:hr-ai/app/main.py
@app.post("/extract", dependencies=[Depends(require_internal_token)])
def extract(req: ExtractRequest) -> JSONResponse:
    """PDF → per-page text + page-image S3 keys (ADR-0010).

    Reads the original from S3, writes page images to S3, returns page data.
    Never writes the database.
    """
    try:
        result = extract_pdf(req.storage_key, req.document_uuid)
        return JSONResponse(result)
    except Exception as exc:  # noqa: BLE001 - surface extraction/storage failure
        return JSONResponse(
            {"status": "error", "detail": str(exc)},
            status_code=502,
        )
```
An `--ocr` opt-in (§3) means adding an `ocr: bool = False` (+ a page cap) to `ExtractRequest`, threaded from `hr-backend`'s `ExtractionClient::extract()` call:
```42:57:hr-backend/app/Services/ExtractionClient.php
public function extract(string $storageKey, string $documentUuid): array
{
    $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
        ->timeout(180)
        ->acceptJson()
        ->post("{$this->base()}/extract", [
            'storage_key' => $storageKey,
            'document_uuid' => $documentUuid,
        ]);

    if (! $response->successful()) {
        throw new RuntimeException("hr-ai /extract failed ({$response->status()}): ".$response->body());
    }

    return $response->json();
}
```

### 1.2 Where "no text" is detected and what happens today (`DocumentIngestor`)

```139:145:hr-backend/app/Services/DocumentIngestor.php
        } elseif (! $isXlsx) {
            $extract = $this->extractor->extract($storageKey, $uuid);
            $pages = $extract['pages'] ?? [];
        }
        $emptyText = ! $isXlsx && $pages !== [] && collect($pages)->every(
            fn ($p) => trim((string) ($p['text'] ?? '')) === ''
        );
```
`$emptyText` is document-level (every page empty), computed **after** `/extract` returns, entirely in `hr-backend`. `document_pages` rows are then written verbatim from the response:
```170:178:hr-backend/app/Services/DocumentIngestor.php
            // Replace pages (re-ingest is idempotent).
            $document->pages()->delete();
            foreach ($pages as $p) {
                DocumentPage::create([
                    'document_id' => $document->id,
                    'page_number' => $p['page_number'],
                    'text' => $p['text'] ?? '',
                    'image_path' => $p['image_key'] ?? null,
                ]);
            }
```
This is the exact hook point for the extraction-step fallback: `/extract`'s per-page loop is where a **page-level** (not document-level) "no text" check belongs, so a partially-scanned PDF (some real pages, some scanned inserts) gets OCR only on the pages that need it — the spec's "page-by-page, text-less pages only" requirement. Page-level `has_text` is already surfaced to the admin UI today:
```128:135:hr-backend/app/Http/Controllers/Admin/DocumentController.php
            'pages' => $document->pages->map(fn ($p) => [
                'page_number' => $p->page_number,
                'text' => $p->text,
                'has_text' => trim((string) $p->text) !== '',
                'image_path' => $p->image_path,
            ]),
            'empty_text' => $document->pages->isNotEmpty() && $document->pages->every(fn ($p) => trim((string) $p->text) === ''),
```
and the same document-level `∅ No text` boolean drives the Knowledge Center list badge:
```603:603:hr-backend/app/Http/Controllers/Admin/DocumentController.php
            'empty_text' => ((int) $d->pages_total) > 0 && ((int) $d->pages_with_text) === 0,
```

### 1.3 The post-extraction pipeline that must be reused unchanged — entry point + input shape

**This is the load-bearing finding.** `chunks:embed` (the only caller of `/embed`) passes `storage_path` — the **original PDF's S3 key** — not any per-page text:
```62:66:hr-backend/app/Console/Commands/ChunksEmbed.php
            $scope = $this->resolveScope($d);
            try {
                $result = $client->embed($d->id, $d->uuid, $d->storage_path, $scope);
```
`hr-ai`'s `/embed` re-extracts from those raw bytes, never touching `document_pages`:
```370:385:hr-ai/app/main.py
@app.post("/embed", dependencies=[Depends(require_internal_token)])
async def embed(req: EmbedRequest) -> JSONResponse:
    """Re-extract column-aware → de-space → article-chunk → embed (BGE-M3/1024)
    → WRITE document_chunks (ADR-0013). hr-backend passes the resolved scope;
    the denormalized scope columns are copied verbatim. Idempotent re-embed.
    """
    from .pipeline import embed_document
    try:
        scope = req.scope.model_dump()
        scope["validity_start"] = req.scope.validity_start
        scope["validity_end"] = req.scope.validity_end
        result = await embed_document(req.document_id, req.storage_key, scope)
        return JSONResponse(result)
```
```1:50:hr-ai/app/pipeline.py
"""Embed pipeline (ADR-0013): S3 original → column-aware streams → de-spaced
article chunks → BGE-M3 embeddings → write `document_chunks` directly.
...
"""
from __future__ import annotations
import asyncio
from .chunking.chunker import chunk_document
from .chunking.extract_columns import extract_language_streams
from .chunks_db import replace_document_chunks
from .embeddings import count_tokens, embed_texts
from .storage import get_object_bytes


def build_chunks(pdf_bytes: bytes) -> tuple[list[dict], dict]:
    """Synchronous CPU work: extract columns → chunk. Returns (chunks, stats)."""
    extracted = extract_language_streams(pdf_bytes)
    chunks = chunk_document(extracted["streams"], count_tokens)
    return chunks, extracted["stats"]


async def embed_document(
    document_id: int,
    storage_key: str,
    scope: dict,
) -> dict:
    pdf_bytes = await asyncio.to_thread(get_object_bytes, storage_key)
    chunks, stats = await asyncio.to_thread(build_chunks, pdf_bytes)
```
**Consequence:** OCR'ing a scanned page and writing the result only to `document_pages.text` is invisible to `/embed`. Whatever design ships for OCR must give `extract_language_streams`/`build_chunks` a way to see the OCR'd text for a page whose native PyMuPDF pass yields zero blocks — see §3.

The pipeline's real entry point and exact input shape, verbatim:
```154:201:hr-ai/app/chunking/extract_columns.py
def extract_language_streams(pdf_bytes: bytes) -> dict:
    """Return per-language page-tagged text units + extraction stats. ..."""
    ratio = settings.chunk_space_gap_ratio
    repeat_fraction = settings.chunk_repeat_furniture_min_page_fraction

    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    try:
        page_count = doc.page_count

        # --- Pass 1: collect de-spaced text blocks with geometry ---
        blocks: list[dict] = []
        long_token_flags = 0
        for index in range(page_count):
            page = doc.load_page(index)
            page_number = index + 1
            pw = float(page.rect.width) or 1.0
            ph = float(page.rect.height) or 1.0
            raw = page.get_text("rawdict")
            for blk in raw.get("blocks", []):
                if blk.get("type", 0) != 0:
                    continue  # image block, no text
                text, flags = despace_block(blk, ratio)
                long_token_flags += flags
                if not text:
                    continue
                x0, y0, x1, y1 = blk.get("bbox", (0, 0, 0, 0))
                blocks.append(
                    {
                        "page": page_number, "pw": pw, "ph": ph,
                        "x0": float(x0), "x1": float(x1), "y0": float(y0), "y1": float(y1),
                        "x_mid": (float(x0) + float(x1)) / 2.0,
                        "width": float(x1) - float(x0),
                        "text": text, "furniture": False,
                    }
                )
```
`Pass 2` (furniture, `:207-236`) marks repeating margin-band blocks. `Pass 3` (`:238-303`) calls `_classify_page` per page (positive-evidence two-column test — tall/balanced/overlapping/gutter-clear, row-aligned-short-cell table exclusion, `:88-151`) and the Spanish-function-word bilingual gate (`_es_ratio`, `:67-75`; the bilingual branch `:277-291`). It emits ordered `(page, col, y0, text)` tuples into `es`/`eu`, sorted at `:304-305` into the flat `list[tuple[page_number, text]]` streams `chunker.chunk_document` consumes:
```20:29:hr-ai/app/chunking/chunker.py
This stage composes with — never replaces — the 2a extraction front-end
(`extract_columns.py`): de-spacing, repetition/margin-band furniture stripping,
positive-evidence two-column detection, the Spanish-function-word language gate,
and language tagging all run BEFORE this, and are untouched. The chunker still
receives one already-separated language stream at a time.
```
Two shapes matter for the integration design in §3:
1. **The geometry-block shape Pass 1 builds** (`page, x0/x1/y0/y1, x_mid, text` — all in PDF-point page-relative coordinates, i.e. fractions of `pw`/`ph` once normalized) is what `_classify_page` and the furniture pass consume.
2. **The flat stream shape** `chunker.chunk_document` consumes is just `[(page_number, text), ...]` per language, already in reading order — geometry has been fully resolved by the time it gets here.

### 1.4 The embedding gate, confirm, and the badge — the "inert until verified" machinery to reuse

Selection query (`tagging_status != under_review` is the gate OCR'd docs must sit behind):
```34:42:hr-backend/app/Console/Commands/ChunksEmbed.php
    private const IN_SCOPE_TYPES = ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling'];

    public function handle(ExtractionClient $client): int
    {
        $query = Document::query()
            ->with(['documentType', 'convenio'])
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::IN_SCOPE_TYPES))
            ->whereIn('retrieval_status', ['active', 'historical'])
            ->where('tagging_status', '!=', 'under_review');
```
Confirm (the human-verify action that flips the gate open — ADR-0020):
```260:287:hr-backend/app/Http/Controllers/Admin/DocumentController.php
    public function confirm(Request $request, string $uuid): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $adminId = $request->user()->id;

        DB::transaction(function () use ($document, $adminId) {
            $document->update(['tagging_status' => 'verified']);
            TagEvent::create([
                'entity_type' => 'document', 'entity_id' => $document->id,
                'facet' => 'document', 'old_value' => null, 'new_value' => 'verified',
                'source' => 'admin_manual', 'actor_id' => $adminId, 'confidence' => null,
                'note' => 'tags confirmed',
            ]);
            $document->reviewTasks()->where('status', 'open')->update([
                'status' => 'resolved', 'resolved_by' => $adminId, 'resolved_at' => now(),
            ]);
        });
        return response()->json(['status' => 'ok', 'tagging_status' => 'verified']);
    }
```
An OCR'd document therefore needs **no new gate at all** — it needs to *stay* `under_review` (already true for every scanned doc found on staging, see §1.6) until this exact `confirm()` runs, at which point the *next* `chunks:embed` run picks it up because it is no longer excluded by `:42`.

Data-model confirms `document_pages` is the untouched citation surface and re-states the embedding gate as an explicit invariant:
```238:238:hr-docs/architecture/data-model.md
> **Embedding requires `tagging_status ≠ under_review` (ADR-0013).** ... `document_pages` (Sprint 1 per-page text + page images) are **untouched** — they remain the citation surface; `document_chunks.content` is the normalized retrieval surface.
```

### 1.5 A second existing consumer of `document_pages.text` that OCR unblocks "for free" — the tag-proposal tier

Independent of the embed pipeline, Sprint 7a's tag-proposal service reads `document_pages.text` directly (not the raw PDF) and explicitly skips when it's empty — with a doc-string comment that is literally about to become false:
```64:72:hr-backend/app/Services/TagProposalService.php
        $document->loadMissing('pages');
        $pageText = $document->pages
            ->sortBy('page_number')
            ->map(fn ($p) => (string) $p->text)
            ->implode("\n\n");

        if (trim($pageText) === '') {
            // A scan with no extractable text (OCR is out of scope this sprint).
            return ['status' => 'skipped', 'reason' => 'no_extractable_text'];
        }
```
This means OCR filling `document_pages.text` has **two** consumers, not one: the citation/viewer surface (always true) and — as soon as the text is non-empty — the *existing* AI tag-proposal job (`ProposeDocumentTags`), which can now propose `convenio`/`territory`/`sector`/`document_type` for a document that was previously untaggable. This matters concretely: every scanned document found on staging today (§1.6) has `convenio_id = NULL` — OCR alone does not fix that; the *existing*, unmodified 7a re-suggest path does, once text exists. This is folded into §4 (backfill) rather than treated as new code.

### 1.6 The provider/key path (candidate: Claude vision) and hr-ai's dependency footprint

`AnswerProvider` (ADR-0015) has **no image-input method today** — `synthesise`/`classify`/`ground`/`propose_tags`/`segment_facts` are all text-in, text-out:
```226:300:hr-ai/app/providers/base.py
class AnswerProvider(ABC):
    """A pluggable answer provider (synthesis + routing + grounding). Default: Claude. ...
    """
    @abstractmethod
    def synthesise(self, question: str, chunks: list[ChunkInput], api_key: str, config: ProviderConfig) -> SynthesisResult: ...
    @abstractmethod
    def classify(self, question: str, api_key: str, config: ProviderConfig) -> RouterResult: ...
    @abstractmethod
    def ground(self, question: str, answer: str, chunks: list[GroundChunk], api_key: str, config: ProviderConfig) -> GroundingResult: ...
    @abstractmethod
    def propose_tags(self, page_text: str, candidate_vocabulary: dict[str, list[VocabularyCandidate]], api_key: str, config: ProviderConfig) -> TagProposalResult: ...
    @abstractmethod
    def segment_facts(self, pages_text: str, candidate_convenios: list[ConvenioCandidate], candidate_topics: list[VocabularyCandidate], api_key: str, config: ProviderConfig) -> SegmentedFactsResult: ...
```
`ClaudeProvider.synthesise` (`hr-ai/app/providers/claude.py:560-580`) shows the established per-call-key pattern any vision call would follow: `anthropic.Anthropic(api_key=api_key, base_url=config.endpoint or None)`, `client.messages.create(model=config.model, ...)`. The Anthropic Messages API accepts an `image` content block alongside `text` in the same `messages.create` call — no new SDK dependency (`anthropic>=0.39` is already in `requirements.txt:10`). **For the eval only**, the simplest correct thing is a standalone `score_ocr.py`/eval harness calling `anthropic` directly (mirroring the same per-call-key idiom) rather than shoehorning a one-off vision call into the `AnswerProvider` interface — that interface has no vision method, and adding one is an integration-phase decision gated on the eval's outcome, not a prerequisite for running the eval.

hr-ai's dependency footprint today (`requirements.txt:1-10`): `pymupdf`, `boto3` (Textract client already available, **zero new dependency** to trial it — only IAM permission + confirming the account's `eu-west-1` region, which the kickoff prompt confirms), `sentence-transformers`, `anthropic`. **No OCR library present.** Tesseract is a system package, not a `pip` package: the `Dockerfile` (`hr-ai/Dockerfile:23-26`) installs only `awscli curl` via `apt-get`; adding Tesseract means `apt-get install tesseract-ocr tesseract-ocr-spa tesseract-ocr-eus` (the `spa`/`eus` traineddata, per the kickoff prompt) plus `pip install pytesseract` — a real image-size and build-time cost, but a bounded, well-understood one (no GPU, no large model download unlike BGE-M3's 4.3 GB already handled via the `HF_HOME` named volume, `Dockerfile:1-11`).

### 1.7 The real scans on staging — live inventory (queried, not assumed)

**The kickoff prompt's ids (89, 91, COEAS Estatal) are stale.** They come from `deploy.md` §4a/§5, written before a later full re-ingest (`documents:ingest-folder`) renumbered every document id. Querying staging directly (`documents` ⨝ `document_pages`, grouping by document and filtering `pages > 0 AND pages_with_text = 0`) gives the current, authoritative set — **9 documents, 101 total on staging**:

| doc id | uuid | title | convenio | retrieval_status | tagging_status | pages (all text-less) |
|---|---|---|---|---|---|---|
| 14 | `55184e0c-…` | Acuerdo fin de huelga UBIK - SEDENA SL 2019-2022 | — (NULL) | active | under_review | 12 |
| 18 | `9f5181ee-…` | ConvenioLimpiezaEdificiosLocalesGipuzkoa2019-2026 | — (NULL) | historical | under_review | 36 |
| **31** | `e4dc1595-…` | **PACTO CULTURA NAVARRA** | — (NULL) | **active** | under_review | 2 |
| 35 | `2cd0c64a-…` | 31004605011982 Limpieza Navarra 2024 2027 Tablas 2026 | 22 (LIMPIEZA DE EDIFICIOS Y LOCALES) | active | auto_proposed | 1 |
| 47 | `2aa6ad5f-…` | Pacto de la empresa | — (NULL) | historical | under_review | 2 |
| **50** | `70bf3234-…` | **CONVENIO DEPORTE NAVARRA 2025 A 2028** | — (NULL) | **active** | under_review | 27 |
| 68 | `c178518e-…` | CONDICIONES LABORALES PERSONAL SUBROGADO BARAKALDO | — (NULL) | historical | under_review | 1 |
| 69 | `d4ecc298-…` | PACTO | — (NULL) | historical | under_review | 7 |
| 85 | `e9ffa7ba-…` | CONVENIO DEPORTE ESTATAL | — (NULL) | historical | under_review | 17 |

**Bold** rows (31, 50) are the active-scope, currently-unanswerable targets the kickoff prompt is really pointing at (formerly ids 91/89 under the old corpus numbering). Doc 14 (active, under_review) is a third plausible active target; the rest are `historical` (lower go-live urgency, still worth OCR'ing for backfill completeness).

**COEAS Estatal is *not* on this list — verified directly, not assumed.** Its convenio (`numero 99100055012011`) has two documents:
```
doc 76 | COEAS Estatal 2025 2027 | retrieval=active   | tagging=under_review | pages=74 | with_text=74
doc 87 | COEAS Estatal 2020 2023 | retrieval=historical | tagging=under_review | pages=62 | with_text=62
```
Both are **fully text-extracted** (74/74, 62/62). COEAS Estatal's unanswerability on current staging is a `tagging_status = under_review` problem (blocked by the embedding gate, §1.4), **not an OCR problem** — `deploy.md`'s listing of it under "Known coverage gaps" alongside 89/91 was accurate when written but the corpus has since changed under it. **Correction to carry into `deploy.md`/`roadmap.md` at sprint close:** COEAS Estatal drops off the Sprint-7e OCR backlog; it needs only tagging verification (already tracked separately as a go-live item, `deploy.md:60`).

A structural note that explains why every one of the 9 is `under_review` with `convenio_id = NULL`: `DocumentTagger` sets `under_review` precisely when the filename parser opened a review task (conflict/ambiguity), independent of OCR:
```176:176:hr-backend/app/Support/DocumentTagger.php
            'tagging_status' => $review !== null ? 'under_review' : 'auto_proposed',
```
These filenames (`PACTO`, `Pacto de la empresa`, `CONVENIO DEPORTE ESTATAL`, …) don't carry the numero-based convention the parser expects, so the review-task path and the "no text" path co-occur but are two independent problems. §4 folds the existing tag re-suggest into the backfill flow to close both.

### 1.8 Fixture pages selected — copied to `sprint-07e/eval/fixtures/`

Five representative page images, downloaded from S3 (the exact `page_image_key`s `/extract` already produced) and copied into `hr-docs/sprints/sprint-07e/eval/fixtures/`:

| file | source | why |
|---|---|---|
| `doc18-gipuzkoa-limpieza-p10-twocol-eseu.jpg` | doc 18, page 10 | Genuine bilingual two-column BOG page (Euskara left / Spanish right, Capítulo/Artículo headers) — the hard case the engine choice is decided on |
| `doc18-gipuzkoa-limpieza-p20-twocol-eseu.jpg` | doc 18, page 20 | A second two-column es/eu page, different article range — one fixture is not enough to trust a column-integrity score |
| `doc50-deporte-navarra-p05-singlecol-es.jpg` | doc 50, page 5 | Single-column Spanish prose (articles) — the common case, must not regress relative to the two-column case |
| `doc50-deporte-navarra-p26-table-annex.jpg` | doc 50, page 26 | Salary-grid annex (row/column table) — tests OCR column integrity on a *real* table, not prose, and stresses the "tabular → do not chunk as prose" boundary |
| `doc69-pacto-p03-lowquality-marginalia.jpg` | doc 69, page 3 | Lower print/scan quality, handwritten marginalia and signature bleed-through — degradation stress case |

No OCR has been run on these; no transcription exists yet (§2 designs the gold-transcription step next).

---

## 2. The eval design

### 2.1 Gold-set format

One JSON file per fixture page, `sprint-07e/eval/gold/<fixture-stem>.json`:
```json
{
  "fixture": "doc18-gipuzkoa-limpieza-p10-twocol-eseu.jpg",
  "document_id": 18,
  "page_number": 10,
  "layout": "two_column_bilingual",
  "columns": [
    { "language": "eu", "order": 0, "text": "…hand-transcribed Euskara column, in reading order…" },
    { "language": "es", "order": 1, "text": "…hand-transcribed Spanish column, in reading order…" }
  ],
  "article_headers": ["22. artikulua", "Artículo 22", "23. artikulua", "Artículo 23"],
  "notes": "footer boilerplate excluded from gold (furniture, not prose)"
}
```
Rationale for the shape, tied directly to what the pipeline actually needs (§1.3):
- **`columns[]`, not one flat `text` field** — because the metric that matters most (column integrity, below) is exactly "did the engine keep Euskara and Spanish, and left-then-right, separate and in order," which a single flattened gold string can't score against.
- **es and eu are separate streams**, matching `extract_language_streams`'s own output shape (`streams: {"es": [...], "eu": [...]}`, `extract_columns.py:308`) — the gold format mirrors the pipeline's own contract so `score_ocr.py` can diff like-for-like without a translation step.
- **`article_headers`** — a short explicit list, because the chunker's header detector (`chunker.py`) is the next load-bearing consumer downstream; "article-header survival" (below) is scored against this list, not against fuzzy substring search in the full text.
- Single-column and table fixtures use the same shape with one `columns` entry (`order: 0`) or a `"layout": "table"` + a `cells` grid instead of `columns` (table gold is row/column-labelled, since CER on a table is close to meaningless — a transposed digit is catastrophic, a re-wrapped line is not).

### 2.2 How the gold pages get transcribed

**Recommendation: a first-draft transcription, human-corrected — not from-scratch hand transcription.** Rationale: hand-transcribing bilingual legal prose from a scan (5 fixtures × ~1 page) end-to-end is slow and itself error-prone on Euskara (a language most reviewers don't read); a first-pass transcription for a human to *correct* is faster and, critically, the correction step is exactly the skill a verifier needs anyway (ADR-0020's human-in-the-loop principle already assumes a reviewer reads the source, not that they produce it from nothing). Concretely:
1. Run the **strongest available** OCR pass (Claude vision, since it is expected to be the most layout-competent, even though it is a *candidate* not yet chosen) once per fixture, in the `columns[]` shape above.
2. Pedram reads the fixture image side-by-side with the draft and corrects it line-by-line (this is a review action, not a build action — it does not "spend" the engine-decision's objectivity, because the *engine used to draft gold is never the sole engine scored against its own gold* — all candidates, including the drafting one, are scored against the human-corrected result, not the draft).
3. `article_headers` and table `cells` are filled by the same read-through.

This keeps the eval honest (gold is human-verified, not any engine's raw output) while making the 5-fixture transcription tractable in an afternoon rather than a multi-day task. **Open in §7 (Q1):** confirm this is acceptable, or whether independent from-scratch transcription is preferred for the two hardest (two-column) fixtures specifically, given they carry the most weight in the engine decision.

### 2.3 `score_ocr.py` — metrics

A standalone script, `sprint-07e/eval/score_ocr.py`, run once per (engine × fixture), reading the gold JSON (§2.1) and the engine's raw output for that fixture:

| metric | definition | why it's here |
|---|---|---|
| **Column integrity %** | Of the gold's ordered `columns[]`, the fraction whose text the engine kept as a *distinct, correctly-ordered, correctly-language-tagged* unit (not interleaved with the other column, not merged, not swapped left/right). Binary per column, then averaged per fixture, then across fixtures. | The metric that maps directly onto what `extract_columns.py`'s two-column detector + bilingual gate already guarantee for native PDFs (`:88-151`, `:277-291`) — this is the property OCR must reproduce *itself* for a scanned page, because (§3) there is no PyMuPDF geometry pass to fix it up after the fact. **Primary decision metric**, per the hard constraint. |
| **Article-header survival** | Of gold's `article_headers[]`, the fraction the engine's raw text contains verbatim (or with only whitespace/case normalization) at a line start. | This is exactly what `chunker.py`'s header detector needs (`Artículo N.º` / `N. artikulua` / etc.) — an OCR'd header that's merged into the preceding line or split mid-token silently breaks chunking downstream, and CER alone would not surface that (a header with 95% character accuracy but a missing line break is a 0% survival, correctly). |
| **CER / WER** | Standard character/word error rate against the flattened gold text per column (or per cell for tables), Levenshtein-based. | Secondary, per the hard constraint ("CER acceptable" only after column integrity + header survival pass) — still reported because it's the standard OCR-quality number and useful for the backfill quality score (§3). |
| **Cost / page** | Actual metered cost (Claude vision: input+output tokens × price; Tesseract: $0, CPU-seconds only; Textract: per-page API price) for that fixture. | Feeds the decision rule's "cheaper if close" tie-break and the backfill cost projection (§4, §7). |
| **Sec / page** | Wall-clock latency for that one page's OCR call. | Feeds the sync-vs-queued decision (§3) directly — this is the number that decides it, not a guess. |

### 2.4 Candidate engines — wired for the eval only

- **Claude vision** — via the Anthropic Messages API directly (not through `AnswerProvider`, §1.6), one image content block per fixture page (already rendered as JPEG at DPI 150 by `/extract`, no re-render needed), prompted to (a) transcribe strictly in reading order per visual column, (b) label each column's language, (c) preserve article-header lines verbatim. This prompt-level column/language self-report is exactly what §3's simplest integration shape needs if this engine wins.
- **Tesseract + layout analysis** — `pytesseract` (or raw `tesseract` CLI) with `--psm 3`/`--psm 1` (automatic page segmentation with orientation/script detection) and both `spa`+`eus` traineddata, run against the same page image. Tesseract's TSV/HOCR output gives **word-level bounding boxes**, which is the ingredient §3's alternative integration shape (geometry-block injection, reusing `_classify_page` unchanged) needs — Tesseract does not know Spanish from Basque, so the *existing* `_es_ratio` bilingual gate (`extract_columns.py:67-75`) would still be needed on its output, which is a genuine advantage of that integration shape for this engine specifically.
- **Textract (optional)** — `boto3`'s existing Textract client (`AnalyzeDocument`, `LAYOUT`/`TABLES` feature types), eu-west-1 (confirmed available). Zero new dependency (`boto3` is already in `requirements.txt`). Included only if time budget allows — flagged in §7 (Q2) as the first thing to drop if the eval needs to be time-boxed, since Claude vision and Tesseract already bracket the quality/cost trade space (best-layout/priciest vs. free/CPU-bound-and-untested-on-this-corpus).

### 2.5 Decision rule

In order, per the hard constraint:
1. **Column integrity % and article-header survival first.** An engine that scores well on CER but scrambles the two-column bilingual reading order is disqualified outright — that failure mode (per the roadmap's own framing, `roadmap.md:161`) is exactly what "older OCR engines... produce garbled or merged text" describes, and it is unrecoverable downstream (no code fixes a scrambled column after the fact, §3).
2. **CER/WER as a tie-breaker among engines that clear (1).** "CER acceptable" — no fixed target this plan sets in advance; the eval numbers decide, recorded in ADR-0026 with the actual measured distribution (mirroring how ADR-0024 recorded calibration numbers rather than an a-priori threshold, `sprint-07d/review.md` precedent).
3. **Cost/page as the final tie-break when (1) and (2) are close.** If two engines both clear the bar and score within a small CER band, cheaper wins — "cheaper if close" per the constraint, not cheaper unconditionally.

The output of this process is **not** written this turn (no engine chosen) — it is a decision recorded in ADR-0026 during the integration build, after the eval script runs against the real fixtures.

---

## 3. The integration (narrow)

### 3.1 Where the fallback hooks into `/extract`

Page-by-page, inside `extract_pdf`'s existing loop (`hr-ai/app/extract.py:31-46`), immediately after `text = page.get_text("text") or ""`: when `text.strip() == ""` **and** the request opted in (`ocr=True`, §3.5) **and** the page is within the page cap, run the chosen engine against the *already-rendered* pixmap/JPEG (no second render — reuse the same bytes `put_object_bytes` is about to write). The returned OCR result populates `text` for that page exactly as the native path would, so `DocumentIngestor`'s existing `$emptyText`/`document_pages.text` write path (`DocumentIngestor.php:143-178`) needs **zero changes** for the citation/viewer/tag-proposal consumers (§1.2, §1.5) — this half of the integration is genuinely additive-and-done at the `/extract` layer alone.

### 3.2 The hard part — getting OCR text into `/embed`'s pipeline, which never reads `document_pages`

Per §1.3's finding, `/embed` re-extracts from `storage_key` (the original PDF), so a page OCR'd at ingest time is invisible to it unless the OCR result is *also* made available at embed time. Two additive shapes, both consistent with "the existing pipeline unchanged" — the choice between them is an engine-decision consequence, not a free design pick:

**Option A — synthetic geometry blocks, spliced into Pass 1 (`extract_columns.py:170-201`).** For any page where the native loop finds zero blocks, inject block dict(s) — `{page, pw, ph, x0, y0, x1, y1, x_mid, text, furniture: False}` in the *same normalized page-relative coordinates* PyMuPDF already uses (trivial to produce from any bbox-reporting engine, since these are just fractions of page width/height) — **before** Pass 2 (furniture) and Pass 3 (`_classify_page` + the bilingual gate) run. Every downstream line — two-column detection, the Spanish-function-word gate, furniture stripping — runs **completely unmodified** on the injected blocks; they cannot tell an OCR block from a native one. This is the natural fit if Tesseract (with its own bbox output) or Textract wins: neither engine knows Spanish from Basque, so letting the *existing, tested* `_es_ratio` gate make that call — instead of re-implementing it inside the OCR step — is strictly less new code and reuses a component that already has the right bias (`extract_columns.py:9-24`'s "when evidence is weak → single column, keep text").

**Option B — pre-split stream units, appended directly to `es`/`eu` before the final sort (`extract_columns.py:304-309`).** For a page with zero native blocks, skip `_classify_page` entirely and append the OCR engine's own already-column-split, already-language-tagged text units (in reading order) straight into the `es`/`eu` accumulator lists that Pass 3 populates for every other page. This is the natural fit if Claude vision wins: it can be prompted to do the column/language read itself (§2.4), and `chunker.chunk_document` only ever needs the flat `(page, text)` shape (`extract_columns.py:304-309`, `chunker.py:29`) — it never sees geometry, so there is nothing to "reuse" at the geometry layer for a vision engine that has already solved that problem visually. The cost of this option is that furniture-stripping (repeating bilingual footers, `extract_columns.py:207-236`) is **not** automatically applied to OCR'd pages — accepted as a quality delta in the same direction as the codebase's existing bias ("keep a stray bit of furniture rather than delete an article," `extract_columns.py:22-24`), not a new risk.

Both options require one new plumbing decision, independent of A/B: **how does `hr-ai` see the OCR result at embed time**, given `/embed`'s request today is just `{document_id, uuid, storage_path, scope}`? Two candidates, to settle once the engine is chosen:
- **(i) hr-backend passes it in the `/embed` request body** — `ChunksEmbed` already reads `document_pages` and could select rows with the new `extraction_source = 'ocr'` (§3.3) and include their text (+ engine-reported bboxes for Option A) as an additive `ocr_pages` field, mirroring exactly how `scope` is already resolved in `hr-backend` and passed verbatim (`ChunksEmbed.php:138-155`, ADR-0007's "hr-ai never derives, only receives" pattern).
- **(ii) hr-ai reads an OCR sidecar from S3 itself** — `/extract`'s OCR step additionally writes a small JSON (text + column/bbox info) to a derived key next to the page image (e.g. `documents/{uuid}/ocr/{page:04d}.json`), and `build_chunks`/`extract_language_streams` probes for it when a page's native block count is zero. This keeps the `/embed` request/response contract untouched and stays inside hr-ai's *already-established* S3-write privilege (`extract.py:38`) rather than growing the HTTP body — arguably a better fit for `hr-ai`'s "stateless except `document_chunks` + S3" framing (ADR-0007), and avoids inflating the `/embed` payload for a many-page scanned doc.
- **Recommendation for the build turn: (ii)**, because it needs no `/embed` contract change at all (only an additive, purely-internal-to-`hr-ai` S3 read) — but this is explicitly a build-time call, not fixed here, since it depends slightly on which of Option A/B wins (Option A's bboxes are a natural fit for a small JSON sidecar either way; Option B's plain text units are trivially small too).

### 3.3 Provenance — additive columns, document-level and page-level

- **`document_pages.extraction_source`** — new nullable enum/string column, `'pdf_text' | 'ocr'`, default `'pdf_text'` for every existing row (a plain backfill-on-migrate default, not a data migration). Set per-page in `DocumentIngestor.php`'s existing page-write loop (`:172-178`) from whatever `/extract` now returns per page (`extract.py`'s per-page dict gains an `extraction_source` key alongside `text`/`image_key`).
- **Document-level OCR provenance** — a single derived flag (`documents` gains no new column; it's computed the same way `empty_text` already is, `DocumentController.php:135`/`:603` — e.g. "any page has `extraction_source = 'ocr'`") surfaced next to the existing `∅ No text` badge as a distinct `⚙ OCR'd` badge, and in the per-page viewer alongside the existing `has_text` flag (`:131`) as a small provenance marker on any OCR'd page (a citation/answer surfacing this page should visibly say so — the spec's "provenance visible... citation marker" requirement). No new migration needed on `documents` itself; `document_pages.extraction_source` is the only schema change.
- The `TagEvent` provenance ledger (already used for `filename_parse`/`ai_agent`/`admin_manual`, `DocumentIngestor.php:181-197`) is a natural, **already-existing** place to also log an `ocr` source event per document at ingest time (facet `'extraction'`, note = engine name + quality score) — zero new table, one more row shape in a table that already has a free-string `source` column (mirroring how `sprint-07d`'s ADR-0024 avoided a CHECK-rewrite by using free strings, `data-model.md` Group G).

### 3.4 The deterministic per-page quality score

A number computed at OCR time (no LLM re-judging its own output — this must be deterministic per the "no LLM cleanup" constraint), stored alongside `extraction_source` (e.g. `document_pages.ocr_quality` numeric, nullable) or folded into the same `TagEvent` note above. Candidate deterministic signals, all computable from the OCR output alone with no gold reference (unlike the eval's CER, which needs gold): character count vs. expected page-image text density (a near-empty result on a visibly dense scan page is a red flag), a flagged low-confidence-run count if the engine reports per-token confidence (Tesseract and Textract both do; Claude vision does not, natively — a prompt-elicited self-reported confidence would be a model claim, not a deterministic signal, so it should be weighted low or excluded), and — reusing existing machinery for free — the **same over-strip/under-chunking sanity check `chunks:embed` already runs** (`ChunksEmbed.php:105-133`) applied post-hoc to the OCR'd page's resulting blocks/chunks once it *is* embedded (after verification), giving a second, pipeline-native quality signal. **Open in §7 (Q3):** the exact formula is an integration-phase decision once the winning engine's native confidence signal (if any) is known.

### 3.5 The `--ocr` opt-in flag + page cap

- **hr-ai:** `ExtractRequest` (`main.py:60-62`) gains `ocr: bool = False` and `ocr_page_cap: int | None = None`.
- **hr-backend:** `ExtractionClient::extract()` (`ExtractionClient.php:42-57`) gains matching parameters in the POST body; `DocumentIngestor` (or a caller of it) decides whether to pass `ocr: true` per document/run.
- **`documents:ingest-folder`** (`IngestFolder.php`) — no `--ocr` flag exists today (confirmed by reading the file in full, `:24-30`); this sprint adds `{--ocr}` + `{--ocr-page-cap=N}` to its signature, threaded through to `DocumentIngestor`/`ExtractionClient`. Default stays OCR-off, matching the constraint ("opt-in").
- **Page cap rationale:** bounds worst-case cost/latency for a single pathological upload (a 300-page fully-scanned PDF should not silently trigger 300 vision calls); a capped run OCRs the first N text-less pages and leaves the rest as-is, surfaced in the backfill report (§4) rather than silently truncating without a trace.

### 3.6 Synchronous vs. queued — decided by the eval's own latency number, not guessed here

The kickoff prompt is explicit that this is decided "by expected latency/cost" — which is exactly what §2.3's **sec/page** metric measures, so this plan does not pre-guess it. The shape of the decision, once the number exists: `/extract`'s current synchronous request (`timeout(180)` in `ExtractionClient.php:44`) already budgets 180s for a whole-document extract; if the winning engine's sec/page × (text-less page count, up to the cap) plus native extraction time stays comfortably inside that window for the realistic worst case in the inventory (§1.7's largest text-less document is 36 pages, doc 18), synchronous is simplest and needs no new infrastructure (no queue, no polling UI). If not — most likely if Claude vision wins and a full-cap run is tens of pages — OCR should move to the existing queued-job pattern already used for `ProposeDocumentTags` (§1.5) rather than inventing a new one, with the ingest/backfill command dispatching and reporting completion asynchronously.

---

## 4. Backfill + the three targets

### 4.1 `documents:ocr-backfill`

A new Artisan command, modeled directly on the existing `IngestFolder`/`ChunksEmbed` shape (query → act → report), never touching a document already verified:
1. **Find:** every document with `pages > 0 AND pages_with_text = 0` — the exact query used to build §1.7's inventory table, now as a reusable scope (e.g. `Document::whereHas('pages')->whereDoesntHave('pages', fn ($q) => $q->whereRaw("trim(text) <> ''"))`), optionally filtered to `--active-only` for the go-live-relevant subset (docs 14, 31, 50).
2. **OCR:** call the now-`--ocr`-capable `/extract` per document (§3.5), page-capped.
3. **Report:** pages OCR'd, per-page quality score (§3.4), cost incurred (from §2.3's measured cost/page × pages actually OCR'd), printed in the same audit-first style `chunks:embed` already uses for its suspicious-document summary (`ChunksEmbed.php:93-100`) — a table, not a silent success.
4. **Leave `under_review`:** the command does **not** call `confirm()` — that stays a human action, per ADR-0020 and the "inert until verified" constraint. It *may* additionally dispatch the existing `ProposeDocumentTags` job (§1.5) now that text exists, so the review queue gets a facet proposal to look at rather than just raw OCR text.

### 4.2 The verify step (unchanged, reused)

A human reviews the OCR'd pages (viewer already shows page images + the new provenance marker, §3.3), corrects/resolves any AI-proposed facets from step 4 above (existing admin edit UI, `DocumentController::update`, `:407-450`), and calls the **existing, unmodified** `confirm()` (`:260-287`). No new endpoint.

### 4.3 Embed (unchanged trigger, narrow pipeline change from §3.2)

The next `chunks:embed` run picks the now-verified document up automatically (`:42`'s gate is satisfied) — no manual embed step, no new command flag; `resolveScope` (`:138-155`) copies whatever `convenio_id` the human just set (was `NULL` for every candidate today, §1.6) into the chunk rows exactly as it does for every other document today.

### 4.4 Acceptance check per target

Applied to docs 14, 31, 50 (the active-scope targets) at minimum, and to the full inventory (§1.7) for backfill completeness:
- **Chunks > 0** — `document_chunks` gains rows for the document (`chunks:embed --dry-run` then a real run, verified by count).
- **In retrieval** — the document's chunks are reachable by `/retrieve` under its (now-set) scope; this doubles as an implicit check that `convenio_id` was actually resolved (§1.6/§4.2), not left `NULL`.
- **A scoped, cited answer** — a real question against the document's convenio produces an answer citing this document's chunk(s) with the correct authority level, exercising the full frozen answer loop end-to-end (unchanged, per §5) but now with a previously-dead document as the source. This is the same shape as `Sprint7cAdditivityRegressionTest`'s golden-trace assertions and should be one concrete, named test case per target document, not just a manual smoke check.

---

## 5. Additivity

### 5.1 Changed

| repo | file | change |
|---|---|---|
| hr-ai | `app/extract.py`, `app/main.py` (`ExtractRequest`) | page-level OCR fallback behind `ocr`/`ocr_page_cap`; reuses the already-rendered page image, no second render |
| hr-ai | `app/chunking/extract_columns.py` (or a thin wrapper called from `pipeline.py`) | one additive hook (Option A or B, §3.2) so a zero-native-block page can be filled from an OCR source; every existing line in `_classify_page`, the furniture pass, and the bilingual gate is untouched |
| hr-ai | new `app/ocr/` module (engine-specific, decided post-eval) | the actual OCR call(s); `requirements.txt` gains the winning engine's dependency only |
| hr-ai | S3 (new, additive keys only) | OCR sidecar JSON per OCR'd page, if design §3.2(ii) is taken — new object keys, no existing key touched |
| hr-backend | `app/Services/ExtractionClient.php`, `app/Services/DocumentIngestor.php` | thread `ocr`/`ocr_page_cap` through; write `extraction_source` per page |
| hr-backend | `app/Console/Commands/IngestFolder.php` | `{--ocr}` `{--ocr-page-cap=}` flags |
| hr-backend | new `app/Console/Commands/OcrBackfill.php` | §4.1 |
| hr-backend | 1 additive migration | `document_pages.extraction_source` (nullable/defaulted), possibly `ocr_quality` — §6 |
| hr-backend | `Http/Controllers/Admin/DocumentController.php` | one new derived provenance field/badge (`⚙ OCR'd`), reusing the existing `empty_text`-style computed-boolean pattern — no new table |
| hr-frontend | Knowledge Center list/detail, page viewer | render the new badge + per-page provenance marker; reuse existing token/badge components |
| hr-docs | `architecture.md`, `data-model.md`, `roadmap.md` (mark 7e progress), ADR-0026, `sprint-07e/review.md` | docs |

### 5.2 Not changed — the frozen loop

Untouched, byte for byte: `hr-ai` `POST /retrieve`, `/synthesise`, `/ground`, `/route`, `/sandbox-retrieve`, `/compare-scope`; `chunking/chunker.py` (article-boundary detection, size caps); `chunking/normalize.py` (`despace_block`); `_classify_page`, `_es_ratio`, the furniture pass, and the bilingual gate **as logic** (only newly *reachable* by injected/appended units, per §3.2 — no existing branch is edited); `embeddings.py` (BGE-M3); `chunks_db.py` (`replace_document_chunks`); `ChatService`, `RouterService`, `GroundingService`, `SalaryAnswerService`, `ReferenceFactRouter`/`ReferenceFactAnswerService`, `GuardrailService`/`GuardrailPolicy`, `RulingPublisher`, `EscalationService`, `SemanticFenceService` (7d); `TagProposalService.php` (reads more documents successfully once text exists, but its own code — including the `no_extractable_text` skip line, which simply stops firing — is unedited); `document_chunks` schema (no new column); the embedding-gate query (`ChunksEmbed.php:38-42`) and `confirm()` (`:260-287`) — both reused verbatim, not modified.

**hr-ai never migrates** (ADR-0007) — `document_pages.extraction_source` is an hr-backend-owned migration; hr-ai's only new writes are S3 objects (already an established privilege, `extract.py:38`) and, unchanged, `document_chunks`.

### 5.3 The golden-trace regression is the gate

`php artisan test --filter Sprint7cAdditivityRegressionTest` must stay green, byte-for-byte, exactly as prior sprints required (`sprint-07d/plan.md:580`) — this sprint touches the extraction *front door* and one narrow seam in the post-extraction pipeline, never the answer loop itself, so a passing golden-trace run is the direct evidence that 7e did not perturb prose/salary answer byte-shape. Additionally: a **new** invariant test asserting a native (non-OCR) document's `extract_language_streams` output is bit-for-bit identical before/after the §3.2 hook is added (i.e. the hook is a true no-op on every page that already has native blocks) — this is the sprint-specific analogue of 7d's `Sprint7dFenceNeverOpensTest` (`sprint-07d/plan.md:580`, `:610`): prove the fallback is a pure addition, not just assert it in prose.

---

## 6. Migrations & build order

### 6.1 Migrations (additive, nullable, no backfill data-rewrite)

| # | table | columns | for |
|---|---|---|---|
| **1** | `document_pages` | `extraction_source` varchar(16) nullable, default `'pdf_text'` for existing rows going forward · `ocr_quality` numeric(4,3) nullable | §3.3, §3.4 |

Deliberately **not** migrated: `documents` (no new column — the OCR badge is a derived boolean over `document_pages`, exactly like `empty_text` already is, `DocumentController.php:135`); `document_chunks` (hr-ai never migrates, ADR-0007); `tag_events` (`source`/`facet` are already free strings, §3.3 reuses them as-is).

### 6.2 Build order

**Eval → engine decision → integration → provenance/badge → backfill on staging → docs.**

1. **Eval infra + gold (§2).** `score_ocr.py`, the 5 gold JSON files (draft-then-correct, §2.2), wiring for Claude vision + Tesseract (+ Textract if time allows) against the fixtures already in `sprint-07e/eval/fixtures/`.
2. **Run the eval, record the numbers, decide.** Column integrity + header survival first; CER/cost as tie-breaks (§2.5). Write **ADR-0026** with the actual measured table (mirroring `sprint-07d`'s calibration-recorded-in-ADR precedent) — engine choice is a consequence of this step, not a prior assumption.
3. **Integration (§3).** The `/extract` page-level fallback; the chosen Option A/B hook into `extract_language_streams`/`build_chunks`; the `--ocr`/page-cap flags; the migration (§6.1); the new no-op invariant test + golden-trace re-run (§5.3).
4. **Provenance/badge (§3.3).** `extraction_source` writes, the `⚙ OCR'd` badge, per-page viewer marker.
5. **Backfill on staging (§4).** `documents:ocr-backfill` run against the real 9-document inventory (§1.7), human verify on the 3 active targets at minimum, `chunks:embed`, acceptance checks (§4.4).
6. **Docs.** `architecture.md`, `data-model.md` (the new column, the OCR provenance note), `roadmap.md` (7e → DONE, correct the stale 89/91/COEAS-Estatal references per §1.7's correction), `sprint-07e/review.md` (the eval numbers, the backfill report, the acceptance-check results).

Stop — no commit until review, at each of the two review gates the constraint requires (plan review — this document; build review — after step 5/6, before merge).

### 6.3 Compute — `t3.large` or the resize?

Staging runs `t3.large` normally, with a documented resize path to `c7i.2xlarge` for CPU-heavy ingest (`vars.sh:48-49`, `EC2_INSTANCE_TYPE_INGEST`) — already used for the bulk `documents:ingest-folder` run. The answer depends directly on which engine wins (§2), so this plan states the two branches rather than picking one:
- **API-bound vision engine (Claude, or Textract) wins:** OCR cost is dominated by network/API latency, not local CPU — `t3.large` is fine; no resize needed. This is the "no infra change" branch.
- **CPU-bound Tesseract wins:** OCR is local CPU work, competing with the same instance already running `hr-backend`/`hr-ai`/Caddy/the BGE-M3 embedder. The existing `resize-for-ingest.sh`/`resize-back.sh` pattern (`Dockerfile:8-11` comment references it) is the natural fit for a one-off backfill run (§4) — resize up, run `documents:ocr-backfill`, resize back — exactly like the bulk ingest already does, needing no new infra script, only using the existing one for a new purpose.

---

## 7. Assumptions & open questions

**Q1 — gold-transcription approach (§2.2).** Recommending draft-by-Claude-vision-then-human-correct over from-scratch hand transcription, for tractability (5 fixtures, one afternoon vs. multi-day) — with the drafting engine still scored against the corrected gold like every other candidate, so it isn't graded on its own homework. **Open:** confirm this, or require independent from-scratch transcription for the two two-column fixtures specifically (the highest-stakes ones for the engine decision).

**Q2 — is Textract worth wiring for the eval?** It's a zero-new-dependency trial (`boto3` already present, eu-west-1 confirmed available) but adds a third engine's worth of prompt/config tuning and per-page cost to the eval budget. **Recommendation:** include it only if Claude vision and Tesseract don't cleanly bracket the decision (e.g. if Tesseract fails column integrity outright and Claude vision's cost is a real go-live concern, Textract is the natural middle option to check before committing). **Open:** time-box it explicitly, or drop it and decide between the two primary candidates.

**Q3 — the per-page quality-score formula (§3.4).** No formula fixed here — it depends on which engine's native confidence signal (if any) is available. **Open:** once the engine is chosen, is character-density-vs-expected the sole fallback signal for an engine with no native confidence (Claude vision), or is a second Claude call asked to self-rate its own transcription confidence acceptable (a model claim, weighted low, still deterministic-to-store even if not deterministic-to-produce)? Recommendation: avoid a second LLM call purely for self-rating — prefer the chunks:embed-reused over-strip/under-chunking signal (§3.4) once the doc is actually embedded post-verification, accepting that this specific signal only exists *after* verification, not at backfill-report time.

**Q4 — per-page cost if vision wins.** Not measurable until the eval runs (§2.3's cost/page metric) — this plan does not estimate it in advance, deliberately, since a guessed number here would just be an anchor the real eval should not be biased toward. **Open:** once measured, is the projected total cost for the full backfill (9 documents × their text-less page counts, §1.7 — at most ~105 pages across the inventory, well under the page-cap concern) acceptable as a one-off, or does it change the sync-vs-queued call (§3.6) or the "opt-in by default off" posture for future ingests?

**Q5 — what the real code makes non-obvious (restated so it can't be missed at build time).**
1. **`/embed` never reads `document_pages`** (`pipeline.py:23`, `ChunksEmbed.php:65`) — the single most important finding in this plan; OCR text in `document_pages.text` alone is a no-op for chunking (§1.3, §3.2).
2. **The page image OCR should run against already exists** — `/extract`'s pixmap render (`extract.py:37-38`) happens before the text-emptiness check even matters; OCR must reuse that exact JPEG/pixmap, not re-render from the PDF a second time.
3. **Every current text-less document also has `convenio_id = NULL`** (§1.6/§1.7) — OCR fixes the text; it does not fix scope. The backfill (§4) must fold in the *existing* tag re-suggest path or the "acceptance check" (§4.4) will fail on scope resolution even after a perfect OCR pass.
4. **The kickoff prompt's ids (89, 91, COEAS Estatal) are stale against current staging** (§1.7) — a re-ingest since `deploy.md` was written renumbered everything; the live query, not the doc, is the source of truth, and `deploy.md`/`roadmap.md` should be corrected at sprint close (§6.2 step 6).
5. **COEAS Estatal already has full text on staging today** — verified directly (74/74, 62/62 pages); it should be dropped from the 7e OCR backlog and left as a pure tagging-verification item.

---

## 8. Hard-constraint self-check

| constraint | how this plan satisfies it |
|---|---|
| Eval first; engine decision follows the numbers, recorded in the ADR; column integrity beats CER | §2 (eval design, decision rule §2.5 explicitly orders column integrity + header survival before CER); §6.2 step 2 (ADR-0026 written from measured numbers, not before) |
| OCR only fills text-less pages; output enters the existing pipeline unchanged; no LLM cleanup | §3.1 (page-level check inside the existing `/extract` loop); §3.2 (both integration options reuse `_classify_page`/the bilingual gate/`chunk_document` verbatim — no new prose logic in the chunking path); §3.4 (quality score is deterministic, no LLM re-judging OCR text) |
| OCR'd docs stay `under_review` + unembedded until verified; provenance visible | §4.1 step 4 (backfill never calls `confirm()`); §1.4 (the existing gate reused verbatim); §3.3 (badge + per-page marker) |
| Answer loop/chunker/embed untouched; golden-trace green; hr-ai never migrates; `--ocr` opt-in + page cap | §5.2 (explicit not-changed list); §5.3 (the gate + new no-op invariant test); §6.1 (the one migration lives in hr-backend); §3.5 |
| Feature-sprint gate: plan → review → build → review → commit | This document is the plan-review artifact; §6.2 names both review points explicitly; no code has been written this turn |

---

## 9. Definition of done (for the build turn, not this turn)

The eval runs against all 5 fixtures for at least Claude vision + Tesseract, with `score_ocr.py` output and the gold JSON committed; ADR-0026 records the measured column-integrity/header-survival/CER/cost/latency table and the resulting engine decision; the `/extract` OCR fallback + chosen integration option (§3.2) ship behind `--ocr`, page-capped; the new extract_language_streams no-op invariant test and `Sprint7cAdditivityRegressionTest` are both green; `document_pages.extraction_source` migration applied; the `⚙ OCR'd` badge + per-page provenance marker render in `hr-frontend`; `documents:ocr-backfill` runs against staging's real 9-document inventory (§1.7) and reports pages/quality/cost; docs 14, 31, 50 are OCR'd, human-verified (including scope resolution via the existing tag re-suggest path), embedded, and each passes the §4.4 acceptance check (chunks > 0, in retrieval, a scoped cited answer) as a named test case; `deploy.md`/`roadmap.md` corrected for the stale ids and the COEAS Estatal reclassification (§1.7); `sprint-07e/review.md` written. **No commit until review.**

---

**Ready for review. No OCR code written; no engine chosen. Files touched this turn: this plan, plus the 5 fixture images copied into `sprint-07e/eval/fixtures/`.**
