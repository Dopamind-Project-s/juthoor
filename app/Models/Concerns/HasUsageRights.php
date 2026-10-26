<?php

namespace App\Models\Concerns;

/**
 * Copyright and usage permission of heritage material. Public display needs cleared rights; material whose rights
 * are unknown stays within the family; material that may not be shared stays restricted.
 */
trait HasUsageRights
{
    /** @var array<string, string> */
    public const RIGHTS = [
        'unknown' => 'غير محددة',
        'owned' => 'ملك العائلة أو المساهم',
        'permission' => 'بإذن صاحب الحقوق',
        'licensed' => 'برخصة استخدام',
        'public_domain' => 'ملك عام',
        'restricted' => 'غير مسموح بنشره',
    ];

    /** @var array<int, string> */
    public const RIGHTS_CLEARED = ['owned', 'permission', 'licensed', 'public_domain'];

    public function rightsAllowAudience(string $audience): bool
    {
        return match ($audience) {
            'public' => in_array($this->rights_status, self::RIGHTS_CLEARED, true),
            'members' => $this->rights_status !== 'restricted',
            default => true,
        };
    }

    /** @return array<string, array<int, mixed>> */
    protected static function rightsRules(): array
    {
        return [
            'rights_status' => ['required', 'in:'.implode(',', array_keys(self::RIGHTS))],
            'rights_holder' => ['nullable', 'string', 'max:255'],
            'rights_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
