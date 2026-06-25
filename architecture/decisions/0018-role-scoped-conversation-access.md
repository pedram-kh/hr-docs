# ADR-0018 — Role-scoped conversation access (`history.view_all`) + conversation access logging

**Status:** accepted (Sprint 5)

## Context

Employee chat conversations contain special-category personal data (health, family, harassment, disciplinary matters). The platform must let designated oversight roles review conversations — for quality audit, dispute defence, and the flywheel — **without** making every admin a silent reader of everyone's private conversations. Spain's AEPD and the works council (comité de empresa) can gate deployment on exactly this: who can read what, on what basis, and whether each access is accountable.

The substrate was laid earlier and deliberately: chat rows all key back to `employee_id` (data-model §8, "role-scoping-ready: access is a query filter, not a reshape"), roles live in `spatie/laravel-permission`, and Sprint 3/4 established the `EnsureCan` ability-gate pattern (`knowledge.edit`, `escalation.work`). Sprint 4 gave `hr_agent` a **card-scoped** conversation view keyed strictly to `card.chat_session_id` — never a caller-supplied id. Sprint 5 adds the **full-history browser** and must decide how broad conversation access is granted and made accountable.

This sits on the identity/privacy axis beside **ADR-0003** (email OTP), **ADR-0004** (directory is first-class, no HRIS sync), and **ADR-0005** (Sanctum sessions).

## Decision

Three load-bearing rules, true in code and proven by API tests:

1. **Broad conversation access is a deliberately-granted ability held by a designated few — not automatic for any role.** A new `spatie` permission **`history.view_all`** gates the full-history browser/search and every cross-employee conversation read. It is granted to **`super_admin`** and **`auditor`** only, and is **distinct** from `escalation.work`. An `hr_agent` does **not** hold `history.view_all`: they keep **only** the conversations attached to the cards they work (the Sprint-4 card-scoped path, keyed to `card.chat_session_id`, unchanged). A `knowledge_editor` holds neither and has **no** conversation access anywhere — including the card-detail conversation payload, which is tightened this sprint to require `escalation.work` **or** `history.view_all`.

   The access matrix (enforced server-side):

   | Role | Full History | Card-scoped conversation |
   |---|---|---|
   | `super_admin` | yes (read + may act) | yes |
   | `auditor` | yes, read-only | yes (browse only) |
   | `hr_agent` | no (403) | yes — only their cards |
   | `knowledge_editor` | no (403) | no (403) |

   `history.view_all` is a **permission attached to roles**, not a role-name check — so it composes with `EnsureCan`/`$user->can()`, survives role edits, and surfaces in `IdentityPresenter.abilities`. "auditor read-only" needs no separate gate: History endpoints are reads; acting (reply/resolve/convert) routes through the existing `escalation.work`-gated endpoints, which auditor lacks.

2. **Every conversation access is logged — including `super_admin`'s.** An append-only `conversation_access_log` records who viewed whose conversation, when, and how (`conversation_view` on opening a conversation; a lighter `history_search` on running a search). No role is exempt. Search result snippets are kept deliberately brief (a match fragment, not the conversation's substance) so a search listing is a `history_search` event, not per-employee disclosure. This is the accountability safeguard that makes broad oversight defensible; Sprint 9 hardens it (retention, erasure, the audit/reporting layer over this log, AEPD/comité sign-off prep).

3. **The server is the boundary; the UI only hides.** Hiding a nav item is not access control. Every history/conversation/directory/admin endpoint checks its ability on the server and returns a JSON 403 when absent — verified by API tests that hit endpoints directly, not through the UI. Account `status` is enforced at both OTP paths (login refused for an inactive admin or employee) and deactivation revokes outstanding Sanctum tokens, so access ends immediately.

## Consequences

- Conversation oversight is legible and auditable: a fixed set of roles can read history, and every read leaves a record — the two facts AEPD / comité de empresa care about.
- `hr_agent` stays narrowly scoped (card-only), which is the privacy posture architecture §11 always described; `knowledge_editor` truly has no chat access.
- The access log grows unboundedly until Sprint 9 adds retention — accepted for now (it is the safeguard; pruning it prematurely would defeat the purpose).
- A `super_admin` reviewing many conversations generates many log rows (including their own) — intended; no one is above the log.
- This ADR is referenced by Sprint 9 (privacy/GDPR hardening) as the access model it hardens.
