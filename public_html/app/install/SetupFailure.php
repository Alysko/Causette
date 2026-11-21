<?php

declare(strict_types=1);

namespace Causette\Installation;

use Causette\Localization\Locale;
use RuntimeException;

require_once __DIR__ . '/../core/Locale.php';

final class SetupFailure extends RuntimeException
{
    public function __construct(public readonly string $translationKey, int $code = 0)
    {
        parent::__construct(Locale::resolve('en')->text($translationKey), $code);
    }
}
