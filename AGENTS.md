# Juthoor — shared engineering instructions

## Start here

Juthoor is an Arabic RTL genealogy and family-portal application: Laravel 13, PHP 8.3+, Eloquent, Pest, Blade, local Bootstrap/Cairo assets, and native JavaScript/SVG genealogy rendering. No Node build is needed to serve the current UI.

1. Read [the compact project map](docs/ai/project-map.md).
2. Inspect relevant source and sibling conventions; search for existing behavior before adding anything.
3. Load only the relevant skill/reference. Do not reread all requirements, unchanged documents, or source inventories by default.
4. Repository files are the shared source of truth; Claude and Codex do not share conversation memory automatically.

## Architecture and invariants

- Use Laravel routing, validation, Eloquent, DI, storage, transactions, queues and policies. Preserve meaningful domain services; avoid query wrappers, duplicate permission systems and aesthetic architecture layers.
- A Person belongs to a FamilyTree; scope every lookup and mutation to its family/tree. Branches are views of the same graph, not independent ancestry copies. CommunityEntity provides content/administration hierarchy and is distinct from Branch.
- Preserve stable canonical identities, provenance and approved parent relationships. Never merge by name alone or guess missing fathers. Keep cycle, unique-primary-father, founder and hierarchy validation.
- Preserve PersonPrivacy and tri-state living status. Unknown does not mean deceased. Imported/source-supported/pending claims do not mean historically verified. Unresolved raw seed sources require the existing family review permission.
- Preserve the UX-P0.1 permission catalog, family memberships, family functional roles, entity scopes, review separation and policy checks. Honorary person attributes never grant user privileges.
- The development seed entry point is DatabaseSeeder → RolePermissionSeeder, PersonAttributeSeeder, AlZoubiFamilyTreeSeeder, SiteUsersSeeder. The self-contained seed snapshot is in App\Services\Imports\UnifiedGenealogyDataset; runtime does not require resources/data files. Preserve source evidence and pending reviews; protect environment restrictions and administrator changes on reruns.
- Use the existing tree-layout.js / genealogy-tree.js renderer and portal services. Classification colors come from PersonAttribute records. Counts must use visibility-appropriate database queries.

## Changes and safety

- Keep changes necessary and idiomatic. No unrelated formatting, cosmetic renames, speculative deletion or weakened tests. Trace container/route/CLI/config/test/provenance references before removing code or files.
- Do not edit vendor/, node_modules/, third-party public assets, generated dependency/autoload files or framework internals. Do not change manifests/lockfiles without a justified dependency task. Use supported extension points.
- Preserve migrations, original genealogy evidence, .env/local secrets, .claude/ and existing Codex configuration. Do not expose private data under public/.
- Never reset production, staging, shared or valuable databases. Verify actual environment, resolved connection and existing contents first. Use a newly created disposable SQLite file or dedicated PostgreSQL test database for destructive tests.
- No commits, pushes, merges, tags, deployments or production host activation without explicit task authorization. Preserve unrelated user changes.

## Implementation and validation

- Follow existing PHP types, return types, model attributes/casts and named routes. Format only changed PHP files with vendor/bin/pint and explicit paths.
- Run focused tests while editing; run full SQLite/PostgreSQL suites for completed architecture changes. Read [the testing guide](docs/ai/testing-guide.md) for isolation and environment prerequisites.
- Main commands: php artisan test --compact; node tests/js/tree-layout.test.cjs; php artisan genealogy:seed-audit --json --dry-run.
- Browser scripts live in tests/Browser; use disposable fixtures and an isolated local server. Do not mutate the normal development database just to exercise a browser test.
- Update the project map or focused documentation only when architecture/workflows materially change. State exactly what was tested and any limits.

## Documentation authority

- Architecture/privacy baseline: docs/Juthoor_Master_Project_Requirements_v2.0.md.
- Supplementary UX direction: docs/Juthoor_Experience_v3_Requirements_and_Roadmap.md.
- Current implementation plan: docs/implementation-roadmap.md; implementation history: docs/development-progress.md.
- Source lineage: docs/genealogy-source-inventory.md and docs/genealogy-import-coverage.md. Read these only for source/import work.
- Claude-specific setup belongs in CLAUDE.md / .claude; Codex-specific setup stays in its native configuration. Shared skills and compatibility are indexed in docs/ai/project-map.md.
