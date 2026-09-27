# Sprint 12a — Spanish copy audit

Audit only. No dictionary or brand code has been changed.

Source: every leaf of `hr-frontend/src/i18n/es.ts` (1,213 strings) compared with `en.ts`. Terminology follows the approved glossary in `hr-docs/sprints/sprint-11b/review.md` (convenio stays convenio; vigencia, ámbito, dato de referencia, categoría profesional, verificado / sin verificar, cobertura, tema, territorio). `super_admin` stays the role token, matching the Spanish already in Guardrails. `IA` replaces `AI`, matching the Grafo chrome.

## Count

| Bucket | Strings |
| --- | ---: |
| Leaves in `es.ts` | 1213 |
| Untranslated or partly English — proposed below | 372 |
| Stale content — rewrite, not translate | 10 |
| Employee chat chrome still in English | 0 |
| Login strings still in English | 12 |

By screen:

| Screen | Rows |
| --- | ---: |
| Login | 12 |
| Employee chat | 0 |
| Admin shell | 5 |
| Shared labels | 5 |
| Knowledge map | 40 |
| Documents | 26 |
| Document detail | 97 |
| Review queue | 58 |
| Reference fact | 52 |
| New reference fact | 37 |
| Fact versions | 3 |
| Vocabulary proposal | 20 |
| Coverage | 4 |
| Analytics | 2 |
| Quality | 2 |
| People | 3 |
| Shared controls | 6 |
| **Total proposed** | **372** |

## Left out of the translation table

Identical in both locales on purpose, not a copy miss:

- Cognates and kept terms: `Error`, `Convenio`, `Sector`, `Manual`, `Auditor`, `Roles`, `Grafo`, `Id`, `OK`, `p.`, punctuation.
- `tracePanel.conflictVsConvenio` / `tracePanel.convenioPrefix` — already the word convenio.
- `referenceFactCreatePanel.valuePlaceholder` — a Spanish source sample (`periodo de prueba 90/75 días`), glossary: periodo de prueba is data, not chrome.
- `referenceFactPanel.sourceLocatorPlaceholder` — a format example (`p.3 §2 / sheet:smi26`), same in both locales.
- `brand.productName` (`HR Platform`) — brand identity, identical by design. See the brand inventory; it is not a translation row.
- `Tokens` and `Embeddings` are still listed: they are English loanwords. Proposed Spanish keeps both as the technical labels.

## Stale content — rewrite, do not translate

These describe work that already shipped, or they leak sprint ids, ADRs, plan paths, enum keys, or internal class names. Translating them would preserve the wrong content.

| Key | Current es | Why it is stale | Proposed rewrite |
| --- | --- | --- | --- |
| `documentDetail.noTopicsNotice` | No topics tagged yet — topic tagging arrives with the AI tier (Sprint 7); a human can tag now. | Says topic tagging “arrives with the AI tier (Sprint 7)”. Sprint 7 shipped; tagging is live. | Aún no hay temas etiquetados. Puedes etiquetarlos ahora. |
| `hierarchy.noTopicsNotice` | No topics tagged yet — topic tagging arrives with the AI tier (Sprint 7). You can tag topics by hand from a document card. | Same Sprint 7 promise, on the map empty state. | Aún no hay temas etiquetados. Puedes etiquetar temas a mano desde la ficha del documento. |
| `documentDetail.noTextOcrMiddle` | (Sprint 7e, ADR-0026) to OCR it, or re-ingest with | Cites “Sprint 7e, ADR-0026” in the OCR hint. That sprint shipped; an ADR id is not product copy. The command name around this fragment stays in the JSX. | para hacer el OCR, o vuelve a ingerir con |
| `documentDetail.zeroChunksNotice` | Zero chunks — this document is not retrievable (unanswerable until re-chunked; re-chunking is not a Knowledge-Center action). | Tells the admin that re-chunking “is not a Knowledge-Center action” — internal product name, in English. | Sin fragmentos — este documento no es recuperable (no se puede responder hasta volver a fragmentarlo; volver a fragmentar no está disponible aquí). |
| `adminShell.views.settings.description` | Configure the external answer-model provider key (ADR-0015). | Admin-facing sentence whose only extra is the internal id ADR-0015. | Configura la clave del proveedor externo del modelo de respuesta. |
| `adminShell.views.brandPreview.heading` | Brand preview (CP-1 — sprint-11a) | Internal checkpoint label “CP-1 — sprint-11a” on a screen that is not in the nav. | Vista previa de marca |
| `adminShell.views.brandPreview.description` | Not in the nav — reachable only via #view=brand-preview. See sprint-11a/plan.md §G.1 step 3. | Points at `sprint-11a/plan.md` and a plan step. Keep the hash route; drop the sprint citation. | No está en el menú — solo por #view=brand-preview. |
| `reviewQueue.tagging.underReview` | under_review | The raw status code `under_review` is rendered as the visible word. `en.ts` has the same leak. | en revisión |
| `proposeVocabularyForm.topicNoAliasNotice` | Topics have no alias-fold mechanism — spelling variants are resolved in code via TopicLexicon, not here. | Names the class `TopicLexicon` in the admin sentence. | Los temas no tienen mecanismo de alias: las variantes de grafía se resuelven en el código, no aquí. |
| `analyticsPage.periodNote` | Deflection rate excluye `needs_category` del denominador (§2.1, resuelto). | Half English (“Deflection rate”), plus the code `needs_category` and “§2.1, resuelto”. The KPI next to it is already “Tasa de resolución (deflection)”. | La tasa de resolución excluye «necesitan categoría» del denominador. |

Already Spanish, so they are not in the table above, but they still cite spec sections or internal field names. Drop those citations in the same pass if the copy should read as product text:

| Key | Current es |
| --- | --- |
| `qualitySampleQueue.introPart1` | Muestra mensual estratificada de turnos respondidos (§6.2) — cada fila es un turno REAL que un empleado recibió, no un caso sintético. Marcar |
| `qualitySampleQueue.reviewerBarredNotice` | No puedes revisar esta muestra: estás asignado a una tarjeta de escalación de la misma sesión (§6.3). |
| `analyticsPage.kpiSatisfactionSubSuffix` | (§7, opcional) |
| `analyticsPage.escalationsByFixHeading` | Escalaciones por corrección (§3) |
| `analyticsPage.clusteringHeadingPrefix` | Agrupación de preguntas (§4) — ejecución |
| `analyticsPage.pathSplitHeading` | Reparto por vía (path_split) |
| `analyticsPage.authoritySplitHeading` | Reparto por autoridad (authority_split) |
| `analyticsPage.unansweredRankingHeading` | Ranking "sin responder" (escalation_rate × volumen × personas afectadas) |

## Proposed Spanish

Glossary applied in the proposals: ámbito (not “scope”), vigencia (not “validity”), dato / dato de referencia (not “fact”), tema, territorio, tipo, categoría profesional, verificado / sin verificar, cobertura, Guardarraíles (the nav label, matching the existing heading). View toggles that sit beside the Grafo section use **Gráfico / Lista**, so they do not collide with the section name Grafo. `super_admin` is unchanged.

### Login (12)

The whole `login` namespace was copied through in English at extraction. Placeholders `you@example.com` and `123456`, and the `localhost:8025` host, are hardcoded in `LoginPage.tsx` and are not dictionary leaves.

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `login.subtitle` | Sign in with a one-time email code (email OTP). | Sign in with a one-time email code (email OTP). | Entra con un código de un solo uso enviado por correo. |
| `login.emailRequestFailed` | We couldn't send the code. Check the email address and try again. | We couldn't send the code. Check the email address and try again. | No pudimos enviar el código. Revisa el correo e inténtalo de nuevo. |
| `login.codeVerifyFailed` | That code didn't match. Request a new one and try again. | That code didn't match. Request a new one and try again. | Ese código no coincide. Pide otro e inténtalo de nuevo. |
| `login.emailLabel` | Email | Email | Correo |
| `login.sendingButton` | Sending… | Sending… | Enviando… |
| `login.sendCodeButton` | Send code | Send code | Enviar código |
| `login.codeSentPrefix` | We sent a 6-digit code to | We sent a 6-digit code to | Enviamos un código de 6 dígitos a |
| `login.codeSentMailhogPrefix` | . In local dev it is visible in MailHog at | . In local dev it is visible in MailHog at | . En local se ve en MailHog, en |
| `login.codeLabel` | Code | Code | Código |
| `login.verifyingButton` | Verifying… | Verifying… | Verificando… |
| `login.verifyAndSignInButton` | Verify & sign in | Verify & sign in | Verificar y entrar |
| `login.useDifferentEmailButton` | Use a different email | Use a different email | Usar otro correo |

### Employee chat (0)

No rows. Employee chat chrome in `chat` is already Spanish (welcome, input, feedback, escalation badge, category picker). `citationList` is already Spanish. The two `tracePanel` leaves that match English are the kept word convenio.

### Admin shell (5)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `adminShell.nav.guardrails` | Guardrails | Guardrails | Guardarraíles |
| `adminShell.logout` | Log out | Log out | Cerrar sesión |
| `adminShell.views.map.description` | Navigate the corpus by lens, spot coverage gaps, and open a document to inspect, test, or edit its labels. | Navigate the corpus by lens, spot coverage gaps, and open a document to inspect, test, or edit its labels. | Navega el corpus por criterio, localiza huecos de cobertura y abre un documento para inspeccionar, probar o editar sus etiquetas. |
| `adminShell.views.analytics.description` | Deflection, escalaciones por corrección y agrupación de preguntas — todo reproducible desde los comandos | Deflection, escalations by correction, and question clustering — all reproducible from the commands | Tasa de resolución (deflection), escalaciones por corrección y agrupación de preguntas — todo reproducible desde los comandos |
| `adminShell.views.settings.heading` | Gobierno · Answer model | Governance · Answer model | Gobierno · Modelo de respuesta |

### Shared labels (5)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `common.validity` | Validity | Validity | Vigencia |
| `common.territory` | Territory | Territory | Territorio |
| `common.type` | Type | Type | Tipo |
| `common.topic` | Topic | Topic | Tema |
| `common.jobCategory` | Job category | Job category | Categoría profesional |

### Knowledge map (40)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `gapMeta.unanswerable.label` | Unanswerable | Unanswerable | Sin respuesta |
| `gapMeta.unanswerable.hint` | Active document with 0 indexed chunks (e.g. a scanned PDF, or still under review) — it cannot answer. | Active document with 0 indexed chunks (e.g. a scanned PDF, or still under review) — it cannot answer. | Documento vigente con 0 fragmentos indexados (p. ej. un PDF escaneado, o aún en revisión) — no puede responder. |
| `gapMeta.expired_no_successor.label` | No active successor | No active successor | Sin sucesor vigente |
| `gapMeta.expired_no_successor.hint` | Only historical prose remains for this scope — no active version to answer from (coverage hole). | Only historical prose remains for this scope — no active version to answer from (coverage hole). | Solo queda prosa histórica para este ámbito — no hay versión vigente desde la que responder (hueco de cobertura). |
| `gapMeta.suspected_mistag.label` | Suspected mistag | Suspected mistag | Posible confusión de etiquetado |
| `gapMeta.suspected_mistag.hint` | Tagged as convenio prose but the title/filename says "tabla" — likely a salary table. Human decides (retag in the card). | Tagged as convenio prose but the title/filename says "tabla" — likely a salary table. Human decides (retag in the card). | Etiquetado como prosa de convenio, pero el título o el nombre de archivo dice «tabla» — probable tabla salarial. Lo decide una persona (reetiquetar en la ficha). |
| `gapMeta.date_expired_active.label` | Date-expired (still active) | Date-expired (still active) | Fecha vencida (sigue vigente) |
| `gapMeta.date_expired_active.hint` | Validity end is in the past but the document is still active — a staleness signal, not a hole. The scope is still answerable. | Validity end is in the past but the document is still active — a staleness signal, not a hole. The scope is still answerable. | El fin de vigencia ya pasó, pero el documento sigue activo — señal de obsolescencia, no un hueco. El ámbito aún se puede responder. |
| `gapMeta.unscoped.label` | Unscoped | Unscoped | Sin ámbito |
| `gapMeta.unscoped.hint` | A non-national document with no convenio carries no scope (the scope-rides-on-convenio limitation). | A non-national document with no convenio carries no scope (the scope-rides-on-convenio limitation). | Un documento no nacional sin convenio no tiene ámbito (el ámbito va ligado al convenio). |
| `gapMeta.SCAN_NO_TEXT.label` | Scan, no text | Scan, no text | Escaneo, sin texto |
| `gapMeta.SCAN_NO_TEXT.hint` | Active prose document(s) exist but have 0 indexed chunks (e.g. a scanned PDF) — it cannot answer. | Active prose document(s) exist but have 0 indexed chunks (e.g. a scanned PDF) — it cannot answer. | Hay documento(s) de prosa vigentes, pero con 0 fragmentos indexados (p. ej. un PDF escaneado) — no puede responder. |
| `gapMeta.UNDER_REVIEW_SCOPE.label` | Scope under review | Scope under review | Ámbito en revisión |
| `gapMeta.UNDER_REVIEW_SCOPE.hint` | Prose document(s) exist but tagging is not yet verified — the scope is provisional, not yet answerable. | Prose document(s) exist but tagging is not yet verified — the scope is provisional, not yet answerable. | Hay documento(s) de prosa, pero el etiquetado aún no está verificado — el ámbito es provisional y todavía no se puede responder. |
| `gapMeta.EXPIRED_NO_SUCCESSOR.label` | Expired, no successor | Expired, no successor | Vencido, sin sucesor |
| `gapMeta.EXPIRED_NO_SUCCESSOR.hint` | Only historical/expired prose exists for this cell — no active successor to answer from. | Only historical/expired prose exists for this cell — no active successor to answer from. | Solo hay prosa histórica o vencida en esta celda — no hay sucesor vigente desde el que responder. |
| `gapMeta.SALARY_PDF_NOT_IMPORTED.label` | Salary PDF not imported | Salary PDF not imported | PDF salarial no importado |
| `gapMeta.SALARY_PDF_NOT_IMPORTED.hint` | A PDF salary document exists but has not been converted/imported into the salary table yet. | A PDF salary document exists but has not been converted/imported into the salary table yet. | Existe un PDF salarial, pero aún no se ha convertido ni importado a la tabla salarial. |
| `gapMeta.FACT_NEEDS_REVIEW.label` | Fact needs review | Fact needs review | Dato pendiente de revisión |
| `gapMeta.FACT_NEEDS_REVIEW.hint` | A proposed reference fact exists but no human has verified it yet. | A proposed reference fact exists but no human has verified it yet. | Hay un dato de referencia propuesto, pero ninguna persona lo ha verificado todavía. |
| `gapMeta.NO_SALARY_SOURCE.label` | No salary source | No salary source | Sin fuente salarial |
| `gapMeta.NO_SALARY_SOURCE.hint` | No salary table, and no salary PDF either — nothing to import from yet. | No salary table, and no salary PDF either — nothing to import from yet. | No hay tabla salarial, ni tampoco PDF salarial — aún no hay nada que importar. |
| `gapMeta.coverage_gap_unclassified.label` | Unclassified gap | Unclassified gap | Hueco sin clasificar |
| `gapMeta.coverage_gap_unclassified.hint` | This cell is uncovered but does not match a known reason code — needs manual investigation. | This cell is uncovered but does not match a known reason code — needs manual investigation. | Esta celda no está cubierta y no encaja en un motivo conocido — hay que investigarlo a mano. |
| `hierarchy.loadingMapText` | Loading map… | Loading map… | Cargando mapa… |
| `hierarchy.nothingToShowNotice` | Nothing to show for this lens yet. | Nothing to show for this lens yet. | Nada que mostrar para este criterio todavía. |
| `hierarchy.factBadgeTitle` | Structured reference fact | Structured reference fact | Dato de referencia estructurado |
| `hierarchy.emptyChildren` | (empty) | (empty) | (vacío) |
| `hierarchy.itemWord` | item | item | elemento |
| `hierarchy.itemsWordPlural` | items | items | elementos |
| `knowledgeMap.lensAriaLabel` | Lens | Lens | Criterio |
| `knowledgeMap.lensTerritory` | Territory | Territory | Territorio |
| `knowledgeMap.lensValidity` | Validity | Validity | Vigencia |
| `knowledgeMap.lensTopic` | Topic | Topic | Tema |
| `knowledgeMap.viewAriaLabel` | View | View | Vista |
| `knowledgeMap.viewGraph` | Graph | Graph | Gráfico |
| `knowledgeMap.viewList` | List | List | Lista |
| `knowledgeMap.newReferenceFactButton` | + New reference fact | + New reference fact | + Nuevo dato de referencia |
| `knowledgeMap.coverageGapsTitle` | Coverage gaps | Coverage gaps | Huecos de cobertura |
| `knowledgeMap.coverageGapsNone` | None detected. | None detected. | Ninguno detectado. |

### Documents (26)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `documentsPage.uploadFolderLabel` | Upload folder | Upload folder | Subir carpeta |
| `documentsPage.convenioFilterPrefix` | Convenio # | Convenio # | Convenio n.º  |
| `documentsPage.ingestingPrefix` | Ingesting | Ingesting | Ingiriendo |
| `documentsPage.ingestingSuffix` | file(s)… | file(s)… | archivo(s)… |
| `documentsPage.ingestedLabel` | Ingested | Ingested | Ingeridos |
| `documentsPage.skippedLabel` | skipped | skipped | omitidos |
| `documentsPage.failedLabel` | failed | failed | fallidos |
| `documentsPage.ingestFailedPrefix` | Couldn't ingest these files: | Couldn't ingest these files: | No se pudieron ingerir estos archivos: |
| `documentsPage.documentWord` | document | document | documento |
| `documentsPage.documentsWordPlural` | documents | documents | documentos |
| `documentsPage.allStatusesOption` | All statuses | All statuses | Todos los estados |
| `documentsPage.autoProposedOption` | Auto-proposed | Auto-proposed | Propuesta automática |
| `documentsPage.underReviewOption` | Under review | Under review | En revisión |
| `documentsPage.verifiedOption` | Verified | Verified | Verificado |
| `documentsPage.conflictsOnlyLabel` | Conflicts only | Conflicts only | Solo conflictos |
| `documentsPage.titleHeader` | Title | Title | Título |
| `documentsPage.flagsHeader` | Flags | Flags | Avisos |
| `documentsPage.conflictBadge` | Conflict | Conflict | Conflicto |
| `documentsPage.noTextBadge` | No text | No text | Sin texto |
| `documentsPage.nationalBadge` | National | National | Nacional |
| `documentsPage.noDocumentsMatchFilters` | No documents match these filters. | No documents match these filters. | Ningún documento coincide con estos filtros. |
| `documentsPage.noDocumentsYet` | No documents yet — upload a convenio folder to ingest. | No documents yet — upload a convenio folder to ingest. | Aún no hay documentos — sube una carpeta de convenio para ingerirla. |
| `documentsPage.prevButton` | ‹ Prev | ‹ Prev | ‹ Ant. |
| `documentsPage.nextButton` | Next › | Next › | Sig. › |
| `documentsPage.pagePrefix` | Page | Page | Página |
| `documentsPage.pageOfConnector` | of | of | de |

### Document detail (97)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `documentDetail.close` | Close | Close | Cerrar |
| `documentDetail.loadingTitle` | Loading… | Loading… | Cargando… |
| `documentDetail.confirmFailedPrefix` | Confirm failed:  | Confirm failed:  | No se pudo confirmar:  |
| `documentDetail.scanNoTextNotice` | No extractable text — this is a scan PDF. AI tagging requires a text layer. | No extractable text — this is a scan PDF. AI tagging requires a text layer. | Sin texto extraíble: es un PDF escaneado. El etiquetado por IA necesita una capa de texto. |
| `documentDetail.ocrdBadge` | OCR'd | OCR'd | Con OCR |
| `documentDetail.readOnlyPrefix` | Read-only — you don't have the | Read-only — you don't have the | Solo lectura — no tienes el permiso |
| `documentDetail.readOnlySuffix` | ability. You can browse, inspect, and run the sandbox. | ability. You can browse, inspect, and run the sandbox. | para editar. Puedes consultar, inspeccionar y usar el simulador. |
| `documentDetail.noConvenioNotice` | No convenio — this document carries no scope (scope is derived via the convenio), so employees won't receive it as an answer. | No convenio — this document carries no scope (scope is derived via the convenio), so employees won't receive it as an answer. | Sin convenio: este documento no tiene ámbito (el ámbito se deriva del convenio), así que los empleados no lo recibirán como respuesta. |
| `documentDetail.noTextOcrPrefix` | No extractable text — this looks like a scanned, image-only PDF. Run | No extractable text — this looks like a scanned, image-only PDF. Run | Sin texto extraíble: parece un PDF escaneado, solo imagen. Ejecuta |
| `documentDetail.suspectedMistagPrefix` | Suspected salary-table mistag: tagged as convenio prose but named like a table. | Suspected salary-table mistag: tagged as convenio prose but named like a table. | Posible confusión con tabla salarial: etiquetado como prosa de convenio, pero el nombre parece una tabla. |
| `documentDetail.suspectedMistagEditHint` | Use "Re-type document" below → Tablas salariales. | Use "Re-type document" below → Tablas salariales. | Usa «Cambiar tipo de documento» más abajo → Tablas salariales. |
| `documentDetail.suspectedMistagNoEditHint` | A knowledge editor can retag this. | A knowledge editor can retag this. | Un editor de conocimiento puede reetiquetarlo. |
| `documentDetail.aiTaggingUnverified` | Unverified AI tagging — | Unverified AI tagging — | Etiquetado de IA sin verificar — |
| `documentDetail.aiTaggingInert` | inert | inert | sin efecto |
| `documentDetail.aiTaggingUntilConfirm` | until you confirm. | until you confirm. | hasta que confirmes. |
| `documentDetail.aiTaggingStep1Label` | Step 1: | Step 1: | Paso 1: |
| `documentDetail.aiTaggingStep1Text` | review the fuchsia suggestions below; use the edit pickers to accept or correct the convenio, type, and validity. | review the fuchsia suggestions below; use the edit pickers to accept or correct the convenio, type, and validity. | revisa las sugerencias en fucsia; usa los selectores para aceptar o corregir el convenio, el tipo y la vigencia. |
| `documentDetail.aiTaggingStep2Label` | Step 2: | Step 2: | Paso 2: |
| `documentDetail.aiTaggingStep2Click` | click | click | pulsa |
| `documentDetail.confirmTagsButton` | Confirm tags | Confirm tags | Confirmar etiquetas |
| `documentDetail.aiTaggingStep2Suffix` | — that writes the scope and makes the document retrievable. | — that writes the scope and makes the document retrievable. | — eso fija el ámbito y hace recuperable el documento. |
| `documentDetail.derivedLabel` | derived | derived | derivado |
| `documentDetail.derivedTitleHint` | Derived from the convenio — not editable | Derived from the convenio — not editable | Derivado del convenio — no editable |
| `documentDetail.removedLabel` | removed | removed | eliminado |
| `documentDetail.scopeHeading` | Scope | Scope | Ámbito |
| `documentDetail.kvRetrieval` | Retrieval | Retrieval | Recuperación |
| `documentDetail.kvAuthority` | Authority | Authority | Autoridad |
| `documentDetail.kvLanguage` | Language | Language | Idioma |
| `documentDetail.kvStatus` | Status | Status | Estado |
| `documentDetail.reviewTasksHeading` | Review tasks | Review tasks | Tareas de revisión |
| `documentDetail.tagsConfirmed` | Tags confirmed ✓ | Tags confirmed ✓ | Etiquetas confirmadas ✓ |
| `documentDetail.resuggestButton` | Re-suggest with AI | Re-suggest with AI | Volver a sugerir con IA |
| `documentDetail.proposing` | Proposing… | Proposing… | Proponiendo… |
| `documentDetail.resuggestTitleNoText` | No extractable text — scan PDF, cannot AI-tag | No extractable text — scan PDF, cannot AI-tag | Sin texto extraíble: PDF escaneado, no se puede etiquetar con IA |
| `documentDetail.resuggestTitleReady` | Re-run the AI tagging proposal (queued) | Re-run the AI tagging proposal (queued) | Volver a lanzar la propuesta de etiquetado por IA (en cola) |
| `documentDetail.provenanceHeading` | Provenance | Provenance | Procedencia |
| `documentDetail.adminHashPrefix` | admin # | admin # | admin n.º  |
| `documentDetail.aiSuggestedHeading` | Suggested facets | Suggested facets | Facetas sugeridas |
| `documentDetail.aiSuggestedUnverified` | (unverified) | (unverified) | (sin verificar) |
| `documentDetail.aiUnresolvedNotice` | The AI couldn’t resolve a facet — see the flagged values below. | The AI couldn’t resolve a facet — see the flagged values below. | La IA no pudo resolver una faceta — mira los valores marcados abajo. |
| `documentDetail.aiSuggestionsNotChanged` | These are suggestions only — they have NOT changed the document’s scope. | These are suggestions only — they have NOT changed the document’s scope. | Son solo sugerencias: NO han cambiado el ámbito del documento. |
| `documentDetail.aiSuggestionsEditHint` | Adjust below if needed, then Confirm tags to verify (the human write). | Adjust below if needed, then Confirm tags to verify (the human write). | Ajusta abajo si hace falta y luego Confirmar etiquetas para verificar (la escritura humana). |
| `documentDetail.aiSuggestionsNoEditHint` | A knowledge editor verifies them. | A knowledge editor verifies them. | Las verifica un editor de conocimiento. |
| `documentDetail.proposeVocabulary` | Propose vocabulary | Propose vocabulary | Proponer vocabulario |
| `documentDetail.cancelPropose` | Cancel | Cancel | Cancelar |
| `documentDetail.topicsHeading` | Topics | Topics | Temas |
| `documentDetail.removeTopicAriaPrefix` | Remove | Remove | Quitar |
| `documentDetail.addTopicPlaceholder` | Add a topic… | Add a topic… | Añadir un tema… |
| `documentDetail.addTopicButton` | Add topic | Add topic | Añadir tema |
| `documentDetail.chunkHealthHeading` | Chunk health | Chunk health | Estado de los fragmentos |
| `documentDetail.chunksLabel` | Chunks | Chunks | Fragmentos |
| `documentDetail.tokensLabel` | Tokens | Tokens | Tokens |
| `documentDetail.pagesLabel` | Pages | Pages | Páginas |
| `documentDetail.embeddingsLabel` | Embeddings | Embeddings | Embeddings |
| `documentDetail.embeddingsPresent` | present | present | presentes |
| `documentDetail.embeddingsMissing` | missing | missing | ausentes |
| `documentDetail.lineageHeading` | Lineage | Lineage | Sucesión |
| `documentDetail.lineageSupersedes` | supersedes | supersedes | sucede a |
| `documentDetail.lineageSupersededBy` | superseded by | superseded by | sucedido por |
| `documentDetail.editLabelsHeading` | Edit labels | Edit labels | Editar etiquetas |
| `documentDetail.editLabelsNotice` | Bounded edit (FK pickers into existing vocabulary). Territory & sector are derived from the convenio and not editable. Every save appends append-only human provenance. | Bounded edit (FK pickers into existing vocabulary). Territory & sector are derived from the convenio and not editable. Every save appends append-only human provenance. | Edición acotada (selectores sobre el vocabulario existente). Territorio y sector se derivan del convenio y no se editan. Cada guardado añade procedencia humana, solo de añadido. |
| `documentDetail.rescopeConvenio` | Re-scope convenio | Re-scope convenio | Cambiar convenio |
| `documentDetail.retypeDocument` | Re-type document | Re-type document | Cambiar tipo de documento |
| `documentDetail.selectValuePlaceholder` | Select a value… | Select a value… | Selecciona un valor… |
| `documentDetail.retagButton` | Retag | Retag | Reetiquetar |
| `documentDetail.applyButton` | Apply | Apply | Aplicar |
| `documentDetail.rescopeConfirmTitle` | Re-scope this document? | Re-scope this document? | ¿Cambiar el convenio de este documento? |
| `documentDetail.retypeConfirmTitle` | Re-type to a salary table? | Re-type to a salary table? | ¿Pasarlo a tabla salarial? |
| `documentDetail.rescopeConfirmBody` | Changing the convenio changes the document’s derived territory + sector — i.e. which employees receive it as an answer. This appends human provenance and cannot rewrite history. | Changing the convenio changes the document’s derived territory + sector — i.e. which employees receive it as an answer. This appends human provenance and cannot rewrite history. | Cambiar el convenio cambia el territorio y el sector derivados — es decir, qué empleados lo reciben como respuesta. Añade procedencia humana y no reescribe el historial. |
| `documentDetail.retypeConfirmBody` | Marking this as a salary table moves it off the prose answer path onto the structured salary (SQL) path, and removes it from convenio-prose retrieval. This appends human provenance. | Marking this as a salary table moves it off the prose answer path onto the structured salary (SQL) path, and removes it from convenio-prose retrieval. This appends human provenance. | Marcarlo como tabla salarial lo saca de la vía de prosa y lo pasa a la vía salarial estructurada (SQL), y lo quita de la recuperación de prosa de convenio. Añade procedencia humana. |
| `documentDetail.retrievalFieldLabel` | Retrieval | Retrieval | Recuperación |
| `documentDetail.taggingFieldLabel` | Tagging | Tagging | Etiquetado |
| `documentDetail.validFromLabel` | Valid from | Valid from | Vigente desde |
| `documentDetail.validToLabel` | Valid to | Valid to | Vigente hasta |
| `documentDetail.saveLifecycle` | Save lifecycle | Save lifecycle | Guardar vigencia |
| `documentDetail.scopeAffectingSuffix` | (scope-affecting) | (scope-affecting) | (afecta al ámbito) |
| `documentDetail.scopeAffectingModalTitle` | Scope-affecting change | Scope-affecting change | Cambio que afecta al ámbito |
| `documentDetail.scopeAffectingModalBody` | Changing the retrieval status or validity window moves the eligibility window — which employees receive this document as an answer. This appends human provenance and cannot rewrite history. | Changing the retrieval status or validity window moves the eligibility window — which employees receive this document as an answer. This appends human provenance and cannot rewrite history. | Cambiar el estado de recuperación o la ventana de vigencia mueve quién puede recibir este documento como respuesta. Añade procedencia humana y no reescribe el historial. |
| `documentDetail.confirmChangeButton` | Confirm change | Confirm change | Confirmar cambio |
| `documentDetail.originalDocumentHeading` | Original document | Original document | Documento original |
| `documentDetail.hideSource` | Hide source | Hide source | Ocultar fuente |
| `documentDetail.viewOriginal` | View original | View original | Ver original |
| `documentDetail.cantEmbedPrefix` | Can’t embed inline — | Can’t embed inline — | No se puede incrustar — |
| `documentDetail.openTheFile` | open the file | open the file | abrir el archivo |
| `documentDetail.downloadOrOpen` | Download / open the original file | Download / open the original file | Descargar / abrir el archivo original |
| `documentDetail.sandboxHeading` | Sandbox | Sandbox | Simulador |
| `documentDetail.sandboxTag` | read-only · persists nothing | read-only · persists nothing | solo lectura · no guarda nada |
| `documentDetail.sandboxRunPrefix` | Run the answer pipeline against | Run the answer pipeline against | Ejecuta el proceso de respuesta contra |
| `documentDetail.sandboxRunSuffix` | only. Same gates as production; no chat, no escalation is saved. | only. Same gates as production; no chat, no escalation is saved. | solo. Las mismas compuertas que en producción; no se guarda ni el chat ni la escalación. |
| `documentDetail.testButton` | Test | Test | Probar |
| `documentDetail.running` | Running… | Running… | Ejecutando… |
| `documentDetail.outcomeResult` | result | result | resultado |
| `documentDetail.retrievedPrefix` | retrieved | retrieved | recuperados |
| `documentDetail.topScorePrefix` | top | top | máx. |
| `documentDetail.draftSummary` | Draft the model produced (not served) | Draft the model produced (not served) | Borrador que produjo el modelo (no se sirve) |
| `documentDetail.groundingStoppedPrefix` | Stopped by the grounding gate — ungrounded: | Stopped by the grounding gate — ungrounded: | Detenido por la compuerta de fundamentación — sin respaldo: |
| `documentDetail.sourcePagesHeading` | Source pages | Source pages | Páginas de origen |

### Review queue (58)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `reviewQueue.tabTagging` | AI tagging | AI tagging | Etiquetado IA |
| `reviewQueue.tabReferenceFacts` | Reference facts | Reference facts | Datos de referencia |
| `reviewQueue.tabGroups` | Groups | Groups | Grupos |
| `reviewQueue.tabVocabulary` | Vocabulary proposals | Vocabulary proposals | Propuestas de vocabulario |
| `reviewQueue.tabExpiry` | Expiry | Expiry | Vencimiento |
| `reviewQueue.facts.intro` | Reference facts awaiting verification — AI-segmented or manually created — | Reference facts awaiting verification — AI-segmented or manually created — | Datos de referencia pendientes de verificación — segmentados por IA o creados a mano — |
| `reviewQueue.facts.introUncertainFirst` | uncertain-first | uncertain-first | primero los más inciertos |
| `reviewQueue.facts.introRest` | , then lowest-confidence, then (ties only) topic demand (a manual fact carries neither signal, so it falls to the bottom of its tier). Inert until a human verifies, whichever source it came from. Open one to check the source (the quoted line for an AI proposal, fuchsia; the linked document for a manual fact) against the assigned scope. | , then lowest-confidence, then (ties only) topic demand (a manual fact carries neither signal, so it falls to the bottom of its tier). Inert until a human verifies, whichever source it came from. Open one to check the source (the quoted line for an AI proposal, fuchsia; the linked document for a manual fact) against the assigned scope. | , luego menor confianza y, solo en empate, la demanda del tema (un dato manual no trae ninguna de las dos señales, así que cae al final de su tramo). Sin efecto hasta que una persona lo verifique, venga de donde venga. Abre uno para contrastar la fuente (la línea citada si es propuesta de IA, en fucsia; el documento enlazado si es un dato manual) con el ámbito asignado. |
| `reviewQueue.facts.allTopics` | All topics | All topics | Todos los temas |
| `reviewQueue.facts.colValue` | Value | Value | Valor |
| `reviewQueue.facts.colSource` | Source | Source | Fuente |
| `reviewQueue.facts.colScope` | Scope | Scope | Ámbito |
| `reviewQueue.facts.colGroup` | Group | Group | Grupo |
| `reviewQueue.facts.colTopic` | Topic | Topic | Tema |
| `reviewQueue.facts.colConf` | Conf. | Conf. | Conf. |
| `reviewQueue.facts.colFlags` | Flags | Flags | Avisos |
| `reviewQueue.facts.versionBadge` | version | version | versión |
| `reviewQueue.facts.noFacts` | No facts awaiting review — the queue is clear. | No facts awaiting review — the queue is clear. | No hay datos pendientes de revisión — la cola está vacía. |
| `reviewQueue.facts.totalOne` | fact | fact | dato |
| `reviewQueue.facts.totalMany` | facts | facts | datos |
| `reviewQueue.tagging.introPrefix` | Documents | Documents | Documentos |
| `reviewQueue.tagging.introSuffix` | — not retrievable until verified. The AI auto-proposes facets on ingest; lowest-confidence first. Open one to review the (fuchsia) AI suggestions and Confirm. | — not retrievable until verified. The AI auto-proposes facets on ingest; lowest-confidence first. Open one to review the (fuchsia) AI suggestions and Confirm. | — no recuperables hasta verificarlos. La IA propone las facetas al ingerir; primero los de menor confianza. Abre uno para revisar las sugerencias (fucsia) y Confirmar. |
| `reviewQueue.tagging.colTitle` | Title | Title | Título |
| `reviewQueue.tagging.colType` | Type | Type | Tipo |
| `reviewQueue.tagging.colConfidence` | Confidence | Confidence | Confianza |
| `reviewQueue.tagging.colFlags` | Flags | Flags | Avisos |
| `reviewQueue.tagging.conflictBadge` | Conflict | Conflict | Conflicto |
| `reviewQueue.tagging.noTextBadge` | No text | No text | Sin texto |
| `reviewQueue.tagging.nothingUnderReview` | Nothing under review — the queue is clear. | Nothing under review — the queue is clear. | Nada en revisión — la cola está vacía. |
| `reviewQueue.tagging.totalOne` | document | document | documento |
| `reviewQueue.tagging.totalMany` | documents | documents | documentos |
| `reviewQueue.vocabulary.introPrefix` | Proposed vocabulary (variant→alias is the default; create-new is deliberate). Approving writes into the controlled vocabulary — gated by | Proposed vocabulary (variant→alias is the default; create-new is deliberate). Approving writes into the controlled vocabulary — gated by | Vocabulario propuesto (variante→alias es lo habitual; crear uno nuevo es deliberado). Aprobar escribe en el vocabulario controlado — lo autoriza |
| `reviewQueue.vocabulary.introSuffix` | (super_admin). The AI proposes only. | (super_admin). The AI proposes only. | (super_admin). La IA solo propone. |
| `reviewQueue.vocabulary.noProposals` | No open vocabulary proposals. | No open vocabulary proposals. | No hay propuestas de vocabulario abiertas. |
| `reviewQueue.vocabulary.looksLikePrefix` | · looks like # | · looks like # | · se parece a n.º  |
| `reviewQueue.vocabulary.proposedByPrefix` | proposed by | proposed by | propuesto por |
| `reviewQueue.vocabulary.fromDocumentPrefix` | · from | · from | · desde |
| `reviewQueue.vocabulary.reject` | Reject | Reject | Rechazar |
| `reviewQueue.vocabulary.awaitingApproval` | Awaiting a super_admin to approve. | Awaiting a super_admin to approve. | A la espera de que un super_admin lo apruebe. |
| `reviewQueue.vocabulary.totalOne` | proposal | proposal | propuesta |
| `reviewQueue.vocabulary.totalMany` | proposals | proposals | propuestas |
| `reviewQueue.expiry.intro` | Active prose within 90 days of expiry (or already past). Confirm a successor (same convenio only) to write the lineage — | Active prose within 90 days of expiry (or already past). Confirm a successor (same convenio only) to write the lineage — | Prosa vigente a menos de 90 días del vencimiento (o ya vencida). Confirma un sucesor (solo del mismo convenio) para escribir la sucesión — |
| `reviewQueue.expiry.introOldDocIs` | — the old document is | — the old document is | el documento antiguo |
| `reviewQueue.expiry.introNeverRetired` | never auto-retired | never auto-retired | nunca se retira solo |
| `reviewQueue.expiry.nothingExpiringPrefix` | Nothing expiring — the queue is clear. (Run | Nothing expiring — the queue is clear. (Run | Nada próximo a vencer — la cola está vacía. (Ejecuta |
| `reviewQueue.expiry.nothingExpiringSuffix` | to refresh.) | to refresh.) | para actualizar.) |
| `reviewQueue.expiry.totalOne` | task | task | tarea |
| `reviewQueue.expiry.totalMany` | tasks | tasks | tareas |
| `reviewQueue.expiry.pastBadge` | Past | Past | Vencido |
| `reviewQueue.expiry.noConvenio` | no convenio | no convenio | sin convenio |
| `reviewQueue.expiry.validPrefix` | valid | valid | vigente |
| `reviewQueue.expiry.noConvenioSuccessionNotice` | No convenio — succession is scope-based, so no same-convenio successor can be linked. Dismiss or escalate. | No convenio — succession is scope-based, so no same-convenio successor can be linked. Dismiss or escalate. | Sin convenio: la sucesión se basa en el ámbito, así que no se puede enlazar un sucesor del mismo convenio. Descartar o escalar. |
| `reviewQueue.expiry.pickSuccessor` | Pick the successor (same convenio)… | Pick the successor (same convenio)… | Elige el sucesor (mismo convenio)… |
| `reviewQueue.expiry.alsoRetire` | Also retire this one (historical) | Also retire this one (historical) | Retirar también este (histórico) |
| `reviewQueue.expiry.confirmSuccessionRetire` | Confirm succession + retire | Confirm succession + retire | Confirmar sucesión y retirar |
| `reviewQueue.expiry.confirmSuccessionButton` | Confirm succession | Confirm succession | Confirmar sucesión |
| `reviewQueue.expiry.dismissAction` | Dismiss (renewed in place / no action) | Dismiss (renewed in place / no action) | Descartar (renovado en el sitio / sin acción) |
| `reviewQueue.expiry.escalateAction` | Escalate | Escalate | Escalar |

### Reference fact (52)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `referenceFactPanel.heading` | Reference fact | Reference fact | Dato de referencia |
| `referenceFactPanel.loadingText` | Loading… | Loading… | Cargando… |
| `referenceFactPanel.aiProposalBadge` | AI proposal | AI proposal | Propuesta de IA |
| `referenceFactPanel.badgeVerified` | verified | verified | verificado |
| `referenceFactPanel.badgeNeedsReview` | needs review | needs review | pendiente de revisión |
| `referenceFactPanel.badgeRejected` | rejected | rejected | rechazado |
| `referenceFactPanel.closeAriaLabel` | Close | Close | Cerrar |
| `referenceFactPanel.readOnlyNoticePrefix` | Read-only — you don't have the | Read-only — you don't have the | Solo lectura — no tienes el permiso |
| `referenceFactPanel.readOnlyNoticeSuffix` | ability. | ability. | para editar. |
| `referenceFactPanel.aiProposalNoticeBold` | AI-segmented proposal — unverified. | AI-segmented proposal — unverified. | Propuesta segmentada por IA — sin verificar. |
| `referenceFactPanel.aiProposalNoticeRest` | Check the scope against the quoted source line below before verifying. The agent only proposes; it never verifies itself. | Check the scope against the quoted source line below before verifying. The agent only proposes; it never verifies itself. | Contrasta el ámbito con la línea de origen citada abajo antes de verificar. El agente solo propone; nunca se verifica a sí mismo. |
| `referenceFactPanel.confidencePrefix` | Confidence: | Confidence: | Confianza: |
| `referenceFactPanel.versionDuplicateBold` | Possible version/duplicate | Possible version/duplicate | Posible versión o duplicado |
| `referenceFactPanel.versionDuplicatePrefix` | — same scope as an existing fact with a different value (" | — same scope as an existing fact with a different value (" | — mismo ámbito que un dato existente, con otro valor (« |
| `referenceFactPanel.versionDuplicateSuffix` | "). Decide which is true, and since when. | "). Decide which is true, and since when. | »). Decide cuál es el válido y desde cuándo. |
| `referenceFactPanel.inertNoticeBold` | Inert until verified | Inert until verified | Sin efecto hasta verificarlo |
| `referenceFactPanel.inertNoticeRest` | this fact is not answerable until a human verifies it (once verified, it can be served directly as a live answer). | this fact is not answerable until a human verifies it (once verified, it can be served directly as a live answer). | este dato no se puede responder hasta que una persona lo verifique (una vez verificado, puede servirse tal cual como respuesta vigente). |
| `referenceFactPanel.sourceLineHeading` | Source line (check the scope) | Source line (check the scope) | Línea de origen (comprueba el ámbito) |
| `referenceFactPanel.valueHeading` | Value | Value | Valor |
| `referenceFactPanel.rawValuesSummary` | Original (raw_values) | Original (raw_values) | Original (raw_values) |
| `referenceFactPanel.scopeHeading` | Scope | Scope | Ámbito |
| `referenceFactPanel.groupLabel` | Group | Group | Grupo |
| `referenceFactPanel.noJobCategoryFallback` | — (convenio-wide) | — (convenio-wide) | — (todo el convenio) |
| `referenceFactPanel.authorityLabel` | Authority | Authority | Autoridad |
| `referenceFactPanel.authorityLockTitle` | A reference fact can never outrank a convenio (enforced in schema + validation). | A reference fact can never outrank a convenio (enforced in schema + validation). | Un dato de referencia nunca puede estar por encima de un convenio (forzado en el esquema y en la validación). |
| `referenceFactPanel.sourceLabel` | Source | Source | Fuente |
| `referenceFactPanel.sourceManual` | manual | manual | manual |
| `referenceFactPanel.statusLabel` | Status | Status | Estado |
| `referenceFactPanel.verifiedByPrefix` | by | by | por |
| `referenceFactPanel.noSourceDocLinked` | No source document linked | No source document linked | Sin documento de origen enlazado |
| `referenceFactPanel.verifying` | Verifying… | Verifying… | Verificando… |
| `referenceFactPanel.verifyProposal` | Verify proposal | Verify proposal | Verificar propuesta |
| `referenceFactPanel.verifyFact` | Verify fact | Verify fact | Verificar dato |
| `referenceFactPanel.cancelEdit` | Cancel edit | Cancel edit | Cancelar edición |
| `referenceFactPanel.fixThenVerify` | Fix then verify | Fix then verify | Corregir y verificar |
| `referenceFactPanel.editButton` | Edit | Edit | Editar |
| `referenceFactPanel.rejecting` | Rejecting… | Rejecting… | Rechazando… |
| `referenceFactPanel.rejectButton` | Reject | Reject | Rechazar |
| `referenceFactPanel.resegmentTitle` | Re-run the segmentation agent on the source (idempotent upsert) | Re-run the segmentation agent on the source (idempotent upsert) | Volver a ejecutar el agente de segmentación sobre la fuente (actualización idempotente) |
| `referenceFactPanel.resegmentButton` | Re-segment source | Re-segment source | Re-segmentar fuente |
| `referenceFactPanel.provenanceHeading` | Provenance | Provenance | Procedencia |
| `referenceFactPanel.adminHashPrefix` | admin # | admin # | admin n.º  |
| `referenceFactPanel.validityStartLabel` | Validity start | Validity start | Inicio de vigencia |
| `referenceFactPanel.validityEndLabel` | Validity end | Validity end | Fin de vigencia |
| `referenceFactPanel.sourceLocatorLabel` | Source locator | Source locator | Localizador de la fuente |
| `referenceFactPanel.authorityLockedPrefix` | Authority is locked to | Authority is locked to | La autoridad está fijada en |
| `referenceFactPanel.authorityLockedSuffix` | — it cannot be raised. | — it cannot be raised. | — no se puede subir. |
| `referenceFactPanel.saving` | Saving… | Saving… | Guardando… |
| `referenceFactPanel.confirmScopeChangeAriaLabel` | Confirm scope change | Confirm scope change | Confirmar cambio de ámbito |
| `referenceFactPanel.scopeChangeTitle` | Scope change | Scope change | Cambio de ámbito |
| `referenceFactPanel.scopeChangeBody` | This changes the validity/scope of the fact (which employees it would answer). Confirm to apply. | This changes the validity/scope of the fact (which employees it would answer). Confirm to apply. | Esto cambia la vigencia o el ámbito del dato (a qué empleados respondería). Confirma para aplicar. |
| `referenceFactPanel.confirmChangeButton` | Confirm change | Confirm change | Confirmar cambio |

### New reference fact (37)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `referenceFactCreatePanel.heading` | New reference fact | New reference fact | Nuevo dato de referencia |
| `referenceFactCreatePanel.requiredFieldsError` | A convenio and a value are required. | A convenio and a value are required. | Hacen falta un convenio y un valor. |
| `referenceFactCreatePanel.convenioRequiredLabel` | Convenio * | Convenio * | Convenio * |
| `referenceFactCreatePanel.selectConvenioPlaceholder` | Select a convenio… | Select a convenio… | Selecciona un convenio… |
| `referenceFactCreatePanel.derivedScopePrefix` | Derived scope: | Derived scope: | Ámbito derivado: |
| `referenceFactCreatePanel.derivedScopeSuffix` | (territory & sector ride the convenio — not editable) | (territory & sector ride the convenio — not editable) | (territorio y sector van con el convenio — no editables) |
| `referenceFactCreatePanel.jobCategoryOptionalLabel` | Job category (optional) | Job category (optional) | Categoría profesional (opcional) |
| `referenceFactCreatePanel.convenioWideOption` | Convenio-wide | Convenio-wide | Todo el convenio |
| `referenceFactCreatePanel.topicOptionalLabel` | Topic (optional) | Topic (optional) | Tema (opcional) |
| `referenceFactCreatePanel.noTopicOption` | No topic | No topic | Sin tema |
| `referenceFactCreatePanel.valueRequiredLabel` | Value * | Value * | Valor * |
| `referenceFactCreatePanel.originalTextOptionalLabel` | Original text (raw, optional) | Original text (raw, optional) | Texto original (en bruto, opcional) |
| `referenceFactCreatePanel.rawTextPlaceholder` | Paste the verbatim source phrasing (kept in raw_values)… | Paste the verbatim source phrasing (kept in raw_values)… | Pega la redacción literal de la fuente (se guarda en raw_values)… |
| `referenceFactCreatePanel.sourceDocumentOptionalLabel` | Source document (optional) | Source document (optional) | Documento de origen (opcional) |
| `referenceFactCreatePanel.noSourceLinkOption` | No source link | No source link | Sin enlace a la fuente |
| `referenceFactCreatePanel.sourceLocatorOptionalLabel` | Source locator (optional) | Source locator (optional) | Localizador de la fuente (opcional) |
| `referenceFactCreatePanel.authorityPrefix` | Authority: | Authority: | Autoridad: |
| `referenceFactCreatePanel.lockedSuffix` | — locked. | — locked. | — fijada. |
| `referenceFactCreatePanel.authorityNeverOutrankNotice` | A reference fact can never outrank a convenio. | A reference fact can never outrank a convenio. | Un dato de referencia nunca puede estar por encima de un convenio. |
| `referenceFactCreatePanel.willLandPrefix` | The fact will land | The fact will land | El dato quedará |
| `referenceFactCreatePanel.willLandSuffix` | — verify it from its card to make it count. | — verify it from its card to make it count. | — verifícalo desde su ficha para que cuente. |
| `referenceFactCreatePanel.creating` | Creating… | Creating… | Creando… |
| `referenceFactCreatePanel.createFactButton` | Create fact | Create fact | Crear dato |
| `referenceFactCreatePanel.cancelButton` | Cancel | Cancel | Cancelar |
| `referenceFactCreatePanel.readerHeading` | Reference source | Reference source | Fuente de referencia |
| `referenceFactCreatePanel.readerIntroPrefix` | Read a non-salary .docx/.xlsx to enter facts by hand. Tagging it | Read a non-salary .docx/.xlsx to enter facts by hand. Tagging it | Lee un .docx/.xlsx que no sea salarial para introducir datos a mano. Etiquetarlo |
| `referenceFactCreatePanel.readerIntroSuffix` | keeps it off the salary path. | keeps it off the salary path. | lo mantiene fuera de la vía salarial. |
| `referenceFactCreatePanel.uploadLabel` | Upload a new source (.docx / .xlsx) | Upload a new source (.docx / .xlsx) | Subir una fuente nueva (.docx / .xlsx) |
| `referenceFactCreatePanel.uploadingText` | Uploading + reading… | Uploading + reading… | Subiendo y leyendo… |
| `referenceFactCreatePanel.uploadedNote` | Uploaded — select it below to read its content. | Uploaded — select it below to read its content. | Subido — selecciónalo abajo para leer el contenido. |
| `referenceFactCreatePanel.openSourceLabel` | Open a source | Open a source | Abrir una fuente |
| `referenceFactCreatePanel.selectSourcePlaceholder` | Select a reference source… | Select a reference source… | Selecciona una fuente de referencia… |
| `referenceFactCreatePanel.loadingContentText` | Loading content… | Loading content… | Cargando contenido… |
| `referenceFactCreatePanel.noExtractableContent` | (no extractable content) | (no extractable content) | (sin contenido extraíble) |
| `referenceFactCreatePanel.sectionPrefix` | Section | Section | Sección |
| `referenceFactCreatePanel.useAsLocatorTitle` | Use as source locator | Use as source locator | Usar como localizador de la fuente |
| `referenceFactCreatePanel.useLocatorButton` | use locator | use locator | usar localizador |

### Fact versions (3)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `factDuplicatePanel.supersedeSuccessMsg` | Superseded — the previous fact's validity period has closed. Neither has been deleted. | Superseded — the previous fact's validity period has closed. Neither has been deleted. | Sucedido — se ha cerrado la vigencia del dato anterior. No se ha borrado ninguno. |
| `factDuplicatePanel.coexistSuccessMsg` | Marked as coexisting — both remain answerable. | Marked as coexisting — both remain answerable. | Marcados como coexistentes — los dos siguen pudiendo responder. |
| `factDuplicatePanel.rejectSuccessMsg` | Discarded as a duplicate — it is no longer answerable. | Discarded as a duplicate — it is no longer answerable. | Descartado como duplicado — ya no puede responder. |

### Vocabulary proposal (20)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `proposeVocabularyForm.proposeForPrefix` | Propose vocabulary for | Propose vocabulary for | Proponer vocabulario para |
| `proposeVocabularyForm.foldIntoPrefix` | Fold into | Fold into | Incorporar a |
| `proposeVocabularyForm.foldAsAliasLabel` | as an alias | as an alias | como alias |
| `proposeVocabularyForm.similarityPrefix` | (similarity | (similarity | (similitud |
| `proposeVocabularyForm.foldIntoExistingLabel` | Fold into an existing value | Fold into an existing value | Incorporar a un valor existente |
| `proposeVocabularyForm.nothingCloseFoundHint` | (nothing close enough was found) | (nothing close enough was found) | (no se encontró nada lo bastante parecido) |
| `proposeVocabularyForm.createNewPrefix` | Create a new | Create a new | Crear un nuevo |
| `proposeVocabularyForm.deliberateHint` | (deliberate) | (deliberate) | (deliberado) |
| `proposeVocabularyForm.levelLabel` | Level | Level | Nivel |
| `proposeVocabularyForm.convenioBlockedNotice` | Convenios are created by the registry import, not this flow. Fold the spelling into an existing convenio instead. | Convenios are created by the registry import, not this flow. Fold the spelling into an existing convenio instead. | Los convenios los crea la importación del registro, no este flujo. Incorpora la grafía a un convenio existente. |
| `proposeVocabularyForm.approveAsAliasButton` | Approve as alias | Approve as alias | Aprobar como alias |
| `proposeVocabularyForm.proposeOnlyButton` | Propose (a super_admin approves) | Propose (a super_admin approves) | Proponer (lo aprueba un super_admin) |
| `proposeVocabularyForm.approveAsNewValueButton` | Approve as new value | Approve as new value | Aprobar como valor nuevo |
| `proposeVocabularyForm.foldedMsgPrefix` | Folded “ | Folded “ | Se incorporó « |
| `proposeVocabularyForm.foldedMsgMid` | ” into | ” into | » a |
| `proposeVocabularyForm.createdNewMsgPrefix` | Created new | Created new | Se creó un |
| `proposeVocabularyForm.proposedMsgPrefix` | Proposed “ | Proposed “ | Se propuso « |
| `proposeVocabularyForm.proposedMsgSuffix` | ” — a super_admin will approve it. | ” — a super_admin will approve it. | » — un super_admin lo aprobará. |
| `proposeVocabularyForm.foldedExistingMsg` | Folded into the existing value. | Folded into the existing value. | Incorporado al valor existente. |
| `proposeVocabularyForm.createdNewValueMsg` | Created the new value. | Created the new value. | Se creó el valor nuevo. |

### Coverage (4)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `coveragePage.viewGroupAriaLabel` | View | View | Vista |
| `coveragePage.graphButton` | Graph | Graph | Gráfico |
| `coveragePage.listButton` | List | List | Lista |
| `coveragePage.loadingText` | Loading… | Loading… | Cargando… |

### Analytics (2)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `analyticsPage.loadingText` | Loading… | Loading… | Cargando… |
| `analyticsPage.scoreLabel` | score | score | puntuación |

### Quality (2)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `qualitySampleQueue.loadingText` | Loading… | Loading… | Cargando… |
| `qualitySampleQueue.stratumPathFallback` | prose | prose | prosa |

### People (3)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `escalationCard.colEmail` | Email | Email | Correo |
| `csvImportPanel.colEmail` | Email | Email | Correo |
| `adminsPage.roleLabels.super_admin` | Super admin | Super admin | Superadministrador |

### Shared controls (6)

| Key | Current es | Current en | Proposed Spanish |
| --- | --- | --- | --- |
| `pager.prevButton` | ‹ Prev | ‹ Prev | ‹ Ant. |
| `pager.pagePrefix` | Page | Page | Página |
| `pager.pageOfConnector` | of | of | de |
| `pager.nextButton` | Next › | Next › | Sig. › |
| `charts.trendChartAriaLabel` | trend chart | trend chart | gráfico de tendencia |
| `protectedRoute.loadingText` | Loading… | Loading… | Cargando… |

## Brand inventory — logo on login, favicon on both shells

No client name is hardcoded in components today. `brand.ts` exports only the logo asset. The product name lives in `t.brand.productName` (`HR Platform` in both locales).

### Login form

`LoginPage.tsx` renders `<h1>{t.brand.productName}</h1>`. It does not use the logo.

Both shells already do: `<img src={BRAND.logo} alt={t.brand.productName}>` in `AdminShell` (sidebar) and `EmployeeShell` (header). `BRAND.logo` is `src/assets/brand/logo.svg`, the wordmark copied from `hr-docs/sedena/LOGO-SEDENA-01.svg` (`viewBox="0 0 197.17 40.56"`, about 4.86:1). Sprint 11a sized it by height, not in a square, and did not repeat the product name beside it because the graphic already reads as the name.

What the login change needs:

1. Read `BRAND.logo` from `brand.ts`. Do not import the sedena path, and do not write a client name or `HR Platform` into the component.
2. `alt={t.brand.productName}`.
3. Size by aspect ratio (same as the shells). The asset is a wordmark, not an icon.
4. Do not also show the `h1` text — it would duplicate the wordmark. Keep a heading for the card if needed, visually hidden, so the accessible name stays the product name from the dictionary.

### Favicon (both shells)

Admin and employee are one SPA. One document head covers both shells; there is no per-shell icon today.

| Piece | Today |
| --- | --- |
| `hr-frontend/index.html` | `<link rel="icon" href="/favicon.svg">` and `<title>HR Platform</title>`. `lang="en"`. |
| `hr-frontend/public/favicon.svg` | The Vite default purple mark. Not a brand asset. |
| `brand.ts` | `{ logo }` only. No favicon field. |
| `main.tsx` | Sets `document.title` from `es.brand.productName` after load. The static title is only the pre-JS fallback. |
| `hr-docs/sedena/` | Wordmark `LOGO-SEDENA-01.svg` plus fonts. No square icon file. |

What the favicon change needs:

1. A square mark cropped from the diamond emblem on the left of `logo.svg` (the wordmark itself is too wide for a tab icon). The brand drop does not include one.
2. Put that file under `src/assets/brand/` with a generic filename, and export it from `brand.ts` (for example `BRAND.favicon`) next to `logo`. No client name in the export, the path string shown to the app, or the `alt`.
3. Point the icon at that export for both shells. `index.html` cannot import `brand.ts`; `main.tsx` already sets the title from brand data before paint, and the same place can set the icon link. Replace `public/favicon.svg` as well so the first paint is not the Vite mark.
4. The static `<title>HR Platform</title>` is the same class of hardcoded product string. Runtime title already reads the dictionary. Worth aligning in this pass; it is not a second favicon.

## Stop

No `es.ts` edits and no brand wiring until this proposed Spanish is reviewed.
