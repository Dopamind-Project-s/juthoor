# Juthoor — Implementation Roadmap & Phase 1 Backlog

Planning phases below map onto the ten-step roadmap in v2.0 chapter 22. Deferred requirements stay in scope; see `docs/project-audit.md` for statuses.

## Phases

| Phase | Objective | v2.0 roadmap steps | Requirements | Depends on | Exit criteria |
|---|---|---|---|---|---|
| 0 — Audit & stabilization | Verified baseline, traceability, green suite on SQLite + PostgreSQL | 1 | — | — | ✅ Done 2026-10-22: 194/194 green on both engines, CI workflow added |
| 1 — Family Tree MVP | Database-backed public tree, person/relationship/branch management, publication governance, family dashboard | 1, 3 (partial) | JTH-01–03, 07, 13, 14; UC-01–05, 07–09 | 0 | ✅ Done 2026-10-22: Definition of Done below |
| 2 — Genealogy governance | Assertions & competing narratives, field-level privacy, merge with undo, revert-any-change, mothers/spouses/events, GEDCOM, branch-scoped roles, notifications | 2, 8 | JTH-06, 08–13, 28 | 1 | UC-06, UC-10, UC-12 fully met; no leak via search/API/export |
| 3 — Hierarchical identity & community pages | Entity pages per level, hierarchical filtering (direct/with descendants), counters with coverage, regions, diwans + Maps, personalities with disclaimer, archive | 4, 9 | JTH-04, 15–20, 31–35 | 1, 2 (privacy) | UC-13, 14, 19–22 |
| 4 — Community & extended | News, deaths/condolences, celebrations, events, optional Facebook, member directory & professional profiles | 5, 6, 7 | JTH-21–27 | 3 (audience scopes) | UC-15–18 |
| 5 — Production readiness | Backups + restore drill, monitoring/error reporting, security headers, 2FA, load tests, caching after measurement | 10 | JTH-29, 30 | continuous | Public-release criteria in v2.0 chapter 22 |

## Phase 1 Definition of Done (verified 2026-10-22)

- [x] A public visitor opens and navigates a real database-backed tree (tests; Chrome on the real seeded data).
- [x] Existing genealogy records render (base + Qawasmeh seed, HTTP and browser checks).
- [x] Authorized users add and edit people; manage valid relationships (integrity tests: cycle, self-parent, second father).
- [x] Changes reach the tree only under the publication rules (`PublicationGuard`, UC-07), including published narratives.
- [x] One public visibility rule on every public endpoint, tested for visitor, reader, editor, family admin and super admin.
- [x] Family administrators manage their family scope; membership only by accepted invitation; `/site-admin` reserved for super admins.
- [x] Fakhd and branch views work (types, nesting, focus, derived membership).
- [x] Shared ancestors are not duplicated (ROOT-077/ROOT-078 reconciled onto existing records; 0 same-name siblings).
- [x] Duplicate detection on direct creation and on person proposals (submit and review).
- [x] Approved seed data loads together: `db:seed` → `AlQawasmehBranchSeeder` succeeds and repeats with identical counts on SQLite and PostgreSQL.
- [x] Automated tests pass: 218/218 on SQLite and PostgreSQL 16.
- [x] Core journeys verified end to end in Chrome (7 journeys, no page errors).
- [x] Documentation reflects the actual state.

**Phase 1 is complete** for the Family Tree MVP scope. Limitations carried into Phase 2 are listed under "Remaining" below and in `docs/project-audit.md` §6 (D-06, R-04, R-07).

## Phase 1 backlog

Legend: [x] completed and verified · [ ] open · [~] partial · [!] blocked.

### 1. Database & data integrity
- [x] **P1-01 Audit action length** — `audit_logs.action` 24 → 64 chars (migration `2026_10_22_073526_widen_audit_log_action_column`). *Acceptance:* Qawasmeh import succeeds on PostgreSQL. *Evidence:* PostgreSQL suite green.
- [x] **P1-02 Deterministic branch members** — `BranchMembershipService::members()` ordered by id. *Evidence:* `ConnectedGenealogyWorkflowTest` green on PostgreSQL.
- [x] **P1-03 Hierarchy types** — `Branch::TYPES` (قبيلة، عشيرة، فخذ، فخذ فرعي، فرع / حمولة) in requests, forms, lists and the public selector; default `family_group` keeps existing data. *Tests:* `FamilyTreeMvpTest` (type stored, invalid type rejected, label shown).
- [x] **P1-04 Duplicate confirmation (UC-03)** — `DuplicatePersonDetector` (normalized names and recorded variants; same father, or no father): direct creation, person proposals at submission (`confirm_distinct`) and at review (reviewer confirmation). Never merges. *Tests:* `FamilyTreeMvpTest`; Chrome E2E. *Limitation:* person proposals carry no father, so they are compared with fatherless records.

### 2. Genealogy services
- [x] **P1-05 Publication guard (UC-07)** — `PublicationGuard` blocks non-admins from publishing or changing/deleting published people, relationships and branches. Applied in `GenealogyMutationService::apply`, `RelationshipController`, `BranchController`. Editors use pending records or proposals. *Tests:* 4 cases in `FamilyTreeMvpTest`; mutation-checked.
- [x] **P1-06 Scoped shared-ancestry invalidation** — `CrossFamilyLinkInvalidator`. *Tests:* `AdministrationSecurityTest`.
- [x] **P1-07 Unify public visibility (D-03, D-04)** — `Person::publiclyVisible`, `PersonRelationship::publiclyVisible`, `Branch::published` used by batch, progressive graph, focus, search, lineage, person and branch pages; `verifiedHistorical` kept for cross-family linking. Hard-coded exception replaced by `public_narrative` (migration `2026_10_22_095033`, backfill proven identical). *Tests:* `PublicVisibilityTest` (10 cases, 5 roles); mutation-checked.

### 3. Backend APIs
- [x] **P1-08 Search lineage (UC-11)** — `lineage` field ("بن الأب بن الجد") in `genealogy.search`; public results name only visible ancestors through verified edges; `text` contract unchanged. *Tests:* `FamilyTreeMvpTest`.
- [x] **P1-09 Rate limits & numeric identifiers** — done in Phase 0. *Tests:* `AdministrationSecurityTest`.

### 4. Public tree UI
- [x] **P1-10 Database tree, focus, ancestors, person pages** — existing; verified by tests, seeded-data HTTP checks and Chrome.
- [x] **P1-11 Static-to-DB bridge** — static Al-Zoubi branch pages link to `genealogy.branch` when the DB branch is published (`Branch::scopePublished`). *Tests:* `FamilyTreeMvpTest`. Full replacement is Phase 3 (JTH-16).

### 5. Person CRUD
- [x] **P1-13 Add/edit person with father, root and founded branch** — existing `ConnectedPersonService`; verified by E2E.

### 6. Branch / fakhd management
- [x] **P1-14 Branch CRUD with founder validation, nesting and derived membership** — existing, plus P1-03.

### 7. Family administration
- [x] **P1-15 Family dashboard and workspace** — Phase 0 deliverable.
- [x] **P1-16 Editors see publication limits in forms** — shared `management/partials/verification-options` disables verified/sourced for non-admins (not on proposal forms).

### 8. Permissions & verification
- [x] **P1-17 Audit detail visibility** — Phase 0 deliverable.
- [x] **P1-18 Membership and site-admin routes** — (a) family admins' "add member" sends an invitation via `FamilyInvitationService`; identical reply for registered and unknown emails; super admins keep direct enrollment. (b) `/site-admin/families/{id}/trees*` super-admin only; family admins use `management.trees.store` / `management.trees.details` (`FamilyTreeManager` shared). *Tests:* `AdministrationSecurityTest` (consent, acceptance, cross-family, disabled family, direct URLs).

### 9. Seeders
- [x] **P1-19 Founder badges** — `tribe-founder`, `clan-founder`, `fakhd-founder`, `sub-branch-founder` added idempotently; existing keys unchanged.
- [x] **P1-20 Reconcile ROOT-077 (D-01)** — in-place display-spelling reconciliation with recorded variant and audit reason; ROOT-078 alias to `AZ-RAHAYMEH-SULAYMAN`; base people seeder no longer overwrites names (refuses unreconciled differences with nothing written). *Tests:* `AlZoubiAnchorReconciliationTest` (7). *Evidence:* seeding orders A/B/C on SQLite and PostgreSQL.
- [x] **P1-21 Seeder idempotency** — base → Qawasmeh twice: 119 people / 118 edges / 6 branches / 86 citations / 167 audits, identical; shared → Qawasmeh twice: 113 / 112 / 1 / 242, identical.
- [x] **P1-25 Seeded search keys (D-05)** — removed `WithoutModelEvents` from `DatabaseSeeder`; `backfill_people_name_search` migration.

### 10–12. Tests, E2E, documentation
- [x] **P1-22 Automated tests** — `FamilyTreeMvpTest` (13), `PublicVisibilityTest` (10), `AlZoubiAnchorReconciliationTest` (7), additions to `AdministrationSecurityTest`; updated tests listed in `docs/development-progress.md`.
- [x] **P1-23 End-to-end checks** — Chrome headless over the DevTools protocol on the real seeded data: public tree + variant search with lineage; admin adds a published child; editor restrictions; reader duplicate proposal; reviewer confirmation; permissions and member invitation; mobile RTL. The repository's Playwright scripts (`tests/Browser`) were not run because Playwright is not installed; Safari/Firefox and real devices were not tested.
- [x] **P1-24 Documentation** — this file, `docs/project-audit.md`, `docs/development-progress.md`.

### Remaining (moved to Phase 2)
- **D-06** reconcile the other 39 shared-ancestry spellings with sources before the shared and base datasets can coexist.
- **R-07** explicit living status and field-level privacy (JTH-28).
- **R-04** store shared-ancestry validity at write time.
- Person proposals with a proposed father; father/fakhd search filters (UC-11).

## Phase 2 — first approved tasks (proposed order)
1. Field-level privacy and living-status backfill (JTH-28, UC-12).
2. Assertions with competing narratives and per-fact citations (JTH-08).
3. Merge workflow with preview and undo, built on `DuplicatePersonDetector` (JTH-10, UC-06).
4. Revert any audit entry; restore a deleted person (JTH-09).
5. Shared-ancestry spelling reconciliation (D-06) and write-time link validity (R-04).
6. Mothers, spouses and events; then GEDCOM 5.5.1 export (JTH-06, JTH-12).
