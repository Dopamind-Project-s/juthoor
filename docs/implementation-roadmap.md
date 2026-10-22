# Juthoor — Implementation Roadmap & Phase 1 Backlog

Planning phases below map onto the ten-step roadmap in v2.0 chapter 22. Deferred requirements stay in scope; see `docs/project-audit.md` for statuses.

## Phases

| Phase | Objective | v2.0 roadmap steps | Requirements | Depends on | Exit criteria |
|---|---|---|---|---|---|
| 0 — Audit & stabilization | Verified baseline, traceability, green suite on SQLite + PostgreSQL | 1 | — | — | ✅ Done 2026-10-22: 194/194 green on both engines, CI workflow added |
| 1 — Family Tree MVP | Database-backed public tree, person/relationship/branch management, publication governance, family dashboard | 1, 3 (partial) | JTH-01–03, 07, 13, 14; UC-01–05, 07–09 | 0 | Definition of Done below |
| 2 — Genealogy governance | Assertions & competing narratives, field-level privacy, merge with undo, revert-any-change, mothers/spouses/events, GEDCOM, branch-scoped roles, notifications | 2, 8 | JTH-06, 08–13, 28 | 1 | UC-06, UC-10, UC-12 fully met; no leak via search/API/export |
| 3 — Hierarchical identity & community pages | Entity pages per level, hierarchical filtering (direct/with descendants), counters with coverage, regions, diwans + Maps, personalities with disclaimer, archive | 4, 9 | JTH-04, 15–20, 31–35 | 1, 2 (privacy) | UC-13, 14, 19–22 |
| 4 — Community & extended | News, deaths/condolences, celebrations, events, optional Facebook, member directory & professional profiles | 5, 6, 7 | JTH-21–27 | 3 (audience scopes) | UC-15–18 |
| 5 — Production readiness | Backups + restore drill, monitoring/error reporting, security headers, 2FA, load tests, caching after measurement | 10 | JTH-29, 30 | continuous | Public-release criteria in v2.0 chapter 22 |

## Phase 1 Definition of Done (status 2026-10-22)

- [x] A public visitor opens and navigates a real database-backed tree (tests + Chrome E2E).
- [x] Existing genealogy records render (both seed paths load and render; HTTP checks; 0 duplicate nodes).
- [x] Authorized users add people (admin and editor flows, E2E).
- [x] Authorized users edit people.
- [x] Authorized users manage valid relationships (integrity tests: cycle, self-parent, second father).
- [x] Changes reach the tree only under the publication rules (`PublicationGuard`, UC-07).
- [x] Family administrators manage their family scope (isolation tests).
- [x] Fakhd and branch views work (types, nesting, focus, derived membership).
- [x] Shared ancestors are not duplicated within a tree (focus de-duplication test; Qawasmeh reuses anchors).
- [x] Integrity constraints enforced.
- [x] Permissions enforced.
- [x] Family dashboard gives access to the management functions.
- [ ] **Approved seed data loads together** — blocked by D-01 (ذبيان / ذيبان anchor conflict). Each dataset is intact and idempotent on its own.
- [x] Automated tests pass (194/194, SQLite and PostgreSQL).
- [x] Core journeys verified end to end (Chrome over the DevTools protocol).
- [ ] **One public visibility rule** — D-03 open.
- [x] Documentation reflects the actual state.

Phase 1 is **not complete** until P1-07 and P1-20 close.

## Phase 1 backlog

Legend: [x] completed and verified · [ ] open · [~] partial · [!] blocked.

### 1. Database & data integrity
- [x] **P1-01 Audit action length** — `audit_logs.action` 24 → 64 chars (migration `2026_10_22_073526_widen_audit_log_action_column`). *Acceptance:* Qawasmeh import succeeds on PostgreSQL. *Evidence:* PostgreSQL suite green.
- [x] **P1-02 Deterministic branch members** — `BranchMembershipService::members()` ordered by id. *Evidence:* `ConnectedGenealogyWorkflowTest` green on PostgreSQL.
- [x] **P1-03 Hierarchy types** — `Branch::TYPES` (قبيلة، عشيرة، فخذ، فخذ فرعي، فرع / حمولة) in requests, forms, lists and the public selector; default `family_group` keeps existing data. *Tests:* `FamilyTreeMvpTest` (type stored, invalid type rejected, label shown).
- [x] **P1-04 Duplicate confirmation (UC-03)** — same normalized name under the same father (or both fatherless) requires `confirm_distinct`. *Tests:* `FamilyTreeMvpTest`; Chrome E2E. *Remaining:* change-request create path does not run this check yet.

### 2. Genealogy services
- [x] **P1-05 Publication guard (UC-07)** — `PublicationGuard` blocks non-admins from publishing or changing/deleting published people, relationships and branches. Applied in `GenealogyMutationService::apply`, `RelationshipController`, `BranchController`. Editors use pending records or proposals. *Tests:* 4 cases in `FamilyTreeMvpTest`; mutation-checked.
- [x] **P1-06 Scoped shared-ancestry invalidation** — `CrossFamilyLinkInvalidator`. *Tests:* `AdministrationSecurityTest`.
- [ ] **P1-07 Unify public visibility (D-03, D-04)** — one service/scope used by batch, progressive graph, focus, search and person pages; replace the ROOT-001..079 hard-code with an explicit published-narrative flag. *Acceptance:* the shared dataset returns the same people from every public endpoint; existing privacy tests stay green. *Depends on:* product decision on how "submitted narrative" records are published.

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
- [ ] **P1-18 Policy decisions** — (a) adding members by email without consent; (b) family admins using `/site-admin/families/{id}/trees` routes. *Blocked on:* product owner decision.

### 9. Seeders
- [x] **P1-19 Founder badges** — `tribe-founder`, `clan-founder`, `fakhd-founder`, `sub-branch-founder` added idempotently; existing keys unchanged.
- [!] **P1-20 Reconcile ROOT-077 (D-01)** — choose the canonical spelling and chain, record the source, then pass a reviewed anchor reference to `AlQawasmehBranchImporter::import()` or align the base seed. Do not rename by hand. *Acceptance:* `db:seed` followed by `AlQawasmehBranchSeeder` succeeds twice on a fresh database with no duplicate people.
- [x] **P1-21 Seeder idempotency** — both paths seeded twice with identical counts (base 85/84/6/0; shared + Qawasmeh 113/112/1/242).

### 10–12. Tests, E2E, documentation
- [x] **P1-22 Automated tests** — new `FamilyTreeMvpTest` (11 tests); updated tests listed in `docs/development-progress.md`.
- [x] **P1-23 End-to-end checks** — Chrome headless over the DevTools protocol: admin adds child → public person page/search/tree; duplicate warning; editor draft stays private. The repository's Playwright scripts (`tests/Browser`) were not run because Playwright is not installed.
- [x] **P1-24 Documentation** — this file, `docs/project-audit.md`, `docs/development-progress.md`.
