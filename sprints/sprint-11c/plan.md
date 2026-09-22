# Sprint 11c — Plan: Knowledge graph on the Map (3D, with 2D toggle)

> Status: **ACCEPTED 2026-09-22 — building on branch `sprint-11c`, no commit until
> review.** All five §0 corrections accepted. §4b's stale claim ticked in
> `deploy.md` the same day (see git history). Every open question resolved
> below, before Step 1 starts, per §E.1's build order.
> Spec: `hr-docs/sprints/sprint-11c/sprint-11c-spec.md`
> Every count below was measured against **hr-staging** on **2026-09-22** with
> `hr-docs/sprints/sprint-11c/measure-graph.php` (read-only SELECTs, ADR-0030;
> runner: `run-measure-graph.sh`). Re-run it to reproduce any number here.
> Every bundle size below was measured by building real Vite bundles in a
> scratch directory (§B.1), not read off a README.

## Decisions (2026-09-22, Pedram)

| # | Question | Decision |
|---|---|---|
| OQ-1 | Draw `fact → source document` as a 7th edge type for the shared-source (105/106) cluster? | **No for v1.** Stays bipartite, spec §2's six edge types only. The side card names the shared-source provenance (`source_document` field, §A.5) instead of drawing it. Revisit only if CP-1 finds the cluster genuinely unexplained on screen. |
| OQ-2 | Draw the 5 approved `convenio_groups` (+8 fact edges, +5 convenio edges, +2 hub→hub edges)? | **Defer.** Rationale, for the record: all 5 approved nodes sit on 1 of 26 convenios (id 21) while 28 more are still `needs_review` on 5 other convenios — drawing them now would render *reviewer throughput* as if it were *knowledge structure*, making convenio 21 look structurally distinguished when the real difference is only that a human got to it first. Structure should reflect the corpus, not the review queue's backlog order. |
| OQ-3 | 2D library (`force-graph`) vs hand-rolled SVG? | **`force-graph`.** Shared accessor API with the 3D renderer, one state→visual mapping. |
| OQ-4 | Scope node colour: `--accent` both themes, or `--brand-warm` in dark? | **`--accent`, both themes.** No token-restriction argument to have. |
| OQ-5 | Label count: 46 persistent / 20 / distance-based? | **Start at 46** (scope nodes). Ladder down at CP-1 if crowded. |
| OQ-6 | Draw the 2 zero-knowledge convenios (ids 7, 27)? | **Yes.** Both reach a drawn hub; an empty convenio is a coverage gap worth showing, not tidying away. |
| OQ-7 | Include `salary_tables` (319 rows)? | **Excluded**, as proposed. No edge type for them in spec §2. |

---

## 0. Corrections to the spec, found while measuring

Five things the spec assumes that the real data or the real code contradicts.
None of them change the sprint's goal; all of them change a number or a name.

1. **Scale: 261 nodes / 421 edges, not "≈300–350 nodes".** Spec §3 estimates from
   raw inventory (27 convenios + 106 docs + 150 facts + hubs). Applying the spec's
   own rules removes 89 of those: 40 orphan documents, 5 `rejected` facts, 22
   folded/unused hubs, 1 fixture convenio. The rules are doing their job; the
   estimate just predated them. Full derivation in §A.2.

2. **There is no `reference_facts.convenio_group_id`.** Spec §2's parenthetical
   ("where a bound `convenio_group_id` exists") describes a column that does not
   exist. The fact↔group binding is the M2M table `reference_fact_group_scopes`
   (`2026_09_09_180003_create_reference_fact_group_scopes_table.php:47-65`).
   8 bindings exist, across 6 facts and 4 groups, all on convenio 21. §A.4-C.

3. **`ADR-0018` is `0018-role-scoped-conversation-access`, not an ADR titled
   "server is the boundary".** The principle the spec is invoking is stated
   inside it and is quoted in two places in the code
   (`hr-backend/routes/api.php:108` "The SERVER is the boundary";
   `hr-frontend/src/lib/api.ts:50-52` "The UI only HIDES on these — the server
   enforces every endpoint"). The citation is right, the parenthetical is a
   paraphrase. Flagged only so nobody hunts for a missing ADR.

4. **`deploy.md` §4b is stale.** It records "**0** `reference_facts` rows exist
   [on staging] today" as an open pre-go-live item. There are now **154**. The
   item looks done; someone should confirm and tick it. Doc hygiene, not a
   sprint task.

5. **The 2D toggle cannot be the same library's `numDimensions(2)`.** Spec §7's
   R4 resolution is "auto-switch to 2D" when WebGL is unavailable — but
   `numDimensions(2)` is still the same three.js WebGL renderer drawing a flat
   graph, so a machine with no WebGL that auto-switched to 2D would hit exactly
   the same failure. The 2D mode therefore has to be a non-WebGL renderer. This
   is what decides R1, on evidence rather than taste — §B.3.

---

## A. The data, measured

### A.1 Raw inventory (hr-staging, 2026-09-22)

| Thing | Count | Detail |
|---|---|---|
| convenios | **27** | 26 real + 1 `DEV-FIXTURE-0001` (id 28; 0 docs, 0 facts) |
| — with no documents and no facts | **2** | id 7 `ACCIÓN E INTERVENCIÓN SOCIAL ESTATAL`, id 27 `LOCALES Y CAMPOS DEPORTIVOS` |
| documents | **106** | 61 bound to a convenio, **45 with `convenio_id IS NULL`** |
| — `retrieval_status = active` | 46 | 35 bound / 11 unbound |
| — `retrieval_status = draft` | 3 | 3 bound / 0 unbound |
| — `retrieval_status = historical` | 57 | 23 bound / 34 unbound |
| — `tagging_status` | | 42 `auto_proposed`, 48 `under_review`, 16 `verified` |
| — `authority_level` | | 100 `official_convenio`, 3 `national_law`, 3 `internal_hr_ruling` |
| reference_facts | **154** | all 154 have both `convenio_id` and `topic_id` |
| — `verified` | 132 | **131 `ai_agent`** + 1 `admin_manual` |
| — `needs_review` | 17 | all `ai_agent`, across 8 convenios |
| — `rejected` | 5 | all `ai_agent` |
| — spread | | 22 distinct convenios, **only 5 distinct topics**, 17 distinct source documents |
| topics | **13** | all `approved`; 11 carry ≥1 document tag, 5 carry ≥1 fact |
| territories | **12** | 1 `national` (Estatal), 10 `provincial`, 1 `regional` (Andalucía) — all 12 used by a convenio |
| sectors | **20** | 19 used by a real convenio, 1 used only by the fixture |
| convenio_groups | **33** | **5 `approved`** (all on convenio 21) + 28 `needs_review` |
| reference_fact_group_scopes | **8** | 6 facts ↔ 4 groups; all 8 point at `approved` groups |
| salary_tables / rows | 19 / **319** | 9 convenios — **excluded from the graph**, see §A.4-E |

The single most load-bearing number for the colour design: **131 of the 154 facts
are AI-authored *and* human-verified.** Under ADR-0020 those are **not** fuchsia
(§D.2).

### A.2 The graph the spec's rules actually produce

Computed in SQL from the same `drawn_*` CTEs the builder will implement, so the
node table and the edge table cannot disagree (`measure-graph.php`, `NODES` /
`EDGES`).

**Nodes — 261**

| Node type | Drawn | Not drawn | Why not |
|---|---|---|---|
| convenio | **26** | 1 | `DEV-FIXTURE-0001` is not real data (deploy.md §4) |
| document | **66** | 40 | orphan: no convenio **and** no edge to a drawn topic |
| reference fact | **149** | 5 | `status = rejected` — a discard record, not knowledge |
| territory hub | **5** | 7 | 1 attachment → folded to a count on the convenio |
| sector hub | **6** | 14 | 13 folded singletons + 1 used only by the fixture |
| topic hub | **9** | 4 | 2 folded singletons + 2 with zero attachments |
| **total** | **261** | **71** | |

Drawn hubs, with degree: territories **Navarra 6, Estatal 5, Gipuzkoa 4, Álava 2,
Vizcaya 2**; sectors **OFICINAS Y DESPACHOS 3**, then five at 2; topics **periodo
de prueba 88, jornada 32, vacaciones 22, permisos retribuidos 18, festivos 5,
retribución 5, bajas médicas 4, normativa/derechos 3, formación 2**.

**Edges — 421**

| Edge | Count |
|---|---|
| document → convenio | **61** |
| document → topic | **30** |
| fact → convenio | **149** |
| fact → topic | **149** |
| convenio → territory | **19** |
| convenio → sector | **13** |
| **total** | **421** |

Average degree 3.2. For `3d-force-graph` this is trivial — its own demos run
tens of thousands of nodes. Nothing here is a performance question.

**The shape is lopsided, and that matters for the look.** Topic
`periodo de prueba` has **degree 88** — 21% of all edges converge on one node.
The next largest are `jornada` 32 and `vacaciones` 22; the largest convenios are
18 and 25 at 19 each. So the picture is one dominant starburst, two medium ones,
and 26 convenio clusters. This is the main thing CP-1 has to judge (§E.3).

### A.3 Every edge type, with its exact source relation

Six edge types. Each one is a single foreign key or a single pivot row, and each
survives one factual sentence.

| # | Edge | Source relation | Sentence | Count |
|---|---|---|---|---|
| 1 | document → convenio | `documents.convenio_id` → `convenios.id` | "This document is bound to this convenio." | 61 |
| 2 | document → topic | `document_topics.document_id` → `documents.id`, `document_topics.topic_id` → `topics.id` | "This document is tagged with this topic." | 30 |
| 3 | fact → convenio | `reference_facts.convenio_id` → `convenios.id` | "This fact's scope is this convenio." | 149 |
| 4 | fact → topic | `reference_facts.topic_id` → `topics.id` | "This fact is about this topic." | 149 |
| 5 | convenio → territory | `convenios.territory_id` → `territories.id` | "This convenio applies in this territory." | 19 |
| 6 | convenio → sector | `convenios.sector_id` → `sectors.id` | "This convenio covers this sector." | 13 |

Migration citations: `documents.convenio_id`
(`2026_06_20_131008_create_documents_table.php:17`); `document_topics`
(`2026_06_20_131010_create_document_topics_table.php:13-14`);
`reference_facts.convenio_id` / `.topic_id`
(`2026_06_26_120001_create_reference_facts_table.php:39`, `:43`);
`convenios.sector_id` (`2026_06_20_131003_create_convenios_table.php:16`);
`convenios.territory_id` (created as `province_id` at
`2026_06_20_131003_create_convenios_table.php:15`, renamed by
`2026_06_21_100003_rename_province_fk_columns_to_territory.php:20-22`).

**Forbidden by construction.** No document↔document, no fact↔fact, no
document↔fact, no similarity, no inference. Note that the schema *offers* two
tempting edges that are deliberately not in the list:
`documents.predecessor_document_id` and `documents.derived_from_document_id`
(3 rows) are document↔document and break bipartite;
`reference_facts.source_document_id` (154 rows, all non-null) is fact↔document
and also breaks bipartite — it is the subject of **OQ-1**, and it is exactly
where the interesting problem lives.

### A.4 The ambiguous cases

**A. Shared reference sources — documents 105 and 106.** This is the most
interesting thing in the data.

| Document | `convenio_id` | Facts citing it | Distinct convenios |
|---|---|---|---|
| 106 `PERÍODOS PRUEBA ACTUALIZADOS 2026` | **NULL** | 49 | **17** |
| 105 `PERIODOS DE PRUEBA` | **NULL** | 39 | **15** |

Measured consequence: **all 88 facts on topic `periodo de prueba` come from
these two documents, and nothing else does** (`topic1_facts: 88`,
`topic1_facts_from_105_106: 88`, `facts_from_105_106_other_topic: 0`). So the
graph's single dominant structure — the degree-88 starburst — is produced
entirely by two documents that, under the six edge types above, **are not drawn
at all**: both have `convenio_id IS NULL` and neither carries a single
`document_topics` row (`docs_105_106_topics` returns empty).

There is no `document_convenio` pivot in the schema and no plan to add one. The
relation that actually exists is `reference_facts.source_document_id` → the
document, once per fact, each fact carrying its own `convenio_id`. So the honest
choices are:

- **Recommended for v1 (spec-strict): don't draw them.** Documents 105/106 are
  orphans (2 of the 40). The cluster is still explained *in words*: the topic hub
  is labelled `periodo de prueba`, and every fact's side card names its source
  document with a deep link to it. The view's caption states the hidden count.
- **The alternative (OQ-1): add a 7th edge type, `fact → source document`**,
  drawn only for a document that is cited by ≥2 facts and has no convenio of its
  own — which on today's data means exactly these two documents, +2 nodes and
  **+87 edges** (49 + 38 non-rejected). It survives one factual sentence ("this
  fact was read from this document") and makes the biggest cluster
  self-explaining. It costs the bipartite rule: a document would become a hub for
  facts while still being an entity that attaches to a convenio.

I am not taking that decision inside a plan, because it edits spec §2's edge
list. Recommendation and trade-off are in **OQ-1**.

**B. Unbound documents — 45 of 106.** `documents.convenio_id` is a single
nullable FK; 45 rows are null (11 active, 34 historical; 3 of them
`national_law`, which is null *by design* — `HierarchyController.php:94-98`
treats national law as its own scope). Of the 45, **5 still draw** because they
carry topic tags that reach a drawn hub, and **40 have no edge to anything** and
are dropped. The five that draw:

| id | Title | State |
|---|---|---|
| 14 | Acuerdo fin de huelga UBIK - SEDENA SL 2019-2022 | active |
| 31 | PACTO CULTURA NAVARRA | active |
| 47 | Pacto de la empresa | historical |
| 68 | CONDICIONES LABORALES DEL PERSONAL SUBROGADO BARAKALDO | historical |
| 69 | PACTO | historical |

Dropping 40 documents silently would be dishonest, so the rule is: **an orphan
is never drawn, and its count is always on the surface.** The caption carries
"40 documentos sin vínculo — no se dibujan", and the number links to Documents.
This is the same principle as the spec's hub-sparsity rule ("singletons become a
count on the parent"), generalised: no floating nodes, no hidden subtractions.

**C. Facts from multi-convenio HR tables.** The prompt asks how these attach.
Measured answer: **there is no such table.** Every structured row in the schema
binds to exactly one convenio — `reference_facts.convenio_id` is `NOT NULL`
(`2026_06_26_120001_create_reference_facts_table.php:39`),
`salary_tables.convenio_id` is `NOT NULL`
(`2026_06_20_131012_create_salary_tables_table.php:11-18`), and
`convenio_job_categories` is per-convenio. What *looks* like a multi-convenio
table is case A above: one shared source document, many single-convenio fact
rows. So facts attach unambiguously, via edge type 3.

**D. Group nodes — deferred (OQ-2, decided).** `convenio_groups` has 5 `approved`
rows, **all on convenio 21**, plus 28 `needs_review` that are invisible to the
matcher by design (ADR-0028, deploy.md §4). Adding them would cost +5 nodes, +8
`fact → group` edges, +5 `group → convenio` edges, and +2 `group → parent group`
edges (`convenio_groups.parent_id`) — a hub→hub edge and a third node class. **Not
built in v1**: all 5 approved nodes sit on one convenio while 28 more await
review on five others, so drawing them now would render reviewer throughput as
knowledge structure — convenio 21 would look structurally distinguished for a
reason that has nothing to do with what it actually knows.

**E. Salary tables.** 19 tables / 319 rows across 9 convenios. Excluded: there is
no edge type for them in spec §2, and adding 319 nodes would more than double the
graph with rows whose meaning is "a figure in a table", not "a piece of knowledge
that governs something". Recorded here so the exclusion is a decision, not an
oversight.

### A.5 The endpoint and the node payload

**Route.** One line, in the existing admin group:

```php
// hr-backend/routes/api.php — beside the other Map reads (:125-127)
Route::get('/knowledge-graph', [KnowledgeGraphController::class, 'index']);
```

**Gate: the admin group only — `['auth:sanctum', 'admin', 'active']`
(`routes/api.php:64`), with no additional `ability:` middleware.** That is the
Map's current gating, stated in the code: *"Sprint 3 — Knowledge Center. READS
are open to any admin (an auditor browses + inspects + runs the read-only
sandbox). WRITES are gated by the knowledge.edit ability"*
(`routes/api.php:119-127`). `/hierarchy`, `/hierarchy/children` and
`/coverage-gaps` all sit there ungated. The graph is the same read of the same
structure, so it gets the same gate, and spec §6.6's "gating consistent with the
rest of Map" is satisfied by construction rather than by a new rule.

Considered and rejected: `coverage.view` (the `analytics.view OR knowledge.edit`
alias, `app/Http/Middleware/EnsureCanViewCoverage.php:23-24`, used by the
Cobertura routes at `routes/api.php:290-294`). It would be *stricter than the
screen the feature lives on* — an auditor can already see every node and edge of
this same data through `/hierarchy`, so gating the graph harder would hide a
picture of data the same user can already enumerate. Spec §4 also says "no new
permissions".

**Controller.** `app/Http/Controllers/Admin/KnowledgeGraphController.php`, one
`index` method. It runs the queries and hands rows to the pure builder (§C.2).
Plain `response()->json()` with arrays — no API Resource classes, matching
`HierarchyController` and `ReferenceFactController` (the codebase has no Resource
layer).

**Node id grammar — reuse `HierarchyController`'s, exactly.** That controller
already defines an opaque key grammar (`HierarchyController.php:24-28`):
`c:{id}`, `doc:{uuid}`, `fact:{uuid}`, `t:{territoryId}`, `s:{sectorId}`,
`tp:{topicId}`. Reusing it means the two Map surfaces name the same thing the
same way, and a future "focus this node in the hierarchy" is a string pass.

**Payload.**

```jsonc
{
  "generated_at": "2026-09-22T05:00:00Z",
  "counts": {
    "nodes": 261,
    "edges": 421,
    "hidden": {                      // never silently dropped — the caption renders these
      "documents_orphan": 40,        // no convenio and no drawn topic
      "facts_rejected": 5,
      "territories_folded": 7,       // singleton → count on the convenio
      "sectors_folded": 13,
      "topics_folded": 2,
      "topics_empty": 2
    }
  },
  "nodes": [
    {
      "id": "c:18",
      "type": "convenio",            // convenio | document | fact | territory | sector | topic
      "label": "ACCIÓN E INTERVENCIÓN SOCIAL",
      "state": "scope",              // scope | active | draft | historical | verified | unverified_ai
      "degree": 19,                  // drives node size; computed server-side
      "counts": { "documents": 7, "facts": 12 },
      "folded": { "territory": "Navarra", "sector": null },  // singleton hubs, as text
      "link": "#view=coverage&convenio=18"                   // built by AdminLinks
    },
    {
      "id": "fact:9f3c…",
      "type": "fact",
      "label": "Periodo de prueba — Grupo 1",
      "state": "unverified_ai",
      "degree": 2,
      "counts": {},
      "source_document": { "id": 106, "title": "PERÍODOS PRUEBA ACTUALIZADOS 2026" },
      "link": "#view=review&tab=reference-facts&fact=9f3c…"
    }
  ],
  "edges": [
    { "source": "doc:7b1e…", "target": "c:18", "kind": "document_convenio", "provenance": "system" },
    { "source": "doc:7b1e…", "target": "tp:2",  "kind": "document_topic",   "provenance": "unverified_ai" }
  ]
}
```

Notes on the shape:

- **`state` is a closed enum and is computed, not raw.** The precedence order is
  fixed and tested (§C.2): `unverified_ai` > `historical` > `draft` > `active`
  for documents; `unverified_ai` > `verified` for facts; `scope` for convenios and
  hubs. This matters because 5 of the 8 documents carrying an unverified AI facet
  are *also* historical or verified — without a stated precedence, two correct
  implementations would colour them differently.
- **`edges[].provenance`** is `unverified_ai` only when that specific binding is
  an unverified `ai_agent` proposal (`document_topics.source = 'ai_agent' AND
  verified_by IS NULL`) — 27 of the 30 drawn document→topic edges. This is
  ADR-0020's "AI-proposed *facets*" signal living on the facet, which is where the
  proposal actually is.
- **No chunk text, no employee data, no PII** (spec §3). The payload carries ids,
  labels, states, counts and a hash link. Enforced by a feature test that asserts
  the response body contains no key from a deny-list and no `employees`/`chunks`
  join.
- **Deterministic order.** `nodes` sorted by `(type_rank, id)`, `edges` by
  `(kind, source, target)`. The order is part of the contract, because it is what
  the layout seed is keyed on (§C.1). Tested.
- **Size.** ≈261 × ~180 B + 421 × ~70 B ≈ **76 KB** of JSON, ~10 KB gzipped —
  one request, no pagination, no caching layer needed. (Estimate from the field
  set above; confirm at Step 3.)
- **Filters are not query params.** Chips filter client-side over the loaded graph
  (§D.3), so there is one request per view open and hiding never re-runs the
  layout.

---

## B. The library, verified

### B.1 Versions, license, real bundle sizes

Installed into a scratch directory (`/tmp/s11c-probe`, outside both repos) and
built with Vite. Sizes are the actual emitted chunk, `gzip -9`:

| Package | Version | License |
|---|---|---|
| `3d-force-graph` | **1.80.0** | MIT |
| `three` | **0.186.0** | MIT |
| `three-forcegraph` | 1.43.4 | MIT |
| `three-render-objects` | 1.42.0 | MIT |
| `d3-force-3d` | 3.0.6 | MIT |
| `kapsule` / `accessor-fn` | 1.16.3 / 1.5.3 | MIT |
| `force-graph` (2D, §B.3) | **1.51.4** | MIT |

All MIT, one dependency tree, one maintainer (vasturiano).

| Measured chunk | minified | **min+gzip** |
|---|---|---|
| `3d-force-graph` + three (one lazy chunk) | 1,398,990 B | **367,083 B (358 KB)** |
| `force-graph` (2D canvas, separate chunk) | 177,292 B | **57,445 B (56 KB)** |
| `three` alone, the subset this stack touches | 530,780 B | 129,591 B |
| `d3-force-3d` alone | 24,651 B | 8,081 B |
| the eager entry whose only job is `import()` | 2,009 B | 1,029 B |

So **R3's real number is 358 KB gzip**, and three.js is the bulk of it — note the
representative `three` subset alone is 130 KB gzip, meaning
`three-render-objects` pulls in a good deal more of three (all three control
types plus the CSS2D/CSS3D renderers) than a hand-written scene would.

**Bundles cleanly, no CDN, no eval — verified in the shipped files, not assumed.**
`grep` over `node_modules/3d-force-graph/dist/3d-force-graph.mjs` and
`node_modules/three/build/three.module.js` finds **zero** occurrences of `eval(`,
`new Function(`, and zero `cdn`/`unpkg`/`jsdelivr` URLs. `three` is a real
`dependency` of `3d-force-graph` (`"three": ">=0.179 <1"`), not a peer dependency
and not an external — npm resolves it, Vite bundles it. The package ships an ESM
entry (`"module": "dist/3d-force-graph.mjs"`) and its own TypeScript
declarations, so no `@types/three` is needed.

Two real constraints found:

- **Pin exact versions.** `three`'s range is `>=0.179 <1`, so an unpinned install
  can float three across minor versions and silently move the 358 KB number. Add
  `3d-force-graph@1.80.0`, `three@0.186.0`, `force-graph@1.51.4` as exact pins and
  commit `package-lock.json`.
- **`tsc -b` must resolve the shipped `.d.ts`,** which imports
  `three/examples/jsm/postprocessing/EffectComposer.js`. `three` ships that file,
  so this should be a no-op — but `build` is `tsc -b && vite build`
  (`hr-frontend/package.json:8`), so a typings resolution failure breaks the build,
  not just the editor. Verify at Step 6, not at Step 11.

### B.2 Lazy-chunk plan and the measured initial-bundle delta

**Baseline, measured now** (`npx vite build --outDir /tmp/hrf-baseline` from
`hr-frontend`, current `HEAD`):

| Asset | raw | gzip |
|---|---|---|
| `index-*.js` | 516,459 B | **145,308 B** |
| `index-*.css` | 40,756 B | 7,470 B |

The app today emits **one** JS chunk: `vite.config.ts` is eight lines with no
`manualChunks` and no build options, and there is **not a single dynamic
`import()` or `React.lazy` anywhere in `src/`** — every admin page is statically
imported in `AdminShell.tsx`. So Grafo introduces the app's first code split.

**Plan:**

1. `const GrafoView = lazy(() => import('./grafo/GrafoView'))` inside
   `KnowledgeMapPage.tsx`, rendered in a `<Suspense>` with a skeleton. Rolldown
   emits `GrafoView-*.js` automatically; no `manualChunks` config needed.
2. `GrafoView` is the only module that imports `3d-force-graph`. Nothing in the
   eager graph may reference it, or the split collapses — guarded by the Step 7
   size check, which is a diff against **145,308** written down above, not a vibe.
3. The 2D renderer is a **second** lazy chunk (`Grafo2D-*.js`, `force-graph`,
   56 KB gzip), imported on first 2D toggle. Consequence worth stating: a machine
   with no WebGL **never downloads the 358 KB chunk at all**, because WebGL is
   detected before the import (§B.4).
4. Expected entry delta: the import stub plus `Suspense` — a few hundred bytes.
   The probe's whole entry, whose only job was one dynamic import, was
   1,029 B gzip. AC5's budget is ±5 KB, so this has an order of magnitude of
   headroom. **Measured for real at Step 7.**

React stays in the entry (it is in the eager graph), so the split does not
duplicate it.

### B.3 R1 — the library's 2D mode vs a hand-rolled 2D: **recommend `force-graph`**

The comparison the spec asks for has an answer that is forced by R4 rather than by
taste, and it took reading the spec's own risk list to see it.

**`3d-force-graph`'s `numDimensions(2)` is disqualified.** It exists
(confirmed in the dist) and costs zero extra bytes, and it does produce a flat
graph. But it is still the same three.js `WebGLRenderer`. Spec §7 R4 resolves the
no-WebGL case as "auto-switch to 2D" — so if 2D is WebGL, the fallback falls back
onto the thing that just failed. The 2D mode has to be a non-WebGL renderer, and
then it also *is* the R4 fallback, which is the tidier design anyway: one 2D
implementation, not two.

One more measured caveat, had we gone that way: `numDimensions`' `onChange`
retunes the charge force ("Increase repulsion on 3D mode for improved spatial
separation", `three-forcegraph` dist ~:7268-7273), so 2D-via-numDimensions is not
a projection of the 3D layout — it re-settles into a different one.

That leaves two real candidates for the 2D mode:

| | `force-graph` (2D canvas) | hand-rolled SVG (~80–120 lines) |
|---|---|---|
| bundle | **+56 KB gzip**, own lazy chunk, paid only when 2D is opened | +8 KB gzip (`d3-force-3d`, since the 3D chunk may never load) |
| code we own | accessor config only | layout call, pan/zoom, hit-testing, label placement, hover/click, and their tests — forever |
| API | **identical accessors** to the 3D lib (`nodeVal`, `nodeColor`, `nodeLabel`, `onNodeClick`, `graphData`) — the node/edge→visual mapping is written **once** and handed to both renderers | a second, parallel mapping |
| look at 261 nodes / 421 edges | canvas; pans and zooms smoothly | 682 SVG elements with per-frame transforms — workable, but the jank risk is real and we'd own it |
| license / maintenance | MIT, same author, same release cadence as the 3D lib — one upgrade story | ours |

**Recommendation: `force-graph`.** 56 KB, paid only by someone who opens 2D or
lacks WebGL, buys us a renderer whose accessor API is the same shape as the 3D
one — which means the honest part of this feature (state→colour, degree→size,
label rules) exists once. The hand-rolled route saves 48 KB and costs a permanent
maintenance surface in exchange.

The honest counter-argument, recorded: the field guide's ~80-line approach is
appealing precisely because it is ours, and 261 nodes is small enough that it
would work. If the preference is owned code over a second dependency, that is a
legitimate call — **OQ-3**.

One shared consequence either way: the codebase's existing hand-rolled SVG graph
(`Hierarchy.tsx:210-311`, cascading columns with an SVG connector overlay,
constants at `:214-218`) **cannot be reused.** It renders a tree from a selected
root path; this graph has no root and a degree-88 hub. It is a different drawing.

### B.4 R2 — label strategy at our scale

Measured label counts for the three candidate rules:

| Rule | Labels on screen |
|---|---|
| everything | **261** |
| hubs only (territory + sector + topic) | **20** |
| hubs + convenios | **46** |
| documents + facts | 215 |

The degree distribution decides it. 88 edges converge on `periodo de prueba`, and
149 of the 261 nodes are facts. Labelling facts persistently means 88 labels
inside one starburst — unreadable at any zoom, and it would bury the structure
the view exists to show.

**Proposal: persistent labels on the 46 scope nodes (26 convenios + 20 hubs);
hover-only for the 215 knowledge nodes.** The 46 are exactly the nodes that carry
`state: "scope"` in the payload, so the label rule and the colour rule read the
same field rather than duplicating a classification.

Mechanics: persistent labels via `CSS2DRenderer` passed through the documented
`extraRenderers` option (present in the shipped `.d.ts`) — real DOM elements, so
46 is comfortable and 261 would not be; hover labels via the built-in
`nodeLabel` accessor, which costs nothing. Spec §3's "labelled nodes simulated
slightly larger than drawn" is implemented as a larger collision/charge radius for
scope nodes, not a larger sphere, so labels get room without the node lying about
its degree.

**No mock, deliberately.** A drawing of 261 nodes would be an illustration of my
guess, not a measurement, and it would invite approval of something that isn't
what ships. The field guide's own rule applies here — a local render lies, so the
place to judge this is CP-1 on staging, with a fallback ladder already agreed:
46 labels → if crowded, 20 (hubs only) → if still crowded, distance-based
(label within *n* units of the camera). Recorded as **OQ-5** so CP-1 has a
decision to make rather than a discussion to start.

### B.5 R4 — WebGL detection and the fallback path

Detection runs **before** the dynamic import, so a machine without WebGL never
downloads three.js:

```ts
// hr-frontend/src/pages/admin/grafo/webgl.ts
export function hasWebGL(): boolean {
  try {
    const c = document.createElement('canvas');
    return Boolean(c.getContext('webgl2') ?? c.getContext('webgl'));
  } catch {
    return false;          // some locked-down/headless setups throw rather than return null
  }
}
```

Path: `hasWebGL()` false → skip `GrafoView`, load the 2D chunk instead, render the
graph flat, and show one honest line above the canvas ("Tu navegador no tiene
WebGL disponible; se muestra la vista 2D."). The 3D/2D toggle shows 2D as active
and 3D as disabled with that reason as its tooltip — the button never lies about
being available. Also handle the runtime case: `webglcontextlost` on the canvas
swaps to the 2D chunk with the same message.

Two notes from this project's own history:

- Staging is **plain HTTP** with no TLS (`deploy.md` §6b). WebGL is *not*
  secure-context-gated, so unlike `crypto.randomUUID()` — which is exactly how
  Sprint 8 shipped a chat that silently never sent (`deploy.md` §6a) — this should
  work on staging. "Should" is why CP-1 is on the deployed host and not localhost.
- The `try/catch` is not defensive padding: the Sprint-8 bug was an uncaught throw
  from a feature-detect that everyone assumed returned a falsy value.

---

## C. Determinism and the honesty guards

### C.1 Where the layout seed lives — and the trap in the defaults

**File:** `hr-frontend/src/pages/admin/grafo/layoutSeed.ts`, one pure function:

```ts
export interface SeededNode { id: string; x: number; y: number; z: number }

/** Golden-angle spiral keyed on the id-sorted index. No randomness, no clock. */
export function seedPositions(ids: readonly string[]): SeededNode[] {
  const sorted = [...ids].sort();                 // sorts internally: input order cannot matter
  const GOLDEN = Math.PI * (3 - Math.sqrt(5));    // 2.39996…
  const YAW = (Math.PI * 20) / (9 + Math.sqrt(221));
  return sorted.map((id, i) => {
    const r = 10 * Math.cbrt(0.5 + i);
    const roll = i * GOLDEN;
    const yaw = i * YAW;
    return { id, x: r * Math.sin(roll) * Math.cos(yaw), y: r * Math.cos(roll), z: r * Math.sin(roll) * Math.sin(yaw) };
  });
}
```

Those coordinates are written onto the node objects before `graphData()`.
`d3-force-3d` honours them: `initializeNodes` only assigns a position when the
existing one is `NaN` (`d3-force-3d/src/simulation.js:85-100`).

**What reading the library actually revealed — two findings that change the work:**

1. **The library is already deterministic in the places you'd worry about.**
   `d3-force-3d`'s simulation creates `random = lcg()` — a fixed-seed linear
   congruential generator, `s = 1` (`src/lcg.js`) — and passes it to every force
   via `force.initialize(nodes, random, nDim)` (`src/simulation.js:36`, `:110`).
   The `|| Math.random` fallbacks that do exist (`src/link.js:98`,
   `src/manyBody.js:117`, `src/collide.js:114`) only apply to a force initialised
   *outside* a simulation, which never happens through the public API.
   Its `initializeNodes` even uses the same golden angle we would
   (`initialAngleRoll = Math.PI * (3 - Math.sqrt(5))`, `src/simulation.js:79-100`).
   So we seed positions explicitly not because the library is random, but so the
   layout stops depending on the library's internal ordering behaviour — our
   guarantee, keyed on our sorted ids.

2. **The defaults are *not* deterministic, and this is the real bug to prevent.**
   `three-forcegraph` ships `cooldownTicks: Infinity` and **`cooldownTime: 15000`**
   (dist ~:7455-7467). Out of the box the simulation stops after **15 wall-clock
   seconds** — so a fast laptop ticks the simulation more times than a slow one
   and settles into a *different* layout. Reload-identical would hold on one
   machine and break across two, which is exactly the kind of thing that passes
   local review and fails spec §6.5. The fix is to make the stop condition a tick
   count:

```ts
// hr-frontend/src/pages/admin/grafo/simConfig.ts — exported so a test can assert it
export const SIM_CONFIG = {
  warmupTicks: 0,
  cooldownTicks: 300,
  cooldownTime: Infinity,   // MUST be Infinity: the default 15000 ms makes layout machine-speed-dependent
  numDimensions: 3,
} as const;
```

Exporting it as a constant rather than chaining literals at the call site is
deliberate: it makes determinism a testable value instead of a comment.

### C.2 The tests that hold the two invariants

**Determinism — `hr-frontend/src/pages/admin/grafo/__tests__/layoutSeed.test.ts`**
(Vitest; the repo's convention is `*.test.ts` co-located or under `__tests__/`,
and there are three existing examples to copy):

1. **No randomness in the module, asserted from the source text.** Read every file
   under `src/pages/admin/grafo/` and assert none matches
   `/Math\.random|crypto\.getRandomValues|Date\.now|new Date\(|performance\.now/`.
   Scoped to our module on purpose: `node_modules` contains `|| Math.random`
   fallbacks (unreachable, see C.1) and the repo contains one legitimate
   `Math.random` in `ChatScreen.tsx:55`. An app-wide grep would fail for reasons
   that have nothing to do with this feature.
2. **Stable seed output** — snapshot `seedPositions()` for a fixed 12-id fixture.
3. **Order-invariance** — shuffle the same ids, assert byte-identical output. This
   is the property that actually matters: it means an endpoint reordering cannot
   move the layout.
4. **`SIM_CONFIG`** — assert `cooldownTime === Infinity` and
   `Number.isFinite(cooldownTicks)`, with the failure message naming the 15 s
   default. A regression here is invisible by inspection.

**Edge honesty — `hr-backend/tests/Unit/KnowledgeGraphBuilderTest.php`**, detailed
in C.3.

### C.3 The edge builder: a pure function, **on the backend**

**`app/Support/KnowledgeGraphBuilder::build(array $rows): array`** — a static pure
function that takes already-fetched rows and returns `['nodes' => …, 'edges' => …,
'counts' => …]`. No DB access inside, so it unit-tests as plain PHPUnit without
`RefreshDatabase` (the pattern of `tests/Unit/GroupCodeNormalizerTest.php`, which
extends `PHPUnit\Framework\TestCase` directly). The controller does the querying;
the builder owns every rule.

**Why the backend, not the frontend** — four reasons, in order of weight:

1. **An invariant enforced in the browser is advice.** Spec §5.1 wants "no other
   edge type can exist". If the rule lives in TypeScript, it holds for our UI and
   for nothing else; a future screen, or `curl`, gets the raw tables and can draw
   whatever it likes. This repo has a name for that position — *"The SERVER is the
   boundary"* (`routes/api.php:108`) — and a stated rule that the UI only hides
   (`api.ts:50-52`).
2. **The sparsity rule is an aggregate over the whole corpus.** Deciding that
   Navarra draws and Huesca folds needs counts across all convenios. Client-side,
   that means shipping the *un-pruned* graph — all 106 documents, 154 facts, 12
   territories, 20 sectors — and pruning in JS: more bytes, and a second place
   that knows the rule.
3. **Precedent.** `HierarchyController` computes its tree server-side at query
   time and never stores it (`HierarchyController.php:20-22`). The graph is the
   same kind of derived structure over the same tables; putting it anywhere else
   would be a new pattern for no gain.
4. **The test form already exists.** Invariants in this codebase are held by
   PHPUnit fixture tests — `Sprint7aTagProposalInvariantTest`,
   `Sprint7b1ReferenceFactInvariantTest`. A builder on the backend inherits that
   habit instead of inventing one.

**Fixture tests (`tests/Unit/KnowledgeGraphBuilderTest.php`, no DB):**

| Test | Asserts |
|---|---|
| `test_builds_the_six_edge_types_from_fixtures` | a hand-built world yields exactly the expected edge list |
| `test_no_edge_kind_outside_the_allowlist_can_be_emitted` | every emitted `kind` ∈ the six; iterate the output rather than spot-check |
| `test_shared_source_document_creates_no_fact_to_document_edge` | two facts on different convenios citing **one** `source_document_id` (the 105/106 shape) produce zero fact↔document edges |
| `test_no_document_to_document_edge_from_predecessor_or_derived_from` | `predecessor_document_id` / `derived_from_document_id` present in the fixture are ignored |
| `test_hub_with_one_attachment_is_folded_not_drawn` | singleton territory/sector/topic absent from `nodes`, present as text on the parent's `folded`, counted in `counts.hidden` |
| `test_hub_with_two_attachments_is_drawn` | the boundary, from the other side |
| `test_orphan_document_is_dropped_and_counted` | no convenio + no drawn topic → absent from `nodes`, `counts.hidden.documents_orphan` incremented |
| `test_rejected_fact_is_dropped_and_counted` | same, for `status = rejected` |
| `test_dev_fixture_convenio_is_excluded` | `numero LIKE 'DEV-FIXTURE-%'` never appears |
| `test_state_precedence_is_unverified_ai_then_historical_then_draft_then_active` | a historical document with an unverified `ai_agent` facet resolves to `unverified_ai` (the real case: documents 47, 68, 69) |
| `test_verified_ai_fact_is_not_unverified_ai` | `status = verified, source = ai_agent` → `verified`. ADR-0020's revert-on-verify rule, and the single easiest thing to get wrong — it is 131 of 154 rows |
| `test_output_order_is_deterministic_and_input_order_independent` | shuffle the input rows, assert identical output |

**Feature tests (`tests/Feature/Sprint11cKnowledgeGraphTest.php`):**

- 200 for `super_admin`, `knowledge_editor`, `hr_agent`, **`auditor`** — the gate is
  the admin group, and the auditor case is the one that proves it (mirrors
  `Sprint8AnalyticsAccessTest`'s role matrix and
  `Sprint7b1ReferenceFactInvariantTest.php:246-257`).
- 401 unauthenticated; 403 for an employee token (`EnsureAdmin`).
- The response carries no chunk text and no employee/PII field (deny-list assertion).
- Counts on a fixture world match the builder's unit expectations exactly — the
  controller wires the right queries to the right rules.
- `nodes` and `edges` arrive in the documented order.

---

## D. Integration

### D.1 The Map today, and where Grafo goes

There is no React Router route for the Map. `App.tsx:22-30` mounts `/admin` →
`AdminShell`, and every admin "screen" is a `view` value in a union
(`AdminShell.tsx:41-54`) selected by state plus `window.location.hash`. The Map is
`view === 'map'` (`AdminShell.tsx:267-272`), nav label **Conocimiento · Mapa**
(`:190-194`), and it is the app's default view (`:131-135`).

Inside, `KnowledgeMapPage.tsx` is:

```
.docs-main
├── .map-toolbar                                  (:48-75)
│   ├── .seg  role=tablist  — lens: Territory | Sector | Validity | Topic   (:49-61)
│   ├── .seg  role=group    — form: Graph | List                            (:62-69)
│   └── [knowledge.edit] "+ New reference fact"                             (:70-74)
├── CoverageGapBar                                (:77, :111-131)
└── .map-canvas → <Hierarchy lens form … />       (:79-88)
+ DocumentDetailPanel / ReferenceFactPanel / ReferenceFactCreatePanel  (:90-106)
```

**Proposal — reuse the existing segmented control, add no new component.** A third
`.seg` group would read as a peer of "lens" and "form", which it isn't. Instead
promote a section switch to the front of the toolbar:

```
.map-toolbar
├── .seg — section: Jerarquía | Grafo        ← new, first
├── (section === 'hierarchy') lens .seg + form .seg   ← unchanged
└── (section === 'grafo')     3D | 2D .seg + filter chips
```

`.seg` / `.seg-btn` / `.is-active` already exist (`index.css:1863-1894`) and are
used identically on Cobertura (`CoveragePage.tsx:64-68`), so this is markup, not
CSS. `CoverageGapBar` stays visible in both sections — it is about the corpus, not
the renderer.

**Deep link — reuse the `tab` key, no parser change.** `parseAdminHash` already
reads `tab` (`adminHash.ts:29`) and the shell already forwards it to
`ReviewQueuePage`. So `#view=map&tab=grafo` works with zero change to
`adminHash.ts`; `AdminShell` passes `hash.tab` into `KnowledgeMapPage` the way it
passes it into Review (`AdminShell.tsx:287`), keyed so a hash change remounts with
the fresh selection (the existing pattern, `:103-112`). Default without a `tab`
stays Jerarquía, so every existing link keeps landing where it does today.

Labels are **"Jerarquía" and "Grafo"** per spec §3. Note the surrounding toolbar is
currently English ("Territory", "Graph", "List") while the nav is Spanish — this
plan does not fix that; 11b will. All new strings go in as plain inline literals
so 11b's extraction finds them, with no local i18n helper invented here.

### D.2 Side card, deep links, colour

**Side card — `GrafoNodeCard`, deliberately thin.** Click focuses the node
(`cameraPosition`) and opens a small card in the same right-hand slot the Map
already uses: name, type, state badge, counts, and the actions below. It is *not* a
new detail panel — for a document or a fact the card's primary action hands off to
the panel that already exists (`setSelected` → `DocumentDetailPanel`,
`setSelectedFact` → `ReferenceFactPanel`, `KnowledgeMapPage.tsx:90-100`). The graph
is a navigator into screens we already have, so it adds one small component rather
than a parallel detail UI.

**Deep links — all four already exist; build them server-side via `AdminLinks`.**
That class exists precisely so there is one place that knows the hash scheme
(`AdminLinks.php:8-15`), so the node payload's `link` comes from it:

| Node | Link | Builder |
|---|---|---|
| convenio | `#view=coverage&convenio={id}` | `AdminLinks::coverage()` `:75-80` |
| convenio (alt action) | `#view=documents&convenio={id}` | `AdminLinks::documents()` `:57-62` → `DocumentsPage` `initialConvenioId` (`DocumentsPage.tsx:47-56`, API param `convenio_id`) |
| fact | `#view=review&tab=reference-facts&fact={uuid}` | `AdminLinks::fact()` `:26-31` |
| document | `#doc={uuid}` (the pre-existing compat form, `DocumentsPage.tsx:27-36`) or in-place panel | — |
| group node (only if OQ-2 goes ahead) | `#view=review&tab=groups&convenio={id}` | `AdminLinks::groups()` `:19-24` |

Spec §6.3's two checks ("convenio → Cobertura works", "fuchsia fact → lands on it
in the Review queue") map to rows 1 and 3.

**Colour mapping — role → token, one mapping for both themes.**

Spec §2 says node colour = state, so **type is carried by size and label, not
hue**. Sizes come from `degree` (spec §3); the 46 scope nodes are the persistently
labelled skeleton and the 215 knowledge nodes hang off it.

| Role / state | Token | Light | Dark | Nodes today |
|---|---|---|---|---|
| scope — convenio, territory, sector, topic | `--accent` | `#2b6565` | `#94b9b8` | 46 |
| verified fact / active document | `--success` | `#2f7a3d` | `#5fd9a0` | 169 (132 + 37) |
| draft document | `--warning` | `#946200` | `#fcb454` | 3 |
| historical document | `--text-faint` | `#788996` | `#667085` | 26 |
| **unverified AI** | **`--provenance-ai`** | `#e879f9` | `#f0abfc` | **22** (17 facts + 5 documents) |
| edges | `--map-edge` | `#8a9997` | `#5c7a78` | 394 |
| edges that *are* an unverified AI facet binding | `--provenance-ai` @ 50% | — | — | 27 |

Token citations: `--provenance-ai` `index.css:47` (light) / `:179` (dark);
`--map-edge` `:71` / `:144`; `--accent` `:13` / `:157`; `--success` `:33` / `:172`;
`--warning` `:31` / `:170`; `--text-faint` `:58` / `:139`.

Four things worth saying out loud:

- **`--map-edge` was built for exactly this.** It exists because SVG connector
  lines reading `--border` were invisible on a dark canvas, and it is documented as
  "sized for a STROKE against `--canvas` specifically" with measured ratios
  (`index.css:62-71`, dark at `:144`). Reusing it means the graph's 421 edges
  inherit a contrast floor someone already argued about.
- **Fuchsia is 22 nodes, not 148.** 131 facts are AI-authored *and* verified, and
  ADR-0020 is explicit: "On verify/approve the live UI reverts to normal styling
  … Never bleeds onto confirmed content" (ADR-0020, Consequences). So they render
  `--success` like any other verified knowledge. This is counter-intuitive enough
  — most of this corpus came from an agent — that it is the first thing to check at
  CP-2 and the subject of an explicit unit test (§C.3).
- **Document fuchsia needs a tighter rule than "has an AI tag".** 8 drawn documents
  carry an unverified `ai_agent` facet, but 3 of them are themselves
  `tagging_status = verified` (documents 18, 50, 85) — painting a verified document
  fuchsia would be the "bleed" ADR-0020 forbids. Rule: a document is
  `unverified_ai` only when it is **still `under_review` AND** carries an
  unverified `ai_agent` facet → **5 documents** (14, 31, 47, 68, 69). The facet-level
  signal lives on the 27 edges instead, which is where the proposal actually is.
- **No hex may appear in the module.** ADR-0012's rule is stated at the top of the
  stylesheet: "Components reference only var(--…) — no raw hex in component rules"
  (`index.css:1-6`). three.js needs numbers, so the Grafo module reads the tokens at
  runtime — `getComputedStyle(document.documentElement).getPropertyValue('--accent')`
  — and rebuilds its colour map when the theme flips. `ThemeProvider` sets
  `data-theme` on `documentElement` (`ThemeProvider.tsx:8-22`), so the module
  subscribes to `useTheme()` and re-reads. That is also what makes spec §6.5's
  "both themes" true of a WebGL canvas, which cannot inherit CSS.
  **`--brand-warm` is not used**: it is restricted to "icon/decoration/background
  only … NEVER used as foreground text" (`index.css:18-21`), and a node sphere is
  close enough to a foreground mark that using it would start an argument the
  feature doesn't need. Whether the dark theme should use it for scope nodes anyway
  is **OQ-4**.

**Caption (spec §2, one line, always visible):** "Verde = conocimiento vigente ·
Ámbar = borrador · Gris = histórico · **Fucsia = IA sin verificar** · 40
documentos sin vínculo y 5 datos rechazados no se dibujan." The hidden counts come
from `counts.hidden`, so the caption cannot drift from the data.

### D.3 Filter chips

Reuse `.chip` / `.chip-x` (`index.css:2100-2124`) exactly as
`DocumentsPage.tsx:146-160` uses them for its convenio filter.

- **By territory** — 5 chips, the drawn hubs (Navarra, Estatal, Gipuzkoa, Álava,
  Vizcaya). Selecting one dims everything not reachable from it.
- **By state** — "Ocultar históricos" (removes 26 documents), "Ocultar IA sin
  verificar" (removes 17 facts + 5 documents + 27 fuchsia edges). These are spec
  §6.4's two checks.

`FilterToolbar` (`src/components/FilterToolbar.tsx`) exists and is used on
Documents/History/Review, but it is a collapsible panel with an active-count badge
— heavier than two toggles and a territory list. Chips inline in the toolbar are
the closer fit; noted so the choice is visible.

**One rule that matters more than the chips: filtering hides, it never
re-lays-out.** Hidden nodes keep their seeded positions and are masked, so
toggling "ocultar históricos" does not reshuffle the graph. Re-running the
simulation on filter change would make the view feel arbitrary and would quietly
break the "reload → identical layout" promise the moment a filter was active.

---

## E. Build plan

### E.1 Ordered steps

Rules first, rendering second, polish third — so that if the sprint is cut short,
what exists is a correct endpoint with tests rather than a pretty picture with
unproven edges.

| # | Step | Repo | Done when |
|---|---|---|---|
| 0 | Pin `3d-force-graph@1.80.0`, `three@0.186.0`, `force-graph@1.51.4` exactly; commit the lock. Record the baseline entry size (**145,308 B gzip**, §B.2) | frontend | `npm ci` reproduces; baseline written into this plan |
| 1 | `KnowledgeGraphBuilder::build()` — pure, all rules, no DB | backend | the 12 unit tests in §C.3 pass |
| 2 | `KnowledgeGraphController` + route + `AdminLinks` links | backend | the feature tests in §C.3 pass |
| 3 | **Cross-check against staging:** the endpoint's `counts` must equal `measure-graph.php`'s — **261 nodes / 421 edges**, hidden 40/5/7/13/2/2 | both | numbers match exactly; any gap is a builder bug, not a rounding difference |
| 4 | `layoutSeed.ts` + `simConfig.ts` + their tests. No rendering yet | frontend | the 4 determinism tests in §C.2 pass |
| 5 | `graphColors.ts` — reads CSS tokens at runtime, re-reads on theme flip | frontend | test: every `state` maps to a token; no hex literal in the module |
| 6 | `GrafoView` (3D), lazy chunk, `Suspense`, WebGL detect before import, Jerarquía\|Grafo `.seg`, `#view=map&tab=grafo` | frontend | renders locally; `tsc -b` clean (watch the `.d.ts` → `EffectComposer` path, §B.1) |
| 7 | **Measure the bundle:** entry gzip vs 145,308 (AC5 ±5 KB); record both chunk sizes | frontend | delta inside budget, numbers written into `review.md` |
| 8 | Deploy to staging | — | **⏸ CP-1** |
| 9 | Polish: side card, deep links, filter chips (hide-not-relayout), caption, degree sizing, hover labels, persistent scope labels | frontend | spec §3 and §6.3–§6.4 exercised by hand |
| 10 | 2D chunk (`force-graph`), 3D\|2D toggle, WebGL fallback + auto-switch + message | frontend | 2D renders with WebGL disabled in the browser |
| 11 | Full suites (backend PHPUnit + frontend Vitest), then deploy | both | green; then **⏸ CP-2** |

### E.2 Test inventory

Backend — `tests/Unit/KnowledgeGraphBuilderTest.php` (12 cases, §C.3),
`tests/Feature/Sprint11cKnowledgeGraphTest.php` (role matrix incl. auditor, 401/403,
no-PII deny-list, counts, order).
Frontend — `layoutSeed.test.ts` (no-randomness source assertion, seed snapshot,
shuffle-invariance, `SIM_CONFIG`), `graphColors.test.ts` (state→token totality, no
hex), `KnowledgeMapPage` section-toggle test (`#view=map&tab=grafo` lands on Grafo;
no `tab` still lands on Jerarquía) following `AdminShellNav.test.tsx`'s pattern.

Nothing in this sprint touches the answer loop, `hr-ai`, or any write path: the
whole feature is one `GET` of `SELECT`s plus a renderer (AC7).

### E.3 ⏸ CP-1 — first working render on staging (before any polish)

After Step 8. Both modes, both themes, on the deployed host — not localhost, per
the field guide's rule and `deploy.md` §6a, which exists because a
browser-only failure survived every prior sprint's review.

What I need from Pedram, with the specific decisions attached:

1. **The starburst.** `periodo de prueba` has degree 88 (21% of all edges) and its
   cause — documents 105/106 — is invisible under spec-strict rules. Does the
   picture read as honest, or does it need **OQ-1**?
2. **Labels:** 46 persistent (scope) vs 20 (hubs only) vs distance-based — **OQ-5**.
3. **Scope colour:** `--accent` in both themes, or `--brand-warm` in dark —
   **OQ-4**.
4. **Light theme at all.** Spec §3 says "dark canvas shines here". Light is the
   default theme (`ThemeProvider.tsx:8-22` follows `prefers-color-scheme`), so it has
   to be acceptable, and this is where it gets judged.
5. **2D readability** at 261 nodes / 421 edges.
6. Reload → identical layout, on this machine and ideally on a second one (the
   `cooldownTime` trap in §C.1 is precisely a two-machine bug).

### E.4 ⏸ CP-2 — final eyes-on (spec §6)

All six spec §6 items, plus: fuchsia appears on exactly 22 nodes and 27 edges and
nowhere else; the caption's hidden counts match `counts.hidden`; the 358 KB chunk
does not load until Grafo is opened (Network tab); a limited-role login (auditor)
sees the graph exactly as `/hierarchy` allows.

### E.5 Open questions — resolved 2026-09-22, see the Decisions table at the top of this document.

Four of the seven still leave something for CP-1 to look at on screen rather than
decide in the abstract: OQ-1 (is the starburst legible without the 7th edge?),
OQ-4 (does `--accent` read well as the scope colour on the real dark canvas?),
OQ-5 (is 46 labels crowded in practice?), and the light theme generally (spec §3
says "dark canvas shines here" — light is still the default theme and has to
hold up). See §E.3.

### E.6 Artifacts left in this folder

`measure-graph.php` + `run-measure-graph.sh` — the read-only measurement behind
every number in §A, kept so the plan is reproducible rather than assertive
(ADR-0030). They are measurement, not feature code; delete them if you'd rather
the sprint folder held only documents.

---

**STOP — plan gate.** No code written, nothing committed. Awaiting review.







