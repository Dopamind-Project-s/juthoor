# Juthoor — Repository Audit & Requirements Traceability

Audit date: 2026-10-26 (updated after Phase 3.7 — media library, heritage archive and Phase 3 closure). Approved priority change (2026-10-26): Phase 2.6 (mothers, spouses, life events), Phase 2.7 (GEDCOM) and Facebook publishing are **deferred, not cancelled**; Phase 3 proceeds now, then Phase 4 without Facebook. Reference: *Juthoor — Master Project Requirements & Architecture Reference v2.0* (2026-10-22). The reference file is **not in the repository**; it was read from `~/Downloads/Juthoor_Master_Project_Requirements_v2.0.md` on the maintainer's machine. Requirement IDs (JTH-xx, UC-xx, ADR-xxx) below are the reference's own.

Status values: **Implemented** (verified by code + executed tests), **Partial**, **Missing**, **Broken**, **Unverified**. "Implemented" never means "listed in the reference".

## 1. Verified baseline

| Item | Finding |
|---|---|
| Stack | Laravel 13.31, PHP ^8.3 (composer), Pest 4.7, Blade + Bootstrap 5 RTL + vanilla JS (`public/js/genealogy-tree.js`, `public/js/juthoor.js`). No Vite/npm build. |
| Databases | SQLite (tests, local); MySQL per `.env.example` history; PostgreSQL 16 now verified (full suite green). |
| Branch / HEAD | `main` at `781eb9c` (Phases 3.2–3.6 committed by the maintainer); Phase 3.7 changes (session 11) are uncommitted. |
| Test baseline (start of session 11) | 333 passed, 0 failed, 3583 assertions (re-run on SQLite before any change). |
| Test result (end of session 11) | **346 passed, 0 failed, 3790 assertions** on SQLite and on PostgreSQL 16; media tests also green with GD. |
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
| Person privacy | `people.is_living` tri-state + `living_status_basis`, `people.field_visibility`, `LivingStatusClassifier`, `PersonPrivacy` (audience, levels, snapshot redaction) used by public/management person pages, forms, audit and proposal views | Implemented for the fields in the schema |
| Assertions (claims) | `assertions` + `citations.assertion_id` + `change_requests.assertion_id`; `GenealogyAssertionService`, `SharedAncestryScope`, `RootVariantImporter`; facts panel on the management person page | Implemented for 7 fact types (see §8) |
| Person merge | `people.merged_into_id` + `Person` global scope `active`; `person_merges` (immutable journal); `PersonMergeService`, `MergeCandidateFinder`; `/manage/families/{family}/merges` | Implemented (see §9) |
| Sources | `sources`, `citations` (field_key, locator, status), private disk, `SourceController` | Partial vs JTH-08 (no assertion model) |
| Attributes / badges | `person_attributes` (+ family-scoped), assignments, `PersonAttributeSeeder` | Implemented |
| Community entities (Phase 3.1) | `community_entities` (type, parent, optional `branch_id`/`family_tree_id`, publication, review date), `community_entity_managers`, `CommunityHierarchy`, `CommunityEntityIntegrityService`, `CommunityEntityAccess`, `CommunityEntityPolicy`; public `/families/{family}/entities[/{slug}]`, admin `/manage/families/{family}/entities` | Implemented (see §12) |
| Entity content (Phases 3.1–3.6) | `entity_content_links` (subject / audience), `EntityContentScope`, `HasCommunityEntities` + `CommunityContent`; modules `entity_narratives`, `community_locations`, `diwans`, `entity_achievements`; evidence `content_citations`; `CommunityContentService`, `CommunityContentController`, review queue | Implemented (see §13) |
| Entity portal (Phases 3.3–3.4) | `CommunityEntityPage`, `CommunityGenealogy` (founder, chain, mini tree, related branches, shared ancestors), `CommunityStatistics` | Implemented (see §13) |
| Media library & archive (Phase 3.7) | private `media` disk, `config/media.php`, `media_items`, `media_attachments`, `archive_records`, `community_entities.cover_media_id`; `MediaStorage`, `MediaService`, `MediaAccess`, `MediaController` (`/media/{uuid}/{variant}`), `media:verify` | Implemented (see §14) |
| Cross-family links | `cross_family_links`, `CrossFamilyLinkService`, `CrossFamilyLinkInvalidator`, super-admin review | Implemented (see risk R-04) |
| CSV import | `ImportBatch`, `CsvImportService` (dry run, checksum, atomic commit) | Implemented (GEDCOM missing) |
| Ops | `.github/workflows/tests.yml` (SQLite+Pint, PostgreSQL), site health dashboard, rate limits | Partial (no backups/monitoring) |

## 3. Requirements traceability — JTH register

| ID | Requirement | Status | Evidence | Gap | Pri | Phase | Acceptance criteria |
|---|---|---|---|---|---|---|---|
| JTH-01 | Multi-family platform | Implemented | `Family`, memberships, `FamilyDashboardTest`, `AdminFrontendTest` isolation cases | — | P0 | 1 ✓ | Cross-family requests 403/404 (tested) |
| JTH-02 | Unified tree without copies | Partial | Branch focus derives people; Qawasmeh reuses the existing ROOT-077 and `AZ-RAHAYMEH-SULAYMAN` records (`AlZoubiAnchorReconciliationTest`) | Cross-family sharing uses link records, not shared person rows; the user-supplied shared ancestry still differs from the base dataset in 39 names (D-06) | P1 | 2 | Reconciled ancestry with sources; one person row per identity |
| JTH-03 | Fakhd & founders at any depth | Implemented | `BranchIntegrityService`, nested branches, `Branch::TYPES`, `FamilyTreeMvpTest` | — | P0 | 1 ✓ | Nested fakhd focus shows ancestors once (tested) |
| JTH-04 | Independent family portals | Partial | Family admin workspace; DB-driven public entity pages per family (`/families/{family}/entities`, Phase 3.1) | Family landing page with sections; static Al-Zoubi portal not yet replaced | P1 | 3 | Each family has a public landing page from DB |
| JTH-05 | Link families at verified common ancestor | Implemented | `CrossFamilyLinkController`, `CrossFamilyLinksTest` | Public page computes on GET (R-04) | P1 | 2 | Validity stored at write time |
| JTH-06 | People, relationships, Arabic names | Partial | Arabic normalization (`ArabicNameNormalizer`), father edges | Mothers, spouses, events, date qualifiers | P1 | 2 | Union/mother model with integrity + privacy |
| JTH-07 | Toggleable attributes, titles, badges | Implemented | `PersonDescriptorsTest`, founder badges added, deactivation test | — | P1 | 1 ✓ | Deactivated badge hidden, assignment kept (tested) |
| JTH-08 | Sources & assertion verification | Partial | `assertions` with competing narratives, multiple citations per claim, review via change requests, history preserved (`GenealogyAssertionTest`, 12) | Mother/spouse and life-event claims (no model yet); positions/achievements (JTH-18) | P1 | 2 | Same assertion model reused for new fact types |
| JTH-09 | Review, audit, revert | Implemented | Revert of created/updated records, restoration of deleted people/relationships/branches under original ids, preview fingerprints, scope confirmation, eligibility in audit details, recovery history, claim re-proposal path (`AuditRecoveryTest` 15, `AuditReversionTest`) | Reverting an older change underneath newer edits (refused by design); restoring source/citation/membership deletions | P1 | 2 ✓ | Met |
| JTH-10 | Duplicate detection & safe merge | Implemented | Candidate review, read-only preview, explicit conflict choices, atomic merge with journal, exact undo with blockers (`PersonMergeTest`, 15; Chrome) | Merges across trees/families (refused by design); bulk merges | P1 | 2 ✓ | Met |
| JTH-11 | Arabic genealogical search | Partial | Normalized search over names and recorded variants (`alternate_names`), lineage in results; seeded records searchable (D-05 fixed) | Father/fakhd filters, phonetic matching | P1 | 2 | UC-11 filters |
| JTH-12 | GEDCOM import/export | Missing | — | Everything | P2 | 2 | 5.5.1 export with privacy; import via dry run |
| JTH-13 | Central and family roles | Implemented | Policies, `publish` ability, invitation-only enrollment for family admins, super-admin-only `/site-admin`, entity-scoped editor/admin roles over an entity subtree (Phase 3.1), `AdministrationSecurityTest`, `CommunityEntityTest` | Per-module roles (news, diwans…) | P1 | 2 | Branch/module-scoped permissions |
| JTH-14 | Dashboard per family | Implemented | `/manage/families/{family}/dashboard`, `FamilyDashboardTest` | Settings page | P1 | 1 ✓ | Role-aware, family-scoped (tested) |
| JTH-15 | History, archive, media | Implemented | Narratives (3.2); heritage archive with categories, provenance, period, contributor, person, rights and attached files; media library with secure delivery (`CommunityMediaTest`, Chrome) | Static Al-Zoubi archive page not migrated | P2 | 3 ✓ | Archive items linked to person/fakhd |
| JTH-16 | Pages for every clan level | Implemented | Portal per level with overview, history, regions, founder and genealogy, statistics, diwans, notable people, sources, sub-entities and review (`CommunityEntityTest`, `CommunityPortalTest`, Chrome) | Static Al-Zoubi pages kept until reviewed content is migrated | P1 | 3 ✓ | UC-13 |
| JTH-17 | Counters with scope/coverage | Implemented | Registered / verified / published / living (members) / branches / father-link coverage with explanations, date and the approved disclaimer; sourced estimate; population coverage reported unknown | — | P2 | 3 ✓ | UC-21 |
| JTH-18 | Personalities, positions, achievements | Implemented | `entity_achievements` on existing people, sources, review, audience, neutral categories, alphabetical order, approved disclaimer | Static `prominent-people` page not migrated | P2 | 3 ✓ | UC-19 |
| JTH-19 | Residence regions | Implemented | `community_locations` (origin / settlement / migration / gathering, historical / current, period, sources), shared between entities | — | P2 | 3 ✓ | — |
| JTH-20 | Diwans & Google Maps | Implemented | Shared diwans, keyless Maps directions, optional Embed key, validated links, consent rules, images from the media library | — | P2 | 3 ✓ | UC-14 |
| JTH-21 | Hierarchical news | Missing | — | — | P2 | 4 | — |
| JTH-22 | Deaths, burial, condolences | Missing | — | — | P2 | 4 | UC-15 |
| JTH-23 | Weddings, graduations | Missing | — | — | P3 | 4 | — |
| JTH-24 | Events & calendar | Missing | — | — | P2 | 4 | UC-16 |
| JTH-25 | Optional Facebook | Missing | — | — | P3 | 4 | UC-17 |
| JTH-26 | Member directory | Missing | — | — | P2 | 4 | UC-18 |
| JTH-27 | Professional profiles | Missing | — | — | P3 | 4 | — |
| JTH-28 | Field-level voluntary privacy | Partial | `PersonPrivacy` levels public / family / restricted on birth/death year and date, gender and notes; default-deny; `PersonPrivacyTest` (17) | Self-managed ("voluntary") consent by the person, member-directory and contact fields (not in schema yet), photos | P1 | 2 | Directory/contact fields reuse the same service when added |
| JTH-29 | Operations, monitoring, backups | Partial | CI workflow, health dashboard, rate limits | Backups, restore drill, monitoring | P1 | 5 | RPO ≤ 24h, RTO ≤ 4h drill |
| JTH-30 | Future extensions | Missing | — | — | P3 | 5 | — |
| JTH-31 | Hierarchical filtering for all sections | Partial | One switch drives every portal section (children, history, regions, diwans, people, founders, statistics) through `EntityContentScope` | Content search, API and export do not exist yet; they must reuse the scope | P1 | 3 | UC-20 |
| JTH-32 | Owner / subject / audience separation | Implemented | Four modules use owner entity + subject / audience links + audience scope; edit rights follow the owner only (tested) | Publication channel (Facebook) deferred | P1 | 3 ✓ | UC-22 |
| JTH-33 | Upper-level content flows down when targeted | Implemented | Audience links reach descendant pages only, never siblings (tested on diwans and narratives) | — | P2 | 3 ✓ | — |
| JTH-34 | No duplicate people/news/diwans in views & counters | Implemented | Distinct record ids in aggregated sections; distinct person ids across overlapping branches in counters (tested) | — | P1 | 3 ✓ | Distinct IDs in aggregates |
| JTH-35 | Personalities fairness disclaimer | Implemented | Appendix A text on the notable-people section; no ranking | — | P2 | 3 ✓ | Appendix A text shown |
| JTH-36 | Separate genealogical identity, account, directory | Implemented | `people` vs `users` tables, no coupling | Directory n/a yet | P1 | — ✓ | — |

## 4. Use cases relevant to the tree MVP

| UC | Status | Evidence |
|---|---|---|
| UC-01 View unified tree | Implemented | `FamilyTreeMvpTest`, `GenealogyGraphEndpointTest`, browser E2E |
| UC-02 Focus on fakhd | Implemented | `BranchFocusService`, nested focus test |
| UC-03 Add person (match check first) | Implemented | `DuplicatePersonDetector` on direct creation and on person proposals (submit and review) |
| UC-04 Create fakhd | Implemented | Branch types, derived membership test |
| UC-05 Link two families | Implemented | `CrossFamilyLinksTest` |
| UC-06 Duplicate check & merge | Implemented | Preview → explicit decisions → atomic merge → audited undo (`PersonMergeTest`) |
| UC-07 Propose correction; unapproved not published | Implemented | `PublicationGuard` (covers published narratives), `FamilyTreeMvpTest`, `PublicVisibilityTest` |
| UC-08 Manage members | Implemented | Invitation-only enrollment for family admins, identical replies for registered/unknown emails |
| UC-09 Attributes & badges | Implemented | `PersonDescriptorsTest`, founder badge test |
| UC-10 Import | Partial | CSV, not GEDCOM |
| UC-11 Genealogical search | Partial | Lineage in results; no father/fakhd filters |
| UC-12 Living-person privacy | Implemented | Unknown status is private; record rule across page/graph/focus/search; field levels enforced server-side on pages, forms, audit and proposal diffs; JSON endpoints carry no sensitive fields (`PersonPrivacyTest`). No export exists yet. |
| UC-13 Create entity page | Implemented | Entity admins add identity, description and links; the page appears in the hierarchy without copies (`CommunityEntityTest`, Chrome) |
| UC-20 Hierarchical filtering | Implemented | One switch for all sections, preserved section anchor, correct results without duplicates and by audience (tests, Chrome) |
| UC-22 Shared content management | Implemented | One diwan / narrative / achievement linked to several entities, edited centrally by its owner (tests, Chrome) |
| UC-14 Add diwan | Implemented | Several diwans per entity and shared diwans; Maps directions; consent rules |
| UC-19 Nominate a personality | Implemented | Position/achievement with source, review and disclaimer |
| UC-21 Fakhd statistics | Implemented | Number with type, date and coverage statement; registered never presented as population |
| UC-15 – UC-18 | Missing | Phase 4 (UC-17 deferred) |

## 5. Completion dashboard

Method: each of the 36 JTH requirements is counted once. Weighted completion = (Implemented × 1 + Partial × 0.5) ÷ 36.

| Status | Count |
|---|---|
| Implemented | 19 (JTH-01, 03, 05, 07, 09, 10, 13, 14, 15, 16, 17, 18, 19, 20, 32, 33, 34, 35, 36) |
| Partial | 8 (JTH-02, 04, 06, 08, 11, 28, 29, 31) |
| Missing | 9 (JTH-12, 21–27, 30) |
| Broken | 0 |
| Unverified | 0 |
| **Weighted completion** | **(19 + 4) / 36 = 63.9 %** |

| Module group | Requirements | Implemented | Partial | Missing |
|---|---|---|---|---|
| Genealogy core (JTH-01–03, 05–07, 10–11, 34, 36) | 10 | 7 | 3 | 0 |
| Governance & data (JTH-08, 09, 12, 13, 28) | 5 | 2 | 2 | 1 |
| Administration (JTH-04, 14) | 2 | 1 | 1 | 0 |
| Community pages & content (JTH-15–27, 31–33, 35) | 17 | 9 | 1 | 7 |
| Operations & future (JTH-29, 30) | 2 | 0 | 1 | 1 |

Phase 1 (tree MVP): UC-01–UC-05 and UC-07–UC-09 implemented; all Phase 1 acceptance criteria met on 2026-10-22 (see roadmap). Overall weighted completion is unchanged because Phase 1 hardened existing requirements rather than adding new ones.

## 6. Known defects, discrepancies and risks

| ID | Type | Description | Status / resolution |
|---|---|---|---|
| D-01 | Data conflict | Base dataset spelled ROOT-077 **ذبيان** and had no ROOT-078; Qawasmeh, shared ancestry and v2.0 use **ذيبان ← سليمان**. | **Resolved** under the 2026-10-22 working decision: ROOT-077 keeps its id, key and `sourced` status; display spelling ذيبان, ذبيان kept in `alternate_names`; audited (`spelling_reconciled`, reason recorded). ROOT-078 is the existing `AZ-RAHAYMEH-SULAYMAN` (only سليمان son of ROOT-077; sources SRC-16/17/18) through an explicit alias with a pending citation — no person created. Not a historical verification. |
| D-02 | Static content | `/al-zoubi/branches/*` show a hard-coded ancestry strip. | Bridged to the DB branch focus. DB entity pages exist since Phase 3.1; migrating the static Al-Zoubi pages onto them is a separate, data-reviewed step (not done, to avoid inventing content). |
| D-03 | Inconsistency | Two public rules (`treeVisible` vs `publiclyVisible`) gave different answers per endpoint. | **Resolved**: one rule for people, edges and branches on every public surface. |
| D-04 | Coupling | Hard-coded ROOT-001..079 + source-reference exception in the visibility scopes. | **Resolved**: explicit `public_narrative` flag; migration backfill proven identical on an old-code database (79 people / 78 edges before and after; draft stayed hidden). |
| D-05 | Defect (found in session 3) | `DatabaseSeeder` used `WithoutModelEvents`, so seeded people had no `name_search` (not searchable, invisible to duplicate checks) and seeding skipped model integrity validation. | **Resolved**: trait removed; migration `backfill_people_name_search` repairs existing databases (84 → 0 rows on a real seeded DB). |
| D-06 | Data reconciliation | The user-supplied shared ancestry differs from the curated base dataset in 39 further ROOT names (e.g. ROOT-004 قينان/قيدان, ROOT-063). `db:seed` after the shared seeder is refused with nothing written. | **Reviewable since Phase 2.5**: `AlZoubiRootVariantSeeder` records each difference as an unclassified claim citing its list; the reconciliation queue (`/manage/families/{family}/reconciliation`, §11) lets reviewers inspect, cite, classify, accept as a spelling, propose a correction, reject with a reason or leave unresolved. All 39 remain open until evidence-based review; nothing is resolved automatically. |
| D-07 | Defect (found in session 8) | `CrossFamilyLinkInvalidator::flush` used `each(fn => current())`; `each()` stops when the callback returns `false`, so after a write only the links up to the first invalid one were re-checked. Previously masked by per-request re-validation on read. | **Resolved**: the callback no longer returns the result; regression test fails on the old code. |
| D-08 | Defect (found in session 8) | Proposing a full-name claim classified as «تهجئة بديلة» would have replaced the displayed name (`full_name_ar`) instead of adding a variant. | **Resolved**: the proposal adds the value to `alternate_names`; identity and displayed name are unchanged (tested). |
| R-04 | Performance | Public `/shared-ancestry` recomputed every approved link's whole-tree fingerprint per request (4.3 s for 2 × 2,000 people and 5 links). | **Resolved in Phase 2.5**: persisted, change-driven validity (§11); steady state runs no fingerprint (0.49 s, dominated by rendering ~10,000 listed descendants). |
| R-05 | Policy | Family admins enrolled accounts without consent and could probe registration. | **Resolved**: invitation-only for family admins, identical replies; super admins keep direct enrollment. |
| R-06 | Policy | Family admins used `/site-admin/families/{id}/trees*`. | **Resolved**: those routes are super-admin only; family admins use `/manage/families/{family}/trees*`. |
| R-07 | Privacy | `is_living = null` was treated as not living (scopes, `PublicationGuard`), forms/CSV/roots coerced unknown to deceased. | **Resolved in Phase 2.1**: tri-state with provenance; narrowly scoped classification (see §7). |

## 7. Privacy model (Phase 2.1)

**Living status** — `people.is_living`: `true` living, `false` deceased, `null` unknown. Unknown is never treated as deceased and is never public. `living_status_basis` records why a value is set:

| Basis | Meaning |
|---|---|
| `recorded` | Chosen by a user (form, proposal, CSV); set automatically when `is_living` changes |
| `death_recorded` | Classified deceased because a death year/date is recorded |
| `historical_record` | Classified deceased because the record is verified/sourced and explicitly classified `historical`, and no birth year/date falls within the last 110 years |
| `legacy_recorded` | Value existed before tri-state support (may be an old checkbox default); listed in the family-dashboard indicator for review |
| `supplied_ancestry` | Shared-ancestry seeder declares the supplied ancestors deceased |

Migration `2026_10_22_102302` marks existing explicit values `legacy_recorded` and classifies unknown records only with the two evidence rules above (`LivingStatusClassifier`). Everything else stays unknown. Proven on a database built with the previous code: 86 → 85 public people; the only removal was a probe born in 1990 with unknown status; nothing gained; all 34 Qawasmeh records stay unknown. The base Al-Zoubi seed applies the same classifier, so fresh installs keep the 85-person public tree.

**Field levels** — `people.field_visibility` (JSON) per field: `public` (anyone), `family` (members of the person's family), `restricted` (family admins and super admins). Defaults (default-deny): birth/death year and date and gender → `family`; notes → `restricted`. A living or unknown person never exposes a field publicly. The audience is derived from authorization (`FamilyPolicy` view/update, `Gate::before`), not from role names; other families get the public audience.

**Enforcement** — `PersonPrivacy` is used by the public person page (public audience, or the member audience in the verified private preview), management person page and record page, the person form (restricted inputs are not rendered and stored values are kept), audit-log and proposal diffs (redacted with the stricter of the snapshot's and the current level; stored history is unchanged). Graph, search, lineage and lookup JSON carry only names and identifiers (asserted by tests). Only family admins and super admins can change `field_visibility`; proposals cannot set it.

**Not in the schema** — contact information, addresses/locations and photos do not exist on people; they must use `PersonPrivacy` when introduced.

## 8. Assertion model (Phase 2.2)

**Tables** — new `assertions` (claim about one person: `fact_type`, `value` or `related_person_id`, `relation`, `state`, `verification_status`, `notes`, `import_key`, creator/reviewer/reason/time). Reused: `citations` (new nullable `assertion_id`, `field_key = assertion`), `sources` (one source cites many claims), `change_requests` (new nullable `assertion_id`), `audit_logs`.

**Fact types** — full name, alternate name/spelling, father, birth year, death year, title, honorific. Mother/spouse and events are not modelled yet.

**Classification (`relation`)** — unclassified, alternate spelling, compatible additional information, conflicting narrative. Unverified = `verification_status pending_review`; disputed = `unresolved` (existing vocabulary). Only family admins may mark a claim `verified`; readers record `pending_review` only.

**Lifecycle (`state`)** — `proposed` (recorded) → via an ordinary change request → accepted: `authoritative` (displayed value) or `alternative` (accepted spelling variant, added to `alternate_names`); rejected: `rejected` with reason. Accepting a claim marks the previous authoritative claim `superseded`; if none existed, the replaced displayed value is preserved as a superseded claim. Rejected/superseded claims are immutable.

**Active genealogy vs claims** — recording, citing and classifying never touch `people` or `person_relationships`. Only an accepted change request (existing `ChangeReviewService::decide` → `GenealogyMutationService::apply`) changes the displayed value or the primary father edge, inside one transaction with the existing integrity checks (self/cycle/duplicate father), publication guard and audit. Father claims are validated against self-reference and cycles at recording time; duplicate claims are refused.

**Shared ancestors** — `SharedAncestryScope` finds families whose approved cross-family link endpoint descends from (or is) the person. `PublicationGuard` then requires a super admin for any change to that person or their father edge (proposal acceptance or direct edit); the proposal page and the facts panel name the impacted families.

**Privacy** — claims about birth/death years follow the person's field level (`PersonPrivacy::canViewFact`); values, notes and evidence of masked claims are hidden in the facts panel, review page, source page (including the citation edit form) and audit log (`redactFactSnapshot`). Public pages, graph and search show no claims or source metadata; an accepted claim reaches the public only through the person record and the existing publication rules.

**Dependencies for the merge workflow** — merges should move `assertions` (person_id/related_person_id) and their citations with the merged person, record a superseded claim for each losing value, and use `SharedAncestryScope` for cross-family impact.

## 9. Person merge (Phase 2.3)

**References audited** (every FK to `people`): father edges and children (`person_relationships.person_id/related_person_id`), tree roots, branch founders, `citations.person_id` and citations on the duplicate's father edge, `person_sources`, `person_attribute_assignments`, `assertions.person_id/related_person_id`, `cross_family_links.left/right_person_id`, pending `change_requests` (target or payload), plus history only (`audit_logs`, `import_batches.created_ids`).

**Data changes** — `people.merged_into_id` (the duplicate is kept; a global scope `active` hides merged people from every query, tree, graph, search and count); `person_merges` (survivor, merged, performer, reason, preview fingerprint, decisions, row-level journal, undo fields). Migration `2026_10_25_114226_create_person_merges_table`.

**Candidates** — `MergeCandidateFinder` reuses the duplicate rule (same normalized name or recorded alternate spelling, same primary father or both without one); signals respect `PersonPrivacy`. Visible to all members; preview/merge/undo are for family admins.

**Preview** (read-only, tested to leave every table unchanged) — field-by-field comparison with outcome (same / keep / fill from duplicate / conflict needing a choice), father comparison, resulting alternate names, badges, stricter field privacy, publication before/after, losing values kept as superseded claims, every redirected or consolidated reference with counts, impacted families and blockers.

**Blockers** — different primary fathers (resolve through claims and review first), one being an ancestor of the other (self/cycle), invalid tree root, pending proposals about the duplicate, unresolved conflicts, and any result that would publish a record neither identity published. The survivor is always the reviewer's explicit choice and keeps its id and key.

**Execution** — one transaction: lock tree and both people, recompute the plan, compare the preview fingerprint (concurrent edits refused), apply decisions, preserve losing values as superseded claims, redirect or consolidate references (same-father edges consolidated with their evidence), mark the duplicate merged, re-validate relationships, every branch and the root (any failure rolls everything back), write the journal, audit both people and a reviewers-only operation entry. Shared ancestors or cross-family links require a super admin (`SharedAncestryScope`).

**Undo** — replays the journal backwards only if every touched row still has exactly the value the merge wrote (inserted claims unchanged and without new evidence, deleted rows not recreated); otherwise lists the blockers and refuses. Later unrelated work (e.g. new children of the survivor) is kept. Undo is atomic, re-validated, permission-controlled and audited; tested to restore every touched table exactly.

**Routing** — the merged id redirects (301) to the survivor on public pages only when the survivor is public, and on management pages for members.

## 10. Change recovery (Phase 2.4)

**Recovery coverage matrix**

| Event (audit action) | Class | Mechanism / requirement |
|---|---|---|
| Person, relationship, branch — `created` | Conditionally reversible | Delete through `GenealogyMutationService` if it is the record's latest change, its values still match and nothing depends on it |
| Person, relationship, branch — `updated` | Conditionally reversible | Re-apply the `before` values through the governed path (integrity, `PublicationGuard`, `SharedAncestryScope`, audit); explicit confirmation when it widens field privacy or publishes |
| Person, relationship, branch — `deleted` | Conditionally reversible | Re-create under the original id from the deletion snapshot; blocked by missing parent/child/founder, key or slug conflicts, cycles or second fathers; returns pending review (never republished automatically) |
| Change applied by accepting a claim (`change_requests.assertion_id`) | Specialized workflow | Re-propose the superseded claim; it goes through review again |
| `merged` / `merge_undone` | Specialized workflow | Phase 2.3 merge page (journal undo) only |
| Deletions without a complete snapshot (legacy) | Historical only | Refused; no values are invented |
| Sources, citations, memberships, invitations, users, logins, notifications, imports, claims' own state changes | Historical only | Not reversed generically |

**Architecture** — `AuditReversionService` (extended, not replaced): `preview()` is read-only and returns current/proposed values, publication and privacy-scope effects, impacted families, related deleted records and a fingerprint (log id + latest audit id + current row); `revert()` locks the tree, re-runs the preview, compares the fingerprint, requires scope confirmation when needed and writes a new audit entry (`reverted` or `restored`) linked by the unique `reverted_from_id` (immutable record: actor, reason, before/after; an event is recovered at most once). The merge journal was not generalised: generic recovery keeps the governed model path, so every existing rule applies; merges keep their exact journal.

**Fixes found while auditing** — revert of any person with field privacy or alternate names failed (snapshot JSON validated as array); a shared ancestor whose link had been invalidated (`needs_review`) stopped being protected — `SharedAncestryScope` now includes `needs_review` links.

**Privacy** — preview values are masked with `PersonPrivacy::redactSnapshot`; only reviewers (family admins, super admins) see recovery controls or previews; restored records keep their snapshot privacy levels and come back unpublished.

## 11. Historical name reconciliation and shared-ancestry validity (Phase 2.5)

**Architecture findings**
- The 39 ROOT differences already existed as `assertions` (`import_key` `root-variant:{dataset|shared}:{ROOT-xxx}`, state `proposed`, relation `unclassified`, `pending_review`, one citation each); ROOT-077 ذبيان is an accepted variant. They were only reachable person by person.
- Shared-ancestry validity was recomputed on read: `publicIndex` and the admin index called `CrossFamilyLinkService::current()` for every approved link on every request, and `current()` fingerprints both whole trees (all people with sources and descriptors, all edges, all tree citations). The write-time invalidator already existed but stopped at the first invalid link (D-07).

**Reconciliation workflow** — `NameReconciliationService` (read-only) + `NameReconciliationController` + `management/reconciliation/index`, editors and admins (`manageSources`); readers and other families get 403. Each card shows: ROOT key, displayed name and accepted variants, proposed spelling (privacy-masked like the facts panel), original list, evidence, competing name claims for the person, state / relation / verification, affected branches (branches founded by the person or a descendant), shared families, pending change request and the decision history (audit entries of the claim, its citations and its change requests, filtered by `AuditLog::visibleToMember`). Filters: open, unresolved, decided, all; a summary counts each state; the family dashboard shows the open count. All actions reuse existing endpoints: classify (`assertions.update`), cite (`assertions.cite`), propose (`assertions.propose` → change review), plus one new reviewer action `assertions.decline` (`GenealogyAssertionService::decline`: `review` permission, claim must be `proposed` with no pending change, super admin for shared ancestors; claim and evidence are kept as `rejected` with the reason). "Leave unresolved" = verification `unresolved`; the claim stays `proposed` and out of the open queue. Nothing verifies or resolves a claim automatically.

**Validity design** — new nullable columns `cross_family_links.validated_at` and `validation_stamp` (status, `dependency_hash` and `invalidation_reason` already existed).

| Displayed state | Meaning | Shown publicly |
|---|---|---|
| valid | `approved` and the stored stamp equals the current stamp | yes |
| stale («بانتظار إعادة التحقق») | `approved` but dependencies changed since the last full check (or never checked) | no, until a full check passes |
| needs_review («غير صالح») | a full check failed; super admin must re-approve | no |
| pending_review / rejected / disputed | review states | no |

- **Full check** (`current()`, unchanged rules: eligible endpoints, approved evidence, `dependency_hash` = whole-tree fingerprint) now records `validated_at` and the stamp on success, or moves the link to `needs_review` with an audit entry. It runs on writes (invalidator after commit), on approval, on the explicit "إعادة التحقق الآن" action (link admins/super admin, throttled) and on the single-link review page.
- **Stamp** = hash of row count, key sum and latest `updated_at` of: both family trees, all their people (including merged), parent edges, tree citations (excluding claim evidence), `person_sources`, `person_attribute_assignments`, family rows, family sources, link citations, `person_attributes`, plus link status and hash. A stamp is never recorded while a dependency was written in the current second (second-precision timestamps), so a same-second write cannot hide behind it.
- **Read path** (`verified()`): trusted links cost the aggregate queries (shared per tree within a request); a stale link is fully re-checked at most twice per request under a cache lock, otherwise hidden for that request and re-checked after the response. Stale links are never shown as verified.
- **Fingerprint sharing** — within one write's re-check, links of the same trees reuse the encoded tree part; the hash is byte-identical to the previous formula (tested), so existing approvals stay valid after deployment.
- **Claim evidence** — citations attached to assertions are no longer link dependencies (they never change displayed genealogy); recording, citing, classifying or proposing claims leaves validity untouched.

**Invalidation coverage**

| Change | Mechanism | Test |
|---|---|---|
| Parent change, person edit, deletion, tree/family change | model events → invalidator → full check | dataset test (reparent / rename / delete / tree) |
| Approved correction (claim accepted) | applied through the person model | accepted fact change test |
| Merge / merge undo | `PersonMergeService` calls the invalidator after raw updates | merge test |
| Audit revert / deleted-person restoration | governed model path | recovery test |
| Imports, seeders, raw or bulk updates without events | stamp mismatch → stale → re-check on read or by the admin action | bypass test, existing bulk-update test |
| Unapproved claims and their evidence | excluded from dependencies | unapproved claims test (0 full checks) |
| Branch changes | not a dependency (public view lists descendants by father edges, not branches) | — |

**Measurements** (2 families × 2,000 people, 5 approved links, SQLite, median of 3, same generator)

| Request | Before | After |
|---|---|---|
| Public `/shared-ancestry`, steady state | 4,270.9 ms · 141 queries · 5 full checks | 487.3 ms · 60 queries · 0 full checks |
| Admin cross-family links index (super admin) | 3,228.3 ms · 85 queries · 5 full checks | 22.5 ms · 101 queries · 0 full checks |
| Public tree page | 2.0 ms · 4 queries | 1.9 ms · 4 queries |
| First public view of never-validated links (after migration) | — | 1,178.2 ms · 1 full check |
| Write that touches both trees (edit one person) | 1 full check, then stopped (D-07) | 820.1 ms · 1 shared full check covering all 5 links |
| First public view after that write | — | 5.7 ms (links already `needs_review`) |

Remaining public cost is the descendant listing (~570 KB HTML for ~10,000 listed people), now loaded once per tree instead of once per founder.

## 12. Unified hierarchical entities and portals (Phase 3.1)

**Architecture findings**

| Concept | Repository structure | Role |
|---|---|---|
| Person | `people` (+ father edges) | genealogical identity; never created by entity work |
| User | `users` + `family_memberships` | account and family role; unrelated to `people` (JTH-36) |
| Family | `families` | tenant, membership and administration boundary |
| Family tree | `family_trees` | one genealogy graph of a family (root, primary/active) |
| Branch | `branches` (`root_person_id` required when active, `BranchIntegrityService`: founder in tree, nested founder descends from parent founder) | genealogical founder perspective; membership derived from father edges |
| Community entity (new) | `community_entities` | content and administration unit (tribe, clan, fakhd, sub-fakhd, branch/hamula); optional reference to a branch or tree |

Before this phase, "community pages" existed only as static Al-Zoubi views fed by `resources/data/al-zoubi/branches.php`; `Branch` already carried the five level labels (`Branch::TYPES`) but requires a verified founder, so it cannot represent social units (v2.0 ch. 3 and 5). Entities are therefore a separate table that references branches instead of extending them; branch rules and services are unchanged.

**Data model** (migration `2026_10_26_113723_create_community_entities_table`, reversible)
- `community_entities`: `family_id` (cascade), `parent_id` (self, set null), `entity_type` (the `Branch::TYPES` keys), `name_ar`, `name_en`, `slug` (unique per family), `alternate_names` (JSON), `summary`, `description`, `family_tree_id` and `branch_id` (optional, set null; `branch_id` unique: one page per branch), `publication_status` (draft / published / archived), `reviewed_at`, `reviewed_by`, `sort_order`.
- `community_entity_managers`: `community_entity_id`, `user_id`, `role` (editor / admin), unique per entity and user.
- `entity_content_links`: `community_entity_id`, `linkable_type`, `linkable_id`, `relation` (subject / audience), unique per record, entity and relation.
- No foreign key to `people`: the founder is the linked branch's founder, so merge, undo, deletion and recovery services are untouched. Deleting a branch that an entity presents is refused (`GenealogyDeletionService`).

**Hierarchy rules** (`CommunityEntityIntegrityService`, run on every save; writes lock the family row)
- Parent in the same family; no self-parent; no cycle (checked against the stored ancestor chain); an entity never moves to another family.
- Levels are optional: any level may sit directly under any higher or equal level (e.g. hamula under clan). A child is never above its parent's level, and a level change may not place an entity below its own children.
- A linked tree and branch must belong to the entity's family, and the branch to the linked tree.

**Public pages** — `/families/{family}/entities` (hierarchy) and `/families/{family}/entities/{slug}`: type and level, names and alternatives, breadcrumbs, summary and description, parent, child entities with a «هذا المستوى فقط / مع الفروع التابعة» switch, founder and branch links (only when `Branch::published`), tree link, last review date, profile completeness (summary, description, genealogy link, review date — explicitly not a measure of accuracy), correction route, and placeholders for future modules marked «غير متاح بعد». A page is public only when it and every ancestor are published, so breadcrumbs never name an unpublished entity. Entity managers preview other pages with `preview=1`. The public tree page links to the entity index when a family has published entities.

**Content scope foundation** (`EntityContentScope`, `HasCommunityEntities`)

| Page of entity X | Records shown (before the module's own publication/audience rules) |
|---|---|
| Direct | subject = X, or audience = X |
| With descendants (default) | subject ∈ X and its descendants, or audience ∈ X and its ancestors |

Subject links roll up, audience links flow down; a sibling's records never appear (v2.0 ch. 6: news reaches other branches only through an authorised audience). The scope filters the content table by key, so a record linked many times appears once; an optional eligible-entity list removes hidden entities (e.g. drafts) from aggregation. A module implements `scopeVisibleTo($viewer)` for publication and audience; `isEditableBy()` follows `owner_entity_id` only. Links are idempotent and restricted to the owner's family. Tested with a stand-in model; no real module exists yet.

**Authorization** (`CommunityEntityPolicy`, `CommunityEntityAccess`)

| Actor | Create top-level | Edit / create children | Publish, archive, review, edit published | Assign entity managers |
|---|---|---|---|---|
| Family admin / super admin | yes | all entities | yes | yes |
| Family editor | yes (draft) | all entities (drafts) | no | no |
| Entity admin (any family member) | no | own entity and descendants | own subtree | no |
| Entity editor (any family member) | no | own entity and descendants (drafts) | no | no |
| Family reader without scope / other families | no (403) | no | no | no |

Moving an entity requires authority over the destination too. Entity roles lapse when the user leaves the family. Changes and manager assignments are audited (not revertible through audit recovery: no recovery kind is registered for entities).

**Tests** — `CommunityEntityTest` (10), `CommunityContentScopeTest` (6): every level, optional levels, rank and cycle rules (form and model), branch/tree association without genealogy changes, one page per branch, branch deletion guard, community-only entities, public visibility (draft, archived, draft ancestor, disabled family, preview), publication rights, review date, entity-scoped roles, cross-family isolation, dashboard/navigation; direct vs descendants, downward audience, sibling isolation, distinct results, publication and audience rules, eligible entities, edit rights, link integrity. Mutation checks (removing the cycle rule, ancestor publication, downward audience, inherited scope, publication guard, parent family check, rank rule, direct-mode restriction, audience relation, destination authorization) each fail at least one test.

**Limitations**
- Each entity belongs to one family; a tribe spanning several families needs a shared-ownership decision (cross-family governance, like shared ancestry).
- Content sections are placeholders until their modules exist; search, API and export are not yet entity-scoped (no such endpoints for content exist).
- Level labels are the fixed `Branch::TYPES`; per-family custom labels remain a later option.
- The static Al-Zoubi portal (`/al-zoubi/*`) is unchanged.
- The admin list shows all entity names of the family to scoped managers (all are family members); edit controls are limited to their scope.

## 13. Community portal modules (Phases 3.2–3.6)

**Baseline** — HEAD `80d5c91`, clean tree, 319/319 (3374 assertions) on SQLite before changes; every migration applies.

**Architecture** — the Phase 3.1 pieces are the foundation and were extended, not duplicated: `CommunityHierarchy` (parent map, public ids), `EntityContentScope` (placement), `HasCommunityEntities` (owner, links, owner-only edit rights), `CommunityEntityPolicy` / `CommunityEntityAccess` (rights), `CommunityEntityPage` (portal). New shared layer:
- `CommunityContent` trait: publication `draft → submitted → published / archived`, verification (genealogy vocabulary), audience `public / members`, review fields, `citations()`, and the single visibility rule `visibleTo($viewer)` (published; public records for everyone; member records for members of the owner's family; per-module hooks).
- `CommunityContentService`: the only write path (save, submit, review, cite, review/remove citation), locked on the family row, audited with snapshots that include subject/audience ids and citation ids.
- `content_citations`: evidence from the family `sources` library; kept separate from genealogy `citations`, which are tree-bound and part of shared-ancestry fingerprints. Only verified/sourced citations are public; sources evidencing cross-family links cannot be cited.
- `CommunityContentModules`: registry of the four modules; one generic admin controller and one review queue.

**Modules**

| Phase | Table | Content | Public rule beyond publication/audience |
|---|---|---|---|
| 3.2 History | `entity_narratives` | overview, origins, founding, migration, periods, milestones; primary or alternative account; period and years | alternative accounts shown beside the primary one, never replacing it |
| 3.2 Geography | `community_locations` | country, governorate, city, name, historical/current, origin/settlement/migration/gathering, period, description | no coordinates; a region of the group, not an address |
| 3.3 Genealogy | — (existing graph) | founder, ancestor chain, two-generation mini tree, descendant count, related branches, approved shared ancestors, sub-entity founders, links to the full tree / branch focus | visitors: published branches, publicly visible people and edges; validated cross-family links only |
| 3.4 Statistics | — (+ `community_entities.population_estimate*`) | registered, verified, published, living (members only), known branches, share with a verified father link, sourced estimate | estimate shown only with a source; population coverage always «غير معروفة» |
| 3.5 Diwans | `diwans` | type, description, governorate/city, public address, coordinates, Place ID, Maps link, contact, visit instructions, private-residence flags | contact only with consent; a private residence is published only with recorded consent |
| 3.6 Notable people | `entity_achievements` | person (existing record), position/achievement, neutral category, organization, period, description | visitors only when the person is publicly visible; members while the person is active |

**Hierarchical behaviour** — one `scope` parameter for the whole portal; the switch keeps the current section anchor. Direct: content about or addressed to the entity, its own founder and statistics. With sub-branches: content about the entity or any descendant, content addressed to it or any ancestor, founders of descendant entities and statistics over all anchors in scope. Siblings never contribute. Hidden (draft/archived or under a hidden ancestor) entities contribute no content, founders or statistics to public pages.

**Statistics semantics** — membership = founder plus descendants along primary father edges (`BranchMembershipService` semantics), computed for recorded, verified and public edge sets; a tree-linked entity counts its whole tree. Each tree's people and edges are loaded once per page build and shared by the preview and the statistics; people are united by id, so overlapping branches never double-count. Branch counts are filtered in memory (no large `whereIn`).

**Maps** — directions use the keyless Maps URLs API (`/maps/dir/?api=1&destination=lat,lng[&destination_place_id]`, else a place or address search, else the validated stored link). An Embed API iframe appears only when `GOOGLE_MAPS_EMBED_KEY` is configured. Stored links must be `https` on Google Maps hosts (`www.google.com/maps…`, `maps.google.com`, `maps.app.goo.gl`, `goo.gl/maps…`). Coordinates are validated ranges and required in pairs; nothing is geocoded or inferred.

**Governance and privacy**
- Rights come from the owner entity: managers draft and submit; family/entity administrators publish, archive, verify, approve evidence and change published records. Every subject/audience entity must be one the actor manages in the same family.
- Visibility on a page never grants editing (`isEditableBy` follows the owner; the admin route only resolves records by owner).
- Achievements reference `people` with `restrictOnDelete`; person deletion and merges are refused while achievements point at the person (merge journal does not cover content).
- Sources cited by content or used for a population estimate cannot be deleted; the source page lists content usages.
- Recovery: records are never hard-deleted from the UI (archive / restore as draft); every change is audited with full snapshots. Generic audit revert is **not** registered for content types (no replay engine for links/citations); this is documented rather than promised.

**Performance** (SQLite, 4-ary tree, 21 published entities, 253 content records with citations, shared diwan; median of 3)

| Page | 2,000 people | 10,000 people |
|---|---|---|
| Clan, with sub-branches (285 KB, all records) | 129 ms · 53 queries | 330 ms · 53 queries |
| Clan, direct | 61 ms · 48 queries | 253 ms · 48 queries |
| Fakhd, with sub-branches | 75 ms · 54 queries | 277 ms · 54 queries |
| Sub-fakhd, direct | 60 ms · 50 queries | 246 ms · 50 queries |
| Entity index | 5 ms · 3 queries | 5 ms · 3 queries |

Query count is independent of content volume (eager loading; no N+1). The time growth is the single per-page load of the tree graph; a first version loaded it twice (statistics had its own instance) and was fixed by sharing one graph per page build — a container-scoped singleton was rejected because it would survive across requests in long-lived processes and tests. Caching the graph is not introduced without production measurements.

**Limitations**
- Diwan images (no public media store); archive and media module (JTH-15) not built.
- Content search, API and export do not exist yet; when built they must use `EntityContentScope` and `visibleTo`.
- Static Al-Zoubi pages unchanged; nothing was copied or published from them.
- Single-family ownership of entities and content (multi-family governance deferred).
- Shared-ancestor display on the portal uses `CrossFamilyLinkService::verified`, which may re-check up to two stale links on a page view.

## 14. Media library, heritage archive and Phase 3 closure (Phase 3.7)

**Baseline** — HEAD `781eb9c`, clean tree, 333/333 (3583 assertions) on SQLite before changes.

**Findings** — the only file handling was the private source library (`genealogy_private` disk: evidence documents for reviewers, no publication or audience) and CSV imports. Neither is a media library, so they were kept as they are; media reuses the same patterns (private local disk, generated names, controlled downloads) and the Phase 3 content layer instead of a separate system.

**Storage and delivery**
- Disk `media` (configurable `MEDIA_DISK` / `MEDIA_ROOT`, any Laravel disk; default `storage/app/media`, outside every served disk root and not linked by `storage:link`). Paths are `{family}/{uuid[0..1]}/{uuid}.{ext}`; the client file name is kept only as display metadata (sanitized).
- `MediaStorage::inspect`: type from the file's content (`finfo`) against an allow-list (JPEG, PNG, PDF, MP3, OGG); extension must agree; per-category size limits; image dimensions read and capped (`max_pixels_side`); markup or script in the first KB refused; PDF header checked; SHA-256 checksum. SVG, HTML, scripts and archives are never accepted.
- Images get a display copy without metadata (EXIF/GPS/XMP/comments; PNG text/time chunks): re-encoded and resized to `display_max` with GD, then stripped; without GD, stripped in pure PHP. The unchanged original is served only to the owner entity's managers.
- `MediaController` (`/media/{uuid}/{display|original}`): `MediaAccess` decides; anything not readable is a 404 (existence not revealed). Responses carry `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cross-Origin-Resource-Policy: same-origin`; only public published display copies are cacheable.

| Media state | Visitor | Family member | Owner-entity manager | Publisher (family / entity admin) |
|---|---|---|---|---|
| Published · public (entity page public) | display | display | display + original | display + original |
| Published · members | — | display | display + original | display + original |
| Published · restricted | — | — | — | display + original |
| Draft / submitted / archived | — | — | display + original | display + original |

**Data model** (migration `2026_10_26_125915_create_media_and_archive_tables`, reversible)
- `media_items`: Phase 3 governance columns + family, uuid, disk, path, display path, original name, extension, MIME, size, checksum (unique per family — a file is stored once), dimensions, category, title, description, alt text, rights (status, holder, note), provenance, historical period/year.
- `media_attachments`: polymorphic (archive entries, diwans, narratives), unique per file and record; `restrictOnDelete` on the file.
- `archive_records`: governance + title, description, category (genealogy chart, manuscript, book/excerpt, document, photograph, written account, oral history, other), original source, period/years, contributor, optional person, rights.
- `community_entities.cover_media_id`.
- The shared audience gains `restricted` (family and entity administrators) for every content module.

**Rules**
- Publication checks (on publish and on every save of a published record): usage rights must allow the audience (public needs owned / permission / licensed / public domain; unknown rights stay within the family; «غير مسموح بنشره» stays restricted); published images need alternative text; private-residence diwans keep their consent rule.
- Archive entries about a person who is not publicly visible are hidden from visitors (members still see them). Uploading never verifies anything; competing accounts remain separate narratives.
- Attachments: same family only; diwans take images only; changing the files of a published record needs a publisher. Detaching never deletes the library file.
- Deletion: only unpublished, unattached files that are not a cover; files are removed after the transaction commits. Upload failures after storing remove the stored files; replacement keeps the category, rejects duplicates, and deletes the old files only after commit. People referenced by archive entries cannot be deleted or merged away.
- `php artisan media:verify [--delete-orphans]`: reports missing files, checksum mismatches and files without rows (left by interrupted uploads or a deleted family).
- Every upload, replacement, attachment, review decision and deletion is audited; content recovery remains archive/restore (no generic revert for content types).

**Portal** — new sections «الأرشيف والوثائق» and «الصور والوسائط» under the same scope switch; files attached to archive entries, diwans and narratives appear inside their records; an optional cover image in the hero. Each file shown is checked with the same `MediaAccess` rule as the route.

**Performance** (same generator plus 126 images and 42 archive entries with 3 files each; SQLite, median of 3)

| Page | 2,000 people | 10,000 people |
|---|---|---|
| Clan, with sub-branches, visitor (508 KB) | 199 ms · 71 queries | 391 ms · 71 queries |
| Clan, with sub-branches, signed-in member | 255 ms · 77 queries | 393 ms · 77 queries |
| Sub-fakhd, direct | 68 ms · 68 queries | 247 ms · 68 queries |

A first version checked rights per file for signed-in viewers (177 queries); `MediaAccess` now computes the user's manage/publish scope once per family (`CommunityEntityAccess::manageableIds` / `publishableIds`), keeping the count constant.

**Phase 3 closure** — JTH-15–20 and 32–35 are implemented; JTH-31 stays partial only because content search, API and export do not exist yet (they must reuse `EntityContentScope` + `visibleTo` + `MediaAccess` when built). Static Al-Zoubi pages remain until reviewed content is migrated.

**Limitations**
- No video, no in-browser image editing, no virus scanning service (signature/allow-list validation only).
- Display copies are resized only where GD is installed (the PHP 8.3 images used for tests have none; the GD path was verified in a separate PHP 8.3 + GD container). EXIF orientation is not applied.
- PDFs and audio are served as uploaded (their internal metadata is not rewritten); they are delivered sandboxed.
- Files of a deleted family remain on disk until `media:verify --delete-orphans`.
