# Juthoor — Implementation Roadmap & Phase 1 Backlog

Planning phases below map onto the ten-step roadmap in v2.0 chapter 22. Deferred requirements stay in scope; see `docs/project-audit.md` for statuses.

## Updated priorities (approved 2026-10-27)

- Experience v3.0 transformation starts with **UX-P0.1** (people from the tree, review, RBAC and role seeder, safe family seeders) before portal redesign; see the UX-P0.1 section below.

## Earlier priorities (approved 2026-10-26)

- **Deferred, not cancelled:** Phase 2.6 Mothers, spouses & life events (JTH-06); Phase 2.7 GEDCOM import/export (JTH-12); Facebook publishing (JTH-25, UC-17).
- **Order now:** Phase 3 (starting with 3.1 entity architecture and portals), then Phase 4 without Facebook integration.

## Phases

| Phase | Objective | v2.0 roadmap steps | Requirements | Depends on | Exit criteria |
|---|---|---|---|---|---|
| 0 — Audit & stabilization | Verified baseline, traceability, green suite on SQLite + PostgreSQL | 1 | — | — | ✅ Done 2026-10-22: 194/194 green on both engines, CI workflow added |
| 1 — Family Tree MVP | Database-backed public tree, person/relationship/branch management, publication governance, family dashboard | 1, 3 (partial) | JTH-01–03, 07, 13, 14; UC-01–05, 07–09 | 0 | ✅ Done 2026-10-22: Definition of Done below |
| 2 — Genealogy governance | Assertions & competing narratives, field-level privacy, merge with undo, revert-any-change, mothers/spouses/events, GEDCOM, branch-scoped roles, notifications | 2, 8 | JTH-06, 08–13, 28 | 1 | UC-06, UC-10, UC-12 fully met; no leak via search/API/export |
| 3 — Hierarchical identity & community pages | Entity pages per level, hierarchical filtering (direct/with descendants), counters with coverage, regions, diwans + Maps, personalities with disclaimer, archive | 4, 9 | JTH-04, 15–20, 31–35 | 1, 2 (privacy) | UC-13, 14, 19–22 — **✅ Done 2026-10-26 (3.1–3.7)**; content search/API/export pending until such endpoints exist |
| 4 — Community & extended | News, deaths/condolences, celebrations, events, member directory & professional profiles; Facebook deferred | 5, 7 (6 deferred) | JTH-21–24, 26, 27 (25 deferred) | 3 (audience scopes) | UC-15, 16, 18 — **✅ Done 2026-10-26** |
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
5. ✅ Shared-ancestry spelling reconciliation workflow (D-06) and persisted link validity (R-04) — done 2026-10-26 as Phase 2.5, see below. The 39 differences stay open until reviewed with evidence.
6. Mothers, spouses and events (JTH-06) — **deferred** as Phase 2.6; GEDCOM 5.5.1 import/export (JTH-12) — **deferred** as Phase 2.7 (priority change 2026-10-26).

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

## Phase 2.5 — Historical name reconciliation & shared-ancestry validity (done 2026-10-26)

- [x] **P2.5-00 Baseline** — HEAD `b1f6bf8`, 277/277 twice; performance baseline on 2 × 2,000 people / 5 links.
- [x] **P2.5-01 Reconciliation queue** — per-family page with ROOT key, displayed name, proposed spelling, list, evidence, competing claims, verification, affected branches, shared families, pending change, decision history; filters and summary; dashboard count; sidebar entry.
- [x] **P2.5-02 Decisions** — reuse classify / cite / propose; new reviewer `decline` with reason; spelling acceptance adds a variant without renaming (D-08); unresolved stays open; shared ancestors need the super admin.
- [x] **P2.5-03 Persisted validity** — `validated_at` + `validation_stamp`; full check on writes, approval, explicit revalidation and review page; aggregate stamp on read; bounded locked re-check of stale links; same-second protection.
- [x] **P2.5-04 Invalidation** — every approved link re-checked per write (D-07); fingerprint tree part shared within a write (hash unchanged); claim evidence excluded.
- [x] **P2.5-05 UI** — validity badge, last full check and "إعادة التحقق الآن" on the links index and review page.
- [x] **P2.5-06 Tests** — `NameReconciliationTest` (10), `SharedAncestryValidityTest` (16 incl. dataset cases), mutation-checked; 303/303 SQLite and PostgreSQL 16; Chrome journeys 11/11.
- Remaining: actual evidence-based decisions on the 39 differences (data work, not code); bulk actions on the queue; a scheduled sweep that re-checks stale links without a visitor; branch-level dependency tracking if branches ever appear on the shared page.

## Phase 3.1 — Unified hierarchical entity architecture & portals (done 2026-10-26)

- [x] **P3.1-00 Baseline** — HEAD `3d958bb` (Phase 2.5 committed), 303 tests; v2.0 chapters 3, 5–7, 13, 17–18 re-read.
- [x] **P3.1-01 Architecture map** — Person / User / Family / Family tree / Branch / Community entity (`docs/project-audit.md` §12). Decision: entities are a separate table referencing branches; `Branch` and its founder rules are unchanged.
- [x] **P3.1-02 Data model** — `community_entities`, `community_entity_managers`, `entity_content_links` (one reversible migration); `Branch::communityEntity`, `Family::communityEntities`.
- [x] **P3.1-03 Hierarchy integrity** — same family, no self-parent, no cycle, optional levels, no child above its parent, no family move; family row locked on writes.
- [x] **P3.1-04 Public pages** — index and entity page with breadcrumbs, children (direct / with descendants), founder/branch/tree links, publication, review date, completeness, correction route, future sections marked unavailable; preview for managers; link from the public tree.
- [x] **P3.1-05 Content scope foundation** — `EntityContentScope` (direct / with descendants; subject rolls up, audience flows down; distinct ids; eligible entities) and `HasCommunityEntities` (owner, links, `visibleTo`, owner-only edit rights).
- [x] **P3.1-06 Administration** — dashboard panel, sidebar entry, hierarchy list, create/edit form (parent, type, names, descriptions, tree/branch link, publication, review), entity managers (editor/admin) assigned by family admins; audited.
- [x] **P3.1-07 Authorization** — `CommunityEntityPolicy`: family editors draft, family/entity admins publish, entity roles limited to the subtree and to family members, destination checked on moves, cross-family requests 403/404.
- [x] **P3.1-08 Tests** — `CommunityEntityTest` (10), `CommunityContentScopeTest` (6), mutation-checked; 319/319 SQLite and PostgreSQL 16; Chrome journeys 8/8.
- [x] **P3.1-09 Documentation** — audit §12, this section, progress log session 9.
- Remaining: content modules (none exists yet), entity-scoped search/API/export once content exists, cross-family tribe ownership, per-family level labels, migrating the static Al-Zoubi portal pages onto entities (needs reviewed content).

### Phase 3.2 readiness
- A content module needs only: a table with `owner_entity_id`, `HasCommunityEntities`, a `scopeVisibleTo` for its publication/audience rules, and links via `EntityContentScope::link`. Entity pages already reserve its section.
- Authorization for module records: `isEditableBy()` (owner entity) — visibility on another page grants nothing.
- Not started (by instruction): Phase 3.2–3.6 modules, Phase 2.6, GEDCOM, Facebook.

## Phases 3.2–3.6 — Complete community portals (done 2026-10-26)

- [x] **P3.2–3.6-00 Baseline** — HEAD `80d5c91`, 319/319 on SQLite before changes, migrations apply, no regressions.
- [x] **Shared content layer** — `CommunityContent` (publication, verification, audience, review, evidence, `visibleTo`), `CommunityContentService` (single audited write path), `content_citations` (family sources), module registry, generic admin controller, review queue; reuses `EntityContentScope`, `HasCommunityEntities`, `CommunityEntityPolicy`/`CommunityEntityAccess`.
- [x] **3.2 History & geography** — `entity_narratives` (six sections, primary/alternative accounts, periods) and `community_locations` (classification, era, period), sourced and shareable.
- [x] **3.3 Genealogy preview** — founder, ancestor chain, mini tree, descendant count, related branches, approved shared ancestors, sub-entity founders, links to the full tree; empty state for community-only entities; one scope switch for all sections with the section anchor kept.
- [x] **3.4 Statistics** — registered / verified / published / living (members) / branches / father-link coverage, distinct person ids, sourced population estimate, coverage «غير معروفة», approved disclaimer.
- [x] **3.5 Diwans & Maps** — shared diwans, keyless Google Maps directions, optional Embed API key, validated links and coordinates, consent for contacts and private residences.
- [x] **3.6 Notable people** — positions/achievements on existing people, neutral categories, alphabetical, approved disclaimer, privacy by the person's visibility; deletion/merge blockers.
- [x] **Portal & administration** — functional sections replace the placeholders; entity edit page lists module content; dashboard pending count; sidebar review queue; source page lists content usages.
- [x] **Tests** — `CommunityPortalTest` (14), Phase 3.1 tests updated for the new sections (intent unchanged); 14 rule mutations each caught; 333/333 SQLite and PostgreSQL 16; Chrome journeys 12/12.
- Remaining Phase 3 items: archive & media (JTH-15) and diwan images (need a public media store); content search/API/export with the same scope; migrating the static Al-Zoubi pages after content review.

### Phase 4 readiness
- News, deaths/condolences, celebrations and events can be new `CommunityContentModules` entries: a table with the governance columns, `CommunityContent`, `contentRules()`, a fields partial and a portal section. Owner/subject/audience, publication, review queue, evidence and audit come for free.
- Time-bound content (events, condolences) needs date-window rules in its `visibleTo` hooks; notifications and approval of sensitive announcements (deaths) are Phase 4 work.
- Member directory (JTH-26/27) must stay separate from `people` (ADR-008) and reuse `PersonPrivacy`-style levels.
- Facebook remains deferred.

## Phase 3.7 — Media library, heritage archive & Phase 3 closure (done 2026-10-26)

- [x] **P3.7-00 Baseline** — HEAD `781eb9c`, 333/333; storage, disks and upload handlers audited (source library kept as is).
- [x] **Secure storage** — private `media` disk (configurable), generated names, content-sniffed allow-list, size and pixel limits, markup refusal, checksums, metadata-free display copies (GD resize when available), authorized delivery with sandboxing headers, 404 for anything unreadable.
- [x] **Media model** — `media_items` as a content module (owner, links, publication, verification, audience incl. restricted, review, citations, rights, provenance, historical date), one file per family checksum, `media_attachments` with delete protection, consistent upload/replace/delete with cleanup.
- [x] **Heritage archive (JTH-15)** — `archive_records` with categories, source, period, contributor, person, rights, attached files, shared across entities without copies.
- [x] **Diwan images, narratives' files, entity covers** — attachments from the library; covers shown only when published for everyone.
- [x] **Portal & administration** — archive and gallery sections under the shared scope; media library per entity (upload, replace, metadata, rights, links, review, evidence, usage, safe delete); attach/detach on records; cover choice; review queue and dashboard count include the new modules.
- [x] **Integrity** — person deletion/merge blockers for archive entries; `media:verify`.
- [x] **Tests** — `CommunityMediaTest` (13), portal admin test covers all six modules; 17 media rule mutations caught; 346/346 SQLite and PostgreSQL 16; media tests also green with GD.
- [x] **Final Phase 3 regression in Chrome** — Phase 3.1 (8), 3.2–3.6 (12) and 3.7 (9) journeys: 29/29 on one fresh seeded database; `media:verify` clean afterwards.

### Phase 4 readiness (media)
- News, events, condolences and celebrations attach images and documents through `HasMediaAttachments` and the existing library, with no new upload code; time-bound visibility and notifications remain Phase 4 work.

## Phase 4 — Community news, events, publishing & member directory (done 2026-10-26)

- [x] **P4-00 Baseline** — HEAD `57ffda7`, 346/346; mail/queue/notification infrastructure audited (no in-app store).
- [x] **4.3 Shared publishing** — publication windows inside `visibleTo`, public notices with previous values, linked people, sensitive-approval rule, review/decision/change notifications (database channel), `/notifications` inbox.
- [x] **4.1 News & announcements** — seven categories, scheduling and expiry, search, media and evidence, corrections and cancellation; death notices and condolences with consent and family-administrator confirmation; genealogy never changed.
- [x] **4.2 Events & calendar** — separate event records with time zones and status history, Maps directions, announcement from event, calendar month/list views with filters.
- [x] **4.4 Member directory** — opt-in profiles, per-field visibility, audience-safe search and hierarchy filters, in-app contact, reports, affiliation review, suspension, deletion without genealogy impact.
- [x] **Integration** — portal sections, full news and calendar pages, detail pages, admin forms and panels, nav entries, person deletion/merge guards for linked people and deceased records.
- [x] **Tests** — 21 new tests; 21 mutation checks; 367/367 SQLite and PostgreSQL 16; Chrome: Phase 4 (8) plus Phase 3 regression (29), no page errors.
- Deferred (unchanged): Phase 2.6, Phase 2.7 (GEDCOM), Facebook (JTH-25), multi-family entity ownership.

## UX-P0.1 — Genealogy management, RBAC & seeder foundation (done 2026-10-27)

First phase of the approved Experience v3.0 transformation (P0-A, P0-B, P0-C of `Juthoor_P0_People_Roles_Family_Seeders_2026-10-27.md`). Details: `docs/project-audit.md` §16.

- [x] **P0-00 Baseline** — HEAD `4ed00b1`, 367/367 on SQLite and PostgreSQL 16; all reference documents present in `docs/`.
- [x] **A2 Quick add from the tree** — son added from a node with the father preselected and validated server-side, pending and private, duplicate warning and confirmation, sequential brothers.
- [x] **A3 Guided creation** — identity and father (lineage, derived branch), duplicate step with existing-person links, optional details, review summary with publication/approval effect.
- [x] **A4 Editing** — sectioned form, read-only states for published records and shared ancestors with a correction path, father-change impact.
- [x] **A5 Review** — submitter, values, impact, publication effect, earlier decisions, person/tree links, own-request notice, reason required. ("Request more information" not added — no lifecycle state.)
- [x] **A6 Tree actions** — add, edit, propose, focus descendants, sources/claims, person page with ancestors; desktop and 390 px. Tree layout unchanged.
- [x] **B1–B4 RBAC** — permission catalog over the existing membership roles; eight functional role templates; family-scoped, module-scoped content grants; separation of duties preserved; assignment UI with anti-escalation; role matrix page.
- [x] **B5 Role seeder** — `RolePermissionSeeder` / `rbac:sync` (dry run, no silent upgrades, customizations and assignments preserved, renames/deprecations, audit); super-admin role page.
- [x] **C1–C3 Seeders** — create-only family/tree/source/people/edges/branches/founders; father and name conflicts refused before writing; classification inside the transaction; demo password from the environment.
- [x] **C4 Preflight** — `genealogy:seed-audit` (read-only) and `/site-admin/seed-audit`.
- [x] **C5 Family bootstrap** — `family:bootstrap` from a reviewed manifest; no fabricated genealogy; minimal setup without founder.
- [x] **C6 Demo fixtures** — `JuthoorUiDemoSeeder`, local/testing + explicit opt-in, synthetic and unpublished.
- [x] **Tests** — 37 new tests; 17 mutation checks on the new rules (plus 9 by the seeder workstream); Chrome 8/8 journeys.

### UX-P0.2 readiness
Ready. Person, role and seeder foundations are in place and tested, so the design system and Figma work (UX-00/01) and the screens of UX-02…09 can use real workflows and the demo fixture family. Open inputs: decision on a "needs information" review state; whether media should become module-aware for news-only editors; reviewer decisions on the 39 ROOT spellings and the 34 pending Qawasmeh records; moving raw datasets out of `public/docs` (SD-08).

## UX-P0.2 — Family subdomains, dynamic landing pages & branding foundation (done 2026-10-27)

Details: `docs/project-audit.md` §17.

- [x] **Baseline** — HEAD `9882e9f`, 404/404 SQLite and PostgreSQL 16.
- [x] **Security prerequisite (SD-08)** — private datasets and source images moved out of the web root (30 files, checksums and provenance unchanged); exposure tests.
- [x] **Domain foundation** — `family_domains` lifecycle (unconfigured → pending → verified → active → disabled), request rules, platform-only verification/activation, reserved labels, `ResolveFamilyHost` (unknown hosts refused, cross-family paths 404, administration on the platform host), feature flag `JUTHOOR_FAMILY_HOSTS`.
- [x] **Dynamic family portal** — `FamilyPortalPage` over existing services; 17 sections with empty states; database statistics with disclaimer; hierarchy from entities or published branches; tree entry with search.
- [x] **Branding** — draft/preview/publish with audit, approved palettes, public media only, section visibility/order, contact links; dashboard panel.
- [x] **Transition** — legacy `/al-zoubi` unchanged; portal at `/families/{slug}/portal`; canonical redirects behind `JUTHOOR_PORTAL_CANONICAL`.
- [x] **Tests** — 17 new; 421/421 SQLite; Chrome 10/10. PostgreSQL 16 final run not completed — to re-run.
- [ ] **Production enablement** — DNS, TLS, proxy for `al-zoubi.juthoor.dopamind.cloud`, then verify/activate in site-admin and enable the flags (infrastructure task, not done here).

### UX-P0.3 readiness
Ready: the portal links to the unified tree through one entry point (`genealogy.tree`, `?q=`), so a new tree renderer (compact single-child chains, enlarged founder nodes, new pan/zoom) can replace `genealogy-tree.js` behind the same route without touching family sites. Dependencies: approved Al-Zoubi cover image and entity pages (data, not code); decision on caching the tree graph if production timings require it.

### Next recommended phase
UX-P0.3 (tree experience), then UX-00–01 (design system and portal screens on the verified workflows), then Phase 5 — production readiness (JTH-29): backups with a restore drill, monitoring and error reporting, security headers and CSP for the main site, 2FA for administrators, load tests at realistic family sizes, and caching of the tree graph only if measurements require it. Then decide whether to resume Phase 2.6/2.7 and define the content API/export (JTH-31) with the existing scope and privacy rules.
