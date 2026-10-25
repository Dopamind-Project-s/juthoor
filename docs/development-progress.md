# Juthoor — Development Progress Log

Use this file to resume work without repeating the audit. Statuses and IDs refer to `docs/project-audit.md` and `docs/implementation-roadmap.md`.

## Current state (2026-10-25, after session 6)

- Branch `main` at `8318177`; session 6 (Phase 2.3) changes uncommitted (nothing committed by the assistant).
- Suite: **262 passed / 0 failed (2785 assertions)** on SQLite and PostgreSQL 16.
- Phase 1 and Phases 2.1–2.3 complete.

## Next task to execute

Phase 2, item 4: revert any audit entry and restore a deleted person (JTH-09), reusing the merge journal approach for exact, blocker-checked restoration.

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
| Stricter field privacy wins; no merge may publish what neither identity published | Merging must not weaken privacy or publish as a side effect |

## Verification evidence (session 6)

| Check | Result |
|---|---|
| Full suite, SQLite / PostgreSQL 16 | 262 / 262 passed |
| Pint, Blade compile, `node --check`, `git diff --check` | pass |
| Merge migration rollback and re-apply (PostgreSQL) | pass |
| Mutation checks | undo without re-inserting consolidated rows fails the exact-restore test; skipping the fingerprint check fails the concurrency test |
| Chrome merge journeys (real seeded data + planted duplicates) | 5/5: candidate listed and previewed, conflict resolved and merge confirmed with redirects, different-father pair blocked, undo restores both identities, reader limited to candidates |
| Not run | Repository Playwright scripts; Safari/Firefox; real devices |

## Blockers

None for Phase 1. Data decision needed for Phase 2: the 39 remaining shared-ancestry spellings (D-06) require sources before reconciliation.
