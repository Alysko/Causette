<?php

declare(strict_types=1);

namespace Causette\Calls;

use Setting;

final class CallVideoPreferences
{
    private const DEFAULTS = ['camera' => 'auto', 'screen' => 'ask'];
    private const PROFILES = [
        'eco' => 'ui.video_quality_eco',
        '1080p-30fps' => 'ui.1080p_30_fps',
        'native-60fps' => 'ui.native_resolution_60_fps',
    ];

    public static function labels(string $media): array
    {
        return match ($media) {
            'camera' => ['auto' => 'ui.video_quality_automatic'] + self::PROFILES,
            'screen' => ['ask' => 'ui.video_quality_ask'] + self::PROFILES,
            default => [],
        };
    }

    public static function read(int $accountId): array
    {
        $cameraKey = self::key($accountId, 'camera');
        $screenKey = self::key($accountId, 'screen');
        $stored = Setting::getMany([$cameraKey, $screenKey]);
        $result = self::DEFAULTS;
        foreach (['camera' => $cameraKey, 'screen' => $screenKey] as $media => $key) {
            $quality = $stored[$key] ?? null;
            if (is_string($quality) && isset(self::labels($media)[$quality])) {
                $result[$media] = $quality;
            }
        }
        return $result;
    }

    public static function save(int $accountId, mixed $media, mixed $quality): bool
    {
        if ($accountId <= 0 || !is_string($media) || !is_string($quality) || !isset(self::labels($media)[$quality])) {
            return false;
        }
        Setting::set(self::key($accountId, $media), $quality);
        return true;
    }

    private static function key(int $accountId, string $media): string
    {
        return "call_video_quality_{$media}_$accountId";
    }
}
