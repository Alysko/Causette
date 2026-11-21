<?php

declare(strict_types=1);

namespace Causette\Localization;

final class Locale
{
    private const DEFAULT_LANGUAGE = 'en';
    private const COOKIE = 'CAUSETTE_LANGUAGE';
    private static ?self $current = null;
    private array $catalogs = [];

    private function __construct(private readonly string $language)
    {
    }

    public static function initialize(string $method, string $basePath, string $siteLanguage = ''): void
    {
        $requested = $method === 'GET' ? $_GET['lang'] ?? null : null;
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        self::$current = self::resolve($requested, self::supported($cookie) ? $cookie : $siteLanguage, self::acceptLanguage());
        if (self::supported($requested) && ($_COOKIE[self::COOKIE] ?? null) !== $requested) {
            setcookie(self::COOKIE, $requested, [
                'expires' => time() + 31536000,
                'path' => $basePath . '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function initializeForAccount(string $personalLanguage, string $siteLanguage): void
    {
        self::$current = self::resolve($personalLanguage, $siteLanguage, self::acceptLanguage());
    }

    public static function current(): self
    {
        return self::$current ?? self::resolve($_GET['lang'] ?? null, $_COOKIE[self::COOKIE] ?? null, self::acceptLanguage());
    }

    public static function resolve(mixed $requested = null, mixed $preferred = null, string $acceptLanguage = ''): self
    {
        if (self::supported($requested)) {
            return new self($requested);
        }
        if (self::supported($preferred)) {
            return new self($preferred);
        }

        $bestLanguage = self::DEFAULT_LANGUAGE;
        $bestQuality = 0.0;
        foreach (explode(',', substr($acceptLanguage, 0, 4096)) as $range) {
            if (!preg_match('/\A([a-z]{2})(?:-[a-z0-9]{1,8})*(?:\s*;\s*q=(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\z/i', trim($range), $parts)) {
                continue;
            }
            $language = strtolower($parts[1]);
            $quality = isset($parts[2]) ? (float)$parts[2] : 1.0;
            if (self::supported($language) && $quality > $bestQuality) {
                $bestLanguage = $language;
                $bestQuality = $quality;
            }
        }
        return new self($bestLanguage);
    }

    public function language(): string
    {
        return $this->language;
    }

    public static function languages(): array
    {
        static $languages = null;
        return $languages ??= require __DIR__ . '/../locales/languages.php';
    }

    public function catalog(string $group): array
    {
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/', $group)) {
            return [];
        }
        return $this->catalogs[$group] ??= array_replace(
            self::load(self::DEFAULT_LANGUAGE, $group),
            $this->language === self::DEFAULT_LANGUAGE ? [] : self::load($this->language, $group),
        );
    }

    public function text(string $key, array $parameters = []): string
    {
        [$group, $name] = array_pad(explode('.', $key, 2), 2, '');
        $message = $this->catalog($group)[$name] ?? $key;
        $replacements = [];
        foreach ($parameters as $parameter => $value) {
            $replacements['{' . $parameter . '}'] = (string)$value;
        }
        return strtr($message, $replacements);
    }

    public function html(string $key, array $parameters = []): string
    {
        return htmlspecialchars($this->text($key, $parameters), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function acceptLanguage(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        return is_string($header) ? $header : '';
    }

    private static function supported(mixed $language): bool
    {
        return is_string($language) && isset(self::languages()[$language]);
    }

    private static function load(string $language, string $group): array
    {
        $path = __DIR__ . '/../locales/' . $language . '/' . $group . '.php';
        return is_file($path) ? require $path : [];
    }
}
