<?php
require_once __DIR__ . '/../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../modules/2fa/TwoFAManager.php';
require_once __DIR__ . '/../modules/captcha/CaptchaManager.php';
require_once __DIR__ . '/../core/AccountPassword.php';
require_once __DIR__ . '/../core/SecretSettings.php';

use Causette\Localization\DisplayPreferences;
use Causette\Security\AccountPassword;
use Causette\Security\AuthBudget;
use Causette\Security\AuthChallenge;
use Causette\Security\SecretSettings;
use Causette\Security\TrustedBrowser;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class AuthController extends Controller {
    private const AUTH_ATTEMPT_LIMIT_PER_MINUTE = 5;
    private const LOGIN_ACCOUNT_LIMIT = 8;
    private const AUTH_ATTEMPT_REGISTER_INVITE_FAILED = 'register_invite_failed';

    public function showLogin() {
        if (Auth::user()) {
            $this->redirect('/');
        }

        $this->view('auth/login', array_merge(['csrf' => $this->csrfToken()], $this->getCaptchaViewData()));
    }

    public function login() {
        if (Auth::user()) {
            $this->redirect('/');
        }

        Auth::csrfValidate();
        $this->clearPendingTwoFactor();
        $this->verifyCaptchaOrFail('/login');
        $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $user = null;
        if ($identifier !== '' && is_string($password) && $password !== '') {
            $user = str_contains($identifier, '@')
                ? User::findByEmail($identifier)
                : User::findByUsername($identifier);
        }

        // Unknown names share a fixed bucket, and both identifiers for an account share its user ID.
        if (!AuthBudget::allow('login', (int)($user->id ?? 0), $ip, self::LOGIN_ACCOUNT_LIMIT)
            || !$user || !password_verify($password, $user->password)
        ) {
            $this->flash('error', 'invalid');
            $this->redirect('/login');
        }
        $passwordCheckedAt = time();
        $passwordBinding = Auth::passwordBinding($user);

        if (User::isBanned($user)) {
            $this->flash('error', 'banned');
            $this->redirect('/login');
        }

        if (empty($user->email_verified_at)) {
            $emailVerificationRequired = (string)(Setting::get('email_verification_required') ?? '1') === '1';
            if ($emailVerificationRequired) {
                $this->sendVerificationCode($user, 'sent');
                $this->redirect('/verify-email');
            }
            // Verification not required — auto-verify and continue login
            Database::query("UPDATE users SET email_verified_at = NOW() WHERE id = ?", [$user->id]);
            $user->email_verified_at = date('Y-m-d H:i:s');
        }

        $rememberMe = !empty($_POST['remember_me']);

        $twofaProvider = TwoFAManager::getAvailableProvider((int)$user->id);
        $cookie = $_COOKIE[TrustedBrowser::cookieName()] ?? null;
        $trustedBrowser = $twofaProvider?->getName() === 'totp' && is_string($cookie) && is_https_request()
            ? $cookie : null;
        if ($twofaProvider && Auth::needs2FA((int)$user->id, $trustedBrowser)) {
            $providerName = $twofaProvider->getName();
            $deliveryCode = $providerName === 'email' ? AuthChallenge::generateCode() : null;
            $context = $providerName === 'email' ? 'email:' . $user->email : $providerName;
            $challenge = $this->prepareMfaChallenge($user, $ip, $deliveryCode, $context);
            if ($challenge === null) {
                $this->flash('error', 'too_many_attempts');
                $this->redirect('/login');
                return;
            }

            try {
                $delivered = $twofaProvider->sendChallenge((int)$user->id, $deliveryCode);
            } catch (\Throwable) {
                $delivered = false;
            }

            if (!$delivered) {
                $this->cancelMfaChallenge($user, $challenge);
                $this->flash('error', 'mfa_unavailable');
                $this->redirect('/login');
                return;
            }

            $_SESSION['2fa_pending_user'] = $user->id;
            $_SESSION['2fa_remember_me'] = $rememberMe;
            $_SESSION['2fa_provider'] = $providerName;
            $_SESSION['2fa_password_checked_at'] = $passwordCheckedAt;
            $_SESSION['2fa_password_binding'] = $passwordBinding;
            $_SESSION['2fa_challenge'] = $challenge;
            $this->redirect('/2fa');
            return;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->openBoundSession((int)$user->id, $passwordBinding, $ip, $userAgent, $trustedBrowser);
        Auth::recordPasswordAuthentication($passwordCheckedAt);
        if ($rememberMe) {
            Auth::setRememberCookie((int)$user->id, $_SESSION['auth_session_token']);
        } else {
            Auth::clearRememberCookie();
        }
        User::markLoggedIn((int)$user->id);
        Attachment::cleanupPendingForUser($user);
        $this->createSafariDesktopLoginNotice((int)$user->id, $userAgent);
        $_SESSION['last_activity_touch_at'] = time();
        $this->redirect('/');
    }

    public function show2FA() {
        $user = $this->requirePendingSecondFactor();
        $provider = TwoFAManager::getProviderByName($_SESSION['2fa_provider']);
        $this->view('auth/twofa', [
            'csrf' => $this->csrfToken(),
            'providerLabel' => $provider?->getLabel() ?? '',
            'mayTrustBrowser' => $provider?->getName() === 'totp' && TrustedBrowser::enabled($user),
        ]);
    }

    public function verify2FA() {
        Auth::csrfValidate();
        $pendingUser = $this->requirePendingSecondFactor();
        $userId = (int)$pendingUser->id;
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $providerName = $_SESSION['2fa_provider'];
        $verified = AuthChallenge::attempt($pendingUser, AuthChallenge::LOGIN, $_SESSION['2fa_challenge'], $ip,
            static function (object $account, array $challenge) use ($providerName, $code): bool {
                $provider = TwoFAManager::getAvailableProvider((int)$account->id);
                $context = $providerName === 'email' ? 'email:' . $account->email : $providerName;
                return $provider !== null && $provider->getName() === $providerName && $challenge['context'] === $context
                    && $provider->verifyCode((int)$account->id, $code, $challenge);
            });
        if ($verified === null) {
            $this->clearPendingTwoFactor();
            $this->flash('error', 'mfa_expired');
            $this->redirect('/login');
        }
        if (!$verified) {
            $this->flash('error', 'invalid');
            $this->redirect('/2fa');
        }

        $rememberMe = !empty($_SESSION['2fa_remember_me']);
        $trustBrowser = $providerName === 'totp' && ($_POST['trust_browser'] ?? null) === '1';
        $passwordBinding = $_SESSION['2fa_password_binding'];
        $passwordCheckedAt = $_SESSION['2fa_password_checked_at'] ?? null;
        $this->clearPendingTwoFactor();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->openBoundSession((int)$userId, $passwordBinding, $ip, $userAgent);
        if (is_int($passwordCheckedAt)) {
            Auth::recordPasswordAuthentication($passwordCheckedAt);
        }
        if ($rememberMe) {
            Auth::setRememberCookie((int)$userId, $_SESSION['auth_session_token']);
        } else {
            Auth::clearRememberCookie();
        }
        if ($trustBrowser) {
            TrustedBrowser::remember($userId, $_SESSION['auth_session_token']);
        }
        User::markLoggedIn((int)$userId);
        $authedUser = User::find((int)$userId);
        if ($authedUser) {
            Attachment::cleanupPendingForUser($authedUser);
        }
        $this->createSafariDesktopLoginNotice((int)$userId, $userAgent);
        $_SESSION['last_activity_touch_at'] = time();
        $this->redirect('/');
    }

    public function showRegister() {
        if (Auth::user()) {
            $this->redirect('/');
        }

        $inviteCodeRequired = (string)(Setting::get('invite_code_required') ?? '1') === '1';

        $this->view('auth/register', array_merge([
            'csrf' => $this->csrfToken(),
            'invitesEnabled' => $inviteCodeRequired
        ], $this->getCaptchaViewData()));
    }

    public function register() {
        Auth::csrfValidate();
        $this->verifyCaptchaOrFail('/register');
        $username = User::normalizeUsername($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rawPassword = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $inviteCodeRequired = (string)(Setting::get('invite_code_required') ?? '1') === '1';
        $emailVerificationRequired = (string)(Setting::get('email_verification_required') ?? '1') === '1';
        $inviteCode = trim($_POST['invite_code'] ?? '');

        if (!User::isUsernameFormatValid($username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($rawPassword) < 8) {
            $this->flash('error', 'invalid');
            $this->redirect('/register');
        }

        if ($inviteCodeRequired) {
            if ($this->hasTooManyAuthAttempts($ip, self::AUTH_ATTEMPT_REGISTER_INVITE_FAILED)) {
                $this->flash('error', 'too_many_attempts');
                $this->redirect('/register');
            }

            if (!preg_match('/^\d{4}-\d{4}$/', $inviteCode)) {
                $this->recordAuthAttempt($ip, self::AUTH_ATTEMPT_REGISTER_INVITE_FAILED);
                $this->flash('error', 'invalid_invite');
                $this->redirect('/register');
            }
        }

        $password = password_hash($rawPassword, PASSWORD_DEFAULT);
        $registration = $this->createRegistration(
            $username, $email, $password, $emailVerificationRequired, $inviteCodeRequired ? $inviteCode : null
        );
        if (isset($registration['error'])) {
            if ($registration['error'] === 'invalid_invite') {
                $this->recordAuthAttempt($ip, self::AUTH_ATTEMPT_REGISTER_INVITE_FAILED);
            }
            $this->flash('error', $registration['error']);
            $this->redirect('/register');
        }

        $newUserId = $registration['userId'];
        $referrerId = $registration['referrerId'];

        if ((string)(Setting::get('new_user_notification') ?? '0') === '1') {
            $referredBy = null;
            if ($referrerId !== null) {
                $referrer = Database::query("SELECT username FROM users WHERE id = ?", [$referrerId])->fetch();
                $referredBy = $referrer ? (string)$referrer->username : null;
            }
            $this->sendNewUserNotification($username, $email, $ip, $referredBy);
        }

        $newAccount = (object) ['id' => $newUserId, 'email' => $email, 'password' => $password];
        if ($emailVerificationRequired) {
            $this->sendVerificationCode($newAccount, 'sent');
            $this->redirect('/verify-email');
        }
        if (TwoFAManager::getAvailableProvider($newUserId) !== null) {
            $this->flash('success', 'registered');
            $this->redirect('/login');
        }

        $this->openBoundSession($newUserId, Auth::passwordBinding($newAccount), $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
        $newUser = User::find($newUserId);
        if ($newUser) {
            User::markLoggedIn($newUserId);
            Attachment::cleanupPendingForUser($newUser);
        }
        $_SESSION['last_activity_touch_at'] = time();
        $this->autoJoinNewUserToGroup($newUserId, $username);
        $this->redirect('/');
    }

    private function createRegistration(
        string $username, string $email, string $password, bool $verifyEmail, ?string $inviteCode
    ): array {
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $invite = null;
            if ($inviteCode !== null) {
                $invite = Database::query(
                    'SELECT id, creator_id FROM invite_codes WHERE code = ? AND used_by IS NULL AND used_at IS NULL FOR UPDATE',
                    [$inviteCode]
                )->fetch();
                if (!$invite) {
                    return ['error' => 'invalid_invite'];
                }
            }

            if (!User::isUsernameAvailableForUser($username)) {
                return ['error' => 'username_taken'];
            }
            if (User::findByEmail($email)) {
                return ['error' => 'email_taken'];
            }

            Database::query(
                'INSERT INTO users (username, email, password, user_number, email_verified_at) VALUES (?, ?, ?, ?, IF(?, NULL, NOW()))',
                [$username, $email, $password, $this->generateUserNumber(), (int)$verifyEmail]
            );
            $userId = (int)$connection->lastInsertId();
            User::recordUsernameHistory($userId, $username);

            if ($invite !== null) {
                // The timestamp survives deletion of the invited account.
                $consumed = Database::query(
                    'UPDATE invite_codes SET used_by = ?, used_at = NOW() WHERE id = ? AND used_by IS NULL AND used_at IS NULL',
                    [$userId, $invite->id]
                );
                if ($consumed->rowCount() !== 1) {
                    return ['error' => 'invalid_invite'];
                }
            }

            $connection->commit();
            return ['userId' => $userId, 'referrerId' => $invite === null ? null : (int)$invite->creator_id];
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
    }

    public function showVerifyEmail() {
        $loggedIn = Auth::user();
        if ($loggedIn && empty($loggedIn->email_verified_at)) {
            Auth::beginEmailVerification($loggedIn);
        }

        $pendingUser = $this->requirePendingEmailUser();
        if (!empty($pendingUser->email_verified_at)) {
            Auth::clearEmailVerification();
            $this->redirect('/login');
        }

        $this->view('auth/verify_email', [
            'csrf' => $this->csrfToken(),
            'email' => $pendingUser->email
        ]);
    }

    public function verifyEmail() {
        Auth::csrfValidate();
        $pendingUser = $this->requirePendingEmailUser();
        $pendingUserId = (int)$pendingUser->id;
        $id = $_SESSION['email_verification_challenge'] ?? '';
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $verified = AuthChallenge::attempt($pendingUser, AuthChallenge::VERIFY_EMAIL, $id, $_SERVER['REMOTE_ADDR'] ?? '',
            static function (object $account, array $challenge) use ($code): bool {
                if (!empty($account->email_verified_at) || $challenge['context'] !== $account->email
                    || !AuthChallenge::matchesCode($challenge, $code)
                ) {
                    return false;
                }

                Database::query('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$account->id]);
                return true;
            });
        if ($verified !== true) {
            $this->flash('error', $verified === null ? 'challenge_expired' : 'invalid');
            $this->redirect('/verify-email');
        }

        Auth::clearEmailVerification();
        $this->autoJoinNewUserToGroup($pendingUserId, (string)($pendingUser->username ?? ''));
        $this->flash('success', 'email_verified');
        $this->redirect('/login');
    }

    public function resendVerifyEmail() {
        Auth::csrfValidate();
        $pendingUser = $this->requirePendingEmailUser();
        if (!empty($pendingUser->email_verified_at)) {
            Auth::clearEmailVerification();
            $this->redirect('/login');
        }

        $this->sendVerificationCode($pendingUser, 'resent');
        $this->redirect('/verify-email');
    }

    public function logout() {
        Auth::csrfValidate(403);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        Auth::revokeCurrentSession();
        if ($userId > 0 && User::activeSessionCount($userId) === 0) {
            User::markLoggedOut($userId);
        }

        Auth::clearRememberCookie();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        $this->redirect('/login');
    }

    public function showForgot() {
        if (Auth::user()) {
            $this->redirect('/');
        }

        $this->view('auth/forgot', array_merge(['csrf' => $this->csrfToken()], $this->getCaptchaViewData()));
    }

    public function forgotPassword() {
        Auth::csrfValidate();
        $this->verifyCaptchaOrFail('/forgot-password');
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('success', 'sent');
            $this->redirect('/forgot-password');
        }

        $user = Database::query("SELECT id, email FROM users WHERE email = ?", [$email])->fetch();
        if (!$user || !AuthBudget::allow('reset_issue', (int)$user->id, $_SERVER['REMOTE_ADDR'] ?? '', 3)) {
            $this->flash('success', 'sent');
            $this->redirect('/forgot-password');
        }

        $token = AccountPassword::issueReset((int)$user->id, $user->email);
        if ($token !== null) {
            $resetLink = CAUSETTE_CANONICAL_URL . '/reset-password?token=' . rawurlencode($token);
            $safeLink = htmlspecialchars($resetLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $this->sendEmail($user->email, 'Causette Password Reset', "Reset your password: <a href=\"{$safeLink}\">{$safeLink}</a>");
        }

        $this->flash('success', 'sent');
        $this->redirect('/forgot-password');
    }

    public function showReset() {
        $token = $_GET['token'] ?? '';
        $this->viewRaw('auth/reset', [
            'csrf' => $this->csrfToken(),
            'token' => is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) ? $token : '',
        ]);
    }

    public function resetPassword() {
        Auth::csrfValidate();
        $token = $_POST['token'] ?? '';
        $newPassword = $_POST['password'] ?? '';
        $revokeApiKeys = $_POST['revoke_api_keys'] ?? '0';

        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', trim($token))
            || !is_string($newPassword) || strlen($newPassword) < 8 || str_contains($newPassword, "\0")
            || !in_array($revokeApiKeys, ['0', '1'], true)
            || !AccountPassword::reset(trim($token), $newPassword, $revokeApiKeys === '1')
        ) {
            $this->flash('error', 'invalid');
            $this->redirect('/forgot-password');
        }

        $this->flash('success', 'reset');
        $this->redirect('/login');
    }

    private function openBoundSession(int $userId, string $passwordBinding, string $ip, string $userAgent, ?string $trustedBrowser = null): void {
        $token = session_regenerate_id(true) ? Auth::registerBoundSession($userId, $passwordBinding, $ip, $userAgent, $trustedBrowser) : null;
        if ($token === null) {
            $this->flash('error', 'invalid');
            $this->redirect('/login');
        }

        unset($_SESSION['password_authentication'], $_SESSION['auth_session_retired'], $_SESSION['csrf_token']);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_session_token'] = $token;
    }

    private function clearPendingTwoFactor(): void {
        unset(
            $_SESSION['2fa_pending_user'],
            $_SESSION['2fa_remember_me'],
            $_SESSION['2fa_provider'],
            $_SESSION['2fa_password_checked_at'],
            $_SESSION['2fa_password_binding'],
            $_SESSION['2fa_challenge'],
        );
    }

    protected function prepareMfaChallenge(object $account, string $ip, ?string $code, string $context): ?string {
        return AuthChallenge::begin($account, AuthChallenge::LOGIN, $ip, $code, $context);
    }

    protected function cancelMfaChallenge(object $account, string $id): void {
        AuthChallenge::cancel($account, AuthChallenge::LOGIN, $id);
    }

    private function requirePendingSecondFactor(): object {
        $userId = (int)($_SESSION['2fa_pending_user'] ?? 0);
        $user = $userId > 0 ? User::find($userId) : null;
        $binding = $_SESSION['2fa_password_binding'] ?? '';
        $challenge = $_SESSION['2fa_challenge'] ?? '';
        if (!$user || User::isBanned($user) || !is_string($binding) || !hash_equals(Auth::passwordBinding($user), $binding)
            || !is_string($_SESSION['2fa_provider'] ?? null) || !is_string($challenge)
            || AuthChallenge::active($user, AuthChallenge::LOGIN, $challenge) === null
        ) {
            $this->clearPendingTwoFactor();
            $this->flash('error', 'mfa_expired');
            $this->redirect('/login');
        }
        return $user;
    }

    protected function sendEmail($to, $subject, $body): bool {
        $mailHost = (string)(Setting::get('mail_host') ?? '');
        $mailUser = (string)(Setting::get('mail_user') ?? '');

        if ($mailHost === '' || $mailUser === '') {
            return false;
        }

        $mailPort = (int)(Setting::get('mail_port') ?? 587);
        $mailFrom = (string)(Setting::get('mail_from') ?? '');
        $mailFromName = (string)(Setting::get('mail_from_name') ?? '');

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $mailHost;
            $mail->SMTPAuth = true;
            $mail->Username = $mailUser;
            $mail->Password = SecretSettings::read(SecretSettings::SMTP) ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $mailPort;
            $mail->Timeout = 10;

            $mail->setFrom($mailFrom, $mailFromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->Subject = $subject;
            $mail->Body = $body;
            return $mail->send();
        } catch (Exception | RuntimeException) {
            return false;
        }
    }

    private function sendNewUserNotification(string $username, string $email, string $ip, ?string $referredBy) {
        $admins = Database::query("SELECT id, email FROM users WHERE role = 'admin'")->fetchAll();
        DisplayPreferences::preloadRecipients(array_column($admins, 'id'));

        $details = [
            'mail.registration_username' => $username,
            'mail.registration_email' => $email,
            'mail.registration_ip' => $ip,
        ];

        foreach ($admins as $admin) {
            $locale = DisplayPreferences::localeForRecipient((int) $admin->id);
            $body = $locale->html('mail.registration_intro') . '<br><br>';
            foreach ($details as $label => $value) {
                $body .= '<b>' . $locale->html($label) . '</b> '
                    . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>';
            }
            $referrer = $referredBy === null
                ? $locale->html('mail.registration_no_referrer')
                : htmlspecialchars($referredBy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body .= '<b>' . $locale->html('mail.registration_referrer') . '</b> ' . $referrer;
            $this->sendEmail($admin->email, $locale->text('mail.registration_subject'), $body);
        }
    }

    private function generateUserNumber() {
        do {
            $userNumber = str_pad((string) random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
            $exists = User::findByUserNumber($userNumber);
        } while ($exists);

        return $userNumber;
    }

    private function sendVerificationCode(object $user, string $success): void {
        Auth::beginEmailVerification($user);
        $this->clearPendingTwoFactor();
        $code = AuthChallenge::generateCode();
        $id = AuthChallenge::begin($user, AuthChallenge::VERIFY_EMAIL, $_SERVER['REMOTE_ADDR'] ?? '', $code, $user->email);
        if ($id === null) {
            $this->flash('error', 'too_many_attempts');
            return;
        }

        if (!$this->sendEmail($user->email, 'Verify your Causette account', "Your verification code is <b>{$code}</b> (valid 10 minutes).")) {
            AuthChallenge::cancel($user, AuthChallenge::VERIFY_EMAIL, $id);
            $this->flash('error', 'delivery_failed');
            return;
        }

        $_SESSION['email_verification_challenge'] = $id;
        $this->flash('success', $success);
    }

    private function requirePendingEmailUser(): object {
        $user = Auth::pendingEmailVerificationUser();
        if (!$user) {
            $this->redirect('/login');
        }
        if (User::isBanned($user)) {
            Auth::clearEmailVerification();
            $this->flash('error', 'banned');
            $this->redirect('/login');
        }

        return $user;
    }

    private function createSafariDesktopLoginNotice(int $userId, string $userAgent): void {
        if (!$this->isSafariDesktopUserAgent($userAgent)) {
            return;
        }

        $locale = DisplayPreferences::localeForRecipient($userId);
        Notification::create(
            $userId,
            'report',
            $locale->text('notifications.browser_notice_title'),
            $locale->text('notifications.safari_desktop_notice'),
        );
    }

    private function isSafariDesktopUserAgent(string $userAgent): bool {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return false;
        }

        $isMobile = str_contains($ua, 'mobile')
            || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipad')
            || str_contains($ua, 'ipod')
            || str_contains($ua, 'android');
        if ($isMobile) {
            return false;
        }

        $containsSafari = str_contains($ua, 'safari');
        $isNotSafariEngineWrapper = !str_contains($ua, 'chrome')
            && !str_contains($ua, 'chromium')
            && !str_contains($ua, 'crios')
            && !str_contains($ua, 'fxios')
            && !str_contains($ua, 'edg')
            && !str_contains($ua, 'opr')
            && !str_contains($ua, 'opera');

        return $containsSafari && $isNotSafariEngineWrapper;
    }

    private function verifyCaptchaOrFail(string $redirectTo): void {
        $provider = CaptchaManager::getActiveProvider();
        if ($provider === null) {
            return;
        }

        $token = trim((string)($_POST[$provider->getTokenFieldName()] ?? ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$provider->verify($token, $ip)) {
            $this->flash('error', 'captcha_failed');
            $this->redirect($redirectTo);
        }
    }

    private function getCaptchaViewData(): array {
        $provider = CaptchaManager::getActiveProvider();
        if ($provider === null) {
            return ['captchaWidgetHtml' => '', 'captchaScriptUrl' => ''];
        }

        $siteKey = trim((string)(Setting::get('captcha_site_key') ?? ''));
        return [
            'captchaWidgetHtml' => $provider->getWidgetHtml($siteKey),
            'captchaScriptUrl' => $provider->getScriptUrl(),
        ];
    }

    private function hasTooManyAuthAttempts($ip, $actionType) {
        $this->cleanupOldAuthAttempts();

        $attemptCount = (int)Database::query(
            "SELECT COUNT(*) FROM auth_attempt_limits WHERE ip_address = ? AND action_type = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)",
            [(string)$ip, (string)$actionType]
        )->fetchColumn();

        return $attemptCount >= self::AUTH_ATTEMPT_LIMIT_PER_MINUTE;
    }

    private function recordAuthAttempt($ip, $actionType) {
        $this->cleanupOldAuthAttempts();

        Database::query(
            "INSERT INTO auth_attempt_limits (ip_address, action_type) VALUES (?, ?)",
            [(string)$ip, (string)$actionType]
        );
    }

    private function cleanupOldAuthAttempts() {
        Database::query(
            "DELETE FROM auth_attempt_limits WHERE created_at < (NOW() - INTERVAL 1 MINUTE)"
        );
    }

    private function autoJoinNewUserToGroup(int $userId, string $username): void {
        $autoJoinGroupId = (string)(Setting::get('new_user_auto_join_group') ?? '');
        if ($autoJoinGroupId === '' || $autoJoinGroupId === '0') {
            return;
        }

        $chatId = (int)$autoJoinGroupId;

        $chatQuery = "SELECT id FROM chats WHERE id = ? AND type = 'group'";
        if (Chat::supportsSoftDelete()) {
            $chatQuery .= " AND deleted_at IS NULL";
        }
        $chat = Database::query($chatQuery, [$chatId])->fetch();
        if (!$chat) {
            return;
        }

        $memberCount = (int)Database::query(
            "SELECT COUNT(*) FROM chat_members WHERE chat_id = ?",
            [$chatId]
        )->fetchColumn();
        if ($memberCount >= 200) {
            return;
        }

        Database::query(
            "INSERT IGNORE INTO chat_members (chat_id, user_id) VALUES (?, ?)",
            [$chatId, $userId]
        );

        $supportsSystemEvents = (int)Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_system_events'"
        )->fetchColumn() > 0;

        if ($supportsSystemEvents) {
            $normalizedUsername = User::normalizeUsername($username);
            if ($normalizedUsername !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'user_auto_joined', ?)",
                    [$chatId, $normalizedUsername . ' joined via auto-join']
                );
            }
        }
    }
}
