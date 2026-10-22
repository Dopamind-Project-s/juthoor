# Juthoor — Repository Audit & Requirements Traceability

Audit date: 2026-10-22 (updated after Phase 1 finalization). Reference: *Juthoor — Master Project Requirements & Architecture Reference v2.0* (2026-10-22). The reference file is **not in the repository**; it was read from `~/Downloads/Juthoor_Master_Project_Requirements_v2.0.md` on the maintainer's machine. Requirement IDs (JTH-xx, UC-xx, ADR-xxx) below are the reference's own.

Status values: **Implemented** (verified by code + executed tests), **Partial**, **Missing**, **Broken**, **Unverified**. "Implemented" never means "listed in the reference".

## 1. Verified baseline

| Item | Finding |
|---|---|
| Stack | Laravel 13.31, PHP ^8.3 (composer), Pest 4.7, Blade + Bootstrap 5 RTL + vanilla JS (`public/js/genealogy-tree.js`, `public/js/juthoor.js`). No Vite/npm build. |
| Databases | SQLite (tests, local); MySQL per `.env.example` history; PostgreSQL 16 now verified (full suite green). |
| Branch / HEAD | `main` at `a0d25fe`; Phase 1 finalization changes (session 3) are uncommitted. |
| Test baseline (start of session 3) | 194 passed on SQLite (re-verified, not assumed). |
| Test result (end of session 3) | **218 passed, 0 failed, 2402 assertions** on SQLite and on PostgreSQL 16. |
| Environment note | Host PHP builds lack `dom`/`mbstring`/`pdo_sqlite`; all PHP ran in `php:8.3-cli` containers. Laravel Boost MCP did not connect. |

## 2. Implementation inventory (route → controller → service → model → view → policy → tests)

| Module | Evidence | State |
|---|---|---|
| Multi-family & trees | `families`, `family_trees` (primary/active, root), `SelectedFamilyTree`, site-admin family/tree controllers | Implemented |
| People & father edges | `people`, `person_relationships` (father only, `is_primary`), `RelationshipIntegrityService` (self-parent, cycles, second primary father), `ConnectedPersonService` | Implemented (patrilineal only) |
| Traversal | `GenealogyTraversalService` (ancestor path, descendants, side branches) | Implemented |
| Branch / fakhd focus | `branches` (`parent_branch_id`, `root_person_id`, `branch_type`, `membership_mode=descendants`), `BranchIntegrityService`, `BranchMembershipService` (derived membership), `BranchFocusService` (focus/isolated) | Implemented; hierarchy types added this session |
| Public tree | `GenealogyTreeController`, `GenealogyGraphController` (`all=1` batch, progressive, search), `ProgressiveGraphService`, `PublishedTreeTraversal`, `genealogy/tree|person.blade.php`; one rule: `Person::publiclyVisible` / `PersonRelationship::publiclyVisible` / `Branch::published` | Implemented |
| Static Al-Zoubi portal | `routes/web.php` closures over `resources/data/al-zoubi/*.php`; `families/al-zoubi/*` views | Static content; now links to DB branch focus when published |
| Management | `Management\*` controllers, records pages, sections, lookups, family dashboard (`FamilyDashboardController`, `Services/Dashboards/*`) | Implemented |
| Roles | `family_memberships` (reader/editor/admin), `users.is_super_admin`, `FamilyPolicy` (+ `publish`), Person/Branch/Relationship policies, `Gate::before`; membership by invitation (`FamilyInvitationService`); `/site-admin` super-admin only | Implemented |
| Review / audit / revert | `change_requests` + `ChangeReviewService`; `audit_logs` + `AuditLogger`; `AuditReversionService` (latest change only) | Implemented (scope-limited) |
| Sources | `sources`, `citations` (field_key, locator, status), private disk, `SourceController` | Partial vs JTH-08 (no assertion model) |
| Attributes / badges | `person_attributes` (+ family-scoped), assignments, `PersonAttributeSeeder` | Implemented |
| Cross-family links | `cross_family_links`, `CrossFamilyLinkService`, `CrossFamilyLinkInvalidator`, super-admin review | Implemented (see risk R-04) |
| CSV import | `ImportBatch`, `CsvImportService` (dry run, checksum, atomic commit) | Implemented (GEDCOM missing) |
| Ops | `.github/workflows/tests.yml` (SQLite+Pint, PostgreSQL), site health dashboard, rate limits | Partial (no backups/monitoring) |

## 3. Requirements traceability — JTH register

| ID | Requirement | Status | Evidence | Gap | Pri | Phase | Acceptance criteria |
|---|---|---|---|---|---|---|---|
| JTH-01 | Multi-family platform | Implemented | `Family`, memberships, `FamilyDashboardTest`, `AdminFrontendTest` isolation cases | — | P0 | 1 ✓ | Cross-family requests 403/404 (tested) |
| JTH-02 | Unified tree without copies | Partial | Branch focus derives people; Qawasmeh reuses the existing ROOT-077 and `AZ-RAHAYMEH-SULAYMAN` records (`AlZoubiAnchorReconciliationTest`) | Cross-family sharing uses link records, not shared person rows; the user-supplied shared ancestry still differs from the base dataset in 39 names (D-06) | P1 | 2 | Reconciled ancestry with sources; one person row per identity |
| JTH-03 | Fakhd & founders at any depth | Implemented | `BranchIntegrityService`, nested branches, `Branch::TYPES`, `FamilyTreeMvpTest` | — | P0 | 1 ✓ | Nested fakhd focus shows ancestors once (tested) |
| JTH-04 | Independent family portals | Partial | Family admin workspace (done); public portal only static for Al-Zoubi | DB-driven public family portal | P1 | 3 | Each family has a public landing page from DB |
| JTH-05 | Link families at verified common ancestor | Implemented | `CrossFamilyLinkController`, `CrossFamilyLinksTest` | Public page computes on GET (R-04) | P1 | 2 | Validity stored at write time |
| JTH-06 | People, relationships, Arabic names | Partial | Arabic normalization (`ArabicNameNormalizer`), father edges | Mothers, spouses, events, date qualifiers | P1 | 2 | Union/mother model with integrity + privacy |
| JTH-07 | Toggleable attributes, titles, badges | Implemented | `PersonDescriptorsTest`, founder badges added, deactivation test | — | P1 | 1 ✓ | Deactivated badge hidden, assignment kept (tested) |
| JTH-08 | Sources & assertion verification | Partial | `citations.field_key`, statuses, `SourceManagementTest` | Assertion model, competing narratives | P1 | 2 | Per-fact assertions with multiple citations |
| JTH-09 | Review, audit, revert | Partial | `ChangeReviewTest`, `AuditReversionTest`; publication gate (this session) | Revert limited to latest change; no restore of deleted person | P1 | 2 | Revert any entry with conflict check |
| JTH-10 | Duplicate detection & safe merge | Partial | Dashboard indicator, create-time confirmation, CSV similarity | Merge workflow with preview/undo | P1 | 2 | Merge event with before/after and undo |
| JTH-11 | Arabic genealogical search | Partial | Normalized search over names and recorded variants (`alternate_names`), lineage in results; seeded records searchable (D-05 fixed) | Father/fakhd filters, phonetic matching | P1 | 2 | UC-11 filters |
| JTH-12 | GEDCOM import/export | Missing | — | Everything | P2 | 2 | 5.5.1 export with privacy; import via dry run |
| JTH-13 | Central and family roles | Implemented | Policies, `publish` ability, invitation-only enrollment for family admins, super-admin-only `/site-admin`, `AdministrationSecurityTest` | Finer per-module/branch roles | P1 | 2 | Branch/module-scoped permissions |
| JTH-14 | Dashboard per family | Implemented | `/manage/families/{family}/dashboard`, `FamilyDashboardTest` | Settings page | P1 | 1 ✓ | Role-aware, family-scoped (tested) |
| JTH-15 | History, archive, media | Partial | Static history/archive pages; source files | DB archive and media | P2 | 3 | Archive items linked to person/fakhd |
| JTH-16 | Pages for every clan level | Missing | Static Al-Zoubi pages only | Entity pages | P1 | 3 | UC-13 |
| JTH-17 | Counters with scope/coverage | Missing | Admin-only counts | Public counters with disclaimer | P2 | 3 | UC-21 |
| JTH-18 | Personalities, positions, achievements | Partial | Static `prominent-people` page | DB model with sources | P2 | 3 | UC-19 |
| JTH-19 | Residence regions | Missing | — | — | P2 | 3 | — |
| JTH-20 | Diwans & Google Maps | Missing | — | — | P2 | 3 | UC-14 |
| JTH-21 | Hierarchical news | Missing | — | — | P2 | 4 | — |
| JTH-22 | Deaths, burial, condolences | Missing | — | — | P2 | 4 | UC-15 |
| JTH-23 | Weddings, graduations | Missing | — | — | P3 | 4 | — |
| JTH-24 | Events & calendar | Missing | — | — | P2 | 4 | UC-16 |
| JTH-25 | Optional Facebook | Missing | — | — | P3 | 4 | UC-17 |
| JTH-26 | Member directory | Missing | — | — | P2 | 4 | UC-18 |
| JTH-27 | Professional profiles | Missing | — | — | P3 | 4 | — |
| JTH-28 | Field-level voluntary privacy | Missing | Record-level living protection only | Field ACL | P1 | 2 | No leak via search/API/export (UC-12) |
| JTH-29 | Operations, monitoring, backups | Partial | CI workflow, health dashboard, rate limits | Backups, restore drill, monitoring | P1 | 5 | RPO ≤ 24h, RTO ≤ 4h drill |
| JTH-30 | Future extensions | Missing | — | — | P3 | 5 | — |
| JTH-31 | Hierarchical filtering for all sections | Missing | Tree focus only | Content sections | P1 | 3 | UC-20 |
| JTH-32 | Owner / subject / audience separation | Missing | — | — | P1 | 3 | UC-22 |
| JTH-33 | Upper-level content flows down when targeted | Missing | — | — | P2 | 3 | — |
| JTH-34 | No duplicate people/news/diwans in views & counters | Partial | Tree focus de-duplicated (tested) | Content & counters | P1 | 3 | Distinct IDs in aggregates |
| JTH-35 | Personalities fairness disclaimer | Missing | — | — | P2 | 3 | Appendix A text shown |
| JTH-36 | Separate genealogical identity, account, directory | Implemented | `people` vs `users` tables, no coupling | Directory n/a yet | P1 | — ✓ | — |

## 4. Use cases relevant to the tree MVP

| UC | Status | Evidence |
|---|---|---|
| UC-01 View unified tree | Implemented | `FamilyTreeMvpTest`, `GenealogyGraphEndpointTest`, browser E2E |
| UC-02 Focus on fakhd | Implemented | `BranchFocusService`, nested focus test |
| UC-03 Add person (match check first) | Implemented | `DuplicatePersonDetector` on direct creation and on person proposals (submit and review) |
| UC-04 Create fakhd | Implemented | Branch types, derived membership test |
| UC-05 Link two families | Implemented | `CrossFamilyLinksTest` |
| UC-06 Duplicate check & merge | Partial | Detection only |
| UC-07 Propose correction; unapproved not published | Implemented | `PublicationGuard` (covers published narratives), `FamilyTreeMvpTest`, `PublicVisibilityTest` |
| UC-08 Manage members | Implemented | Invitation-only enrollment for family admins, identical replies for registered/unknown emails |
| UC-09 Attributes & badges | Implemented | `PersonDescriptorsTest`, founder badge test |
| UC-10 Import | Partial | CSV, not GEDCOM |
| UC-11 Genealogical search | Partial | Lineage in results; no father/fakhd filters |
| UC-12 Living-person privacy | Partial | One record-level rule across page/graph/focus/search (`PublicVisibilityTest`, 5 roles); no field level; unknown `is_living` is treated as not living |
| UC-13 – UC-22 | Missing | Phases 3–4 |

## 5. Completion dashboard

Method: each of the 36 JTH requirements is counted once. Weighted completion = (Implemented × 1 + Partial × 0.5) ÷ 36.

| Status | Count |
|---|---|
| Implemented | 7 (JTH-01, 03, 05, 07, 13, 14, 36) |
| Partial | 11 (JTH-02, 04, 06, 08, 09, 10, 11, 15, 18, 29, 34) |
| Missing | 18 |
| Broken | 0 |
| Unverified | 0 |
| **Weighted completion** | **(7 + 5.5) / 36 = 34.7 %** |

| Module group | Requirements | Implemented | Partial | Missing |
|---|---|---|---|---|
| Genealogy core (JTH-01–03, 05–07, 10–11, 34, 36) | 10 | 5 | 5 | 0 |
| Governance & data (JTH-08, 09, 12, 13, 28) | 5 | 1 | 2 | 2 |
| Administration (JTH-04, 14) | 2 | 1 | 1 | 0 |
| Community pages & content (JTH-15–27, 31–33, 35) | 17 | 0 | 2 | 15 |
| Operations & future (JTH-29, 30) | 2 | 0 | 1 | 1 |

Phase 1 (tree MVP): UC-01–UC-05 and UC-07–UC-09 implemented; all Phase 1 acceptance criteria met on 2026-10-22 (see roadmap). Overall weighted completion is unchanged because Phase 1 hardened existing requirements rather than adding new ones.

## 6. Known defects, discrepancies and risks

| ID | Type | Description | Status / resolution |
|---|---|---|---|
| D-01 | Data conflict | Base dataset spelled ROOT-077 **ذبيان** and had no ROOT-078; Qawasmeh, shared ancestry and v2.0 use **ذيبان ← سليمان**. | **Resolved** under the 2026-10-22 working decision: ROOT-077 keeps its id, key and `sourced` status; display spelling ذيبان, ذبيان kept in `alternate_names`; audited (`spelling_reconciled`, reason recorded). ROOT-078 is the existing `AZ-RAHAYMEH-SULAYMAN` (only سليمان son of ROOT-077; sources SRC-16/17/18) through an explicit alias with a pending citation — no person created. Not a historical verification. |
| D-02 | Static content | `/al-zoubi/branches/*` show a hard-coded ancestry strip. | Bridged to the DB branch focus; replacement is Phase 3 (JTH-16). |
| D-03 | Inconsistency | Two public rules (`treeVisible` vs `publiclyVisible`) gave different answers per endpoint. | **Resolved**: one rule for people, edges and branches on every public surface. |
| D-04 | Coupling | Hard-coded ROOT-001..079 + source-reference exception in the visibility scopes. | **Resolved**: explicit `public_narrative` flag; migration backfill proven identical on an old-code database (79 people / 78 edges before and after; draft stayed hidden). |
| D-05 | Defect (found in session 3) | `DatabaseSeeder` used `WithoutModelEvents`, so seeded people had no `name_search` (not searchable, invisible to duplicate checks) and seeding skipped model integrity validation. | **Resolved**: trait removed; migration `backfill_people_name_search` repairs existing databases (84 → 0 rows on a real seeded DB). |
| D-06 | Data reconciliation | The user-supplied shared ancestry differs from the curated base dataset in 39 further ROOT names (e.g. ROOT-004 قينان/قيدان, ROOT-063). `db:seed` after the shared seeder is refused with nothing written. | Open — Phase 2 data review with sources; each variant can then be recorded the same way as D-01. |
| R-04 | Performance | Public `/shared-ancestry` recomputes link fingerprints per request (rate-limited). | Open — Phase 2. |
| R-05 | Policy | Family admins enrolled accounts without consent and could probe registration. | **Resolved**: invitation-only for family admins, identical replies; super admins keep direct enrollment. |
| R-06 | Policy | Family admins used `/site-admin/families/{id}/trees*`. | **Resolved**: those routes are super-admin only; family admins use `/manage/families/{family}/trees*`. |
| R-07 | Privacy | `is_living = null` is treated as not living by the public rule (pre-existing; base data relies on it). | Open — Phase 2 field-level privacy (JTH-28) with an explicit backfill of living status. |
