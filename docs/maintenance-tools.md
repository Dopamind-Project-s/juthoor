# Optional maintenance tools

Run `php artisan migrate --force` during deployment to add maintenance_tool_settings. No seed or reset is required. All three tools default to disabled when settings are absent: seed auditing, historical-name reconciliation and dashboard diagnostics. Only an authenticated super administrator can change them at the site-admin dashboard; the route uses the existing authenticated/verified management group, CSRF protection and an explicit super-admin check. Updates are recorded in the security audit log.

Disabled tools refuse direct HTTP access; the seed-audit CLI also refuses execution. Enabling them preserves their existing family permissions. The security audit logger, genealogy integrity checks and privacy remain active. PHPUnit/Node are developer CLI suites, not web application features: they are not executed automatically or exposed through the administrator page. The diagnostics switch controls the existing dashboard health checks, not remote arbitrary test execution.

UnifiedGenealogyDataset contains the sole embedded compressed snapshot, consumed by both the seeder and optional audit. Deleted redundant normalized, source-manifest, review-queue, drawing-extractions, founder-mappings and workbook-audit JSON copies. Original charts, datasets and documents are retained as evidence; they are not needed to run the unified seeder, public portals or optional unified audit. Legacy offline extraction scripts may regenerate intermediate files for research but are not live application dependencies.

Existing test reference to review observations now reads the embedded snapshot. No tests, migrations or seeders were executed for this change. Legacy fixture importers still require their original evidence when explicitly invoked; they are not part of normal runtime.

Removed files:

- `resources/data/genealogy/al-zoubi/normalized.json`
- `resources/data/genealogy/al-zoubi/source-manifest.json`
- `resources/data/genealogy/al-zoubi/workbook-audit.json`
- `resources/data/genealogy/al-zoubi/founder-mappings.json`
- `resources/data/genealogy/al-zoubi/drawing-extractions.json`
- `resources/data/genealogy/al-zoubi/review-queue.json`

## Restored evidence deduplication

Removed only byte-identical root copies whose same-named original remains in the private source-documents directory. Recorded original paths in embedded provenance remain historical source locations; no seeder/audit file reads depend on those paths. SourceDocuments resolves filenames to the retained private directory. Offline source discovery should use the retained copies.

| Removed duplicate | Retained identical evidence |
|---|---|
| `resources/data/بكر خميس1.png` | `resources/data/source-documents/بكر خميس1.png` |
| `resources/data/بكر خميس2.png` | `resources/data/source-documents/بكر خميس2.png` |
| `resources/data/بكر خميس3.png` | `resources/data/source-documents/بكر خميس3.png` |
| `resources/data/بكر خميس4.png` | `resources/data/source-documents/بكر خميس4.png` |
| `resources/data/بكر عيد1.png` | `resources/data/source-documents/بكر عيد1.png` |
| `resources/data/بكر عيد2.png` | `resources/data/source-documents/بكر عيد2.png` |
| `resources/data/بكر عيد3.png` | `resources/data/source-documents/بكر عيد3.png` |
| `resources/data/بكر عيد4.png` | `resources/data/source-documents/بكر عيد4.png` |
| `resources/data/عبدالرجمن1 رحايمه.png` | `resources/data/source-documents/عبدالرجمن1 رحايمه.png` |
| `resources/data/عبدالرجمن2 رحايمه.png` | `resources/data/source-documents/عبدالرجمن2 رحايمه.png` |
| `resources/data/عبدالرجمن3 رحايمه.png` | `resources/data/source-documents/عبدالرجمن3 رحايمه.png` |
| `resources/data/عبدالرجمن4 رحايمه.png` | `resources/data/source-documents/عبدالرجمن4 رحايمه.png` |
| `resources/data/عودتلات.png` | `resources/data/source-documents/عودتلات.png` |
| `resources/data/عودتلات1.png` | `resources/data/source-documents/عودتلات1.png` |
| `resources/data/عودتلات2.png` | `resources/data/source-documents/عودتلات2.png` |
| `resources/data/عودتلات3.png` | `resources/data/source-documents/عودتلات3.png` |
| `resources/data/عودتلات4.png` | `resources/data/source-documents/عودتلات4.png` |
| `resources/data/قواسمه.pdf` | `resources/data/source-documents/قواسمه.pdf` |
| `resources/data/قواسمه.png` | `resources/data/source-documents/قواسمه.png` |
| `resources/data/قواسمه1.png` | `resources/data/source-documents/قواسمه1.png` |
| `resources/data/قواسمه2.png` | `resources/data/source-documents/قواسمه2.png` |
| `resources/data/قواسمه3.png` | `resources/data/source-documents/قواسمه3.png` |
| `resources/data/قواسمه4.png` | `resources/data/source-documents/قواسمه4.png` |
| `resources/data/قواسمه5.png` | `resources/data/source-documents/قواسمه5.png` |
| `resources/data/قواسمه6.png` | `resources/data/source-documents/قواسمه6.png` |
| `resources/data/قواسمه7.png` | `resources/data/source-documents/قواسمه7.png` |


## Administration suspension (protection remains active)

Three additional switches default to disabled: audit viewing/recovery, genealogy administration (people/relationships/branches/trees, source management, imports, reconciliation, merge/review and cross-family administration), and user/permission/membership/invitation administration. They gate HTTP routes including generic section/resource screens. Public portals, trees and profiles continue using their existing visibility checks. The site-admin dashboard omits audit rows while audit viewing is disabled. Security audit writes, login authorization, family isolation, privacy, graph integrity and CLI integrity validation remain enabled.

Only the super administrator can update these switches; the dashboard and its settings endpoint are always reachable subject to existing authentication and super-admin authorization. Turning a function on does not grant permissions or bypass policies. Existing rows need no new migration: missing switch names are disabled. Developer CLI commands and offline tests are not controlled as web administration screens. No tests or migrations were run.


## Removal of resources/data

At the explicit request to remove every file, deleted all remaining 88 files under resources/data, including the original images/documents and legacy datasets. Runtime importers, shared-ancestry fixtures and historical compatibility readers now read the one embedded UnifiedGenealogyDataset snapshot. Historical names, identities, parent mappings, provenance filenames, hashes and review observations are retained in that snapshot. No person or relationship identities were remapped. The former source resolver and the five extraction scripts that depended on removed originals were removed. The removed original binaries are not downloadable or visually inspectable from this checkout; Git history contains the previously tracked originals. References to old filenames are provenance, not filesystem dependencies. Existing private-exposure assertions now inspect retained hashes against public files without requiring original files. No tests, database migrations or import commands were executed.


## Administrator activation and seed approval

The three administration/viewer switches now default to enabled; explicit later administrator choices still override defaults. A new migration enables existing switch rows and approves existing canonical Al-Zoubi seed identities in the main tree. People, accepted father relationships, their person/father citations, seed sources and branch classifications become verified; entities become published. Conflicting assertions and unresolved observations remain separate review items. The migration does not insert missing genealogy, rewrite parent relationships, set living status or remove privacy protections. Known-deceased seed people become historical so the existing public visibility rule works; living/unknown people remain active/private. Rerunning the unified seeder applies the same classifications. The migration records the approval in the audit log.

Deploy with php artisan migrate --force and php artisan optimize:clear. No migration or tests were run in this session, and no live Hostinger database was accessed.


## Explicit public approval of seed names and graph

The administrator explicitly requested public display of all Al-Zoubi seed identities, including unknown living status. A migration sets public_narrative for matching embedded canonical keys in the Al-Zoubi main tree; rerunning the seeder applies it too. The shared public scope now includes verified/sourced identities with explicit public narrative approval regardless of living/historical status. Records without that approval keep the former eligibility rule. Name, graph and search visibility therefore agree. No deceased status is inferred, sensitive person fields retain PersonPrivacy protections, and verifiedHistorical remains strict for cross-family historical linking. Approval is audit logged. Deploy using php artisan migrate --force and php artisan optimize:clear. No migrations or tests were run locally.


## System-wide name eligibility

At the administrator's request, every nonblank verified/sourced person name is publicly eligible across families, independent of living status, person classification, public_narrative and optional profile fields. Explicitly published pending narratives remain eligible as before. The shared Person::publiclyVisible scope governs tree loading, search, profiles and portal people/founder selection; callers still enforce family/tree availability, branch publication and relationship evidence. Sensitive profile fields continue using PersonPrivacy. No father relationship is invented for an isolated eligible person. Strict verifiedHistorical is retained for historical cross-family claims, not ordinary name display. This is a query change: no database migration or reseeding is needed. No tests were run.
