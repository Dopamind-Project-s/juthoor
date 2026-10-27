# UX-P0.7 — Hybrid unified genealogy chart

The existing SVG tree now combines individually visible serpentine ancestry with a traditional subtree-width-aware descendant chart. All three expansion modes, existing scope filters, manual expansion, search and camera interactions use the same authorized graph. Functional/browser validation is complete; seven existing PHP expectation failures prevent an all-green regression claim.

## Baseline and existing architecture

Baseline branch `main`, HEAD `347428b3`, initially clean. Reviewed AGENTS.md, CLAUDE.md, shared testing conventions, v2 genealogy invariants, v3 tree/portal direction, latest audit/roadmap/progress, and completed UX-P0.6. PHP 8.3.6, Laravel 13.31.0, Node 18.19.1 for pure layout tests; existing external Node 22/Playwright/Chrome for browser work. No new dependency or framework was introduced.

The old tree-layout.js combined compact vertical ancestry, folded rows, sibling grids and a width cap that wrapped wide siblings into later visual rows. That was the main source of tall charts and generation ambiguity. Its graph index, source edge metadata, scope filtering and reversible chain rows were reusable. The existing genealogy-tree.js already provided SVG virtualization, dynamic attributes, founder cards, search, keyboard/camera/history, quick-add and profile navigation. These interfaces and the existing palette were retained.

Inspected ProgressiveGraphService, GenealogyTraversalService, BranchFocusService, PublishedTreeTraversal and CommunityGenealogy. Actual data was initialized using the existing unchanged seeders only in a new disposable `/tmp/juthoor-tree-p07.sqlite`. The seeded Al-Zoubi graph has 1,688 people and 1,687 father edges. Before screenshots show the original renderer on this data. No normal development/staging/production database was reset.

## Layout contract and algorithm

`tree-layout.js` remains the pure, DOM-independent engine. Its scope is received eligible nodes/edges, never unrestricted private database records. It returns card/chain items, person rectangles, connector routes and canvas dimensions. Default cards also carry zero-based actual generation within their authorized component and a serpentine flag. Graph identities, father edges and node metadata are not mutated.

1. Index primary edges and build the visible forest iteratively; each person is placed once, including disconnected eligible components. Root-to-person lookup is now push/reverse rather than repeated unshift.
2. Find the eligible single-child root prefix dynamically. Founder status does not break a genuinely single-child path. The transition uses eligible children in the selected scope **before display-mode folding**: hiding an ordinary sibling does not turn a real fork into a false linear ancestry or move founders onto a new snake row.
3. Measure card widths/heights, including existing badges/titles, using the existing renderer. Choose ancestry columns from the measured maximum card width, horizontal gap and a viewport-informed virtual ancestry width (640–1600 px). This may exceed a phone viewport by design.
4. Place each prefix ancestor as its own card, alternating row direction. Row-end drops and sequential horizontal connectors preserve the actual father-child order. Direction arrowheads use the existing relationship verification stroke. Row height accommodates the largest card.
5. Measure descendant subtree footprints bottom-up, then place nodes top-down without recursion. A subtree receives at least its card width or the sum of its child footprints/gaps. Parents center over their allocated child space. A shared per-generation height grid accommodates long names, titles and badges. Siblings never wrap; independent subtrees do not overlap.
6. Generate orthogonal parent/bus/child connectors in the vertical gaps. Different generations stay at their real relative depths, rather than a founder rank. Serpentine ancestry is a compact visual prefix; its underlying parent order and generation values remain intact.

No surname, ancestor key, Yusuf position, fixed generation or fakhd-specific coordinates control layout. No placeholder Person nodes are added. For incomplete authorized graphs, generation starts at each received component root: the renderer does not infer a hidden parent or disclose an undisclosed generation count.

The obsolete sibling-grid and wrapped-sibling layout paths were removed. The optional **سلاسل مختصرة** setting retains reversible ancestry folds only: founder/focus cards stay cards, while ordinary root-prefix runs can fold/unfold. Descendant siblings and generation rows remain traditional even in that optional presentation. Searching a folded ancestor unfolds its actual chain before centering it.

## Classification and the three modes

ProgressiveGraphService adds only a `boundaries` list to existing founder metadata. It derives `main` for top-level approved fakhd/family-group branches and `sub` for nested branches or explicit sub-fakhd classifications. Multiple associations may contribute both boundaries to one existing Person. Existing kind/classification, attributes, branch/entity links and verification remain unchanged. The public query still excludes unapproved/ineligible associations according to the existing rules; no new query or private parent name is needed.

- **العرض الكامل (`full`)** keeps all eligible records in the selected scope; no depth cap or founder cutoff.
- **الأفخاذ الرئيسية (`main_branches`)** keeps every main founder and all required connector paths, including a path through an earlier main founder to a later one. Ordinary descendant detail is closed by default.
- **الأفخاذ الفرعية (`sub_branches`)** adds all eligible subordinate founders and their connector paths, at their real depths. All main founders remain represented. A main branch with no recorded subdivision remains a terminal expandable main-founder card; no subordinate branch is invented.

A scope without any eligible branch-boundary metadata remains fully displayed. This prevents an ordinary/person-focused genealogy from becoming empty merely because it has no fakhds.

Display modes are applied after existing tree/fakhd/isolation/ancestor/descendant scope filters. They do not replace or clear those filters. A compacted root prefix and a hierarchical descendant grid are still calculated from actual scope relationships, independent of classification depths.

## Manual state, search and navigation

Manual opened/closed sets are separate from the selected mode and from optional chain-fold state. Explicit expansion reveals the selected subtree inside the current graph; unrelated branch boundaries remain closed. Explicit collapse wins over defaults, including deeper targets, until reopened. Mode changes clear branch overrides predictably, retain selected scope/search and keep the selected person's connector path visible. A tooltip explains this reset policy. `display` and `ancestry` URL parameters participate in browser history.

Each card with eligible scoped children has a keyboard/touch expansion control; the existing detail-panel action calls the same state transition. Child existence/counts come only from received authorized records. The founder's screen coordinate is anchored through relayout, retaining pan/zoom rather than jumping to another branch.

Remote search retains the existing service. A result behind a closed boundary reveals its required authorized ancestor path, removes manual ancestor closures and focuses/highlights the person. Existing profile navigation, breadcrumbs, browser back/forward, ancestor/descendant buttons, permitted add-person actions, attributes and founder popovers remain in the same renderer.

Default initial ancestry framing starts at the right side for RTL rather than leaving unused canvas on the right. Normal zoom remains readable; fit can now go below the previous fixed 0.02 minimum for exceptionally wide charts. Such overviews necessarily make cards small; zoom/focus/pan restore reading size. Resize can change ancestry columns while preserving an anchor, and a previously hidden embedded stage reframes when it becomes visible.

The surrounding page does not become horizontally scrollable. Mobile retains the existing horizontally scrollable toolbar and bottom sheet; the new display selector is ordered first for immediate access. The SVG canvas itself is allowed to be wide. Source/verification stroke classes, family palette, PersonAttribute colors/activation and founder badge styling were not replaced.

## Performance

Graph indexing sorts edges by existing stable ID (`O(E log E)`); forest construction, layout footprint/placement and boundary/path closure are iterative `O(N + E)` passes. Optional ancestry compaction processes connector shifts once, avoiding repeated scans across disconnected components. A 20,000-person chain is handled without recursion overflow. No eligible genealogy is silently truncated.

The existing API batching and viewport/level-of-detail virtualization are retained. Expansions use already loaded authorized data, so they do not refetch private or separate subtree graphs. API loading still has feedback and failure handling. Camera overview may mount many very small cards when the complete canvas is fitted; normal reading views mount a much smaller viewport subset.

A five-run synthetic 10,000-person, three-child hierarchy comparison (same graph, original engine read from Git versus the new engine) measured median pure-layout time approximately **27.5 ms before / 40.9 ms after**. The old bounded chart was 2,296 × 331,852 px; the new generation-preserving chart is 1,573,388 × 1,228 px. The wider canvas is intentional: every sibling gets a single generation row. All 10,000 identities were placed in both cases. These figures are local synthetic observations, not production benchmarks.

The real Al-Zoubi initial browser run placed 1,688 people, including 76 individually displayed prefix ancestors, with roughly 20 ms relayout and around 30 mounted cards in the initial viewport. See the recorded run metrics rather than treating timing as a universal guarantee.

## Verification

| Check | Executed result |
|---|---|
| Node baseline | 11/11 passed |
| Node final | 40/40 passed: default/compact ancestry, turns/order, transition, siblings, unequal widths/heights, depth 5/7/9, boundaries, overrides/search, collisions, verification, privacy, determinism and large trees |
| Relevant SQLite regression | 59 tests: 52 passed, 7 failed, 0 errors; 493 assertions |
| Same PostgreSQL 16.15 regression | 59 tests: 52 passed, the same 7 failed, 0 errors; 493 assertions |
| New boundary API test alone | 1/1 passed; 8 assertions |
| Actual Chrome | 16/16 journeys; no page errors |
| Syntax/format/diff/Blade compilation | Passed for changed sources |

The relevant PHP set includes GenealogyExperienceTest (which defines the shared fixture function), TreeExperienceTest, GenealogyGraphEndpointTest, FullTreeLoadingTest, PersonPrivacyTest, FamilyPortalTest and EntityPortalExperienceTest. An initial smaller invocation omitted that shared fixture file and produced five undefined-helper errors; the final corrected invocations include it and have zero errors.

All seven final failing test names already occur in the preceding UX-P0.6 full-suite result, covering prior privacy/publication/canonical-route expectations. P0.7 did not change those rules or weaken tests to hide the mismatch. The new founder-boundary case passes, and neither SQLite nor PostgreSQL adds a new failing test name. This is not an all-green repository or release sign-off. The complete Laravel suite was not rerun for this visualization-only phase; the relevant regression set above was run on both engines.

PostgreSQL used a new dedicated `juthoor_p07_regression` database. Browser testing used a loopback-only server at 127.0.0.1:8047 with the dedicated SQLite database, existing unchanged Al-Zoubi seed and a clearly labeled separate synthetic family for generations 5/7/9, 15 siblings and an excluded private record. No seeders, datasets, actual genealogy relationships, privacy policies, role rules, migrations, vendor or dependency files were modified. No commit, push, domain activation or deployment occurred.

## Browser evidence

Before: [unified tree](screenshots/ux-p0.7/before-unified.png), [Qawasmeh](screenshots/ux-p0.7/before-qawasmeh.png).

After:

- [Full unified tree](screenshots/ux-p0.7/after-unified.png) and [serpentine ancestry](screenshots/ux-p0.7/after-serpentine.png).
- [Qawasmeh hierarchy](screenshots/ux-p0.7/after-qawasmeh.png) and [search through closed boundaries](screenshots/ux-p0.7/after-search-reveal.png).
- [Main fakhds](screenshots/ux-p0.7/after-main-fakhds.png), [subordinate fakhds](screenshots/ux-p0.7/after-sub-fakhds.png), [actual SVG generations 5/7/9](screenshots/ux-p0.7/after-generations-5-7-9.png).
- [Fifteen siblings on one row](screenshots/ux-p0.7/after-fifteen-siblings.png), [embedded Bakr](screenshots/ux-p0.7/after-embedded-bakr.png).
- [Mobile Qawasmeh](screenshots/ux-p0.7/after-mobile-qawasmeh.png), [mobile subordinate mode](screenshots/ux-p0.7/after-mobile-sub-fakhds.png).
- [Browser journeys](screenshots/ux-p0.7/journeys.json) and [machine-readable results](tree-ux-p0.7-results.json).

Chrome checked actual SVG generation heights, all eligible graph totals, main/sub/full switching, retained Qawasmeh scope, ancestor/descendant/history, anchored expansion, deep search, pending/private absence from the API, optional compaction, founder hover, keyboard/person navigation, fullscreen/zoom/fit/reset/drag, embedding and mobile touch events/overflow. The screenshots were visually inspected. Mobile testing used Chrome viewport/touch emulation, not physical devices. Safari, Firefox and assistive-technology journeys were not run.

## Files and operation

- `public/js/tree-layout.js`: replaces the wrapped/grid layout with iterative hybrid layout, ancestor-only compact presentation and pure boundary filtering.
- `public/js/genealogy-tree.js`: integrates modes, expansion, orthogonal/sequence routing, camera/history/search, resizing and existing SVG interactions.
- `public/css/juthoor.css`: minor toolbar/control spacing and accessible expansion affordances, using the existing palette.
- `resources/views/genealogy/tree.blade.php`: adds display/ancestry selectors and a scoped visible-record summary to the existing toolbar.
- `app/Services/Genealogy/ProgressiveGraphService.php`: five-line founder-boundary metadata extension, without changing graph eligibility or relationships.
- `tests/js/tree-layout.test.cjs`: adapts superseded wrap/grid assertions to the required no-wrap contract and extends the default/compact invariant coverage.
- `tests/Feature/TreeExperienceTest.php`: verifies metadata is hierarchy-driven, supports different depths and omits nonpublic boundaries.
- `tests/Browser/tree-p07.cjs`, `tests/Browser/fixtures/tree-p07.php`: isolated reproducible journeys/clearly synthetic fixture. The fixture refuses any database other than the dedicated local/testing path and must be run only once on a fresh disposable database.
- This report, results JSON, screenshot directory and the three official audit/roadmap/progress entries document the implementation.

Normal code upload requires clearing compiled views/config as appropriate (`php artisan optimize:clear`); existing script version parameters invalidate changed JavaScript. No new migration or reseed is required for the visual upgrade.

## Remaining limits and UX-P0.8

UX-P0.7 is functionally implemented and validated in the existing application. The seven pre-existing PHP failures must be reconciled separately before full regression/release approval; UX-P0.8 readiness is conditional on that work. Large complete charts may be extremely wide and need focus/pan/zoom by design; fitting everything cannot keep thousands of names readable simultaneously. Existing full authorized-graph fetching/edge-path rendering remain, rather than a new server-side spatial fetch system. Cross-browser, physical-device and screen-reader validation remain outstanding. No authoritative genealogy was inferred from the conceptual sketches or synthetic fixtures.
