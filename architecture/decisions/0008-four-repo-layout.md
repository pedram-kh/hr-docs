# ADR-0008 — Four separate repos in one workspace

**Status:** accepted

## Context
The system has three deployable services plus the docs. Separate git histories and remotes are wanted (independent, releasable services). Cursor must still see the whole picture, and separate repos normally mean it sees only one at a time.

## Decision
Four independent git repos — `hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs` — cloned side by side into one local `hr-platform/` folder that is opened as a single Cursor workspace. The `hr-platform/` root is not itself version-controlled.

## Consequences
- Independent versioning and deploys per service; Cursor sees all four at once locally.
- No root-level shared file is version-controlled, so each code repo carries its **own** lean `AGENTS.md`; the canonical full docs live in `hr-docs`. Load-bearing cross-cutting rules (controlled vocabulary, faceted model, the Laravel↔Python contract) are copied short into each code repo's `AGENTS.md`, and correction prompts must name which repos' `AGENTS.md` to update so they don't drift.
