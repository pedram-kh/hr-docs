# ADR-0027 — A salary figure is a source cell or it is not stored (Correction-salary-01)

**Status:** accepted — supersedes the "14/12 canonical mapping" of ADR-0014 / `sprint-02a` plan-review catch 3.

## Context

Sprint 2a made a deliberate simplification, recorded in `data-model.md` §6: Spanish salary tables express a monthly figure over **14** payments (12 months + 2 extras) and/or over 12, and the column labels vary wildly (`14`/`12`, `Bruto/mes 14 pagas`, `Salario Base`, `Bruto mes`). To keep the typed columns comparable across formats, `salary.py` computed

```
base_salary_monthly = gross_annual / 14
num_payments        = 14
```

for **every** row that had an annual figure, whatever the source said, and preserved the source's own figures verbatim in `raw_values`.

The 14 was never sourced. It was a norm assumed to hold everywhere, and it does not:

- **Convenio 15** (Gestores Información Gipuzkoa, the two OCR-derived tables from docs 12/27) prints both figures in its own gazette: annual **33.491,36 €**, monthly base **2.232,75 €**. Its annual is 15 × its monthly, not 14 ×. The chat answered **2.392,24 €** — a figure that appears nowhere in the convenio, ~7 % above what the employee is actually paid monthly.
- **Convenio 22** (Limpieza Navarra) pays over 16: its own sheet prints `SB` 1.343,28 and a `PAGA 16` column of 1.546,79 × 16 = the annual. Stored monthly was 1.767,76 — 32 % high.
- **Convenio 10** (Agencias de Viajes) prints `Salario base` 1.218,84 with the rest of the annual made up of pluses. Stored monthly was 1.564,11 — 28 % high.

This directly contradicts ADR-0006's whole reason for answering salary from SQL rather than from prose: the figure is exact **because it is the source's own cell**, bound to its category and year by construction. A computed figure has none of that authority, and it is indistinguishable from a sourced one once it is in the column.

The `/14` also masked real coverage. Convenios 18 and 19 have sheets whose annual column is headed by a bare year (`2026`), so `gross_annual` was NULL and no monthly was derived either — while the same sheets printed `SB` and `14 pagas` columns that were sitting unread in `raw_values`.

## Decision

**A typed salary figure is read from a source cell or it is not stored.**

The fix is *never derive*, not *use the right divisor*: no correct constant exists to be found, because the convenios in this corpus divide by 12, 14, 15 and 16, and a figure the source never printed cannot be cited even when the arithmetic happens to be right.

1. `base_salary_monthly` is written **only** from a column the source labels as a monthly base (`SB`, `Salario base`, `Salario base (mes)`, `Sueldo mensual`, or an `N pagas` column). It is **NULL** when the source states none. It is never computed from the annual.
2. `pagas_count` (renamed from `num_payments`, whose old name invited the old behaviour back) is a typed field **in its own right**, set only when a header states it (`14 pagas`, `Bruto/mes 12 pagas`), NULL otherwise, and **never used to derive another figure**. A `PAGA 16`-style label — the value of one payment, not a count of them — is deliberately not read as a count.
3. Where a sheet prints several stated monthlies side by side (COEAS Navarra prints `14 pagas` and `12 pagas` in adjacent columns), the 14-pagas column is stored and the alternative is named in a warning and kept verbatim in `raw_values`. This is a choice **between two source cells**, which is categorically different from computing a figure the source never printed. Where the header states two different counts and no single monthly column, `pagas_count` stays NULL rather than being guessed.
4. `SalaryAnswerService` states only stored figures. With no stored monthly it gives the annual, adds the pagas count when the source stated one ("bruto anual de 31.234,54 € (tabla expresada en 14 pagas)"), and **omits** the monthly rather than dividing. The employee gets a narrower answer, not a wrong one; the alternative — quoting a monthly the payslip will contradict — is exactly the failure the salary path exists to prevent.
5. `salary:import` **fails loudly** (non-zero exit, no success line, nothing written for that document) when a sheet was recognized as a salary grid but yielded zero rows, or when its header maps to no typed field at all. hr-ai reports this per sheet in a new `sheet_diagnostics` block (`ok` | `empty` | `no_header` | `header_but_no_rows` | `header_maps_to_nothing`); a notes/junk sheet with no header stays benign, as designed. A silent zero-row import is how a coverage gap disguises itself as a completed one — and that is how the derived `.xlsx` of docs 12/27 first looked "imported" while writing nothing.
6. `salary:audit-monthly` is the permanent guard: read-only, it compares every stored monthly against what the source states in `raw_values` and exits non-zero on any discrepancy, any monthly with no source cell behind it, and any `pagas_count` no header states.

## Consequences

- **`base_salary_monthly` is NULL for more rows than before, and correct where it is not.** Measured across the corpus after the re-import (`salary:audit-monthly`, 179 rows in 14 tables): **21 rows** in 5 tables (convenios 10, 15, 22) had a wrong monthly replaced by the source's own; **11 rows** in 2 tables (convenio 6, Cantabria) are unchanged, because that source's `Salario base` happens to equal annual/14 — the coincidence that let the assumption survive review in Sprint 2a; **43 rows** in 4 tables (convenios 10, 18, 19) keep a monthly that was always read from a real `SB`/`14 pagas` column; and **32 rows** (COEAS Navarra 2024) *lost* one, because that sheet is a multi-year block stating `14 pagas` twice — once per year — so no monthly can be attributed to the table's single year (point 3's NULL case, reported by the audit as coverage, not as a failure).
- **The golden-trace salary answer was re-baselined deliberately** (`Sprint7cAdditivityRegressionTest`). The originally pinned turn is byte-for-byte unchanged, because its fixture stores both figures — the correction only ever removed derived ones. A second turn now pins the shape that did change: a source with an annual and no monthly answers with the annual alone, where it previously answered "salario base mensual de 1.500,00 € en 14 pagas". The live before/after is convenio 15: **2.392,24 € → 2.232,75 €**. This is a correctness re-baseline, not drift.
- **`raw_values` was always right, and is what made the correction possible at all.** Every figure the parser now reads was already sitting in `raw_values`, verbatim, from the original import — which is why the audit could quantify the damage before any re-ingest, and why the fix needed no new OCR and no new source material.
- **`num_payments` → `pagas_count` is a breaking rename** of a `salary_table_rows` column, the hr-ai `/extract-salary` row contract, and the `salary` trace block. Deliberate: a field that used to mean "the divisor we applied" and now means "what the source states" should not keep the name that invited the first meaning.
- The 14-over-12 preference in point 3 is the one surviving norm-shaped choice. It is confined to picking between columns the source itself printed, is reported in a warning on every affected sheet, and never invents a value — but it is the place to look first if a convenio's monthly ever reads oddly again.
