<?php

declare(strict_types=1);

namespace Causette\View;

final class AssetUrl
{
    public static function html(string $path): string
    {
        $digest = hash_file('sha256', dirname(__DIR__, 2) . $path);
        return htmlspecialchars(base_url($path) . '?v=' . substr($digest, 0, 16), ENT_QUOTES, 'UTF-8');
    }
}
