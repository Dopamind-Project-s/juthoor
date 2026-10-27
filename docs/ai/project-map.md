# Juthoor project map

Read this once, then open only the area needed for the task.

| Area | Entry points and responsibilities |
|---|---|
| HTTP | [routes/web.php](../../routes/web.php), [controllers](../../app/Http/Controllers), [bootstrap/app.php](../../bootstrap/app.php); platform, family, management and site-admin routes |
| Genealogy | [models](../../app/Models): Family, FamilyTree, Person, PersonRelationship, Branch, Assertion, Citation, Source; [genealogy services](../../app/Services/Genealogy) protect graph integrity, traversal, visibility, merge/review and shared ancestry |
| Privacy/review | PersonPrivacy, RelationshipIntegrityService, FamilyTreeIntegrityService, ChangeReviewService, AuditLogger and recovery services; raw unified source access is reviewer-only |
| RBAC | [PermissionCatalog](../../app/Services/Access/PermissionCatalog.php), RoleCatalogSynchronizer, FamilyPermissions, FamilyPolicy, FamilyMembership, FamilyRoleAssignment, CommunityEntityManager; platform/family/entity scope and editor/reviewer separation |
| Community | [community services](../../app/Services/Community), CommunityEntity and content models; hierarchy, source/media access, directory, news and publication |
| Portals/domains | [portal services](../../app/Services/Portal), Portal controllers and FamilyHosts; host routing is opt-in and must not activate production domains during development |
| Rendering | [Blade views](../../resources/views), [tree layout](../../public/js/tree-layout.js), [tree renderer](../../public/js/genealogy-tree.js), public/css; public/vendor contains intentionally retained third-party assets |
| Bootstrap/data | [DatabaseSeeder](../../database/seeders/DatabaseSeeder.php), four catalog/family/account seeders, [canonical package](../../resources/data/genealogy/al-zoubi), [genealogy extraction tools](../../tools/genealogy); original resources/data evidence stays private |
| Imports/audit | [import services](../../app/Services/Imports), [Artisan commands](../../app/Console/Commands); historical import compatibility is audited separately in repository-cleanup-report.md |
| Tests | [Pest feature/unit tests](../../tests), [Node layout tests](../../tests/js), [browser scripts](../../tests/Browser), [.github workflow](../../.github/workflows/tests.yml); see [testing guide](testing-guide.md) |
| Authority | [v2 requirements](../Juthoor_Master_Project_Requirements_v2.0.md), [v3 UX](../Juthoor_Experience_v3_Requirements_and_Roadmap.md), [active roadmap](../implementation-roadmap.md), [progress log](../development-progress.md) |

## Skills and tool compatibility

Codex CLI observed here: 0.164.0-alpha.11.1. Claude Code CLI is not installed in this shell, so its installed discovery cannot be smoke-tested here. Native Claude configuration is preserved. CLAUDE.md imports AGENTS.md with the supported @file mechanism.

Existing Claude skills remain canonical in .claude/skills. Project-specific skills live in skills/juthoor-* with Claude discovery links. Codex discovery links in the protected .agents/skills directory are pending: the automatic approval review timed out, so native Codex skill discovery has not been established. Codex can read the canonical skill files explicitly through this index. This avoids duplicating skill bodies and avoids relying on a symlink for the entire discovery directory. If the checkout cannot preserve symlinks, use a small SKILL.md wrapper pointing to the same canonical skill instead; do not copy full bodies.

- juthoor-genealogy: changing graph imports, identities, source reconciliation or seed packages.
- juthoor-rbac: changing permissions, assignments or raw-source visibility.
- juthoor-testing: preparing disposable databases and regression/browser runs.
- Existing Laravel/testing/deployment/infer-conventions skills remain available, including their Claude-specific invocation restrictions.

Official compatibility references: [Codex skills](https://learn.chatgpt.com/docs/build-skills), [Codex instructions](https://learn.chatgpt.com/docs/agent-configuration/agents-md), [Claude instructions/imports](https://code.claude.com/docs/en/memory), [Claude skills](https://code.claude.com/docs/en/skills). Documentation establishes supported conventions; it does not prove every tool is installed or that conversation memory is shared.
