<?php
require_once __DIR__ . '/DisplayPreferences.php';

use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;

class Controller {
    protected function view($view, $data = []) {
        header('X-App-Version: ' . APP_VERSION);

        if (!isset($data['csrf'])) {
            $data['csrf'] = $this->csrfToken();
        }

        extract($data);
        $pageViewer = Auth::user();
        DisplayPreferences::initialize($pageViewer ? (int)$pageViewer->id : null, $data['userTimezone'] ?? null);
        header('Content-Language: ' . Locale::current()->language());
        $viewFile = __DIR__ . "/../views/{$view}.php";
        if (!file_exists($viewFile)) {
            ErrorHandler::abort(500, 'View not found', [
                'view' => $view,
                'view_file' => $viewFile
            ]);
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        $renderMainOnly = $view === 'chat' && $pageViewer && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && ($_SERVER['HTTP_X_CAUSETTE_VIEW'] ?? '') === 'main';
        require __DIR__ . '/../views/layouts/main.php';
    }

    protected function viewRaw($view, $data = []) {
        if (!isset($data['csrf'])) {
            $data['csrf'] = $this->csrfToken();
        }

        extract($data);
        $pageViewer = Auth::user();
        DisplayPreferences::initialize($pageViewer ? (int)$pageViewer->id : null);
        header('Content-Language: ' . Locale::current()->language());
        $viewFile = __DIR__ . "/../views/{$view}.php";
        if (!file_exists($viewFile)) {
            ErrorHandler::abort(500, 'View not found', [
                'view' => $view,
                'view_file' => $viewFile
            ]);
        }

        require $viewFile;
    }

    protected function flash(string $key, string $value): void {
        $_SESSION['_flash'][$key] = $value;
    }

    protected function redirect($url) {
        $candidate = str_replace(["\r", "\n"], '', trim((string)$url));
        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            $normalizedPath = '/';
        } else {
            $path = isset($parts['path']) ? (string)$parts['path'] : '/';
            if ($path === '') {
                $path = '/';
            }

            $normalizedPath = '/' . ltrim($path, '/');
            if (isset($parts['query']) && $parts['query'] !== '') {
                $normalizedPath .= '?' . $parts['query'];
            }
            if (isset($parts['fragment']) && $parts['fragment'] !== '') {
                $normalizedPath .= '#' . $parts['fragment'];
            }
        }

        $basePath = trim((string)(defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
        $target = ($basePath !== '' ? '/' . $basePath : '') . $normalizedPath;

        header('Location: ' . $target, true, 302);
        exit;
    }

    protected function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-App-Version: ' . APP_VERSION);
        echo json_encode($data);
        exit;
    }

    protected function csrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
