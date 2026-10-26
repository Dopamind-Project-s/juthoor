# Juthoor — Development Progress Log

Use this file to resume work without repeating the audit. Statuses and IDs refer to `docs/project-audit.md` and `docs/implementation-roadmap.md`.

## Current state (2026-10-26, after session 12)

- Branch `main` at `57ffda7` (Phase 3.7 committed by the maintainer); session 12 (Phase 4) changes uncommitted (nothing committed by the assistant).
- Suite: **367 passed / 0 failed** on SQLite and PostgreSQL 16.
- Phase 1, Phases 2.1–2.5, Phase 3 (3.1–3.7) and Phase 4 complete. Deferred: Phase 2.6, Phase 2.7 (GEDCOM), Facebook, multi-family entity ownership. The 39 ROOT name differences are reviewable and intentionally still open.
- Priorities changed 2026-10-26: Phase 2.6 (mothers, spouses, life events), Phase 2.7 (GEDCOM) and Facebook publishing are deferred, not cancelled; Phase 3 continues, then Phase 4 without Facebook.

## Next task to execute

Phase 5 — production readiness (see `docs/implementation-roadmap.md`, "Next recommended phase").

## Work log

### Session 1 — audit, dashboards, hardening (Phase 0)
- Family dashboard (`/manage/families/{family}/dashboard`), workspace layout, super admin health dashboard.
- Fixes: audit detail visibility, scoped shared-ancestry invalidation, quadratic duplicate query, rate limits, password-reset enumeration, numeric route ids, PostgreSQL issues (audit action length, ordering).
- CI workflow `.github/workflows/tests.yml`.

### Session 2 — Phase 1 tree MVP
| Task | Change | Files | Tests |
|---|---|---|---|
| Baseline | Stale Qawasmeh assertions now derive from `manifest.json` (exact counts instead of `> 8` / `> 300`) | `tests/Feature/AlQawasmehBranchTest.php` | 9/9 |
| P1-05 | `PublicationGuard`, `FamilyPolicy::publish`; wired into mutation service, relationship and branch controllers; structural delete checks run first | `app/Services/Genealogy/PublicationGuard.php`, `GenealogyMutationService.php`, `RelationshipController.php`, `BranchController.php`, `FamilyPolicy.php` | `FamilyTreeMvpTest`; mutation check: guard disabled → 3 failures |
| P1-05 test updates | Four existing tests had editors publishing directly; they now submit `pending_review` (their integrity intent is unchanged) | `GenealogyManagementTest.php`, `ConnectedGenealogyWorkflowTest.php` | green |
| P1-16 | Shared verification options partial | `resources/views/management/partials/verification-options.blade.php` + 5 forms | `FamilyTreeMvpTest`, E2E |
| P1-04 | Duplicate confirmation on create | `ConnectedPersonService.php`, `ConnectedPersonRequest.php`, `person-fields.blade.php`, `juthoor.css` | `FamilyTreeMvpTest`, E2E |
| P1-03 | Branch types | `Branch.php`, branch requests, `BranchController.php`, `GenealogyMutationService.php`, `ConnectedPerson*`, branch forms/lists, public selector | `FamilyTreeMvpTest` |
| P1-19 | Founder badges | `PersonAttributeSeeder.php`; `PersonDescriptorsTest` count 6 → 10 | green |
| P1-11 | Static branch pages → DB focus link | `Branch::scopePublished`, `AppServiceProvider` composer, `families/al-zoubi/branches/*.blade.php` | `FamilyTreeMvpTest` |
| P1-08 | Search lineage | `GenealogyGraphController.php`, `public/js/genealogy-tree.js` | `FamilyTreeMvpTest`; `GenealogyGraphEndpointTest` unchanged |

### Session 3 — Phase 1 finalization
| Task | Change | Files | Tests / verification |
|---|---|---|---|
| 1. ROOT-077 conflict | In-place spelling reconciliation (ذبيان → ذيبان, variant kept, audited with reason, status unchanged); ROOT-078 alias to `AZ-RAHAYMEH-SULAYMAN` with pending citation; base people seeder respects recorded variants and refuses other differences; clear prerequisite error | `AlQawasmehBranchImporter.php`, `AlQawasmehBranchSeeder.php`, `GenealogyPeopleSeeder.php`, migration `2026_10_22_094613_add_alternate_names_to_people`, `Person.php` (`alternate_names`, `nameMatches`, `sameName`), person views | `AlZoubiAnchorReconciliationTest` (7); seed orders A/B/C on SQLite and PostgreSQL |
| 2. One public rule | `publiclyVisible` for people/edges, `Branch::published`, `verifiedHistorical` for cross-family links; `public_narrative` flag with backfill; guard treats narratives as published | `Person.php`, `PersonRelationship.php`, `Branch.php`, `ProgressiveGraphService.php`, `PublishedTreeTraversal.php`, `GenealogyGraphController.php`, `GenealogyTreeController.php`, `CrossFamilyLink*`, `FamilyDashboardService.php`, `PublicationGuard.php`, `AlZoubiSharedAncestrySeeder.php`, migration `2026_10_22_095033_add_public_narrative_flag_to_genealogy` | `PublicVisibilityTest` (10, five roles); backfill comparison on an old-code database: 79/78 identical; mutation check (7 failures without the flag clause) |
| 3. Proposal duplicates | `DuplicatePersonDetector` shared by direct creation and proposals; submit needs `confirm_distinct`; review needs reviewer confirmation; reviewer sees matches | `DuplicatePersonDetector.php`, `ConnectedPersonService.php`, `ChangeReviewService.php`, `ChangeRequestController.php`, `changes/show.blade.php`, `person-fields.blade.php` | 2 tests in `FamilyTreeMvpTest`; Chrome journey |
| 4. Membership consent | `FamilyInvitationService`; family-admin add = invitation with identical reply; super admin direct enrollment kept | `FamilyInvitationService.php`, `InvitationController.php`, `MembershipController.php`, `records/page.blade.php` | 4 tests in `AdministrationSecurityTest`; updated `GenealogyManagementTest`, `AdminFrontendTest` |
| 5. Site-admin tree routes | Super-admin only; family-scoped `management.trees.store/details`; shared `FamilyTreeManager` | `SiteAdmin/FamilyTreeController.php`, `Management/TreeController.php`, `FamilyTreeManager.php`, `routes/web.php`, `records/page.blade.php` | 1 test (direct URLs, cross-family, roles); updated `AdminFrontendTest` |
| Found: D-05 | `WithoutModelEvents` left seeded people without `name_search`; removed and backfilled | `DatabaseSeeder.php`, migration `2026_10_22_100207_backfill_people_name_search` | regression test (fails with the old seeder); backfill 84 → 0 on a real seeded DB |

### Session 4 — Phase 2.1 field-level privacy & living status
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Living status | Tri-state, strict public rule, no coercion, provenance hook, tri-state select, CSV/root defaults unknown | `Person.php`, `PublicationGuard.php`, `GenealogyMutationService.php`, `ConnectedPersonService.php`, `TreeRootService.php`, `CsvImportService.php`, `living-status-select.blade.php`, `person-fields.blade.php`, `root-fields.blade.php` | `PersonPrivacyTest` (unknown private, form keeps unknown, CSV) |
| Migration & classifier | `living_status_basis`, `field_visibility`; `LivingStatusClassifier`; seeders set provenance | migration `2026_10_22_102302`, `LivingStatusClassifier.php`, `AlZoubiGenealogySeeder.php`, `AlZoubiSharedAncestrySeeder.php` | classifier test; seeded-tree test (85 public, 34 Qawasmeh unknown); old-code database proof |
| Field privacy | `PersonPrivacy` (audience, levels, `fields()`, `redactSnapshot()` stricter-of) | `PersonPrivacy.php`, `GenealogyTreeController.php`, `AuditLogController.php`, `ChangeRequestController.php`, `ChangeReviewService.php`, `Store/UpdatePersonRequest.php`, `person-private-fields.blade.php`, `genealogy/person.blade.php`, `management/people/show.blade.php`, `records/page.blade.php`, `changes/diff.blade.php` | role matrix (reader/editor/admin/super), cross-family, private preview, JSON and search leakage, audit/proposal redaction, editor restrictions |
| Dashboard | `living_legacy` indicator; corrected text | `FamilyHealthService.php`, `dashboard.blade.php` | full suite |
| Fixtures | Tests that intended public people now declare `is_living => false` | `GenealogyExperienceTest`, `GenealogyGraphEndpointTest`, `BetaReadinessTest`, `PersonDescriptorsTest`, `AlQawasmehBranchTest` | assertions unchanged |

### Session 5 — Phase 2.2 assertions, sources & conflicting narratives
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Data model | `assertions`, `citations.assertion_id`, `change_requests.assertion_id`; model relations; deletion guard | migration `2026_10_25_111923`, `Assertion.php`, `Citation.php`, `ChangeRequest.php`, `Person.php`, `FamilyTree.php`, `GenealogyDeletionService.php` | rollback/re-apply on PostgreSQL |
| Lifecycle | record, classify, cite, propose; accept/reject from `decide`; supersession and preserved replaced values; `alternate_names` accepted through review | `GenealogyAssertionService.php`, `ChangeReviewService.php`, `GenealogyMutationService.php`, `Store/UpdatePersonRequest.php`, `AssertionController.php`, `SourceController.php`, `routes/web.php` | creation, citations, source reuse, alternate spelling, conflicting narratives, rejection/supersession, graph consistency, invalid parentage |
| Shared ancestors | `SharedAncestryScope`; guard requires super admin | `SharedAncestryScope.php`, `PublicationGuard.php`, `ChangeRequestController.php` | shared-ancestor test (mutation-checked) |
| Privacy | fact masking and audit redaction | `PersonPrivacy.php`, `AuditLogController.php`, `facts.blade.php`, `sources/show.blade.php`, `changes/show.blade.php` | restricted-claim test (pages, source page, audit, review page) |
| UI | facts panel, review context, source page claims | `facts.blade.php`, `people/show.blade.php`, `changes/show.blade.php`, `sources/show.blade.php`, `PersonController.php`, `juthoor.css` | Chrome journeys |
| ROOT differences | opt-in idempotent importer | `RootVariantImporter.php`, `AlZoubiRootVariantSeeder.php` | importer test; base 39+1 / shared 40 on SQLite and PostgreSQL |

### Session 6 — Phase 2.3 safe person merge
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Data model | `merged_into_id`, global scope `active`, `survivorOf()`, `person_merges` | migration `2026_10_25_114226`, `Person.php`, `PersonMerge.php` | rollback/re-apply on PostgreSQL; full suite unaffected by the scope |
| Engine | plan / merge / undoBlockers / undo with row journal | `PersonMergeService.php` | preview unchanged state, simple merge, conflicts, blockers, publication, concurrency, rollback, undo exact, undo blocked (mutation-checked) |
| Candidates | duplicate rule + variants, privacy-aware signals | `MergeCandidateFinder.php` | candidate test |
| UI & routing | candidates/history, preview, operation page, nav, merged-id redirects | `PersonMergeController.php`, `merges/*.blade.php`, `family-nav.blade.php`, `GenealogyTreeController.php`, `PersonController.php`, `routes/web.php`, `juthoor.css` | Chrome journeys |
| Governance | family admin; super admin for shared/cross-family | `PersonMergeService::authorize` + `SharedAncestryScope` | role and cross-family tests |

### Session 7 — Phase 2.4 change recovery
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Baseline fix | time-dependent snapshot in a merge test | `tests/Feature/PersonMergeTest.php` | repeated runs |
| Recovery engine | eligibility, preview with fingerprint/scope effects, restoration of deleted records, related deletions, JSON snapshot fix | `app/Services/AuditReversionService.php` | `AuditRecoveryTest` (15); existing `AuditReversionTest` unchanged and green |
| Governance | `needs_review` links still protect shared ancestors; claim re-proposal path | `SharedAncestryScope.php`, `GenealogyAssertionService.php`, `facts.blade.php` | shared-ancestor and claim tests |
| UI | eligibility in audit details, preview, recovery history, nav | `AuditLogController.php`, `AuditReversionController.php`, `audit/show|revert|recoveries.blade.php`, `family-nav.blade.php`, `routes/web.php` | Chrome journeys |

### Session 8 — Phase 2.5 name reconciliation & link validity
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Reconciliation | review queue for ROOT differences with evidence, competing claims, branches, shared families, history; dashboard count; nav | `NameReconciliationService.php`, `NameReconciliationController.php`, `management/reconciliation/index.blade.php`, `FamilyDashboardService.php`, `dashboard.blade.php`, `family-nav.blade.php`, `juthoor.css`, `routes/web.php` | `NameReconciliationTest` (10) |
| Claim decisions | reviewer `decline` with reason; spelling proposal adds a variant (D-08) | `GenealogyAssertionService.php`, `AssertionController.php` | same |
| Link validity | `validated_at`/`validation_stamp`; full check recorded; stamp-based read path; bounded locked re-check; revalidate action; per-tree descendant loading | migration `2026_10_25_130636_…`, `CrossFamilyLink.php`, `CrossFamilyLinkService.php`, `CrossFamilyLinkController.php`, `shared-ancestry/{manage,review,public}.blade.php`, `shared-ancestry/partials/validity.blade.php` | `SharedAncestryValidityTest` (16), `CrossFamilyLinksTest` unchanged and green |
| Invalidator | every approved link re-checked per write (D-07); shared tree fingerprint part; claim evidence ignored | `CrossFamilyLinkInvalidator.php` | regression + identity tests |

### Session 9 — Phase 3.1 hierarchical entities & portals
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Data model | entities, entity managers, content links; branch/family relations | migration `2026_10_26_113723_create_community_entities_table`, `CommunityEntity.php`, `CommunityEntityManager.php`, `EntityContentLink.php`, `Branch.php`, `Family.php` | rollback/re-apply and cascade on PostgreSQL |
| Hierarchy | parent map, ancestors/descendants, public ids, ordering; integrity rules | `Services/Community/CommunityHierarchy.php`, `CommunityEntityIntegrityService.php` | rank, cycle (form and model), family tests |
| Access | entity-scoped roles; policy | `CommunityEntityAccess.php`, `Policies/CommunityEntityPolicy.php` | scoped-manager, publication and isolation tests |
| Public pages | index and entity page, reusable breadcrumb / entity card / scope switch components, tree link | `CommunityEntityController.php`, `CommunityEntityPage.php`, `community/{index,show}.blade.php`, `components/community/*`, `genealogy/tree.blade.php`, `juthoor.css`, `routes/web.php` | page, visibility and founder-link tests; Chrome |
| Administration | hierarchy list, form, managers, dashboard panel, sidebar; branch deletion guard | `Management/CommunityEntityController.php`, `management/entities/{index,form}.blade.php`, `FamilyDashboardService.php`, `dashboard.blade.php`, `family-nav.blade.php`, `GenealogyDeletionService.php` | admin and dashboard tests; Chrome |
| Content scope | shared placement query, module contract | `EntityContentScope.php`, `Models/Concerns/HasCommunityEntities.php` | `CommunityContentScopeTest` (stand-in module) |

### Session 10 — Phases 3.2–3.6 community portals
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Data model | content tables, `content_citations`, entity population estimate | migration `2026_10_26_121429_create_community_content_tables`, `EntityNarrative.php`, `CommunityLocation.php`, `Diwan.php`, `EntityAchievement.php`, `ContentCitation.php`, `Concerns/CommunityContent.php`, `CommunityEntity.php` | rollback/re-apply and family cascade on PostgreSQL |
| Shared services | write path, module registry, genealogy graph and preview, statistics, portal assembly | `CommunityContentService.php`, `CommunityContentModules.php`, `CommunityGenealogy.php`, `CommunityStatistics.php`, `CommunityEntityPage.php` | `CommunityPortalTest` (14) |
| Public portal | section navigation, one scope switch with anchor, nine sections, evidence component | `community/show.blade.php`, `community/sections/*`, `components/community/{evidence,scope-switch}.blade.php`, `CommunityEntityController.php`, `juthoor.js`, `juthoor.css` | portal tests; Chrome |
| Administration | content list/form per module, citations, review decisions, history, queue, entity content panel, population estimate, nav, dashboard count | `Management/CommunityContentController.php`, `management/entities/content/*`, `management/entities/form.blade.php`, `Management/CommunityEntityController.php`, `family-nav.blade.php`, `dashboard.blade.php`, `FamilyDashboardService.php`, `routes/web.php` | admin rendering, workflow and scope tests; Chrome |
| Integration | person deletion and merge blockers, source deletion guard and usage list, Maps embed config | `GenealogyDeletionService.php`, `PersonMergeService.php`, `SourceController.php`, `sources/show.blade.php`, `config/services.php` | portal tests; existing merge/source tests green |
| Tests updated | Phase 3.1 assertions for placeholders (now real sections), completeness 4 → 6 items, a text check that the approved disclaimer now matches | `CommunityEntityTest.php` | intent unchanged |

### Session 11 — Phase 3.7 media library & heritage archive
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Storage | private `media` disk and limits; file inspection, storage, metadata-free display copies | `config/filesystems.php`, `config/media.php`, `MediaStorage.php` | upload validation and stripping tests; GD container run |
| Data model | media, attachments, archive, entity cover, restricted audience | migration `2026_10_26_125915_create_media_and_archive_tables`, `MediaItem.php`, `MediaAttachment.php`, `ArchiveRecord.php`, `Concerns/{HasUsageRights,HasMediaAttachments,CommunityContent}.php`, `CommunityEntity.php`, `Diwan.php`, `EntityNarrative.php` | rollback/re-apply and cascade on PostgreSQL |
| Services | upload/replace/attach/detach/delete, access rule, publish preconditions, module registry, scope sets | `MediaService.php`, `MediaAccess.php`, `CommunityContentService.php`, `CommunityContentModules.php`, `CommunityEntityAccess.php` | `CommunityMediaTest` (13) |
| Delivery | authorizing media route | `MediaController.php`, `routes/web.php` | route tests; Chrome guessed-path checks |
| Portal | archive and gallery sections, attachments in records, cover | `CommunityEntityPage.php`, `community/show.blade.php`, `community/sections/{archive,media,diwans,history}.blade.php`, `components/community/media-strip.blade.php`, `juthoor.css` | portal tests; Chrome |
| Administration | media/archive forms, attachments, usage, delete, cover choice | `CommunityContentController.php`, `management/entities/content/{form,fields/*}.blade.php`, `Management/CommunityEntityController.php`, `management/entities/form.blade.php` | admin tests; Chrome |
| Integrity | archive person guards, consistency command | `GenealogyDeletionService.php`, `PersonMergeService.php`, `Console/Commands/VerifyMediaFiles.php` | tests |
| Fixes during the session | media root moved out of the `local` disk root; media access scopes computed once per family (177 → 77 queries for members); sticky section bar below the site header; GD output also stripped of its comment segment | as above | re-measured; Chrome |

### Session 12 — Phase 4 news, events, directory & notifications
| Area | Change | Files | Tests / verification |
|---|---|---|---|
| Data model | news, events, notices, linked people, directory profiles and reports, notifications table | migrations `2026_10_26_134010_create_notifications_table`, `2026_10_26_134011_create_community_news_events_and_directory_tables`; `NewsPost.php`, `CommunityEvent.php`, `ContentNotice.php`, `MemberProfile.php`, `DirectoryReport.php`, `Concerns/{HasPublicPlace,HasNotices,HasLinkedPeople,CommunityContent}.php`, `Diwan.php` | rollback/re-apply on PostgreSQL |
| Publishing | publication window, sensitive approval, people sync, notifications; notices, cancellations, schedule changes, announcements | `CommunityContentService.php`, `CommunityPublishingService.php`, `CommunityNotifier.php`, `CommunityContentModules.php`, `Notifications/CommunityNotification.php`, `config/community.php` | `CommunityNewsTest`, `CommunityEventTest` |
| Public pages | portal news/events sections, news list with search, calendar, detail pages, cards, notices | `CommunityPublicationController.php`, `CommunityEntityPage.php`, `community/{news,events}/*`, `community/sections/{news,events}.blade.php`, `components/community/{news-card,event-card,notices}.blade.php`, `community/show.blade.php`, `juthoor.css` | tests; Chrome |
| Administration | news/event forms (death fields, places, people, sensitive confirmation), notices and schedule panel, announce action | `CommunityContentController.php`, `management/entities/content/{form,fields/news,fields/events,fields/place}.blade.php`, `routes/web.php` | tests; Chrome |
| Directory | profiles, search, contact, reports, affiliation, suspension, photos | `MemberDirectory.php`, `MemberDirectoryController.php`, `Management/DirectoryProfileController.php`, `directory/*`, `management/directory/*`, `components/community/directory-card.blade.php`, `family-nav.blade.php`, `community/index.blade.php` | `MemberDirectoryTest`; Chrome |
| Notifications UI | inbox, open/mark read, unread badge | `NotificationController.php`, `notifications/index.blade.php`, `layouts/management.blade.php` | tests; Chrome |
| Integrity | linked people and deceased records protected from deletion/merge | `GenealogyDeletionService.php`, `PersonMergeService.php` | test |
| Fixes during the session | event/news model defaults; empty person picker; flash messages on the public profile page; news list N+1 (19 → 8 queries); notification JSON filter in tests (PostgreSQL text column) | as above | re-run |

## Technical decisions

| Decision | Reason |
|---|---|
| Editors may still write unpublished records directly; published records change only through admin review | Satisfies UC-07 without removing existing editor productivity; reuses the existing `change_requests` workflow |
| Guard uses a direct role check instead of the Gate | Services such as `ChangeReviewService::decide()` receive users authorized by controllers; a stale model would otherwise fail the gate's `is_active` check |
| Branch type is a constant list on `Branch`, not a new table | Column already existed; v2.0 says the levels are configurable, so a reference table is a Phase 3 option if families need custom labels |
| Search lineage is a separate `lineage` field | Keeps the existing `text` JSON contract asserted by tests |
| ROOT-077 reconciled in place under the 2026-10-22 working decision | Display spelling ذيبان with ذبيان kept as a recorded variant; same record and status; no claim of verification |
| ROOT-078 resolved by an explicit key alias, not by name search | `AZ-RAHAYMEH-SULAYMAN` is the only سليمان son of ROOT-077 and base sources trace both lines through him; keeping his key keeps the base seeders idempotent |
| Base seeder refuses unreconciled name differences | Prevents silently rewriting 39 user-supplied spellings when seeding over the shared ancestry |
| `public_narrative` only applies while `pending_review` | Same semantics as the removed exception; disputed or unresolved records stay hidden even if flagged |
| Cross-family linking keeps a stricter rule | JTH-05 requires verified common ancestors; publication is not verification |
| Proposals compared with fatherless records | Person proposals carry no father yet; father links are proposed separately |
| Super admins keep direct enrollment | Platform administration; family admins get the consent path |
| Tri-state on the existing `is_living` column, provenance in `living_status_basis` | No duplicate status column; three states already representable |
| Compatibility classification only with explicit evidence | Recorded death, or verified/sourced + curated `historical` with a 110-year birth guard; never from missing data alone |
| `field_visibility` JSON on `people`, no new table | Six sensitive fields; levels travel with audit snapshots |
| Restricted = family admins and super admins | Membership alone must not unlock restricted personal data |
| Snapshot redaction uses the stricter of historical and current level | Tightening privacy also hides earlier audit/proposal values |
| Public page omits hidden fields; management pages show a lock | Visitors should not learn that hidden data exists; members need to know it is withheld |
| Claims in a new `assertions` table, evidence in existing `citations` | No structure stored competing claims; sources/citations already model evidence |
| Claim approval is an ordinary change request | Reuses review rules, fingerprints, self-review prevention, integrity, publication guard and audit; no parallel review path |
| `relation` (how a claim relates) and `state` (lifecycle) beside the existing `verification_status` | Verification keeps its vocabulary (unresolved = disputed); rejection/supersession are lifecycle, not verification |
| Replaced displayed values are preserved as superseded claims | No account is lost when a claim is accepted |
| Shared-ancestor changes need a super admin | A local admin must not silently change a fact other families depend on |
| ROOT differences recorded as unclassified claims, opt-in | Comparison and review without reconciling or changing people |
| Duplicate kept with `merged_into_id` and hidden by a global scope | History and stable redirects without a second "deleted" state; every existing query excludes it automatically |
| Row-level journal applied with raw queries | Exact, verifiable reversal; model events would add side effects that undo could not replay |
| Undo refuses when any touched row changed | Never overwrite newer work; the operation page lists the blockers for controlled recovery |
| Different fathers block the merge | Parentage is resolved through claims and review, never guessed by a merge |
| Generic recovery stays on the governed mutation path; merge journal not generalised | Every governance rule applies to recovery; merges keep their exact journal |
| Recovery record = audit entry with unique `reverted_from_id` | Immutable, linked to the original, prevents double recovery without a second history table |
| Restored records return pending review | Recovery must not republish by itself |
| Only the latest change of a record is reverted | Never overwrite newer work; older changes are recovered by reverting newer ones first or by a new reviewed change |
| Stricter field privacy wins; no merge may publish what neither identity published | Merging must not weaken privacy or publish as a side effect |
| Validity = persisted full check + aggregate stamp, not a revision counter | Bulk/raw writes bypass model events; counts, key sums and `updated_at` still reveal them, so stale links are never trusted |
| No stamp recorded while a dependency changed in the current second | Timestamps have second precision; a later write in that second would otherwise be invisible |
| Stale links re-checked on read at most twice per request, under a lock, else after the response | Correct immediately after most changes without letting a busy page run every fingerprint |
| Fingerprint formula kept byte-identical | Changing it would silently invalidate (or require re-approving) every existing link |
| Claim evidence is not a link dependency | Claims never change displayed genealogy until accepted, and acceptance writes the person |
| Reconciliation reuses claim actions; only `decline` is new | One review workflow, one audit trail; no second decision path for names |
| Community entities are a new table referencing branches, not an extension of `branches` | Branches require a verified founder and drive derived membership; social units must not (v2.0 ch. 3, 5) |
| Founder shown through the linked branch, no entity → person foreign key | Keeps merge, undo, deletion and recovery services unchanged; one page per branch avoids duplicate pages |
| Parent map loaded per family and walked in memory, no closure table | Tens to hundreds of entities per family; one indexed query, no path maintenance on moves |
| Public only when the entity and all ancestors are published | Breadcrumbs and aggregation never reveal unpublished entities |
| Subject links roll up, audience links flow down; filtering by content key | v2.0 ch. 6: no sibling leakage, targeted parent content reaches descendants, distinct results without `DISTINCT` |
| Edit rights follow the content owner only | v2.0 ch. 6/13: visibility on a page never grants editing |
| Entity roles are subtree-scoped and require family membership | Matches the existing family role model; leaving the family revokes them |
| Editors draft; family/entity admins publish or change published pages | Same governance as `PublicationGuard` for genealogy |
| Future sections listed as «غير متاح بعد» without links | Stable page layout without presenting unimplemented features |
| Content evidence in `content_citations`, sources from the family library | Genealogy `citations` need a tree and feed shared-ancestry fingerprints; content must not invalidate links |
| One governance trait and one write service for all modules | Same publication, review, audience and audit rules everywhere; Phase 4 modules plug in |
| Content review = draft → submitted → published/declined, audited; not `change_requests` | Change requests are genealogy payload diffs; content records carry their own state and history |
| Linked entities must be within the actor's scope | Nobody can place content on pages they do not manage (v2.0 ch. 6) |
| Statistics from one per-page graph, three edge sets, distinct ids | Correct de-duplication and one tree load per page; no container singleton (stale across requests) |
| Population coverage always «غير معروفة»; estimate only with a source | Records are not a census (v2.0 ch. 8) |
| Keyless Google Maps URLs; Embed API only with a configured key | No paid or assumed key (v2.0 ch. 9) |
| Achievements hidden unless the person is public (visitors) | Positions must not reveal living or unpublished people |
| No hard delete of content; archive and restore | Reversible without a replay engine; audit keeps full snapshots |
| Media is a content module, not a separate system | Same governance, review queue, evidence, scope and audit as every module; Phase 4 reuses it |
| One file per family (checksum) shared through attachments | No duplicate files; deletion blocked by a foreign key while attached |
| Private disk + authorizing route, 404 for unreadable files | A public directory is not a privacy policy; existence is not revealed |
| Type from content, allow-list, no SVG/HTML | Prevents disguised scripts and unsafe markup |
| Viewers get metadata-free display copies; originals for managers | Protects GPS/EXIF data while keeping the original |
| Rights gate the audience | No public copyrighted material without permission |
| `restricted` added to the shared audience | v2.0 Public / Members / Restricted levels, for all modules |
| News and events are content modules; events stay separate records | One governance model; v2.0 requires Event ≠ NewsPost and announcements without copies |
| Publication window enforced in `visibleTo` | Server-side and authoritative without a scheduler or worker |
| Death notices need a family administrator, consent and a stored confirmation | v2.0: sensitive records verified by an authorised party, with who/when |
| Notices instead of overwriting published content | Changes, postponements and cancellations stay visible with previous values |
| In-app (database) notifications only | No paid service, no sensitive text by e-mail; works without a mail server |
| Directory profile belongs to a User, opt-in, per-field levels capped by audience | v2.0 ch. 12: Person ≠ User ≠ profile; consent and field privacy |
| Per-audience precomputed search text | Hidden fields can never be searched or inferred through filters |
| Admins moderate but never edit member profiles | v2.0: no data entered or published on a member's behalf |

## Verification evidence (session 12)

| Check | Result |
|---|---|
| Baseline before changes | 346 / 346 SQLite |
| Full suite, SQLite / PostgreSQL 16 | 367 / 367 passed |
| Migration up / rollback (2) / up on PostgreSQL 16 | pass |
| Pint, Blade compile, `git diff --check` | pass |
| Mutation checks | 21 Phase 4 rules each fail at least one test (two redundant directory guards each hold alone) |
| Chrome (fresh seeded database) | Phase 4: 8/8 — editor news → admin notified → published, rolled up, not on sibling; scheduled news hidden; death notice refused without consent then published with confirmation, genealogy unchanged; event in calendar at local time, rescheduled and cancelled with visible notices; announcement linked to event; directory opt-in with hidden phone absent from HTML, members-only profile hidden from visitors; in-app contact and verified affiliation filter; mobile. Phase 3 regression: 29/29. No page errors |
| Performance | `docs/project-audit.md` §15 |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices; MySQL; real e-mail delivery |

## Verification evidence (session 11)

| Check | Result |
|---|---|
| Baseline before changes | 333 / 333 SQLite |
| Full suite, SQLite / PostgreSQL 16 | 346 / 346 passed (3790 assertions) |
| Media tests in a PHP 8.3 + GD container; GD smoke (resize, metadata, PNG alpha) | 13 / 13; 1200×800 → display size, no metadata, alpha kept |
| Migration up / rollback / up on PostgreSQL 16; family delete cascades to media, archive and covers | pass |
| Pint, Blade compile, `git diff --check` | pass |
| Mutation checks | 17 media rules (originals, members, hidden entities, drafts, allow-list, extension, markup, metadata, rights, alt text, in-use delete, page visibility, diwan images, upload cleanup, old-file removal, archive person, pixel limit) plus 2 scope-set checks each fail at least one test |
| Chrome — final Phase 3 regression on one fresh seeded database | 29/29, no page errors: Phase 3.1 (8), 3.2–3.6 (12), 3.7 (9: upload with GPS metadata; metadata-free display, originals and guessed paths refused; archive with image + PDF shared by two fakhds; diwan photo; cover; members-only file; unsafe upload and uncleared rights refused; editor draft and duplicate refused; mobile); `media:verify` clean |
| Performance | `docs/project-audit.md` §14 (constant queries: 71 visitor / 77 member; 0.39 s at 10,000 people with 168 extra records) |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices; MySQL; antivirus scanning |

## Verification evidence (session 10)

| Check | Result |
|---|---|
| Baseline before changes | 319 / 319 SQLite; all migrations apply |
| Full suite, SQLite / PostgreSQL 16 | 333 / 333 passed (3583 assertions) |
| Migration up / rollback / up on PostgreSQL 16; family delete cascades to content and links | pass |
| Pint, Blade compile, `git diff --check` | pass |
| Mutation checks | 14 rule removals each fail at least one test: member audience, hidden person, distinct counting, link scope, editor publishing, editor editing published, residence consent, Maps host, hidden-entity aggregation, unapproved citations, private genealogy edges, contact consent, merge blocker, cross-link sources |
| Chrome journeys (real seeded Al-Zoubi + Qawasmeh data) | 12/12, no page errors: historical profile with alternative account and source; sourced region rolls up; founder, chain and mini tree with profile link; one switch keeps the section anchor; statistics equal the traversal count with disclaimer and unknown coverage; shared diwan once; directions open a new tab with the keyless URL; sourced position; private person's record hidden in HTML but visible to members; editor draft → submit → decline with note → resubmit → publish; entity editor limited to its fakhd; 390 px mobile |
| Performance | see `docs/project-audit.md` §13 (constant 48–54 queries; 0.25–0.33 s at 10,000 people) |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices; MySQL |

## Verification evidence (session 9)

| Check | Result |
|---|---|
| Full suite, SQLite / PostgreSQL 16 | 319 / 319 passed (303 existing unchanged + 16 new) |
| Migration up / rollback / up on PostgreSQL 16; family delete cascades through nested entities | pass |
| Pint, Blade compile (`view:cache`), `git diff --check` | pass (no JS changed) |
| Mutation checks | 13 rule removals (cycle, ancestor publication, downward audience, inherited scope, publication guard, parent family, rank, direct mode, audience relation, destination authorization, …) each fail at least one test |
| Chrome journeys (real seeded Al-Zoubi + Qawasmeh data) | 8/8, no page errors: reader refused; dashboard + nav; create clan / fakhds / sub-fakhds linked to existing branches with genealogy counts unchanged (119 people / 118 edges / 6 branches / 1 tree); descendants not offered as parents and level rule enforced; public hierarchy, breadcrumbs and direct/with-descendants switch; founder link into the tree and unpublished branch hidden; scoped entity editor (drafts only, preview); 390 px mobile without overflow |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices; MySQL |

## Verification evidence (session 8)

| Check | Result |
|---|---|
| Full suite, SQLite / PostgreSQL 16 | 303 / 303 passed |
| Migration up / rollback / up on PostgreSQL 16 | pass |
| Pint, Blade compile, `git diff --check` | pass (no JS changed) |
| Mutation checks | trusting without the stamp, counting claim evidence, dropping same-second protection, dropping deferred re-check, and the old `each()` invalidator each fail at least one test |
| Chrome journeys (real seeded data + synthetic partner family) | 11/11: queue lists 39, classify + propose, accept as variant, decline with history, leave unresolved, ROOT-077 preserved, mobile layout, reader refused, approval then public, descendant edit invalidates, direct DB write shows stale then revalidation withdraws |
| Performance | see `docs/project-audit.md` §11 |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices; MySQL |

## Verification evidence (session 7)

| Check | Result |
|---|---|
| Full suite, SQLite / PostgreSQL 16 | 277 / 277 passed |
| Pint, Blade compile, `node --check`, `git diff --check` | pass |
| Mutation checks | disabling "restore unpublished" fails the restoration test; disabling scope confirmation fails the privacy/publication test |
| Chrome recovery journeys (real seeded data) | 4/4: field change reverted from audit details, deleted relationship blocked until its person is restored then both restored, recovery history, reader without controls |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices |

## Blockers

None for code. Deferred by decision: Phase 2.6, Phase 2.7 (GEDCOM), Facebook. Data work: the 39 shared-ancestry spellings (D-06) need sources and reviewer decisions in the reconciliation queue; the platform will not decide them.
