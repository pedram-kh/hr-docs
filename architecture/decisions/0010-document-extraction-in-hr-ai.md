# ADR-0010 — Document extraction (PDF → text + page images) lives in hr-ai

**Status:** accepted

## Context
Ingestion must turn a source PDF into per-page text and per-page images (for chunking later, for citation display, and for the document card's source view). This is exactly the preprocessing the existing corpus already received (each PDF arrived as per-page `.txt` + `.jpeg`). PHP's PDF tooling is weak; Python's (PyMuPDF / pdfplumber) is strong and is what produced the clean extraction we verified. But `hr-backend` owns all database writes (ADR-0007), and that must not change.

## Decision
PDF extraction is an **`hr-ai` responsibility**, exposed as an endpoint that `hr-backend` calls during ingestion:
- Input: the S3 key of the uploaded original.
- `hr-ai` reads the original from S3, extracts text per page, renders each page to an image, **writes the page images to S3** (object storage — permitted; this is not a DB write), and **returns** `{ page_count, pages: [{ page_number, text, image_key }] }`.
- `hr-backend` then writes the `documents` and `document_pages` rows from that response.

`hr-ai`'s role therefore extends from "RAG/reasoning" to "document processing + RAG/reasoning." It still **never writes to the database** and **never migrates** — it writes only to S3 and returns data.

## Consequences
- Heavy document processing uses Python's mature tooling; PHP is not asked to render PDFs.
- DB-write ownership stays clean: only `hr-backend` writes `documents` / `document_pages`.
- Adds one service call on the ingestion path (acceptable — ingestion is a background admin action, not a latency-critical employee path).
- Sprint 1 scope is **PDF only**; other formats (docx misc, xlsx salary tables) are handled in later sprints.
