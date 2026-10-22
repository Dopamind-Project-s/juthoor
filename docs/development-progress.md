# Juthoor — Development Progress Log

Use this file to resume work without repeating the audit. Statuses and IDs refer to `docs/project-audit.md` and `docs/implementation-roadmap.md`.

## Current state (2026-10-22)

- Branch `main` at `45eede7`; session 2 changes uncommitted (nothing committed by the assistant).
- Suite: **194 passed / 0 failed (2199 assertions)** on SQLite and PostgreSQL 16.
- Phase 0 complete. Phase 1: all items done except **P1-07** (unify visibility), **P1-18** (policy decisions) and **P1-20** (blocked data reconciliation).

## Next task to execute

1. **P1-20** once the canonical ROOT-077 spelling/chain is decided (ذبيان vs ذيبان ← سليمان).
2. **P1-07**: introduce one public-visibility service; make `ProgressiveGraphService` (progressive + focus), `PublishedTreeTraversal`, search and batch share it; replace the hard-coded ROOT-001..079 exception with an explicit flag (needs a migration and a data decision).
3. Run the duplicate check (P1-04) for change-request "create person" proposals too.

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

## Technical decisions

| Decision | Reason |
|---|---|
| Editors may still write unpublished records directly; published records change only through admin review | Satisfies UC-07 without removing existing editor productivity; reuses the existing `change_requests` workflow |
| Guard uses a direct role check instead of the Gate | Services such as `ChangeReviewService::decide()` receive users authorized by controllers; a stale model would otherwise fail the gate's `is_active` check |
| Branch type is a constant list on `Branch`, not a new table | Column already existed; v2.0 says the levels are configurable, so a reference table is a Phase 3 option if families need custom labels |
| Search lineage is a separate `lineage` field | Keeps the existing `text` JSON contract asserted by tests |
| ROOT-077 conflict left unresolved | v2.0 forbids merging on name similarity and requires sources for genealogical claims |

## Verification evidence

| Check | Result |
|---|---|
| Full suite, SQLite | 194 passed |
| Full suite, PostgreSQL 16 | 194 passed |
| Pint (`app config database routes tests`) | pass; `resources/data/al-zoubi/people.php` has a pre-existing style issue, deliberately untouched |
| Blade compile, `node --check`, `git diff --check` | pass |
| Base seed (`db:seed` twice) | 85 people / 84 edges / 6 branches, identical on rerun; all public and admin pages 200; graph 0 duplicate nodes |
| Shared + Qawasmeh seed (twice) | 113 / 112 / 1 / 242 citations, identical; chain يوسف ← ذيبان ← سليمان ← زين العابدين ← سليمان ← قاسم ← محمد; sons اعمر، حمدان، سمرين، قاسم |
| Chrome E2E | 4/4: admin adds published child → visible on public person/tree/search; duplicate warning; editor sees reserved statuses and saves a private draft |
| Not run | Repository Playwright scripts (Playwright not installed), Safari/Firefox, real devices, SMTP |

## Blockers

- **D-01 / P1-20**: needs a genealogical decision with a source (ذبيان vs ذيبان and the ROOT-078 سليمان link in the base dataset).
- **P1-18**: product decision on member-add consent and family-admin tree routes.
