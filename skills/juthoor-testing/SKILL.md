---
name: juthoor-testing
description: "Prepare isolated Juthoor database, seed, browser and regression tests without touching valuable development data."
---

# juthoor-testing

Read docs/ai/testing-guide.md and the existing relevant test/fixture before adding coverage. Inspect phpunit.xml and explicit DB overrides; RefreshDatabase can drop the selected database. Use an in-memory/new SQLite file or a newly created dedicated PostgreSQL 16 database, not the populated development file.

Run focused Pest files while editing, then php artisan test --compact on SQLite and PostgreSQL for architecture changes. Run node tests/js/tree-layout.test.cjs for graph/UI compatibility. Preserve real failure coverage; do not replace removed legacy tests with tautological tests of the new implementation.

For browser work inspect tests/Browser scripts and their fixture/port restrictions. Export fixture IDs from the isolated seeded target, start its local server and use actual Chrome. The renderer virtualizes/folds nodes: assert loaded graph totals and interactions, not that every person is mounted in SVG simultaneously.

Keep run artifacts in /tmp unless updating an approved reference. Record engine/version, actual test counts, errors and command/environment overrides; older JSON reports are historical evidence, not proof that the current work passes.
