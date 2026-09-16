# Correction-salary-02 — a mislabelled monthly column that `salary:audit-monthly` cannot catch

**Status:** built, gated, merged. Branch `correction-salary-02` (`hr-ai`, `hr-docs`).
**Scope:** exactly the parser fix, its tests, and the two doc bindings it unblocked. No new behaviour beyond that.

## 1. The failure this prevents

`_MONTHLY` (`hr-ai/app/salary.py`) decides what a spreadsheet column means by its **header label alone** — `sb`, `salario base`, `sueldo mensual`, and a handful of synonyms are read as a stated monthly base salary (Correction-salary-01, ADR-0027). The same label does not mean the same quantity in every workbook in this corpus:

- **COEAS Estatal** (doc #6) heads its annual base **`SB Anual`**. That normalizes to `sb anual`, which sits in `_RAW_MONEY` (raw-only, never typed) — correctly left untyped.
- **COEAS Andalucía** (doc #11) heads the *same quantity* bare **`SB`**. That normalizes to `sb`, which is in `_MONTHLY` — so before this fix, the annual would have been typed as the monthly base.

Measured on the real file, before the fix, for every category in both of doc #11's sheets:

| category | would-be `base_salary_monthly` | = its own `gross_annual` | the real monthly, sitting unread |
|---|---|---|---|
| Director/a Gerente | 22.058,76 € | 22.058,76 € | **1.575,63 €** (bare `14` column) |
| Jefe/a de Departamento | 19.680,81 € | 19.680,81 € | **1.405,77 €** |

An employee asking their salary would have been told a monthly base **≈14× the real figure**. This is not Correction-salary-01's failure mode (nothing is *computed*), but it lands in the same column with the same consequence.

**Why the standing guard does not catch it.** `salary:audit-monthly` (the permanent Correction-salary-01 guard) asks exactly one question: *does a source cell back this stored figure?* `22.058,76` genuinely **is** a source cell, sitting verbatim under `raw_values['sb']` — it is simply the wrong source cell for what the column is labelled to mean. The audit's contract is "sourced or refused," and a mislabelled header produces a figure that is sourced *and* wrong at the same time. That combination is outside what the audit was built to detect, by design, not by omission — closing it needs the parser to know its own header is lying, not the audit to double-check arithmetic it has no ground truth for.

## 2. The fix — two terms, and why one alone was not enough

Both terms live in `_parse_sheet` and both mirror the existing `_bounded`/`_FIELD_BOUNDS` precedent that Correction-salary-01 established: **the typed value is dropped, the figure stays verbatim in `raw_values`, and the refusal is reported in `warnings`.** Nothing is guess-corrected; a refused monthly is reported as an annual instead, exactly like a source that never had a monthly column at all.

### 2.1 The row-level term (`_monthly_under_annual`) — tried first

A monthly that is not strictly below its own row's annual is not a monthly, whatever the header calls it. Simple, local, and it fires — on doc #11 it caught **22 of 27 rows** in `smi 26` and **23 of 27** in `smi 25`.

**But it is not sufficient, measured, not assumed.** Five rows in each sheet carry a `COMP.`/`Comp. SMI` top-up, which pushes `TOTAL` above `SB` on that row alone — so `SB < TOTAL` and the row-level test passes, exactly where it needed to fire:

| category | `SB` (would still be typed as monthly) | `TOTAL` | real monthly, unread |
|---|---|---|---|
| Mediador/a Intercultural Educativo | 17.411,25 € | 17.847,05 € | 1.243,66 € |
| Experto en Talleres o Tallerista | 16.398,41 € | 17.094,01 € | 1.171,32 € |
| Mediador cultural | 17.416,20 € | 17.852,00 € | 1.244,01 € |
| Coordinador/a de actividades y proyectos de centro | 16.714,81 € | 17.094,01 € | 1.193,92 € |
| Monitor/a de ocio educativo y Tiempo Libre | 16.658,20 € | 17.094,00 € | 1.189,87 € |

**9 rows across the two sheets would still have stored an annual as a monthly** under the row-level term alone.

### 2.2 The column-level term — the load-bearing one

Whether a column holds monthlies or annuals is a property of the **column**, not any individual row, so it is decided once, before a single row is typed: if the monthly-labelled column is not below the annual column on a **majority** of the sheet's data rows, the column holds annuals, and `base_salary_monthly` is refused for **every** row of that sheet — including the five top-up rows the row-level test missed. One warning per sheet, not per row. `base_salary_monthly` is also dropped from the sheet's `typed_fields` diagnostic.

This is what makes the top-up rows decidable at all: on their own, `Mediador/a Intercultural Educativo`'s row looks perfectly innocent (17.411,25 < 17.847,05 — a real monthly-under-annual relationship). It is only innocent in isolation. The column's own majority verdict — `sb` fails to be below `total` on 22 of 27 rows — is the signal that survives, and it correctly overrules the one row that would otherwise have looked fine.

The row-level term stays in place as **defence in depth**: it still refuses a lone anomalous row inside an otherwise genuine monthly column (contract case 8c), a case the column-level majority test would not catch on its own.

## 3. Contract test coverage (`hr-ai/scripts/salary_parser_test.py`)

Four new cases, all passing, alongside all pre-existing Correction-salary-01 cases (1 through 7):

- **8** — a monthly-labelled column holding an annual throughout (COEAS Andalucía's bare `SB`) is refused for the whole sheet; the annual is still stored as the annual; the real monthly and the mislabelled figure both survive verbatim in `raw_values`; the refusal is reported.
- **8a** — the column verdict governs the top-up rows the row-level term alone cannot catch: a 3-row fixture (two `SB == TOTAL` rows, one `SB < TOTAL` top-up row) refuses `base_salary_monthly` on **all three**, with exactly one column-level warning, not three.
- **8b** — a legitimate monthly genuinely below its annual is unaffected by either term.
- **8c** — the row-level term is still load-bearing on its own: a real monthly column (two good rows, monthly below annual) with one anomalous row (`Salario base == Bruto anual`) refuses only that one row; the column-level term does not fire here because the column's majority is genuine.

## 4. Regression evidence before anything was written

A read-only script (`salary:import`'s own `/extract-salary` path, called but never written from) re-parsed **every already-bound salary `.xlsx` in the corpus** with the new parser and compared, per stored table, the row count and the monthly-bearing row count against what is currently in `salary_table_rows`:

**Run before doc #11 existed as a stored table: 17 tables, zero drift.**
**Re-run on the merged branch code, after doc #11's own import: 18 tables (adds c4's two), zero drift.**

Every table that legitimately carries a monthly is reproduced exactly — c18 (5+4 monthly rows), c19 (32), c6 (6+5), c10 (5+5), c15 (5+5) — the full set the standing audit already vouches for. The change is inert for the existing corpus and only ever *removes* a figure the source did not actually state; it never adds, changes, or derives one.

```
#1  c18 y2026 '2026'        : rows 12/12  monthly  5/5   same
#1  c18 y2025 '2025'        : rows  4/4   monthly  4/4   same
#2  c22 y2026 '2026'        : rows  3/3   monthly  3/3   same
#2  c22 y2024 '2024-2025'   : rows  5/5   monthly  0/0   same
#3  c19 y2026 '2026'        : rows 32/32  monthly 32/32  same
#3  c19 y2024 '2024-2025'   : rows 32/32  monthly  0/0   same
#4  c6  y2026 '2026'        : rows  6/6   monthly  6/6   same
#4  c6  y2025 '2025'        : rows  5/5   monthly  5/5   same
#6  c11 y2026 'Marzo-Sept…' : rows 32/32  monthly  0/0   same
#6  c11 y2025 '2025 SMI 26' : rows 27/27  monthly  0/0   same
#6  c11 y2024 '2024-2025'   : rows 27/27  monthly  0/0   same
#9  c10 y2025 '2025'        : rows  5/5   monthly  5/5   same
#9  c10 y2024 '2024'        : rows  5/5   monthly  5/5   same
#11 c4  y2026 'smi 26'      : rows 27/27  monthly  0/0   same   (new binding)
#11 c4  y2025 'smi 25'      : rows 27/27  monthly  0/0   same   (new binding)
#102 c15 y2025 'page 2'     : rows  5/5   monthly  5/5   same
#103 c15 y2026 'page 2'     : rows  5/5   monthly  5/5   same
#104 c3  y2025 '2025'       : rows 30/30  monthly  0/0   same
#104 c3  y2026 '2026'       : rows 30/30  monthly  0/0   same

DRIFT_TABLE_COUNT=0
```

## 5. The two doc bindings this unblocked

Both went through the real admin route (`PATCH /admin/documents/{uuid}/facets/convenio` with `confirm_scope_change`, then `POST /confirm`) — never raw SQL — so provenance is recorded in `tag_events` for both.

### Doc #6 → convenio 11 (COEAS Estatal → Ocio Educativo y Animación Sociocultural Estatal)

Content confirmed before binding: all three sheets state `COEAS ESTATAL`, years 2026/2025/2024 stated in each sheet's own header. Import: **3 tables, 86 rows, 32 job categories created.** `base_salary_monthly` NULL for all 86 rows — correctly, because this workbook's monthly-shaped columns (`Bruto/mes 14 pagas`, `Bruto/mes 12 pagas`) are *gross* monthlies (base + prorated extras), a different quantity from `base_salary_monthly` by design, and stay in `raw_values` only. `salary:audit-monthly` exit 0.

### Doc #11 → convenio 4 (COEAS Andalucía → Ocio Educativo y Animación Andalucía)

Content confirmed before binding: `COEAS ANDALUCIA`, years 2026/2025 stated in sheet names, cross-references convenio 11's own code as the base the Andalucía increment applies to. Import (after the fix): **2 tables, 54 rows, 27 job categories created.** `base_salary_monthly` NULL for all 54 rows, with the column-level refusal reported explicitly on both sheets:

```
sheet 'smi 26': the column headed 'SB' is not below 'TOTAL' on 22 of 27 rows —
it holds ANNUAL figures in this workbook, not a monthly base: base_salary_monthly
left NULL for EVERY row of this sheet
sheet 'smi 25': the column headed 'SB' is not below 'TOTAL' on 23 of 27 rows —
[same]
```

`salary:audit-monthly` exit 0, 319 rows across 19 tables, no discrepancies.

**Answer loop, both convenios verified live:**

- **c11** (`test-coeas-estatal@example.com`, seeded for this): *"Para la categoría Animador/a Sociocultural., según la tabla salarial de 2026 de tu convenio: bruto anual de 17.574,51 €; precio/hora de 10,0887 €/hora."* — annual only, no monthly quoted.
- **c4** (`test-andalucia@example.com`, existing, no job category on profile → constrained pick offered 27 categories → re-asked with `Animador/a Sociocultural` selected): *"Para la categoría Animador/a Sociocultural (según tu indicación), según la tabla salarial de 2026 de tu convenio: bruto anual de 17.400,42 €; precio/hora de 9,9888 €/hora."* — same shape, no monthly.

Cobertura's salary cell flipped for both: c11 and c4 now `covered: true, year: 2026`; c9 (doc #7, left unbound pending a year the source does not state — out of scope for this correction) is unchanged.

## 6. What is explicitly out of scope here

- **Doc #7 → convenio 9** — the source states no year anywhere; left unbound. Tracked separately in the data-pass handoff, not part of this correction.
- **Doc #55 retype, the Cultura Navarra doc #31 duplicate finding, the two registry gaps (Gipuzkoa hostelería / Álava)** — all found in the same working session but unrelated to the salary parser; recorded separately in `roadmap.md`/`deploy.md`/the data-pass handoff, not folded into this branch.
- **`salary:audit-monthly`'s own reporting** now describes c4's 54 rows as *"the source states a monthly that no typed column holds — stated by header `sb`"* — technically imprecise (the source states no monthly at all; `sb` is an annual), but nothing wrong is stored and the audit still exits 0. Recorded as a small follow-up in `roadmap.md`, not fixed here (cosmetic, reporting-only, no wrong data).
