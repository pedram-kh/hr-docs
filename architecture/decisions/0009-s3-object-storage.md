# ADR-0009 — S3-compatible object storage

**Status:** accepted

## Context
Original files and per-page images need durable storage, accessible by `hr-backend` (uploads) and `hr-ai` (reads). The self-hosted-vs-cloud hosting question is still open and should not be pre-committed here.

## Decision
**S3-compatible storage.** `storage_path` / `image_path` hold opaque object keys. A single storage adapter wraps the S3 client; all access goes through it.

## Consequences
- Works with AWS S3 or a self-hosted MinIO without code changes — so this does **not** pre-commit the hosting decision.
- MinIO can back local dev.
- Bucket/provider changes never touch the schema or callers.
