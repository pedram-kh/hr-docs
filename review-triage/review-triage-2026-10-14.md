# Review queue triage — 2026-10-14

Source: `hr-docs/review-triage/under-review-2026-10-14.md` (Cursor's read-only export, 47 documents; COEAS Madrid #96 was already confirmed before the export).

## Two facts decide everything

1. **Employees only get answers from documents that meet all of these:** active, tags confirmed, embedded (`chunks:embed`), and bound to the employee's convenio (or national law). Historical documents are never used for employee answers today.
2. **Confirm tags does not assign a convenio and does not embed.** It only marks the labels as checked and closes the open tasks.

So a click can only help an **active prose document that is already bound to a convenio**. In this queue there is exactly one: **#76**. Everything else is either waiting on someone else, or has no effect on answers if confirmed.

## 1. Do now (Pedram, in the app)

| # | Document | Action | Why |
|---|---|---|---|
| 76 | 99100055012011 COEAS Estatal 2025 2027 | **Confirm tags.** Do not approve the "new sector" proposal (Cancel it if open). | Scope is correct (convenio 99100055012011, Estatal, Ocio educativo). The conflict is "COEAS" against "Ocio educativo y animación sociocultural": the same sector, abbreviated. It is the convenio's only current text; 3 employees. |

Already done: #96 COEAS Madrid 2023 2027.

## 2. Then (Cursor)

> Run `chunks:embed --dry-run` and confirm it selects documents 96 (COEAS Madrid 2023 2027) and 76 (COEAS Estatal 2025 2027). Report anything else it would select and do not embed those. Then run `chunks:embed --document=<uuid>` for 96 and 76 only, and report the chunk count for each. No other changes. Stop.

Check afterwards: open each document → Sandbox → "¿Cuántos días de vacaciones tengo?" → Test. A cited answer means those employees now get answers from their convenio.

## 3. Correction to earlier advice

- **Skip Deporte Estatal 2023 2025 (#73).** It is historical (validity ended 2025-12-31), so confirming it changes nothing. The convenio's only active document is the partial agreement #85. The question is for the client (section 5).

## 4. Leave as they are (no click), with the reason

### A. Company or workplace agreements: the system cannot give these an owner (7)
| # | What it is |
|---|---|
| 31 | Company pact, Navarra centres, Dec 2019 (**duplicate of #47**, active) |
| 47 | Same company pact (historical) |
| 14 | Strike-end agreement for one library centre, 2019–2022 |
| 55 | Company equality plan 2023–2027 (typed as convenio text; retype ticket already exists) |
| 60 | Company salary increase 2023 (category table) |
| 68 | Working conditions of subrogated staff, one Bizkaia programme |
| 69 | Agreement for subcontracted sports staff, one municipal body |

Scope in this system rides on a convenio, so a company-level document has no correct owner. Do not accept the AI's convenio suggestions on #31/#47 (Álava convenio for a Navarra pact) or #68/#69. See the product question in section 6. Housekeeping later: retire #31 as the duplicate of #47.

### B. Reference sources: their facts already carry the scope (2)
105 PERIODOS DE PRUEBA, 106 PERÍODOS PRUEBA ACTUALIZADOS 2026. They are meant to stay without a convenio.

### C. Waiting on the client or on identification (4)
| # | Document | Waiting for |
|---|---|---|
| 7 | TABLAS SALARIALES Deporte Estatal | The year it governs (already in `client-asks.md` item 4) |
| 90 | Partial agreement, II Convenio Ocio Educativo **Andalucía** (2024, code 71100045082024) | Client: is the II Convenio Andalucía signed, and does this "Alhambra" agreement apply to your staff? |
| 10 | Tablas acuerdo parcial Alhambra | Same question as #90 |
| 5 | "Tabla 2026.xlsx": no text, convenio unknown | Cursor: read the sheet headers and say which convenio it belongs to |

**Do not bind #90 to the Andalucía COEAS convenio (c4) yet.** If it only covers one workplace, binding it would give its terms to every c4 employee.

### D. Summary (1)
54 Resumen: a summary of the Deporte Cantabria convenio (39100935012024). It is not used for answers. Optional: bind it to Deporte Cantabria for tidiness.

### E. Historical (33): no effect on answers today
Confirming them changes nothing for employees. **Do not bind them to convenios either:** binding an old text to a convenio that today has *no* text switches that convenio from "never loaded" (which answers from the Estatuto) to "expired" (which escalates). Example: **#82 is the 2015–2017 text of Acción e Intervención Social Estatal**, the convenio of the `test-fullgap@` account. Binding it would turn off the Estatuto answers for those employees.

| # | What it is (from its cover) | Note |
|---|---|---|
| 15 | Limpieza Gipuzkoa 2010–2017 | |
| 16 | Limpieza Gipuzkoa 2018 (20000785011981) | |
| 17 | VIII Enseñanza no reglada (Basque Country) 2014–2018 | Only 2 of 35 pages have text |
| 21 | Hostelería **Gipuzkoa** 2008–2010 | Only old Hostelería Gipuzkoa text in the system |
| 22 | Información y documentación Gipuzkoa 2022 (20104415012022) | Basque text |
| 29 | Hostelería Huesca 2022–2024 (bound, false "HOSTELERIA" conflict) | 1 employee, no newer text: client question |
| 37 | Hostelería Navarra 2013–2014 | |
| 38 | Oficinas y despachos Navarra 2017 | |
| 39 | Gestión deportiva Navarra 2012 | |
| 40 | Deporte Navarra 2016–2020 (bound, false "DEPORTE" conflict) | Current 2025–2028 text (#50) already active and verified |
| 42 | Oficinas y despachos Navarra 2010–2014 | |
| 44 | Hostelería Navarra 2019 | |
| 45 | Hostelería Navarra 2015–2017 | |
| 48 | Deporte Navarra 2013–2015 | |
| 49 | "oficinas-despachos revisión salarial" | **Wrong file:** the cover is an unrelated Navarra government decree |
| 61 | Intervención social **Álava** 2014–2016 (01100025012015) | Only Álava text in the system: client question |
| 62 | Actividades deportivas Álava 2020–2023 (01003205012006) | |
| 66 | Locales y campos deportivos Bizkaia 2018–2022 | Likely the same convenio as #71 (two sources) |
| 70 | Intervención social Bizkaia 2017–2021 | Likely the same convenio as #72 (two sources) |
| 71 | Locales y campos deportivos Bizkaia (BOB 2020) | See #66 |
| 72 | Intervención social Bizkaia (BOB 2019) | See #70 |
| 73 | V Instalaciones deportivas y gimnasios estatal 2023–2025 (bound, false "DEPORTE" conflict) | Text ended 2025-12-31: client question |
| 78 | Partial change to the IV Instalaciones deportivas convenio, 2023 | |
| 82 | Acción e intervención social **estatal** 2015–2017 (99016085012007) | **Do not bind** (see above) |
| 83 | II marco estatal Ocio educativo, 2015 | |
| 87 | COEAS Estatal 2020–2023 (bound, false "COEAS" conflict) | Superseded by #76 |
| 98 | I Ocio educativo Madrid 2018 (28102145012018) | Superseded by #96 |
| 8 | Salary tables COEAS 2021–2022 | |
| 20 | Salary revision Hostelería Gipuzkoa 2010 | |
| 46 | Salary revision Oficinas y despachos Navarra 2014 | |
| 88 | Salary tables Acción e intervención social estatal 2018–2021 | |
| 97 | Salary revision Ocio educativo Madrid 2021–2023 | |

(#47 is listed under A.)

## 5. Add to the client email (`client-asks.md` item 3 and 5)

- **Deporte Estatal / Instalaciones deportivas (c9):** the V convenio ended 2025-12-31. Is it still applied (ultraactividad), or is there a newer one?
- **Hostelería Huesca:** we only have 2022–2024; one employee. Current text?
- **Ocio Educativo Andalucía (c4):** a 2024 partial agreement refers to a II Convenio Andalucía (code 71100045082024). Is it signed? Please send it. Does the "Alhambra" agreement apply to your staff?
- **Acción e Intervención Social Estatal:** we only have the 2015–2017 text. Please send the current one.
- **Intervención Social Álava** (already item 5): we only have 2014–2016.
- **Hostelería Gipuzkoa** (already item 5): we only have 2008–2010.
- **Company agreements:** which Sedena pacts and centre agreements are still in force?

## 6. Product question for Pedram (not a click)

Seven documents are company or workplace agreements that **improve on the convenio** for some staff. Example: #68 gives one programme's staff 1,635 annual hours and 100% sick pay. Today the system cannot attach a document to a company or a work centre, only to a convenio. An employee covered by such a pact would get the convenio answer: correct as the legal minimum, but not what they actually get.

Options before the pilot:
- (a) Accept it for the pilot and keep these documents out. Answers stay convenio-only; HR handles company-pact questions through escalation.
- (b) Plan a "company / work-centre agreement" scope as its own sprint after the pilot shows how often it matters.

Recommendation: (a) for the pilot, and ask the client in the email which pacts are still in force, so we know the size of (b).

## 7. Engineering follow-ups (later, not now)

- Embed automatically after Confirm tags (already planned).
- Retire #31 as the duplicate of #47.
- Identify #5 "Tabla 2026.xlsx".
- The sector-conflict rule flags abbreviations (COEAS, DEPORTE, HOSTELERIA) as conflicts. Five false conflicts in this queue. Consider teaching it the abbreviations, or not flagging when the convenio number matched.
