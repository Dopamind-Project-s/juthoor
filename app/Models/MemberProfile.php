<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A voluntary directory profile («دليل أبناء العشيرة»): owned by a User, never by a genealogy Person, and never created
 * from genealogy data. Hidden until the user lists it; each field has its own level (hidden / members / public), capped
 * by the profile's audience. Contact details are hidden by default.
 */
#[Fillable(['display_name', 'entity_id', 'profession', 'specialization', 'sector', 'skills', 'experience', 'organization', 'business_name', 'business_category', 'services', 'governorate', 'city', 'links', 'collaboration', 'contact_email', 'contact_phone', 'audience', 'field_visibility'])]
class MemberProfile extends Model
{
    /** Fields with their own visibility, in display order, with their default level. */
    public const FIELDS = [
        'affiliation' => ['الفخذ / الكيان', 'members'],
        'profession' => ['المهنة والتخصص', 'members'],
        'sector' => ['القطاع', 'members'],
        'skills' => ['المهارات', 'members'],
        'experience' => ['الخبرات', 'members'],
        'organization' => ['جهة العمل', 'hidden'],
        'business' => ['العمل التجاري والخدمات', 'members'],
        'city' => ['المدينة', 'members'],
        'governorate' => ['المحافظة', 'members'],
        'links' => ['الروابط المهنية', 'members'],
        'collaboration' => ['مجالات التعاون', 'members'],
        'photo' => ['الصورة', 'members'],
        'contact_email' => ['البريد الإلكتروني', 'hidden'],
        'contact_phone' => ['الهاتف', 'hidden'],
        'messages' => ['استقبال رسائل التواصل عبر المنصة', 'members'],
    ];

    /** @var array<string, string> */
    public const LEVELS = ['hidden' => 'مخفي', 'members' => 'أعضاء العائلة', 'public' => 'العامة'];

    /** @var array<string, string> */
    public const SECTORS = [
        'education' => 'التعليم', 'health' => 'الصحة', 'engineering' => 'الهندسة', 'technology' => 'التقنية', 'law' => 'القانون',
        'business' => 'الأعمال والتجارة', 'public' => 'القطاع العام', 'military' => 'العسكري والأمني', 'agriculture' => 'الزراعة',
        'crafts' => 'الحرف والمهن', 'arts' => 'الفنون والإعلام', 'other' => 'أخرى',
    ];

    /** @var array<string, string> */
    public const BUSINESS_CATEGORIES = [
        'retail' => 'تجارة وبيع', 'services' => 'خدمات', 'construction' => 'إنشاءات وصيانة', 'food' => 'أغذية ومطاعم', 'transport' => 'نقل',
        'consulting' => 'استشارات', 'education' => 'تدريب وتعليم', 'health' => 'خدمات صحية', 'technology' => 'تقنية', 'other' => 'أخرى',
    ];

    /** @var array<string, string> */
    public const AFFILIATION_STATUSES = ['unverified' => 'غير محدد', 'pending' => 'بانتظار التحقق', 'verified' => 'موثق', 'rejected' => 'لم يُعتمد'];

    protected function casts(): array
    {
        // Unicode JSON keeps Arabic skills readable by the skill filter.
        return ['search_fields_public' => 'array', 'search_fields_members' => 'array', 'skills' => 'json:unicode', 'links' => 'json:unicode', 'field_visibility' => 'array', 'is_listed' => 'boolean', 'listed_at' => 'datetime',
            'suspended_at' => 'datetime', 'affiliation_reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CommunityEntity::class, 'entity_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(DirectoryReport::class);
    }

    /** The level a field actually reaches: its own setting, never wider than the profile's audience. */
    public function fieldLevel(string $field): string
    {
        $level = ($this->field_visibility ?? [])[$field] ?? self::FIELDS[$field][1];
        if ($level === 'public' && $this->audience !== 'public') {
            return 'members';
        }

        return array_key_exists($level, self::LEVELS) ? $level : 'hidden';
    }

    /**
     * Fields readable at an audience level ('public', 'members' or 'owner').
     *
     * @return array<int, string>
     */
    public function fieldsFor(string $level): array
    {
        return array_values(array_filter(array_keys(self::FIELDS), fn (string $field) => match ($level) {
            'owner' => true,
            'members' => in_array($this->fieldLevel($field), ['members', 'public'], true),
            default => $this->audience === 'public' && $this->fieldLevel($field) === 'public',
        }));
    }

    public function isVisibleInDirectory(): bool
    {
        return $this->is_listed && $this->suspended_at === null;
    }
}
