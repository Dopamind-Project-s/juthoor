<?php

namespace App\Models\Concerns;

use App\Services\ArabicNameNormalizer;

/** Search text is indexed once; visibility and hierarchical scope remain query prerequisites. */
trait HasPublicationSearch
{
    public static function bootHasPublicationSearch(): void
    {
        static::saving(function ($record): void {
            $record->searchable_text = $record->publicationSearchText();
        });
    }

    public function publicationSearchText(): string
    {
        $text = collect($this->getAttributes())->only(['title', 'summary', 'body', 'description'])->filter()->implode(' ');

        return mb_strtolower(app(ArabicNameNormalizer::class)->normalize($text));
    }
}
