# Sprint 12a — review

> Status: **CLOSED.** Browser review passed. `hr-frontend`, `hr-backend`, and `hr-docs` committed on `sprint-12a`, merged `--no-ff` into `main`, pushed. Close-out is below.

Build is on branch `sprint-12a` in `hr-frontend`, `hr-backend`, and `hr-docs`.

Pedram's wording was applied verbatim. Everything else follows the approved audit, with the corrections in the build prompt (importar, the two gap labels, the single read-only sentence, the zero-chunks sentence, MailHog dev-only).

## Gates

| Check | Result |
| --- | --- |
| `npx tsc -b` | clean |
| `npx vitest run` | **104/104** passed (12 files) |
| R3 hardcoded-string guard | included in that run (`noHardcodedStrings.test.ts`) |
| §2 protected-strings guard | included in that run (`protectedStrings.test.ts`) |

No snapshot files changed. Two fixtures were updated to the new Spanish: `AdminShellNav.test.tsx` expects **Guardarraíles**, `KnowledgeMapPage.test.tsx` looks for the **Territorio** lens.

Local production build: `VITE_API_BASE_URL=/api npx vite build`. `grep localhost:8000 dist/` → no matches. Spanish markers `Brechas de cobertura`, `Insertar nuevo dato`, and `Sin respuesta posible` are in `index-DkZdNZ95.js`.

## Staging injection — did not land

Documented pattern (tar → `scp` → alpine copy into `hr-staging_frontend-dist`). HTTP on `http://52.211.251.235/` answers **200** (Caddy, `Last-Modified: Wed, 23 Sep 2026` — the previous bundle, not this build). SSH to `ubuntu@52.211.251.235:22` timed out twice (`Operation timed out`, including a 20s connect timeout). The volume was not written. The served bundle was **not** checked for the new strings, because this build is not on the box.

Local asset hashes, for the retry:

| File | SHA-256 | Bytes |
| --- | --- | --- |
| `assets/index-DkZdNZ95.js` | `e94039b04d24940045e33f58afccdfcc0edff956b295bf69d0c0e521a4d81861` | 592291 |
| `assets/en-DPSS7sH3.js` | `52a07538403eb3ce055107e8ac9334c31a401e45aac839fd3c7e038b8ee45101` | 53646 |
| `assets/index-v_eXonWT.css` | `26c36c2cb05582e268353ebea8a9990079187342ae7d726492fffc95df31de3a` | 42612 |
| `favicon.svg` | `0b64c2db0848e6e48df97403e921fccb96107b16865eb3ebe2b6a7be896557e6` | diamond mark |

## What changed

- `es.ts`: approved copy, plus **alcance** everywhere that string is chrome. **Status** and **Flags** left in English on column headers and detail field titles.
- `en.ts`: the same ten stale strings, rewritten in English (Sprint 7 / ADR / `TopicLexicon` / Knowledge-Center / `under_review` / `needs_category` + §2.1 removed). The read-only notice is one sentence here too.
- `documentDetail.readOnlySuffix` removed in both dictionaries. The notice is `readOnlyPrefix` alone; the `knowledge.edit` code is no longer in that sentence.
- Login: `BRAND.logo`, `alt={t.brand.productName}`, `.login-logo` at 40px height, width auto. No visible product-name text. The MailHog sentence and `localhost:8025` render only when `import.meta.env.DEV`. The prefix is translated; the MailHog clause stays English.
- `brand.ts` exports `BRAND.icon` from `src/assets/brand/favicon.svg` (the diamond path, its own file, square viewBox). `main.tsx` points `link[rel=icon]` at that export. `public/favicon.svg` is the same mark so the first paint is not the Vite icon. No client name in the path or the alt.

## Alcance — every frontend key now using it

These 45 `es.ts` leaves contain alcance. None of them said alcance before this sprint.

`documentDetail.noConvenioNotice`, `documentDetail.aiTaggingStep2Suffix`, `documentDetail.scopeHeading`, `documentDetail.aiSuggestionsNotChanged`, `documentDetail.scopeAffectingSuffix`, `documentDetail.scopeAffectingModalTitle`, `reviewQueue.facts.introRest`, `reviewQueue.facts.colScope`, `reviewQueue.expiry.noConvenioSuccessionNotice`, `escalationCard.noTopicScopeWarning`, `escalationCard.confirmScopeAriaLabel`, `escalationCard.confirmScopeTitle`, `escalationCard.confirmScopeBody`, `statusLabels.subOutcome.off_domain.router_off_domain`, `statusLabels.subOutcome.off_domain.admin_off_domain`, `statusLabels.subOutcome.quality_sample_wrong.wrong_scope`, `referenceFactPanel.aiProposalNoticeRest`, `referenceFactPanel.versionDuplicatePrefix`, `referenceFactPanel.sourceLineHeading`, `referenceFactPanel.scopeHeading`, `referenceFactPanel.confirmScopeChangeAriaLabel`, `referenceFactPanel.scopeChangeTitle`, `referenceFactPanel.scopeChangeBody`, `qualitySampleQueue.failureKindLabels.wrong_scope`, `guardrailsPage.reasonLabels.off_domain`, `guardrailsPage.blockedTopicsHeading`, `guardrailsPage.kindOffDomainBadge`, `guardrailsPage.offDomainHeading`, `guardrailsPage.offDomainIntro`, `groupsQueue.convenioWideScopeNotice`, `groupsQueue.alsoBindsSuffix`, `referenceFactCreatePanel.derivedScopePrefix`, `factDuplicatePanel.noticeIntro`, `gapMeta.expired_no_successor.hint`, `gapMeta.date_expired_active.hint`, `gapMeta.unscoped.label`, `gapMeta.unscoped.hint`, `gapMeta.UNDER_REVIEW_SCOPE.label`, `gapMeta.UNDER_REVIEW_SCOPE.hint`, `coveragePage.noRegistryHeadingPrefix`, `chat.welcomeText`, `tracePanel.scopeResolvedLabel`, `tracePanel.scopePrefix`, `grafo.stateLabels.scope`, `escalationReasons.labels.off_domain`.

Standing note: `glossary.md`, and a pointer at the end of the Sprint 11b glossary in `sprint-11b/review.md`.

## Backend ámbito

The list below was the first-pass inventory. Those human-visible sentences are now alcance. See “Backend ámbito → alcance” for the lines that changed, what was left, and the stored-history note.

## Ticket — spec citations left in chrome

Out of scope this sprint. Already Spanish, still citing a spec section or an internal field name:

- `qualitySampleQueue.introPart1` (§6.2)
- `qualitySampleQueue.reviewerBarredNotice` (§6.3)
- `analyticsPage.kpiSatisfactionSubSuffix` (§7)
- `analyticsPage.escalationsByFixHeading` (§3)
- `analyticsPage.clusteringHeadingPrefix` (§4)
- `analyticsPage.pathSplitHeading` (`path_split`)
- `analyticsPage.authoritySplitHeading` (`authority_split`)
- `analyticsPage.unansweredRankingHeading` (`escalation_rate`)

## Backend ámbito → alcance

Wording only. Keys, reason codes, field names, and trace identifiers were left as they are (`off_domain`, `fact_ids`, `t:unscoped`, `gap_kind: unscoped`, `field: version`).

| Path | Lines |
| --- | --- |
| `hr-backend/app/Support/EscalationExplainer.php` | 385, 391, 392, 393, 518, 573, 628, 629, 635, 646, 648, 678, 680, 700, 701 |
| `hr-backend/app/Services/ReferenceFactProposalService.php` | 402 |
| `hr-backend/app/Http/Controllers/Admin/EscalationController.php` | 31, 292, 293, 338, 341, 364, 366 |
| `hr-backend/app/Http/Controllers/Admin/HierarchyController.php` | 201 |
| `hr-backend/app/Support/FactGroupBindingPlanner.php` | 159 |
| `hr-backend/app/Http/Controllers/Admin/ConvenioGroupController.php` | 402, 552 |
| `hr-backend/app/Services/SuccessionProposalService.php` | 247 |
| `hr-frontend/src/i18n/backendMessageMap.ts` | 37, 51 |

The eight §2 constants, including `ChatService::FALLBACK_CAVEAT`, do not contain the word. The fixture in `protectedStrings.test.ts` is unchanged and still asserts those exact values are absent from both dictionaries.

No feature test, golden trace, or eval fixture asserts these sentences. Searched `hr-backend/tests` and `hr-frontend`. Historical dumps that still say ámbito (`hr-docs/sprints/sprint-10c/triage/facts-export.json`, `hr-docs/sprints/sprint-07f/eval/facts_file2_staging.json`) are past exports, not pins of the current template, so they were left as recorded.

Left unchanged on purpose:

- `hr-backend/tests/Feature/Sprint7eOcrInvariantTest.php:118` — convenio source text, `Artículo 2. Ámbito de aplicación.`
- `hr-ai/app/providers/claude.py` — model instructions, not a sentence the app renders (lines 88, 536, 628, 663, 665, 676, 997, 1036, 1061, 1064, 1073, 1117, 1162).

Messages already stored keep the wording they were sent with. This pass does not rewrite history, so Historial will show both words for a while. That is expected.

## Suites

| Suite | Result |
| --- | --- |
| `php artisan test` | **739 passed**, 3354 assertions. First attempt failed because nothing was listening on `127.0.0.1:55432`. Re-ran against a local pgvector container on that port. |
| `npx vitest run` | **104/104** |

## SSH — IP drifted, inject not run (superseded — see below)

| | |
| --- | --- |
| This machine's public IP | `149.14.17.47` |
| Port 22 on `sg-0632f8c8fc693b5ce` (`hr-staging-ec2-sg`) | `79.116.174.4/32` only |
| Ports 80 and 443 | `0.0.0.0/0` |

The two addresses differ. That is why SSH timed out and HTTP still answered.

Instance `i-057d55d8dbec6978f` is `running`. System status `ok`, instance status `ok`. EIP `52.211.251.235` (`eipalloc-0d9f3adaea06581d4`) is still associated with that instance.

The security group was not modified that round. Egress IP rotated twice more before landing (`149.14.17.47` → `188.26.212.139` → still not the rule; the SG rule stayed `79.116.174.4/32` through that check). Not retried blindly — reported each time, waited for Pedram to update the rule. Logged as a roadmap ticket (`hr-docs/roadmap.md`, pre-go-live phase): move staging/prod SSH to AWS SSM Session Manager so a rotating egress IP can't cause this again.

## Staging injection — landed

SG rule updated to `188.26.212.139/32`. SSH confirmed (`ssh ... echo SSH_OK`) before touching anything. Frontend rebuilt locally (`VITE_API_BASE_URL=/api npx vite build`) — the main JS chunk is now `index-BY7cgrpJ.js` (hash changed from the previously-recorded `index-DkZdNZ95.js` because `backendMessageMap.ts`, a frontend file, changed during Task 1 after that hash was recorded). `en-DPSS7sH3.js`, `index-v_eXonWT.css`, and `favicon.svg` hashes are unchanged from before.

**Frontend**: tar → `scp` → unpacked into the `hr-staging_frontend-dist` volume via a throwaway `alpine` container (macOS resource-fork `._*` files from the tar cleaned up afterward). **Backend**: `docker cp` of exactly the 7 files Task 1 changed into `hr-staging-hr-backend-1` (`AuthController.php`, `AppServiceProvider.php`, and `Sprint11aStagingOtpInvariantTest.php` were found modified in the working tree too — unrelated to this sprint's ámbito→alcance work, not part of Task 1's file list, so deliberately **not** copied; flagged for Pedram below), then `docker compose restart hr-backend`.

Note on the restart: `docker compose restart` prints warnings about unset `AWS_REGION`/`RDS_ENDPOINT`/etc. because it re-parses the compose file in a shell that never exported those — harmless, since `restart` (unlike `up`) doesn't recreate the container or re-render its config. The real effect worth recording: `docker exec ... env` does **not** show `DB_PASSWORD` (or the other SSM-sourced secrets) because they're `export`ed dynamically by `/entrypoint.sh` inside the PID-1 process at boot, not baked into the image's static env — so any one-off `docker exec` command (tinker included) must re-invoke `/entrypoint.sh <mappings> -- <command>` itself to fetch those secrets again. Not a regression from the restart; just the documented pattern (`entrypoint.sh`'s own docblock says as much) rediscovered the hard way.

### Verification (against the live host, not localhost)

| Check | Result |
| --- | --- |
| `index-BY7cgrpJ.js` SHA-256 | matches local build byte-for-byte (`4db1fc3b89…`), `Content-Type: text/javascript`, `Content-Length: 592291` |
| `en-DPSS7sH3.js` SHA-256 | matches local build (`52a07538…`) |
| `index-v_eXonWT.css` SHA-256 | matches local build (`26c36c2c…`) |
| `favicon.svg` SHA-256 | matches local build (`0b64c2db…`), `Content-Type: image/svg+xml`, 372 bytes — not the SPA-fallback HTML |
| Login logo (`logo-BWde3LIe.svg`) | served, `Content-Type: image/svg+xml`, 4239 bytes, hash matches local build |
| Bundle contains | `Brechas de cobertura`, `+ Insertar nuevo dato de referencia`, `Subir documento`, `Alcance`, `Territorio`, `Añadir tema` — all present, 1 match each |
| `localhost:8000` in bundle | absent |
| Backend files | `alcance` count matches expected: `EscalationExplainer.php` 15, `EscalationController.php` 7 |
| Live escalation explanation | Created one throwaway `off_domain`/`router_off_domain` card via `EscalationExplainer::explain()` (the deployed class), minted a Sanctum token for an existing admin via tinker, `GET /api/admin/escalations/{uuid}` → **200**, `reason_label: "Fuera de alcance"` and `stopped_reason: "…este alcance."` both present. Card deleted and token revoked immediately after (verified: re-calling with the same token now gets 401). No existing data was read/left changed — old cards' stored `explanation_facts` still say ámbito, as expected (Historial note above). |
| All 5 containers | `caddy`, `hr-ai` (healthy), `hr-backend` (healthy, post-restart), `hr-backend-scheduler`, `hr-backend-worker` — all up |

**Flag for Pedram — found, not touched:** `hr-backend`'s working tree also has uncommitted changes to `app/Http/Controllers/AuthController.php`, `app/Providers/AppServiceProvider.php`, and `tests/Feature/Sprint11aStagingOtpInvariantTest.php` (adds an `example.com` fixture domain and an OTP-request rate-limit exemption for the staging fixed-OTP allowlist). Nothing in this sprint's instructions touched that area, so none of those three files were part of the `docker cp` above — staging still runs the old `AuthController`/`AppServiceProvider` behavior. Surfacing this because it's sitting uncommitted in the same repo and wasn't something I introduced this sprint; worth confirming whose work it is and whether it should be committed, discarded, or picked up as its own task.

## Close-out

Browser review passed. The staging OTP-request rate-limit exemption (guide screenshot captures) was committed on `hr-backend` `main` on its own, before the sprint merge: `9df9ca173909c0358b38121d2da9612b9518045e`. Its tests passed first (`Sprint11aStagingOtpInvariantTest`, 13 passed, 32 assertions). The matching go-live checklist sentence is `hr-docs` `612a24d24fdafe60d22a987058a1cdf394ac4603`.

| Repo | sprint-12a | main (`--no-ff`) |
| --- | --- | --- |
| hr-backend | `97de5bd716f0297bcfd11c288d1fbd9d28b0d20b` | `42b1fea609151936d0f80c99c87b0ecd0eacaaed` |
| hr-frontend | `dc2afdaa9185d999767750fc52151a0d8bfe2439` | `bdb0753ab109e8e46fee6e24f125f23ae95a0d02` |
| hr-docs | `1073c47c943bf56a94a0079c43e928125e02c1d6` | `00e50624647925239e4c2b6e62edfe6960a68110` |
| hr-ai | unchanged | `d6b17b2cb429c4de02c864e3caf7c2de88e15ae1` |

Staging checkouts reset (`git checkout -- . && git clean -fdx`; they were already clean). `deploy.sh` with those four SHAs: leak scan clean, `php artisan migrate --force` reported nothing to migrate, health checks green on attempt 3/90. `deploy-run.sh` force-recreated `hr-backend`, `hr-backend-worker`, `hr-ai`, and `caddy` with `vars.sh` exported. Artisan on the recreated container: Laravel 13.16.1, `APP_URL=http://52.211.251.235`, DB host `hr-staging-db.cpsukkwcomk6.eu-west-1.rds.amazonaws.com`. `EscalationExplainer.php` in the container matches the checkout byte-for-byte (md5 `feadb7f565968e1d718d463e4d37b44e`).

Served from that image build, not the earlier injection. Live SHA-256 matches a local `VITE_API_BASE_URL=/api` build of `hr-frontend` `main`:

| Asset | SHA-256 | Served as |
| --- | --- | --- |
| `index-BY7cgrpJ.js` | `4db1fc3b894f4409ab61f5e7e6cfeeb3feff9660dd530b62616fef275f3232de` | `text/javascript`, 592291 bytes |
| `en-DPSS7sH3.js` | `52a07538403eb3ce055107e8ac9334c31a401e45aac839fd3c7e038b8ee45101` | `text/javascript`, 53646 bytes |
| `index-v_eXonWT.css` | `26c36c2cb05582e268353ebea8a9990079187342ae7d726492fffc95df31de3a` | `text/css`, 42612 bytes |
| `favicon.svg` | `0b64c2db0848e6e48df97403e921fccb96107b16865eb3ebe2b6a7be896557e6` | `image/svg+xml`, 372 bytes |
| `logo-BWde3LIe.svg` | `a0b8fc582beeee901537e6e857e523f8481d54a06d6245af6e13b9f53efb950f` | `image/svg+xml`, 4239 bytes |

The bundle contains `Brechas de cobertura`, `+ Insertar nuevo dato de referencia`, `Subir documento`, `Alcance`, `Territorio`, and `Añadir tema`. No `localhost:8000`. A throwaway `off_domain` card from the deployed explainer returned `Fuera de alcance` and `fuera de este alcance`; the card was deleted and the token revoked (401 afterward).

`hr-staging-post-12a` is `available` (50 GB, 2026-09-27 23:43 UTC). No other manual snapshot was outside the keep list, so nothing was deleted.

| Snapshot | Created | Role |
| --- | --- | --- |
| `hr-staging-post-ingest-20260906` | 2026-09-06 | deep anchor |
| `hr-staging-post-10c` | 2026-09-14 | named keep |
| `hr-staging-post-11a` | 2026-09-22 04:59 UTC | named keep |
| `hr-staging-post-11c` | 2026-09-22 18:12 UTC | named keep |
| `hr-staging-post-11b` | 2026-09-23 00:08 UTC | named keep |
| `hr-staging-post-12a` | 2026-09-27 23:43 UTC | this close-out |
