<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use App\Models\Concerns\HasMediaAttachments;
use App\Models\Concerns\HasPublicPlace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * A diwan, madafa or hall: an independent record that may serve several entities (a shared diwan is one record linked
 * to each fakhd). Coordinates, Place ID and contact details are entered, never derived; a private residence is
 * published only with recorded consent, and contact details are shown only with consent.
 */
#[Fillable(['owner_entity_id', 'publication_status', 'verification_status', 'audience', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by', 'sort_order', 'name', 'kind', 'description', 'governorate', 'city', 'public_address', 'latitude', 'longitude', 'google_place_id', 'maps_url', 'contact_name', 'contact_phone', 'contact_consent', 'visit_instructions', 'is_private_residence', 'residence_consent'])]
class Diwan extends Model
{
    use CommunityContent, HasMediaAttachments, HasPublicPlace;

    /** @var array<string, string> */
    public const KINDS = ['diwan' => 'ديوان', 'madafa' => 'مضافة', 'hall' => 'قاعة مناسبات'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'contact_consent' => 'boolean', 'is_private_residence' => 'boolean', 'residence_consent' => 'boolean'];
    }

    /** @return array<string, array<int, mixed>> */
    public static function contentRules(Family $family): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'description' => ['nullable', 'string', 'max:5000'],
            // Diwans are named places themselves, so the shared place rules apply without a separate location name.
            ...collect(self::placeRules())->except('location_name')->all(),
            'google_place_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{10,255}$/'],
            'visit_instructions' => ['nullable', 'string', 'max:2000'],
            'is_private_residence' => ['nullable', 'boolean'],
            'residence_consent' => ['nullable', 'boolean'],
        ];
    }

    public function getContentTitleAttribute(): string
    {
        return $this->name;
    }
}
