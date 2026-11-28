<?php
require_once __DIR__ . '/TrustedBrowser.php';
require_once __DIR__ . '/BrowserSession.php';
require_once __DIR__ . '/DisplayPreferences.php';

use Causette\Localization\Locale;
use Causette\Security\BrowserSession;
use Causette\Security\TrustedBrowser;

class Auth {
    public static function user() {
        $user = self::authenticatedAccount();
        return $user ? User::attachEffectiveStatus($user) : null;
    }

    private static function authenticatedAccount(): ?object {
        if (!empty($_SESSION['auth_session_retired'])) {
            return null;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $sessionToken = $_SESSION['auth_session_token'] ?? null;
        $digest = BrowserSession::sessionDigest($sessionToken);
        $user = $userId > 0 && $digest !== null ? self::activeAccount($userId, $digest) : null;
        if (!$user) {
            self::clearSessionState();
            $session = self::restoreRememberedSession($userId);
            if ($session === null) {
                return null;
            }
            [$userId, $sessionToken] = $session;
            $digest = BrowserSession::sessionDigest($sessionToken);
            $user = self::activeAccount($userId, $digest);
            if (!$user) {
                self::clearSessionState();
                return null;
            }
        }

        if (User::isBanned($user)) {
            User::deleteAllSessionsForUser((int)$user->id);
            self::clearSessionState();
            return null;
        }

        $lastTouchAt = (int)($_SESSION['last_activity_touch_at'] ?? 0);
        $now = time();
        if (BrowserSession::shouldRotate($sessionToken)) {
            $replacement = self::rotateSession($userId, $digest);
            if ($replacement === null) {
                self::clearSessionState();
                return null;
            }
            if (($_SESSION['password_authentication']['session_token'] ?? null) === $sessionToken) {
                $_SESSION['password_authentication']['session_token'] = $replacement;
            }
            $_SESSION['auth_session_token'] = $replacement;
            $digest = BrowserSession::sessionDigest($replacement);
        }

        $presenceTouchedAt = (int)($_SESSION['last_presence_touch_at'] ?? $lastTouchAt);
        if (($_SERVER['HTTP_X_CAUSETTE_PRESENCE'] ?? '') !== 'idle' && $now - $presenceTouchedAt >= 60) {
            User::touchActivity((int)$user->id);
            $_SESSION['last_presence_touch_at'] = $now;
            $user->last_active_at = date('Y-m-d H:i:s', $now);
        }
        if ($lastTouchAt <= 0 || ($now - $lastTouchAt) >= 60) {
            Database::query(
                'UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND session_token = CAST(? AS BINARY) AND ' . BrowserSession::ACTIVE,
                [$userId, $digest],
            );
            $_SESSION['last_activity_touch_at'] = $now;
        }

        return $user;
    }

    private static function activeAccount(int $userId, string $digest): ?object {
        $account = Database::query(
            'SELECT u.* FROM user_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ? AND s.session_token = CAST(? AS BINARY) AND ' . BrowserSession::ACTIVE . ' LIMIT 1',
            [$userId, $digest],
        )->fetch();
        return $account ?: null;
    }

    private static function rotateSession(int $userId, string $digest): ?string {
        if (!self::renewPhpSession()) {
            return null;
        }

        $token = BrowserSession::newSession();
        $changed = Database::query(
            'UPDATE user_sessions SET session_token = ?, last_seen_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND session_token = CAST(? AS BINARY) AND ' . BrowserSession::ACTIVE,
            [BrowserSession::sessionDigest($token), $userId, $digest],
        )->rowCount() === 1;
        return $changed ? $token : null;
    }

    private static function renewPhpSession(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        // Retire the old ID before releasing its PHP session lock. A parallel
        // request must neither authenticate nor use the remember cookie from it.
        $state = $_SESSION;
        $_SESSION = ['auth_session_retired' => true];
        $renewed = session_regenerate_id(false);
        $_SESSION = $state;
        return $renewed;
    }

    public static function requireAuth(): object {
        $user = self::authenticatedAccount();
        if (!$user) {
            self::redirect('/login');
        }

        $emailVerificationRequired = (string)(Setting::get('email_verification_required') ?? '1') === '1';
        if ($emailVerificationRequired && empty($user->email_verified_at)) {
            self::beginEmailVerification($user);
            self::redirect('/verify-email');
        }
        Locale::bindAuthenticatedAccount((int)$user->id);
        return $user;
    }

    public static function passwordBinding(object $user): string {
        return hash('sha256', (string)$user->password);
    }

    public static function beginEmailVerification(object $user): void {
        $_SESSION['email_verification_user'] = (int)$user->id;
        $_SESSION['email_verification_password_binding'] = self::passwordBinding($user);
        self::clearSessionState();
    }

    public static function pendingEmailVerificationUser(): ?object {
        $userId = (int)($_SESSION['email_verification_user'] ?? 0);
        $binding = $_SESSION['email_verification_password_binding'] ?? null;
        if ($userId > 0 && is_string($binding)) {
            $user = User::find($userId);
            if ($user && hash_equals(self::passwordBinding($user), $binding)) {
                return $user;
            }
        }

        self::clearEmailVerification();
        return null;
    }

    public static function clearEmailVerification(): void {
        unset($_SESSION['email_verification_user'], $_SESSION['email_verification_password_binding'], $_SESSION['email_verification_challenge']);
    }

    public static function needs2FA(int $userId, ?string $trustedBrowser): bool {
        return !TrustedBrowser::permits($userId, $trustedBrowser);
    }

    public static function csrfValidate(int $failureStatus = 419) {
        if (!is_string($_POST['csrf_token'] ?? null) || !is_string($_SESSION['csrf_token'] ?? null)
            || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            ErrorHandler::abort($failureStatus, 'CSRF validation failed');
        }
    }

    public static function registerLoginSession($userId, $ip, $userAgent) {
        $token = BrowserSession::newSession();
        Database::query(
            "INSERT INTO user_sessions (user_id, session_token, ip_address, browser) VALUES (?, ?, ?, ?)",
            [(int)$userId, BrowserSession::sessionDigest($token), (string)$ip, self::browserLabel($userAgent)]
        );

        return $token;
    }

    public static function registerBoundSession(
        int $userId,
        string $passwordBinding,
        string $ip,
        string $userAgent,
        ?string $trustedBrowser = null,
    ): ?string {
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $user = Database::query('SELECT password, is_banned FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user || User::isBanned($user) || !hash_equals(self::passwordBinding($user), $passwordBinding)
                || ($trustedBrowser !== null && self::needs2FA($userId, $trustedBrowser))
            ) {
                $connection->rollBack();
                return null;
            }

            $token = self::registerLoginSession($userId, $ip, $userAgent);
            $connection->commit();
            return $token;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public static function recordPasswordAuthentication(int $checkedAt): void {
        $_SESSION['password_authentication'] = [
            'session_token' => $_SESSION['auth_session_token'],
            'checked_at' => $checkedAt,
        ];
    }

    public static function revokeCurrentSession() {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $sessionToken = $_SESSION['auth_session_token'] ?? null;
        if ($userId > 0 && is_string($sessionToken)) {
            User::revokeSessionByToken($userId, $sessionToken);
        }

        $remember = BrowserSession::rememberDigest($_COOKIE['remember_me'] ?? null);
        if ($remember !== null) {
            Database::query(
                'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(), remember_token = NULL
                 WHERE remember_token = CAST(? AS BINARY) AND revoked_at IS NULL',
                [$remember],
            );
        }
    }

    private static function clearSessionState() {
        unset($_SESSION['user_id'], $_SESSION['auth_session_token'], $_SESSION['last_activity_touch_at'], $_SESSION['password_authentication']);
    }

    private static function redirect(string $path): void {
        $normalizedPath = '/' . ltrim($path, '/');
        $basePath = trim((string)(defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
        $target = ($basePath !== '' ? '/' . $basePath : '') . $normalizedPath;

        header('Location: ' . $target, true, 302);
        exit;
    }

    public static function setRememberCookie(int $userId, string $sessionToken): void {
        $digest = BrowserSession::sessionDigest($sessionToken);
        if ($digest === null || !is_https_request()) {
            return;
        }

        $expires = time() + BrowserSession::REMEMBER_SECONDS;
        $token = BrowserSession::newRemember($expires);
        $changed = Database::query(
            'UPDATE user_sessions SET remember_token = ?
             WHERE user_id = ? AND session_token = CAST(? AS BINARY) AND remember_token IS NULL AND ' . BrowserSession::ACTIVE,
            [BrowserSession::rememberDigest($token), $userId, $digest],
        )->rowCount() === 1;
        if ($changed) {
            self::sendRememberCookie($token, $expires);
        }
    }

    public static function clearRememberCookie(): void {
        unset($_COOKIE['remember_me']);
        self::sendRememberCookie('', time() - 3600);
    }

    private static function restoreRememberedSession(int $expectedUserId): ?array {
        $token = $_COOKIE['remember_me'] ?? null;
        $expires = BrowserSession::rememberExpiry($token);
        if ($expires === null || $expires <= time() || !is_https_request()
            || isset($_SESSION['2fa_pending_user']) || isset($_SESSION['email_verification_user'])
        ) {
            return null;
        }

        $digest = BrowserSession::rememberDigest($token);
        $row = Database::query(
            'SELECT id, user_id FROM user_sessions WHERE remember_token = CAST(? AS BINARY) AND ' . BrowserSession::RESTORABLE,
            [$digest],
        )->fetch();
        if (!$row || ($expectedUserId > 0 && (int)$row->user_id !== $expectedUserId) || !self::renewPhpSession()) {
            return null;
        }

        $userId = (int)$row->user_id;
        $replacementSession = BrowserSession::newSession();
        $replacementRemember = BrowserSession::newRemember($expires);
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            // Share the account lock with password resets and session issuance.
            $account = Database::query('SELECT is_banned FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            $consumed = $account && !User::isBanned($account) && Database::query(
                'UPDATE user_sessions SET session_token = ?, remember_token = ?, logged_in_at = UTC_TIMESTAMP(), last_seen_at = UTC_TIMESTAMP()
                 WHERE id = ? AND user_id = ? AND remember_token = CAST(? AS BINARY) AND ' . BrowserSession::RESTORABLE,
                [BrowserSession::sessionDigest($replacementSession), BrowserSession::rememberDigest($replacementRemember),
                    (int)$row->id, $userId, $digest],
            )->rowCount() === 1;
            if (!$consumed) {
                $connection->rollBack();
                return null;
            }
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_session_token'] = $replacementSession;
        unset($_SESSION['password_authentication'], $_SESSION['csrf_token']);
        $_SESSION['last_activity_touch_at'] = 0;
        self::sendRememberCookie($replacementRemember, $expires);
        return [$userId, $replacementSession];
    }

    private static function sendRememberCookie(string $token, int $expires): void {
        setcookie('remember_me', $token, [
            'expires'  => $expires,
            'path'     => self::cookiePath(),
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function cookiePath(): string {
        return (defined('CAUSETTE_PUBLIC_PATH') ? CAUSETTE_PUBLIC_PATH : '') . '/';
    }

    private static function browserLabel($userAgent) {
        $ua = strtolower(trim((string)$userAgent));
        if ($ua === '') {
            return 'Unknown browser';
        }

        if (strpos($ua, 'edg/') !== false) return 'Microsoft Edge';
        if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) return 'Opera';
        if (strpos($ua, 'firefox/') !== false) return 'Firefox';
        if (strpos($ua, 'chrome/') !== false && strpos($ua, 'chromium') === false) return 'Chrome';
        if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false) return 'Safari';

        return 'Unknown browser';
    }
}
