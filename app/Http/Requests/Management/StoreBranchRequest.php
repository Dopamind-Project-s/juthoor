<?php

namespace App\Http\Requests\Management;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255'],
            'root_person_id' => ['required', 'integer'],
            'parent_branch_id' => ['nullable', 'integer'],
            'branch_type' => ['sometimes', 'nullable', Rule::in(array_keys(Branch::TYPES))],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved'],
        ];
    }
}
