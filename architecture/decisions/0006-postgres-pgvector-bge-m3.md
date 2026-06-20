# ADR-0006 — Postgres + pgvector + BGE-M3; three storage layers

**Status:** accepted

## Context
The corpus is three different kinds of data: prose legal text (good for vector RAG), structured salary tables (numeric grids — embeddings mangle them, and figures must be exact), and structured registry/metadata. Content is Spanish with some Euskara. A dedicated vector DB would be over-engineering at this scale.

## Decision
- **PostgreSQL** as the system of record, with **pgvector** in the same database for prose chunk embeddings (denormalised scope columns for pre-filtering).
- **Structured salary tables** in relational tables, queried via SQL — **never** vectorised.
- **S3-compatible object storage** for source files and page images (ADR-0009).
- Embedding model: **BGE-M3** (multilingual, open-weight, self-hostable), **1024 dimensions**. The dimension is locked after a first-task retrieval test in `hr-ai` on real ES + EU convenios.
- Language is recorded for citation/display only — **never** a retrieval filter, facet, or lens.

## Consequences
- One database to operate; no separate vector store.
- Salary answers are exact lookups, not fuzzy matches.
- Swapping the embedding model later means a re-embed and a column-width change — acceptable, and de-risked by the upfront test.
