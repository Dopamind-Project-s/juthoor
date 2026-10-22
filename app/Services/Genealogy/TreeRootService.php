<?php

namespace App\Services\Genealogy;

use App\Models\FamilyTree;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TreeRootService
{
    public static function rules(): array
    {
        return [
            'root_mode' => ['sometimes', 'required', 'in:empty,existing,new'],
            'root_person_id' => ['nullable', 'required_if:root_mode,existing', 'integer'],
            'root_name_ar' => ['nullable', 'required_if:root_mode,new', 'string', 'max:255'],
            'root_verification_status' => ['nullable', 'in:verified,sourced,pending_review,unresolved'],
            'root_is_living' => ['nullable', 'boolean'],
        ];
    }

    public function configure(FamilyTree $tree, User $user, array $data): void
    {
        if (! array_key_exists('root_mode', $data) && ! array_key_exists('root_person_id', $data)) {
            return;
        }
        DB::transaction(function () use ($tree, $user, $data): void {
            $tree = FamilyTree::query()->lockForUpdate()->findOrFail($tree->id);
            Gate::forUser($user)->authorize('update', $tree->family);
            $audit = app(AuditLogger::class);
            $before = $audit->snapshot($tree);
            $mode = $data['root_mode'] ?? 'existing';
            if ($mode === 'new') {
                $name = $data['root_name_ar'];
                $root = app(ConnectedPersonService::class)->save($tree, $user, [
                    'first_name_ar' => explode(' ', $name)[0], 'full_name_ar' => $name,
                    'status' => 'historical', 'verification_status' => $data['root_verification_status'] ?? 'pending_review',
                    'is_living' => isset($data['root_is_living']) ? (bool) $data['root_is_living'] : null,
                ]);
                $tree->root_person_id = $root->id;
            } else {
                $id = $mode === 'empty' ? null : ($data['root_person_id'] ?? null);
                if ($id && ! $tree->people()->whereKey($id)->exists()) {
                    throw ValidationException::withMessages(['root_person_id' => 'اختر شخصًا من هذه الشجرة.']);
                }
                $tree->root_person_id = $id ? (int) $id : null;
            }
            $this->validateRoot($tree);
            $tree->save();
            $audit->record($user, $tree->family, $tree, 'updated', $before, $audit->snapshot($tree));
        });
    }

    public function validateRoot(FamilyTree $tree): void
    {
        try {
            app(FamilyTreeIntegrityService::class)->validate($tree);
        } catch (DomainException) {
            throw ValidationException::withMessages(['root_person_id' => 'اختر جذرًا من هذه الشجرة دون أي علاقة أبوة. غيّر علاقة الأبوة صراحةً قبل تعيينه جذرًا.']);
        }
    }
}
