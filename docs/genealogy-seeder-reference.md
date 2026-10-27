# Unified development genealogy seeder reference

2026-10-27. This is a development seed proposal, not a historical certification or population census.

## Architecture

The default path is `DatabaseSeeder` → `RolePermissionSeeder`, `PersonAttributeSeeder`, and, only in local/testing, `AlZoubiFamilyTreeSeeder` then `SiteUsersSeeder`. Production receives the reference catalogs only. The tree importer validates the authoritative normalized graph, source checksums, a unique root, connected parent paths, cycles and branch ordering before importing inside one database transaction and a family lock. Existing administrative names, privacy, verification, colors, credentials and role customizations survive reruns. The importer uses lookup-and-create under its transaction and lock rather than per-row `createOrFirst` savepoints; large imports work with PostgreSQL’s default lock table. A competing unique insert fails atomically and can be retried. Existing conflicting fathers/founders and unmanaged identity collisions are refused; an unmanaged matching child requires reconciliation rather than an automatic merge.

```
app/Services/Imports/UnifiedGenealogyDataset.php
app/Console/Commands/InitializeDevelopmentGenealogy.php
database/seeders/{DatabaseSeeder,RolePermissionSeeder,PersonAttributeSeeder,
                  AlZoubiFamilyTreeSeeder,SiteUsersSeeder}.php
resources/data/genealogy/al-zoubi/
  normalized.json             # authoritative people, father keys, branches, attributes and evidence
  source-manifest.json        # original source checksums and per-source coverage
  drawing-extractions.json    # labels, geometry, offsets and candidate connectors; NOT automatic import input
  founder-mappings.json       # chart-position → canonical identity mappings
  workbook-audit.json         # worksheet coverage and conflict/duplicate candidate rows
  review-queue.json           # excluded observations and competing accounts
  coverage-snapshot.json      # actual SQLite counts and per-fakhd coverage
```

The repository-root tools are `discover.py`, `workbook.py`, `assemble.py` and `report.py`, plus `render_drawings.py` for optional geometry-based raster inspection. Run them in that order; the final report reads the real local SQLite database after seeding. Extraction uses Python's standard library; optional raster inspection used Pillow and Poppler. It does not depend on Arabic OCR or an external genealogy service.

## Previous seeder audit and compatibility

The previous default orchestrator called separate family/tree/source/77-root-people/relationships/branch/founder seeders. Qawasmeh, the exact submitted ancestry, root variants and research sources were optional imports. Founder resolution previously assigned verified status; the new default never imports that status as historical verification. The old default could classify chart-only people as historical; the new default leaves descendants' living status unknown.

**Removed from default orchestration:** `AlZoubiGenealogySeeder`, `DevelopmentAdminSeeder`, and their nested family/root/branch importer pipeline. **Deleted seeder files: none.** The original seeders remain explicit compatibility/audit fixtures because the existing audit and reconciliation commands and regression tests depend on their constants, import behavior and historical variants. `Tests\Fixtures\LegacyGenealogySeeder` makes that legacy dataset explicit in its regression tests; those tests no longer accidentally exercise the new default. Optional synthetic UI/stress seeders also remain opt-in and do not run in the default. These historical implementations are now explicitly archived under `app/Services/Imports/LegacySeeders/`; supported application/test imports were relocated with them.

Preserved: UX-P0.1 permission catalog and synchronization commands, genealogy audit/review/recovery services, migrations, source evidence, PersonPrivacy, UX-P0.3 renderer, UX-P0.4/0.5 portal services, and optional historical imports. `SeedAuditService` detects the unified package and compares it with the new proposal; it continues supporting legacy audit fixtures.

## Initialization and reruns

```sh
php artisan genealogy:initialize-development
php artisan db:seed
php artisan genealogy:seed-audit --json --dry-run
```

The initialization command refuses nonlocal environments, DB_URL overrides, unknown connection types, SQLite files outside the application's database directory, PostgreSQL databases without a dedicated local `juthoor_test_*` name, and any database containing rows outside the migrations table. It runs migrations then seeds; it never drops tables. It also refuses a seeded database; use `db:seed` for reruns.

`migrate:fresh --seed` was exercised only on the selected local zero-byte SQLite file and later on records generated during this task. Laravel's generic `migrate:fresh` itself is not made safe by this command: verify environment/connection and inspect data before using it elsewhere. No shared/production/staging database was touched.

This session's host PHP lacked PDO SQLite/PostgreSQL. Drivers were downloaded and extracted under `/tmp/juthoor-packages`; commands used `PHP_INI_SCAN_DIR=/tmp/juthoor-php-ini` and `LD_LIBRARY_PATH=/tmp/juthoor-packages/extracted/usr/lib/x86_64-linux-gnu`. PostgreSQL 16.15 ran in an isolated `/tmp/juthoor-pgdata` cluster on localhost:55439, database `juthoor_test_genealogy`. Ordinary PHP commands on this host still require those extensions installed or the session's temporary environment. The local application's previously empty APP_KEY was initialized for actual browser journeys.

## Accounts and scope

All new local/testing demo accounts use password `password`, hashed with Laravel Hash. Existing credentials are never reset, and reruns do not restore revoked memberships or functional/entity roles. The seeder refuses public application URLs and every environment except local/testing; accepted hosts are localhost, loopback or reserved `.test`.

| Username | Scope |
|---|---|
| superadmin | platform super administrator |
| familyadmin | Al-Zoubi family administrator membership |
| editor | Al-Zoubi editor membership |
| reader | Al-Zoubi reader membership |
| demo_genealogy_manager | family-scoped genealogy manager |
| demo_genealogy_editor | family-scoped genealogy editor |
| demo_genealogy_reviewer | family-scoped genealogy reviewer |
| demo_content_manager | family-scoped content manager |
| demo_news_editor | family-scoped news editor |
| demo_news_publisher | family-scoped news publisher |
| demo_death_notice_reviewer | family-scoped death-notice reviewer |
| demo_directory_moderator | family-scoped directory moderator |
| entity_admin | Qawasmeh entity administrator plus family reader |
| entity_editor | Qawasmeh entity editor plus family reader |

Each email is `<username>@juthoor.test`. Functional permissions are assigned through family memberships; entity roles cover Qawasmeh and its descendants, not the whole family. The descriptive title `مؤسس النظام` on مصعب has no account or privilege association.

## Evidence, privacy and interfaces

The supplied 79-person root is a pending canonical proposal with its approved narrative flag and supplied ancestry living-status basis. Chart descendants and the 34 submitted Qawasmeh people remain pending review, unknown living status and nonpublic. Sixteen founders receive existing attribute/color lookup IDs; colors remain database-driven. All 17 branches and 18 community entities are drafts pending review, so the public directory and portal do not pretend they contain published descendants. Authorized previews show actual database coverage, including 356 Qawasmeh, 955 Bakr and 254 Rahaymeh people. These overlapping subtree counts are not population estimates.

Use `/families/al-zoubi/portal` for the existing dynamic public portal, `/families/al-zoubi/tree?private=1` for the authenticated unified tree, and `/families/al-zoubi/entities/al-qawasmeh?preview=1` for an authorized fakhd preview. Public tree display contains the 79-person submitted narrative only. The canonical-portal feature flag now also sends legacy fakhd directory and branch URLs to the existing database-backed entity pages, including Eid/Khamis aliases. Production configuration and subdomains remain unchanged. Unified raw sources and unresolved observations require the family’s genealogy-review permission, including direct source/download access; ordinary readers cannot enumerate them. Unresolved records appear as private source-observation citations; known-person differences also appear as proposed assertions. No source claim is automatically verified or published. The existing renderer’s progress label now says records are permitted in the current view, so a private pending record is not mislabeled as published.

## Validation record

Validation results are recorded below. Browser artifacts: `docs/screenshots/genealogy-rebuild/`, including 24 screenshots and `journeys.json` with 41 successful checks and zero JavaScript errors. Journeys used actual Chrome, the real seeded SQLite database, all 14 logins, public privacy rejection, ancestral and full private trees, Qawasmeh/Bakr/Eid/Khamis/Samrin navigation, repeated Arabic search, founders, deep profiles, fakhd previews, role management, dashboard, source review and desktop/mobile layouts.

The full SQLite suite initially passed 448/449; a pre-existing redirect test inherited the local APP_URL port. `phpunit.xml` now supplies deterministic `http://localhost`, and the full rerun passed **453/453, 11,760 assertions**. The seeder/audit subset passed 15/15 tests with 6,891 assertions. After removing nested importer savepoints, the latest seeder subset also passed 10/10 with 6,833 assertions, including unmanaged identity rejection, preservation of manually removed attributes and revoked demo-role assignments. All 11 Node tree-layout tests pass. **PostgreSQL 16.15: 453/453 tests, 11,760 assertions passed**, using the default lock settings. Its final importer/privacy/portal regression subset passed 20/20 with 6,952 assertions. Machine-readable results and the live audit are in `genealogy-validation-results.json`.

## Remaining evidence work

All 49 distinct editable drawings were inspected or compared with corresponding raster evidence; 37 contributed anchored descendant components. Twelve remain unconnected because their shared anchor or ancestry is not reliable. Awtalat's founder path remains disputed and unlinked. Faqara and Abdul-Latif are preserved as separately classified review material rather than attached speculatively. Thalji, Mahmoud, Hamdan and Ali/Wahsh contributed additional documented descendants; Ali/Wahsh is a neutral branch classification, without an invented fakhd rank. V4 branch positional identity mappings remain unresolved even when independently extracted chart descendants have already been imported. No coverage percentage implies historical verification.

The final expanded package contains **1,688 people, 1,687 father relationships, 17 branches, 16 linked founders, 18 community entities, 56 sources, 8,067 citations, 40 proposed root assertions and 3,620 unresolved observations**. The full source inventory and its distinct checksums are recorded in `genealogy-source-inventory.md`; 83 distinct evidence files were processed. Source extraction includes 4,956 candidate person-label occurrences; that is not a unique-person count.

PostgreSQL validation exposed and fixed per-row Eloquent savepoint accumulation (`out of shared memory / max_locks_per_transaction`). The importer now keeps one atomic transaction and uses model-backed lookup-and-create under the family lock. PostgreSQL stayed at `max_locks_per_transaction=64`; no server limit was raised to hide the issue.

The current local `.env` enables `JUTHOOR_PORTAL_CANONICAL=true`, so `/al-zoubi`, its fakhd directory and individual branch URLs use the existing database-backed portal/entity routes. This development-only configuration was not applied to production. The review server is available at `http://127.0.0.1:8039`; raw-source access remains restricted to genealogy reviewers.

## Modified paths

- Runtime: `app/Services/Imports/UnifiedGenealogyDataset.php`, `SeedAuditService.php`, `app/Console/Commands/InitializeDevelopmentGenealogy.php`, and `app/Http/Controllers/Management/SourceController.php`.
- Default seeding: `database/seeders/DatabaseSeeder.php`, `PersonAttributeSeeder.php`, `AlZoubiFamilyTreeSeeder.php`, and `SiteUsersSeeder.php`; `RolePermissionSeeder` is retained unchanged.
- Routing/presentation: `routes/web.php`, the existing seed-audit Blade view, and one progress-label correction in `public/js/genealogy-tree.js`.
- Data/extraction: `resources/data/genealogy/al-zoubi/` and `tools/genealogy/` (discovery, workbook inspection, deterministic assembly, source rendering and actual DB reporting).
- Validation: `phpunit.xml`, new unified-seeder and source-privacy tests, explicit legacy fixtures in eight existing regression files, family portal redirect assertions, and `tests/Browser/unified-genealogy.cjs` with its read-only fixture exporter.
- Documentation: README, project audit, roadmap, development progress, the three genealogy reports, validation JSON, and 24 actual browser screenshots plus journeys JSON.
- Required bootstrap: Laravel Boost's tracked development vendor/guideline files were refreshed by the AGENTS.md setup commands. Composer dependency requirements and lock file are unchanged from the repository's declared versions.
- Local-only configuration: APP_KEY initialized and canonical portal flag enabled in the untracked `.env`. No migration or original genealogy evidence file was removed or edited. No commits, pushes, merges, tags, deployment or production host activation occurred.


## Final seeder cleanup — 2026-10-27

Completed the remaining physical cleanup. `database/seeders/` now has exactly the five authoritative entry-point/catalog files: DatabaseSeeder, RolePermissionSeeder, SiteUsersSeeder, PersonAttributeSeeder and AlZoubiFamilyTreeSeeder. Optional UI/stress fixtures moved into `database/seeders/Fixtures/`. Historical genealogy implementations moved into `app/Services/Imports/LegacySeeders/` for audit/reconciliation and regression compatibility; application/test imports now use their explicit archive namespace. No historical implementation or source evidence was discarded. The new bootstrap reads `AlZoubiSeedDefaults` rather than depending on archived seeders.

The previously outstanding legacy-seeder relocation is complete. Historical ambiguities remain deliberately unresolved rather than guessed. Development accounts and the improved PersonAttributeSeeder remain in the authoritative path. No database reset or reseed was needed for this file-organization change.

At the user's explicit request, no checks or tests were run after this final cleanup. Earlier recorded test results apply to the implementation before this namespace/file relocation.
