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
- **R-04** store shared-ancestry validity at write time.
- Person proposals with a proposed father; father/fakhd search filters (UC-11).

## Phase 2 — first approved tasks (proposed order)
1. ✅ Field-level privacy and living-status backfill (JTH-28, UC-12) — done 2026-10-22 as Phase 2.1, see below.
2. ✅ Assertions with competing narratives and per-fact citations (JTH-08) — done 2026-10-25 as Phase 2.2, see below.
3. ✅ Merge workflow with preview and undo (JTH-10, UC-06) — done 2026-10-25 as Phase 2.3, see below.
4. ✅ Revert audit entries and restore deleted people (JTH-09) — done 2026-10-25 as Phase 2.4, see below.
5. Shared-ancestry spelling reconciliation (D-06 — differences are now recordable as claims) and write-time link validity (R-04).
6. Mothers, spouses and events; then GEDCOM 5.5.1 export (JTH-06, JTH-12).

## Phase 2.1 — Field-level privacy & living status (done 2026-10-22)

- [x] **P2.1-01 Audit** — documented every place unknown meant deceased: `Person::publiclyVisible`/`verifiedHistorical`, `PublicationGuard::isPublic`, `GenealogyMutationService::attributes`, `ConnectedPersonService`, person form checkbox, `CsvImportService` default `'0'`, `TreeRootService` new roots.
- [x] **P2.1-02 Tri-state living status** — strict `is_living = false` in public scopes and the publication guard; no coercion in write paths; tri-state select (`living-status-select` partial) in person and root forms; CSV blank → unknown; `living_status_basis` provenance.
- [x] **P2.1-03 Migration** — `2026_10_22_102302_add_living_status_basis_and_field_visibility_to_people` with `LivingStatusClassifier` (narrow evidence rules); verified on old-code data; rollback/re-apply on PostgreSQL.
- [x] **P2.1-04 Field privacy** — `PersonPrivacy`; `field_visibility`; admin-only privacy controls; editors cannot read restricted values or change levels; proposals cannot set levels.
- [x] **P2.1-05 Enforcement** — public/management person pages, record page, forms, audit log, proposal diffs; JSON endpoints asserted free of sensitive fields.
- [x] **P2.1-06 Dashboard** — `living_legacy` indicator and corrected publication text.
- [x] **P2.1-07 Tests** — `PersonPrivacyTest` (17); existing fixtures that meant "public" now declare deceased explicitly (assertions unchanged).
- [x] **P2.1-08 Verification** — 235/235 SQLite and PostgreSQL; Chrome privacy journeys 6/6.
- Remaining: person-managed consent ("voluntary" privacy), contact/address/photo fields, member directory privacy, export (none exists yet).

## Phase 2.2 — Genealogical assertions, sources & conflicting narratives (done 2026-10-25)

- [x] **P2.2-01 Audit** — citations already scoped facts by `field_key`; review existed only as change requests; no structure for competing claims.
- [x] **P2.2-02 Data model** — `assertions` table; `citations.assertion_id`; `change_requests.assertion_id` (migration `2026_10_25_111923_create_assertions_table`).
- [x] **P2.2-03 Lifecycle** — record / classify / cite / propose (`GenealogyAssertionService`), accept/reject hooked into `ChangeReviewService::decide`; supersession and preservation of replaced values.
- [x] **P2.2-04 Active graph separation** — only accepted change requests change people/father edges; father claims validated (self, cycle, duplicate).
- [x] **P2.2-05 Shared ancestors** — `SharedAncestryScope` + `PublicationGuard`: super admin required; impacted families shown.
- [x] **P2.2-06 Privacy** — `PersonPrivacy::canViewFact`/`redactFactSnapshot`; facts panel, review page, source page (incl. edit form) and audit masking; nothing public.
- [x] **P2.2-07 UI** — facts panel on the management person page (displayed value, claims, evidence, classification, citation, proposal); claim context and competing claims on the review page; claim citations on source pages.
- [x] **P2.2-08 ROOT differences** — `AlZoubiRootVariantSeeder` (opt-in, idempotent): records differences as reviewable claims; nothing reconciled.
- [x] **P2.2-09 Tests & verification** — `GenealogyAssertionTest` (12); 247/247 SQLite and PostgreSQL; Chrome facts journeys 5/5.
- Remaining: mother/spouse/event claims, public display of approved citations, claim-level privacy overrides beyond the person's field levels, notifications to impacted families.

## Phase 2.3 — Safe person merge, preview, impact analysis & undo (done 2026-10-25)

- [x] **P2.3-01 Reference audit** — every FK to `people` and its handling (`docs/project-audit.md` §9).
- [x] **P2.3-02 Data model** — `people.merged_into_id`, global scope, `person_merges` journal.
- [x] **P2.3-03 Candidates** — `MergeCandidateFinder` on the existing duplicate rule; privacy-aware signals.
- [x] **P2.3-04 Preview** — read-only plan with conflicts, references, privacy, publication, impact and blockers.
- [x] **P2.3-05 Execution** — fingerprinted, locked, atomic, re-validated, audited; losing values as superseded claims.
- [x] **P2.3-06 Undo** — journal replay with blockers and a controlled recovery path.
- [x] **P2.3-07 Governance** — family admins only; super admin for shared ancestors and cross-family links.
- [x] **P2.3-08 UI** — candidates, manual selection, preview with decisions, confirmation, operation page with undo, sidebar entry.
- [x] **P2.3-09 Tests** — `PersonMergeTest` (15, mutation-checked); 262/262 SQLite and PostgreSQL; Chrome merge journeys 5/5.
- Remaining: merges across trees/families (refused), merging people with different fathers (must be resolved first), bulk merges, a dedicated "report duplicate" action for readers.

## Phase 2.4 — Audit revert, change recovery & deleted-record restoration (done 2026-10-25)

- [x] **P2.4-00 Baseline** — intermittent `PersonMergeTest` failure traced to a snapshot taken before `updated_at` changes; fixed.
- [x] **P2.4-01 Coverage matrix** — `docs/project-audit.md` §10.
- [x] **P2.4-02 Restoration** — deleted people (with badges, privacy, living status), relationships and branches under their original ids; blockers for missing dependencies, conflicts and integrity; restored unpublished.
- [x] **P2.4-03 Safety** — preview fingerprint, locked transaction, re-validation, scope confirmation for privacy widening/publication, one recovery per event.
- [x] **P2.4-04 Governance** — reviewers only; shared ancestors (including invalidated links) need the super admin.
- [x] **P2.4-05 Specialised paths** — claim decisions via re-proposal of superseded claims; merges via the merge page.
- [x] **P2.4-06 UI** — eligibility badge and reason in audit details, preview with diff and related deletions, confirmation, recovery history page, sidebar entry.
- [x] **P2.4-07 Tests** — `AuditRecoveryTest` (15, mutation-checked); 277/277 SQLite and PostgreSQL; Chrome recovery journeys 4/4.
- Remaining: reverting an older change underneath newer edits (refused by design), restoring deleted sources/citations/memberships, bulk restoration of a deleted person together with its deleted relationships.
