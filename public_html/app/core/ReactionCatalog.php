<?php

declare(strict_types=1);

namespace Causette\Chat;

use Causette\Localization\Locale;

final class ReactionCatalog
{
    public const IMAGE_DIRECTORY = '/assets/img/reactions/';

    public const CODES_V2 = [
        '1F44D', '1F44E', '2665', '1F923', '1F622', '1F436', '1F4A9',
        '1F389', '1F525', '1F44F', '1F64C', '1F62E', '1F914', '1F602',
        '1F60D', '1F929', '1F60E', '1F64F', '1F680', '1F4AF', '2728',
    ];
    public const GROUPS = [
        'common' => self::CODES_V2,
        'faces' => [
            '1F600', '1F601', '1F605', '1F642', '1F609', '1F970', '1F618',
            '1F917', '1F60F', '1FAE0', '1FAE1', '1F644', '1F610', '1F615',
            '1F612', '1F629', '1F62D', '1F631', '1F621', '1F624', '1F973',
            '1F92F', '1F97A', '1F636',
        ],
        'hands' => [
            '1F44B', '1F44C', '270C', '1F91D', '1F4AA', '1F91E', '1F44A',
            '1F590', '1F918',
        ],
        'hearts' => [
            '2764', '1F494', '1F49B', '1F49A', '1F499', '1F49C', '1F90D',
            '1FA77', '1F495',
        ],
        'extras' => [
            '1F440', '1F3C6', '1F31F', '1F308', '1F382', '2615', '1F431',
            '1F480', '1F9E0', '1F33B', '1F381', '1F388',
        ],
    ];
    public const CODES = [
        ...self::CODES_V2,
        ...self::GROUPS['faces'],
        ...self::GROUPS['hands'],
        ...self::GROUPS['hearts'],
        ...self::GROUPS['extras'],
    ];

    public static function imagePath(string $code): string
    {
        return in_array($code, self::CODES, true) ? self::IMAGE_DIRECTORY . strtolower($code) . '.png' : '';
    }

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
