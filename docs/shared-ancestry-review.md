# Complete trees and reviewed links between families

## Implemented behavior

The public tree automatically fetches every authorized person in batches of 160. It includes all recorded descendants and visible unlinked people, with every branch initially expanded. Batches have stable ID cursors, totals and completion flags; the UI reports progress and retryable failures. Branch focus retains the full ancestor path without the former 20-generation cap; isolation hides side branches. Search, pan, pinch/zoom, fit and per-person collapse remain available. Active reference attributes appear as text badges with contrast-aware colors and a visible legend. Verification labels are also textual.

The supplied Al-Zoubi foundation remains one tree. Its 79 existing stable keys, exact spellings, 78 father edges, titles, honorifics, assignments and statuses are preserved. Yusuf remains ROOT-076. The explicitly supplied historical foundation is available publicly even when its existing status is pending_review, as requested; it is labeled as a submitted account awaiting review. This narrowly scoped publication rule requires the existing Al-Zoubi family, supplied stable keys and supplied-source provenance. It excludes living/nonhistorical/restricted records and does not publish arbitrary pending records. It changes no verification status and marks no person or relationship disputed. Future branches still use the ordinary graph integrity rules.

The general public match boundary remains stricter: both endpoints must be historical, nonliving, reviewed/sourced records in active trees and families. Publishing the submitted foundation alone does not authorize a cross-family match.

## Pages and workflow

- `/families/{family}/tree`: complete authorized tree, automatic batching, progress and legend.
- `/shared-ancestry`: approved source-backed links, two separate family records, visible descendant paths with parent labels, citations, approval reason/date and reviewer. Trees without a defensible connection remain listed separately.
- `/manage/cross-family-links`: Super Admin review queue with status filters and pagination.
- `/manage/cross-family-links?family={slug}`: Family Admin submission/tracking queue.
- `/manage/cross-family-links/create?family={slug}`: independent name searches, existing-person selectors, same-person or either parent/child direction, source URL/reference/locator and evidence notes.
- `/manage/cross-family-links/{id}`: side-by-side ancestral paths, names, titles, active attributes, citations and review decision. The reviewer is an active verified Super Admin; no new broadly privileged reviewer role was introduced.

The link is separate from both family trees. It never merges people, copies ancestors, adds an ordinary cross-tree father edge or infers identity from a name. Family Admins cannot approve. Readers/editors cannot inspect private proposals or their evidence through the family source library. A Family Admin sees only public records from the other family, even during comparison. Approval requires an explicit evidence confirmation and reason, records actor/time, and reviews the proposal-specific citation/source. Rejection/dispute requires a reason and changes neither tree. Approval rejects cycles introduced by cross-family parentage or identity equivalences.

Approved links store a dependency fingerprint of the participating trees' people, relationships, tree settings, attributes, source citations and proposal evidence. Ordinary saves/deletes immediately re-evaluate approved links; public reads recheck the fingerprint to catch bulk updates too. A change conservatively withdraws affected links as `needs_review`; rollback rolls back that withdrawal. Reapproval is explicit. This deliberately rechecks more than just the shortest path: changes elsewhere in a participating tree may also require reapproval. Audit records retain proposal, decisions and invalidation events; sensitive snapshots are hidden from ordinary family readers/editors. Evidence can be updated by its owning Family Admin or Super Admin, explicitly resubmitting the link for review and withdrawing prior approval.

## Research and evidence limits — consulted 2026-10-17

No real cross-family match was approved or seeded by this task. The only approved examples were synthetic test/browser fixtures with `example.org` evidence, isolated from development data.

| Stored reference | Consulted source | What it supports and does not support |
| --- | --- | --- |
| `research-dar-ifta-4031` | [Dar al-Ifta: نسب النبي صلى الله عليه وآله وسلم](https://www.dar-alifta.org/ar/ourreligion/details/4031/نسب-النبي-صلى-الله-عليه-وآله-وسلم), paragraph on Adnan; cites Ibn Hisham | Describes scholarly agreement on the prophetic lineage to Adnan. It does not establish the supplied Al-Zoubi chain or every relationship beyond Adnan. |
| `research-islamweb-202633` | [Islamweb, fatwa 202633, 2013-04-02](https://islamweb.net/ar/fatwa/202633/نسب-النبي-المعلوم-الصحة), passage quoting Zad al-Ma'ad | Distinguishes agreement to Adnan from differing accounts above him; describes descent from Ishmael, not proof of each supplied intermediate father edge. |
| `research-islamweb-16525` | [موسوعة السيرة النبوية والتاريخ الإسلامي: نسب عدنان](https://www.islamweb.net/ar/library/content/200/16525/نسب-عدنان), passage on differing counts/names | Records traditional genealogies and variation in the intervening generations. It is contextual evidence of variation, not a substitute for reviewing the exact supplied sequence. |

`AlZoubiResearchContextSeeder` is optional and idempotent. It attaches three pending research sources and six contextual citations to existing Adnan (`ROOT-029`) and his recorded father relationship. It creates no ancestors/branches and changes no person's or relationship's verification status. All contextual citations explicitly disclaim proving the particular father relationship. The original names seeder is unchanged.

The request both calls the foundation documented and asks to retain pending states until supported review. The implementation preserves the project foundation and makes it visible, while showing the actual stored review state. It neither upgrades records based on resemblance to published chains nor downgrades the foundation. Similarities, compound names such as فهر قريش and عبدمناف قصي, and spelling differences still require human reconciliation; see [the original reconciliation notes](person-attributes-and-shared-ancestry.md).

## Verification and isolated setup

No development/production database was migrated, reset or seeded for this work. Apply migrations through the project's normal deployment process before enabling these pages. Run the optional context seeder only after reviewing its contents and selecting the intended database.

Focused tests cover full batched ancestry, privacy and inactive attributes, optional research idempotence, submission isolation, approval/evidence requirements, rejection/dispute, parentage direction, cycle rejection, source privacy, invalidation, rollback and paths beyond 100 generations.

`tests/Browser/fixtures/shared-ancestry.php` requires a **new** SQLite database under `/tmp/juthoor-shared-browser-*.sqlite` with `APP_ENV=testing`. It refuses an existing database. It seeds the supplied chain plus separate synthetic trees and the development accounts. Run it with the isolated environment, then serve that same database on `127.0.0.1:8018` or `8019`. Run `tests/Browser/shared-ancestry.cjs` with `BROWSER_BASE_URL` pointing there and Playwright available. The browser script exercises full 79-person/181-person views, batch completion, deep branch focus/isolation, search, badges, mobile layout and the submission/review/publication/dispute lifecycle. Do not point it at user data.
