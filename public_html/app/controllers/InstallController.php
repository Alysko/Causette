<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Installation\SetupConfiguration;
use Causette\Installation\SetupFailure;
use Causette\Localization\Locale;

require_once __DIR__ . '/../install/SetupConfiguration.php';
require_once __DIR__ . '/../install/InstallDatabase.php';

class InstallController extends Controller
{
    public function showInstall(): void
    {
        $prepared = $this->prepared();
        if ($prepared === null) {
            $this->renderSetup('closed', 404, 'install.unavailable');
            return;
        }

        $screen = $this->authorized($prepared['configuration']) ? 'administrator' : 'operator';
        $this->renderSetup($screen);
    }

    public function install(): void
    {
        $prepared = $this->prepared();
        if ($prepared === null) {
            $this->renderSetup('closed', 404, 'install.unavailable');
            return;
        }

        $csrf = $_POST['csrf_token'] ?? null;
        if (!is_string($csrf) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            $this->renderSetup('operator', 403, 'install.csrf');
            return;
        }

        $configuration = $prepared['configuration'];
        $stage = $_POST['stage'] ?? '';
        if ($stage === 'authorize') {
            $this->authorizeOperator($configuration);
            return;
        }
        if ($stage !== 'install' || !$this->authorized($configuration)) {
            $this->renderSetup('operator', 403, 'install.token_required');
            return;
        }

        $username = $_POST['username'] ?? null;
        $email = $_POST['email'] ?? null;
        $password = $_POST['password'] ?? null;
        $fieldErrors = [];
        if (!is_string($username) || !preg_match('/\A[a-z][a-z0-9]{3,31}\z/D', $username)) {
            $fieldErrors['admin-username'] = 'install.username_invalid';
        }
        if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['admin-email'] = 'install.email_invalid';
        }
        if (!is_string($password) || strlen($password) < 15 || strlen($password) > 72 || str_contains($password, "\0")) {
            $fieldErrors['admin-password'] = 'install.password_invalid';
        }
        if ($fieldErrors !== []) {
            $this->renderSetup('administrator', 422, fieldErrors: $fieldErrors);
            return;
        }

        $this->completeInstallation($prepared['storage'], $username, $email, $password);
    }

    private function prepared(): ?array
    {
        $storage = SetupConfiguration::privateRoot();
        if ($storage === null
            || SetupConfiguration::exists($storage . '/' . SetupConfiguration::RUNTIME_FILE)
            || SetupConfiguration::exists(__DIR__ . '/../config/config.php')
        ) {
            return null;
        }

        try {
            $configuration = SetupConfiguration::read($storage . '/' . SetupConfiguration::OPERATOR_FILE);
            SetupConfiguration::validateSite($configuration);
            $operatorToken = $configuration['bootstrap_token'] ?? null;
            if (!is_string($operatorToken) || !preg_match('/\A[A-Za-z0-9_-]{48,128}\z/D', $operatorToken)
                || !SetupConfiguration::acceptsRequest($configuration['url'], $configuration['trusted_proxies'] ?? [])
            ) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return ['configuration' => $configuration, 'storage' => $storage];
    }

    private function authorizationFingerprint(array $configuration): string
    {
        return hash('sha256', serialize($configuration));
    }

    private function authorized(array $configuration): bool
    {
        return ($_SESSION['setup_authorized_until'] ?? 0) > time()
            && ($_SESSION['setup_authorization'] ?? '') === $this->authorizationFingerprint($configuration);
    }

    private function authorizeOperator(array $configuration): void
    {
        if (($_SESSION['setup_attempts_expire'] ?? 0) <= time()) {
            $_SESSION['setup_attempts_expire'] = time() + 900;
            $_SESSION['setup_attempts'] = 0;
        }
        if ($_SESSION['setup_attempts'] >= 5) {
            $this->renderSetup('operator', 429, 'install.rate_limit');
            return;
        }

        $token = $_POST['bootstrap_token'] ?? null;
        if (!is_string($token) || strlen($token) > 128 || !hash_equals($configuration['bootstrap_token'], $token)) {
            $_SESSION['setup_attempts']++;
            unset($_SESSION['setup_authorization'], $_SESSION['setup_authorized_until']);
            $this->renderSetup('operator', 403, 'install.token_rejected');
            return;
        }

        if (!session_regenerate_id(true)) {
            $this->renderSetup('operator', 503, 'install.protected_session');
            return;
        }
        $_SESSION = [
            'setup_authorization' => $this->authorizationFingerprint($configuration),
            'setup_authorized_until' => time() + 600,
        ];
        $this->renderSetup('administrator');
    }

    private function completeInstallation(
        string $storage,
        string $username,
        string $email,
        string $password,
    ): void {
        $required = ['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'openssl', 'ctype', 'session'];
        $missing = array_values(array_filter($required, static fn(string $extension): bool => !extension_loaded($extension)));
        if (PHP_VERSION_ID < 80500) {
            $this->renderSetup('administrator', 503, 'install.php_version');
            return;
        }
        if ($missing !== []) {
            $this->renderSetup('administrator', 503, 'install.extensions', parameters: ['extensions' => implode(', ', $missing)]);
            return;
        }

        foreach (['', '/attachments', '/avatars', '/logs'] as $directory) {
            $path = $storage . $directory;
            if (is_link($path) || realpath($path) !== $path || !is_dir($path) || !is_writable($path)) {
                $this->renderSetup('administrator', 503, 'install.storage_writable');
                return;
            }
        }

        $lockPath = $storage . '/install.lock';
        $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c');
        if ($lock === false) {
            $this->renderSetup('administrator', 503, 'install.lock_open');
            return;
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                $this->renderSetup('administrator', 409, 'install.lock_busy');
                return;
            }

            // Recheck the operator file and completion state while holding the installation lock.
            $current = $this->prepared();
            if ($current === null || !$this->authorized($current['configuration'])) {
                $this->renderSetup('closed', 404, 'install.unavailable');
                return;
            }

            $databaseVersion = (new InstallDatabase($storage))->install($current['configuration'], $username, $email, $password);
            @unlink($storage . '/' . SetupConfiguration::OPERATOR_FILE);
            $_SESSION = [];
            $this->renderSetup('success', databaseVersion: $databaseVersion);
        } catch (PDOException) {
            $this->renderSetup('administrator', 503, 'install.database_error');
        } catch (SetupFailure $failure) {
            $this->renderSetup('administrator', $failure->getCode() === 409 ? 409 : 503, $failure->translationKey);
        } catch (Throwable) {
            $this->renderSetup('administrator', 503, 'install.retry');
        } finally {
            fclose($lock);
        }
    }

    private function renderSetup(
        string $screen,
        int $status = 200,
        string $messageKey = '',
        string $databaseVersion = '',
        array $parameters = [],
        array $fieldErrors = [],
    ): void {
        http_response_code($status);
        $locale = Locale::current();
        header('Content-Language: ' . $locale->language());
        $csrfToken = $screen === 'operator' || $screen === 'administrator' ? $this->csrfToken() : '';
        $setupBasePath = defined('CAUSETTE_PUBLIC_PATH') ? CAUSETTE_PUBLIC_PATH : '';
        require __DIR__ . '/../views/install.php';
    }
}
