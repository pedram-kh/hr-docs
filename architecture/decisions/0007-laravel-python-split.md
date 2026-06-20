# ADR-0007 — Laravel backend + separate Python AI service

**Status:** accepted

## Context
Laravel (PHP) is excellent for auth, CRUD, roles, and business logic — the bulk of the app. But the RAG/embedding/LLM-orchestration ecosystem lives almost entirely in Python. Hand-rolling chunking and retrieval in PHP would forgo mature tooling on the most delicate part of the system.

## Decision
`hr-backend` (Laravel) is the system of record and API; it **owns the entire relational schema and all migrations**, and resolves employee scope deterministically. `hr-ai` (Python/FastAPI) owns embeddings, vector search, the router, answer synthesis, and the LLM tagging tier; it **reads** registry/scope tables and **reads & writes** `document_chunks` only, and never runs migrations. Laravel calls `hr-ai` with the query + resolved scope; `hr-ai` returns answer, citations, confidence, and trace.

## Consequences
- Each language does what it is strongest at.
- Cost: one extra service to deploy and a defined HTTP contract between the two.
- Clear database ownership avoids migration conflicts; the contract must be kept in sync in both repos' `AGENTS.md`.
