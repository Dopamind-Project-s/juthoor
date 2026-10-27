<?php

use App\Models\Family;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Imports\UnifiedGenealogyDataset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['audit_viewer', 'genealogy_administration', 'permissions_administration'] as $name) {
                DB::table('maintenance_tool_settings')->updateOrInsert(['name' => $name], [
                    'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $family = Family::query()->where('slug', 'al-zoubi')->first();
            $tree = $family?->familyTrees()->where('slug', 'main')->first();
            if (! $tree) {
                return;
            }
            $data = app(UnifiedGenealogyDataset::class)->data();
            $ids = [];
            foreach (array_chunk(array_column($data['people'], 'key'), 300) as $keys) {
                $people = $tree->people()->whereIn('canonical_key', $keys)->get();
                foreach ($people as $person) {
                    $before = app(AuditLogger::class)->snapshot($person);
                    $person->fill([
                        'verification_status' => 'verified',
                        'status' => $person->is_living === false ? Person::STATUS_HISTORICAL : Person::STATUS_ACTIVE,
                    ]);
                    if ($person->isDirty()) {
                        $person->save();
                        app(AuditLogger::class)->record(null, $family, $person, 'seed_approved', $before, app(AuditLogger::class)->snapshot($person));
                    }
                    $ids[] = $person->id;
                }
            }
            $before = ['seed_people' => count($ids), 'reason' => 'Administrator requested approval of the embedded seed.'];
            foreach (array_chunk($ids, 300) as $chunk) {
                $tree->personRelationships()->whereIn('person_id', $chunk)->whereNotNull('canonical_key')
                    ->where('relationship_type', 'father')->update(['verification_status' => 'verified']);
            }
            $sourceIds = $family->sources()->where('reference', 'like', 'unified:%')->pluck('id');
            $family->sources()->whereIn('id', $sourceIds)->update(['verification_status' => 'verified']);
            foreach (array_chunk($sourceIds->all(), 300) as $chunk) {
                DB::table('citations')->where('family_tree_id', $tree->id)->whereIn('source_id', $chunk)
                    ->whereIn('field_key', ['person', 'father'])->update(['verification_status' => 'verified']);
            }
            $slugs = array_column($data['branches'], 'slug');
            $tree->branches()->whereIn('slug', $slugs)->update(['verification_status' => 'verified']);
            $tree->branches()->whereIn('slug', $slugs)->whereNotNull('root_person_id')->update(['is_active' => true]);
            $tree->branches()->whereIn('slug', $slugs)->whereNull('root_person_id')->update(['is_active' => false]);
            $family->communityEntities()->whereIn('slug', array_merge(['al-zoubi'], $slugs))->update([
                'publication_status' => 'published', 'reviewed_at' => now(),
            ]);
            app(AuditLogger::class)->record(null, $family, $tree, 'seed_approved', $before, [
                'verification_status' => 'verified', 'privacy' => 'unchanged',
            ]);
        });
    }

    public function down(): void
    {
        // Do not silently revoke an administrator approval or restore obsolete privacy classifications.
    }
};
