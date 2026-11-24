<?php
use Causette\Localization\Locale;
use Causette\View\AssetUrl;

require_once __DIR__ . '/Locale.php';
require_once __DIR__ . '/AssetUrl.php';

class ErrorHandler {
    private static $debugMode = false;

    private static function appVersion() {
        if (defined('APP_VERSION') && APP_VERSION !== '') {
            return (string) APP_VERSION;
        }

        return 'unknown';
    }

    private static function databaseVersion() {
        try {
            if (!class_exists('Setting')) {
                return 'unavailable';
            }

            $version = Setting::get('database_version');
            if ($version === null || $version === '') {
                return 'unknown';
            }

            return (string) $version;
        } catch (Throwable $e) {
            return 'unavailable';
        }
    }

    private static function versionMetadata() {
        return [
            'appVersion' => self::appVersion(),
            'databaseVersion' => self::databaseVersion()
        ];
    }

    public static function setDebugMode($enabled) {
        self::$debugMode = (bool)$enabled;
    }

    public static function register() {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        $logDir = trim((string)(getenv('LOG_DIRECTORY') ?: ''));
        if ($logDir === '' && defined('APP_LOG_DIRECTORY')) {
            $logDir = trim((string)APP_LOG_DIRECTORY);
        }
        if ($logDir !== '') {
            $logDir = rtrim($logDir, '/');
            if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
                ini_set('error_log', $logDir . '/error.log');
            }
        }
        error_reporting(E_ALL);

        set_exception_handler(function ($exception) {
            self::handleException($exception);
        });

        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function () {
            $error = error_get_last();

            if (!$error) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            self::logError('Fatal error', [
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);

            if (!headers_sent()) {
                @http_response_code(500);
            }

            self::render(500, $error['message'], [
                'file' => $error['file'],
                'line' => $error['line']
            ]);
            exit;
        });
    }

    public static function abort($status, $message = 'Unexpected error', $debug = []) {
        $status = (int) $status;
        $httpStatus = self::httpStatus($status);

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $silentPrefixes = ['/.well-known/'];
        foreach ($silentPrefixes as $prefix) {
            if (strncmp($uri, $prefix, strlen($prefix)) === 0) {
                if (!headers_sent()) {
                    @http_response_code($httpStatus);
                }
                exit;
            }
        }

        self::logError("HTTP {$status}", [
            'message' => $message,
            'debug' => $debug,
            'uri' => explode('?', $uri, 2)[0],
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI'
        ]);

        if (!headers_sent()) {
            @http_response_code($httpStatus);
        }

        self::render($status, $message, $debug);
        exit;
    }

    private static function httpStatus(int $status): int {
        // Keep the session-expired message while sending a standard HTTP status.
        return $status === 419 ? 403 : $status;
    }

    private static function handleException($exception) {
        self::logError('Unhandled exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);

        if (!headers_sent()) {
            @http_response_code(500);
        }

        self::render(500, $exception->getMessage(), [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
        exit;
    }

    private static function render($status, $message, $debug = []) {
        $key = self::statusKey($status);
        $path = self::applicationPath();
        if ($path === '/api' || str_starts_with($path, '/api/')
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        ) {
            $locale = Locale::forApiPath($path);
            header('Cache-Control: no-store');
            header('Content-Type: application/json');
            header('Content-Language: ' . $locale->language());
            $payload = ['error' => $locale->text('error.' . $key)];

            if (self::$debugMode) {
                $payload['debug'] = [
                    'message' => $message,
                    'details' => $debug,
                    'versions' => self::versionMetadata()
                ];
            }

            echo json_encode($payload);
            return;
        }

        $locale = Locale::current();
        $title = self::httpStatus($status) . ' - ' . $locale->text('error.' . $key);
        $description = $locale->html('error.' . $key . '_help');

        $debugBlock = '';
        if (self::$debugMode) {
            $versions = self::versionMetadata();
            $details = [
                $locale->text('error.debug_message', ['value' => $message]),
                $locale->text('error.debug_application_version', ['value' => $versions['appVersion']]),
                $locale->text('error.debug_database_version', ['value' => $versions['databaseVersion']]),
            ];

            if (!empty($debug['file'])) {
                $details[] = $locale->text('error.debug_file', ['value' => $debug['file']]);
            }

            if (!empty($debug['line'])) {
                $details[] = $locale->text('error.debug_line', ['value' => $debug['line']]);
            }

            if (!empty($debug['trace'])) {
                $details[] = $locale->text('error.debug_trace') . "\n" . $debug['trace'];
            }

            $debugBlock = '<pre class="debug">'
                . htmlspecialchars(implode("\n", $details), ENT_QUOTES, 'UTF-8')
                . '</pre>';
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Content-Language: ' . $locale->language());
            header("Content-Security-Policy: default-src 'none'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        echo '<!doctype html>'
            . '<html lang="' . htmlspecialchars($locale->language(), ENT_QUOTES, 'UTF-8') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<link rel="stylesheet" href="' . AssetUrl::html('/assets/css/error.css') . '">'
            . '</head><body><main>'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>' . $description . '</p>'
            . '<a class="home-link" href="' . htmlspecialchars(base_path('/'), ENT_QUOTES, 'UTF-8') . '">'
            . $locale->html('error.back_home') . '</a>'
            . $debugBlock
            . '</main></body></html>';
    }

    private static function logError($type, $context = []) {
        $context = array_merge($context, self::versionMetadata());
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('[Causette][' . $type . '] ' . $contextJson);
    }

    public static function logToDirectory(string $filename, string $type, array $context = []): void {
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $line = '[' . date('Y-m-d H:i:s') . '][Causette][' . $type . '] ' . $contextJson . PHP_EOL;

        if (defined('APP_LOG_DIRECTORY')) {
            $dir = rtrim((string)APP_LOG_DIRECTORY, '/');
            if ($dir !== '' && (is_dir($dir) || @mkdir($dir, 0775, true))) {
                $path = $dir . '/' . $filename;
                if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) {
                    return;
                }
            }
        }

        error_log('[Causette][' . $type . '][fallback:' . $filename . '] ' . $contextJson);
    }

    private static function applicationPath(): string {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $prefix = defined('APP_BASE_PATH') ? trim(APP_BASE_PATH, '/') : '';
        if ($prefix !== '' && str_starts_with($path, '/' . $prefix . '/')) {
            return substr($path, 1 + strlen($prefix));
        }
        return $prefix !== '' && $path === '/' . $prefix ? '/' : $path;
    }

    private static function statusKey($status): string {
        return match ((int) $status) {
            403 => 'forbidden',
            404 => 'not_found',
            419 => 'session_expired',
            424 => 'failed_dependency',
            500 => 'server_error',
            default => 'error',
        };
    }
}
