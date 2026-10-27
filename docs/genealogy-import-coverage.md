# Genealogy import and coverage

2026-10-27. Counts below come from the initialized local SQLite database, not static landing-page arrays. No production or shared database was reset. The original selected SQLite file was zero bytes, APP_ENV was local, and all subsequent reset records were generated during this task.

| Measure | Count |
|---|---:|
| people | 1688 |
| person_relationships | 1687 |
| families | 1 |
| family_trees | 1 |
| branches | 17 |
| sources | 56 |
| citations | 8067 |
| assertions | 40 |
| users | 14 |
| community_entities | 18 |
| source files inventoried (including architecture/UI references) | 408 |
| distinct checksums | 380 |
| distinct evidence files processed (copies counted once) | 83 |
| extracted person label occurrences (not unique identities) | 4956 |
| drawings decoded / visually reconciled descendant components | 49 / 37 |
| candidate drawing nodes (includes graphical labels, overlaps) | 5046 |
| candidate connector relationships | 4660 |
| unique people identified and imported | 1688 |
| main fakhds / subordinate branches / other branches / linked founders | 4 / 12 / 1 / 16 |
| private unresolved review observations | 3620 |
| competing root claims in assertions | 40 |
| imported people / father relationships pending authorized review | 1688 / 1687 |
| workbook potential duplicate groups (not confirmed duplicates) | 268 |
| conflicting observations (40 root variants + 4 branch/trunk accounts; overlapping events) | 44 |
| confirmed database duplicate identities under the same father | 0 |

## Coverage definitions

All 1,505 v4 staging rows received a disposition: 77 root rows are represented by the submitted canonical root proposal, and all 1,428 branch/source rows are retained in the review queue. Disposition coverage is **100%**. Direct positional identity reconciliation for the 1,428 branch rows remains **0%**: they have no fathers or chart coordinates. It would be misleading to divide the imported people count by 1,505 and call the result genealogy coverage. Instead, supported descendants were independently extracted from editable chart connectors and visually checked against raster evidence. Several v4 rows may refer to these same people; that mapping remains review work.

The canonical root has 79 people, rather than the v4 root’s 77. This preserves the exact supplied chain through عبد القادر, including يوسف → ذيبان → سليمان. Existing Rahaymeh سليمان/عبد القادر aliases resolve to ROOT-078/079, without duplicate people. The 34 supplied Qawasmeh identities are retained alongside chart-supported descendants. Recent/chart-only people have unknown living status, pending_review verification and no automatic public publication.

## Per-fakhd database coverage

| Branch | Type | Founder key | People including founder |
|---|---|---|---:|
| بكر (bakr) | fakhd | AZ-BAKR-FOUNDER | 955 |
| عيد (eid) | sub_fakhd | AZ-EID-FOUNDER | 189 |
| خميس (khamis) | sub_fakhd | AZ-KHAMIS-FOUNDER | 171 |
| الرحايمة (al-rahaymeh) | fakhd | AZ-RAHAYMEH-FOUNDER | 254 |
| علي (وحش) (ali-wahsh) | family_group | AZ-ALI-WAHSH-FOUNDER | 40 |
| القواسمة (al-qawasmeh) | fakhd | QW-0003 | 356 |
| أعمر (qawasmeh-amar) | sub_fakhd | QW-0005 | 199 |
| قاسم (qawasmeh-qasim) | sub_fakhd | QW-0006 | 62 |
| حمدان (qawasmeh-hamdan) | sub_fakhd | QW-0007 | 33 |
| سمرين (qawasmeh-samrin) | sub_fakhd | QW-0008 | 60 |
| العوتلات (al-awtalat) | fakhd | unresolved | 0 |
| جمعة (bakr-jumah) | sub_fakhd | AZ-DESC-B0A0BE0112919136FA9C | 66 |
| حمدان (bakr-hamdan) | sub_fakhd | AZ-DESC-492F6D9A3528104765CD | 87 |
| ثلجي (bakr-thalji) | sub_fakhd | AZ-DESC-61D097EE7A231C7BA178 | 167 |
| محمود (bakr-mahmoud) | sub_fakhd | AZ-DESC-E317631822D4A50FB64D | 172 |
| حمد الله (bakr-hamdallah) | sub_fakhd | AZ-DESC-D82E2A8317F30882EB91 | 69 |
| أحمد (bakr-ahmad) | sub_fakhd | AZ-DESC-CA0E50F40444A73A0807 | 33 |

Nested branch counts overlap and must not be added. Awtalat is represented by a draft entity/branch; its conflicting intermediary ancestry prevents safe founder linkage. No unrelated family was attached to Al-Zoubi. Faqara and Abdul-Latif remain separately classified source observations until their ancestry and family identity are established.

## Exclusions and conflicts

Each excluded source node has its original name, drawing offset and position or original v4 record in `review-queue.json`. Reasons include a blank label, ambiguous connector, disconnected descendant component, repeated same-name sibling, unreviewed editable drawing, competing ancestor account, or missing father identifier. No record was discarded because an old importer selected only READY_ROOT.

Competing accounts retained explicitly: Bakr charts name حمدان after Yusuf where the approved path uses ذيبان; Qawasmeh charts include عبد القادر/عقيل and blank intermediaries instead of the submitted زين العابدين path; Awtalat charts disagree on عقيل/نعسان/لحسان order. Root spelling and identity differences remain proposed conflicting assertions. Honorary titles and imported source claims do not confer historical verification.

## Validation

See `genealogy-seeder-reference.md` for commands and the final validation results. Browser screenshots and machine-readable journeys are under `docs/screenshots/genealogy-rebuild/`. Unresolved sources remain private to authorized reviewers via the existing source/citation pages; known-person competing claims also appear in assertions.

## Source contributions

| Primary source | People first contributed | People referenced (overlap allowed) |
|---|---:|---:|
| resources/data/al-zoubi/branch-founder-resolution.php | 6 | 6 |
| resources/data/qq.ddd | 70 | 70 |
| resources/data/بكر احمد.pdf | 0 | 32 |
| resources/data/بكر جمعة1.png | 0 | 13 |
| resources/data/بكر جمعة2.png | 0 | 65 |
| resources/data/بكر حمدالله.png | 0 | 68 |
| resources/data/بكر خميس1.png | 0 | 70 |
| resources/data/بكر خميس2.png | 0 | 66 |
| resources/data/بكر خميس3.png | 0 | 54 |
| resources/data/بكر خميس4.png | 0 | 54 |
| resources/data/بكر عيد1.png | 6 | 56 |
| resources/data/بكر عيد2.png | 0 | 64 |
| resources/data/بكر عيد3.png | 0 | 85 |
| resources/data/بكر عيد4.png | 0 | 42 |
| resources/data/شجرة عيلة الزعبي/qq.ddd | 0 | 70 |
| resources/data/شجرة عيلة الزعبي/بكر - أحمد - 3.ddd | 32 | 32 |
| resources/data/شجرة عيلة الزعبي/بكر - ثلجي - 1.ddd | 53 | 53 |
| resources/data/شجرة عيلة الزعبي/بكر - ثلجي - 2.ddd | 49 | 51 |
| resources/data/شجرة عيلة الزعبي/بكر - ثلجي - 3.ddd | 1 | 54 |
| resources/data/شجرة عيلة الزعبي/بكر - ثلجي - 4.ddd | 28 | 60 |
| resources/data/شجرة عيلة الزعبي/بكر - ثلجي - 5.ddd | 35 | 67 |
| resources/data/شجرة عيلة الزعبي/بكر - جمعه - 1.ddd | 13 | 13 |
| resources/data/شجرة عيلة الزعبي/بكر - جمعه - 2.ddd | 52 | 65 |
| resources/data/شجرة عيلة الزعبي/بكر - حمد الله -1.ddd | 68 | 68 |
| resources/data/شجرة عيلة الزعبي/بكر - حمدان - 1.ddd | 42 | 42 |
| resources/data/شجرة عيلة الزعبي/بكر - حمدان - 4.ddd | 44 | 54 |
| resources/data/شجرة عيلة الزعبي/بكر - خميس - 1.ddd | 70 | 70 |
| resources/data/شجرة عيلة الزعبي/بكر - خميس - 2.ddd | 59 | 66 |
| resources/data/شجرة عيلة الزعبي/بكر - خميس - 3.ddd | 40 | 54 |
| resources/data/شجرة عيلة الزعبي/بكر - خميس - 4.ddd | 1 | 54 |
| resources/data/شجرة عيلة الزعبي/بكر - عيد - 1.ddd | 50 | 50 |
| resources/data/شجرة عيلة الزعبي/بكر - عيد - 2.ddd | 54 | 64 |
| resources/data/شجرة عيلة الزعبي/بكر - عيد - 3.ddd | 68 | 85 |
| resources/data/شجرة عيلة الزعبي/بكر - عيد - 4.ddd | 16 | 42 |
| resources/data/شجرة عيلة الزعبي/بكر - محمود - 1.ddd | 65 | 65 |
| resources/data/شجرة عيلة الزعبي/بكر - محمود - 2.ddd | 77 | 85 |
| resources/data/شجرة عيلة الزعبي/بكر - محمود - 3.ddd | 1 | 34 |
| resources/data/شجرة عيلة الزعبي/بكر - محمود - 4.ddd | 28 | 51 |
| resources/data/شجرة عيلة الزعبي/عبد الرحمن - 1.ddd | 89 | 89 |
| resources/data/شجرة عيلة الزعبي/عبد الرحمن - 2.ddd | 78 | 81 |
| resources/data/شجرة عيلة الزعبي/عبد الرحمن - 3 ).ddd | 56 | 61 |
| resources/data/شجرة عيلة الزعبي/عبد الرحمن - 4).ddd | 30 | 43 |
| resources/data/شجرة عيلة الزعبي/علي (وحش) - 1.ddd | 40 | 40 |
| resources/data/شجرة عيلة الزعبي/قاسم - 1.ddd | 58 | 72 |
| resources/data/شجرة عيلة الزعبي/قاسم - 2.ddd | 55 | 59 |
| resources/data/شجرة عيلة الزعبي/قاسم - 3.ddd | 33 | 50 |
| resources/data/شجرة عيلة الزعبي/قاسم - 4.ddd | 29 | 43 |
| resources/data/شجرة عيلة الزعبي/قاسم - 5.ddd | 6 | 45 |
| resources/data/شجرة عيلة الزعبي/قاسم - 6.ddd | 39 | 46 |
| resources/data/شجرة عيلة الزعبي/قاسم - 7.ddd | 34 | 51 |
| resources/data/عبدالرجمن1 رحايمه.png | 0 | 89 |
| resources/data/عبدالرجمن2 رحايمه.png | 0 | 81 |
| resources/data/عبدالرجمن3 رحايمه.png | 0 | 61 |
| resources/data/عبدالرجمن4 رحايمه.png | 0 | 43 |
| resources/data/قواسمه1.png | 0 | 72 |
| resources/data/قواسمه2.png | 0 | 50 |
| resources/data/قواسمه3.png | 0 | 59 |
| resources/data/قواسمه4.png | 0 | 43 |
| resources/data/قواسمه5.png | 0 | 45 |
| resources/data/قواسمه6.png | 0 | 46 |
| resources/data/قواسمه7.png | 0 | 51 |
| user:2026-10-27 | 113 | 113 |
