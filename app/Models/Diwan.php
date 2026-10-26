<?php

namespace App\Models;

use App\Models\Concerns\CommunityContent;
use Closure;
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
    use CommunityContent;

    /** @var array<string, string> */
    public const KINDS = ['diwan' => 'ديوان', 'madafa' => 'مضافة', 'hall' => 'قاعة مناسبات'];

    /** Hosts accepted for a stored Google Maps link. */
    public const MAPS_HOSTS = ['www.google.com', 'google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl'];

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
            'governorate' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'public_address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'google_place_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{10,255}$/'],
            'maps_url' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isGoogleMapsUrl((string) $value)) {
                    $fail('رابط الخريطة يجب أن يكون رابط Google Maps يبدأ بـ https.');
                }
            }],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'contact_consent' => ['nullable', 'boolean'],
            'visit_instructions' => ['nullable', 'string', 'max:2000'],
            'is_private_residence' => ['nullable', 'boolean'],
            'residence_consent' => ['nullable', 'boolean'],
        ];
    }

    public static function isGoogleMapsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, self::MAPS_HOSTS, true)) {
            return false;
        }

        return $host === 'maps.app.goo.gl' || $host === 'maps.google.com' || str_starts_with($parts['path'] ?? '', '/maps');
    }

    public function getContentTitleAttribute(): string
    {
        return $this->name;
    }

    /** Keyless Google Maps URL (Maps URLs API): directions to coordinates or a place, else the stored link, else a search. */
    public function directionsUrl(): ?string
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return 'https://www.google.com/maps/dir/?'.http_build_query(array_filter([
                'api' => 1, 'destination' => $this->latitude.','.$this->longitude, 'destination_place_id' => $this->google_place_id,
            ]));
        }
        if ($this->google_place_id) {
            return 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $this->name, 'query_place_id' => $this->google_place_id]);
        }
        if ($this->maps_url && self::isGoogleMapsUrl($this->maps_url)) {
            return $this->maps_url;
        }
        $query = collect([$this->name, $this->public_address, $this->city, $this->governorate])->filter()->implode('، ');

        return $this->public_address || $this->city ? 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $query]) : null;
    }

    /** Maps Embed API iframe source, only when an embed key is configured (the Embed API itself is free but keyed). */
    public function embedUrl(): ?string
    {
        $key = config('services.google_maps.embed_key');
        $query = $this->google_place_id ? 'place_id:'.$this->google_place_id : ($this->latitude !== null && $this->longitude !== null ? $this->latitude.','.$this->longitude : null);

        return $key && $query ? 'https://www.google.com/maps/embed/v1/place?'.http_build_query(['key' => $key, 'q' => $query]) : null;
    }

    public function getPlaceLabelAttribute(): string
    {
        return collect([$this->city, $this->governorate])->filter()->implode('، ');
    }
}
