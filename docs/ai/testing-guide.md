# Testing Juthoor

PHP 8.3+ needs PDO SQLite for the default suite and PDO PostgreSQL for PostgreSQL 16. The application uses Pest; phpunit.xml fixes APP_ENV=testing, an in-memory SQLite target, array mail/cache/session and a deterministic test URL. PostgreSQL connection variables supplied externally override the default PHPUnit values; never supply a valuable database.

```sh
php artisan test --compact tests/Feature/PersonPrivacyTest.php
php artisan test --compact
node tests/js/tree-layout.test.cjs
vendor/bin/pint path/to/changed.php
```

For PostgreSQL, create a dedicated empty test database/cluster first. The existing .github/workflows/tests.yml documents PostgreSQL 16 service setup. Set DB_CONNECTION=pgsql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD explicitly for that target, with DB_URL empty. RefreshDatabase and migration tests are destructive to the selected test database.

Use a separate disposable SQLite file for fresh-seeding/browser workflows. Run migrations and DatabaseSeeder there, then rerun and compare actual table/key counts. The normal development file is populated and must not be presumed empty. genealogy:initialize-development refuses nonempty/nonlocal targets; genealogy:seed-audit --json --dry-run is read-only.

Browser scripts require a compatible external Playwright installation, Node 20+ and Chrome. tests/Browser/unified-genealogy.cjs uses its own read-only fixture exporter and a local server on port 8039; inspect its environment options before running. Other browser scripts have their own fixture/port requirements. Preserve raw-source reviewer restrictions and public/unknown-living privacy checks. Store new cleanup-run artifacts under /tmp unless a committed reference screenshot materially changes.

Host-specific temporary PHP extensions or PostgreSQL binaries are not project dependencies. Detect available tools and record exact overrides in the run report; do not alter vendor or system services to hide test failures. Run meaningful focused tests during edits and the full SQLite/PostgreSQL and Node suites before completing an architecture cleanup. Existing feature tests cover RBAC, source/media access, family/domain isolation, portals and seed repeatability.
