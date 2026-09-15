# Sprint 10c — Review queue triage export, summary

Read-only export from staging, 146 `needs_review` reference facts total (all topics, all convenios). No verification performed, no status changes. Companion file: `facts-export.json` (sorted by topic, then convenio, then id — same-convenio/same-topic rows sit adjacent).

## Counts by topic

| Topic | Count |
|---|---|
| periodo de prueba | 82 |
| jornada | 30 |
| permisos retribuidos | 16 |
| vacaciones | 15 |
| festivos | 3 |
| **Total** | **146** |

## Counts by convenio

| convenio_id | Número | Name | Count |
|---|---|---|---|
| 2 | 01003205012006 | ACTIVIDADES DEPORTIVAS | 6 |
| 3 | 01100635012017 | OCIO EDUCATIVO Y ANIMACION SOCIOCUL | 8 |
| 4 | 71103505012022 | OCIO EDUCATIVO Y ANIMACION ANDALUCIA | 6 |
| 6 | 39100935012024 | DEPORTE CANTABRIA | 7 |
| 8 | 99008825011994 | ENSEÑANZA Y FORMACION NO REGLADA | 9 |
| 9 | 99015105012005 | INSTALACIONES DEPORTIVAS Y GIMNASIO | 2 |
| 10 | 99000155011981 | AGENCIAS DE VIAJES | 5 |
| 11 | 99100055012011 | OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | 7 |
| 12 | 20100035012014 | ALOJAMIENTOS | 9 |
| 13 | 20000785011981 | LIMPIEZA EDIFICIOS Y LOCALES | 9 |
| 14 | 20100025012011 | INTERVENCION SOCIAL GIPUZKOA | 2 |
| 15 | 20104415012022 | INFORMACIÓN Y DOCUMENTACIÓN | 5 |
| 17 | 28102145012018 | OCIO EDUCATIVO Y ANIMACIÓN MADRID | 8 |
| 18 | 31101815012021 | ACCIÓN E INTERVENCIÓN SOCIAL | 12 |
| 19 | 31102195012024 | COEAS NAVARRA | 4 |
| 20 | 31008235012003 | GESTIÓN DEPORTIVA NAVARRA | 14 |
| 21 | 31003805011981 | HOSTELERIA NAVARRA | 3 |
| 22 | 31004605011982 | LIMPIEZA DE EDIFICIOS Y LOCALES | 7 |
| 23 | 31005105011984 | OFICINAS Y DESPACHOS | 1 |
| 24 | 37000375011982 | OFICINAS Y DESPACHOS SALAMANCA | 3 |
| 25 | 46000805011981 | OFICINAS Y DESPACHOS VALENCIA | 16 |
| 26 | 48006185012006 | INTERVENCION SOCIAL | 3 |
| | | **Total** | **146** |

## Flags

- **Carry an `uncertainty` flag:** 26 of 146
- **Have a `group_label` but the convenio has NO approved group tree** (group-scoped, currently unanswerable at the group level even once verified): 95 of 146
- **Carry a version/duplicate flag** (`duplicate_of_id` set, flagged BY another fact's `duplicate_of_id`, or `superseded_by_id` set): 14 of 146
- **Null validity** (both `validity_start` and `validity_end` are null — no specific date window recorded): 19 of 146

### Version/duplicate flag detail

All 14 are pre-existing `periodo de prueba` pairs/chains, not from this sprint's own batches (permisos/vacaciones/jornada/festivos each carry 0 duplicate flags — consistent with `review.md`'s "0 collisions surviving to the queue" for all four topics this sprint touched):

| Fact id | Convenio | is_duplicate_of | flagged_by | superseded_by |
|---|---|---|---|---|
| 8 | 01100635012017 | — | 41 | 41 |
| 9 | 01100635012017 | — | 42 | — |
| 42 | 01100635012017 | 9 | — | — |
| 11 | 71103505012022 | — | 79 | 79 |
| 12 | 71103505012022 | — | 80 | — |
| 80 | 71103505012022 | 12 | — | — |
| 28 | 31101815012021 | — | 56 | — |
| 56 | 31101815012021 | 28 | — | — |
| 30 | 31008235012003 | — | 50 | — |
| 31 | 31008235012003 | — | 51 | — |
| 50 | 31008235012003 | 30 | — | — |
| 51 | 31008235012003 | 31 | — | — |
| 38 | 31004605011982 | — | 48 | — |
| 48 | 31004605011982 | 38 | — | — |

### Group-label-without-approved-tree detail (by convenio)

| convenio_id | Número | Name | Group-labelled facts with no approved tree |
|---|---|---|---|
| 2 | 01003205012006 | ACTIVIDADES DEPORTIVAS | 2 |
| 3 | 01100635012017 | OCIO EDUCATIVO Y ANIMACION SOCIOCUL | 5 |
| 4 | 71103505012022 | OCIO EDUCATIVO Y ANIMACION ANDALUCIA | 6 |
| 6 | 39100935012024 | DEPORTE CANTABRIA | 3 |
| 8 | 99008825011994 | ENSEÑANZA Y FORMACION NO REGLADA | 7 |
| 9 | 99015105012005 | INSTALACIONES DEPORTIVAS Y GIMNASIO | 2 |
| 10 | 99000155011981 | AGENCIAS DE VIAJES | 1 |
| 11 | 99100055012011 | OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | 4 |
| 12 | 20100035012014 | ALOJAMIENTOS | 6 |
| 13 | 20000785011981 | LIMPIEZA EDIFICIOS Y LOCALES | 6 |
| 14 | 20100025012011 | INTERVENCION SOCIAL GIPUZKOA | 2 |
| 15 | 20104415012022 | INFORMACIÓN Y DOCUMENTACIÓN | 2 |
| 17 | 28102145012018 | OCIO EDUCATIVO Y ANIMACIÓN MADRID | 5 |
| 18 | 31101815012021 | ACCIÓN E INTERVENCIÓN SOCIAL | 9 |
| 19 | 31102195012024 | COEAS NAVARRA | 1 |
| 20 | 31008235012003 | GESTIÓN DEPORTIVA NAVARRA | 11 |
| 22 | 31004605011982 | LIMPIEZA DE EDIFICIOS Y LOCALES | 5 |
| 24 | 37000375011982 | OFICINAS Y DESPACHOS SALAMANCA | 3 |
| 25 | 46000805011981 | OFICINAS Y DESPACHOS VALENCIA | 12 |
| 26 | 48006185012006 | INTERVENCION SOCIAL | 3 |
