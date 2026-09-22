# Sprint 11c — Spec: Knowledge graph on the Map (3D, with 2D toggle)

> Save as: `hr-docs/sprints/sprint-11c/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Depends on: 11a (brand tokens, sidebar), ADR-0020 (fuchsia = unverified AI), ADR-0018 (server is the boundary).
> Sprint 11b (translations) runs AFTER this sprint so it extracts 11c's strings too.

---

## 1. Goal

A beautiful, honest visualization of the knowledge base as a graph — a new view inside the **Map** section, default **3D** (`3d-force-graph`), with a **2D toggle** for reading/navigation. It shows, at a glance: what knowledge exists, what governs what, and what state it's in (verified vs unverified-AI vs historical).

## 2. The honesty rules (non-negotiable, from the field guide)

- **Every edge survives one factual sentence.** Edges come only from stated bindings in the database — never similarity, never inference:
  - document → its convenio (`convenio_id` binding)
  - reference fact → its convenio, and fact → its topic
  - convenio → its territory, convenio → its sector
  - (plan may propose fact → group node where a bound `convenio_group_id` exists)
- **Bipartite:** entities connect to hubs only; never document→document or fact→fact.
- **Hubs are sparse:** a territory/sector/topic node is drawn only if ≥2 things attach; singletons become a count on the parent.
- **Provenance on the surface:** node colour = state. Verified facts / active docs → brand tokens; **unverified-AI content → fuchsia** (`--provenance-ai`), same meaning as everywhere; historical → muted. One caption line on the view explains the colour code.
- **Deterministic layout seed** keyed on sorted node ids — two loads look the same; no `Math.random` in the module.

## 3. In scope

- **Data endpoint:** one read-only admin endpoint returning nodes + edges (id, type, label, state, counts), built server-side from the real tables. Ability-gated like the rest of the Map. No chunk text, no employee data — structure only.
- **The view:** a tab/toggle within the Map section — "Jerarquía" (existing) | "Grafo". Inside Grafo: 3D default, 2D toggle (the library supports both renderers; if 2D via the same lib is poor, plan proposes the 2D fallback).
- **Interaction:** hover → label + counts; click → focus the node and show a small side card (name, state, counts) with a deep link to the real screen (document detail, convenio in Cobertura, fact in Review). Filter chips: by territory, by state (show/hide historical, unverified).
- **Visual:** brand tokens for colours (dark canvas shines here), node size by degree (facts/docs count), labelled nodes simulated slightly larger than drawn.
- **Bundle:** `3d-force-graph` + three.js **bundled** (never CDN); lazy-load the Grafo tab's chunk so the admin app's initial load doesn't pay for three.js.
- **Scale check:** ~27 convenios + ~106 docs + ~150 facts + hubs ≈ 300–350 nodes — trivial for the library; the plan confirms real counts.

## 4. Out of scope

- No AI anywhere in this feature. No similarity edges. No employee-facing graph. No graph editing. No new permissions (reuse existing view gating). Translations (11b).

## 5. Acceptance criteria

1. Every edge type maps to a named DB relation, listed in the plan; a unit test builds edges from fixtures and asserts no other edge type can exist.
2. Deterministic: same data → same layout seed; test asserts no `Math.random` in the module and stable seed output.
3. Fuchsia only on unverified-AI nodes; verified/active use brand tokens; caption present.
4. Deep links land on the right detail screens.
5. Lazy chunk: initial admin bundle size unchanged (±5 KB); the Grafo chunk loads on first open.
6. Renders correctly on the deployed host in both themes (the field guide's rule: verify in the real runtime — a local render lies).
7. Full suites green; no answer-loop, no data writes.

## 6. Eyes-on (staging, real browser)

1. Map → Grafo: 3D renders, brand-coloured, readable labels on hover; rotate/zoom smooth.
2. Toggle 2D → same graph, flat, readable.
3. Click a convenio → side card correct counts; deep link to Cobertura works. Click a fuchsia fact → lands on it in the Review queue.
4. Filter: hide historical → old docs disappear; hide unverified → fuchsia disappears.
5. Both themes; reload → identical layout.
6. Open as a role with limited Map access → gating consistent with the rest of Map.

## 7. Risks / plan-gate questions

- **R1:** 2D quality of the same library vs a small hand-rolled 2D (the field-guide 80-line approach) — plan compares and recommends.
- **R2:** label legibility in 3D at ~300 nodes — plan proposes what shows labels when (hover-only vs persistent for hubs).
- **R3:** bundle size of three.js even lazy-loaded — plan reports real numbers.
- **R4:** WebGL on admin laptops — graceful fallback message if WebGL unavailable (auto-switch to 2D).
