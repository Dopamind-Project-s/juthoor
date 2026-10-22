# Juthoor — Development Progress Log

Use this file to resume work without repeating the audit. Statuses and IDs refer to `docs/project-audit.md` and `docs/implementation-roadmap.md`.

## Current state (2026-10-22, after session 3)

- Branch `main` at `a0d25fe`; session 3 changes uncommitted (nothing committed by the assistant).
- Suite: **218 passed / 0 failed (2402 assertions)** on SQLite and PostgreSQL 16.
- **Phase 1 complete.** Open items moved to Phase 2: D-06 (39 shared-ancestry spellings), R-04, R-07 — see `docs/implementation-roadmap.md`.

## Next task to execute

Phase 2, item 1: field-level privacy and an explicit living-status backfill (JTH-28, UC-12, R-07).

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

## Verification evidence (session 3)

| Check | Result |
|---|---|
| Full suite, SQLite | 218 passed |
| Full suite, PostgreSQL 16 | 218 passed |
| Pint (`app config database routes tests`) | pass (178 files) |
| Blade compile, `node --check` (public JS + browser scripts), `git diff --check` | pass |
| New migrations rollback and re-apply (PostgreSQL) | pass |
| Seed order A: `db:seed` → Qawasmeh → repeat | 119 / 118 / 6 branches / 86 citations / 167 audits, identical on repeat; ROOT-077 same id; 0 duplicate siblings (SQLite and PostgreSQL) |
| Seed order B: shared → Qawasmeh → `db:seed` | `db:seed` refused, nothing written (D-06); Qawasmeh repeat identical |
| Seed order C: Qawasmeh on empty DB | refused with a clear prerequisite message |
| Chrome E2E on real seeded data | 7/7: public tree + variant search with lineage; admin adds a published child; editor restrictions; reader duplicate proposal; reviewer confirmation; permissions + invitation; mobile RTL; no page errors |
| Not run | Repository Playwright scripts (Playwright not installed); Safari/Firefox; real devices; real SMTP delivery |

## Blockers

None for Phase 1. Data decision needed for Phase 2: the 39 remaining shared-ancestry spellings (D-06) require sources before reconciliation.
