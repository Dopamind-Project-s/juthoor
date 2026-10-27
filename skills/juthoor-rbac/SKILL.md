---
name: juthoor-rbac
description: "Change Juthoor role catalogs, family/entity assignments, review authorization or private source access."
---

# juthoor-rbac

Inspect app/Services/Access/PermissionCatalog.php, RoleCatalogSynchronizer.php, FamilyPermissions.php and the affected policy before changing permissions. Functional roles attach through FamilyMembership/FamilyRoleAssignment; entity managers cover their entity/descendants rather than ancestors, siblings or another family.

Preserve custom roles and existing assignments when synchronizing the catalog. Use its established dry-run/upgrade mechanism rather than replacing grants. Keep editor/reviewer separation and all server-side gates. PersonAttribute and honorary titles have no authorization effect.

Raw unified sources use the existing family review permission in Management/SourceController; hiding links alone is insufficient. Demo accounts belong only in SiteUsersSeeder with environment/host guards and must not restore revoked grants.

Run RolePermissionTest, AdministrationSecurityTest, UnifiedSourcePrivacyTest and affected family/entity policy tests. For broader changes include SourceManagementTest, CommunityMediaTest and family isolation tests.
