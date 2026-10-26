<?php

namespace App\Models\Concerns;

use Closure;

/**
 * A public place (diwan, condolence hall, event venue) with keyless Google Maps directions. Coordinates, Place ID and
 * contact details are entered, never derived; contact details are shown only with recorded consent.
 */
trait HasPublicPlace
{
    /** Hosts accepted for a stored Google Maps link. */
    public const MAPS_HOSTS = ['www.google.com', 'google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl'];

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

    /** @return array<string, array<int, mixed>> */
    protected static function placeRules(): array
    {
        return [
            'location_name' => ['nullable', 'string', 'max:255'],
            'public_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'governorate' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'maps_url' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isGoogleMapsUrl((string) $value)) {
                    $fail('رابط الخريطة يجب أن يكون رابط Google Maps يبدأ بـ https.');
                }
            }],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'contact_consent' => ['nullable', 'boolean'],
        ];
    }

    /** Name used in Maps searches. */
    protected function placeName(): string
    {
        return (string) ($this->getAttribute('location_name') ?: $this->getAttribute('name') ?: $this->getAttribute('title'));
    }

    /** Keyless Google Maps URL (Maps URLs API): directions to coordinates or a place, else the stored link, else a search. */
    public function directionsUrl(): ?string
    {
        $placeId = $this->getAttribute('google_place_id');
        if ($this->latitude !== null && $this->longitude !== null) {
            return 'https://www.google.com/maps/dir/?'.http_build_query(array_filter([
                'api' => 1, 'destination' => $this->latitude.','.$this->longitude, 'destination_place_id' => $placeId,
            ]));
        }
        if ($placeId) {
            return 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $this->placeName(), 'query_place_id' => $placeId]);
        }
        if ($this->maps_url && self::isGoogleMapsUrl($this->maps_url)) {
            return $this->maps_url;
        }
        $query = collect([$this->placeName(), $this->public_address, $this->city, $this->governorate])->filter()->implode('، ');

        return $this->public_address || $this->city ? 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $query]) : null;
    }

    /** Maps Embed API iframe source, only when an embed key is configured (the Embed API itself is free but keyed). */
    public function embedUrl(): ?string
    {
        $key = config('services.google_maps.embed_key');
        $placeId = $this->getAttribute('google_place_id');
        $query = $placeId ? 'place_id:'.$placeId : ($this->latitude !== null && $this->longitude !== null ? $this->latitude.','.$this->longitude : null);

        return $key && $query ? 'https://www.google.com/maps/embed/v1/place?'.http_build_query(['key' => $key, 'q' => $query]) : null;
    }

    public function getPlaceLabelAttribute(): string
    {
        return collect([$this->city, $this->governorate])->filter()->implode('، ');
    }

    /** Contact details are public only with recorded consent. */
    public function hasPublicContact(): bool
    {
        return (bool) $this->contact_consent && ($this->contact_name || $this->contact_phone);
    }
}
