# Review-queue triage — 146 `needs_review` facts (staging, 2026-09-15) — RESOLVED EDITION

Every fact now has a single action. No judgment calls left open — where the export left a choice, I made it and wrote the reason. The verify click stays yours.

## Totals by action

| Action | Count |
|---|---|
| VERIFY (as-is) | 99 |
| FIX THEN VERIFY (exact edit text given) | 4 |
| REJECT (reason given) | 5 |
| VERIFY AS HISTORY (old table; already out of validity) | 30 |
| AUTO-CLOSED by "Resolver versión" | 6 |
| NOTHING (already superseded) | 2 |

Order of work (demand-ranked): vacaciones → permisos → jornada → festivos → periodo de prueba 2026 → periodo de prueba history.

## Step 0 — Cursor prep (do first, ~30 min of its time, saves you 19 edits)

19 facts have null validity, clustered by source document: convenios **3, 6, 10, 18**. Paste to Cursor:

> Read-only check, then a pre-named data update. (1) For the convenio-text documents of convenios 3, 6, 10 and 18, report each vigencia article verbatim and the registry's recorded validity window. (2) For convenios 3, 6, 10: set the document validity from the vigencia article and copy it onto their null-validity `needs_review` facts — list the exact ids before writing. (3) For convenio 18: report only. Rule for Pedram's decision: if the registry/document is `active` and the vigencia has not ended, set its facts' validity to that window; if the vigencia ended and the text is in ultraactividad, this is a convenio-4/21 situation — leave the c18 facts unverified and add c18 to the data-pass expired list. (4) Confirm the six "Resolver versión" pairs work from the review UI: #42→#9, #48→#38, #50→#30, #51→#31, #56→#28, #80→#12. (5) Optional but valuable: run `facts:segment-topic "periodo de prueba"` for convenios 8, 9 and 11 (three calls, ~$0.40) — they have no 2026 periodo-de-prueba row at all (see Coverage gap). Then STOP.

## Manual creates (two, via the Reference facts "create" lane)

1. **c8 (Enseñanza y Formación no Reglada) — vacaciones, convenio-wide.** Value: *"Todas las personas trabajadoras disfrutarán de un mes de vacaciones retribuidas al año, o la parte proporcional al tiempo trabajado, a disfrutar en los períodos de menor actividad empresarial, preferentemente en verano; la empresa fijará el calendario y los turnos con al menos dos meses de antelación."* Source: document of c8, p18 (Vacaciones article), validity 2024-01-01 → 2027-12-31. Without this, no ungrouped c8 employee can ever get a vacaciones answer (the rule only lives inside the two group facts #130/#131).
2. Nothing else — every other gap is a data-pass item, not a manual create.

## Coverage gap to flag to HR (not fixable by clicking)

Convenios **8, 9 and 11** have periodo-de-prueba facts only from the OLD table (validity ended 2025-12-31) — the 2026 table has no rows for them. After you verify those as history, those three convenios have **no current periodo-de-prueba fact**. Two ways to close it: HR confirms the values are unchanged (then "Fix then verify" a copy with open validity), or Cursor's optional step (5) segments the topic straight from the convenio text.


## FIX THEN VERIFY (4) — exact edit text

| id | convenio | scope | action | what to change |
|---|---|---|---|---|
| #140 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Personal general (resto de grupos profesionales, salvo excepciones expresamente previstas) | **FIX THEN VERIFY** | 1) clear the group label (convenio-wide). 2) prefix the value with: "Con carácter general, salvo para los Técnicos de Actividad deportiva y los Técnicos de Sala, que tienen jornada propia: " — both facts supported by the same page. |
| #147 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Personal en régimen de guardia o expectativa | **FIX THEN VERIFY** | append to the value: "La prestación de servicios se calendariza con previsión semanal conocida con 3 días de antelación; la disponibilidad presencial máxima es de 180 minutos; cuando se presten jornadas laborales consecutivas de turnos completos se garantizan 12 horas de descanso entre turno y turno." (the details from #135, p37). Keep label "Personal en régimen de guardia o expectativa". |
| #144 | c10 AGENCIAS DE VIAJES (Estatal) | convenio-wide | **FIX THEN VERIFY** | replace the sentence "Trabajo en festivos/domingos: retribución adicional (Plus de Festivos, 51,50€, 57,68€ a partir del séptimo festivo/domingo trabajado en el año, o parte proporcional)." with "Trabajo en festivos/domingos: retribución adicional mediante el Plus de Festivos según las tablas salariales vigentes (importe incrementado a partir del séptimo festivo o domingo trabajado en el año, o parte proporcional)." Validity: set by Cursor prep (c10). |
| #159 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupos II, III | **FIX THEN VERIFY** | replace the whole value with: "Jornada laboral anual máxima: 1.715 horas para los Grupos II y III. Módulo semanal de referencia: 39 horas semanales para los Grupos II, III y IV. La empresa puede optar por otra distribución semanal según las necesidades de la actividad, de conformidad con la legislación vigente." Keep label "Grupos II, III". (Removes the model's meta-commentary; the Grupo IV ambiguity is preserved honestly by simply not claiming an annual figure for it.) |

## REJECT (5)

| id | convenio | scope | action | reason |
|---|---|---|---|---|
| #108 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupo I | **REJECT** | REJECT. Same sentence already captured inside vacaciones fact #130 (where it belongs — it sits in the Vacaciones article). If verified here as a Grupo I "permisos" fact it would compete with the real permisos article #107 for Grupo I employees and could answer a permisos question with only the Semana Santa compensation. The model itself flagged it as boundary. |
| #149 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Educadora de centros residenciales con guardia y educadora suplente (Asociación Navarra Nuevo Futuro - ANNF) | **REJECT** | DO NOT VERIFY unless the client IS Asociación Navarra Nuevo Futuro (ANNF). This fact applies to ONE employer inside the convenio, and the system has no "entity" scope dimension — verified, it would be served to any c18 employee bound to that label. Leave unverified (or reject). |
| #135 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Personal en régimen de guardia o expectativa (disponibilidad) | **REJECT** | merged into #147 (above). Reason to record: duplicate of #147, same population, details carried over. |
| #150 | c10 AGENCIAS DE VIAJES (Estatal) | grupo: Guías de turismo (con relación laboral) | **REJECT** | not a jornada fact — a scope-exclusion note that also carries a salary level. Reason to record: no extractable jornada value; conditions "por usos y costumbres". |
| #29 | c19 COEAS NAVARRA (Navarra) | grupo: Establecido en COEAS ESTATAL | **REJECT** | no value — a remission to COEAS Estatal (whose own facts #5–#7 exist). Reason: remisión, sin dato. |

## VERIFY — by topic, in work order


### vacaciones (15)

| id | convenio | scope | action | note |
|---|---|---|---|---|
| #119 | c2 ACTIVIDADES DEPORTIVAS (Álava) | convenio-wide | **VERIFY** | — |
| #118 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | convenio-wide | **VERIFY** | validity null → set from doc |
| #129 | c6 DEPORTE CANTABRIA (Cantabria) | convenio-wide | **VERIFY** | validity null → set from doc |
| #130 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupo I | **VERIFY** | verify as-is (content correct). PLUS: create one convenio-wide c8 vacaciones fact manually — see "Manual creates" below |
| #131 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupos II, III y IV | **VERIFY** | verify as-is (content correct); compound label "Grupos II, III y IV" is what the text says |
| #121 | c10 AGENCIAS DE VIAJES (Estatal) | convenio-wide | **VERIFY** | validity null → set from doc; core figures confirmed, calendar/promotional-trip items elided — spot-check p37 |
| #132 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | convenio-wide | **VERIFY** | — |
| #126 | c12 ALOJAMIENTOS (Gipuzkoa) | convenio-wide | **VERIFY** | core 30/26 confirmed; rules a–h elided in excerpt — open p11 and spot-check two of them |
| #124 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | convenio-wide | **VERIFY** | core 31/26 confirmed (matches 2c gold); excerpt elides the rest — open p3–p4 and spot-check the IT-18-months and finiquito items |
| #125 | c15 INFORMACIÓN Y DOCUMENTACIÓN (Gipuzkoa) | convenio-wide | **VERIFY** | source excerpt is the Basque column; value is a faithful translation (33 natural/29 working, Mon–Sat) — glance at the Spanish column on p4 to be comfortable |
| #133 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | convenio-wide | **VERIFY** | — |
| #117 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | convenio-wide | **VERIFY** | validity null → set from doc (this is the one you already opened) |
| #127 | c19 COEAS NAVARRA (Navarra) | convenio-wide | **VERIFY** | — |
| #120 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | convenio-wide | **VERIFY** | core 30 días 15/15 confirmed; the numbered rules (1)–(4) are elided — open p10–p11, spot-check two |
| #122 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | convenio-wide | **VERIFY** | — |

### permisos retribuidos (15)

| id | convenio | scope | action | note |
|---|---|---|---|---|
| #97 | c2 ACTIVIDADES DEPORTIVAS (Álava) | convenio-wide | **VERIFY** | anchor items a–g confirmed in excerpt; spot-check h–k on p19–p20 |
| #96 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | convenio-wide | **VERIFY** | validity null → set from doc; a–e confirmed; spot-check the "+2 días >150 km" and "1 día boda familiar" items on p19–p21 |
| #106 | c6 DEPORTE CANTABRIA (Cantabria) | convenio-wide | **VERIFY** | validity null → set from doc; 1) 15 and 13) fuerza mayor confirmed; spot-check 4) asuntos propios 3 jornadas prorrateadas on p23–p24 |
| #107 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | convenio-wide | **VERIFY** | a) 15 and j) 20 horas confirmed; spot-check b/c fallecimiento 5/2 on p18 |
| #99 | c10 AGENCIAS DE VIAJES (Estatal) | convenio-wide | **VERIFY** | validity null → set from doc; a) 16 and q) confirmed; spot-check b–d fallecimiento (6/3/2) on p38 |
| #123 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | convenio-wide | **VERIFY** | a) hasta 4 and k) 15 horas confirmed; spot-check b bis) fallecimiento 3 (+2) on p36–p37 |
| #103 | c12 ALOJAMIENTOS (Gipuzkoa) | convenio-wide | **VERIFY** | a) 17 (+11 con cargo a vacaciones) and d–i confirmed; spot-check the fallecimiento days b/c on p9 |
| #101 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | convenio-wide | **VERIFY** | a) 20 días confirmed; b–g elided — spot-check nacimiento (3+3 cesárea) and fallecimiento (5/3/2) on p5 |
| #102 | c15 INFORMACIÓN Y DOCUMENTACIÓN (Gipuzkoa) | convenio-wide | **VERIFY** | a) 16, b) 7/5/3, c) 1 confirmed. ONE item to check specifically: the "licencia personal adicional de 16 días laborales tras 8 años" (art. 49, p21) — unusual benefit, highest-risk claim in the fact |
| #110 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | convenio-wide | **VERIFY** | a) hasta 3 confirmed; b–h elided — spot-check e) 15 días matrimonio and h.1) 4 días fuerza mayor on p26–p27 |
| #95 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | convenio-wide | **VERIFY** | validity null → set from doc; a) 16 confirmed; spot-check c) fallecimiento 3 (+1/+2) on p38–p39 |
| #104 | c19 COEAS NAVARRA (Navarra) | convenio-wide | **VERIFY** | a) 15 and f) confirmed; spot-check d) fallecimiento 2 (+2) on p6–p7 |
| #98 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | convenio-wide | **VERIFY** | A) 18 and I) oncológicos confirmed; spot-check B) fallecimiento 6/3/2 on p12 |
| #105 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | convenio-wide | **VERIFY** | a) 18 and j) nieto confirmed; spot-check the fallecimiento 3/5 (Comunidad Foral vs fuera) on p9. This is test-navarra@'s convenio — high value |
| #100 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | convenio-wide | **VERIFY** | 1) 15 and 19) confirmed; spot-check 4)/5) fallecimiento 5/3 (+2 >200 km) on p41 |

### jornada (23)

| id | convenio | scope | action | note |
|---|---|---|---|---|
| #137 | c2 ACTIVIDADES DEPORTIVAS (Álava) | grupo: Grupos profesionales 1 y 2 | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #138 | c2 ACTIVIDADES DEPORTIVAS (Álava) | grupo: Grupos profesionales 3 y 4 | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #139 | c2 ACTIVIDADES DEPORTIVAS (Álava) | convenio-wide | **VERIFY** | overlaps festivos fact #165 (same sentence) — harmless, both correct |
| #136 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | convenio-wide | **VERIFY** | validity null → set from doc; figures confirmed |
| #157 | c6 DEPORTE CANTABRIA (Cantabria) | convenio-wide | **VERIFY** | validity null → set from doc |
| #158 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupo I | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #160 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | convenio-wide | **VERIFY** | — |
| #161 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | convenio-wide | **VERIFY** | — |
| #162 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | grupo: Personal de servicios con pernocta de los usuarios (campamentos, casas de colonias, albergues y equipamientos similares) | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #153 | c12 ALOJAMIENTOS (Gipuzkoa) | convenio-wide | **VERIFY** | — |
| #154 | c12 ALOJAMIENTOS (Gipuzkoa) | grupo: camareras de pisos | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #151 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | convenio-wide | **VERIFY** | Basque+Spanish excerpt; 1.592,5 h / 35 h confirmed |
| #152 | c15 INFORMACIÓN Y DOCUMENTACIÓN (Gipuzkoa) | convenio-wide | **VERIFY** | — |
| #163 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | convenio-wide | **VERIFY** | — |
| #164 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | grupo: Grupo III (Experto en Talleres) | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #134 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | convenio-wide | **VERIFY** | validity null → needs the c18 decision (see notes); figures confirmed incl. the ultraactividad schedule 2026–2028, which the text states explicitly |
| #148 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Puestos docentes (profesorado y maestras/os de taller) en programas formativos estables del Departamento de Educación | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #155 | c19 COEAS NAVARRA (Navarra) | convenio-wide | **VERIFY** | — |
| #141 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Técnicos de Actividad deportiva (monitores) | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #142 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Técnicos de Sala | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #143 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | convenio-wide | **VERIFY** | — |
| #156 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | convenio-wide | **VERIFY** | 1.673 h 20 min confirmed — test-navarra@'s convenio |
| #146 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | convenio-wide | **VERIFY** | includes the 24/31 dic + 5/18 marzo festivos and the 17-18 marzo permiso no retribuido — all confirmed in p45–p46 |

### festivos (3)

| id | convenio | scope | action | note |
|---|---|---|---|---|
| #165 | c2 ACTIVIDADES DEPORTIVAS (Álava) | convenio-wide | **VERIFY** | — |
| #167 | c6 DEPORTE CANTABRIA (Cantabria) | convenio-wide | **VERIFY** | validity null → set from doc |
| #166 | c10 AGENCIAS DE VIAJES (Estatal) | convenio-wide | **VERIFY** | validity null → set from doc; two passages merged (p35+p38), both quoted |

### periodo de prueba — 2026 table (43)

| id | convenio | scope | action | note |
|---|---|---|---|---|
| #42 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | grupo: Grupo 2 | **VERIFY** | 2026 successor of #9: open it, click "Resolver versión" (supersede the old row), then verify. Value matches the 2026 table. |
| #43 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | grupo: Grupos 3, 4, 5 y 6 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #80 | c4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA (Andalucía) | grupo: Grupo 2 | **VERIFY** | 2026 successor of #12: open it, click "Resolver versión" (supersede the old row), then verify. Value matches the 2026 table. |
| #81 | c4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA (Andalucía) | grupo: Grupos 3, 4, 5 y 6 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #82 | c4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA (Andalucía) | grupo: Contratos de formación en alternancia | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #83 | c6 DEPORTE CANTABRIA (Cantabria) | grupo: Grupos Profesionales 1 y 2 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #84 | c6 DEPORTE CANTABRIA (Cantabria) | grupo: Grupos Profesionales 3, 4 y 5 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #85 | c6 DEPORTE CANTABRIA (Cantabria) | grupo: Contratos por circunstancias de la producción (Art. 15.2 ET) de duración reducida | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #61 | c12 ALOJAMIENTOS (Gipuzkoa) | grupo: Técnicos titulados | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #62 | c12 ALOJAMIENTOS (Gipuzkoa) | grupo: Primeros Jefes (nivel 1) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #63 | c12 ALOJAMIENTOS (Gipuzkoa) | grupo: Segundos Jefes (nivel 2) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #64 | c12 ALOJAMIENTOS (Gipuzkoa) | grupo: Oficiales (nivel 3) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #65 | c12 ALOJAMIENTOS (Gipuzkoa) | grupo: No cualificados (niveles 4 y 5) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #57 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | grupo: Grupo I (Directivos y Técnicos) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #58 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | grupo: Grupos II y III (Administrativos y Mandos) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #59 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | grupo: Grupo IV (Operarios) | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #60 | c14 INTERVENCION SOCIAL GIPUZKOA (Gipuzkoa) | grupo: General | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #66 | c15 INFORMACIÓN Y DOCUMENTACIÓN (Gipuzkoa) | grupo: Contratos para la práctica profesional | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #68 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | grupo: Grupo 1 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #69 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | grupo: Grupo 2 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #70 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | grupo: Grupos 3, 4, 5 y 6 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #71 | c17 OCIO EDUCATIVO Y ANIMACIÓN MADRID (Madrid) | grupo: Contrato formativo (práctica profesional) | **VERIFY** | content confirmed; group/contract-type label unbound until a tree exists — verifying now means it goes live the moment HR binds it |
| #54 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Grupo 1 | **VERIFY** | job category ALREADY BOUND (CAT=GRUPO 1/2) — this one becomes servable immediately on verify. Transcription matches. |
| #55 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Grupo 2 | **VERIFY** | job category ALREADY BOUND (CAT=GRUPO 1/2) — this one becomes servable immediately on verify. Transcription matches. |
| #56 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Resto de grupos | **VERIFY** | 2026 successor of #28: open it, click "Resolver versión" (supersede the old row), then verify. Value matches the 2026 table. |
| #50 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupo 1 | **VERIFY** | 2026 successor of #30: open it, click "Resolver versión" (supersede the old row), then verify. Value matches the 2026 table. |
| #51 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupo 2 | **VERIFY** | 2026 successor of #31: open it, click "Resolver versión" (supersede the old row), then verify. Value matches the 2026 table. |
| #52 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupos 3, 4 y 5 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #53 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupo 6 | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #47 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | grupo: Personal obrero y subalterno | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #48 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | grupo: Personal técnico y administrativo | **VERIFY** | 2026 successor of #38: open it, click "Resolver versión" (supersede the old row), then verify. Value matches the 2026 table. |
| #49 | c23 OFICINAS Y DESPACHOS (Navarra) | convenio-wide | **VERIFY** | convenio-wide rule (no group); transcription matches the 2026 table |
| #86 | c24 OFICINAS Y DESPACHOS SALAMANCA (Salamanca) | grupo: Técnicos titulados superiores | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #87 | c24 OFICINAS Y DESPACHOS SALAMANCA (Salamanca) | grupo: Técnicos de grado medio y administrativos cualificados | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #88 | c24 OFICINAS Y DESPACHOS SALAMANCA (Salamanca) | grupo: Resto de personal | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #72 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo I | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #73 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo II | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #74 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo III | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #75 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo IV | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #76 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupos V y VI | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #77 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupos VII y VIII | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |
| #78 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | convenio-wide | **VERIFY** | convenio-wide rule (no group); transcription matches the 2026 table |
| #67 | c26 INTERVENCION SOCIAL (Vizcaya) | grupo: General | **VERIFY** | 2026 table transcription matches; group label, no tree — inert until the tree exists, but verifying now means it goes live the moment HR approves the tree |

### periodo de prueba — old table, history (38)

Do these LAST. They are already out of validity (ended 2025-12-31) so verifying them changes no current answer — it records history and empties the queue.

| id | convenio | scope | action | note |
|---|---|---|---|---|
| #8 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | grupo: Grupo 1 | **NOTHING** | already superseded by a verified 2026 fact (#41/#79) |
| #9 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | grupo: Grupo 2 | **AUTO-CLOSED** | closed by "Resolver versión" from its 2026 successor; verify it as history afterwards if the UI still allows — harmless either way |
| #10 | c3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | grupo: Grupos 3,4,5 y 6 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #11 | c4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA (Andalucía) | grupo: Grupo 1 | **NOTHING** | already superseded by a verified 2026 fact (#41/#79) |
| #12 | c4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA (Andalucía) | grupo: Grupo 2 | **AUTO-CLOSED** | closed by "Resolver versión" from its 2026 successor; verify it as history afterwards if the UI still allows — harmless either way |
| #13 | c4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA (Andalucía) | grupo: Grupos 3,4,5 y 6 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #1 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupos 1 y 2 | **VERIFY AS HISTORY + FLAG** | c8 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #2 | c8 ENSEÑANZA Y FORMACION NO REGLADA (Estatal) | grupo: Grupo 3 o grado superior | **VERIFY AS HISTORY + FLAG** | c8 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #3 | c9 INSTALACIONES DEPORTIVAS Y GIMNASIO (Estatal) | grupo: Grupos 1 y 2 | **VERIFY AS HISTORY + FLAG** | c9 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #4 | c9 INSTALACIONES DEPORTIVAS Y GIMNASIO (Estatal) | grupo: Grupos 3,4 y 5 | **VERIFY AS HISTORY + FLAG** | c9 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #5 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | grupo: Grupo 1 | **VERIFY AS HISTORY + FLAG** | c11 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #6 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | grupo: Grupo 2 | **VERIFY AS HISTORY + FLAG** | c11 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #7 | c11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (Estatal) | grupo: Grupos 3,4,5 y 6 | **VERIFY AS HISTORY + FLAG** | c11 has NO 2026 periodo-de-prueba row at all — after verifying, this convenio has no current fact. See "Coverage gap" below. |
| #14 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | grupo: Grupo 1 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #15 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | grupo: Grupo 2 y 3 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #16 | c13 LIMPIEZA EDIFICIOS Y LOCALES (Gipuzkoa) | grupo: Grupo 4 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #17 | c14 INTERVENCION SOCIAL GIPUZKOA (Gipuzkoa) | grupo: Todo (general) | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #18 | c15 INFORMACIÓN Y DOCUMENTACIÓN (Gipuzkoa) | grupo: General | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #27 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Grupos 1 y 2 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #28 | c18 ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | grupo: Resto de grupos | **AUTO-CLOSED** | closed by "Resolver versión" from its 2026 successor; verify it as history afterwards if the UI still allows — harmless either way |
| #30 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupo 1 | **AUTO-CLOSED** | closed by "Resolver versión" from its 2026 successor; verify it as history afterwards if the UI still allows — harmless either way |
| #31 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupo 2 | **AUTO-CLOSED** | closed by "Resolver versión" from its 2026 successor; verify it as history afterwards if the UI still allows — harmless either way |
| #32 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupos 3,4 y 5 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #33 | c20 GESTIÓN DEPORTIVA NAVARRA (Navarra) | grupo: Grupo 6 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #34 | c21 HOSTELERIA NAVARRA (Navarra) | grupo: Grupo 1 y área cinco de Grupo 2 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #35 | c21 HOSTELERIA NAVARRA (Navarra) | grupo: Grupo 2 excepto área cinco | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #36 | c21 HOSTELERIA NAVARRA (Navarra) | grupo: Grupo 3 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #37 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | grupo: Obreros y subalternos | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #38 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | grupo: Personal técnico y administrativo | **AUTO-CLOSED** | closed by "Resolver versión" from its 2026 successor; verify it as history afterwards if the UI still allows — harmless either way |
| #39 | c22 LIMPIEZA DE EDIFICIOS Y LOCALES (Navarra) | grupo: Emprendedores | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #19 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo 1 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #20 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo 2 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #21 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo 3 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #22 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo 4 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #23 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupo 5 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #24 | c25 OFICINAS Y DESPACHOS VALENCIA (Valencia) | grupo: Grupos 6,7 y 8 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #25 | c26 INTERVENCION SOCIAL (Vizcaya) | grupo: Grupos 1,2 y 3 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |
| #26 | c26 INTERVENCION SOCIAL (Vizcaya) | grupo: Grupos 4 y 5 | **VERIFY AS HISTORY** | value matches the old HR table; validity already ends 2025-12-31 so it can never serve a current question — verifying it records history and clears the queue |