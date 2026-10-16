<?php

namespace App\Services\Genealogy;

use App\Models\Person;
use App\Models\PersonAttribute;
use Illuminate\Validation\ValidationException;

class PersonAttributeService
{
    public function validateIds(int $familyId, array $ids, ?Person $person = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $existing = $person?->personAttributes()->pluck('person_attributes.id')->all() ?? [];
        $allowed = PersonAttribute::query()->availableFor($familyId)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $existing))
            ->whereIn('id', $ids)->pluck('id')->all();
        if (count($allowed) !== count($ids)) {
            throw ValidationException::withMessages(['attribute_ids' => 'اختر صفات نشطة مشتركة أو تابعة لهذه العائلة فقط.']);
        }

        return $ids;
    }
}
