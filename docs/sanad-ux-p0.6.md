# UX-P0.6 — دليل السند

Functional implementation is complete. Regression sign-off is blocked by 35 pre-existing failures: the full SQLite and PostgreSQL runs reproduce the same failing test names as the baseline, with no new full-suite failure. This phase does not claim an entirely green repository or an unconditional UX-P0.7 release approval.

## Baseline and audit

- Branch `main`, HEAD `e5eefec7`; PHP 8.3.6, Laravel 13.31.0, Composer 2.9.7. Before this phase, the working tree already contained `docs/hostinger-deployment.md`, `public/js/genealogy-tree.js` and `resources/views/genealogy/tree.blade.php` changes. Those tree/deployment changes were preserved and are not part of Sanad.
- Reviewed shared instructions/project map, Phase 4 and UX-P0.4/P0.5 progress/roadmap/audit, the latest embedded seeder and maintenance toggles, MemberProfile, DirectoryReport, MemberDirectory, both directory controllers, privacy, family membership/functional permissions, routes and existing tests.
- The existing directory is opt-in and has no automatic genealogy enrollment. One profile per user/family, per-field privacy, affiliation decisions, private photo delivery, contact notifications, reports, suspension, reinstatement and withdrawal already existed. These implementations were reused.
- Existing UI was a dense filter form, minimal cards, one long edit form and sparse moderation lists. Skills were stored but missing from profile display. General search normalized Arabic, but profession/skill filters used unnormalized raw values. A published profile could show or match a now-unpublished affiliation. Listings did not exclude profiles after their owner's membership was revoked. Directory responses lacked a no-store cache policy. Withdrawal triggered two confirmations. These defects were addressed.
- Before screenshots use the original templates with synthetic opted-in profiles in an isolated SQLite database. No production or normal local development database was reset.

## Landing, listings and profile

The family website now presents **دليل السند** with the requested introduction, family/fakhd navigation, voluntary enrollment invitation, privacy explanation, prominent search and data-derived sector categories. Counts use eligible audience-scoped queries rather than genealogy estimates. Empty data creates empty sections, not sample members.

Cards show only consented fields: image, readable name, profession/specialization, current verified affiliation, general city, sector, skills and services, plus profile/contact actions. Responsive grid and compact list modes retain filters and pagination (20 profiles per page). Search feedback and reset controls work without a second frontend library.

Profile details use the family frame, a professional header, explicit affiliation-versus-professional-claim explanation, skills tags, professional details, experience, services, collaboration, links and contact/report panels. Optional private fields never render merely because a name is public in the genealogy system. User accounts, directory profiles and genealogy people remain distinct.

Family navigation calls this feature دليل السند. Fakhd pages link to the same directory with entity and existing `direct`/`descendants` scope parameters; profiles are not copied across entities.

## Search and privacy architecture

The existing ArabicNameNormalizer and per-audience search text are retained. Two nullable JSON columns, `search_fields_public` and `search_fields_members`, hold normalized text per consented field. Profession/specialization, skills, services and collaboration filters now query the matching audience/field rather than another visible field or hidden raw column. General partial-name/keyword search continues using the established index. No external search service or additional search engine was introduced.

Migration `2026_10_28_010000_add_sanad_search_fields_to_member_profiles` backfills existing profiles in chunks of 100 with their existing consent settings. It never lists a profile or widens an audience. Saving/reviewing a profile rebuilds its search fields through the existing service.

Filters include profession/specialization, skills, sector, city, business category, services, collaboration, current verified affiliation and fakhd scope. The family is selected by the existing scoped route. Public affiliation filtering and display check current entity/ancestor publication. Public general text intentionally excludes entity names, preventing stale publication changes from leaking hidden entity names through search. Family members can still search affiliations, and public users can use eligible entity filters.

Listings/categories/counts exclude withdrawn, suspended, inactive-owner, unverified-owner and revoked-membership profiles. Audience-safe sectors/cities only are offered as data-derived choices. Photos retain authorized private delivery. Directory HTML uses `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`. No private profile fields are serialized into public JavaScript or hidden DOM elements.

PersonPrivacy was not changed in this phase. Directory consent does not inherit the earlier system-wide genealogy name-publication changes.

## Onboarding and withdrawal

The existing save endpoint now has six progressively enhanced steps: identity/audience, affiliation, profession/experience, business/collaboration, contact/privacy, and review/save. Only the display name is mandatory; existing audience/consent defaults remain restrictive. Progress/completeness, keyboard-focusable step headings, field errors, previous/next controls and a client-side audience preview reduce accidental disclosure. Preview uses textContent and the owner's current choices; it makes no publication request and excludes unverified affiliations. Without JavaScript all sections remain available in sequence.

The member explicitly opts in at save. Unchecked listing consent is submitted as false even when validation fails. Suspended profiles receive an accurate saved-but-suspended message. Withdrawal uses one confirmation and the existing deletion workflow; authentication and genealogy records are untouched. Existing audit trails remain in place.

## Contact and moderation

The existing notification-based in-app contact action now accepts an optional message, with a neutral default message and clear sender feedback. Existing auth/verified middleware, recipient consent, family/audience checks and route rate limits remain. Submitting a request reveals neither party's hidden phone or email. This backend does not have contact approval/decline states, a block model or chat; none was invented or advertised. Reporting is retained.

Directory Moderators use the existing `directory.moderate` permission through a family membership. Their screen now has queue totals, affiliation requests, open reports, suspended profiles and a paginated decision history. Queue sections use 15-item pagination with independent page parameters; member/private contact fields are not added to moderator payloads. Report closure adds a minimal security-audit event, retaining the decision after profile withdrawal without copying private contact data into that event. Existing suspension/reinstatement and affiliation notification logic are reused.

A functional-role regression verifies that a reader with Directory Moderator can process a report, gains no `person.create` permission and cannot moderate another family. Browser review uses that role, not a super administrator.

## Performance and accessibility

Profiles paginate server-side; no complete member list is loaded into JavaScript. Entity/family relations are eager-loaded; current public entity IDs are memoized within the service instance instead of queried per card. Sectors use one grouped, consent-scoped query; the existing family/listed index narrows searches. Substring JSON/text filters still scan the eligible family subset: no speculative B-tree index was added for `%term%` predicates.

A read-only local request with three synthetic public profiles returned HTTP 200 in approximately 40 ms: 14 queries overall, including four member-profile queries. This is a small-fixture observation, not a production load benchmark.

RTL/mobile cards, wrapped long Arabic names, stacked filters, readable controls, semantic details/fieldsets, keyboard step navigation, status messages, inherited focus styling and reduced-motion handling use the existing Bootstrap/Juthoor tokens. Chrome journeys checked overflow at 390 px and 1440 px. Real devices, Safari/Firefox and a screen reader were not exercised.

## Verification

| Run | Result |
|---|---|
| Baseline full SQLite | 453 tests: 418 passed, 35 failed; 4,602 assertions |
| Full SQLite after implementation | 459 tests: 424 passed, the same 35 failed; 4,640 assertions |
| Full PostgreSQL 16.15 after implementation | 459 tests: 424 passed, the same 35 failed; 4,640 assertions |
| Final directory SQLite | 14/14 passed; 142 assertions |
| Final directory PostgreSQL 16.15 | 14/14 passed; 142 assertions |
| Node tree layout | 11/11 passed |
| Chrome desktop/mobile | 13/13 journeys; no page errors |
| Formatting/JS syntax/diff checks | Passed for changed sources |

The full runs collected 13 directory cases. The final Directory Moderator case and the final report-audit/pagination adjustments were verified afterward in the complete 14-case directory subset on both engines. No full green result is implied. The full-suite failure-name sets match the baseline exactly; details are in [machine-readable results](sanad-ux-p0.6-results.json). Existing failures include prior genealogy visibility/seeding/portal expectations and maintenance-tool defaults outside this phase; tests were not weakened to make them pass.

The normal development database was not reset. Browser fixtures used `/tmp/juthoor-sanad-browser.sqlite` and loopback port 8046. PostgreSQL used new databases `juthoor_sanad_regression` and `juthoor_sanad_focused` on the temporary PostgreSQL 16 cluster. PHP drivers/Playwright/Chrome were supplied by existing external tools, without dependency changes. No vendor/core or lockfiles were modified, and there was no commit, push or deployment.

## Browser artifacts and reproduction

- [Before desktop](screenshots/ux-p0.6/before-desktop.png), [before mobile](screenshots/ux-p0.6/before-mobile.png).
- [After desktop](screenshots/ux-p0.6/after-desktop.png), [mobile filters](screenshots/ux-p0.6/after-mobile-filters.png), [list mode](screenshots/ux-p0.6/after-list.png).
- [Profile](screenshots/ux-p0.6/after-profile.png), [mobile profile](screenshots/ux-p0.6/after-mobile-profile.png), [privacy preview](screenshots/ux-p0.6/after-privacy-preview.png), [mobile editing](screenshots/ux-p0.6/after-mobile-edit.png).
- [Contact](screenshots/ux-p0.6/after-contact.png), [moderation](screenshots/ux-p0.6/after-moderation.png), [mobile moderation](screenshots/ux-p0.6/after-mobile-moderation.png), [withdrawal](screenshots/ux-p0.6/after-withdrawal.png), [journey results](screenshots/ux-p0.6/journeys.json).

`tests/Browser/fixtures/sanad.php` seeds explicitly synthetic profiles only into its required disposable SQLite path. Run migrations against that path first, then the fixture; serve it on localhost:8046 with the same DB/environment. `tests/Browser/sanad.cjs` refuses other hosts/ports and uses the existing external Playwright/Chrome setup. Use a fresh fixture before each journey run because it creates, moderates and withdraws a profile.

Deploy the schema change with `php artisan migrate --force` through the normal authorized deployment process, then clear compiled views/config as appropriate. No reseeding or reset is necessary.

## Remaining limits and UX-P0.7 readiness

UX-P0.6 is implemented and its directory/privacy/moderation coverage and browser journeys pass. The 35 baseline regression failures must be reconciled before claiming repository-wide release readiness. UX-P0.7 may build on this existing directory architecture; release sign-off remains conditional on that regression work. Contact request lifecycle approvals and user blocking remain unavailable because the reused backend has no such workflow. No marketplace, CV system, public phone disclosure, external search dependency or real-time chat was introduced.


## Changed files

- `AGENTS.md`
- `app/Http/Controllers/Management/DirectoryProfileController.php`
- `app/Http/Controllers/MemberDirectoryController.php`
- `app/Models/MemberProfile.php`
- `app/Services/Community/MemberDirectory.php`
- `app/Services/Portal/FamilySiteChrome.php`
- `database/migrations/2026_10_28_010000_add_sanad_search_fields_to_member_profiles.php`
- `docs/ai/project-map.md`
- `docs/development-progress.md`
- `docs/implementation-roadmap.md`
- `docs/project-audit.md`
- `docs/sanad-ux-p0.6-results.json`
- `docs/sanad-ux-p0.6.md`
- `public/css/sanad.css`
- `public/js/sanad.js`
- `resources/views/community/show.blade.php`
- `resources/views/components/community/directory-card.blade.php`
- `resources/views/directory/index.blade.php`
- `resources/views/directory/show.blade.php`
- `resources/views/layouts/family-site.blade.php`
- `resources/views/layouts/management.blade.php`
- `resources/views/management/directory/edit.blade.php`
- `resources/views/management/directory/review.blade.php`
- `tests/Browser/fixtures/sanad.php`
- `tests/Browser/sanad.cjs`
- `tests/Feature/MemberDirectoryTest.php`
- `docs/screenshots/ux-p0.6/`: synthetic before/after PNGs and browser journey JSON.
