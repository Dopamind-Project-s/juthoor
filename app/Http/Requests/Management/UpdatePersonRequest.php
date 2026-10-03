<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'first_name_ar' => ['required', 'string', 'max:255'],
            'full_name_ar' => ['required', 'string', 'max:255'],
            'canonical_key' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'in:male,female'],
            'birth_year' => ['nullable', 'integer', 'between:1,9999'],
            'death_year' => ['nullable', 'integer', 'between:1,9999', 'gte:birth_year'],
            'is_living' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,historical,unresolved'],
            'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved'],
        ];
    }

    public function messages(): array
    {
        return ['death_year.gte' => 'يجب ألا تسبق سنة الوفاة سنة الميلاد.'];
    }
}
