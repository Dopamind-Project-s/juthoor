<?php

namespace App\Services\Portal;

use App\Models\CommunityEntity;
use App\Models\Family;
use App\Models\FamilyPortalSetting;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Community\MediaAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Family portal branding as structured, validated values — never HTML, scripts, CSS or free colours. Administrators
 * edit a draft, preview it, and publish it; both steps are audited. Images come from the family's media library and must
 * already be publicly viewable through the media rules.
 */
class PortalSettings
{
    /** @var array<string, string> Sections in their default order. */
    public const SECTIONS = [
        'search' => 'البحث في الشجرة',
        'summary' => 'نبذة عن العائلة',
        'statistics' => 'إحصاءات السجلات المنشورة',
        'hierarchy' => 'الأفخاذ والفروع',
        'genealogy' => 'معاينة النسب',
        'history' => 'التاريخ والأصول',
        'geography' => 'المناطق والانتشار',
        'diwans' => 'الدواوين والمضافات',
        'achievements' => 'شخصيات وإنجازات',
        'archive' => 'الأرشيف',
        'gallery' => 'معرض الصور',
        'news' => 'آخر الأخبار',
        'events' => 'المناسبات القادمة',
        'directory' => 'دليل الأعضاء',
        'contribute' => 'المساهمة والتصحيح',
    ];

    /** @var array<string, string> */
    public const CTA_TARGETS = ['tree' => 'الشجرة الجامعة', 'entities' => 'الأفخاذ والفروع', 'directory' => 'دليل الأعضاء', 'contribute' => 'المساهمة والتصحيح'];

    public function __construct(private readonly AuditLogger $audit, private readonly MediaAccess $media) {}

    /** @return array<string, mixed> Defaults derived from the family record only. */
    public function defaults(Family $family): array
    {
        return [
            'display_name' => $family->name_ar,
            'intro' => $family->short_description,
            'description' => null,
            'hero_title' => $family->name_ar,
            'hero_subtitle' => $family->location,
            'cover_media' => null,
            'logo_media' => null,
            'accent' => 'heritage',
            'cta_label' => 'استكشف الشجرة الجامعة',
            'cta_target' => 'tree',
            'contact_links' => [],
            'public_email' => null,
            'footer_text' => null,
            'root_entity_id' => null,
            'sections' => collect(self::SECTIONS)->keys()->map(fn (string $key) => ['key' => $key, 'visible' => true])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function published(Family $family): array
    {
        return $this->complete($family, $family->portalSetting?->published);
    }

    /** @return array<string, mixed> */
    public function draft(Family $family): array
    {
        $setting = $family->portalSetting;

        return $this->complete($family, $setting?->draft ?? $setting?->published);
    }

    /** @param array<string, mixed> $input */
    public function saveDraft(User $user, Family $family, array $input): FamilyPortalSetting
    {
        Gate::forUser($user)->authorize('update', $family);
        $values = $this->validate($family, $input);

        return DB::transaction(function () use ($user, $family, $values): FamilyPortalSetting {
            $setting = FamilyPortalSetting::query()->lockForUpdate()->firstOrNew(['family_id' => $family->id]);
            $before = $setting->exists ? ['draft' => $setting->draft] : null;
            $setting->fill(['draft' => $values, 'draft_updated_by' => $user->id, 'draft_updated_at' => now()])->save();
            $this->audit->record($user, $family, $setting, 'portal.draft_saved', $before, ['draft' => $values]);

            return $setting;
        });
    }

    public function publish(User $user, Family $family): FamilyPortalSetting
    {
        Gate::forUser($user)->authorize('update', $family);

        return DB::transaction(function () use ($user, $family): FamilyPortalSetting {
            $setting = FamilyPortalSetting::query()->lockForUpdate()->where('family_id', $family->id)->first();
            if (! $setting?->draft) {
                throw ValidationException::withMessages(['portal' => 'لا توجد مسودة لنشرها.']);
            }
            // Re-validated at publication: an image may have been unpublished since the draft was saved.
            $values = $this->validate($family, $setting->draft);
            $before = ['published' => $setting->published];
            $setting->fill(['published' => $values, 'draft' => $values, 'published_by' => $user->id, 'published_at' => now()])->save();
            $this->audit->record($user, $family, $setting, 'portal.published', $before, ['published' => $values]);

            return $setting;
        });
    }

    /** Publicly viewable image of this family by uuid, or null. */
    public function publicImage(Family $family, ?string $uuid): ?MediaItem
    {
        if (! $uuid) {
            return null;
        }
        $media = MediaItem::query()->where('family_id', $family->id)->where('uuid', $uuid)->with('ownerEntity')->first();

        return $media && $media->isImage() && $this->media->canView(null, $media) ? $media : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(Family $family, array $input): array
    {
        $sectionKeys = array_keys(self::SECTIONS);
        $data = Validator::make($input, [
            'display_name' => ['required', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:400'],
            'description' => ['nullable', 'string', 'max:4000'],
            'hero_title' => ['required', 'string', 'max:120'],
            'hero_subtitle' => ['nullable', 'string', 'max:240'],
            'cover_media' => ['nullable', 'uuid'],
            'logo_media' => ['nullable', 'uuid'],
            'accent' => ['required', Rule::in(array_keys(config('portals.accents')))],
            'cta_label' => ['required', 'string', 'max:40'],
            'cta_target' => ['required', Rule::in(array_keys(self::CTA_TARGETS))],
            'contact_links' => ['nullable', 'array', 'max:6'],
            'contact_links.*.label' => ['required', 'string', 'max:60'],
            'contact_links.*.url' => ['required', 'url:https', 'max:500'],
            'public_email' => ['nullable', 'email', 'max:190'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'root_entity_id' => ['nullable', 'integer', Rule::exists('community_entities', 'id')->where('family_id', $family->id)],
            'sections' => ['required', 'array'],
            'sections.*.key' => ['required', 'distinct', Rule::in($sectionKeys)],
            'sections.*.visible' => ['required', 'boolean'],
        ], [
            'contact_links.*.url.url' => 'روابط التواصل تقبل عناوين https فقط.',
            'accent.in' => 'اختر لونًا من الألوان المعتمدة.',
        ])->validate();

        foreach (['cover_media' => 'صورة الغلاف', 'logo_media' => 'الشعار'] as $field => $label) {
            if (filled($data[$field] ?? null) && ! $this->publicImage($family, $data[$field])) {
                throw ValidationException::withMessages([$field => $label.' يجب أن تكون صورة من مكتبة وسائط العائلة منشورة للعامة وحقوقها مسموحة.']);
            }
        }
        // Every section appears exactly once; unknown or missing keys are completed in default order.
        $sections = collect($data['sections'])->map(fn (array $row) => ['key' => $row['key'], 'visible' => (bool) $row['visible']]);
        foreach ($sectionKeys as $key) {
            if (! $sections->contains('key', $key)) {
                $sections->push(['key' => $key, 'visible' => true]);
            }
        }
        $data['sections'] = $sections->values()->all();
        $data['contact_links'] = array_values($data['contact_links'] ?? []);
        $data['root_entity_id'] = isset($data['root_entity_id']) ? (int) $data['root_entity_id'] : null;

        return $data + array_intersect_key($this->defaults($family), array_flip(['cover_media', 'logo_media', 'public_email', 'footer_text', 'intro', 'description', 'hero_subtitle']));
    }

    /** @param array<string, mixed>|null $values @return array<string, mixed> */
    private function complete(Family $family, ?array $values): array
    {
        $values = array_replace($this->defaults($family), $values ?? []);
        $values['sections'] = $this->withNewSections($values['sections']);
        if (! isset(config('portals.accents')[$values['accent']])) {
            $values['accent'] = 'heritage';
        }

        return $values;
    }

    /**
     * Sections added after a family saved its settings appear visible at their default position; saved order and
     * visibility of every existing section are kept.
     *
     * @param  array<int, array{key: string, visible: bool}>  $sections
     * @return array<int, array{key: string, visible: bool}>
     */
    public function withNewSections(array $sections): array
    {
        $keys = array_column($sections, 'key');
        $defaults = array_keys(self::SECTIONS);
        foreach ($defaults as $position => $key) {
            if (in_array($key, $keys, true)) {
                continue;
            }
            $previous = $defaults[$position - 1] ?? null;
            $at = $previous !== null && ($found = array_search($previous, array_column($sections, 'key'), true)) !== false ? $found + 1 : 0;
            array_splice($sections, $at, 0, [['key' => $key, 'visible' => true]]);
        }

        return array_values(array_filter($sections, fn (array $section) => isset(self::SECTIONS[$section['key']])));
    }

    /** Root entity candidates for the portal content. */
    public function entityOptions(Family $family)
    {
        return CommunityEntity::query()->where('family_id', $family->id)->orderBy('parent_id')->orderBy('sort_order')->get(['id', 'name_ar', 'parent_id', 'publication_status']);
    }
}
