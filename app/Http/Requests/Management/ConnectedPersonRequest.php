<?php

namespace App\Http\Requests\Management;

class ConnectedPersonRequest extends StorePersonRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'father_id' => ['sometimes', 'nullable', 'integer'],
            'father_verification_status' => ['nullable', 'in:verified,sourced,pending_review,unresolved'],
            'make_root' => ['nullable', 'boolean'],
            'branch_enabled' => ['nullable', 'boolean'],
            'founded_branch_id' => ['exclude_unless:branch_enabled,1', 'nullable', 'integer'],
            'branch_name_ar' => ['exclude_unless:branch_enabled,1', 'required', 'string', 'max:255'],
            'branch_slug' => ['exclude_unless:branch_enabled,1', 'required', 'alpha_dash', 'max:255'],
            'branch_parent_id' => ['exclude_unless:branch_enabled,1', 'nullable', 'integer'],
            'branch_description' => ['exclude_unless:branch_enabled,1', 'nullable', 'string', 'max:2000'],
            'branch_is_active' => ['exclude_unless:branch_enabled,1', 'nullable', 'boolean'],
            'branch_verification_status' => ['exclude_unless:branch_enabled,1', 'required', 'in:verified,sourced,pending_review,unresolved'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'branch_name_ar.required' => 'أدخل اسم الفرع الذي يؤسسه هذا الشخص.',
            'branch_slug.required' => 'أدخل الرابط المختصر للفرع.',
            'branch_slug.alpha_dash' => 'رابط الفرع يقبل الحروف والأرقام والشرطة فقط.',
            'father_id.integer' => 'اختر الأب من نتائج البحث داخل هذه الشجرة.',
            'branch_verification_status.required' => 'اختر حالة توثيق الفرع.',
        ];
    }
}
