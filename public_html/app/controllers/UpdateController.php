<?php

declare(strict_types=1);

use Causette\Installation\SetupConfiguration;
use Causette\Installation\ReactionSchemaUpgrade;

require_once __DIR__ . '/../install/ReactionSchemaUpgrade.php';

class UpdateController extends Controller
{
    private const REAUTHENTICATION_WINDOW = 300;

    public static function supportsVersion(string $version): bool
    {
        return defined('CAUSETTE_UPDATE_SOURCES')
            && version_compare($version, APP_VERSION, '<')
            && in_array($version, CAUSETTE_UPDATE_SOURCES, true);
    }

    public static function allowsDuringMaintenance(string $path, string $method, bool $supported): bool
    {
        $route = $method . ' ' . $path;
        if (in_array($route, ['GET /update', 'POST /update', 'POST /update/reauthenticate'], true)) {
            return true;
        }

        return $supported && in_array($route, [
            'GET /login', 'POST /login',
            'GET /2fa', 'POST /2fa',
            'GET /verify-email', 'POST /verify-email', 'POST /verify-email/resend',
        ], true);
    }

    public function showUpdate(): void
    {
        $this->requireAdministrator();
        $version = $this->requireSupportedVersion();
        $this->viewRaw('update', [
            'csrf' => $this->csrfToken(),
            'dbVersion' => $version,
            'appVersion' => APP_VERSION,
            'recentAuthentication' => $this->hasRecentPasswordAuthentication(),
            'reactionSteps' => $this->reactionUpgradeProgress(),
        ]);
    }

    public function runUpdate(): void
    {
        $this->requireAdministrator();
        $this->validateForm();
        $version = $this->requireSupportedVersion();
        if (!$this->hasRecentPasswordAuthentication()) {
            ErrorHandler::abort(403);
        }
        if (($_POST['backup_confirmed'] ?? null) !== '1') {
            ErrorHandler::abort(400);
        }

        try {
            $advanced = $this->advanceVersion($version);
        } catch (Throwable) {
            error_log('[Causette] The database update could not complete. Check the reaction table constraints and ALTER permissions.');
            ErrorHandler::abort(503);
        }
        if (!$advanced) {
            ErrorHandler::abort(409);
        }

        $this->flash('success', 'update_complete');
        $this->redirect('/');
    }

    protected function advanceVersion(string $version): bool
    {
        return APP_VERSION === ReactionSchemaUpgrade::TARGET
            ? ReactionSchemaUpgrade::apply($version)
            : Setting::advanceDatabaseVersion($version, APP_VERSION);
    }

    protected function reactionUpgradeProgress(): ?int
    {
        return APP_VERSION === ReactionSchemaUpgrade::TARGET
            ? (int)Setting::get(ReactionSchemaUpgrade::PROGRESS_KEY)
            : null;
    }

    public function reauthenticate(): void
    {
        $this->requireAdministrator();
        $this->validateForm();
        $this->requireSupportedVersion();
        (new AuthController())->logout();
    }

    private function requireAdministrator(): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

        $secure = defined('CAUSETTE_CANONICAL_URL') ? is_https_request() : SetupConfiguration::acceptsRequest(APP_URL);
        if (!defined('CAUSETTE_UPDATE_SOURCES') || !$secure
            || empty($_SESSION['user_id']) || empty($_SESSION['auth_session_token'])
        ) {
            ErrorHandler::abort(403);
        }

        $administrator = Auth::user();
        if (($administrator->role ?? null) !== 'admin' || empty($administrator->email_verified_at)) {
            ErrorHandler::abort(403);
        }
    }

    private function validateForm(): void
    {
        if (!is_string($_POST['csrf_token'] ?? null)) {
            ErrorHandler::abort(419);
        }
        Auth::csrfValidate();
    }

    private function requireSupportedVersion(): string
    {
        $version = Setting::get('database_version');
        if ($version === APP_VERSION) {
            ErrorHandler::abort(404);
        }
        if ($version === null || !self::supportsVersion($version)) {
            ErrorHandler::abort(409);
        }

        return $version;
    }

    private function hasRecentPasswordAuthentication(): bool
    {
        $authentication = $_SESSION['password_authentication'] ?? [];
        $checkedAt = $authentication['checked_at'] ?? null;
        $now = time();

        return ($authentication['session_token'] ?? null) === $_SESSION['auth_session_token']
            && is_int($checkedAt) && $checkedAt <= $now && $checkedAt >= $now - self::REAUTHENTICATION_WINDOW;
    }
}
