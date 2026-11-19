<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Nombres de empresa comparables: sin tildes, en mayúsculas, sin signos y sin
 * la forma jurídica ("Fontanería García, S.L." → "FONTANERIA GARCIA").
 */
class CompanyName
{
    public static function normalize(?string $name): string
    {
        $name = Str::upper(Str::ascii((string) $name));
        $name = (string) preg_replace('/[^A-Z0-9 ]/', ' ', $name);
        $name = (string) preg_replace('/\s+/', ' ', trim($name));
        $name = (string) preg_replace('/\b(S L U|S L L|S L|S A U|S A|S C P|S C|C B|SLU|SLL|SL|SAU|SA|SCP|CB|SOCIEDAD LIMITADA|SOCIEDAD ANONIMA)$/', '', $name);

        return trim($name);
    }

    public static function similar(?string $a, ?string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === '' || $b === '') {
            return $a === $b;
        }

        similar_text($a, $b, $percent);

        return $a === $b || $percent >= 85;
    }
}
