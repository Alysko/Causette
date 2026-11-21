<?php

declare(strict_types=1);

namespace Causette\Chat;

use Causette\Localization\Locale;

final class ReactionCatalog
{
    public const CODES_V2 = [
        '1F44D', '1F44E', '2665', '1F923', '1F622', '1F436', '1F4A9',
        '1F389', '1F525', '1F44F', '1F64C', '1F62E', '1F914', '1F602',
        '1F60D', '1F929', '1F60E', '1F64F', '1F680', '1F4AF', '2728',
    ];
    public const CODES = self::CODES_V2;

    public static function normalize(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }
        $code = preg_replace('/[^0-9A-F]/', '', strtoupper((string)$value));
        return in_array($code, self::CODES, true) ? $code : '';
    }

    public static function labels(Locale $locale): array
    {
        $labels = [];
        foreach (self::CODES as $code) {
            $labels[$code] = self::label($code, $locale);
        }
        return $labels;
    }

    public static function label(string $code, Locale $locale): string
    {
        return $locale->text('reactions.' . strtolower($code));
    }
}
