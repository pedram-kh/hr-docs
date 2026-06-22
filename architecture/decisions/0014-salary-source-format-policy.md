# ADR-0014 — Salary-source format policy & coverage-gap visibility

**Status:** accepted

## Context
Sprint 2a turns salary data into exact relational rows (`salary_tables` / `salary_table_rows`), queried by SQL and never embedded (ADR-0006). The real corpus carries salary figures in **three** forms, with very different extraction reliability:

1. **Structured `.xlsx`** (e.g. `TABLAS COEAS Andalucia.xlsx`, `Tabla Salarios Deporte Cantabria.xlsx`, the Estatal ones) — already a grid. Inspecting these shows real but *bounded* messiness: junk/notes sheets left over from PDF→xlsx conversion, the header not on row 1, and cryptically-labelled columns (`14`/`12` = monthly over 14/12 payments, a bare hours number = hourly rate, `SB`/`COMP`/`Comp. SMI` = breakdown). Tractable with a small per-format header map.
2. **Salary grids embedded inside a convenio PDF** (e.g. Gipuzkoa *Limpieza* `20000785011981`, tables on BOG pages 33–35) — extracting an exact numeric grid from a 50-page legal PDF is error-prone, and a wrong salary figure carries legal weight.
3. **`.doc/.docx` prose** convenios (e.g. *Deporte Navarra*) — neither clean grids nor in the PDF-first prose path.

A salary figure must be **exact**. Reliability of the source therefore drives what we extract now versus later.

## Decision
- **xlsx-first.** Sprint 2a extracts salary **only from `.xlsx`** sources, via the `hr-ai` extract-and-return parser (ADR-0010 pattern); `hr-backend` writes the rows and populates `convenio_job_categories` from them as a deliberate, logged, idempotent import (ADR-0002).
- **In-PDF salary-grid extraction is deferred.** Salary tables embedded inside convenio PDFs are **not** parsed this sprint — the error rate on exact figures is not acceptable, and the dedicated approach is later work.
- **`.doc/.docx` prose is deferred** (both salary and prose-convenio handling for these formats).
- **Coverage gaps are surfaced, never silent.** A convenio whose salary lives only in a PDF (or `.doc`) legitimately has **zero** salary rows in 2a. The system must show this as **"no salary rows yet"** — distinct from "this category has no such pay concept" (a legitimate NULL). The `salary:import` summary lists the gap convenios; the verification harness prints the gap message instead of an empty result; the same signal seeds the later analytics coverage-gap surface (architecture §10.5).
- **The `.xlsx`→convenio association rides the existing tagging path.** Salary `.xlsx` ingest as `salary_tables`-type `documents` through the Sprint-1 `FilenameParser`/conflict/review machinery. Because most salary filenames lack a `numero`, expect many to land `under_review` for deliberate admin convenio assignment before their rows populate — this is the intended controlled path, not a failure.

## Consequences
- Salary answers come only from clean structured sources in 2a, so exactness is protected; the SQL path is trustworthy where it has data.
- The salary-SQL surface has **known, visible coverage gaps** (any convenio whose salary is PDF/`.doc`-only), tracked rather than hidden, and prioritisable later.
- In-PDF salary-grid extraction and `.doc/.docx` handling become explicit future work, with a clear bar to clear (exact-figure reliability) before they ship.
- Numero-less salary `.xlsx` add a deliberate human step (convenio assignment) to the ingestion path — acceptable, and consistent with the controlled-vocabulary / human-confirms discipline.
