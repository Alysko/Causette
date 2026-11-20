<?php

declare(strict_types=1);

use Causette\Installation\SetupConfiguration;
use Causette\Security\AuthBudget;
use Causette\Security\CredentialUpgrade;

require_once __DIR__ . '/../core/AuthBudget.php';
require_once __DIR__ . '/../core/CredentialUpgrade.php';

final class CredentialUpgradeController extends Controller
{
    public function handle(string $method, string $databaseVersion): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

        $token = $this->operatorToken();
        if ($token === null) {
            http_response_code(404);
            return;
        }
        if ($databaseVersion !== APP_VERSION && !UpdateController::supportsVersion($databaseVersion)) {
            $this->render(409, 'ui.credential_version_unavailable');
            return;
        }
        if ($method === 'GET' || $method === 'HEAD') {
            $this->render();
            return;
        }
        if ($method !== 'POST') {
            header('Allow: GET, HEAD, POST');
            http_response_code(405);
            return;
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
            http_response_code(413);
            return;
        }

        $csrf = $_POST['csrf_token'] ?? null;
        $proof = $_POST['operator_token'] ?? null;
        if (!is_string($csrf) || !is_string($_SESSION['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            $this->render(403, 'ui.credential_form_stale');
            return;
        }
        if (!AuthBudget::allow('credential_upgrade', 0, $_SERVER['REMOTE_ADDR'] ?? '', 60)) {
            $this->render(429, 'ui.credential_rate_limit');
            return;
        }
        if (!is_string($proof) || strlen($proof) > 128 || !hash_equals($token, $proof)) {
            $this->render(403, 'ui.credential_proof_invalid');
            return;
        }
        if (($_POST['backup_confirmed'] ?? null) !== '1') {
            $this->render(400, 'ui.credential_backup_required');
            return;
        }

        try {
            $result = CredentialUpgrade::run(CAUSETTE_INTEGRATION_KEY_FILE);
            $this->render(result: $result);
        } catch (Throwable $failure) {
            $message = $failure->getCode() === 409
                ? 'ui.credential_busy'
                : 'ui.credential_failed';
            $this->render($failure->getCode() === 409 ? 409 : 503, $message);
        }
    }

    private function operatorToken(): ?string
    {
        if (!defined('CAUSETTE_INTEGRATION_KEY_FILE')) {
            return null;
        }
        $path = dirname(CAUSETTE_INTEGRATION_KEY_FILE) . '/' . CredentialUpgrade::OPERATOR_FILE;
        if (!SetupConfiguration::exists($path)) {
            return null;
        }
        try {
            $token = SetupConfiguration::read($path)['operator_token'] ?? null;
        } catch (Throwable) {
            return null;
        }
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{48,128}\z/', $token) ? $token : null;
    }

    private function render(int $status = 200, string $message = '', ?array $result = null): void
    {
        http_response_code($status);
        $this->viewRaw('credential-upgrade', ['message' => $message, 'result' => $result]);
    }
}
