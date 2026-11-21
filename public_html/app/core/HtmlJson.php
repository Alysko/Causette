<?php

declare(strict_types=1);

namespace Causette\View;

final class HtmlJson
{
    public static function encode(mixed $value): string
    {
        // The HTML parser must see no literal tag delimiters inside the JSON.
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
