# Database-backed public pages

Public family information now comes from Eloquent through FamilyPortalController, FamilyPortalPage, CommunityEntityController and the existing directory/genealogy controllers. Routes no longer require static Al-Zoubi PHP datasets or register pages by iterating dataset branches. Empty database results remain empty; no sample people or counts are substituted. Legacy /al-zoubi URLs redirect to the existing database-backed destinations. The /ui prototype route was removed.

Removed the obsolete static branch view composer and the unused canonical_portal feature switch. The current SVG tree renderer, published-content assembly, family isolation and privacy services were retained. Generic shared styles/scripts and the person-card component were retained because they still have callers; no dependency or source-evidence cleanup was inferred from their age.

## Removed files

- `resources/data/al-zoubi/archive.php`
- `resources/data/al-zoubi/branches.php`
- `resources/data/al-zoubi/family.php`
- `resources/data/al-zoubi/genealogy.php`
- `resources/data/al-zoubi/history.php`
- `resources/data/al-zoubi/people.php`
- `resources/views/components/archive-card.blade.php`
- `resources/views/components/branch-card.blade.php`
- `resources/views/components/empty-state.blade.php`
- `resources/views/components/family-card.blade.php`
- `resources/views/components/member-card.blade.php`
- `resources/views/components/person-profile-card.blade.php`
- `resources/views/components/source-card.blade.php`
- `resources/views/components/stat-card.blade.php`
- `resources/views/components/timeline-item.blade.php`
- `resources/views/components/tree-node.blade.php`
- `resources/views/components/tree-toolbar.blade.php`
- `resources/views/families/al-zoubi/achievements.blade.php`
- `resources/views/families/al-zoubi/archive.blade.php`
- `resources/views/families/al-zoubi/branches/index.blade.php`
- `resources/views/families/al-zoubi/branches/show.blade.php`
- `resources/views/families/al-zoubi/history.blade.php`
- `resources/views/families/al-zoubi/home.blade.php`
- `resources/views/families/al-zoubi/members.blade.php`
- `resources/views/families/al-zoubi/prominent-people.blade.php`
- `resources/views/families/al-zoubi/tree.blade.php`
- `resources/views/layouts/family.blade.php`
- `resources/views/layouts/portal.blade.php`
- `resources/views/pages/branches.blade.php`
- `resources/views/pages/families.blade.php`
- `resources/views/pages/family.blade.php`
- `resources/views/pages/home.blade.php`
- `resources/views/pages/ui.blade.php`
- `resources/views/trees/branch.blade.php`
- `resources/views/trees/master.blade.php`
- `resources/views/trees/person.blade.php`

## Reference review and limits

Reviewed route/controller/provider and Blade references before removing the prototype views and their exclusive components. No remaining application route/view references were found to the deleted templates, /ui or the six removed presentation datasets. Original genealogy sources and import/audit infrastructure were not removed.

No tests were added or run, as requested. Existing FamilyPortalTest still expects the old /al-zoubi/history page to return 200; it predates the intentional change to a portal redirect and requires updating when test changes are authorized. No database changes, vendor changes, commits or deployment were performed.

After uploading, clear the deployed route, configuration and view caches using php artisan route:clear, php artisan config:clear and php artisan view:clear.
