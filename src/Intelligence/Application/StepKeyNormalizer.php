<?php

namespace App\Intelligence\Application;

final class StepKeyNormalizer
{
    public static function normalize(string $stepKey): string
    {
        $normalized = trim($stepKey);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = strtr($normalized, [
            'Ä' => 'Ae',
            'Ö' => 'Oe',
            'Ü' => 'Ue',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);

        return strtolower($normalized);
    }
}
