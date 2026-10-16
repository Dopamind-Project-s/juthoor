<?php

namespace App\Services\Genealogy;

use App\Http\Requests\Management\StoreBranchRequest;
use App\Http\Requests\Management\StorePersonRequest;
use App\Http\Requests\Management\StoreRelationshipRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonAttribute;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GenealogyMutationService
{
    public function __construct(private AuditLogger $audit, private GenealogyDeletionService $deletion, private RelationshipIntegrityService $relationships, private BranchIntegrityService $branches) {}

    public function modelClass(string $kind): string
    {
        return match ($kind) {
            'person' => Person::class,'relationship' => PersonRelationship::class,'branch' => Branch::class,default => abort(422)
        };
    }

    public function target(FamilyTree $tree, string $kind, int $id): Model
    {
        return $this->modelClass($kind)::query()->where('family_tree_id', $tree->id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function referenceLabels(FamilyTree $tree, array $before, array $after): array
    {
        $result = [];
        foreach (['person_id', 'related_person_id', 'root_person_id'] as $field) {
            $ids = array_filter([$before[$field] ?? null, $after[$field] ?? null]);
            $result[$field] = $tree->people()->whereKey($ids)->get()->pluck('display_name', 'id')->all();
        }
        $result['parent_branch_id'] = $tree->branches()->whereKey(array_filter([$before['parent_branch_id'] ?? null, $after['parent_branch_id'] ?? null]))->pluck('name_ar', 'id')->all();

        $attributeIds = array_unique(array_merge($before['attribute_ids'] ?? [], $after['attribute_ids'] ?? []));
        $result['attribute_ids'] = PersonAttribute::query()->availableFor($tree->family_id)->whereIn('id', $attributeIds)->pluck('label', 'id')->all();

        return $result;
    }

    public function fingerprint(Model $record): string
    {
        $latest = AuditLog::query()->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->max('id');

        return hash('sha256', json_encode([$record->getAttributes(), $latest], JSON_THROW_ON_ERROR));
    }

    public function attributes(FamilyTree $tree, string $kind, array $input, ?Model $record = null): array
    {
        $current = $record ? $this->audit->snapshot($record) : [];
        if ($kind === 'relationship') {
            $current['father_id'] = $current['related_person_id'] ?? null;
        }
        if (isset($input['related_person_id'])) {
            $input['father_id'] = $input['related_person_id'];
        }
        if ($kind === 'person' && ! empty($input['attributes_present'])) {
            $input['attribute_ids'] ??= [];
        }
        $input = array_replace($current, $input);
        $request = match ($kind) {
            'person' => new StorePersonRequest,'branch' => new StoreBranchRequest,'relationship' => new StoreRelationshipRequest
        };
        $data = Validator::make($input, $request->rules(), $request->messages())->validate();
        if ($kind === 'person') {
            unset($data['attributes_present']);
            if (array_key_exists('attribute_ids', $data)) {
                $data['attribute_ids'] = app(PersonAttributeService::class)->validateIds($tree->family_id, $data['attribute_ids'], $record);
            }
            $data['is_living'] = (bool) ($data['is_living'] ?? false);
            $key = $data['canonical_key'] ?? null;
            if ($key && $tree->people()->where('canonical_key', $key)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                $this->reject('المفتاح المرجعي مستخدم داخل هذه الشجرة.');
            }
        } elseif ($kind === 'relationship') {
            foreach ([$data['person_id'], $data['father_id']] as $id) {
                $tree->people()->findOrFail($id);
            }
            $data = ['person_id' => $data['person_id'], 'related_person_id' => $data['father_id'], 'relationship_type' => 'father', 'is_primary' => true, 'verification_status' => $data['verification_status']];
        } else {
            $tree->people()->findOrFail($data['root_person_id']);
            if ($data['parent_branch_id'] ?? null) {
                $tree->branches()->findOrFail($data['parent_branch_id']);
            }
            if ($tree->branches()->where('slug', $data['slug'])->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                $this->reject('الرابط المختصر مستخدم داخل هذه الشجرة.');
            }
            $data += ['branch_type' => 'family_group', 'membership_mode' => 'descendants'];
            $data['is_active'] = (bool) ($data['is_active'] ?? false);
        }

        return $data;
    }

    public function validateModel(Model $model): void
    {
        try {
            if ($model instanceof PersonRelationship) {
                $this->relationships->validate($model);
            }
            if ($model instanceof Branch) {
                $this->branches->validate($model);
            }
        } catch (DomainException $exception) {
            $this->reject('التغيير يخالف سلامة الأنساب: علاقة مكررة أو دورة أو جذر أو فرع غير صالح.');
        }
    }

    public function apply(FamilyTree $tree, User $user, string $kind, string $operation, array $data, ?Model $record = null, bool $validateHierarchy = true): AuditLog
    {
        $before = $record ? $this->audit->snapshot($record) : null;
        $attributeIds = $kind === 'person' && array_key_exists('attribute_ids', $data) ? $data['attribute_ids'] : null;
        unset($data['attribute_ids']);
        if ($operation === 'delete') {
            $this->assertDeletable($record);
            $record->delete();
        } else {
            if ($record instanceof PersonRelationship && ((int) $record->related_person_id !== (int) ($data['related_person_id'] ?? $record->related_person_id) || (int) $record->person_id !== (int) ($data['person_id'] ?? $record->person_id))) {
                if ($record->citations()->exists()) {
                    $this->reject('العلاقة مرتبطة باستشهادات. راجع الاستشهادات وأزل الربط قبل تغيير طرفيها.');
                }
            }
            $record ??= new ($this->modelClass($kind));
            $record->fill($data);
            $record->family_tree_id = $tree->id;
            $this->validateModel($record);
            $record->save();
            if ($record instanceof Person && $attributeIds !== null) {
                $ids = app(PersonAttributeService::class)->validateIds($tree->family_id, $attributeIds, $record);
                $record->personAttributes()->sync($ids);
                $record->unsetRelation('personAttributes');
            }
            $record->refresh();
            // A changed ancestry must still satisfy every existing nested branch.
            if ($validateHierarchy && in_array($kind, ['relationship', 'branch']) && $operation === 'update') {
                $this->validateBranches($tree);
            }
        }

        if ($validateHierarchy && $kind === 'relationship' && $operation === 'delete') {
            $this->validateBranches($tree);
        }

        return $this->audit->record($user, $tree->family, $record, match ($operation) {
            'create' => 'created','update' => 'updated','delete' => 'deleted'
        }, $before, $operation === 'delete' ? null : $this->audit->snapshot($record));
    }

    public function validateBranches(FamilyTree $tree): void
    {
        foreach ($tree->branches()->get() as $branch) {
            $this->validateModel($branch);
        }
    }

    public function assertDeletable(Model $record): void
    {
        match (true) {
            $record instanceof Person => $this->deletion->assertPersonCanBeDeleted($record),$record instanceof PersonRelationship => $this->deletion->assertRelationshipCanBeDeleted($record),$record instanceof Branch => $this->deletion->assertBranchCanBeDeleted($record),default => $this->reject('هذا النوع غير مدعوم للاسترجاع.')
        };

    }

    public function reject(string $message): never
    {
        throw ValidationException::withMessages(['change' => $message]);
    }
}
