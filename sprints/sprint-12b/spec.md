# Slice 12b — Spec: Demo polish (colleague's review batch)

> Save as: `hr-docs/sprints/sprint-12b/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Origin: internal review by Pedram's colleague before a client demo, 2026-10-01. Presentation only, plus one data operation. Follows 12a's discipline (Pedram's wording wins; `alcance` glossary; `Status`/`Flags` stay English).

---

## 1. Goal

Make the admin app easier to follow in a live demo, without changing any behaviour, permission, or answer. Eight items; one is a staging data operation.

## 2. In scope

| # | Item | Behaviour |
|---|---|---|
| **1** | Hide the **Chunk Health** block | Behind a frontend flag (`VITE_SHOW_CHUNK_HEALTH`, default off). Component stays; nothing deleted. |
| **2** | Clear the escalations board | **Data op on staging, not code.** Scripted bulk-close of every open escalation that originates from eval/test traffic (test accounts, `answer:gate` sessions, CP/eyes-on sessions) with a recorded reason `eval_traffic_pre_pilot`; through the real service method so `tag_events`/audit rows are written; **never delete**. Snapshot first. Report counts before/after; anything that is not provably eval traffic stays open and is listed. |
| **3** | Escalation card: collapsible conversation | The `Conversación` block collapsed by default with a toggle ("Ver conversación completa / Ocultar"); the employee's triggering question stays visible; state remembered per session (localStorage). |
| **4** | Visual separation inside popups/drawers — all sections | Popup/drawer canvas uses a grey surface (`--surface-inset` or a new `--panel-canvas` token), each content block is a white card with its own border/radius and vertical margin; applies to every drawer/modal through the shared panel styles (escalation card, document detail, fact panel, history conversation, employee drawer). Both themes; contrast table for the new pairs. |
| **5** | Historial filters on one line | FilterToolbar: search + the five filters in one row at ≥ 1280 px, wrapping gracefully below; dates as compact inputs. Behaviour of every filter unchanged. |
| **6** | Analítica legends → human phrases | Every chart legend, axis label and table key that currently shows an internal value (`salary_sql`, `prose`, `reference_fact_composition`, `low_confidence`, …) renders through `statusLabels`. New map `analyticsLabels` in both dictionaries (es first, Pedram's phrasing: *salary_sql → "Preguntas sobre el sueldo"*, etc.); the enum guard test extends to it so a new value can't render raw. |
| **7** | Hide **Cobertura** for the demo | Nav item behind a frontend flag (`VITE_SHOW_COVERAGE`, default off for the demo build, on by default in config); route still reachable by hash so nothing breaks; comes back by flipping the flag. |
| **8** | Collapsible sidebar groups | Each of the five groups (Conocimiento, Atención, Análisis, Personas, Gobierno) folds on its header; chevron; state persisted per group in localStorage; the group containing the active page never collapses; keyboard accessible; works with the existing icon-only collapsed sidebar mode and the mobile overlay. Per-role nav snapshot tests updated. |

## 3. Out of scope

Any change to data shown, permissions, outcomes, answer text, the review workflow in Calidad (stays its own page), or the engine. The three explanation items from the review (AI tagging, *Editar etiquetas*, thresholds) are a document, not code.

## 4. Acceptance criteria

1. Items 1, 3, 4, 5, 6, 7, 8 live on staging; both themes; desktop and phone for 3, 4, 8.
2. No raw internal key visible anywhere in Analítica (guard test over the live enum values).
3. Per-role nav snapshots pass with groups collapsible; active-group rule tested.
4. Item 2 report: open escalations before/after, closed count by source, list of anything left open and why; `tag_events` rows written; snapshot id.
5. Full suites green; 12a/13-series goldens untouched (no backend code change except item 2's one-off script, which is not a product path).
6. Eyes-on (§5).

## 5. Eyes-on (Pedram, staging)

1. Sidebar: fold/unfold each group; reload keeps state; navigate into a folded group's page → it opens.
2. Escalaciones: board shows only real/pilot-relevant cards; open one → conversation collapsed, toggle works, blocks visually separated on grey.
3. Historial: filters on one line; open a conversation → same block separation.
4. Analítica: every legend in Spanish prose; Calidad still its own page.
5. Chunk Health and Cobertura absent from the demo build; flip the flags → back.
6. Dark mode pass on 2–4; phone pass on 2 and the sidebar.

## 6. Risks / plan-gate questions

- **R1 (item 2):** the eval-traffic predicate — the plan must show exactly how an escalation is classified as eval (account domain, session label, gate marker) and what remains ambiguous.
- **R2 (item 4):** the grey/white nesting must keep AA contrast in dark mode — new token pairs get a contrast row.
- **R3 (item 8):** interaction with the icon-only sidebar and the mobile overlay — collapsed groups in icon mode should still show their icons (group fold applies to expanded mode only).
- **R4 (item 6):** the full list of internal values that reach Analítica — grep every chart/table, not the known list.
