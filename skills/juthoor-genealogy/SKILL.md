---
name: juthoor-genealogy
description: "Modify genealogy graphs, canonical seed packages, source reconciliation or branch founders in Juthoor."
---

# juthoor-genealogy

Start with docs/ai/project-map.md and the relevant import/graph service. For seeding, inspect database/seeders/AlZoubiFamilyTreeSeeder.php and resources/data/genealogy/al-zoubi/normalized.json; source extraction tooling is tools/genealogy.

Keep canonical keys independent of database IDs; distinguish same-name people by paternal context and evidence. A branch is a view into one tree. Reject missing parents/cycles and record disputed accounts instead of changing approved ancestry. Preserve original evidence and its checksums/locators. Unknown living status and imported assertions stay subject to PersonPrivacy and review gates.

Use model-backed writes inside the existing family transaction/lock. Do not reintroduce thousands of nested createOrFirst savepoints into bulk imports; PostgreSQL default lock limits are part of compatibility. Preserve administrator edits and revoked attributes/roles on reruns.

Validate with UnifiedGenealogySeederTest, UnifiedSourcePrivacyTest, relevant graph/privacy tests and genealogy:seed-audit --json --dry-run. Use the testing guide for disposable targets. Read genealogy coverage/source reports only when reconciling evidence.
