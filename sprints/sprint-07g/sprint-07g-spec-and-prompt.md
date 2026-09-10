# Sprint 7g — the follow-on that makes Sprint 7 usable (spec + build prompt in one)

> Location: `hr-docs/sprints/sprint-07g/spec.md`. Paste the **Cursor prompt** section into a fresh thread. Branch `sprint-7g` in all four repos; feature gate: review → merge. Size **S–M**. None of this touches the answer loop (golden trace stays green throughout).
> Why: Sprint 7 made the system *correct* — it now escalates in strictly more cases, on purpose, and every one of those turns produces the same neutral "coverage gap" text. 7g makes the escalations *explicable and actionable*, makes the review surfaces usable at real corpus size, and closes three operational traps found along the way. Item 0 is a security fix and goes first.

---

## Cursor prompt

You are in the `hr-platform` workspace; Sprint 7 (7a–7f + Correction-salary-01) is merged on `main` and live on staging. **This is Sprint 7g** — six small, additive items. Work on `sprint-7g`; Item 0 is committed and deployed **first and separately** (it changes the deploy path itself). Nothing here changes when or whether anything escalates, retrieves, synthesises, grounds, or answers; `Sprint7cAdditivityRegressionTest` and the 7c/7f reference-fact suites must stay green after every item.

### Item 0 — Deploy keys, then private repos (security; do this first, on `main`)
- Create **one read-only deploy key per repo** (four keys), add each to its GitHub repo as a read-only deploy key (Pedram pastes the public keys in the console — print them and stop for that). Install the private keys on the staging box under `/opt/hr-staging/keys/` (0600, root-owned or the deploy user), with a `~/.ssh/config` mapping four host aliases (`github-hr-backend` …) to the four keys. Switch `deploy.sh`'s four clone/fetch URLs from anonymous HTTPS to the aliased SSH URLs. **No PAT, ever** (account-wide + write-capable).
- Test: `deploy.sh` succeeds on the current SHAs over SSH. Then **Pedram flips all four repos to private** in the console. Re-run `deploy.sh` — must still succeed. Record in `deploy.md`: the keys, their location, rotation (delete in GitHub → regenerate → replace on box), and that anonymous HTTPS was load-bearing since Sprint 0 and no longer is. Commit on `main`, push, done before anything else.

### Item 1 — Escalation explanations (HR gets the why; employees never do)
- **Employee side:** every escalation, every reason, shows **one fixed neutral message** in chat — *"Un/a compañero/a de Recursos Humanos revisará tu consulta y te responderá."* — replacing the current per-reason copy. No reason codes, no document ids, no hints about scope or coverage. Test: a scan over all reasons asserts the employee text is identical and contains no reason token, id, convenio name, or another person's name.
- **HR side, two layers, stored on the card at creation (additive columns: `explanation_facts` jsonb, `explanation_text`, `fix_action`, `fix_surface`, `fix_link`):**
  - **Deterministic facts** — an `EscalationExplainer` (pure function: reason + trace → structured facts) covering **every** reason/sub-outcome in the live enum + trace: sensitive/other-employee/legal-medical (fired pre-retrieval, never sent to the model); off_domain; explicit_request; low_confidence split by *no/weak retrieval* (Check A — name the scope, say it's a likely coverage gap), *citations failed*, *figure not grounded*, *entailment failed (which claim)*, *aggregation*, *cross_path*; conflict (both figures + sources); salary_coverage_gap split by *no table / category unresolved / future-only*; reference_fact_coverage_gap split by the 7f outcomes — *employee group unknown*, *fact depends on a sub-area (X vs Y) not recorded*, *convenio's group structure not approved*, *only needs_review facts*, *out of validity*; plus the publish-time reasons. Each entry carries: what was asked, what was found (by name), why it stopped, **the fix** (action + surface + a deep link — directory profile `#emp=`, Groups tab, Reference-facts tab, `#doc=`), and what the employee was told. **A guard test fails the build if any enum value lacks an entry.**
  - **AI-written paragraph** — a Haiku call (`ROUTER_MODEL` path, cheap) writes a short, readable HR paragraph **from the structured facts only**, instructed to restate them in plain language and add nothing. A deterministic check confirms every fact value (names, figures, the fix) appears in the paragraph and no number/name outside the facts was introduced; on failure or provider error, the card shows the structured facts rendered as sentences instead. The paragraph is labelled *"Resumen IA"*. **The fix action and link are always the structured ones, never the model's.** Cost logged per card.
- Card header shows the paragraph + a **"Corregir"** button to the fix surface. Backfill existing cards (facts + text). Tests: one per reason/sub-outcome asserting the facts, the fix link, and the employee text; the guard test; the no-new-claims check.

### Item 2 — Review surfaces at real corpus size
- **Pagination + visible totals** on all four Review tabs (AI tagging, Reference facts, Groups, Vocabulary proposals, Expiry) — the Documents-page fix applied (`?page=`, prev/next, "N of M", total always visible; filter/sort reset to page 1). Confirm the backends already paginate; add where they don't (additive).
- **Reference-facts list:** show the **fact id** and the **first line of `source_excerpt`** inline, so a reviewer can identify and sanity-check a fact from the list. Same for the Groups tab's fact lists.
- **Deep links** `#fact=<id>` and `#emp=<uuid>` (the `#doc=` pattern) — needed by Item 1's fix links.

### Item 3 — Checksum-dedupe must not silently re-type a document (F-1)
- Ingesting a file whose checksum matches an existing document must **not** change that document's `document_type`, validity, or convenio without the same **409 `confirm_scope_change`** gate a manual edit requires. Default behaviour on a dedupe hit: report "already exists as document N (type X)" and change nothing; an explicit `--retype`/confirm path applies the change with an `admin_manual` event. Test: re-ingest a salary xlsx as `reference_source` → 409/report, document untouched.

### Item 4 — Binding-aware duplicate detection (F-2)
- `facts:scan-duplicates` compares **bound group nodes** when both facts have bindings (same convenio+topic; same node or ancestor/descendant → candidate version pair; sibling nodes → **not** duplicates), falling back to the token pass only for unbound facts. Test: 44 vs 45 (siblings área 5 / resto áreas) is **not** flagged; the Navarra Intervención Social re-group pair still is. Dry-run on staging; report false positives before/after (expect the five to vanish).

### Item 5 — `otp.sh` (F-3)
- Extract the 6-digit login code, not a Message-ID fragment (anchor on the OTP line/format, newest code for that email). Test on staging.

### Constraints
Additive migrations only (hr-backend); hr-ai unchanged except the Haiku call reuses the existing provider path (no new endpoint needed if the backend calls the provider as the router does — decide and state); no change to any escalation *decision*, gate, or answer; employee text never carries internals; the fix link is never AI-generated. Feature gate: `review.md`, stop before merge (Item 0 is the exception — merged first, separately).

### Docs at close
`architecture.md` (§8 escalation cards gain explanation + fix; the deploy-key note in §2/deploy), `data-model.md` (the four card columns), `deploy.md` (deploy keys + rotation; repos private ✓; the dedupe guard; the Review pagination note), a short **ADR-0029 — Escalation explanations: deterministic facts, AI prose over them, employees never see the reason**. `roadmap.md` (7g DONE). `sprint-07g/review.md` with per-item proof.
