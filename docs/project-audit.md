# Juthoor — Repository Audit & Requirements Traceability

Audit date: 2026-10-27 (updated after UX-P0.3 — unified tree redesign; see §18; UX-P0.2 in §17, UX-P0.1 in §16). Approved priority change (2026-10-26): Phase 2.6 (mothers, spouses, life events), Phase 2.7 (GEDCOM) and Facebook publishing are **deferred, not cancelled**; Phase 3 proceeds now, then Phase 4 without Facebook. Reference: *Juthoor — Master Project Requirements & Architecture Reference v2.0* (2026-10-22). The reference file is **not in the repository**; it was read from `~/Downloads/Juthoor_Master_Project_Requirements_v2.0.md` on the maintainer's machine. Requirement IDs (JTH-xx, UC-xx, ADR-xxx) below are the reference's own.

Status values: **Implemented** (verified by code + executed tests), **Partial**, **Missing**, **Broken**, **Unverified**. "Implemented" never means "listed in the reference".

## 1. Verified baseline

| Item | Finding |
|---|---|
| Stack | Laravel 13.31, PHP ^8.3 (composer), Pest 4.7, Blade + Bootstrap 5 RTL + vanilla JS (`public/js/genealogy-tree.js`, `public/js/juthoor.js`). No Vite/npm build. |
| Databases | SQLite (tests, local); MySQL per `.env.example` history; PostgreSQL 16 now verified (full suite green). |
| Branch / HEAD | `main` at `6e2e4e2` (UX-P0.2 committed by the maintainer); UX-P0.3 changes (session 15) are uncommitted. |
| Test baseline (start of session 15) | SQLite 421 / 421; PostgreSQL 16 420 / 421 (ordering-only test defect, fixed — §18.1). |
| Test result (end of session 15) | **428 passed, 0 failed** (4788 assertions) on SQLite; PostgreSQL 16: **428 passed, 0 failed** (4788 assertions). Node layout tests 11/11. |
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
| News, events & notices (Phase 4) | `news_posts`, `community_events`, `content_notices`, `content_person_links`; `CommunityPublishingService`, `CommunityPublicationController` (news list, calendar, detail pages), `HasPublicPlace` / `HasNotices` / `HasLinkedPeople` | Implemented (see §15) |
| Member directory (Phase 4.4) | `member_profiles`, `directory_reports`; `MemberDirectory`, `MemberDirectoryController`, `DirectoryProfileController` | Implemented (see §15) |
| In-app notifications (Phase 4) | Laravel `notifications` table (database channel only), `CommunityNotification`, `CommunityNotifier`, `/notifications` | Implemented (see §15) |
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
| JTH-21 | Hierarchical news | Implemented | News, announcements, initiatives, meetings and notices through the shared scope (roll-up, targeted audience, sibling isolation, one record), publication window, review, notices (`CommunityNewsTest`, Chrome) | — | P2 | 4 ✓ | — |
| JTH-22 | Deaths, burial, condolences | Implemented | Death and condolence categories with optional funeral, burial and condolence details, consent, family-administrator confirmation, visible corrections; genealogy never changed | — | P2 | 4 ✓ | UC-15 |
| JTH-23 | Weddings, graduations | Implemented | Event categories (wedding, graduation, achievement…) with linked people shown only per their visibility | Per-person consent records for named people (editorial rule for now) | P3 | 4 ✓ | — |
| JTH-24 | Events & calendar | Implemented | `community_events` separate from news, time zones, status and schedule-change history, calendar month/list views with category and scope filters, announcement from event (`CommunityEventTest`, Chrome) | iCalendar export | P2 | 4 ✓ | UC-16 |
| JTH-25 | Optional Facebook | Missing | — | Deferred by decision (2026-10-26) | P3 | deferred | UC-17 |
| JTH-26 | Member directory | Implemented | Voluntary opt-in profiles per user and family, per-field visibility, audience-safe search and filters, in-app contact requests, reports, affiliation review, suspension (`MemberDirectoryTest`, Chrome) | Retention period settings | P2 | 4 ✓ | UC-18 |
| JTH-27 | Professional profiles | Implemented | Profession, specialization, sector, skills, experience, organization, business and services, links, collaboration interests within the directory profile | — | P3 | 4 ✓ | — |
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
| UC-15 Death notice | Implemented | Consent and authorised confirmation before publication; changes and cancellations shown with history |
| UC-16 Public event | Implemented | Date, place, audience and status; shown per audience scope |
| UC-17 Facebook | Deferred | — |
| UC-18 Directory profile | Implemented | Create, edit, hide and delete without affecting genealogy; field privacy |

## 5. Completion dashboard

Method: each of the 36 JTH requirements is counted once. Weighted completion = (Implemented × 1 + Partial × 0.5) ÷ 36.

| Status | Count |
|---|---|
| Implemented | 25 (JTH-01, 03, 05, 07, 09, 10, 13–24, 26, 27, 32–36) |
| Partial | 8 (JTH-02, 04, 06, 08, 11, 28, 29, 31) |
| Missing | 3 (JTH-12 GEDCOM — deferred, JTH-25 Facebook — deferred, JTH-30) |
| Broken | 0 |
| Unverified | 0 |
| **Weighted completion** | **(25 + 4) / 36 = 80.6 %** |

| Module group | Requirements | Implemented | Partial | Missing |
|---|---|---|---|---|
| Genealogy core (JTH-01–03, 05–07, 10–11, 34, 36) | 10 | 7 | 3 | 0 |
| Governance & data (JTH-08, 09, 12, 13, 28) | 5 | 2 | 2 | 1 |
| Administration (JTH-04, 14) | 2 | 1 | 1 | 0 |
| Community pages & content (JTH-15–27, 31–33, 35) | 17 | 15 | 1 | 1 |
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

## 15. Community news, events, directory and notifications (Phase 4)

**Baseline** — HEAD `57ffda7`, clean tree, 346/346 (3790 assertions) on SQLite before changes. Mail goes to the log and the queue is the database; no in-app notification store existed.

**Architecture** — news and events are two more `CommunityContentModules` entries, so ownership, subject/audience links, the scope switch, publication and review states, the review queue, citations, media attachments and audit are the Phase 3 ones. New shared pieces: `CommunityContent::publicationWindow` (checked inside `visibleTo` on every query, no worker needed), `HasNotices` (`content_notices`: public updates, corrections, cancellations and schedule changes with the replaced values), `HasLinkedPeople` (`content_person_links`, references only), `HasPublicPlace` (Maps directions and consented contact, now also used by diwans). Facebook and every publication channel other than the site remain out of scope.

**News (4.1)** — `news_posts`: category (news, announcement, death, condolence, initiative, meeting, notice), title, summary, body, `publish_at` / `expires_at` (entered in `COMMUNITY_TIMEZONE`, stored UTC), optional event, death and condolence fields (deceased name, optional genealogy record, death date, funeral prayer, burial, condolence place and times), public place and contact, family consent, sensitive confirmation (who and when). Expired posts are hidden or labelled (`COMMUNITY_EXPIRED_NEWS`).

**Sensitive announcements** — death notices and condolences are published (or changed after publication) only by a family administrator — not by entity administrators — with the family's consent recorded and an explicit confirmation stored with the confirmer and time. Linking the genealogy record never changes it; a living or unpublished record is not linked publicly. Contact details appear only with their own consent.

**Events and calendar (4.2)** — `community_events` separate from news: category (wedding, graduation, achievement, gathering, meeting, initiative, other), organizer, start/end in UTC with the event's time zone, all-day, place, status scheduled / postponed / cancelled / completed (finished scheduled events display as completed without changing the record). Reschedule, postpone, relocate, cancel, reinstate and complete go through `CommunityPublishingService` with a public notice keeping the previous values. An announcement generated from an event is a draft news post pointing at the event (no copy). Public calendar per entity: month grid, upcoming and past lists, category and scope filters, links to sub-entity calendars.

**Unified publishing (4.3)** — same scope semantics as Phase 3 (direct; with descendants plus ancestor-targeted; sibling isolation; one row per record). Changing published content needs a publisher (family or entity administrator; family administrator for sensitive categories); corrections are public notices, never silent rewrites. Detail pages need the record to be visible to the viewer and its owner entity's page to be public (managers may preview). News search (`q`, category) runs inside the same scope and visibility query.

**Notifications** — Laravel database notifications only (`/notifications`, unread badge): review requested (to the owner entity's publishers), review decided (to the author), published changes (author and publishers), directory contact requests, reports and affiliation decisions. Payloads hold a title, a short context and an internal link; nothing is e-mailed and no announcement text or contact detail is copied. Opening a notification follows only internal links.

**Member directory (4.4)** — `member_profiles` belong to a User (one per family), never to a Person, and are never created from genealogy. Hidden until the member lists them. Profile audience (family members / public) caps every field; each field (affiliation, profession, sector, skills, experience, organization, business, city, governorate, links, collaboration, photo, e-mail, phone, in-app messages) is hidden / members / public, with organization and contact details hidden by default. Search and filters use text and field lists precomputed per audience, so hidden fields cannot be matched, filtered or returned; filter choices only show values the audience can read; pages send `X-Robots-Tag: noindex`. Affiliation claims are shown and filterable (along the entity hierarchy) only after a family administrator verifies them. Contact requests are delivered in-app without revealing either side's address. Administrators review affiliations and reports and may suspend a listing, but have no way to edit or publish a member's data. Deleting a profile removes it and its photo only. Photos use the media inspection and keep only the metadata-free copy.

| Directory viewer | Sees |
|---|---|
| Visitor | public listed profiles; public-level fields only |
| Family member | public and member profiles; member- and public-level fields |
| Owner | their whole profile |
| Family administrator | as a member, plus the display name and claimed entity in the review queue |

**Tests** — `CommunityNewsTest` (9), `CommunityEventTest` (5), `MemberDirectoryTest` (7). 21 rule mutations each fail a test (scheduling, expiry, sensitive approval ×3, contact consent, editors changing published content, hidden owners, field search/filters ×5, messaging, photo visibility, notification recipients, linked-people privacy, member-only profiles); the profile-audience cap and the per-level field rule are redundant guards (each holds alone).

**Performance** (SQLite, 10,000 people, 21 entities, 504 news, 315 events, 1,000 directory profiles; median of 3)

| Page | Time · queries |
|---|---|
| Clan portal, with sub-branches (visitor / member) | 407 ms · 85 / 423 ms · 91 |
| News list, any page | 6–7 ms · 8 |
| Calendar (clan) | 33 ms · 14 |
| Directory, visitor / member search / by entity | 8 ms · 7 / 4 ms · 10 / 10 ms · 14 |

The news list first ran 19 queries (notices per card); eager loading made it constant. Portal time is still dominated by the single tree-graph load (§13).

**Limitations**
- No outbound e-mail for Phase 4 notifications (by design); no push or SMS.
- Expiry is a visibility rule, not an automatic archive; no iCalendar export; no RSVP or attendance.
- Per-person consent for people named in events and news is an editorial rule (form guidance), not a recorded consent workflow.
- Directory retention periods and export of one's own profile are not implemented; there is no public API.
- JTH-31: there is still no content API or export; any future endpoint must reuse `EntityContentScope`, `visibleTo`, `MediaAccess` and the directory's per-audience fields.

## 16. Person management, RBAC and seeders (UX-P0.1, 2026-10-27)

**References** — all seven documents named in the UX-P0.1 brief are in `docs/` (`Juthoor_Master_Project_Requirements_v2.0.md` is now in the repository; `Juthoor_Experience_v3_Requirements_and_Roadmap.md`, `Juthoor_P0_People_Roles_Family_Seeders_2026-10-27.md`, `Juthoor_Seeder_Audit_and_Design_Data_2026-10-27.md` and the three living docs). Priority order applied: v2.0 integrity/privacy, then v3.0 decisions, then the P0 annex and seeder audit, then verified repository behaviour.

**Baseline** — `main` at `4ed00b1`, clean tree; 367/367 (4048 assertions) on SQLite and on PostgreSQL 16 before any change.

### 16.1 Findings (before)

| Area | Finding |
|---|---|
| RBAC model | No Spatie or permission tables. Three membership roles per family (`reader`/`editor`/`admin`), entity managers (`editor`/`admin`, subtree-scoped), `users.is_super_admin`. Policies called `canEditFamily()` / `roleFor() === 'admin'` directly; `PublicationGuard`, merge and recovery used the admin role check. |
| Missing capabilities | No way to give a member one narrow duty (e.g. review genealogy only, or edit news only) without making them an editor or administrator of everything; no role seeder; no permission matrix. |
| Person UX | Tree already linked "add son" / "edit" to the flat person form (with `father_id` prefill); no in-tree add, no duplicate check before submit (only a refusal after), no lineage/branch context for the chosen father, no read-only state for published records, no father-change impact, review page without submitter, impact, publication effect or earlier decisions. |
| Seeders | SD-01…SD-07 and SD-09 confirmed in code: `updateOrCreate`/`fill()->save()` reset family, tree, people, edges, branches and founders on every `db:seed`; classification ran outside the import transaction; demo password fixed to `password`. |

### 16.2 Role and permission model

Functional roles are **added on top of** a membership in one family (`family_role_assignments` → `family_memberships`, cascade on leave). Membership roles keep exactly their previous rights (now written down in `PermissionCatalog::MEMBERSHIP_PERMISSIONS`). Effective permissions = membership rights ∪ rights of roles assigned through that membership (`FamilyPermissions`); nothing crosses families; inactive accounts have none; super admins pass through `Gate::before` as before. Platform governance (`platform.govern`: shared ancestors, cross-family links) is never grantable through a family role. Entity roles are unchanged (`community_entity_managers`) — no second entity role model.

| Permission (`key`) | Reader | Editor | Admin | Genealogy manager | Genealogy editor | Genealogy reviewer | Content manager | News editor | News publisher | Death-notice reviewer | Directory moderator |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `person.view-drafts` | ✓ | ✓ | ✓ | | | | | | | | |
| `person.propose` | ✓ | ✓ | ✓ | | | | | | | | |
| `person.create` | | ✓ | ✓ | ✓ | ✓ | | | | | | |
| `person.edit-unpublished` | | ✓ | ✓ | ✓ | ✓ | | | | | | |
| `genealogy.review` | | | ✓ | ✓ | | ✓ | | | | | |
| `genealogy.publish` | | | ✓ | ✓ | | | | | | | |
| `privacy.manage` | | | ✓ | ✓ | | | | | | | |
| `source.manage` | | ✓ | ✓ | ✓ | ✓ | | | | | | |
| `branch.manage` | | ✓ | ✓ | ✓ | | | | | | | |
| `person.merge` | | | ✓ | | | | | | | | |
| `audit.recover` | | | ✓ | | | | | | | | |
| `family.settings` | | | ✓ | | | | | | | | |
| `family.members.manage` | | | ✓ | | | | | | | | |
| `role.assign` | | | ✓ | | | | | | | | |
| `content.edit` / `content.publish` | | ✓ / — | ✓ / ✓ | | | | ✓ / ✓ | | | | |
| `news.edit` / `news.publish` | | ✓ / — | ✓ / ✓ | | | | covered by content¹ | ✓ / — | ✓ / ✓ | ✓ / ✓ | |
| `news.death-notices` | | | ✓ | | | | | | | ✓ | |
| `directory.moderate` | | | ✓ | | | | | | | | ✓ |

¹ Content permissions cover every module including news and events; news permissions cover only news and events. Editors keep their previous draft rights (`content.edit`, `news.edit`). Functional role columns list only what the role adds (a reader holding it still has the reader rights). The table mirrors `app/Services/Access/PermissionCatalog.php`, which is authoritative and is rendered live at `/manage/families/{family}/roles`.

Abilities: `FamilyPolicy` (`review`, `publish`, `managePrivacy`, `merge`, `recover`, `update`, `manageMembers`, `assignRoles`, `moderateDirectory`, `reviewDeathNotices`, `manageSources`, `propose`), `PersonPolicy`, `PersonRelationshipPolicy`, `BranchPolicy`, `CommunityEntityPolicy::update/publish($entity, $module)` (news permissions cover only the `news` and `events` modules), `PublicationGuard::canPublish/canManagePrivacy`, `PersonMergeService::authorize`.

**Separation of duties** — self-approval remains blocked in `ChangeReviewService::decide`; a genealogy reviewer may apply someone else's accepted proposal (the review is the publication decision, `GenealogyMutationService::applyReviewed`) but cannot create, edit or publish directly; a news editor gets no genealogy or other content rights; a content manager gets no genealogy rights; death notices need `news.death-notices` even for news publishers; shared ancestors still need a super admin; merge and recovery stay with family administrators. Assignment: only `role.assign` holders, never their own roles (super admins excepted), never a role with permissions the assigner lacks; every change is audited on the membership (admin-only audit type).

**RBAC seeder** — `RolePermissionSeeder` / `php artisan rbac:sync [--dry-run] [--apply-upgrades] [--json]` (`RoleCatalogSynchronizer`): deterministic keys; creates missing permissions and templates; never duplicates; never touches assignments, custom roles, manual grants or roles an administrator customized (reported as differences); removes withdrawn catalog grants (least privilege, reported); **reports but does not grant** new template permissions for an existing role unless `--apply-upgrades`; renamed keys move grants (`RENAMED`), removed keys are deprecated and grant nothing; scope changes are refused as conflicts; every applied change is audited (`rbac.*`, platform audit). It creates no accounts. `DatabaseSeeder` runs it after the genealogy seeders. Super admins see the dry-run report, customize templates (marks them customized, manual grants flagged) and create custom roles at `/site-admin/roles`.

### 16.3 Person workflows

- **Tree** (`genealogy-tree.js`, `ProgressiveGraphService::actions`) — node panel: quick add of a son (name required, living status optional, father = the node, validated in the same tree server-side, always `pending_review` and private; duplicate candidates shown live and on refusal with an explicit confirmation; "add another son" keeps the father), full form, edit, focus on descendants, propose correction, sources/claims page, person page with ancestors. Actions are computed from permissions and re-authorized by each route.
- **Guided creation** (`management/people/form.blade.php`, `person-form.js`) — four steps (identity and father with lineage, derived branch and existing sons; duplicate check by normalized name, variants, same father, similar brothers and same name elsewhere — each candidate with lineage and an "open this person" link; optional details; review summary with tree effect, verification, publication and approval). Works as one form without JavaScript. Branch membership is shown as derived from the father chain, never chosen. Optional alternate spelling on creation; sequential entry of brothers.
- **Editing** — sections (identity, genealogy, historical data, sources, privacy and publication, claims, history), dependants count, derived and founded branches, shared families; a warning when the approved father changes; read-only with a "propose correction" path for published records (non-publishers) and shared ancestors (non super admins); the server enforces the same rules.
- **Review** — submitter and dates, current → proposed values, people and branches affected, publication effect before/after, earlier proposals and decisions on the same record, links to the person and the tree, own-request notice (no decision form), reason required for both decisions. A "request more information" state was **not** added: the `change_requests` lifecycle has no such state and adding one is a separate decision.
- Endpoints: `management.people.quick` (POST, throttled), `management.people.candidates` (GET, read-only).

### 16.4 Seeders (Workstream C)

| ID | Status |
|---|---|
| SD-01/02 | Fixed — family and tree create-if-missing; a new tree is primary only if none is. |
| SD-03 | Fixed — existing people keep name, status, verification, notes, privacy and living status; the dataset citation is still attached. Unreconciled name differences are still refused. |
| SD-04 | Fixed — missing father edges only; a different existing father stops the seed before writing and lists every conflict; existing edges keep source/verification; tree root only set when empty. |
| SD-05/06 | Fixed — branches create-only; founder paths refuse different names/fathers; an administrator-set founder is kept and reported, not reactivated. If an administrator clears a founder, the next seed relinks it (cannot be told apart from "never linked"). |
| SD-07 | Fixed — classification inside the import transaction, only when the run created people. |
| SD-08 | Reported by the audit only (raw files under `public/docs`); not moved. |
| SD-09 | Fixed — `DEV_SEED_PASSWORD` or a random password printed once; existing accounts keep theirs. Browser scripts need `DEV_SEED_PASSWORD=password`. |
| SD-11 | Fixed as opt-in demo fixtures (below). |

**Preflight** — `php artisan genealogy:seed-audit [--family=] [--json] [--dry-run]` (`SeedAuditService`), always read-only (rolled-back transaction, no seeder call): families, trees, counts, expected dataset records, branches/founders, sources, dataset files with sha256 and versions, missing dependencies, identity conflicts, preserved administrator edits, duplicate candidates, self-parent / multiple fathers / cycles, privacy and publication counts. Exit 1 on conflicts. Web view for super admins: `/site-admin/seed-audit`.

**Family bootstrap** — `php artisan family:bootstrap {manifest.json} [--dry-run] [--json]` (`FamilyBootstrapService`): family (created inactive), main tree, role templates, documented sources (pending), draft entities, pending branches; a founder only by an existing canonical key; an existing account may be enrolled as administrator (no account creation); create-only with a report of kept and skipped items; never creates people, fathers, founders, diwans, news, population figures or living statuses. Minimal setup (family only) is supported.

**Demo fixtures** — `JuthoorUiDemoSeeder`: local/testing **and** `JUTHOOR_DEMO_FIXTURES=true`; never in `DatabaseSeeder`; separate family `demo-juthoor` labelled synthetic; unresolved/pending people with unknown living status, draft entities and content, no contact details; idempotent; never touches `al-zoubi`.

### 16.5 Database changes

Migration `2026_10_27_071232_create_roles_and_permissions_tables`: `permissions`, `roles`, `permission_role` (with `origin` catalog/manual), `family_role_assignments` (FK to membership, cascade). Rollback/re-apply verified on PostgreSQL 16. No change to genealogy tables.

### 16.6 Limitations

- Content separation is by module family (news/events vs. the rest); a news-only editor cannot attach media (media checks are not module-aware).
- `privacy.manage` is separate in the catalog, but the restricted-field audience (`PersonPrivacy`) still means family administrators and super admins; a genealogy manager sets privacy levels without seeing restricted values unless an administrator.
- Person proposals still carry no father (P1-04); quick add is for `person.create` holders; members without it use "propose correction".
- Sources are attached from the person page after creation, not inside the guided form.
- No "needs more information" review state; no platform auditor role (not needed: super admins audit).
- The tree layout is unchanged (UX-P0.2/Tree Experience).

## 17. Family websites, domains and branding (UX-P0.2, 2026-10-27)

**Baseline** — `main` at `9882e9f` (UX-P0.1 committed by the maintainer), clean tree; 404/404 on SQLite and on PostgreSQL 16 (PostgreSQL re-run on a clean worktree of HEAD, because the first run overlapped the dataset move below).

### 17.1 Security prerequisite — public datasets (SD-08 closed)

| Finding | Detail |
|---|---|
| Reachable | Everything under `public/docs` is served directly by the web server (no PHP involved), whatever `Options -Indexes` says. |
| Private data | `juthoor_alzoubi_genealogy_dataset_v4.json`/`.xlsx` (+ byte-identical "(1)" copies): 1,505 canonical records, **1,428 unimported branch people** (recent generations, likely living), raw source readings, blank-name resolutions. 19 handwritten fakhd charts (بكر/عيد/خميس/رحايمه/عودتلات) and the 9 Qawasmeh source images/PDF behind the **34 pending Qawasmeh records**. |
| Consumers | `AlZoubiDataset` (seeders), `AlQawasmehBranchImporter` (sha256-checked attachments), `SeedAuditService`. `sources.file_path` stores `docs/<file>` as provenance; the source download route reads a private disk, not `public/`. |
| Intentionally public / harmless | UI mock-ups (sample "Sanders" tree, showcase PNGs, blueprint/spec PDFs) and published-style research notes (`*_no_people_*`, site-content JSON) — no private person data; left in place. |
| Remediation | 30 files moved (not deleted) to `resources/data/source-documents/` (outside the web root) with identical names and sha256 (verified before/after); `SourceDocuments::path()` resolves them; `AlZoubiDataset::PATH` keeps `docs/…` as the recorded provenance. Seed audit now reports `ok`. |
| Tests | `PrivateDatasetExposureTest`: no file under `public/` matches any private file's checksum or name pattern; importers read the relocated files with unchanged checksums; former URLs return 404. |
| Note | The repository-root `.htaccess` (committed by the maintainer) contains `Options All` and duplicated rewrite rules; an unconditional rewrite into `public/` keeps root files unreachable, but the file should be reviewed with the server configuration. Not changed here. |

### 17.2 Domain architecture

One application, one database, the `Family` model as the only tenant identity. `family_domains` (host unique, kind subdomain/custom, status, verification token, requester/verifier/activator, timestamps). Lifecycle: *no row* = unconfigured → `pending_verification` → `verified` → `active` → `disabled` (→ reopened as pending). Rules (`FamilyHosts`):

- Family administrators (`family.settings`) may request only `<their slug>.<suffix>` or a custom domain (proved by a `juthoor-verify=<token>` TXT record); never a reserved label (`config/portals.php`), a platform host or a platform subdomain, an IP, or a host held by any family (the refusal does not say which). Super admins may choose any non-reserved label.
- Only super admins verify (explicit DNS, TLS and proxy confirmations plus notes), activate (family active, one active domain per family) and disable; every transition is audited without the token.
- Reserved labels can no longer be family slugs (site-admin create/update, bootstrap).

`ResolveFamilyHost` (first in the web group) when `JUTHOOR_FAMILY_HOSTS=true`: platform host and aliases → platform; an **active** host of an **active** family → that family's website (`/`, `/families/<own slug>/…`, `/media/…`); other families' paths → 404; any other GET (administration, login, notifications) → redirect to the platform host; non-GET → 404; unknown host → 404 (no Host-header confusion). With the flag off, behaviour is exactly as before.

**Production configuration (not applied here; the host is not claimed live):**

| Item | Requirement |
|---|---|
| DNS | `al-zoubi.juthoor.dopamind.cloud` (or `*.juthoor.dopamind.cloud`) A/AAAA → platform server; custom domains also need the TXT record |
| TLS | certificate covering the platform host and each family host (wildcard or per host) |
| Reverse proxy | pass the original `Host`; configure `TrustProxies` for the proxy so `https` and host are correct |
| Environment | `APP_URL=https://juthoor.dopamind.cloud`, `JUTHOOR_PLATFORM_HOST=juthoor.dopamind.cloud`, `JUTHOOR_FAMILY_DOMAIN_SUFFIX=juthoor.dopamind.cloud`, `JUTHOOR_PLATFORM_ALIASES=` (empty in production), `JUTHOOR_FAMILY_HOSTS=true` only after the checks |
| Sessions | keep `SESSION_DOMAIN` empty (host-only cookies): family hosts are public sites and send sign-in to the platform host; `SESSION_SECURE_COOKIE=true` |
| Activation | site-admin → نطاقات العائلات → verify (with confirmations) → activate |

### 17.3 Family portal

`FamilyPortalPage` (view model) assembles the website from existing services only: `CommunityHierarchy` (public entities), `CommunityEntityPage::build()` on the family's root entity with descendants (content scope, audiences, publication windows, sensitive news rules, media access, public people), `CommunityGenealogy` (public graph: registered / published / verified, per-founder public counts, root preview), `Branch::published` (fallback hierarchy when a family has no entities), `MemberDirectory` (public profiles, public fields ∩ photo/profession/sector/city — never contact or workplace) and `MediaAccess`. The landing page applies public rules to every viewer, signed-in members included. Routes: `/families/{slug}` and `/families/{slug}/portal` (platform path), `/` on an active family host, `/manage/families/{slug}/portal/preview` (draft, `noindex`, `no-store`).

Sections (each with an empty state, none filled with legacy or invented data): hero with search (to the unified tree, `?q=` now pre-fills the tree search), summary, published statistics with timestamp and the coverage disclaimer, fakhds and branches (hierarchy grid, founder only when public, public record counts, links to entity or branch pages), genealogy preview, history, diwans, achievements, archive, latest news, upcoming events, directory preview, contribution CTA, footer with approved links and platform attribution.

### 17.4 Branding

`family_portal_settings` (draft and published JSON, who/when). Values are structured and validated (`PortalSettings`): display name, intro, description (plain text, escaped), hero title/subtitle, cover and logo (media uuid of this family that is a **publicly viewable** image — re-checked at publication and at render), accent from five approved palettes (CSS variables from config, no free colours or CSS), CTA label/target, up to six `https` links and a public e-mail, footer text, root entity, section visibility and order. Edit/preview/publish need `family.settings` (family administrators); both steps audited (`portal.draft_saved`, `portal.published`). The family dashboard gains a website/domain/roles panel (publication state, completeness, domains, functional role holders, entity counts).

### 17.5 Legacy routes and transition

`/al-zoubi/*` static pages unchanged and default; `/families/al-zoubi` still redirects there. The dynamic portal is reachable at `/families/al-zoubi/portal`. `JUTHOOR_PORTAL_CANONICAL=true` (after comparison) makes `/al-zoubi` redirect (302) to the canonical portal URL and `/families/al-zoubi` render it. Genealogy, branch, person, entity, news and directory URLs are unchanged.

### 17.6 Performance

| Scenario | Server time (median of 5) | Queries |
|---|---|---|
| Seeded Al-Zoubi (123 people, 6 local test entities), family host | 53 ms | constant (test: adding 16 entities with 48 records adds 0 queries) |
| Same + 10,000 published people | 494 ms | constant |
| Legacy static `/al-zoubi` | 21 ms | — |

The portal is dominated by the single tree-graph load (as entity pages, §13); caching only if production measurements require it.

### 17.7 Screenshots

`docs/screenshots/ux-p0.2/` — Al-Zoubi desktop hero (synthetic test cover labelled as such), hierarchy grid, mobile header and menu, tree entry from the hero search, branding administration, draft preview, second (synthetic demo) family portal, site-admin domains, family dashboard panel. Entities named «(اختبار)» exist only in the local verification database; the repository seeds none.

### 17.8 Limitations

- Content sections use one root entity (configurable); a family with several top-level entities shows all of them in the hierarchy but takes content from the root.
- Verification is an audited administrative confirmation; the application does not perform DNS/TLS checks itself.
- Internationalized (non-ASCII) domain names are not accepted; punycode must be entered.
- Branding publication needs one family administrator (no second approver); every change is audited.
- Logged-in members see the public version of the landing page; members-only content stays on entity pages.
- The tree page itself is unchanged (UX-P0.3).

## 18. Unified tree redesign (UX-P0.3, 2026-10-27)

### 18.1 UX-P0.2 verification gate

| Check | Result |
|---|---|
| HEAD / tree | `6e2e4e2` (UX-P0.2 committed by the maintainer), clean before this phase |
| SQLite full suite | 421 / 421 |
| PostgreSQL 16 full suite (disposable container) | 420 / 421 — `CommunityEventTest` compared audit actions without `ORDER BY`; PostgreSQL does not guarantee row order. Test fixed (`orderBy('id')`), file re-run 3× green; the same latent pattern fixed in `CommunityMediaTest`, `CommunityNewsTest`, `CommunityEntityTest`. Not an application defect. |
| Migration `2026_10_27_082059` rollback / re-apply | Disposable database `juthoor_mig_p2` in a disposable container: migrate + seed + domain request + portal draft; rollback dropped both tables with genealogy untouched (85 people); re-apply restored the schema; database dropped afterwards |
| Root `.htaccess` (probed with Apache 2.4 on a scratch copy, dummy files only) | **Confirmed defects:** `php_value error_log "error_log.txt"` is relative to `public/` (front controller) → PHP error logs would be web-downloadable (200); `Options All` → directory listings (200) and server-side includes executed. **Fix:** `Options -Indexes -Includes -ExecCGI +FollowSymLinks`, error log to `../storage/logs/php-errors.log`, `<FilesMatch "(^error_log\|\.log$)"> Require all denied`. After: logs 403, listings 403, SSI not executed, `/.env` still routed to the front controller, normal files unaffected. The duplicated rewrite rules are behaviourally harmless and were left as they are. |

### 18.2 Previous renderer (findings)

Hand-written SVG in `genealogy-tree.js`: every person a 240 px card in a classic top-down tidy layout; single-child chains took a full row per generation (the real Al-Zoubi tree was **780 × 10,149 px**, one column of 75+ ancestors); every node redrawn on each pan/zoom; full tree fetched in 160-person batches with five absolute URLs per node; branch focus recomputed client-side; the attribute legend was rebuilt from nodes. At 10,000 synthetic people: 60 requests, 3.9 MB, 56,679 SVG elements, ~403 ms per pan frame, 1.7 million px wide, 23 s to ready. The library (plain SVG) is adequate; layout, payload and rendering strategy were the bottlenecks — extended in place, no new framework.

### 18.3 Architecture

| Layer | Implementation |
|---|---|
| Payload (`ProgressiveGraphService::batch`, `all=1`) | Same privacy rules (people and edges `publiclyVisible`, both ends visible). Per node: id, display name, verification, attribute ids, founder (if any). Per edge: id, child, father, **verification**. Per batch: attribute dictionary (from the lookup table, active only, global or this family) and URL templates with an id placeholder — management templates only for permitted actions (`person.create`, `person.edit-unpublished`, `person.propose`). Batches up to 2,000 (`batch=`); ≈186 bytes per person. |
| Founders | Only approved associations: published branches (members: branches with a founder) by `root_person_id` → classification «مؤسس <branch type>», branch name, description (published branches only), verification, branch-tree URL, public entity page URL (`CommunityHierarchy::publicIds`); the tree root as «الجد الجامع للشجرة». Never from names or position. |
| Layout (`public/js/tree-layout.js`, pure, Node-tested) | visible set per view (full / ancestors / descendants / fakhd focus or isolated with the `BranchFocusService` path); units: cards, **compact chains**, **sibling grids**; tidy placement with **row wrapping** for wide families (connector corridor); mirrored for RTL. |
| Renderer (`public/js/genealogy-tree.js`) | one load, viewport virtualization, three levels of detail by zoom, connectors drawn as three paths (verified solid / sourced light / pending dashed), founder cards, chain rows with fold, grid cells, popover, panel/bottom sheet, breadcrumb, views, history, fullscreen, keyboard, touch pan/pinch, search, UX-P0.1 quick add. |
| Fakhd page | `GenealogyTreeController::branch` passes the `BranchFocusService` ancestor path (approved father chain, limited to people the viewer may see) as `data-branch-path`; the same renderer is used. |

**Compact lineage algorithm** — a chain is a maximal run of ≥ 3 people who each have exactly one *visible* child and are not founders, the root, collapsed, or the view's focus. It is drawn as one card with one row per person in father → son order, with a dot coloured by verification. Runs longer than 9 show the first 3 and the last 3 around a fold («⋯ N جيلًا — إظهار»). Search, the breadcrumb and keyboard selection expand the fold that contains the target before focusing it. The chain is presentation only: it adds and removes no edge. The note under the toolbar says that a compact chain does not deny unpublished branches; the number of hidden children is never revealed.

**Sibling grids and wrapping** — ≥ 5 children of one father without visible children of their own form a grid under him. A father whose children's sub-trees would be wider than 3,200 px places them in rows beside a connector corridor (orthogonal connectors in the gaps, never across cards).

**Founder nodes** — wider (272 px) cards with a dark heritage band, gold border, «★ classification» and branch name. Mouse hover or keyboard focus shows a popover (description, verification, links to the fakhd page and branch tree); touch opens the same content in the bottom sheet. The root uses a bronze band.

**Attributes** — chips with the administrator's colour and text colour plus a «◆» marker and the label (not colour alone); the legend lists the attributes in use with their labels; inactive attributes and other families' attributes are never sent. A legend note: honorary attributes are not verification.

### 18.4 Performance (synthetic labelled fixtures, Chrome 1440×900, local server, median of 3; host load average 13–16)

| People (published) | Requests / payload | Ready | Layout | Draw | Pan frame p50 / p95 | Fit | Search → results* | Focus | JS heap |
|---|---|---|---|---|---|---|---|---|---|
| 100 (96) | 1 / 21 KB | 597 ms | 4.5 ms | 0.1 ms | 33 / 34 ms | 31 ms | 320 ms | 33 ms | 14 MB |
| 1,000 (935) | 1 / 171 KB | 602 ms | 11 ms | 0.1 ms | 33 / 34 ms | 41 ms | 316 ms | 21 ms | 18 MB |
| 5,000 (4,717) | 5 / 870 KB | 863 ms | 42 ms | 2.8 ms | 33 / 34 ms | 39 ms | 320 ms | 25 ms | 21 MB |
| 10,000 (9,444) | 10 / 1.7 MB | 1,335 ms | 64 ms | 3.6 ms | 33 / 34 ms | 42 ms | 327 ms | 21 ms | 17 MB |

\* includes the 250 ms typing debounce. 33 ms = two animation frames (the measurement waits for both). Mounted SVG groups stay below ~700 at any zoom (virtualization + level of detail). Previous renderer at 10,000: 23 s, 60 requests, 3.9 MB, 56,679 elements, 403 ms per pan frame. Measurements before the row-wrapping change; the change affects only placement (Node test: 10,000 people laid out well under 1.5 s).

### 18.5 Privacy verification

Feature tests (`TreeExperienceTest`): the public payload contains exactly the `publiclyVisible` people and edges (each once); living, unknown-status and pending people, unpublished branches and their descriptions appear in no node, edge, founder, attribute dictionary, search result or tree HTML; members' private preview needs membership; other families never appear; unpublished entity pages are not linked; inactive and foreign attributes are omitted. Chrome: the public payload of the real seeded tree has 85 nodes (119 in the database), no canonical keys, living flags or birth years.

### 18.6 Screenshots

`docs/screenshots/ux-p0.3/` — before: full tree (default and fitted), mobile; after: full tree, long chain folded and expanded, founder popover, fakhd Bakr, search and focus with breadcrumb, mobile start and founder sheet, tablet, person actions (editor, private preview), large branching and attribute legend (labelled synthetic fixture: the seeded public Al-Zoubi records carry no attributes and have no large sibling groups).

### 18.7 Limitations

- The full overview of thousands of people is navigable but not legible at fit; reading happens zoomed in, in a branch, or through search.
- Attribute "icons" are a shared marker (◆) plus the label; the lookup table has no icon column (not added in this phase).
- Children order follows edge id (record order); there is no birth-order field.
- The fold threshold (9) and wrap width (3,200 px) are constants, not settings.
- Print/export views do not exist yet (nothing to leak; any future export must reuse `batch` rules).
- Shared-ancestry (cross-family) links are not drawn inside a family's tree; governance is unchanged (super-admin edits).

## 19. Family, clan & fakhd portals and the heritage experience (UX-P0.4 + UX-P0.5, 2026-10-27)

### 19.1 Baseline and UI audit (before)

Baseline at `5ef3cb3`, clean tree: SQLite 428/428, PostgreSQL 16 428/428, Node 11/11. Browser audit (Chrome, 1440 and 390 px, screenshots `before-*` in `docs/screenshots/ux-p0.4-p0.5/`):

- **Three visual languages.** The family portal (`portal.css`, `--portal-*`), the platform/entity pages (`juthoor.css`, `--juthoor-*`) and the tree (`--tree-*`) each defined their own colours, cards and badges. Leaving the family portal for an entity page dropped the family header, navigation and branding.
- **Entity pages were one long column.** All 14 sections were stacked (hundreds of empty-state lines on sparse entities), with no way to jump to history, diwans or sources except a link bar. The tree appeared only as a static founder preview.
- **The directory was a flat list.** It had no search, level filter, founder, counts or cover image.
- **Diwans had no page of their own.** A diwan shared by two fakhds could only be reached inside an entity page.
- **Verification vocabulary differed by module.** Badges were coloured differently, and "alternative account" sometimes replaced the record's status text.
- **Mobile.** Section links overflowed horizontally; there was no family navigation outside the portal home.

### 19.2 Design system (Workstream A)

`public/css/design-system.css` is now loaded by every layout (`app`, `family`, `management`, `portal`, `family-site`).

- **Tokens.**
  - Palette: `--j-green #133D32`, `--j-green-2 #285D4E`, `--j-ivory #F6F4EE`, `--j-sand #EEE5D6`, `--j-gold #B28C54`.
  - Brand tokens `--j-brand`, `--j-brand-2`, `--j-accent`, `--j-tint`, which the family's published branding overrides.
  - Surface, ink and line tokens, verification colours, radius, shadow, spacing and the focus ring.
- **Legacy aliases.** `--juthoor-*`, `--portal-*` and `--tree-*` point at the tokens, so earlier pages follow without rewrites.
- **Components (`.j-*`).**
  - Shell, section, section head, buttons, links, breadcrumb, hero (with a cover), pills.
  - Verification label, with glyph plus text, never colour alone.
  - Cards (entity, founder, content), stats, tabs, filters/chips, search, timeline (alternative accounts styled), article/prose, evidence/sources, gallery, file, empty/skeleton/error states, embed, footer.
- **Blade components.** `x-ui.verification`, `x-ui.empty`, `x-ui.section-head`, `x-ui.founder-card`, `x-family-site.header` and `x-family-site.footer`.
- **Shared family frame.** `layouts/family-site` (header, mobile `<details>` menu, footer, branding tokens) serves the portal home, directory, entity pages, diwan pages, news, events and the member directory.
- **Navigation.** `FamilySiteChrome` builds the navigation from published data only. A view composer supplies it to child views. `aria-current` marks the current page.

### 19.3 Portals (Workstream B)

| Area | Implementation |
|---|---|
| Family landing | Unchanged `FamilyPortalPage` with new **geography** and **gallery** sections (`PortalSettings::withNewSections` inserts missing keys after their default predecessor, visible, keeping any saved order; 19 configurable sections). |
| Directory (`EntityDirectory`) | Public entities in hierarchy order. Per card: path, founder (published branch → publicly visible person only, never inferred from a name), published-record count via the public graph, sub-entities, public cover, branch tree link. GET search over names, recorded alternate names and founder names (Arabic-normalized), level filter, "within" filter. |
| Entity portal (one view for tribe → branch) | Hero with cover, breadcrumb, level, founder, counts and actions; the existing **scope switch** (`هذا المستوى فقط / مع الفروع التابعة`) drives every tab. Nine accessible tabs: نظرة عامة, الشجرة, التاريخ, الأصول والمناطق, الدواوين, الشخصيات, الأرشيف والمعرض, الأخبار والمناسبات, المصادر. Each tab has a count and is server-rendered (works without JS). Old anchors (`#history`, `#section-history`, `#children`…) resolve to their tab. RTL arrow keys. |
| Tree integration | No new renderer. The tree tab holds a placeholder; `entity-portal.js` creates an iframe to the existing `genealogy.branch` (or `genealogy.tree?person=` for the root) with `embed=1` **only when the tab opens**. Embed mode hides site chrome. Measured: one graph request. The `private` parameter is only used in manager preview. |
| Founder cards | `x-ui.founder-card` from the approved branch association (`CommunityGenealogy` anchor), with founder and branch verification. Fix: the founder query did not select `verification_status`, so every founder showed «قيد المراجعة»; it now shows the recorded status. |

### 19.4 Heritage (Workstream C)

- **History.** A `j-timeline` ordered by section, primary account first. A date or period appears only when the record has `period_label`, `start_year` or `end_year`. Alternative accounts stay visible with their recorded status («محل خلاف») plus «رواية تاريخية دون تحقق نهائي». Chip filters cover each section and the alternative accounts.
- **Archive and gallery.** Client-side search and category chips (no extra requests). Files are served only through `media.show` and filtered by `MediaAccess` (published, rights and audience).
- **Diwans.** Cards link to a new **detail page** (`community.diwans.show`):
  - The owner entity must be public and the record `visibleTo` the viewer, or a manager preview.
  - Images go through `MediaAccess`.
  - Related public entities are listed once each, so a shared diwan is one record with one page.
  - Address, map and keyless Google directions appear only when the place is not a private residence or consent is recorded.
  - Contact details appear only with contact consent.
- **Geography.** Grouped by kind (origin, settlement, migration, gathering). No coordinates are stored or invented.
- **Notable people.** The non-exhaustiveness disclaimer and alphabetical order are kept, with neutral category filters. The existing model rule is unchanged: achievements of people who are not publicly visible (for example, living people) are hidden from visitors. Only publicly visible people are linked.
- **Evidence.** One `x-community.evidence` (verification label, scope, review date, numbered approved citations) on every record type. The sources tab lists each approved source once.

### 19.5 Fixture

`HeritageShowcaseFixtureSeeder` (`demo-heritage`) has the same guards as the other fixtures: local/testing only, `JUTHOOR_DEMO_FIXTURES=true`, never called by `DatabaseSeeder`, idempotent.

- **Why it publishes content.** Public portal pages can only be reviewed with published records.
- **Labelling.** Every name, title and source starts with «[تجريبي]», and the family name carries «بيانات تجريبية اصطناعية».
- **Images.** The images are abstract GD stripe patterns, titled and described as synthetic. They are generated only when GD is available.
- **No real data.** There are no coordinates or real addresses, the phone number is `0000000000`, and al-zoubi is never touched.
- **Contents.**
  - Hierarchy: clan → two fakhds (+ a sub-fakhd), plus one fakhd with no tree.
  - Branches with founders.
  - A living person and a pending person.
  - Narratives, including an alternative account.
  - Shared locations.
  - A diwan shared between two fakhds.
  - A private residence with consent (contact without consent).
  - Achievements and archive records.

### 19.6 Performance (in-process requests on the scratch database, median of 3 warm runs)

| Page | Before (HEAD) | After |
|---|---|---|
| Al-Zoubi family host landing | 20.5 ms / 67 queries | 26.1 ms / 71 queries |
| Al-Zoubi directory | 10.0 ms / 11 | 16.4 ms / 24 |
| Al-Zoubi fakhd Bakr | 24.4 ms / 50 | 27.6 ms / 55 |
| Showcase landing / clan / diwan / embed | 28.8 / 30.6 ms; diwan and embed new | 32.1 ms (98 q) / 35.5 ms (84 q) / 13.2 ms (26 q) / 13.1 ms (22 q) |
| 10,000-person fakhd page | 272 ms / 50 | 265 ms / 55 |
| 10,000-person directory | 12.8 ms / 11 | **238 ms / 24** |

The extra queries come from the shared chrome and the directory's founder, cover and count lookups. Query counts do not grow with the number of entities.

The directory on a 10,000-person tree now loads the public graph once to count published records, which is the same cost an entity page already had. Caching the public graph per tree version is recommended for UX-P0.6 if production timings require it.

### 19.7 Screenshots

`docs/screenshots/ux-p0.4-p0.5/` contains 14 before-shots and 17 after-shots at desktop and 390 px.
- **Before:** the platform home, the Al-Zoubi portal and legacy page, the directory, the clan and fakhd pages, and the person page.
- **After:** the Al-Zoubi portal, the showcase landing, the directory, the history, tree, archive and sub-fakhd tabs, a diwan page, the mobile pages, a second family's branding, an entity preview and the entity editor.

### 19.8 Limitations

- Tabs are client-side; with JavaScript off, all sections render in sequence (by design).
- The embedded tree is an iframe of the existing page; it is not a second renderer, and its toolbar still offers full-page navigation.
- Achievement category filters reload the page (`?category=`), whereas archive and gallery filters are client-side; both are kept as they were.
- No real Al-Zoubi heritage content, cover or diwans exist yet, so the Al-Zoubi pages show empty states (data, not code).
- Safari and Firefox, real devices and screen readers other than Chrome's accessibility tree were not tested.


## Unified development genealogy rebuild — 2026-10-27

The default seed path now consumes a separate normalized, source-traced Al-Zoubi proposal through `AlZoubiFamilyTreeSeeder`, followed by scoped local-only `SiteUsersSeeder`; RBAC and PersonAttribute catalogs remain intact. The final proposal has 1,688 people, 1,687 father edges, 17 branches (16 linked founders), one family and one tree. The 79-person supplied ancestry is shared once; ROOT-076 is Yusuf, ROOT-078/079 replace prior Rahaymeh anchor aliases. Qawasmeh includes its 34 submitted identities and additional chart-supported descendants. All descendants retain unknown living status/pending review. No chart-only death inference or automatic historical verification is performed.

All v4 rows are classified, including 1,428 parentless/blank source rows retained for review. The recursive inventory includes editable Diagram Designer evidence beyond the PNG/PDF/XLSX copies. Conflicting Awtalat ancestry, Faqara/Abdul-Latif family attachment, and unresolved chart identity mappings remain review material. The safe initialization command refuses nonempty/nonlocal targets and never drops tables. Previous seeder implementations are explicitly archived outside the active seeder directory; audit/test dependencies use the archive namespace.

See [source inventory](genealogy-source-inventory.md), [database coverage](genealogy-import-coverage.md), and [architecture, accounts, commands and validation](genealogy-seeder-reference.md). Screenshots and browser journeys are in `docs/screenshots/genealogy-rebuild/`. No migration/source evidence was deleted; no production database, deployment, subdomain activation or Git commit/push was performed.

Final verification: SQLite 453/453 and PostgreSQL 16.15 453/453 (11,760 assertions each); PostgreSQL final regression subset 20/20; Node layout 11/11. Raw unified source observations are now reviewer-only, including direct download access. The PostgreSQL savepoint/lock-exhaustion defect was fixed in the importer without raising server limits. Development canonical portal routing uses the existing database-backed portal and entity components; production host routing remains unchanged. See `genealogy-validation-results.json`.


## Final seeder cleanup — 2026-10-27

Completed the remaining physical cleanup. `database/seeders/` now has exactly the five authoritative entry-point/catalog files: DatabaseSeeder, RolePermissionSeeder, SiteUsersSeeder, PersonAttributeSeeder and AlZoubiFamilyTreeSeeder. Optional UI/stress fixtures moved into `database/seeders/Fixtures/`. Historical genealogy implementations moved into `app/Services/Imports/LegacySeeders/` for audit/reconciliation and regression compatibility; application/test imports now use their explicit archive namespace. No historical implementation or source evidence was discarded. The new bootstrap reads `AlZoubiSeedDefaults` rather than depending on archived seeders.

The previously outstanding legacy-seeder relocation is complete. Historical ambiguities remain deliberately unresolved rather than guessed. Development accounts and the improved PersonAttributeSeeder remain in the authoritative path. No database reset or reseed was needed for this file-organization change.

At the user's explicit request, no checks or tests were run after this final cleanup. Earlier recorded test results apply to the implementation before this namespace/file relocation.


## UX-P0.6 — Sanad Directory & Community Experience

Implemented دليل السند using the existing Phase 4 MemberDirectory, member-profile consent, affiliation, reporting, notifications and family-scoped Directory Moderator permission. Added the directory hero/data-derived categories, grid/list search, normalized field-specific filters, professional profiles, guided privacy-preview onboarding, scoped fakhd links and moderation decision history. Two JSON search-field columns are backfilled without widening consent. Directory pages are private/no-store; current affiliation publication and revoked memberships are honored. No genealogy renderer, PersonPrivacy or role privileges were changed.

Final directory tests: SQLite 14/14 and PostgreSQL 16.15 14/14 (142 assertions each); Node 11/11; Chrome 13/13 desktop/mobile journeys. Full SQLite and PostgreSQL runs: 424/459 passed with the same 35 baseline failures, no new failing test names. Baseline was 418/453. The final moderator case and final focused adjustments were verified in the complete directory subset after the full runs. Functional phase is implemented; repository-wide regression/release sign-off is still blocked by those existing failures. UX-P0.7 readiness is conditional, not an all-green claim.

See [implementation, audit, privacy, performance, files and limitations](sanad-ux-p0.6.md), [test results](sanad-ux-p0.6-results.json) and [before/after screenshots](screenshots/ux-p0.6). No deployment, commit, vendor/dependency edits or production database changes were performed.


## UX-P0.7 — Hybrid unified genealogy visualization (2026-10-28)

Implemented the existing SVG renderer's hybrid chart: every default ancestry card is visible in alternating horizontal rows; descendants use iterative subtree-width allocation and a generation grid. Siblings no longer wrap or become grids. Optional compaction applies only to ancestry and retains founder/focus cards. The true ancestry-to-hierarchy transition uses eligible children in the selected scope before display-mode folding.

Added independent Full / Main Fakhds / Subordinate Fakhds expansion modes. Hierarchy-derived boundary metadata preserves founders and required paths at arbitrary depths (including 5, 7 and 9); terminal main branches remain expandable. Manual overrides are separate, mode changes reset them predictably, and search reveals the required path without opening unrelated branches. Existing scope filters, styling/attributes, profile links, keyboard, pan/zoom, fullscreen, history and embedded/mobile views remain. No genealogy identities, father/founder associations, seeders, privacy or RBAC rules were changed.

Validation: Node 40/40; Chrome 16/16 actual journeys with before/after screenshots and no page errors. Relevant SQLite and PostgreSQL 16.15 suites each passed 52/59 with seven previously recorded expectation failures, zero runtime errors and no new failing test names. The new boundary API test passed separately (8 assertions). Functional UX-P0.7 is complete; all-green regression/release approval and UX-P0.8 readiness remain conditional on reconciling the pre-existing failures.

See [layout architecture, behavior, performance, limitations and changed files](tree-ux-p0.7.md), [machine-readable results](tree-ux-p0.7-results.json) and [screenshots](screenshots/ux-p0.7). No production database/reset, dependency/vendor changes, commit, push or deployment occurred.


## UX-P0.8 — News and community publishing (2026-10-28)

Implemented the existing publishing experience: Arabic-normalized search and date/category filters, explicit publisher-only featured news, approved media cards, public related content, celebration/condolence presentation, calendar/list views and event-state filters. Added safe local editorial previews, contextual death fields and share/copy links. Reused hierarchical content scope, review/consent, media access, sources, audit and in-app notifications. Corrected the save policy call to retain module-scoped news-publisher grants; ordinary editors cannot feature or publish. No genealogy layout/data/privacy or unrelated architecture changes.

Validation: six new cases pass; full SQLite snapshot 429/464 with the same 35 historical failure names; final relevant SQLite 52/57 with five previously recorded expectation failures. Node 40/40. Chrome public/mobile and editor/publisher journeys pass with no page errors; baseline/current screenshots use synthetic local data. Full PostgreSQL 16.15 snapshot: 430/465, with the identical 35 historical failure names; the final six new tests pass on both databases. Chrome: 21/21 journeys, zero page errors, with three reconstructed baseline screenshots. Full-suite counts differ because additional tests were added between snapshots. Previously failing privacy/seeder/static-route expectations remain a regression/release blocker; UX-P0.9 release readiness is conditional.

See [implementation, workflows, constraints, performance and reproduction](community-ux-p0.8.md), [exact results and changed files](community-ux-p0.8-results.json) and [screenshots](screenshots/ux-p0.8). Apply the additive discovery migration through the normal deployment workflow. No production database reset, dependency/vendor changes, commit, push or deployment occurred.
