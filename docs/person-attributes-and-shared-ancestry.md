# Person descriptors and supplied Al-Zoubi ancestry

## Reference data and administration

- Shared definitions: `/site-admin/person-attributes`; only Super Admin may change them.
- Family definitions: `/manage/families/{slug}/person-attributes`; Family Admin may manage their own definitions and read shared ones.
- Editors can assign active shared/own-family definitions through person forms, but cannot manage reference definitions. Readers retain proposal access; approved proposals use the same scoped validation.
- Deactivation hides badges without deleting assignments. Existing inactive assignments can be retained or explicitly removed.
- `title` and `honorific` are separate optional fields; canonical names remain unchanged. Labels and colors come from the database, including multiple badges on tree nodes. Public queries still filter living/restricted/pending people.
- Assignment changes are included in audit snapshots and supported by the existing reversion workflow.

## Seeding

The new `AlZoubiSharedAncestrySeeder` is opt-in. It does **not** run the legacy dataset/branch seeders.

```sh
# Use an isolated database with APP_ENV=testing and its own DB_DATABASE.
php artisan migrate
php artisan db:seed --class=PersonAttributeSeeder
php artisan db:seed --class=AlZoubiSharedAncestrySeeder
```

It creates 79 people and 78 primary father edges in family `al-zoubi`, tree `main`. Every supplied entry is one person, in the exact supplied order. Repeated محمد entries remain distinct (`ROOT-057`, `ROOT-066`). Stable people keys are `ROOT-001` through `ROOT-079`. Each edge has a key such as `ROOT-079-father-ROOT-078`.

All newly seeded people, edges, source records and citations are **pending_review**. This records a submitted claim, not independent historical verification. The provenance reference is `al-zoubi-shared-ancestry-user-2026-10-16`. Source citations document both person descriptions and the supplied father sequence. Titles/honorifics are populated on newly created people; existing manually maintained descriptions and verification states are preserved.

The six specified prophet assignments and Ali's two assignments are idempotent. Display honorifics are عليه السلام for the six specified names and كرم الله وجهه for علي, matching the supplied/existing display convention; they never enter canonical names.

No branch is created, guessed, activated or renamed by this seeder. يوسف is `ROOT-076`, followed by ذيبان `ROOT-077`, سليمان `ROOT-078`, عبد القادر `ROOT-079`. Future descendants/founders must reference these records; never duplicate this chain.

## Reconciliation required on legacy installations

The existing v4 dataset has 77 shared ancestors and different spellings/identities. The new seeder refuses conflicting canonical keys, parentage, roots, or unkeyed exact-name collisions and rolls back the entire import. It does not silently merge people, normalize names, create a parallel tree, or overwrite manually maintained records. Run it on a clean isolated database to review the supplied sequence. A legacy installation requires explicit human reconciliation before importing. Do not subsequently run the legacy dataset importer over the supplied sequence: that importer maintains its original v4 names.

These are discrepancies against v4, **not proposed corrections**:

| Stable key | Existing v4 text | Supplied text preserved |
| --- | --- | --- |
| ROOT-001 | آدم عليه السلام | آدم |
| ROOT-004 | قينان | قيدان |
| ROOT-005 | مهلائيل | مهلئيل |
| ROOT-007 | أخنوخ عليه السلام | اخنوع |
| ROOT-010 | نوح عليه السلام | نوح |
| ROOT-012 | أرفخشم | أرفشخم |
| ROOT-013 | هود عليه السلام | هود |
| ROOT-015 | أرعو | آرعو |
| ROOT-016 | شاروج | شاروخ |
| ROOT-019 | إبراهيم عليه السلام | ابراهيم |
| ROOT-020 | إسماعيل عليه السلام | اسماعيل |
| ROOT-024 | سلمان | سلامان |
| ROOT-025 | الهميع | الهياسع |
| ROOT-026 | اليبع | اليسع |
| ROOT-027 | أدو | ىدو |
| ROOT-028 | أد | ىد |
| ROOT-033 | إلياس | الياس |
| ROOT-034 | مدركة | معدكة |
| ROOT-036 | كنانة | كنانه |
| ROOT-037 | النضر | المضر |
| ROOT-041 | لؤي | لؤى |
| ROOT-043 | مرة | مره |
| ROOT-045 | عبد مناف قصي | عبدمناف قصي |
| ROOT-048 | أبو طالب | ابو طالب |
| ROOT-049 | علي كرم الله وجهه | علي |
| ROOT-052 | عبد الله المحض | عبدالله المحض |
| ROOT-054 | عبد الله الثاني | عبدالله الثاني |
| ROOT-056 | داوود | داؤود |
| ROOT-059 | عبد الله | عبدالله |
| ROOT-061 | عبد القادر الجيلاني | عبد القدار الجيلاني |
| ROOT-062 | عبد العزيز أبو بكر | عبد العزيز ابو بكر |
| ROOT-063 | علي نور الدين الزاهد | نور الدين الزعبي |
| ROOT-064 | محمد أبو بكر | محمد ابو بكر |
| ROOT-065 | موسى شرف الدين أبو الفتح | موسى شريف الدين ابو الفتح |
| ROOT-067 | أحمد أبو البقاء | احمد ابو البقاء |
| ROOT-069 | صلاح الدين علي | علاء الدين علي |
| ROOT-070 | أبو بكر نور الدين | ابو بكر نور الدين |
| ROOT-071 | سيف الدين يعقوب | سيف الدين يعوق |
| ROOT-073 | عماد الدين علي المتفرقه | عماد الدين علي المقرفص |
| ROOT-077 | ذبيان | ذيبان |

## Ambiguities preserved for review

- Compound entries فهر قريش and عبدمناف قصي are single people, as supplied. No split into additional ancestors was inferred.
- Repeated محمد and عبدالله entries use positional keys; identical-looking names are not evidence of identity.
- Spellings such as ىدو، ىد، معدكة، المضر، عبد القدار الجيلاني، سيف الدين يعوق، عماد الدين علي المقرفص remain literal.
- نور الدين الزعبي and علاء الدين علي differ from the legacy identities at the same positions. Positional proximity alone does not resolve identity.
- The description عم النبي محمد for ابو طالب is preserved literally; its phrasing and the genealogical claims require source review.
- The supplied titles for هاشم، الحسن السبط، نور الدين الزعبي and يوسف are descriptions under review, not verified assertions.
- سليمان and عبد القادر are new final entries; no identity was inferred between them and similarly named people in existing branch records.

## Validation and deployment

Tests seed SQLite in memory. The development MySQL database is not migrated or seeded by this task. Apply the additive migration in the intended environment before using these pages. All new seeder test runs are isolated; the legacy source files remain unchanged.
