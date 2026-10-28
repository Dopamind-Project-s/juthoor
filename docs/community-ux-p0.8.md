# UX-P0.8 — Community publications

Implemented on 2026-10-28, based on `009c932c`. Laravel 13.31 / PHP 8.3.6, Blade, Bootstrap/Cairo and native JavaScript. No genealogy layout, identity, parentage, privacy, permission catalog, dependency or deployment changes.

## Baseline and reuse

Phase 4 already provides `NewsPost`, `CommunityEvent`, `CommunityContentService`, `CommunityPublishingService`, `CommunityNotifier`, `EntityContentScope`, `CommunityEntityPolicy`, `MediaAccess`, citations and versioned notices. The family and entity portals already share publication cards. These remain the implementation, including draft/submission/review/archive, sensitive consent checks, scheduling, correction/cancellation history and existing in-app notifications. No parallel publishing, upload or notification backend was added.

Before-change news/event/media tests: 24 passed / 27, with three existing expectation failures. Broader historical failures and their exact names are recorded in the results JSON; tests were not weakened. Three baseline screenshots were reconstructed from the HEAD controller and Blade templates on a separate local server, using the same explicitly synthetic fixture database. They are comparisons of the previous implementation, not screenshots of the deployed website.

## Public experience

- News landing: editorial hero, explicitly featured eligible story, latest cards, category navigation, normalized Arabic search, inclusive local-date boundaries, scoped pagination and actual database counts. Featuring never selects unpublished, future, cancelled or expired news. Deterministic timestamp/ID ordering; featured stories occur once per page.
- Existing categories remain. Congratulations, graduation, wedding, achievement, appointment and award notices use the same model, workflow and forms. Celebration cards have a restrained gold accent; death/condolence pages use an understated presentation. No name-based Person associations, invented real activity or automatic death-state changes.
- Reusable cards show dates, owner context and available approved image attachments through existing authorized media URLs. Missing images produce text cards. Family and fakhd sections reuse these cards and their visibility maps.
- Details retain escaped body text, approved citations, authorized people, contact/map consent, attachments and change notices. Added public related-entity navigation, scoped related articles and share/copy-link controls. Shared URLs omit preview/query parameters. Unpublished linked events and announcements owned by unpublished entities are excluded.
- Calendar/list toggle, month navigation, Arabic search, category and status filters preserve scope and other query parameters. List view paginates 20 records in the selected month. Calendar keeps local-day grouping and existing upcoming/past sections. Ongoing requires recorded start/end times; completed follows the existing end/start rule. Rescheduling uses existing notices, without inventing a new persisted event state or erasing history.

## Editorial and security behavior

News and event forms now have safe local previews. Preview uses `textContent`, saves nothing and sends no notifications. News forms show optional death/condolence details contextually, while preserving the no-JavaScript form and server consent enforcement. Featuring appears only for publishers, and unauthorized submitted feature changes are rejected server-side. The save path now supplies its existing module key to the publish policy, matching the form/controller and preserving news-only functional roles without granting unrelated content permissions.

Existing audience choices, entity/people links, approved source selection, media attachment/rights checks, scheduling, submission/review actions and in-app review/change notifications remain. No new notification channel, external sharing API, ranking, payments or social feed was introduced. The new share button invokes the browser's share/copy capability only after a click.

All feeds still start from `onEntityPage` and `visibleTo`; filtering never replaces family, entity, audience or publication eligibility. Descendant roll-up and targeted ancestor announcements remain single records, without sibling leakage. Normal article payloads exclude unpublished attachments even for managers; the existing explicitly authorized preview can display them. Related entities must have a public ancestor path. Person publication decisions remain delegated to the existing privacy implementation.

## Data and operations

Migration `2026_10_28_020000_add_community_publication_discovery_fields` adds `news_posts.is_featured` (default false) and normalized `searchable_text` to the two existing publication tables. It backfills search text in batches of 100, preserving original timestamps and publication decisions. `HasPublicationSearch` updates the derived text on Eloquent saves. Direct bulk SQL publication edits must also rebuild this derived search text; use the existing content service normally.

Apply through the normal migration workflow (`php artisan migrate --force` on the intended deployment). No real application database was migrated/reset during this work. Disposable SQLite `/tmp/juthoor-community-p08.sqlite` and PostgreSQL `juthoor_p08_regression` were used for verification. The development-only browser fixture rejects any other SQLite path and local/testing environment; it is not a production seeder and should be run once against a freshly migrated disposable fixture database.

## Accessibility and performance

Existing RTL typography, breadcrumbs, labels, focus styles, pagination and semantic notices remain. New controls have visible labels, status announcements and active-view indicators; previews respect reduced motion. Cards wrap long titles; 390px Chrome journeys check document overflow. No frontend tree files were changed.

Publication/relationship/media lookups are eager-loaded; visibility checks reuse one media-access instance for each listing. News and event lists paginate in SQL. Calendar monthly records are server rendered, not a full-family JavaScript payload. List mode skips unused calendar/upcoming/past queries. A small synthetic fixture measured 17 queries for news, 20 for calendar, 14 for list and 21 for article, with 18–29 KB HTML and local response times below 100ms. This is an instrumentation sample, not production load certification; large monthly event volumes and broad substring search should be profiled against production-shaped data before adding database-specific indexes.

## Reproducing verification

Use PHP with PDO SQLite/PostgreSQL. Run the six new cases in `CommunityNewsTest.php` / `CommunityEventTest.php`, then relevant/full suites. Node layout tests remain `node tests/js/tree-layout.test.cjs`.

For browsers, migrate the dedicated SQLite path and run `tests/Browser/fixtures/community-p08.php`, serve the application at localhost:8048 with that same database, then run `tests/Browser/community-publications.cjs` and `community-editor.cjs` using Node 20+, external Playwright and Chrome. Synthetic accounts use `p08-editor` / `p08-publisher` and password `password`, exclusively in that guarded fixture. Screenshots and exact verification results are linked below.

## Results and remaining limits

See [machine-readable results](community-ux-p0.8-results.json) and [screenshots](screenshots/ux-p0.8). Functional implementation is complete; all-green regression/release approval and UX-P0.9 release readiness remain conditional on resolving previously recorded regression expectations. No failing test or privacy rule was removed to obtain a green result. Browser coverage uses explicitly fictional content, not deployed Hostinger data; no public deployment or production validation was performed. Approved image handling uses the existing media service and automated media coverage; the screenshot fixture has no real family photographs. Native OS share-sheet behavior and a screen-reader audit remain manual checks.

### Verification totals

| Check | Actual result |
|---|---|
| Before-change news/event/media | 24/27 passed; 3 existing failures |
| Full SQLite snapshot | 429/464 passed; 35 recorded historical failures |
| Full PostgreSQL 16.15 snapshot | 430/465 passed; identical 35 failure names |
| Final relevant SQLite | 52/57 passed; 5 recorded historical failures |
| Six new cases, final SQLite / PostgreSQL | 6/6 each, 25 assertions each |
| Node tree-layout regression | 40/40 |
| Chrome 147 | 14 public/mobile/share + 7 editorial journeys; zero page errors |
| Pint / whitespace | Passed |

Full-suite snapshots were collected while new cases were being added; the final six new cases were subsequently rerun on both databases. The new cases cover normalized Arabic/feature deduplication, editor feature rejection, date/filter validation, ongoing/list/sibling scope, module-scoped publisher featuring and unpublished linked-event exclusion. Historical failure names match the earlier UX-P0.6 full-suite baseline; they concern privacy/seed/static-route expectations and are not silently marked passing.

### Changed files

| Area | Changes |
|---|---|
| Controller | `CommunityPublicationController`: validated scoped discovery, deterministic featuring, calendar/list filters, related content and media visibility |
| Models | `NewsPost`, `CommunityEvent`, shared `HasPublicationSearch`: categories, feature flag, derived search text and ongoing display state |
| Publishing | `CommunityContentService`: existing module-aware publish authorization and feature guard |
| Migration | Additive search/feature columns and timestamp-preserving backfill |
| Public Blade | News/calendar/detail pages, shared cards and family/fakhd card callers |
| Management Blade | News/event safe preview, sensitive contextual fields and publisher feature control |
| Assets | `community-publications.css` / `.js`: scoped RTL presentation, local preview and explicit share actions |
| Tests | Six feature cases, guarded synthetic fixture and two reproducible Chrome scripts |
| Documentation | This report/results/screenshots and three authoritative audit/progress/roadmap updates |

No files were deleted. The results JSON enumerates every source/document path and screenshot. UX-P0.8 implementation is delivered with conditional regression sign-off; UX-P0.9 can build on these components, but release approval remains blocked by the historical failing expectations.
