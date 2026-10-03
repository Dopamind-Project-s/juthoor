<?php

namespace App\Services;

class ArabicNameNormalizer
{
    public function normalize(string $value): string
    {
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = str_replace(['ـ', 'أ', 'إ', 'آ', 'ٱ'], ['', 'ا', 'ا', 'ا', 'ا'], $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
