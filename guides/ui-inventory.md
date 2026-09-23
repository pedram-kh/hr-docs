# UI inventory — super_admin, Spanish locale

Evidence for a later user guide. Strings are the `es` dictionary values in `hr-frontend/src/i18n/es.ts` (locale `es`). Where that file still stores English, the Spanish UI shows that English. Those lines are quoted as they appear, not translated.

Out of the nav, and not one of the 12 views: `#view=brand-preview` (`Brand preview (CP-1 — sprint-11a)`).

Shell routes: admin console `/admin` (hash selects the view). Employee chat `/app`. Login `/login` (English, shared).

Hash scheme (`src/lib/adminHash.ts`): `#view=<view>&tab=<tab>&fact=<uuid>&emp=<uuid>&convenio=<id>`. Documents also accept bare `#doc=<uuid>`. Default with no hash: Mapa, section Jerarquía.

## Ability matrix

Source: `hr-backend/database/seeders/RoleSeeder.php`. The nav hides on the ability; the server enforces the route. `knowledge_editor` is included because several gates are “super_admin + knowledge_editor”, not “super_admin only”.

| Ability | super_admin | hr_agent | auditor | knowledge_editor |
|---|---|---|---|---|
| `knowledge.edit` | yes | no | no | yes |
| `escalation.work` | yes | yes | no | no |
| `history.view_all` | yes | no | yes | no |
| `directory.manage` | yes | yes | no | no |
| `admin.manage` | yes | no | no | no |
| `guardrails.manage` | yes | no | no | no |
| `vocabulary.approve` | yes | no | no | no |
| `analytics.view` | yes | yes | yes | no |

Nav visibility (`AdminShell.tsx`):

| Nav item | Visible when |
|---|---|
| Mapa, Documentos, Revisión, Escalaciones, Calidad, Guardrails, Ajustes | any admin |
| Historial | `history.view_all` — super_admin, auditor. Hidden from hr_agent and knowledge_editor. |
| Analítica | `analytics.view` — super_admin, hr_agent, auditor. Hidden from knowledge_editor. |
| Cobertura | `analytics.view` OR `knowledge.edit` — all four roles. |
| Directorio | `directory.manage` — super_admin, hr_agent. Hidden from auditor and knowledge_editor. |
| Administradores | `admin.manage` — **super_admin only.** |

Ajustes is in the nav for every admin. `AnswerModelController` calls `authorizeSuperAdmin` on status, save, and delete (`Only a super_admin can manage the answer model.`). hr_agent, auditor, and knowledge_editor see the item and get that 403.

Calidad reads are open to every admin. Writing a verdict requires `escalation.work` (super_admin, hr_agent).

## Shell chrome (every admin view)

Sidebar groups and items, in order:

1. **Conocimiento** — Mapa, Documentos, Revisión
2. **Atención** — Escalaciones, Historial
3. **Análisis** — Analítica, Cobertura, Calidad
4. **Personas** — Directorio, Administradores
5. **Gobierno** — Guardrails, Ajustes

Footer, not from a view dictionary:

- Signed-in email.
- Theme toggle. In light theme the label is `Dark` (hardcoded in `ThemeToggle.tsx`, not in `es.ts`). Theme is not persisted; it follows `prefers-color-scheme` until toggled.
- Locale toggle. In Spanish the label is `English` and the mark is `ES`. `aria-label` is `Switch to English`.
- `Log out` (the `es` value is the English words).

Sidebar controls: `Colapsar menú` / `Expandir menú`. Mobile: `Abrir menú`.

Product `alt` on the wordmark: `HR Platform`.

## Badge colours (light theme)

Tokens from `:root` in `hr-frontend/src/index.css`. Fuchsia is unverified AI only (`--provenance-ai: #e879f9`).

| Class | Light colour | Used for |
|---|---|---|
| `.ai-pill`, `.badge.ai`, `.ai-facet`, `.ai-marked` (left border) | fuchsia `#e879f9` on a 20% tint | IA sin verificar. Pill text is the literal `AI` in the queues and on the document card, or `AI proposal` / `Resumen IA` where the dictionary supplies it. |
| `.badge-manual`, `.badge-historical` | neutral `#475467` on `#f2f4f7` | `Manual`, `Histórico`, `Auto-propuesto`, inactive admin |
| `.badge-review`, `.badge-empty` | warning `#946200` on `#f4efe6` | needs review, under review, empty text, escalated, not-yet-reviewed sample |
| `.badge-conflict` | danger `#b42318` on `#f8e9e8` | conflict, past expiry, wrong quality verdict, uncertainty flag |
| `.badge-verified`, `.badge.ok` | success `#2f7a3d` on `#eaf2ec` | verified, active, correct verdict, approved group |
| `.badge-national`, `.badge-ocr`, `.badge-reference` | info `#2a6ea6` on `#eaf0f6` | national law, OCR, reference-fact class (`dato`) |
| `.badge-agent` | agent bubble styling | human HR reply |
| `.badge.muted` | same neutral pair as historical | rejected / unbound group state |
| `.gap--danger` | danger pair | coverage hole |
| `.gap--warning` | warning pair | coverage warning |
| `.gap--neutral` | neutral pair | staleness / scope note, not a hole |

Grafo node state (legend on the Grafo section, not a badge class): `Verde = conocimiento vigente · Ámbar = borrador · Gris = histórico · Fucsia = IA sin verificar`. Tokens: vigente/verificado `--success` `#2f7a3d`, borrador `--warning` `#946200`, histórico `--text-faint` `#788996`, ámbito `--accent` `#2b6565`, IA sin verificar `--provenance-ai`.

---

## 1. Mapa

- **Route:** `/admin` or `/admin#view=map`. Grafo: `/admin#view=map&tab=grafo`. Anything else, including no `tab`, is Jerarquía.
- **Nav:** Conocimiento · Mapa
- **Who:** every admin. The `+ New reference fact` button is `knowledge.edit` only (super_admin, knowledge_editor). Hidden for hr_agent and auditor.
- **Heading:** `Conocimiento · Mapa`
- **Description:** `Navigate the corpus by lens, spot coverage gaps, and open a document to inspect, test, or edit its labels.`

### Sections (segmented control, `aria-label` `Sección`)

- `Jerarquía`
- `Grafo`

### Jerarquía

Lens (`aria-label` `Lens`): `Territory`, `Sector`, `Validity`, `Topic`.

View (`aria-label` `View`): `Graph`, `List`.

Action: `+ New reference fact` — opens the create drawer. **super_admin + knowledge_editor.**

Coverage bar title: `Coverage gaps`. Empty: `None detected.` Counts that are non-zero render `{label}: {n}` with hint as `title`:

| Kind | Label | Hint | Colour |
|---|---|---|---|
| unanswerable | `Unanswerable` | `Active document with 0 indexed chunks (e.g. a scanned PDF, or still under review) — it cannot answer.` | gap--danger |
| expired_no_successor | `No active successor` | `Only historical prose remains for this scope — no active version to answer from (coverage hole).` | gap--warning |
| suspected_mistag | `Suspected mistag` | `Tagged as convenio prose but the title/filename says "tabla" — likely a salary table. Human decides (retag in the card).` | gap--warning |
| date_expired_active | `Date-expired (still active)` | `Validity end is in the past but the document is still active — a staleness signal, not a hole. The scope is still answerable.` | gap--neutral |

Empty states: `Loading map…`. Topic lens with nothing tagged: `No topics tagged yet — topic tagging arrives with the AI tier (Sprint 7). You can tag topics by hand from a document card.` Other lenses: `Nothing to show for this lens yet.` Empty branch: `(empty)`. Counts: `item` / `items`.

Fact leaves use badge `dato` (info) plus `verified` (success) or `needs review` (warning). `title` on the fact badge: `Structured reference fact`.

A document leaf opens the document drawer. A fact leaf opens the reference-fact drawer. Same drawers as Documentos and Revisión.

**FilterToolbar:** none.

### Grafo

Not FilterToolbar. Chip row, `aria-label` `Filtros del grafo`.

- Mode (`aria-label` `Modo`): `3D`, `2D`. No WebGL: `3D` disabled, `title` `WebGL no disponible en este navegador`, notice `WebGL no disponible — mostrando 2D.`
- One chip per territory hub (the hub’s own label, not a dictionary string).
- `Ocultar históricos`
- `Ocultar IA sin verificar`
- `Limpiar filtros` — only while a chip is active.

Loading: `Cargando grafo…`, then `Cargando renderizador…`.

Caption (counts are live numbers): `Verde = conocimiento vigente · Ámbar = borrador · Gris = histórico · ` + bold `Fucsia = IA sin verificar` + ` · {n} documentos sin vínculo y {n} datos rechazados no se dibujan.`

Side card (node click), badges from `grafo.stateLabels`:

| State | Label | Colour |
|---|---|---|
| scope | `Ámbito` | accent |
| active | `Vigente` | success |
| verified | `Verificado` | success |
| draft | `Borrador` | warning |
| historical | `Histórico` | faint/neutral |
| unverified_ai | `IA sin verificar` | fuchsia `.ai-pill` |

Type labels: `Convenio`, `Documento`, `Dato de referencia`, `Territorio`, `Sector`, `Tema`.

Folded hubs: `Territorio: …` / `Sector: …` + ` (sin hub propio — muy pocos convenios).` Shared source: `Fuente compartida: «…» — no dibujada como nodo (ver honestidad del grafo).` `Conexiones: `.

Actions: `Abrir documento`, `Abrir dato de referencia`, `Ver cobertura`.

### Drawer — new reference fact

Heading `New reference fact`. **knowledge.edit.**

Fields: `Convenio *` / `Select a convenio…`; `Derived scope:` + `(territory & sector ride the convenio — not editable)`; `Job category (optional)` / `Convenio-wide`; `Topic (optional)` / `No topic`; `Value *` placeholder `periodo de prueba 90/75 días`; `Original text (raw, optional)` placeholder `Paste the verbatim source phrasing (kept in raw_values)…`; `Source document (optional)` / `No source link`; `Source locator (optional)`.

Notices: `Authority:` + `— locked.`; `A reference fact can never outrank a convenio.`; `The fact will land` + `needs review` + `— verify it from its card to make it count.`

Buttons: `Create fact` / `Creating…`, `Cancel`. Error: `A convenio and a value are required.`

Reader: `Reference source`. `Read a non-salary .docx/.xlsx to enter facts by hand. Tagging it` + `reference_source` (code, in the component) + `keeps it off the salary path.` `Upload a new source (.docx / .xlsx)`, `Uploading + reading…`, `Uploaded — select it below to read its content.`, `Open a source`, `Select a reference source…`, `Loading content…`, `(no extractable content)`, `Section`, `use locator` (`title` `Use as source locator`).

Badge on the form: `dato`.

---

## 2. Documentos

- **Route:** `/admin#view=documents`. Deep link `#doc=<uuid>` or `#view=documents&convenio=<id>`.
- **Nav:** Conocimiento · Documentos
- **Who:** every admin. Upload is not ability-gated in the UI. Label edits inside the drawer are `knowledge.edit` (see drawer).
- **Heading:** `Conocimiento · Documentos`
- **Description:** `Sube carpetas de convenios, revisa el etiquetado automático, resuelve conflictos y confirma.`

### FilterToolbar

Primary (always visible, not a filter): `Upload folder`. If a convenio deep link is on: chip `Convenio #{id}` with `×` (`aria-label` `Quitar filtro de convenio`). Upload status: `Ingesting {n} file(s)…` then `Ingested {n}, skipped {n}, failed {n}.` Failure: `Couldn't ingest these files:`.

`Filtros` disclosure (open by default). Active count badge. `Limpiar filtros` once any filter is set.

Filters:

- Status `<select>`: `All statuses`, `Auto-proposed`, `Under review`, `Verified`.
- Checkbox `Conflicts only`.

Total: `{n} document` / `{n} documents`.

### Table

Columns: `Title`, `Territory`, `Sector`, `Convenio`, `Type`, `Validity`, `Retrieval`, `Status`, `Flags`.

Retrieval badges (`statusLabels.retrievalStatus`):

| Value | Label | Class |
|---|---|---|
| active | `Activo` | badge-verified (success) |
| historical | `Histórico` | badge-historical (neutral) |
| draft | `Borrador` | badge-review (warning) |

Tagging badges (`statusLabels.taggingStatus`):

| Value | Label | Class |
|---|---|---|
| verified | `Verificado` | badge-verified |
| under_review | `En revisión` | badge-review |
| auto_proposed | `Auto-propuesto` | badge-historical |

Flag badges: `⚠ Conflict` (badge-conflict), `⏳ Under review` (badge-review, only if no open conflict), `∅ No text` (badge-empty, warning), `⚙ OCR'd ({n})` (badge-ocr, info), `⚑ National` (badge-national, info).

Empty: with filters, `No documents match these filters.` Without, `No documents yet — upload a convenio folder to ingest.` Loading: `Cargando…`.

Pager: `‹ Prev`, `Page {n} of {n}`, `Next ›`.

Row click opens the document drawer.

### Drawer — document detail

Close: `Close`. Loading: `Loading…`.

Badges: `Resolución RR. HH.` (when the doc is an HR ruling), `OCR'd` (info).

Ruling line: `Creada desde la escalación` + `por` + `Ver la tarjeta →`.

Read-only (no `knowledge.edit` — hr_agent and auditor): `Read-only — you don't have the` + code `knowledge.edit` + `ability. You can browse, inspect, and run the sandbox.`

Notices:

- `No extractable text — this is a scan PDF. AI tagging requires a text layer.`
- `No convenio — this document carries no scope (scope is derived via the convenio), so employees won't receive it as an answer.`
- `No extractable text — this looks like a scanned, image-only PDF. Run` + code `documents:ocr` + `(Sprint 7e, ADR-0026) to OCR it, or re-ingest with` + code `documents:ingest`.
- `Suspected salary-table mistag: tagged as convenio prose but named like a table.` Hint if the viewer can edit: `Use "Re-type document" below → Tablas salariales.` Else: `A knowledge editor can retag this.`

Unverified AI block (fuchsia): `Unverified AI tagging —` bold `inert` `until you confirm.` `Step 1:` `review the fuchsia suggestions below; use the edit pickers to accept or correct the convenio, type, and validity.` `Step 2:` `click` **`Confirm tags`** `— that writes the scope and makes the document retrievable.`

Scope heading `Scope`. KV: `Retrieval`, `Authority`, `Language`, `Status`. Derived facet: `derived` (`title` `Derived from the convenio — not editable`). Removed: `removed`.

`Review tasks`. After confirm: `Tags confirmed ✓`. **knowledge.edit:** `Re-suggest with AI` / `Proposing…`. Disabled title: `No extractable text — scan PDF, cannot AI-tag`. Ready title: `Re-run the AI tagging proposal (queued)`.

`Provenance`. Actor prefix `admin #`. AI origin dot is fuchsia.

`Suggested facets` + `(unverified)`, with an `AI` pill. `The AI couldn’t resolve a facet — see the flagged values below.` `These are suggestions only — they have NOT changed the document’s scope.` Edit hint: `Adjust below if needed, then Confirm tags to verify (the human write).` No-edit hint: `A knowledge editor verifies them.` **knowledge.edit:** `Propose vocabulary` / `Cancel`.

`Topics`. Empty: `No topics tagged yet — topic tagging arrives with the AI tier (Sprint 7); a human can tag now.` **knowledge.edit:** remove `aria-label` `Remove {topic}`, placeholder `Add a topic…`, button `Add topic`.

`Chunk health`. Zero chunks: `Zero chunks — this document is not retrievable (unanswerable until re-chunked; re-chunking is not a Knowledge-Center action).` Labels: `Chunks`, `Tokens`, `Pages`, `Embeddings` + `present` / `missing`.

`Lineage`: `supersedes`, `superseded by`.

**knowledge.edit** `Edit labels`. Notice: `Bounded edit (FK pickers into existing vocabulary). Territory & sector are derived from the convenio and not editable. Every save appends append-only human provenance.` Buttons: `Re-scope convenio`, `Re-type document`, placeholder `Select a value…`, `Retag`, `Apply`.

Confirm modals:

- `Re-scope this document?` — `Changing the convenio changes the document’s derived territory + sector — i.e. which employees receive it as an answer. This appends human provenance and cannot rewrite history.`
- `Re-type to a salary table?` — `Marking this as a salary table moves it off the prose answer path onto the structured salary (SQL) path, and removes it from convenio-prose retrieval. This appends human provenance.`

Lifecycle fields: `Retrieval`, `Tagging`, `Valid from`, `Valid to`, button `Save lifecycle`. Scope-affecting fields carry `(scope-affecting)`. Modal `Scope-affecting change`: `Changing the retrieval status or validity window moves the eligibility window — which employees receive this document as an answer. This appends human provenance and cannot rewrite history.` Buttons `Confirm change`, `Cancel`. Error prefix: `Confirm failed: `.

`Original document`: `View original` / `Hide source`. `Can’t embed inline —` `open the file`. `Download / open the original file`.

`Sandbox` tag `read-only · persists nothing`. `Run the answer pipeline against` + `this document` (component) + `only. Same gates as production; no chat, no escalation is saved.` Placeholder `¿cuántos días de vacaciones tengo?`. `Test` / `Running…`. Outcomes: `Respondida`, `Escalada`, `result`. `retrieved`, `top`, page `p.`. `Draft the model produced (not served)`. `Stopped by the grounding gate — ungrounded:`.

`Source pages`: `← Anterior`, `Siguiente →`, `Página {n} de {n}`. `Texto obtenido por OCR`, `calidad`, `Página bilingüe — revisa también la columna en euskera frente a la columna en castellano.` `Cargando imagen…`. Alt `Página`. `(sin imagen)`, `(sin texto extraíble)`.

---

## 3. Revisión

- **Route:** `/admin#view=review`. Tabs: `tagging` (default), `reference-facts`, `groups`, `vocabulary`, `expiry`. Fact deep link: `&fact=<uuid>`. Groups deep link: `&tab=groups&convenio=<id>`.
- **Nav:** Conocimiento · Revisión
- **Who:** every admin can open every tab. Writes split below.
- **Heading:** `Conocimiento · Revisión`
- **Description:** `Las colas de la cola larga: propuestas de etiquetado por IA para verificar, propuestas de vocabulario para aprobar y documentos próximos a vencer para sucesión. La fucsia marca contenido de IA sin verificar.`

Tabs: `AI tagging`, `Reference facts`, `Groups`, `Vocabulary proposals`, `Expiry`.

### AI tagging

Intro: `Documents` + code `under_review` + `— not retrievable until verified. The AI auto-proposes facets on ingest; lowest-confidence first. Open one to review the (fuchsia) AI suggestions and Confirm.`

**FilterToolbar:** no filter children, so no `Filtros` button. Total only: `{n} document` / `documents`.

Columns: `Title`, `Convenio`, `Type`, `Confidence`, `Flags`.

Badges: `AI` (fuchsia pill, row also `.ai-marked`), `⚠ Conflict` (danger), `∅ No text` (warning).

Empty: `Nothing under review — the queue is clear.`

Row opens the document drawer (section 2). `Confirm tags` is **knowledge.edit**.

Pager: `‹ Prev` / `Page {n} of {n}` / `Next ›`.

### Reference facts

Intro: `Reference facts awaiting verification — AI-segmented or manually created —` bold `uncertain-first` `, then lowest-confidence, then (ties only) topic demand (a manual fact carries neither signal, so it falls to the bottom of its tier). Inert until a human verifies, whichever source it came from. Open one to check the source (the quoted line for an AI proposal, fuchsia; the linked document for a manual fact) against the assigned scope.`

**FilterToolbar:** one filter, `<select>` default `All topics`, then topic names from the vocabulary. `Filtros`, `Limpiar filtros` when a topic is chosen. Total: `{n} fact` / `facts`.

Columns: `Id`, `Value`, `Source`, `Scope`, `Group`, `Topic`, `Conf.`, `Flags`, and an unlabeled actions column.

Badges:

| Mark | Class | Colour |
|---|---|---|
| `AI` | ai-pill, row `.ai-marked` | fuchsia |
| `Manual` | badge-manual | neutral |
| `⚠ {field}` | badge-conflict | danger. `{field}` is the uncertainty field name from the API, not a dictionary string. |
| `≈ version` | badge-conflict | danger |
| `{resolution}` | badge-historical | neutral. Resolution text is the stored value. |

Action on an unresolved duplicate: `Resolver versión` — opens the version drawer.

Empty: `No facts awaiting review — the queue is clear.`

#### Drawer — reference fact

Heading `Reference fact`. Loading `Loading…`. Close `aria-label` `Close`.

Badges: `dato` (info). If AI: `AI proposal` (fuchsia). If manual pending: `Manual` (neutral). Status: `verified` (success), `needs review` (warning), `rejected` (warning — same badge-review class).

Read-only (no `knowledge.edit`): `Read-only — you don't have the` + code `knowledge.edit` + `ability.`

AI notice: bold `AI-segmented proposal — unverified.` `Check the scope against the quoted source line below before verifying. The agent only proposes; it never verifies itself.` `Confidence:`.

Version: bold `Possible version/duplicate` `— same scope as an existing fact with a different value ("` … `"). Decide which is true, and since when.` Button `Resolver versión (comparar lado a lado)`.

Resolution lines: bold `Sustituido` `por “…”` `(desde` … `)` `este valor sigue siendo el correcto para su periodo de vigencia; no se ha borrado.` Bold `Sustituye` `a una versión anterior, cuya vigencia se cerró.` Bold `Coexiste` `con el hecho marcado: no son versiones del mismo dato.` Bold `Descartado` `como duplicado incorrecto.`

Bold `Inert until verified` `this fact is not answerable until a human verifies it (once verified, it can be served directly as a live answer).`

`Source line (check the scope)`. `Value`. Disclosure `Original (raw_values)`.

`Scope`. `Group`. `Job category` fallback `— (convenio-wide)`. `Territory`, `Sector`, `Convenio`, `Topic`, `Validity`. `Authority` (`title` `A reference fact can never outrank a convenio (enforced in schema + validation).`). `Source` value `manual` when manual. `Status`. `by` + verifier. `No source document linked`.

**knowledge.edit** buttons: `Verify proposal` or `Verify fact` / `Verifying…`; `Edit` / `Cancel edit`; `Fix then verify`; `Reject` / `Rejecting…`; `Re-segment source` (`title` `Re-run the segmentation agent on the source (idempotent upsert)`).

Edit fields: `Validity start`, `Validity end`, `Source locator` placeholder `p.3 §2 / sheet:smi26`. `Authority is locked to` + `reference_data` + `— it cannot be raised.` `Saving…`.

Scope-change modal `aria-label` `Confirm scope change`, title `Scope change`: `This changes the validity/scope of the fact (which employees it would answer). Confirm to apply.` Buttons `Confirm change`, `Cancel`.

`Provenance`, `admin #`.

#### Drawer — resolver versión

Heading `Resolver versión`. Read-only: `Solo lectura — necesitas` + code `knowledge.edit` + `para resolver una versión.`

Already resolved: `Este par ya está resuelto. El enlace se conserva como linaje de versiones; nada se ha borrado.`

Notice: `Dos hechos del mismo ámbito y tema con valores distintos. Decide si uno` bold `sustituye` `al otro (una versión posterior), si` bold `coexisten` `(no son versiones) o si uno es` bold `incorrecto` `. Sustituir cierra la vigencia del anterior y` bold `no borra nada` `: una pregunta con fecha antigua seguirá obteniendo el valor que era cierto entonces.`

`Resolver`. `¿Cuál es la versión posterior? Se cerrará la vigencia de la otra el` + date + `· desde`. `Nota (queda en la provenencia)`.

**knowledge.edit** buttons: `Sustituir (cerrar vigencia de la anterior)`, `Coexisten (no son versiones)`, `Descartar este hecho`.

Confirm modal `aria-label` `Confirmar resolución`, button label `Confirmar` / `Aplicando…`:

- Supersede: `Se cerrará la vigencia del hecho anterior el` … `. Ambos hechos se conservan verificados: el anterior seguirá respondiendo a preguntas fechadas en su periodo.` Success string in `es.ts` is English: `Superseded — the previous fact's validity period has closed. Neither has been deleted.`
- Coexist: `Los dos hechos se marcarán como coexistentes. Ambos siguen siendo respondibles y la marca de versión deja de pedir atención.` Success: `Marked as coexisting — both remain answerable.`
- Reject: `Este hecho pasará a rechazado y dejará de ser respondible. No se borra: queda con su provenencia para auditoría.` Success: `Discarded as a duplicate — it is no longer answerable.`

Side-by-side badges: `más reciente`, `verificado` (success), `sin verificar` (warning), `rechazado` (warning). Fields: `Grupo (tal cual)`, `Categoría`, `Tema`, `Vigencia desde`, `Vigencia hasta`, `Línea de origen`. `por` before the resolver name.

### Groups

Intro: `Estructura de grupos por convenio. Los nodos propuestos por la IA son inertes: no los usa nadie hasta que se aprueban.`

**FilterToolbar:** none. The convenio list is the picker.

List columns: `Convenio`, `Pendientes`, `Aprobados`, `Datos con grupo`.

Empty until a row is chosen: `Elige un convenio.` Loading: `Cargando estructura…`.

**knowledge.edit:** `Proponer estructura con IA` / `Encolando…`. Without edit rights the row actions render `—`.

No structure: `Este convenio no tiene ninguna estructura de grupos todavía. Sin ella, un dato con grupo no puede vincularse y la pregunta se deriva a una persona.`

`Áreas sin grupo padre visible`.

`Datos que no se vinculan solos`. Intro: `Estas etiquetas no se pueden resolver sin criterio humano. Se listan aquí en lugar de descartarse: mientras no se vinculen, esas preguntas se derivan. Si tú sí sabes a qué nodo pertenecen, elígelo — queda registrado como decisión tuya, no como lectura del analizador.`

Columns: `Id`, `Etiqueta`, `Valor`, `Fuente`, `Motivo`, `Vincular a`. Badge `vinculado a mano` (neutral/muted styling on the chip the component uses). Placeholders: `ámbito convenio — no se acota`, `aprueba primero un nodo`, `Elegir nodo…`. Button `Vincular` (**knowledge.edit**).

Node badges (`statusLabels.groupNodeStatus`): `Por revisar` with class `badge ai` (fuchsia) while pending; `Aprobado` class `badge ok` (success); `Rechazado` class `badge muted` (neutral).

`Sin cita del convenio.` `Categorías propuestas`. `indicio:`. `Datos que apuntan a este nodo`. `vinculado` / `sin vincular`. Links `desvincular`, `vincular`. `title` `La clave que comparará el emparejador`. `{n} dato(s) vinculados`.

**knowledge.edit** buttons: `Revisar y aprobar…`, `Editar`, `Rechazar`. Edit hint: `Escribe la etiqueta tal como la imprime el convenio; la clave se recalcula sola.`

Approve modal heading `Qué vinculará esta aprobación`. Empty: `Ningún dato apunta a este nodo. Se puede aprobar igualmente.` Columns: `Etiqueta`, `Valor`, `Fuente`, `Vigencia`, `Estado`. `Este dato abarca también otro(s) nodo(s):` … `Vincúlalo allí también o su ámbito quedará incompleto.` Open-ended date: `abierta`. Badge `ya vinculado`. Heading `No se resuelven solos`. Button `Aprobar nodo y vincular {n} dato(s)`.

### Vocabulary proposals

Intro: `Proposed vocabulary (variant→alias is the default; create-new is deliberate). Approving writes into the controlled vocabulary — gated by` + code `vocabulary.approve` + `(super_admin). The AI proposes only.`

**FilterToolbar:** total only, no `Filtros`. `{n} proposal` / `proposals`.

Empty: `No open vocabulary proposals.`

Each card: `AI` pill (fuchsia) and `.ai-marked` when `proposed_by_source` is `ai_agent`. Facet code, proposed value. `· looks like #{id}` plus similarity percent. `proposed by` … `· from` “document title”.

**vocabulary.approve (super_admin only)** sees `Approve as alias`, `Approve as new value`, `Fold into an existing value`, `Create a new` + `(deliberate)`, `Level`, and `Reject`. Without it: `Awaiting a super_admin to approve.`

Propose-only (knowledge.edit, not super_admin), from the document card’s `Propose vocabulary` form: `Propose (a super_admin approves)`. Convenios: `Convenios are created by the registry import, not this flow. Fold the spelling into an existing convenio instead.` Topics: `Topics have no alias-fold mechanism — spelling variants are resolved in code via TopicLexicon, not here.`

Result lines (English in `es.ts`): `Folded “…” into ….`, `Created new “…” .`, `Proposed “…” — a super_admin will approve it.`, `Folded into the existing value.`, `Created the new value.`

Form chrome: `Propose vocabulary for`, `Fold into` … `as an alias`, `(similarity` `%)`, `(nothing close enough was found)`.

### Expiry

Intro: `Active prose within 90 days of expiry (or already past). Confirm a successor (same convenio only) to write the lineage —` `— the old document is` bold `never auto-retired` `.`

**FilterToolbar:** total only. `{n} task` / `tasks`.

Empty: `Nothing expiring — the queue is clear. (Run` + code `documents:expiry` + `to refresh.)`

Badge: `⚠ Past` (badge-conflict) when past. `no convenio`. `valid`.

AI suggestion block (fuchsia `AI` pill): `Sin sugerencia de sucesión:` / `Reintentar`. `Sugerencia de IA rechazada — sin efecto sobre el documento.`

Relationship labels: `Sucesor propuesto`, `Posible conflicto (no sucesión)`, `Documentos que coexisten (no sucesión)`, `Sin relación afirmada`. Suffix `— sin verificar, no se ha escrito nada.`

`Candidato:` `· solapamiento` `este documento` / `candidato` `(vigencia estrictamente posterior)`. `Este documento`. `Candidato ·`.

**knowledge.edit** buttons: `Confirmar esta sucesión`, `Rechazar sugerencia`. Hint: `La IA no propone una sucesión aquí; si crees que la hay, elígela abajo a mano.`

Result lines: `Enlazado el sucesor y se retiró el documento antiguo.` `Enlazado el sucesor (el documento antiguo sigue activo).` `Descartado.` `Escalado para adjudicación.` `Sugerencia de IA rechazada — no se ha escrito ninguna relación; la tarea sigue abierta.` `Comparación en cola — vuelve a cargar en unos segundos.`

No convenio: `No convenio — succession is scope-based, so no same-convenio successor can be linked. Dismiss or escalate.`

Manual: `Pick the successor (same convenio)…`. Checkbox `Also retire this one (historical)`. Buttons `Confirm succession + retire` or `Confirm succession`, `Dismiss (renewed in place / no action)`, `Escalate`.

---

## 4. Escalaciones

- **Route:** `/admin#view=escalations`. A map/document “ver la tarjeta” sets the open card in memory; it does not write a hash.
- **Nav:** Atención · Escalaciones
- **Who:** every admin can read the board and open a card. Assign, drag between columns, reply, resolve, publish: `escalation.work` (super_admin, hr_agent). Auditor and knowledge_editor are read-only.
- **Heading:** `Atención · Escalaciones`
- **Description:** `Gestiona preguntas escaladas: asigna, responde al empleado y resuelve — opcionalmente publicando la respuesta como conocimiento reutilizable.`

### FilterToolbar

Primary, only when the viewer lacks `escalation.work`: `Solo lectura — no tienes el permiso` + code `escalation.work` + `.`

Filters:

- Motivo (`aria-label` `Filtrar por motivo`): `Todos los motivos`, then `Baja confianza`, `Fuera de ámbito`, `Tema sensible`, `Petición explícita`, `Hueco salarial`, `Salario no disponible`, `Hueco en datos de referencia`, `Convenio vencido / sin texto vigente`, `Conflicto`, `Muestra de calidad incorrecta`.
- Checkbox `Solo asignadas a mí`.

`Filtros` / `Limpiar filtros`. No total slot.

### Board

Columns: `Nuevas`, `Asignadas`, `En curso`, `Resueltas`, `Cerradas`. Drag only if `escalation.work`, and only along the legal transition the client already knows.

Card: reason badge (badge-review, warning) using the reason label above. Optional topic chip. Question, or `(sin texto)`. Assignee chip, or `Sin asignar`.

Click opens the drawer.

### Drawer — escalation card

`Escalación ·` + short id. Close `Cerrar`. Loading `Cargando…`. Error `Error`.

Status labels: `Nueva`, `Asignada`, `En curso`, `Resuelta`, `Cerrada`.

Read-only notice (no `escalation.work`): `Solo lectura — puedes ver la conversación y el razonamiento, pero no asignar, responder ni resolver.`

`(sin pregunta de origen)`. `Escalada el`. KV: `Estado`, `Empleado`, `Convenio`, `Asignada a` / `Sin asignar`, `Tema`.

`Triaje`. **escalation.work:** `Asignarme`, `Quitar asignación`, status `<select>` `aria-label` `Mover estado`, placeholder `Mover a…`.

`Conversación`. If the viewer cannot see it: `No tienes permiso para ver el contenido de la conversación. Se requiere` + the ability names the API names + `o`. Intro: `La conversación completa de la sesión de esta tarjeta (no es un histórico general). Una misma sesión puede generar varias tarjetas, que comparten esta conversación; la pregunta que originó` + `esta` + `tarjeta se muestra arriba.`

Bubbles: human reply badge `Respuesta de` + name + `(persona)` (badge-agent). Escalated turn: `Escalado a Recursos Humanos` (badge-review). Fallback author `Recursos Humanos`.

`Explicación`. Empty: `Sin explicación estructurada todavía (tarjeta anterior a esta función, o pendiente de re-procesar).` AI summary badge `Resumen IA` (fuchsia), `title` `Redactado por IA a partir de los hechos estructurados de abajo — no añade ningún dato nuevo`. Sub-outcome labels are `statusLabels.subOutcome` (49 Spanish strings, e.g. `Tema sensible (base)`, `Sin contenido encontrado`, `Dato vs. convenio en conflicto`). Link `Corregir` (hash into Revisión / Documentos / Mapa).

`Empleado`. Restricted: `No tienes permiso para ver el contexto del empleado. Se requiere` + ability. Rows: `Nombre`, `Email`, `Territorio`, `Categoría / grupo`, `Antigüedad` + `año(s) (desde` or `no registrada`. `Acción sugerida:`.

`Responder a la persona`. `Se enviará al chat del empleado como respuesta humana, claramente atribuida a Recursos Humanos.` Placeholder `Escribe la respuesta para el empleado…`. **escalation.work:** `Enviar respuesta` / `Enviando…`.

Citations in the thread use the admin citation badges (section 13). The employee chat does not show them.

`Resolver / Guardar como conocimiento`. Placeholder `Redacta la resolución para esta consulta…`. Checkbox `Publicar como conocimiento (resolución interna de RR. HH.)`. `Tema… (recomendado)`. Warning: `Sin tema, la verja de conflicto bloquea por ámbito completo (sobreprotege). Asigna un tema para afinarla.`

Publish results: `Publicada —` `{n} fragmento(s) indexados (texto íntegro verificado).` or `Publicada, pero el texto indexado NO coincide exactamente con el escrito (revisar — posible mangling).`

Checkboxes when the compare step asks: `He revisado el convenio vigente por mi cuenta y confirmo que esta resolución no lo contradice.` `He leído los pasajes y confirmo que esta resolución no se solapa con el convenio vigente.`

**escalation.work:** `Publicar con esta confirmación` / `Publicando…`, `Publicar como conocimiento`, `Marcar como resuelta`.

Scope modal `aria-label` `Confirmar ámbito`, title `¿Publicar e heredar el ámbito del empleado?`: `La resolución se publicará como internal_hr_ruling heredando el convenio del empleado (territorio y sector incluidos) y pasará a responder a otras personas de ese ámbito. No puede prevalecer sobre un convenio oficial vigente para el mismo ámbito y tema (se bloqueará si lo hace).` Buttons `Cancelar`, `Confirmar y publicar`.

`Actividad` — event log, no extra dictionary labels beyond the event payload.

---

## 5. Historial

- **Route:** `/admin#view=history`
- **Nav:** Atención · Historial
- **Who:** `history.view_all` only — **super_admin and auditor.** Hidden from hr_agent and knowledge_editor. Read-only for both roles that see it. Opening a conversation writes `conversation_access_log`, including for super_admin.
- **Heading:** `Atención · Histórico de conversaciones`
- **Description:** `Consulta y busca las conversaciones de toda la organización (solo lectura). Cada apertura queda registrada en el registro de accesos.`

### FilterToolbar

Primary: search placeholder `Buscar en el contenido de las conversaciones…` (`aria-label` `Buscar en conversaciones`), button `Buscar` / `Buscando…` (disabled under 2 characters). While results are showing: `Ver listado`.

Filters:

- Convenio (`aria-label` `Convenio`): `Todos los convenios`, then `{numero} — {name}`.
- Territorio (`aria-label` `Territorio`): `Todos los territorios`.
- Resultado (`aria-label` `Resultado`): `Respondidas y escaladas`, `Solo respondidas`, `Solo escaladas`.
- Motivo (`aria-label` `Motivo de escalación`): same reason list as Escalaciones, first option `Todos los motivos`.
- `Desde` (date), `Hasta` (date).

`Filtros` / `Limpiar filtros`.

### Table

Columns: `Empleado`, `Convenio`, `Mensajes`, `Última actividad`, `Resultado`.

Result badges: `Escalada` (badge-review, warning), `Respondida` (badge-verified, success).

Empty: `No hay conversaciones que coincidan.`

Search mode intro: `{n} coincidencia(s) para` + query + `Se muestran fragmentos breves; abre una conversación para leerla (cada apertura queda registrada).` Columns: `Empleado`, `Rol`, `Fragmento`, `Actividad`. Empty: `Sin coincidencias.`

### Drawer

`aria-label` `Conversación`. Default heading `Conversación`. Notice: `Solo lectura. Esta apertura ha quedado registrada en el registro de accesos.` `Inicio`, `Última actividad`.

Employee block reuses the escalation card rows (`Nombre`, `Email`, `Territorio`, `Categoría / grupo`, `Antigüedad`). Seniority uses `año` / `años` and `(desde`.

Bubbles match the card: `Respuesta de {nombre} (persona)`, `Escalado a Recursos Humanos`. Assistant turns include `Fuentes` and a `<details>` summary `Cómo llegué a esto` (trace steps in section 13).

---

## 6. Analítica

- **Route:** `/admin#view=analytics`
- **Nav:** Análisis · Analítica
- **Who:** `analytics.view` — super_admin, hr_agent, auditor. Hidden from knowledge_editor. Read-only for everyone who sees it.
- **Heading:** `Análisis · Analítica`
- **Description:** `Deflection, escalaciones por corrección y agrupación de preguntas — todo reproducible desde los comandos` + code `stats:*` + `/` + code `questions:cluster` + `.`

No FilterToolbar. No tabs. No write actions.

Loading: `Loading…`. `Periodo`. Note: `Deflection rate excluye `needs_category` del denominador (§2.1, resuelto).` (the backticks are in the dictionary string).

KPI labels: `Tasa de resolución (deflection)`, `Respondidas`, `Escaladas`, `Necesitan categoría` / `excluido del denominador`, `Respuestas humanas (RR. HH.)`, `Satisfacción (👍/👍+👎)` / `(§7, opcional)`.

Charts: `Reparto por vía (path_split)`, `Reparto por autoridad (authority_split)`. Empty chart: `Sin datos.` Chart `aria-label` `trend chart`.

`Escalaciones por corrección (§3)`. `{n} tarjeta(s) anteriores sin explicación estructurada aún.` `{n} resueltas en el periodo · tasa de conversión a conocimiento`.

Columns: `Motivo`, `Sub-resultado`, `Acción de corrección`, `Tarjetas`, `Resueltas`. Sub-resultado uses `statusLabels.subOutcome`. Empty: `Sin escalaciones en el periodo.` Fix link text `Corregir` when the row has a `fix_link`.

`Agrupación de preguntas (§4) — ejecución` + run id. `Etiqueta = medoide del cluster (nunca un resumen de IA). Umbral τ=`. Columns: `Medoide`, `Miembros`, `Similitud (mín–máx)`, `Tasa de escalación`, `Motivo top`. Singleton: `(único)`. Empty: `Sin clusters (ejecuta` + code `questions:cluster` + `).`

`Preguntas por tema (top 10)`.

`Ranking "sin responder" (escalation_rate × volumen × personas afectadas)`. `volumen`, `tasa`, `peso por plantilla`, `score`. Empty: `Sin datos.`

---

## 7. Cobertura

- **Route:** `/admin#view=coverage`
- **Nav:** Análisis · Cobertura
- **Who:** `analytics.view` OR `knowledge.edit` — all four roles. Read-only. Export is a download, not a knowledge write.
- **Heading:** `Análisis · Cobertura`
- **Description:** `La rejilla convenio × (prosa, salario, datos, resoluciones) — la misma consulta que` + code `corpus:coverage` + `.`

No FilterToolbar.

View group `aria-label` `View`: `Graph`, `List`. Buttons: `↓ Exportar (.md)` / `Exportando…`, `↻ Actualizar`. `a fecha de` + snapshot time. Loading: `Loading…`.

The grid is the same Hierarchy component as Mapa (section 1), fixed to the coverage lens. Leaf badges and gap badges use `gapMeta` (section 1 table, plus the cell reasons below). `Ver` opens the linked document, fact, or Documentos pre-filtered by convenio.

Cell reason labels (on the leaf, colour from `gapMeta.ts`):

| Code | Label | Colour |
|---|---|---|
| SCAN_NO_TEXT | `Scan, no text` | danger |
| UNDER_REVIEW_SCOPE | `Scope under review` | warning |
| EXPIRED_NO_SUCCESSOR | `Expired, no successor` | warning |
| SALARY_PDF_NOT_IMPORTED | `Salary PDF not imported` | danger |
| FACT_NEEDS_REVIEW | `Fact needs review` | warning |
| NO_SALARY_SOURCE | `No salary source` | danger |
| coverage_gap_unclassified | `Unclassified gap` | neutral |
| unscoped | `Unscoped` | neutral |

`Convenios con brecha total`. `Ni prosa, ni salario, ni datos, ni resoluciones — ordenados por plantilla afectada.` `{n} persona` / `personas`. Link `Ver`. Empty: `Ningún convenio con brecha total.`

`Ámbitos sin convenio de registro`. Columns: `Territorio`, `Sector`, `Plantilla`, `Motivo`.

`Brechas cerradas — tendencia (últimos {n} instantáneas)`. Empty chart: `Sin datos.`

---

## 8. Calidad

- **Route:** `/admin#view=quality`
- **Nav:** Análisis · Calidad
- **Who:** nav and reads, every admin. `Guardar veredicto` requires `escalation.work` (super_admin, hr_agent). Auditor and knowledge_editor see the queue and the drawer, and the read-only notice.
- **Heading:** `Análisis · Calidad`
- **Description:** `Muestra mensual estratificada de turnos respondidos (` + code `quality:sample` + `). Lectura abierta a cualquier admin; marcar una muestra requiere` + code `escalation.work` + `.`

No FilterToolbar. Filters sit in a `.reassign` row, always visible:

- Text input placeholder `Mes (AAAA-MM)…`
- Checkbox `Solo sin revisar`

Intro: `Muestra mensual estratificada de turnos respondidos (§6.2) — cada fila es un turno REAL que un empleado recibió, no un caso sintético. Marcar` bold `Incorrecta` `abre una tarjeta de corrección (` + code `quality_sample_wrong` + `), igual que cualquier otra escalación.` Total `{n} muestra` / `muestras`.

Monthly summary, or `Sin veredictos registrados todavía.` Line: `{month} — {n} correcta · {n} parcialmente · {n} incorrecta · {n}% de precisión`. Chart labels: `Correcta`, `Parcialmente`, `Incorrecta`.

Columns: `Mes`, `Pregunta`, `Estrato`, `Territorio`, `Veredicto`, `Revisor`, `Tarjeta`. Row action `Ver respuesta`. Stratum fallback `prose`. Territory fallback `nacional`.

Verdict badges:

| Verdict | Label | Class |
|---|---|---|
| (none) | `Sin revisar` | badge-review (warning) |
| correct | `Correcta` | badge-verified (success) |
| partially | `Parcialmente correcta` | badge-review (warning) |
| wrong | `Incorrecta` | badge-conflict (danger) |

Empty: `No hay muestras para este filtro. (Ejecuta` + code `php artisan quality:sample` + `para generar la del mes.)` Loading: `Loading…`.

Pager as elsewhere.

### Drawer

Heading `Muestra de calidad`. `Semilla`. `Tarjeta de corrección` when one exists. `Sin conversación asociada.` Otherwise the same bubbles, `Fuentes`, and `Cómo llegué a esto` as Historial.

`Revisión`. Read-only: `Solo lectura — se requiere` + code `escalation.work` + `para registrar un veredicto.` Barred reviewer: `No puedes revisar esta muestra: estás asignado a una tarjeta de escalación de la misma sesión (§6.3).`

Verdict choices: `Correcta`, `Parcialmente correcta`, `Incorrecta`. Failure `<select>` placeholder `Motivo de fallo…`: `Ámbito incorrecto`, `Cifra incorrecta`, `Documento obsoleto`, `Poco claro`, `Otro`. Placeholder `Nota (opcional)…`. **escalation.work:** `Guardar veredicto` / `Guardando…`. Error: `Selecciona un motivo de fallo.` Already reviewed: `Ya revisada por {name} el {date} — guardar de nuevo sobrescribe el veredicto.`

---

## 9. Directorio

- **Route:** `/admin#view=directory`. Deep link `&emp=<uuid>`.
- **Nav:** Personas · Directorio
- **Who:** `directory.manage` — **super_admin and hr_agent.** Hidden from auditor and knowledge_editor. Both roles that see it can create, edit, import, and mark reviewed.
- **Heading:** `Personas · Directorio`
- **Description:** `Gestiona el alta y los datos de las personas (convenio, territorio, categoría). Cada cambio queda auditado; importa en bloque por CSV.`

**Not FilterToolbar.** Always-visible `.docs-toolbar`:

- Search placeholder `Buscar por nombre o correo…` (`aria-label` `Buscar empleados`). Submit is the form; there is no separate search button.
- Convenio (`aria-label` `Filtrar por convenio`): `Todos los convenios`.
- Estado (`aria-label` `Filtrar por estado`): `Activos e inactivos`, `Activos`, `Inactivos`.
- `Nuevo empleado`
- `Importar CSV` / `Ocultar importación`

### Table

Columns: `Nombre`, `Correo`, `Convenio`, `Territorio`, `Categoría`, `Grupo`, `Estado`, `Revisión`.

Group empty: `sin grupo`. Status: `Activo` (badge-verified), `Inactivo` (badge-historical). Review: `Sin revisar` (badge-review) until attested. Dash `—`.

Empty: `No hay empleados que coincidan.` Loading: `Cargando…`.

### CSV panel

Heading `Importar empleados (CSV)`. `Columnas:` + required column names in `<code>` (`email`, and the rest of the schema as code, not prose) + `(obligatorias); opcionales`. `Primero se valida (sin escribir nada); las filas con error se informan, no se descartan en silencio.`

Group help: `acepta el grupo tal y como lo escribe el convenio (` … `) o su código (` … `);` `y` `son el mismo grupo. Para un área dentro de un grupo, usa` … `. Solo se admiten grupos ya aprobados de ese convenio: un valor que no exista, o que sea ambiguo (p. ej.` … `cuando existe en dos grupos), da error en su fila — nunca se elige uno por ti ni se crea un grupo nuevo. En blanco, la persona queda sin grupo, y las preguntas que dependan del grupo se derivan a RRHH.`

Buttons: `Validar (simulación)` / `Validando…`, `Importar {n} fila(s) válida(s)` / `Importando…`.

Results: `Importadas:` `creadas,` `actualizadas` `con error (no aplicadas).` `Simulación:` `{n} fila(s) ·` `válidas ·` `con error.`

Columns: `Fila`, `Email`, `Acción`, `Estado`, `Detalle`. `OK`, `Error`.

### Drawer

`aria-label` `Empleado`. Titles: `Nuevo empleado` or `Empleado`. `Cerrar`. Loading `Cargando…`.

Fields: `Nombre completo`. `Correo (clave de acceso)`. On edit, `Cambiar el correo cambia cómo inicia sesión esta persona. Se pedirá confirmación explícita.` `Convenio` / `Selecciona…`. `Categoría profesional` / `Elige primero un convenio` / `Sin categoría`. `Grupo del convenio` / `Este convenio no tiene grupos aprobados` / `Sin grupo`. Suggested: `Sugerido a partir de la categoría — confírmalo. Nada se guarda hasta que envíes el formulario.` Not suggested: `Sin grupo, las respuestas que dependan del grupo se derivarán a una persona de RRHH en lugar de arriesgar un dato incorrecto.` `Territorio`. `Tipo de jornada`: `Completa`, `Parcial`. `Centro de trabajo`. `ID externo`. `Estado`. Hint `Inactivo (no podrá iniciar sesión ni chatear)`.

Buttons: `Crear empleado` / `Guardar cambios` / `Guardando…`.

`Revisión del perfil`. `Nunca revisado.` or `Revisado por última vez el` + date. `Editar no cuenta como revisar: es una atestación explícita.` Button `Marcar como revisado`.

`Historial de cambios`. Empty: `Sin cambios registrados.` Created row: `creado`.

Email modal `aria-label` `Confirmar cambio de correo`, title `¿Cambiar el correo de acceso?`: `El correo es la clave de inicio de sesión. Vas a cambiarlo de` … `a` … `La persona iniciará sesión con el nuevo correo. El cambio queda registrado.` Buttons `Cancelar`, `Confirmar cambio`.

---

## 10. Administradores

- **Route:** `/admin#view=admins`
- **Nav:** Personas · Administradores
- **Who:** **super_admin only** (`admin.manage`). Hidden from hr_agent, auditor, and knowledge_editor.
- **Heading:** `Personas · Administradores y roles`
- **Description:** `Crea administradores, asigna los cuatro roles y desactiva cuentas (la desactivación retira el acceso de inmediato).`

No FilterToolbar. No filters.

Button `Nuevo administrador` opens the create block (same heading).

Create fields: `Nombre completo`, `Correo`, `Roles` as checkboxes. Button `Crear administrador` / `Creando…`.

Role checkbox labels and `title` hints:

| Role | Label | Hint |
|---|---|---|
| super_admin | `Super admin` | `Acceso total · gestiona admins · ve todo el histórico` |
| hr_agent | `Agente de RR. HH.` | `Trabaja escalaciones · gestiona el directorio` |
| knowledge_editor | `Editor de conocimiento` | `Edita conocimiento · sin acceso a conversaciones` |
| auditor | `Auditor` | `Ve y busca todo el histórico (solo lectura)` |

Table columns: `Nombre`, `Correo`, `Roles`, `Estado`, and an unlabeled actions column.

Role chips use the labels above. No role: `sin rol`. Status: `Activo` (badge-verified), `Inactivo` (badge-historical).

Row actions: button `Roles` (the column-header string, opens checkboxes), then `Guardar` and `Cancelar`. Status: `Desactivar` or `Reactivar`.

Empty: `No hay administradores.`

---

## 11. Guardrails

- **Route:** `/admin#view=guardrails`
- **Nav:** Gobierno · Guardrails (nav label is `Guardrails`; the page heading is `Guardarraíles`)
- **Who:** every admin can read. Writes (`Guardar umbrales`, `Añadir`, `Desactivar`, `Guardar mensaje`, `Guardar tono`, and the convert checkboxes) require `guardrails.manage` — **super_admin only.** The flag comes from the payload `can_manage`, not from a hidden nav item. Auditor, hr_agent, and knowledge_editor get `Tu rol es de solo lectura.`
- **Heading:** `Gobierno · Guardarraíles`
- **Description:** `Ajusta la capa configurable sobre la base de seguridad fija. Solo puede endurecer, nunca debilitar: el servidor aplica siempre el valor más estricto y rechaza cualquier valor por debajo del mínimo. Escritura solo para super_admin; auditor en solo lectura.`

No FilterToolbar.

Intro: `Esta configuración solo puede` bold `endurecer` `el comportamiento base, nunca debilitarlo. El sistema aplica siempre el valor más estricto entre el mínimo de seguridad (fijo en el código) y tu ajuste; un valor por debajo del mínimo se` bold `rechaza` `(no se recorta). Los patrones base de seguridad (acoso, salud mental, despido, legal/médico, otras personas) no son editables.`

`Umbrales`. `Cada umbral muestra su mínimo de seguridad fijo. Solo puedes subirlo. El de confianza (Check C) es un` bold `desempate` `, no una puerta principal — las puertas reales son la recuperación (A) y las citas (B).`

Fields:

- `Umbral de recuperación (Check A)` — `Puntuación mínima del mejor fragmento para intentar responder. Subirlo escala más preguntas dudosas. Es una verdadera puerta.`
- `Umbral de confianza (Check C — desempate)` — `Señal secundaria, NO una puerta principal. Las puertas reales son A (recuperación) y B (citas). Subirlo afina el desempate.`
- `Umbral del enrutador (secundario)` — `Confianza mínima del enrutador. Subirlo envía más casos intermedios al camino seguro de prosa.`

Placeholder `mínimo {n} (sin ajuste)`. Help `· Mínimo fijo:` `· Efectivo ahora:` `(usando el mínimo)`. Button `Guardar umbrales` / `Guardando…`.

Errors: `No se pudo cargar la configuración.` `No se pudo guardar.` `No se pudo añadir.` `No se pudo desactivar.` `: introduce un número.` `no puede bajar de` `(mínimo de seguridad).` `no puede superar 1.`

`Temas bloqueados y fuera de ámbito`. `Lista` bold `aditiva` `sobre la base fija: cada entrada añade una escalación, nunca quita una. Se compara como texto literal (sin acentos, por palabra completa) — no como expresión regular. Una pregunta bloqueada escala` bold `antes` `de llegar al proveedor.`

Empty: `Sin entradas todavía.` Badges: `Fuera de ámbito`, `Tema sensible`. **super_admin:** `Desactivar`. Disabled rows: `desactivado`. Placeholder `palabra o frase`. Button `Añadir`.

`Mensaje de «fuera de ámbito»`. `Texto que se muestra al escalar por estar fuera de ámbito. Solo afecta al texto; no cambia ninguna decisión.` `Guardar mensaje`.

`Tono y estilo`. `Solo` bold `estilo y formato` `(p. ej. «trato de usted, respuestas breves»). El tono` bold `no puede` `saltarse la fundamentación ni las citas: las verificaciones son independientes y posteriores. Una instrucción que intente desbloquear una puerta se rechaza. Máximo` `{n}` `caracteres.` `Guardar tono`.

`Conversión a conocimiento por motivo`. `Qué motivos de escalación pueden convertirse en una regla publicada. Solo puedes` bold `restringir` `. «Tema sensible» nunca es convertible (bloqueado).` Reason labels: `Baja confianza`, `Hueco en tablas salariales`, `Fuera de ámbito`, `Petición explícita`, `Tema sensible`.

`Historial de cambios`. `Cada cambio queda registrado (quién, cuándo, de qué a qué). Solo lectura.` Empty: `Sin cambios todavía.` Columns: `Campo`, `Antes`, `Después`, `Quién`, `Cuándo`.

---

## 12. Ajustes

- **Route:** `/admin#view=settings`
- **Nav:** Gobierno · Ajustes
- **Who:** nav, every admin. The screen’s API is **super_admin only** (status, save, delete). Other roles see the heading and then the 403 `Only a super_admin can manage the answer model.`
- **Heading:** `Gobierno · Answer model`
- **Description:** `Configure the external answer-model provider key (ADR-0015).`

No FilterToolbar.

Card heading `Modelo de respuesta`. `La clave del proveedor se guarda cifrada, se muestra enmascarada y se puede rotar, pero nunca se vuelve a mostrar. El navegador nunca ve la clave ni llama al proveedor.`

`Estado`. Badges: `Configurado ✓` (success), `Sin configurar` (warning/historical treatment on the status badge the page uses for the unconfigured state — class follows the configured flag in `AnswerModelPage`). `Proveedor`. `Clave` (masked). `Nueva clave (rotar)` when configured. `Clave del proveedor` on the empty form.

**super_admin** buttons: `Guardar clave` / `Rotar clave` / `Guardando…`, `Eliminar clave`.

Errors: `No se pudo cargar el estado.` `No se pudo guardar la clave.` `No se pudo eliminar la clave.` Loading: `Cargando…`.

---

## 13. Chat del empleado

- **Route:** `/app` (account type employee, not an admin view).
- **Who:** the signed-in employee. Admins do not get this shell. No ability matrix; there is one employee surface.

Header: wordmark `alt` `HR Platform`, the employee email, theme toggle (`Dark` in light theme), locale toggle (`English` while the UI is Spanish), `Log out`. Mobile: `Abrir menú` / `Cerrar menú`.

### Welcome (no messages yet)

`Pregúntame sobre tu convenio: jornada, vacaciones, permisos, festivos… Te respondo según tu ámbito, citando las fuentes.`

`aria-label` `Preguntas frecuentes`. The five prompts are not in `es.ts`; they are `SUGGESTED_QUESTIONS`:

- `¿Cuántos días de vacaciones me corresponden al año?`
- `¿Cuántos días de permiso tengo por matrimonio?`
- `¿Cuánto dura el periodo de prueba en mi convenio?`
- `¿Cuál es mi jornada anual?`
- `Quiero hablar con una persona de Recursos Humanos`

If the convenio needs a category before an answer, the pick block: `aria-label` `Elige tu categoría profesional`, option text `{name}` plus ` (grupo {code})` when a group exists. After a choice: `Categoría seleccionada.` Failure: `No se pudo enviar la selección. Inténtalo de nuevo.` Chip once chosen: `Mi categoría: `.

### Composer

Placeholder `Escribe tu pregunta sobre convenio, jornada, vacaciones…`. `Enviar` / `Enviando…`. While waiting: `Pensando…`. Send failure: `No se pudo enviar la pregunta. Inténtalo de nuevo.`

### Answer

The employee sees the answer prose and one source line: `Basado en: ` … `.` Sprint 10a (Correction-01) removed the `Fuentes` block from this view. The employee endpoint does not send citation excerpts or the trace, so there is no `Fuentes` list and no `Cómo llegué a esto` here.

Feedback group `aria-label` `¿Te ha resultado útil esta respuesta?` Buttons `aria-label` `Respuesta útil` and `Respuesta no útil`. After a vote: `Gracias por tu valoración.`

### Fuentes and the trace (admin only)

`Fuentes` and the trace are admin surfaces only: Historial (§5), the escalation card (§4), and Calidad (§8).

`Fuentes`. Citation badges:

| Badge | Class | Colour |
|---|---|---|
| `Tabla salarial` | badge-verified | success |
| `Dato de referencia` | badge-verified | success |
| `Ley nacional` | badge-national | info |
| `Convenio` | badge-verified | success |
| `Resolución RR. HH.` | badge-review | warning |
| `Fuente` | badge-historical | neutral |

Page prefix `p.`. Fallback title `Documento`.

Trace `<details>` summary: `Cómo llegué a esto`. Admin only — not on the employee chat. Step labels, in pipeline order when present: `Ámbito resuelto`, `Salvaguarda`, `Enrutado`, `Salario (SQL)`, `Dato de referencia (estructurado)`, `Composición (dato + convenio)`, `Cobertura del convenio`, `Recuperación`, `Síntesis`, `Fundamentación (entailment)`, `Decisión`.

Meta fragments: `activada (` / `)` or `sin incidencias`; ` subconsulta(s)`; ` reformulación(es)`; `Ver texto de la(s) reformulación(es)`; ` · confianza `; ` · categoría: `; ` · año `; ` · ámbito: `; ` · validez: `; ` · CONFLICTO (` `: dato ` ` vs convenio ` `) → escala, no mezcla`; ` · el convenio gobierna`; ` fragmento(s) de convenio sobre el tema`; ` · pendiente de indexar`; `convenio nunca cargado · se responde con el Estatuto (mínimos legales)`; `el convenio existe pero no es recuperable · el Estatuto NO lo sustituye (ultraactividad) → escala`; ` pasadas (recall)`; ` fragmentos · score máx. `; ` cita(s)`; ` · fundamentado en: `; ` afirmación(es)`; `verificada` / `no verificada`; ` sin respaldo`; outcomes `responder`, `pedir categoría`, `escalar`; ` · base: Estatuto (mínimos legales)`; `convenio `; ` · estado `.

### Escalation and human reply

Escalated assistant turn badge: `Escalado a Recursos Humanos` (badge-review, warning).

A later human reply: `Respuesta de ` + author + ` (persona)` (badge-agent). If the server sends no author label, the name is `Recursos Humanos`.

---

## Login (not an admin view)

Shown before either shell. Entirely English in `es.ts`: `Sign in with a one-time email code (email OTP).` `Email`, `Send code` / `Sending…`, `We sent a 6-digit code to`, `Code`, `Verify & sign in` / `Verifying…`, `Use a different email`. Errors: `We couldn't send the code. Check the email address and try again.` `That code didn't match. Request a new one and try again.`

## FilterToolbar coverage

The component defaults the disclosure **open**. `Filtros` and `Limpiar filtros` render only when the screen passes filter children. `Limpiar filtros` only after at least one filter is active.

| Screen | Filter children |
|---|---|
| Documentos | status select, `Conflicts only` |
| Revisión · Reference facts | topic select |
| Revisión · AI tagging, Vocabulary, Expiry | none — total only, no `Filtros` |
| Escalaciones | motivo select, `Solo asignadas a mí` |
| Historial | convenio, territorio, resultado, motivo, desde, hasta |
| Mapa, Grafo, Groups, Analítica, Cobertura, Calidad, Directorio, Administradores, Guardrails, Ajustes, Chat | not this component. Grafo and those screens have their own chips or always-visible controls, listed above. |
