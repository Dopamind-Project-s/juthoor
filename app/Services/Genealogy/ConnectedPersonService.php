<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\ArabicNameNormalizer;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConnectedPersonService
{
    public function __construct(private GenealogyMutationService $mutations, private AuditLogger $audit) {}

    public function save(FamilyTree $tree, User $user, array $input, ?Person $person = null): Person
    {
        return DB::transaction(function () use ($tree, $user, $input, $person): Person {
            $tree = FamilyTree::query()->lockForUpdate()->findOrFail($tree->id);
            if ($person) {
                $person = $tree->people()->lockForUpdate()->findOrFail($person->id);
                Gate::forUser($user)->authorize('update', $person);
            } else {
                Gate::forUser($user)->authorize('create', [Person::class, $tree->family]);
            }

            $input['is_living'] = (bool) ($input['is_living'] ?? false);
            if (! $person && empty($input['confirm_distinct'])) {
                $this->assertNotLikelyDuplicate($tree, (string) ($input['full_name_ar'] ?? ''), empty($input['father_id']) ? null : (int) $input['father_id']);
            }
            $attributes = $this->mutations->attributes($tree, 'person', Arr::only($input, [
                'first_name_ar', 'full_name_ar', 'canonical_key', 'gender', 'birth_year', 'title', 'honorific', 'attribute_ids', 'attributes_present',
                'death_year', 'is_living', 'status', 'verification_status',
            ]), $person);
            $log = $this->mutations->apply($tree, $user, 'person', $person ? 'update' : 'create', $attributes, $person);
            $person = $tree->people()->findOrFail($log->auditable_id);

            if (array_key_exists('father_id', $input)) {
                $fatherId = empty($input['father_id']) ? null : (int) $input['father_id'];
                if ($fatherId !== null) {
                    $tree->people()->findOrFail($fatherId);
                }
                $edges = $person->outgoingRelationships()->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true)->get();
                if ($edges->count() > 1) {
                    throw ValidationException::withMessages(['father_id' => 'توجد أكثر من علاقة أبوة أساسية؛ راجع العلاقات قبل التعديل.']);
                }
                $edge = $edges->first();
                if ($fatherId === null && $edge) {
                    $this->mutations->apply($tree, $user, 'relationship', 'delete', [], $edge, false);
                } elseif ($fatherId !== null) {
                    $edgeData = $this->mutations->attributes($tree, 'relationship', [
                        'person_id' => $person->id, 'father_id' => $fatherId,
                        'verification_status' => $input['father_verification_status'] ?? $edge?->verification_status ?? 'pending_review',
                    ], $edge);
                    if (! $edge || $edge->related_person_id !== $fatherId || $edge->verification_status !== $edgeData['verification_status']) {
                        $this->mutations->apply($tree, $user, 'relationship', $edge ? 'update' : 'create', $edgeData, $edge, false);
                    }
                }
            }

            if (! empty($input['make_root'])) {
                Gate::forUser($user)->authorize('update', $tree->family);
                if ($tree->root_person_id && $tree->root_person_id !== $person->id) {
                    throw ValidationException::withMessages(['make_root' => 'للشجرة جذر محدد. استخدم إعدادات الجذر لتغييره صراحةً.']);
                }
                $before = $this->audit->snapshot($tree);
                $tree->root_person_id = $person->id;
                app(TreeRootService::class)->validateRoot($tree);
                $tree->save();
                $this->audit->record($user, $tree->family, $tree, 'updated', $before, $this->audit->snapshot($tree));
            }

            if (! empty($input['branch_enabled'])) {
                $branch = ! empty($input['founded_branch_id'])
                    ? $person->foundedBranches()->where('family_tree_id', $tree->id)->findOrFail($input['founded_branch_id'])
                    : $person->foundedBranches()->where('family_tree_id', $tree->id)->where('slug', $input['branch_slug'])->first();
                $branchData = $this->mutations->attributes($tree, 'branch', [
                    'name_ar' => $input['branch_name_ar'], 'slug' => $input['branch_slug'],
                    'root_person_id' => $person->id, 'parent_branch_id' => $input['branch_parent_id'] ?? null,
                    'description' => $input['branch_description'] ?? null,
                    'is_active' => (bool) ($input['branch_is_active'] ?? false),
                    'verification_status' => $input['branch_verification_status'],
                    ...(empty($input['branch_type']) ? [] : ['branch_type' => $input['branch_type']]),
                ], $branch);
                $this->mutations->apply($tree, $user, 'branch', $branch ? 'update' : 'create', $branchData, $branch, false);
            }

            // Validate the final graph, including branches whose parent founder moved.
            $this->mutations->validateBranches($tree);

            return $person->fresh();
        });
    }

    /**
     * UC-03: a new person whose normalized name matches a sibling under the same father (or another
     * fatherless record) needs explicit confirmation. Name similarity alone never merges records.
     */
    private function assertNotLikelyDuplicate(FamilyTree $tree, string $fullName, ?int $fatherId): void
    {
        $matches = $tree->people()
            ->where('name_search', app(ArabicNameNormalizer::class)->normalize($fullName))
            ->when($fatherId, fn ($query) => $query->whereHas('outgoingRelationships', fn ($edge) => $edge->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true)->where('related_person_id', $fatherId)),
                fn ($query) => $query->whereDoesntHave('outgoingRelationships', fn ($edge) => $edge->where('relationship_type', PersonRelationship::TYPE_FATHER)->where('is_primary', true)))
            ->limit(5)->get(['id', 'full_name_ar', 'canonical_key']);

        if ($matches->isNotEmpty()) {
            throw ValidationException::withMessages(['confirm_distinct' => 'يوجد في هذه الشجرة شخص بالاسم نفسه '.($fatherId ? 'وللأب نفسه' : 'ودون أب مسجل').': '
                .$matches->map(fn (Person $match) => $match->full_name_ar.' (#'.$match->id.($match->canonical_key ? ' · '.$match->canonical_key : '').')')->implode('، ')
                .'. افتح السجل القائم بدل إنشاء نسخة، أو أكّد أنه شخص مختلف.']);
        }
    }
}
