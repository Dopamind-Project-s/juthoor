<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

class StoreRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'person_id' => ['required', 'integer'],
            'father_id' => ['required', 'integer', 'different:person_id'],
            'verification_status' => ['required', 'in:verified,sourced,pending_review,unresolved'],
        ];
    }

    public function messages(): array
    {
        return ['father_id.different' => 'لا يمكن أن يكون الشخص أبًا لنفسه.'];
    }
}
