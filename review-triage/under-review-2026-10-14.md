# Review queue — AI tagging (`tagging_status = under_review`)

Read-only export from staging, 2026-10-14T11:33:47+00:00. 47 documents. No rows were written.

Sort: active convenio texts, then active partial agreements, then salary tables, then reference sources, then anything else still active, then historical. Within a group, by document id.

Current text, for the question on each document, means `retrieval_status = active` and `document_type` in `convenio_text`, `partial_agreement`, `national_law`, `internal_hr_ruling`. Confirm tags does not change retrieval status or type.

## Would Confirm tags make a document with no stored convenio retrievable?

No. Not for any document in this queue, and not in general for a document whose authority is `official_convenio`.

Confirm tags only sets `tagging_status` to `verified` and resolves open review tasks. It does not set `convenio_id`, and it does not embed.

```302:322:hr-backend/app/Http/Controllers/Admin/DocumentController.php
        DB::transaction(function () use ($document, $adminId) {
            $document->update(['tagging_status' => 'verified']);

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'document',
                'old_value' => null,
                'new_value' => 'verified',
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'tags confirmed',
            ]);

            $document->reviewTasks()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);
        });
```

`chunks:embed` selects a document when its type is `convenio_text`, `national_law`, `partial_agreement` or `internal_hr_ruling`, its `retrieval_status` is `active` or `historical`, and its `tagging_status` is not `under_review`. `convenio_id` is not part of that selection. Salary types are never selected. After confirm, an unscoped prose document would be selected, and the scope passed to hr-ai would carry `convenio_id` null and the document's own `authority_level`.

```34:42:hr-backend/app/Console/Commands/ChunksEmbed.php
    private const IN_SCOPE_TYPES = ['convenio_text', 'national_law', 'partial_agreement', 'internal_hr_ruling'];

    public function handle(ExtractionClient $client): int
    {
        $query = Document::query()
            ->with(['documentType', 'convenio'])
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::IN_SCOPE_TYPES))
            ->whereIn('retrieval_status', ['active', 'historical'])
            ->where('tagging_status', '!=', 'under_review');
```

```146:154:hr-backend/app/Console/Commands/ChunksEmbed.php
        return [
            'convenio_id' => $d->convenio_id,
            'territory_id' => $d->convenio?->territory_id,
            'sector_id' => $d->convenio?->sector_id,
            'validity_start' => $d->validity_start?->toDateString(),
            'validity_end' => $d->validity_end?->toDateString(),
            'retrieval_status' => $d->retrieval_status,
            'authority_level' => $d->authority_level,
        ];
```

The employee retrieve filter then keeps a chunk only when `convenio_id` equals the employee's convenio, or when `include_national_law` is set and `authority_level` is `national_law`. The answer loop always passes the employee's convenio id with national law included, and a second pass with `convenio_id` null that still requires `national_law`. Both passes ask for `retrieval_status = active` only. A chunk with `convenio_id` null and `authority_level = official_convenio` matches neither pass. Every document in this queue has `authority_level = official_convenio`, including the 42 with no convenio.

```106:110:hr-ai/app/chunks_db.py
                WHERE ( ($2::bigint IS NOT NULL AND convenio_id = $2)
                        OR ($3::boolean AND authority_level = 'national_law') )
                  AND retrieval_status = ANY($4::varchar[])
                  AND ($5::date IS NULL OR validity_start IS NULL OR validity_start <= $5::date)
                  AND ($5::date IS NULL OR validity_end IS NULL OR validity_end >= $5::date)
```

```107:114:hr-backend/app/Services/Answer/RetrievalUnion.php
            $resp = $this->safeRetrieve([
                'query' => $q,
                'convenio_id' => $scopeConvenioId,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOf,
                'k' => $poolK,
            ]);
```

So: salary tables, reference sources and the summary are outside the embed selection, and confirming them does nothing for retrieval. Unscoped prose would be embedded on the next `chunks:embed` and still never returned to an employee. A scoped active prose document is the case confirm can make retrievable, and only after `chunks:embed` runs. In this queue that is document 76.

## Active convenio texts

### 31 — PACTO CULTURA NAVARRA

- **uuid:** `e4dc1595-6c60-4fce-bcbf-55180bc9a33c`
- **original filename:** `PACTO CULTURA NAVARRA.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 2 · **pages_with_text:** 2 · **pages:** 2
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 22 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: PACTO CULTURA
  - facet `convenio` · parsed value: PACTO CULTURA NAVARRA.pdf
  - facet `company` · parsed value: SEDENA, S.L.

**AI suggestions**

- facet `document_type` · value `partial_agreement` · confidence 0.95
- facet `convenio` · value `01100635012017` · confidence 0.85
- facet `territory` · value `31` · confidence 0.95
- facet `sector` · value `OCIO EDUCATIVO Y ANIMACION SOCIOCUL` · confidence 0.85
- facet `validity` · value `2019-01-01..2021-12-31` · confidence 0.75
- facet `topic` · value `retribución` · confidence 0.95
- facet `topic` · value `bajas médicas` · confidence 0.9
- facet `topic` · value `permisos retribuidos` · confidence 0.85

**Cover (first 800 characters of page 1)**

```text
REUNIDOS

ACUERDAN

PRIMERO.- AMBITO DE APLICACIÓN

SEGUNDO.-

TERCERO.- INCREMENTO SALARIAL

En Pamplona a 12 de diciembre de 2019

REUNIDOS

De una parte: en representación de la empresa SEDENA, Don [nombre], con DNI. [DNI], consejero delegado.

De otra parte: El comité de empresa de SEDENA, S.L.

ACUERDAN

PRIMERO.- AMBITO DE APLICACIÓN: El presente acuerdo será de aplicación a las personas trabajadoras de Sedena S.L , cuyo Convenio Colectivo de aplicación es el ocio educativo y animación socio cultural y prestan servicio en los siguientes centros de trabajo que Sedena gestiona en Navarra:

1. Gestión de servicios socioculturales de la Red Civivox
2. Casa de la Juventud de Pamplona
3. Centro Sociocultural Civican
4. Servicio de Información y Recursos pa
```

**Duplicate first page** (same text hash)

- id 47 · `historical` / `under_review` · `convenio_text` · `Pacto de la empresa.pdf`

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 55 — Plan Igualdad texto

- **uuid:** `54639919-d1c5-42d0-8c8d-30078a35e053`
- **original filename:** `Plan_Igualdad_texto.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 78 · **pages:** 78
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 39 · kind `unresolved` (type `tag_review`)
  - facet `territory` · parsed value: Plan Igualdad
  - facet `sector` · parsed value: Plan Igualdad
  - facet `convenio` · parsed value: Plan_Igualdad_texto.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Plan de Igualdad de Sedena 2023-2027
Página 1 de 78
IV PLAN DE IGUALDAD 
ENTRE
MUJERES Y HOMBRES EN LA 
EMPRESA 
SEDENA, S.L.
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 76 — 99100055012011 COEAS Estatal 2025 2027

- **uuid:** `abb0c1f9-f07a-422f-b13c-01220944c773`
- **original filename:** `99100055012011_COEAS Estatal_2025 2027.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** 2025-01-01 → 2027-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 74 · **pages:** 74
- **chunk count:** 0
- **scope:** convenio 11 · 99100055012011 · OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL; territory 12 99 Estatal; sector 3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL

**Open review tasks**

- task 52 · kind `conflict` (type `conflict`)
  - facet `sector` · parsed value: parsed sector COEAS disagrees with registry · registry value: OCIO EDUCATIVO Y ANIMACION SOCIOCUL (The task stores the sentence only. This is the bound convenio sector DocumentTagger compared (DocumentTagger.php rule 3).)

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
III. OTRAS DISPOSICIONES
MINISTERIO DE TRABAJO Y ECONOMÍA SOCIAL
5694
Resolución de 25 de febrero de 2026, de la Dirección General de Trabajo, por 
la que se registra y publica el IV Convenio colectivo marco estatal de ocio 
educativo y animación sociocultural.
Visto el texto del IV Convenio colectivo marco estatal de ocio educativo y animación 
sociocultural (código de convenio n.º 99100055012011), que fue suscrito con fecha 28 de 
noviembre de 2025, de una parte por la Federación Estatal de Organizaciones 
Empresariales de Ocio Educativo y Sociocultural (FOESC), la Fundación Catalana de 
L'Esplai (AEEISSS), la Asociación de Empresas de Educación Cultura y Tiempo Libre 
(EDUCATIA) y la Asociación Nacional de Empresas Socioculturales (ANESOC), en 
representación de las empresas del sector,
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

- id 6 · `salary_tables` · validity — → — · `active` / `verified` · TABLAS SALARIALES COEAS Estatal
- id 87 · `convenio_text` · validity 2020-01-01 → 2023-12-31 · `historical` / `under_review` · 99100055012011 COEAS Estatal 2020 2023

**Would confirming this document give its convenio its only current text?** Yes. Convenio 99100055012011 has no other active prose document. Confirming this one would leave it as that convenio's only current text. Salary tables and historical documents on the same convenio are not current text.

**Employees on staging:** 3 (active 3).

## Active partial agreements

### 14 — Acuerdo fin de huelga UBIK - SEDENA SL 2019-2022 (2019-08-07)

- **uuid:** `55184e0c-77e0-4570-8113-433d7a9a148f`
- **original filename:** `Acuerdo fin de huelga UBIK - SEDENA SL 2019-2022 (2019-08-07).pdf`
- **document_type:** `partial_agreement` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 12 · **pages_with_text:** 12 · **pages:** 12
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 12 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: fin huelga UBIK SEDENA SL 2019-2022 (2019-08-07)
  - facet `convenio` · parsed value: Acuerdo fin de huelga UBIK - SEDENA SL 2019-2022 (2019-08-07).pdf
  - facet `convenio` · parsed value: SEDENA, S.L. - Biblioteca de creación UBIK
  - facet `sector` · parsed value: Biblioteca de creación / Cultura contemporánea

**AI suggestions**

- facet `document_type` · value `partial_agreement` · confidence 0.95
- facet `territory` · value `20` · confidence 0.9
- facet `validity` · value `2019-01-01..2022-12-31` · confidence 0.85
- facet `topic` · value `jornada` · confidence 0.95
- facet `topic` · value `vacaciones` · confidence 0.9
- facet `topic` · value `festivos` · confidence 0.85
- facet `topic` · value `bajas médicas` · confidence 0.9
- facet `topic` · value `retribución` · confidence 0.95

**Cover (first 800 characters of page 1)**

```text
ACUERDO DE FIN DE HUELGA DE SEDENA, S.L. Y PERSONAL DEL CENTRO DE TRABAJO EMPLEADAS Y EMPLEADOS EN LA BIBLIOTECA DE CREACIÓN UBIK.

1. Ámbito funcional, personal y temporal:

2. Modalidades de contratación:

ACUERDO DE FIN DE HUELGA DE SEDENA, S.L. Y PERSONAL DEL CENTRO DE TRABAJO EMPLEADAS Y EMPLEADOS EN LA BIBLIOTECA DE CREACIÓN UBIK.

El presente acuerdo será de aplicación exclusivamente para el personal actual y futuro ocupado en la Biblioteca de creación UBIK con la categoría de técnico especialista y coordinación de servicio.

1. Ámbito funcional, personal y temporal:
El presente acuerdo será de aplicación para las y los mediadores y persona de coordinación actuales y futuros con la categoría de técnico especialista y coordinación del servicio de atención a la biblioteca de creación
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 90 — ACUERDO PARCIAL COEAS ANDALUCIA Alhambra

- **uuid:** `13b025e6-502b-47dc-b61a-d687acded41a`
- **original filename:** `ACUERDO PARCIAL COEAS ANDALUCIA_Alhambra.pdf`
- **document_type:** `partial_agreement` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 11 · **pages:** 11
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 63 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: COEAS Alhambra
  - facet `convenio` · parsed value: ACUERDO PARCIAL COEAS ANDALUCIA_Alhambra.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
00307421
3. Otras disposiciones
CONSEJERÍA DE EMPLEO, EMPRESA Y TRABAJO AUTÓNOMO
Resolución de 9 de septiembre de 2024, de la Dirección General de Trabajo, 
Seguridad y Salud Laboral, por la que se procede a la publicación del Acuerdo 
Parcial de la Comisión Negociadora del II Convenio Colectivo de la Comunidad 
Autónoma de Andalucía del Sector Ocio Educativo y Animación Sociocultural.
Visto el texto del Acuerdo Parcial de la Comisión Negociadora del II Convenio Colectivo 
de la Comunidad Autónoma de Andalucía del Sector Ocio Educativo y Animación 
Sociocultural, cód. 71100045082024, suscrito por las partes legitimadas, en fecha 19 de 
junio de 2024, y de conformidad con lo dispuesto en el artículo 90, apartados 2 y 3, 
del Real Decreto Legislativo 2/2015, de 23 de octubre, por el que se a
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

## Salary tables

### 5 — Tabla 2026

- **uuid:** `170f3fec-84ae-4001-a48f-605fcb4e3c71`
- **original filename:** `Tabla 2026.xlsx`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 0 · **pages:** 0
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 43 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: Tabla
  - facet `convenio` · parsed value: Tabla 2026.xlsx

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

No extracted text on page 1.

**Duplicate first page** (same text hash)

No page-1 text to hash.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 7 — TABLAS SALARIALES Deporte Estatal

- **uuid:** `0c2bb5be-e131-4206-a380-66ca58d2bb2b`
- **original filename:** `TABLAS SALARIALES Deporte Estatal.xlsx`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 0 · **pages:** 0
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 53 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: SALARIALES Deporte
  - facet `convenio` · parsed value: TABLAS SALARIALES Deporte Estatal.xlsx

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

No extracted text on page 1.

**Duplicate first page** (same text hash)

No page-1 text to hash.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 10 — Tablas acuerdo parcial Alhambra

- **uuid:** `7d5cb553-9dcf-427f-a3b3-6d13e9929259`
- **original filename:** `Tablas acuerdo parcial_Alhambra.xlsx`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 0 · **pages:** 0
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 72 · kind `unresolved` (type `tag_review`)
  - facet `territory` · parsed value: Alhambra
  - facet `sector` · parsed value: Alhambra
  - facet `convenio` · parsed value: Tablas acuerdo parcial_Alhambra.xlsx

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

No extracted text on page 1.

**Duplicate first page** (same text hash)

No page-1 text to hash.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

## Reference sources

### 105 — PERIODOS DE PRUEBA

- **uuid:** `cb9487d6-95e6-42cc-90c8-7511f5952987`
- **original filename:** `PERIODOS DE PRUEBA.docx`
- **document_type:** `reference_source` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** 2022-01-01 → 2025-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 7 · **pages:** 7
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 70 · kind `unresolved` (type `tag_review`)
  - facet `territory` · parsed value: PERIODOS PRUEBA
  - facet `sector` · parsed value: PERIODOS PRUEBA
  - facet `convenio` · parsed value: PERIODOS DE PRUEBA.docx

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
[ESTATAL]
ESTATAL
ENSEÑANZA Y FORMACIÓN NO REGLADA
Grupos 1 y 2: Un mes
Grupo 3 o grado superior: Dos meses
INSTALACIONES DEPORTIVAS Y GIMNASIO
Grupos 1 y 2: Tres meses
Grupos 3,4 y 5: Dos meses
COEAS ESTATAL
Grupo 1: Seis meses
Grupo 2: dos meses
Grupos 3,4,5 y 6: un mes
(excepto titulados grado superior y grado medio que son DOS meses)
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 106 — PERÍODOS PRUEBA ACTUALIZADOS 2026

- **uuid:** `052a3228-0053-4d8f-b2f8-91bceda7bca2`
- **original filename:** `PERÍODOS PRUEBA ACTUALIZADOS 2026.docx`
- **document_type:** `reference_source` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** 2026-01-01 → —
- **OCR'd pages:** 0 · **pages_with_text:** 1 · **pages:** 1
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 71 · kind `unresolved` (type `tag_review`)
  - facet `territory` · parsed value: PERÍODOS PRUEBA ACTUALIZADOS
  - facet `sector` · parsed value: PERÍODOS PRUEBA ACTUALIZADOS
  - facet `convenio` · parsed value: PERÍODOS PRUEBA ACTUALIZADOS 2026.docx

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
[Sección 1]
ÁLAVA
Actividades Deportivas (Empresas para terceros): El periodo de prueba no podrá exceder de dos meses en ningún caso.
Ocio Educativo y Animación Sociocultural:
Grupo 1: 5 meses.
Grupo 2: 2 meses.
Grupos 3, 4, 5 y 6: 1 mes, salvo titulados de grado medio o superior, que tendrán 2 meses.
Nota: Para contratos menores a 9 meses, el periodo se reduce proporcionalmente, con un mínimo de 15 días.
NAVARRA
Hostelería: Varía según el tipo de contrato y grupo profesional:
Grupo 1 (todas las áreas) y Grupo 2 (área 5): 90 días (indefinidos), 75 días (temporales > 3 meses) y 60 días (temporales hasta 3 meses).
Grupo 2 (resto áreas): 60 días (indefinidos), 45 días (temporales > 3 meses) y 30 días (temporales hasta 3 meses).
Grupo 3 (todas las áreas): 45 días (indefinidos), 30 días (tempor
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

## Historical

### 15 — limpieza

- **uuid:** `b6dca35b-8b88-4f17-994f-a57dec395eb8`
- **original filename:** `limpieza.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 28 · **pages:** 28
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 13 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: limpieza
  - facet `convenio` · parsed value: limpieza.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
CONVENIO COLECTIVO PROVINCIAL DE GIPUZKOA DE LIMPIEZA DE EDIFICIOS Y 
LOCALES 
( 2010-2017) 
 
 CAPÍTULO PRIMERO 
 Ámbitos 
 
 
 Artículo 1.º Ámbito territorial.—Las disposiciones del presente Convenio regirán en la 
Provincia de Gipuzkoa. 
 Art. 2.º Ámbito funcional.—Quedan sometidas a las disposiciones del presente Convenio 
todas las empresas dedicadas a la limpieza de edificios y locales, cualquiera que sea la forma 
jurídica con la que actúen e independientemente de cuál sea su domicilio y objeto social, 
constituyendo la causa de sometimiento al Convenio la realización, aunque sea de forma 
parcial, de trabajos de limpieza de edificios y locales en el territorio histórico de Gipuzkoa. 
 Art. 3.º Ámbito personal.—Las normas establecidas en el presente Convenio serán de 
aplicación a t
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 16 — Resolución de 13 de novi...

- **uuid:** `64e21e16-3a44-4b79-ada2-b8b0d6fac136`
- **original filename:** `Resolución_de_13_de_novi....pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 23 · **pages:** 23
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 14 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: Resolución novi...
  - facet `convenio` · parsed value: Resolución_de_13_de_novi....pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 13 de noviembre de 2018, del Delegado Territorial de Trabajo y Seguridad Social, por la que se
dispone el registro, publicación y depósito del Convenio Colectivo de Limpieza de Edificios y Locales de
Gipuzkoa para el año 2018
BOG 19 Noviembre 2018
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 20000785011981; 2000785;
Ámbito territorial: Gipuzkoa
Sector de actividad: Limpieza de edificios y locales
Duración temporal: del 1 enero 2018 hasta 31 diciembre 2018
INTRODUCCIÓN
ANTECEDENTES
Primero. El día 15 de octubre de 2018 se suscribió el convenio citado por Aspel, en representación de las empresas, y ELA, LAB,
CC.OO., UGT, y ESK en representación de la parte trabajadora.
Segundo. El día 16 de octubre de 2018 se presentó en esta Delegación Territorial
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 17 — TEXTO-VIII-CONVENIO-COLECTIVO-NO-REGLADA-steilas

- **uuid:** `701d3479-8d24-4325-9985-902347de0b26`
- **original filename:** `TEXTO-VIII-CONVENIO-COLECTIVO-NO-REGLADA-steilas.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 2 · **pages:** 35
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 15 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: TEXTO-VIII-CONVENIO-COLECTIVO-NO-REGLADA-steilas
  - facet `convenio` · parsed value: TEXTO-VIII-CONVENIO-COLECTIVO-NO-REGLADA-steilas.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Irakaskuntza Pribatua 
Enseñanza Privada 
 
 
 
 
 
 
Lege 
gordailua 
BI-2729/00
 
 
 
 
 
 
 
 
VIII CONVENIO 
COLECTIVO 
DE ENSEÑANZA Y 
FORMACIÓN NO 
REGLADA 
2014 – 2018 
 
 
ARABA: Eulogio Serdan, 5 01012 Gasteiz ℡ 945 14 11 04  945 14 43 02 araba@stee-eilas.org 
BIZKAIA: Nikolas Alkorta, 3 (Zabalburu) 48003 Bilbao ℡ 944 10 02 98  944 10 13 60 bizkaia@stee-eilas.org 
GIPUZKOA: Baso-txiki, 30-34 (atzealdea) 20015 Donostia ℡ 943 46 60 00  943 45 36 27 gipuzkoa@stee-eilas.org
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 21 — HOSTELERIA  2008 2010

- **uuid:** `187ddd8b-6eef-4458-aa6a-38bc5b895335`
- **original filename:** `HOSTELERIA  2008 2010.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** 2008-01-01 → 2010-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 25 · **pages:** 25
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 18 · kind `unresolved` (type `tag_review`)
  - facet `convenio` · parsed value: HOSTELERIA  2008 2010.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 21 de enero de 2009, de la Delegada Territorial de Gipuzkoa del Departamento de Justicia, Empleo y
Seguridad Social, por la que se dispone el registro, publicación y depósito del Convenio Colectivo de Hostelería de
Gipuzkoa de 2008-2010
BOG 5 Febrero 2009
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 2000705
Ámbito territorial: Gipuzkoa
Sector de actividad: Hostelería y turismo
Duración temporal: del 1 enero 2008 hasta 31 diciembre 2010
INTRODUCCIÓN
ANTECEDENTES
Primero: El día 11 de diciembre de 2008 se ha presentado en esta delegación el convenio citado, suscrito por la Asociación de Hostelería y ADEGI,
en representación de los empresarios, y por ELA, LAB, CC.OO. y UGT, en representación de los trabajadores, el pasado 24 de noviembre de 2008.
Se
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 22 — CONVENIO COLECTIVO BIBLIOTECAS GUIPUZCOA 2022

- **uuid:** `087dc666-e190-4386-8f09-e1b917fcb698`
- **original filename:** `CONVENIO COLECTIVO BIBLIOTECAS GUIPUZCOA 2022.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 29 · **pages:** 29
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 19 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: CONVENIO COLECTIVO BIBLIOTECAS
  - facet `convenio` · parsed value: CONVENIO COLECTIVO BIBLIOTECAS GUIPUZCOA 2022.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
EUSKO JAURLARITZA
LAN ETA ENPLEGU SAILA
Gipuzkoako Lurralde Ordezkaritza
EBAZPENA, Lan eta Gizarte Segurantzako Lurralde
or dezkariarena, Gi puz koa ko informazioaren eta do-
kumentazioaren kudeatzaileentzako sektoreko Lan
Hi tzar menaren erregistro, gordailu eta argitarapena
agintzen duena (20104415012022 kodea).
AURREKARiAK
Lehenen goa.    2022ko apirilaren 27an, Asociacion Galdek
enpresaburuen ordezkapenean, eta LAB sindikatuak langileen
ordezkapenean, sinatu zuten aipatutako hi tzar men kolektiboa.
Bigarrena.    2022ko maiatzaren 2an, aurkeztu zen Lurralde
Ordezkaritza honetan, adierazitako hi tzar menaren erregistro,
gordailu eta argitalpen eskaera.
ZUZENBiDEKO OiNARRiAK
Lehenen goa.    Urriaren 23ko 2/2015 Legegintzako Errege
Dekretuak onartutako Langileen Estatutuaren Legearen testu
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 29 — 22000175012004 HOSTELERIA HUESCA 2022 2024

- **uuid:** `327fe447-24ff-47a8-93c4-56b9e8b97505`
- **original filename:** `22000175012004_HOSTELERIA HUESCA_2022 2024.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** 2022-01-01 → 2024-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 32 · **pages:** 32
- **chunk count:** 0
- **scope:** convenio 16 · 22000175012004 · HOSTELERIA Y TURISMO HUESC; territory 3 22 Huesca; sector 14 HOSTELERIA Y TURISMO HUESC

**Open review tasks**

- task 20 · kind `conflict` (type `conflict`)
  - facet `sector` · parsed value: parsed sector HOSTELERIA disagrees with registry · registry value: HOSTELERIA Y TURISMO HUESC (The task stores the sentence only. This is the bound convenio sector DocumentTagger compared (DocumentTagger.php rule 3).)

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
ADMINISTRACIÓN AUTONÓMICA
GOBIERNO DE ARAGÓN
DEPARTAMENTO DE ECONOMÍA, PLANIFICACIÓN Y EMPLEO
SUBDIRECCIÓN PROVINCIAL DE TRABAJO
4095 
RESOLUCIÓN
RESOLUCIÓN de la Subdirección Provincial de Trabajo del Departamento de Economía,
Planificación y Empleo en Huesca por la que se dispone la inscripción en el registro y
publicación del CONVENIO COLECTIVO PROVINCIAL PARA LAS INDUSTRIAS DE
HOSTELERIA Y TURISMO DE LA PROVINCIA DE HUESCA (código de convenio número
22000175012004).
 
Visto el Texto del CONVENIO COLECTIVO PROVINCIAL PARA LAS INDUSTRIAS DE
HOSTELERIA Y TURISMO DE LA PROVINCIA DE HUESCA (código de convenio número
22000175012004), suscrito entre los representantes de los empresarios del citado sector y
su representación sindical, éstos afiliados a las centrales sindicales de U.G.T. y CC.O
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

None.

**Would confirming this document give its convenio its only current text?** No. Confirm tags does not change retrieval_status (historical) or document_type (convenio_text). Current text means an active document of an embeddable prose type (convenio_text, partial_agreement, national_law, internal_hr_ruling). This document would not be one.

**Employees on staging:** 1 (active 1).

### 37 — HOSTELERIA 20137 2014

- **uuid:** `7fb88aa0-6fc9-4aca-8475-bdd2b266c4a7`
- **original filename:** `HOSTELERIA 20137 2014.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 21 · **pages:** 21
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 25 · kind `unresolved` (type `tag_review`)
  - facet `convenio` · parsed value: HOSTELERIA 20137 2014.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución 11 de marzo de 2014, de la Directora General de Trabajo y Prevención de Riesgos, por la que se acuerda el
registro, depósito y publicación en el Boletín Oficial de Navarra del Convenio Colectivo del sector Industria de Hostelería
de Navarra
BON 8 Abril 2014
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 31003805011981
Ámbito territorial: Comunidad Foral de Navarra
Sector de actividad: Hostelería y turismo
Duración temporal: del 1 enero 2013 hasta 31 diciembre 2014
Efectos económicos: desde 1 enero 2013
INTRODUCCIÓN
Visto el texto del Convenio Colectivo del sector Industria de Hostelería de Navarra (código número 31003805011981), que tuvo entrada en este
Registro, en fecha 10 de febrero de 2014, que fue suscrito el día 21 de noviembre de 2013 por la re
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 38 — oficinas-despachos-navarra2017

- **uuid:** `a3792e34-8965-49a9-b9d8-ad29a55c1cdc`
- **original filename:** `oficinas-despachos-navarra2017.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 25 · **pages:** 25
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 26 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: oficinas-despachos-navarra2017
  - facet `convenio` · parsed value: oficinas-despachos-navarra2017.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Fecha de entrada en el Registro: 29 de diciembre de 2016
Código número: 31005105011984
Boletín Oficial de Navarra número 51, de 14 de marzo de 2017
Convenio Colectivo del Sector  
Oficinas y Despachos de Navarra
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 39 — GESTIÓN DEPORTIVA 2012

- **uuid:** `0d8bd296-ddd7-4dec-a789-e4f34b4d5c69`
- **original filename:** `GESTIÓN DEPORTIVA 2012.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 13 · **pages:** 13
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 27 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: GESTIÓN DEPORTIVA
  - facet `convenio` · parsed value: GESTIÓN DEPORTIVA 2012.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
RESOLUCIÓN de 30 de enero de 2013, de la Directora General de Trabajo y Prevención de Riesgos, por la que se acuerda
el registro, depósito y publicación en el Boletín Oficial de Navarra del Convenio Colectivo del sector Empresas Gestoras de
Servicios y Equipamientos Deportivos de Navarra
BON 5 Marzo 2013
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 31008235012003
Ámbito territorial: Comunidad Foral de Navarra
Sector de actividad: Empresas gestoras de servicios y equipamientos deportivos
Duración temporal: del 1 enero 2012 hasta 31 diciembre 2012
INTRODUCCIÓN
Visto el texto del Convenio Colectivo del sector Empresas Gestoras de Servicios y Equipamientos Deportivos de Navarra (código número
31008235012003), que tuvo entrada en este Registro en fecha 15 de enero
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 40 — 31008235012003 DEPORTE NAVARRA 2016 2020

- **uuid:** `911bb7bc-f78c-4fcd-b486-1f5f44179dae`
- **original filename:** `31008235012003_DEPORTE NAVARRA_2016 2020.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** 2016-01-01 → 2020-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 27 · **pages:** 27
- **chunk count:** 0
- **scope:** convenio 20 · 31008235012003 · GESTIÓN DEPORTIVA NAVARRA; territory 5 31 Navarra; sector 16 GESTIÓN DEPORTIVA

**Open review tasks**

- task 28 · kind `conflict` (type `conflict`)
  - facet `sector` · parsed value: parsed sector DEPORTE disagrees with registry · registry value: GESTIÓN DEPORTIVA (The task stores the sentence only. This is the bound convenio sector DocumentTagger compared (DocumentTagger.php rule 3).)

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Fecha de entrada en el Registro: 5 de octubre de 2018
Código número: 31008235012003
Boletín Oficial de Navarra número 242, de 18 de diciembre de 2018
Convenio Colectivo del Sector Empresas 
y Entidades Privadas Gestoras 
de Servicios y Equipamientos Deportivos 
Propiedad de otras Entidades de Navarra
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

- id 50 · `convenio_text` · validity 2025-01-01 → 2028-12-31 · `active` / `verified` · CONVENIO DEPORTE NAVARRA 2025 A 2028

**Would confirming this document give its convenio its only current text?** No. Confirm tags does not change retrieval_status (historical) or document_type (convenio_text). Current text means an active document of an embeddable prose type (convenio_text, partial_agreement, national_law, internal_hr_ruling). This document would not be one.

**Employees on staging:** 6 (active 6).

### 42 — OFICINAS Y DESPACHOS

- **uuid:** `8878bc48-2b11-4b3f-820b-ede0f7dc7995`
- **original filename:** `OFICINAS Y DESPACHOS.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 15 · **pages:** 15
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 29 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: OFICINAS DESPACHOS
  - facet `convenio` · parsed value: OFICINAS Y DESPACHOS.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
RESOLUCIÓN de 11 de julio de 2013, de la Directora General de Trabajo y Prevención de Riesgos, por la que se acuerda
el registro, depósito y publicación en el Boletín Oficial de Navarra del Convenio Colectivo del sector Oficinas y Despachos
de Navarra, para los años 2010­2014
BON 21 Agosto 2013
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 31005105011984
Ámbito territorial: Comunidad Foral de Navarra
Sector de actividad: Oficinas y despachos
Duración temporal: del 1 enero 2010 hasta 31 diciembre 2014
Efectos económicos: desde 1 enero 2010
INTRODUCCIÓN
Visto el texto del Convenio Colectivo del sector Oficinas y Despachos de Navarra (Código número 31005105011984), que tuvo entrada en este
Registro en fecha 2 de julio de 2013, que fue suscrito el día 2 de julio, y
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 44 — convenio hosteleria navarra 2019

- **uuid:** `8cd8eea9-bc18-41f2-9ed2-0ffb09731a54`
- **original filename:** `convenio hosteleria navarra 2019.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 29 · **pages:** 29
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 30 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: convenio hosteleria
  - facet `convenio` · parsed value: convenio hosteleria navarra 2019.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Fecha de entrada en el Registro: 23 de julio de 2019
Código número: 31003805011981
Boletín Oficial de Navarra número 223, de 12 de noviembre de 2019
Convenio Colectivo del Sector 
de Hostelería de Navarra
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 45 — Convenio Hosteleria 2015 - 2017

- **uuid:** `c7ed871a-c3fd-4c44-913f-5d86be65861c`
- **original filename:** `Convenio Hosteleria 2015 - 2017.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 39 · **pages:** 39
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 31 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: Convenio Hosteleria
  - facet `convenio` · parsed value: Convenio Hosteleria 2015 - 2017.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
CONVENIO COLECTIVO 
 PARA LA INDUSTRIA DE HOSTELERÍA 
2015-2016-2017 
 
 
 
 
ARTICULO 1º.- ÁMBITO FUNCIONAL Y DETERMINACIÓN DE LAS PARTES 
 
El presente Convenio Colectivo se concierta por los Representantes designados por la 
Asociación de Empresarios de Hostelería Y Turismo de Navarra (AEHN) y la Asociación 
Navarra de Pequeña empresa de Hostelería (ANAPEH) como parte empresarial y las 
Centrales Sindicales, Unión General de Trabajadores ( U.G.T.)  y  Comisiones Obreras 
(C.C.O.O.) como integrantes de la parte social. 
 
El presente Convenio es de aplicación a las empresas y trabajadores del sector de hostelería. 
Se incluyen en el sector de hostelería todas las empresas que independientemente de su 
titularidad y fines perseguidos, realicen en instalaciones fijas o móviles y tanto de f
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 47 — Pacto de la empresa

- **uuid:** `2aa6ad5f-c210-475c-8d33-00ab0355cdf3`
- **original filename:** `Pacto de la empresa.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 2 · **pages_with_text:** 2 · **pages:** 2
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 33 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: Pacto empresa
  - facet `convenio` · parsed value: Pacto de la empresa.pdf

**AI suggestions**

- facet `document_type` · value `partial_agreement` · confidence 0.95
- facet `convenio` · value `01100635012017` · confidence 0.85
- facet `territory` · value `31` · confidence 0.95
- facet `sector` · value `OCIO EDUCATIVO Y ANIMACION SOCIOCUL` · confidence 0.85
- facet `validity` · value `2019-01-01..2021-12-31` · confidence 0.75
- facet `topic` · value `retribución` · confidence 0.95
- facet `topic` · value `bajas médicas` · confidence 0.9
- facet `topic` · value `permisos retribuidos` · confidence 0.85

**Cover (first 800 characters of page 1)**

```text
REUNIDOS

ACUERDAN

PRIMERO.- AMBITO DE APLICACIÓN

SEGUNDO.-

TERCERO.- INCREMENTO SALARIAL

En Pamplona a 12 de diciembre de 2019

REUNIDOS

De una parte: en representación de la empresa SEDENA, Don [nombre], con DNI. [DNI], consejero delegado.

De otra parte: El comité de empresa de SEDENA, S.L.

ACUERDAN

PRIMERO.- AMBITO DE APLICACIÓN: El presente acuerdo será de aplicación a las personas trabajadoras de Sedena S.L , cuyo Convenio Colectivo de aplicación es el ocio educativo y animación socio cultural y prestan servicio en los siguientes centros de trabajo que Sedena gestiona en Navarra:

1. Gestión de servicios socioculturales de la Red Civivox
2. Casa de la Juventud de Pamplona
3. Centro Sociocultural Civican
4. Servicio de Información y Recursos pa
```

**Duplicate first page** (same text hash)

- id 31 · `active` / `under_review` · `convenio_text` · `PACTO CULTURA NAVARRA.pdf`

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 48 — DEPORTE NAVARRA 2013-2015  S

- **uuid:** `ae32fc00-2e2a-4338-bd5f-3fd8656c4872`
- **original filename:** `DEPORTE NAVARRA 2013-2015 _S_.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 28 · **pages:** 28
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 34 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: DEPORTE 2013-2015 S
  - facet `convenio` · parsed value: DEPORTE NAVARRA 2013-2015 _S_.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
1 
 
 
CONVENIO COLECTIVO DE EMPRESAS Y ENTIDADES PRIVADAS GESTORAS DE 
SERVICIOS Y EQUIPAMIENTOS DEPORTIVOS PROPIEDAD DE OTRAS 
ENTIDADES, DE NAVARRA 
 
CAPITULO PRELIMINAR: DETERMINACION DE LAS PARTES 
 
Art. 1º- El presente Convenio Colectivo se concierta por la parte económica, por la 
Asociación Navarra de Empresas gestoras de servicios y equipamientos deportivos 
propiedad de entidades públicas y/o privadas (AGEDENA) y por la parte social, por los 
representantes de las centrales sindicales Unión General de los Trabajadores (UGT) y 
Comisiones Obreras (CCOO)  
 
CAPITULO PRIMERO: DISPOSICIONES GENERALES 
 
Art. 2º- AMBITO FUNCIONAL. 
El presente Convenio es de aplicación a las empresas y entidades privadas que gestionan 
servicios y equipamientos deportivos propiedad de otras entidad
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 49 — oficinas-despachos-navarra revisió salarial

- **uuid:** `c0649ef7-169a-47c8-b9a2-cfe53a371f0a`
- **original filename:** `oficinas-despachos-navarra revisió salarial.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 6 · **pages:** 6
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 35 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: oficinas-despachos-navarra revisió salarial
  - facet `convenio` · parsed value: oficinas-despachos-navarra revisió salarial.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Página 16 - Número 2
Martes, 5 de enero de 2021
Artículo 5.º  Creación de un puesto de trabajo en el Departamento 
de Educación.
Uno.–Creación del puesto de trabajo de Experto en Aprendizaje de 
Competencias Básicas para Adultos, nivel B, adscrito al Negociado de 
Formación Permanente del Departamento de Educación.
Dos.–A dicho puesto de trabajo se le asigna el complemento de incom-
patibilidad y un complemento de puesto de trabajo del 6,73% del sueldo 
de su correspondiente nivel, según lo establecido en los Decretos Forales 
318/2004, 11/2007 y 19/2008.
DISPOSICIONES FINALES
Disposición final primera.–Habilitación para el desarrollo y ejecución 
del decreto foral.
Se faculta al Consejero de Presidencia, Igualdad, Función Pública e 
Interior para dictar las disposiciones precisas en desar
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 60 — Subida salarial según convenio 2023

- **uuid:** `71bf563d-1c9b-4da0-8963-131b4bf8f990`
- **original filename:** `Subida salarial según convenio 2023.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 2 · **pages:** 2
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 40 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: Subida salarial según convenio
  - facet `convenio` · parsed value: Subida salarial según convenio 2023.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
SUBIDA SALARIAL AÑO 2023
“IPC2019 + IPC2020 + IPC2021 + IPC2022 + 3 % - 4´5 %”
•
IPC de los años 2019 (1´2), 2020 (-0´3), 2021 (6´4) y 2022 (5´4). 
•
Los IPC negativos no se contarán para la fórmula, por lo que tenemos que excluir el año 2020.
•
1'2+6'4+5'4-1'5= 11´5% de subida salarial al salario base del 2022.
CATEGORÍA
2022
2023
Director/a gerente
30.311,80
33.797'66
Jefe/a de Departamento
26.745,71
29.821'47
Director de programas y equipamientos
26.745,71
29.821'47
Coordinador/a proyectos pedagógicos
25.408,43
28.330'40
Titulado/a de Grado
25.408,43
28.330'40
Técnico/a de gestión
25.408,43
28.330'40
Coordinador/a de actividades y proyectos de centro/servicios
24.071,14
26.839'32
Técnico/a especialista
24.071,14
26.839'32
Técnico de biblioteca
24.071,14
26.839'32
Técnico/a especialista
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 61 — INTERVENCIÓN SOCIAL ALAVA

- **uuid:** `d5542180-b359-4284-b2f0-f221b33d9733`
- **original filename:** `INTERVENCIÓN SOCIAL ALAVA.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 20 · **pages:** 20
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 41 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: INTERVENCIÓN SOCIAL
  - facet `convenio` · parsed value: INTERVENCIÓN SOCIAL ALAVA.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 29 de enero de 2015 del delegado territorial de Trabajo, Empleo y Políticas Sociales de Álava del
Departamento de Empleo y Políticas Sociales, por la que se dispone el registro, publicación y depósito del convenio
colectivo 2014-2015-2016 para el Sector de Intervención Social de Álava
BOTHA 11 Febrero 2015
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 01100025012015
Ámbito territorial: Álava
Sector de actividad: Acción e intervención social
Duración temporal: del 1 enero 2014 hasta 31 diciembre 2016
INTRODUCCIÓN
ANTECEDENTES
El día 13 de enero de 2015 se ha presentado en esta Delegación el texto del convenio colectivo citado, suscrito por la representación empresarial
AISA y la representación social ESK, LAB, ELA, UGT y CCOO en la mesa negociadora
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 62 — 2022 101 02474 C

- **uuid:** `99352d0f-21fc-41c9-93ff-08c5169243ee`
- **original filename:** `2022_101_02474_C.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 33 · **pages:** 33
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 42 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: C
  - facet `convenio` · parsed value: 2022_101_02474_C.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
lunes, 5 de septiembre de 2022  •  Núm. 101
1/33
2022-02474
D.L.: VI-1/1958 ISSN: 2254-8432
www.araba.eus
III - OTRAS ADMINISTRACIONES PÚBLICAS
GOBIERNO VASCO
DEPARTAMENTO DE TRABAJO Y EMPLEO
Delegación Territorial de Álava
Convenio colectivo para el sector de empresas que realizan actividades deportivas para ter­
ceros en Álava
Resolución de la delegada territorial de Trabajo y Seguridad Social de Álava del Depar­
tamento de Trabajo y Empleo, por la que se dispone el registro, publicación y depósito del 
convenio colectivo 2020-2021-2022-2023 para el sector de empresas que realizan actividades 
deportivas para terceros en Álava. Código convenio número 01003205012006.
ANTECEDENTES
El día 12 de julio de 2022 se ha presentado en esta Delegación el texto del convenio colec­
tivo citado, suscr
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 66 — LOCALES Y CAMPOS DEPORTIVOS DE BIZKAIA

- **uuid:** `252e74ef-f77a-4d50-a7b1-dbb88b110b34`
- **original filename:** `LOCALES Y CAMPOS DEPORTIVOS DE BIZKAIA.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 16 · **pages:** 16
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 44 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: LOCALES CAMPOS DEPORTIVOS
  - facet `convenio` · parsed value: LOCALES Y CAMPOS DEPORTIVOS DE BIZKAIA.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 13 de octubre de 2020, del delegado territorial de Trabajo y Seguridad Social de Bizkaia, del
Departamento de Trabajo y Empleo, por la que se dispone el registro, publicación y depósito del Convenio
Colectivo de Locales y Campos Deportivos de Bizkaia, 2018-2022
BOB 23 Noviembre 2020
Ref. CISS 22493/2020
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 48001455011981; 4801455;
Ámbito territorial: Bizkaia
Sector de actividad: Locales de espectáculos y deportes
Duración temporal: del 1 enero 2018 hasta 31 diciembre 2022
INTRODUCCIÓN
Antecedentes
Por vía telemática se ha presentado en esta delegación el convenio citado, suscrito por la representación patronal y la representación
sindical.
Fundamentos de derecho
Primero: La competencia prevista en el artí
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 68 — CONDICIONES LABORALES DEL PERSONAL SUBROGADO BARAKALDO

- **uuid:** `c178518e-abf3-425d-8bf7-c90226a81f8f`
- **original filename:** `CONDICIONES LABORALES DEL PERSONAL SUBROGADO BARAKALDO.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 1 · **pages_with_text:** 1 · **pages:** 1
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 45 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: CONDICIONES LABORALES PERSONAL SUBROGADO BARAKALDO
  - facet `convenio` · parsed value: CONDICIONES LABORALES DEL PERSONAL SUBROGADO BARAKALDO.pdf
  - facet `convenio` · parsed value: Convenio Colectivo de Intervención Social de Bizkaia

**AI suggestions**

- facet `document_type` · value `reference_source` · confidence 0.7
- facet `convenio` · value `48006185012006` · confidence 0.85
- facet `territory` · value `48` · confidence 0.9
- facet `sector` · value `INTERVENCION SOCIAL` · confidence 0.85
- facet `topic` · value `jornada` · confidence 0.95
- facet `topic` · value `bajas médicas` · confidence 0.9
- facet `topic` · value `retribución` · confidence 0.75

**Cover (first 800 characters of page 1)**

```text
1.4. CONDICIONES LABORALES DEL PERSONAL

suspergintza elkartea

edetaldea

1.4. CONDICIONES LABORALES DEL PERSONAL

Básicamente las condiciones laborales que tiene el personal adscrito al programa están regidas por el Convenio Colectivo de Intervención Social de Bizkaia.

Las condiciones especificadas en dicho convenio, están mejoradas en algunos aspectos por condiciones anteriores que colectivamente se han mantenido como práctica y costumbre. Estos elementos son:

– Jornada laboral: todo el personal del programa realiza 1635 horas anuales.

– Cobro durante el periodo de IT: los trabajadores y trabajadoras en situación de IT, tanto por contingencias profesionales como comunes, reciben el complemento necesario para completar el 100% de su base reguladora, durante todo el periodo que dure la
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 69 — PACTO

- **uuid:** `d4ecc298-2257-4060-adbe-24bfad0026a4`
- **original filename:** `PACTO.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 7 · **pages_with_text:** 7 · **pages:** 7
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 46 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: PACTO
  - facet `convenio` · parsed value: PACTO.pdf
  - facet `convenio` · parsed value: Personal subcontratado actividades deportivas Durango Kirolak

**AI suggestions**

- facet `document_type` · value `convenio_text` · confidence 0.95
- facet `convenio` · value `48001455011981` · confidence 0.85
- facet `territory` · value `48` · confidence 0.9
- facet `sector` · value `LOCALES Y CAMPOS DEPORTIVOS` · confidence 0.85
- facet `validity` · value `2018-01-01..2020-12-31` · confidence 0.95
- facet `topic` · value `retribución` · confidence 0.95
- facet `topic` · value `jornada` · confidence 0.9
- facet `topic` · value `permisos retribuidos` · confidence 0.9
- facet `topic` · value `festivos` · confidence 0.85
- facet `topic` · value `formación` · confidence 0.8
- facet `topic` · value `conciliación` · confidence 0.75
- facet `topic` · value `permisos no retribuidos` · confidence 0.75
- facet `topic` · value `normativa/derechos` · confidence 0.7

**Cover (first 800 characters of page 1)**

```text
Articulo 1. Ámbito personal, funcional y territorial

Artículo 2. Vigencia

Artículo 3. Prioridad aplicativa

ACUERDO COLECTIVO DE LAS CONDICIONES LABORALES

DEL PERSONAL SUBCONTRATADO, QUE IMPARTE ACTIVIDADES DEPORTIVAS EN EL "O.A. DURANGO KIROLAK" DEL AYUNTAMIENTO DE DURANGO

Articulo 1. Ámbito personal, funcional y territorial

El presente acuerdo colectivo afecta a todos/as los/as trabajadores/as, presentes y futuros, con independencia de su categoría profesional que, durante su vigencia, presten servicios bajo dependencia y por cuenta y órdenes de la empresa adjudicataria de la gestión de las actividades deportivas municipales del Organismo Autónomo Durango Kirolak, perteneciente al Ayuntamiento de Durango

Artículo 2. Vigencia

La vigencia del presente acuerdo colectivo se extenderá
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 70 — ACCIÓN E INTERVENCIÓN SOCIAL VIZCAYA

- **uuid:** `92062f97-6a2e-4f29-9b02-99d61309e201`
- **original filename:** `ACCIÓN E INTERVENCIÓN SOCIAL VIZCAYA.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 22 · **pages:** 22
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 47 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: ACCIÓN E INTERVENCIÓN SOCIAL
  - facet `convenio` · parsed value: ACCIÓN E INTERVENCIÓN SOCIAL VIZCAYA.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 17 de diciembre de 2018, del delegado territorial de Trabajo y Seguridad Social de Bizkaia del
Departamento de Trabajo y Justicia, por la que se dispone el registro, publicación y depósito del Convenio Colectivo
del sector de Intervención Social 2017-2021 de Bizkaia
BOB 15 Enero 2019
Ref. CISS 22148/2018
Tipo de convenio: Convenio colectivo de trabajo
Código convenio colectivo: 48006185012006; 4806185;
Ámbito territorial: Bizkaia
Sector de actividad: Acción e intervención social
Duración temporal: del 1 enero 2017 hasta 31 diciembre 2021
INTRODUCCIÓN
Antecedentes
Por vía telemática se ha presentado en esta delegación el acuerdo citado, suscrito por la representación empresarial y sindical.
Fundamentos de derecho
Primero: La competencia prevista en el artículo 90.2 de la Ley d
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 71 — LOCALES Y CAMPOS DEPORTIVOS

- **uuid:** `be8cedde-d587-4b9b-961a-20520d33fd00`
- **original filename:** `LOCALES Y CAMPOS DEPORTIVOS.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 23 · **pages:** 23
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 48 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: LOCALES CAMPOS DEPORTIVOS
  - facet `convenio` · parsed value: LOCALES Y CAMPOS DEPORTIVOS.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
cve: BOB-2020a225
BOLETÍN OFICIAL DE BIZKAIA
BOB
Lunes, 23 de noviembre de 2020
Núm. 225
Pág. 11
SECCIÓN III
ADMINISTRACIÓN AUTONÓMICA DEL PAÍS VASCO
Departamento de Trabajo y Empleo
Resolución del Delegado Territorial de Trabajo, y Seguridad Social de Bizkaia 
del Departamento de Trabajo y Empleo, por la que se dispone el registro, 
publicación y depósito del Convenio Colectivo de Locales y Campos Depor-
tivos de Bizkaia (código de convenio 48001455011981).
Antecedentes
Por vía telemática se ha presentado en esta delegación el convenio citado, suscrito 
por la representación patronal y la representación sindical.
Fundamentos de derecho
Primero: La competencia prevista en el artículo 90.2 de la Ley del Estatuto de los Tra-
bajadores, Real Decreto Legislativo 2/2015 de 23 de octubre («BOE»
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 72 — V Convenio Intervención social 2017-2021

- **uuid:** `c134618f-ec1f-4104-a3da-2e5e24d7fc3f`
- **original filename:** `V Convenio Intervención social 2017-2021.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 33 · **pages:** 33
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 49 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: V Convenio Intervención social 2017-2021
  - facet `convenio` · parsed value: V Convenio Intervención social 2017-2021.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
cve: BOB-2019a010-(III-2)
BOLETÍN OFICIAL DE BIZKAIA
BOB
Martes, 15 de enero de 2019
Núm. 10
Pág. 32
SECCIÓN III
ADMINISTRACIÓN AUTONÓMICA DEL PAÍS VASCO
Departamento de Trabajo y Justicia
Resolución del delegado territorial de Trabajo, y Seguridad Social de Bizkaia 
del Departamento de Trabajo y Justicia, por la que se dispone el registro, 
publicación y depósito del Convenio Colectivo del sector de Intervención 
Social de Bizkaia (código de convenio 48006185012006).
Antecedentes
Por vía telemática se ha presentado en esta delegación el acuerdo citado, suscrito 
por la representación empresarial y sindical.
Fundamentos de derecho
Primero: La competencia prevista en el artículo 90.2 de la Ley del Estatuto de los Tra-
bajadores, Real Decreto Legislativo 2/2015 de 23 de octubre («BOE» de 24
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 73 — 99015105012005 Deporte Estatal 2023 2025

- **uuid:** `5ff35bef-698a-4726-ae02-9f8b07102542`
- **original filename:** `99015105012005_Deporte Estatal_2023 2025.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** 2023-01-01 → 2025-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 43 · **pages:** 43
- **chunk count:** 0
- **scope:** convenio 9 · 99015105012005 · INSTALACIONES DEPORTIVAS Y GIMNASIO; territory 12 99 Estatal; sector 8 INSTALACIONES DEPORTIVAS Y GIMNASIO

**Open review tasks**

- task 50 · kind `conflict` (type `conflict`)
  - facet `sector` · parsed value: parsed sector DEPORTE disagrees with registry · registry value: INSTALACIONES DEPORTIVAS Y GIMNASIO (The task stores the sentence only. This is the bound convenio sector DocumentTagger compared (DocumentTagger.php rule 3).)

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
III. OTRAS DISPOSICIONES
MINISTERIO DE TRABAJO Y ECONOMÍA SOCIAL
1506
Resolución de 16 de enero de 2024, de la Dirección General de Trabajo, por 
la que se registra y publica el V Convenio colectivo estatal de instalaciones 
deportivas y gimnasios.
Visto el texto del V Convenio colectivo estatal de instalaciones deportivas y 
gimnasios (Código de convenio número 99015105012005), que fue suscrito con fecha 20 
de octubre de 2023, y finalmente subsanado mediante acta de fecha 22 de diciembre 
de 2023, de una parte por las organizaciones empresariales Federación Nacional de 
Empresarios de Instalaciones Deportivas (FNEID) y Asociación Española de Campos de 
Golf (AECG), en representación de las empresas del sector y, de otra, por las 
organizaciones sindicales FeSMC-UGT y FSC-CC.OO., en repre
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

- id 85 · `partial_agreement` · validity 2023-09-26 → — · `active` / `verified` · CONVENIO DEPORTE ESTATAL

**Would confirming this document give its convenio its only current text?** No. Confirm tags does not change retrieval_status (historical) or document_type (convenio_text). Current text means an active document of an embeddable prose type (convenio_text, partial_agreement, national_law, internal_hr_ruling). This document would not be one.

**Employees on staging:** 1 (active 1).

### 78 — deporte estatal 2023

- **uuid:** `d85479e2-ea34-424d-b449-67bfc0fc6411`
- **original filename:** `deporte estatal 2023.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 17 · **pages:** 17
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 54 · kind `unresolved` (type `tag_review`)
  - facet `convenio` · parsed value: deporte estatal 2023.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
III. OTRAS DISPOSICIONES
MINISTERIO DE TRABAJO Y ECONOMÍA SOCIAL
20101
Resolución de 11 de septiembre de 2023, de la Dirección General de Trabajo, 
por la que se registra y publica el Acuerdo de modificación parcial del IV 
Convenio colectivo estatal de instalaciones deportivas y gimnasios.
Visto el texto del Acta de la Comisión Negociadora del IV Convenio colectivo estatal 
de instalaciones deportivas y gimnasios mediante la que se acuerda la modificación 
parcial del IV Convenio colectivo del sector, que fue publicado en el «Boletín Oficial del 
Estado» de 11 de junio de 2018 (código de convenio n.º 99015105012005), Acta que ha 
sido suscrita con fecha 22 de junio de 2023 por la Federación Nacional de Empresarios 
de Instalaciones Deportivas (FNEID) y la Asociación Española de Campos de
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 82 — acción e intervención

- **uuid:** `265d73f4-c37f-40c9-9b5b-480b65962e25`
- **original filename:** `acción e intervención.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 58 · **pages:** 58
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 55 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: acción e intervención
  - facet `convenio` · parsed value: acción e intervención.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
BOLETÍN OFICIAL DEL ESTADO
Núm. 158	
Viernes 3 de julio de 2015	
Sec. III.   Pág. 54664
III. OTRAS DISPOSICIONES
MINISTERIO DE EMPLEO Y SEGURIDAD SOCIAL
7435
Resolución de 22 de junio de 2015, de la Dirección General de Empleo, por la 
que se registra y publica el Convenio colectivo estatal de acción e intervención 
social 2015-2017.
Visto el texto del Convenio colectivo estatal de acción e intervención social 2015-2017, 
(código de Convenio número 99016085012007) que fue suscrito con fecha 13 de mayo de 
2015, de una parte por la Asociación Española de Empresas de Iniciativa Social y Servicios 
Sociales (AEEISSS), la Asociación Estatal de Organizaciones y Acción e Intervención 
Social (OEIS) y la Asociación de Empresas de Servicios de Atención a la Persona 
(AESAP), en representación de l
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 83 — II CC OCIO EDUCATIVO Y ANIMACION SOCIOCULTURAL PDF DEFINITIVO - FIRMADO

- **uuid:** `8f81b08b-b965-48e9-aa42-f2dd010d3480`
- **original filename:** `II_CC_OCIO_EDUCATIVO_Y_ANIMACION_SOCIOCULTURAL_PDF_DEFINITIVO_-_FIRMADO_.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 79 · **pages:** 79
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 56 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: II CC OCIO EDUCATIVO ANIMACION SOCIOCULTURAL FIRMADO
  - facet `convenio` · parsed value: II_CC_OCIO_EDUCATIVO_Y_ANIMACION_SOCIOCULTURAL_PDF_DEFINITIVO_-_FIRMADO_.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Usuario 
 
0 
 
 
II CC marco Estatal Ocio 
Educativo y Animacio n 
Sociocultural 
 
 
Madrid, 21 de mayo de 2015
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 87 — 99100055012011 COEAS Estatal 2020 2023

- **uuid:** `db6e2072-0e55-439f-be5e-6768bea32443`
- **original filename:** `99100055012011_COEAS Estatal_2020 2023.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** 2020-01-01 → 2023-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 62 · **pages:** 62
- **chunk count:** 0
- **scope:** convenio 11 · 99100055012011 · OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL; territory 12 99 Estatal; sector 3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL

**Open review tasks**

- task 59 · kind `conflict` (type `conflict`)
  - facet `sector` · parsed value: parsed sector COEAS disagrees with registry · registry value: OCIO EDUCATIVO Y ANIMACION SOCIOCUL (The task stores the sentence only. This is the bound convenio sector DocumentTagger compared (DocumentTagger.php rule 3).)

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
BOLETÍN OFICIAL DEL ESTADO
Núm. 69	
Lunes 22 de marzo de 2021	
Sec. III.   Pág. 32574
III. OTRAS DISPOSICIONES
MINISTERIO DE TRABAJO Y ECONOMÍA SOCIAL
4476
Resolución de 10 de marzo de 2021, de la Dirección General de Trabajo, por 
la que se registra y publica el III Convenio colectivo marco estatal del sector 
ocio educativo y animación sociocultural.
Visto el texto del III Convenio colectivo marco estatal del sector Ocio Educativo y 
Animación Sociocultural (Código Convenio 99100055012011), que fue suscrito con fecha 
20 de enero de 2021, de una parte por la Federación Estatal de Organizaciones 
Empresariales de Ocio Educativo y Sociocultural (FOESC), la Fundación Catalana De 
L’Ésplai (AEISS) y la Asociación Nacional De Empresas Socioculturales (ANESOC), en 
representación de las empres
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

- id 6 · `salary_tables` · validity — → — · `active` / `verified` · TABLAS SALARIALES COEAS Estatal
- id 76 · `convenio_text` · validity 2025-01-01 → 2027-12-31 · `active` / `under_review` · 99100055012011 COEAS Estatal 2025 2027

**Would confirming this document give its convenio its only current text?** No. Confirm tags does not change retrieval_status (historical) or document_type (convenio_text). Current text means an active document of an embeddable prose type (convenio_text, partial_agreement, national_law, internal_hr_ruling). This document would not be one.

**Employees on staging:** 3 (active 3).

### 98 — I Convenio Ocio Educativo y Animacion Sociocultural de la Comunidad de Madrid

- **uuid:** `f80f80b6-bd4c-4830-bc7c-413eb298ba23`
- **original filename:** `I Convenio Ocio Educativo y Animacion Sociocultural de la Comunidad de Madrid.pdf`
- **document_type:** `convenio_text` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 47 · **pages:** 47
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 67 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: I Convenio Ocio Educativo Animacion Sociocultural Comunidad
  - facet `convenio` · parsed value: I Convenio Ocio Educativo y Animacion Sociocultural de la Comunidad de Madrid.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
SÁBADO 7 DE JULIO DE 2018
B.O.C.M. Núm. 161
Pág. 5
BOCM-20180707-2
BOLETÍN OFICIAL DE LA COMUNIDAD DE MADRID
BOCM
I. COMUNIDAD DE MADRID
D) Anuncios
Consejería de Economía, Empleo y Hacienda
2
RESOLUCIÓN de 11 de junio de 2018, de la Dirección General de Trabajo de la
Consejería de Economía, Empleo y Hacienda, sobre registro, depósito y publi-
cación del Convenio Colectivo del Sector de Ocio Educativo y Animación Socio-
cultural, suscrito por la Asociación de Empresas de Educación Cultura y Tiem-
po Libre de la Comunidad de Madrid (Educatia-Madrid), la Asociación
Madrileña de Empresas de Enseñanza, Formación y Animación Sociocultural
(AMESOC), y la Federación de Empleados de Servicios Públicos de UGT
(FeSP-UGT Madrid) (Código número 28102145012018).
Examinado el texto del Convenio Colectiv
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 8 — TABLAS SALARIALES 2021 2022 COEAS

- **uuid:** `96d4b2ab-3446-4561-81af-c2c697b54a09`
- **original filename:** `TABLAS SALARIALES 2021 2022 COEAS.xlsx`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** 2021-01-01 → 2022-12-31
- **OCR'd pages:** 0 · **pages_with_text:** 0 · **pages:** 0
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 57 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: SALARIALES COEAS
  - facet `convenio` · parsed value: TABLAS SALARIALES 2021 2022 COEAS.xlsx

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

No extracted text on page 1.

**Duplicate first page** (same text hash)

No page-1 text to hash.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 20 — TABLAS SALARIALES HOSTELERIA

- **uuid:** `01217b2a-179e-49e8-80bc-0af8576f062e`
- **original filename:** `TABLAS SALARIALES HOSTELERIA.pdf`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 2 · **pages:** 2
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 17 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: SALARIALES HOSTELERIA
  - facet `convenio` · parsed value: TABLAS SALARIALES HOSTELERIA.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 9 de febrero de 2010, del Delegado Territorial de Gipuzkoa del Departamento de Empleo y Asuntos
Sociales, por la que se dispone el registro, publicación y depósito de la revisión salarial para 2010 del Convenio Colectivo
de Hostelería de 2008­10
BOG 18 Febrero 2010
Tipo de convenio: Revisión salarial
Código convenio colectivo: 2000705
Ámbito territorial: Gipuzkoa
Sector de actividad: Hostelería y turismo
Duración temporal: del 1 enero 2010 hasta 31 diciembre 2010
INTRODUCCIÓN
ANTECEDENTES
Primero. El día 3 de febrero de 2010 la comisión negociadora del convenio colectivo de Hostelería (código convenio n.º 2000705) ha presentado la
revisión salarial para 2010, prevista en el Anexo II del convenio.
Segundo. La vigencia de dicha revisión salarial se establece del 1 de enero al 3
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 46 — TABLAS OFICINAS Y DESPACHOS

- **uuid:** `24c12894-7ce7-4cbb-97b5-02445916bdd4`
- **original filename:** `TABLAS OFICINAS Y DESPACHOS.pdf`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 1 · **pages:** 1
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 32 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: OFICINAS DESPACHOS
  - facet `convenio` · parsed value: TABLAS OFICINAS Y DESPACHOS.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 30 de abril de 2014, de la Directora General de Trabajo y Prevención de Riesgos, por la que se acuerda el
registro, depósito y publicación en el Boletín Oficial de Navarra del Acuerdo de revisión salarial del Convenio Colectivo del
sector Oficinas y Despachos de Navarra
BON 22 Mayo 2014
Tipo de convenio: Revisión salarial
Código convenio colectivo: 31005105011984
Ámbito territorial: Comunidad Foral de Navarra
Sector de actividad: Oficinas y despachos
Duración temporal: del 1 enero 2014 hasta 31 diciembre 2014
INTRODUCCIÓN
Visto el texto del Acuerdo de revisión salarial del Convenio Colectivo del sector Oficinas y Despachos de Navarra (Código número 31005105011984),
correspondiente al año 2014, que tuvo entrada en este Registro en fecha 19 de abril de 2014, y que fue suscrito
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 88 — Tablas salariales Accion e intervencion Social

- **uuid:** `81ed8aa0-053a-4665-aff9-ced196e60849`
- **original filename:** `Tablas salariales Accion e intervencion Social.pdf`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 7 · **pages:** 7
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 60 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: salariales Accion e intervencion Social
  - facet `convenio` · parsed value: Tablas salariales Accion e intervencion Social.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
BOLETÍN OFICIAL DEL ESTADO
Núm. 28	
Viernes 1 de febrero de 2019	
Sec. III.   Pág. 9141
III. OTRAS DISPOSICIONES
MINISTERIO DE TRABAJO, MIGRACIONES
Y SEGURIDAD SOCIAL
1327
Resolución de 15 de enero de 2019, de la Dirección General de Trabajo, por la 
que se registra y publica el Acta del acuerdo relativo a las tablas salariales 
para los años 2018, 2019, 2020 y 2021 del Convenio colectivo estatal del 
sector de acción e intervención social.
Visto el texto del Acta en la que se contiene el acuerdo relativo a las tablas salariales 
para los años 2018, 2019, 2020 y 2021 del Convenio colectivo estatal del sector de acción e 
intervención social (código de convenio número 99100155012015), Acta que fue suscrita, 
con fecha 15 de noviembre de 2018, de una parte por la Asociación Estatal de Organi
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

### 97 — tablas salariales ocio educativo 21 23

- **uuid:** `cd01e383-5fca-4b3d-9114-4ce3068e2d47`
- **original filename:** `tablas salariales ocio educativo 21 23.pdf`
- **document_type:** `salary_tables` · **authority_level:** `official_convenio`
- **retrieval_status:** `historical` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 5 · **pages:** 5
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 66 · kind `unresolved` (type `tag_review`)
  - facet `sector` · parsed value: salariales ocio educativo
  - facet `convenio` · parsed value: tablas salariales ocio educativo 21 23.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resolución de 2 de agosto de 2021, de la Dirección General de Trabajo de la Consejería de Economía,
Hacienda y Empleo, sobre registro, depósito y publicación del Acta de 14 de junio de 2021, de la Comisión
Negociadora del Convenio Colectivo del sector de Ocio Educativo y Animación Sociocultural de la Comunidad
de Madrid (código 28102145012018)
BOCM 21 Agosto 2021
Tipo de convenio: Revisión salarial
Código convenio colectivo: 28102145012018;
Ámbito territorial: Comunidad de Madrid
Sector de actividad: Ocio educativo y sociocultural
Duración temporal: del 1 octubre 2021 hasta 30 septiembre 2023
INTRODUCCIÓN
Examinada el Acta de la Comisión Negociadora del Convenio Colectivo de Ocio Educativo y Animación Sociocultural de la Comunidad de
Madrid, de fecha 14 de junio de 2021, por la que llegan
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.

## Other active

### 54 — Resumen

- **uuid:** `c0648dc3-2e53-4ab4-83b4-984d3e996328`
- **original filename:** `Resumen.pdf`
- **document_type:** `summary` · **authority_level:** `official_convenio`
- **retrieval_status:** `active` · **language:** `es`
- **validity:** — → —
- **OCR'd pages:** 0 · **pages_with_text:** 3 · **pages:** 3
- **chunk count:** 0
- **scope:** none

**Open review tasks**

- task 38 · kind `unresolved` (type `tag_review`)
  - facet `convenio` · parsed value: Resumen.pdf

**AI suggestions**

None.

**Cover (first 800 characters of page 1)**

```text
Resumen del contrato 
Tipo de contrato 
Convenio Colectivo del Sector de Contratos Públicos 
de Servicios Deportivos  
ID del contrato 
Código: 39100935012024  
Partes 
Asociación Española de Empresarios de Servicios 
Deportivos a las Administraciones Públicas 
(AEESDAP) en representación de las empresas del 
sector.  
, Organizaciones Sindicales 
FSO-CCOO y FeSMC-UGT en 
representación del colectivo 
laboral afectado. 
 
Fecha de inicio 
21 de marzo de 2024  
Fecha de finalización 
31 de diciembre de 2028  
Fecha de entrada en vigor 
21 de marzo de 2024  
Término del contrato 
Desde su publicación oficial en el Boletín Oficial de 
Cantabria hasta el 31 de diciembre de 2028, con 
posibilidad de prórrogas anuales hasta un máximo de 
dos años si no se denuncia el convenio.  
Cláusula de term
```

**Duplicate first page** (same text hash)

None.

**Other documents on the same convenio**

No convenio stored.

**Would confirming this document give its convenio its only current text?** No. Nothing is bound to a convenio, and Confirm tags does not assign one, so confirming this document cannot give a convenio its text.

**Employees on staging:** no convenio, so no headcount.
