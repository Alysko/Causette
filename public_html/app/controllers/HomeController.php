<?php
require_once __DIR__ . '/../modules/2fa/totp/TOTP.php';
require_once __DIR__ . '/../../vendor/phpqrcode/qrcode.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../core/AccountPassword.php';
require_once __DIR__ . '/../core/SecretSettings.php';

use Causette\Security\AccountPassword;
use Causette\Security\AuthBudget;
use Causette\Security\AuthChallenge;
use Causette\Security\BrowserSession;
use Causette\Security\SecretSettings;
use Causette\Security\TrustedBrowser;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class HomeController extends Controller {
    public function index() {
        Auth::requireAuth();
        $userId = Auth::user()->id;
        $selectedTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
        if (!in_array($selectedTab, ['favorites', 'online', 'all', 'requests'], true)) {
            $selectedTab = 'all';
        }

        $selectedRequestsTab = strtolower(trim((string)($_GET['requests'] ?? 'incoming')));
        if (!in_array($selectedRequestsTab, ['incoming', 'outgoing'], true)) {
            $selectedRequestsTab = 'incoming';
        }

        $friends = Database::query(
            "SELECT u.*, f.status,
                    CASE WHEN ff.id IS NULL THEN 0 ELSE 1 END AS is_favorite
             FROM friends f
             JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id)
             LEFT JOIN friend_favorites ff ON ff.user_id = ? AND ff.favorite_user_id = u.id
             WHERE (f.user_id = ? OR f.friend_id = ?) AND u.id != ? AND f.status = 'accepted'
             ORDER BY u.username ASC",
            [$userId, $userId, $userId, $userId]
        )->fetchAll();
        $pendingIncoming = Database::query(
            "SELECT f.user_id AS requester_id,
                    u.id,
                    u.username,
                    u.user_number,
                    u.avatar_filename,
                    u.presence_status,
                    u.last_active_at,
                    f.created_at
             FROM friends f
             JOIN users u ON f.user_id = u.id
             WHERE f.friend_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC",
            [$userId]
        )->fetchAll();
        $pendingOutgoing = Database::query(
            "SELECT f.friend_id AS target_user_id,
                u.id,
                    u.username,
                    u.user_number,
                    u.avatar_filename,
                    u.presence_status,
                    u.last_active_at,
                    f.created_at
             FROM friends f
             JOIN users u ON f.friend_id = u.id
             WHERE f.user_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC",
            [$userId]
        )->fetchAll();
        $incomingRequestCount = count($pendingIncoming);

        User::attachEffectiveStatusList($friends);
        User::attachEffectiveStatusList($pendingIncoming);
        User::attachEffectiveStatusList($pendingOutgoing);

        $visibleFriends = $friends;
        if ($selectedTab === 'favorites') {
            $visibleFriends = array_values(array_filter($friends, static function($friend) {
                return (int)($friend->is_favorite ?? 0) === 1;
            }));
        } elseif ($selectedTab === 'online') {
            $visibleFriends = array_values(array_filter($friends, static function($friend) {
                return ($friend->effective_status ?? 'offline') !== 'offline';
            }));
        }

        $lastGroupChatQuery = "SELECT c.id,
                                      c.chat_number,
                                      c.title,
                                      c.created_at,
                                      (SELECT m.created_at FROM messages m WHERE m.chat_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_message_at
                               FROM chats c
                               JOIN chat_members cm ON cm.chat_id = c.id
                               WHERE cm.user_id = ?
                                 AND c.type = 'group'";
        if (Chat::supportsSoftDelete()) {
            $lastGroupChatQuery .= " AND c.deleted_at IS NULL";
        }
        $lastGroupChatQuery .= "
                               ORDER BY COALESCE(last_message_at, c.created_at) DESC
                               LIMIT 1";

        $lastGroupChat = Database::query($lastGroupChatQuery, [$userId])->fetch();
        if ($lastGroupChat) {
            $lastGroupChat->chat_number_formatted = User::formatUserNumber((string)$lastGroupChat->chat_number);
            $customTitle = trim((string)($lastGroupChat->title ?? ''));
            $lastGroupChat->chat_title = $customTitle !== '' ? $customTitle : $lastGroupChat->chat_number_formatted;
        }

        $invitesEnabled = (string)(Setting::get('invites_enabled') ?? '1') === '1';
        $announcementMessage = trim((string)(Setting::get('announcement_message') ?? ''));
        $announcementStyle = trim((string)(Setting::get('announcement_style') ?? 'orange'));
        $inviteReferralPrompt = $this->getInviteReferralPrompt((int)$userId);

        $recentFriendPosts = Post::getFriendsFeed((int)$userId, 3);

        $this->view('dashboard', [
            'friends' => $friends,
            'visibleFriends' => $visibleFriends,
            'lastGroupChat' => $lastGroupChat,
            'pendingIncoming' => $pendingIncoming,
            'pendingOutgoing' => $pendingOutgoing,
            'incomingRequestCount' => $incomingRequestCount,
            'selectedTab' => $selectedTab,
            'selectedRequestsTab' => $selectedRequestsTab,
            'invitesEnabled' => $invitesEnabled,
            'announcementMessage' => $announcementMessage,
            'announcementStyle' => $announcementStyle,
            'inviteReferralPrompt' => $inviteReferralPrompt,
            'recentFriendPosts' => $recentFriendPosts,
            'csrf' => $this->csrfToken()
        ]);
    }

    private function getInviteReferralPrompt($userId) {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }

        $sessionKey = 'invite_referral_prompt_seen_' . $userId;
        if (!empty($_SESSION[$sessionKey])) {
            return null;
        }

        $sessionCount = (int)Database::query(
            "SELECT COUNT(*) FROM user_sessions WHERE user_id = ?",
            [$userId]
        )->fetchColumn();

        if ($sessionCount !== 1) {
            $_SESSION[$sessionKey] = 1;
            return null;
        }

        $referrer = Database::query(
            "SELECT inviter.id, inviter.username, inviter.user_number
             FROM invite_codes ic
             JOIN users inviter ON inviter.id = ic.creator_id
             WHERE ic.used_by = ?
             ORDER BY ic.used_at ASC, ic.id ASC
             LIMIT 1",
            [$userId]
        )->fetch();

        if (!$referrer) {
            $_SESSION[$sessionKey] = 1;
            return null;
        }

        $friendship = Database::query(
            "SELECT status
             FROM friends
             WHERE (user_id = ? AND friend_id = ?)
                OR (user_id = ? AND friend_id = ?)
             LIMIT 1",
            [$userId, (int)$referrer->id, (int)$referrer->id, $userId]
        )->fetch();

        if ($friendship && in_array((string)($friendship->status ?? ''), ['pending', 'accepted'], true)) {
            $_SESSION[$sessionKey] = 1;
            return null;
        }

        $_SESSION[$sessionKey] = 1;

        return [
            'user_id' => (int)$referrer->id,
            'username' => (string)$referrer->username,
            'user_number' => User::formatUserNumber((string)$referrer->user_number),
        ];
    }

    public function posts() {
        Auth::requireAuth();
        $userId = (int)Auth::user()->id;
        $selectedPostScope = strtolower(trim((string)($_GET['scope'] ?? 'friends')));
        if (!in_array($selectedPostScope, ['friends', 'server'], true)) {
            $selectedPostScope = 'friends';
        }

        $postsPerPage = 10;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));

        if ($selectedPostScope === 'server') {
            $totalPosts = Post::countServerFeed();
            $totalPages = max(1, (int)ceil($totalPosts / $postsPerPage));
            $currentPage = min($currentPage, $totalPages);
            $posts = Post::getServerFeed($userId, $postsPerPage, ($currentPage - 1) * $postsPerPage);
        } else {
            $totalPosts = Post::countFriendsFeed($userId);
            $totalPages = max(1, (int)ceil($totalPosts / $postsPerPage));
            $currentPage = min($currentPage, $totalPages);
            $posts = Post::getFriendsFeed($userId, $postsPerPage, ($currentPage - 1) * $postsPerPage);
        }

        $this->view('posts', [
            'posts' => $posts,
            'selectedPostScope' => $selectedPostScope,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalPosts' => $totalPosts,
            'csrf' => $this->csrfToken()
        ]);
    }

    public function search() {
        Auth::requireAuth();

        $mode = strtolower(trim((string)($_GET['mode'] ?? 'users')));
        if (!in_array($mode, ['users', 'messages', 'posts'], true)) {
            $mode = 'users';
        }

        $this->view('search', [
            'mode' => $mode,
            'csrf' => $this->csrfToken()
        ]);
    }

    public function exitSession() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if (!$user || $sessionId <= 0) {
            $this->flash('error', 'session_not_found');
            $this->redirect('/settings');
        }

        $session = Database::query(
            'SELECT id, session_token FROM user_sessions WHERE id = ? AND user_id = ? AND ' . BrowserSession::AVAILABLE,
            [$sessionId, $user->id]
        )->fetch();

        if (!$session) {
            $this->flash('error', 'session_not_found');
            $this->redirect('/settings');
        }

        $currentDigest = BrowserSession::sessionDigest($_SESSION['auth_session_token'] ?? null);
        $isCurrentSession = $currentDigest !== null && hash_equals($currentDigest, (string)$session->session_token);

        if (!User::revokeSessionById((int)$user->id, $sessionId)) {
            $this->flash('error', 'session_not_found');
            $this->redirect('/settings');
        }

        if (User::activeSessionCount((int)$user->id) === 0) {
            User::markLoggedOut((int)$user->id);
        }

        if ($isCurrentSession) {
            Auth::clearRememberCookie();
            session_regenerate_id(true);
            $_SESSION = [];
            $this->flash('success', 'session_exited');
            $this->redirect('/login');
        }

        $this->flash('success', 'session_exited');
        $this->redirect('/settings');
    }

    public function generateInvite() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $invitesEnabled = (string)(Setting::get('invites_enabled') ?? '1') === '1';
        if (!$invitesEnabled) {
            $this->flash('error', 'invite_disabled');
            $this->redirect('/settings');
        }

        $inviteLimit = (int) (Setting::get('invite_codes_per_user') ?? 0);
        if ($inviteLimit <= 0) {
            $this->flash('error', 'invite_disabled');
            $this->redirect('/settings');
        }

        $inviteCount = (int) Database::query("SELECT COUNT(*) FROM invite_codes WHERE creator_id = ?", [$user->id])->fetchColumn();
        if ($inviteCount >= $inviteLimit) {
            $this->flash('error', 'invite_limit');
            $this->redirect('/settings');
        }

        $newCode = $this->generateInviteCode();
        Database::query("INSERT INTO invite_codes (code, creator_id) VALUES (?, ?)", [$newCode, $user->id]);

        $this->flash('success', 'invite_created');
        $this->redirect('/settings');
    }

    public function deleteInvite() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $invitesEnabled = (string)(Setting::get('invites_enabled') ?? '1') === '1';
        if (!$invitesEnabled) {
            $this->flash('error', 'invite_disabled');
            $this->redirect('/settings');
        }

        $user = Auth::user();
        $inviteCode = trim($_POST['invite_code'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $inviteCode)) {
            $this->flash('error', 'invite_delete_unavailable');
            $this->redirect('/settings');
        }

        $invite = Database::query(
            "SELECT id FROM invite_codes WHERE code = ? AND creator_id = ? AND used_by IS NULL LIMIT 1",
            [$inviteCode, $user->id]
        )->fetch();

        if (!$invite) {
            $this->flash('error', 'invite_delete_unavailable');
            $this->redirect('/settings');
        }

        $deleted = Database::query(
            "DELETE FROM invite_codes WHERE id = ? AND creator_id = ? AND used_by IS NULL",
            [$invite->id, $user->id]
        );

        if ($deleted->rowCount() < 1) {
            $this->flash('error', 'invite_delete_unavailable');
            $this->redirect('/settings');
        }

        $this->flash('success', 'invite_deleted');
        $this->redirect('/settings');
    }

    public function saveAccountEmail() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $this->requireSettingsPassword($user, 'email_change_password_invalid');
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->flash('error', 'email_invalid');
            $this->redirect('/settings');
        }

        if (strcasecmp($email, (string)$user->email) === 0) {
            AuthChallenge::cancel($user, AuthChallenge::CHANGE_EMAIL, $_SESSION['email_change_challenge'] ?? '');
            unset($_SESSION['email_change_challenge']);
            $this->flash('success', 'email_saved');
            $this->redirect('/settings');
        }

        if (User::findByEmail($email)) {
            $this->flash('error', 'email_taken');
            $this->redirect('/settings');
        }

        $code = AuthChallenge::generateCode();
        $id = AuthChallenge::begin($user, AuthChallenge::CHANGE_EMAIL, $_SERVER['REMOTE_ADDR'] ?? '', $code, $email);
        if ($id === null) {
            $this->flash('error', 'email_change_limit');
            $this->redirect('/settings');
        }
        if (!$this->sendEmail(
            $email,
            'Confirm your new Causette email',
            "Your email change verification code is <b>{$code}</b> (valid 10 minutes)."
        )) {
            AuthChallenge::cancel($user, AuthChallenge::CHANGE_EMAIL, $id);
            $this->flash('error', 'email_change_delivery_failed');
            $this->redirect('/settings');
        }

        $_SESSION['email_change_challenge'] = $id;
        $this->flash('success', 'email_change_sent');
        $this->redirect('/settings');
    }

    public function verifyAccountEmailChange() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
        }
        $id = $_SESSION['email_change_challenge'] ?? '';
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        try {
            $verified = AuthChallenge::attempt($user, AuthChallenge::CHANGE_EMAIL, $id, $_SERVER['REMOTE_ADDR'] ?? '',
                static function (object $account, array $challenge) use ($code): bool {
                    if (!AuthChallenge::matchesCode($challenge, $code)) {
                        return false;
                    }

                    Database::query('UPDATE users SET email = ?, email_verified_at = NOW() WHERE id = ?',
                        [$challenge['context'], $account->id]);
                    Database::query('DELETE FROM password_resets WHERE user_id = ?', [$account->id]);
                    return true;
                });
        } catch (PDOException $failure) {
            if ($failure->getCode() !== '23000') {
                throw $failure;
            }
            AuthChallenge::cancel($user, AuthChallenge::CHANGE_EMAIL, $id);
            $this->flash('error', 'email_taken');
            $this->redirect('/settings');
        }
        if ($verified !== true) {
            $this->flash('error', $verified === null ? 'email_change_expired' : 'email_change_code_invalid');
            $this->redirect('/settings');
        }
        unset($_SESSION['email_change_challenge']);
        $this->flash('success', 'email_saved');
        $this->redirect('/settings');
    }

    public function saveAccountPassword() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
        }
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($currentPassword) || !password_verify($currentPassword, (string)$user->password)) {
            $this->flash('error', 'password_current_invalid');
            $this->redirect('/settings');
        }

        if (!is_string($newPassword) || strlen($newPassword) < 8 || str_contains($newPassword, "\0")) {
            $this->flash('error', 'password_invalid');
            $this->redirect('/settings');
        }

        if (!is_string($confirmPassword) || !hash_equals($newPassword, $confirmPassword)) {
            $this->flash('error', 'password_mismatch');
            $this->redirect('/settings');
        }

        if (!session_regenerate_id(true)) {
            ErrorHandler::abort(500, 'Could not renew the session');
        }
        $newSessionToken = AccountPassword::change(
            (int)$user->id,
            Auth::passwordBinding($user),
            (string)($_SESSION['auth_session_token'] ?? ''),
            $newPassword,
        );
        if ($newSessionToken === null) {
            $this->flash('error', 'password_current_invalid');
            $this->redirect('/settings');
        }

        $_SESSION['auth_session_token'] = $newSessionToken;
        unset($_SESSION['csrf_token']);
        Auth::recordPasswordAuthentication(time());
        Auth::clearRememberCookie();
        TrustedBrowser::clearCookie();
        $this->flash('success', 'password_saved');
        $this->redirect('/settings');
    }

    public function saveProfileUsername() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $username = User::normalizeUsername($_POST['username'] ?? '');

        if (!User::isUsernameFormatValid($username)) {
            $this->flash('error', 'username_invalid');
            $this->redirect('/settings');
        }

        if ($username === User::normalizeUsername((string)$user->username)) {
            $this->flash('error', 'username_same');
            $this->redirect('/settings');
        }

        $usernameChangeAvailableAt = $this->usernameChangeAvailableAt($user);
        if ($usernameChangeAvailableAt !== null) {
            $this->flash('error', 'username_cooldown');
            $this->redirect('/settings');
        }

        if (!User::isUsernameAvailableForUser($username, (int)$user->id, (string)$user->username)) {
            $this->flash('error', 'username_taken');
            $this->redirect('/settings');
        }

        Database::query(
            "UPDATE users SET username = ?, username_changed_at = NOW() WHERE id = ?",
            [$username, $user->id]
        );
        User::recordUsernameHistory((int)$user->id, $username);

        $this->flash('success', 'username_saved');
        $this->redirect('/settings');
    }

    public function saveAvatarSettings() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $userId = Auth::user()->id;
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
            if ($isAjax) {
                $this->json(['error' => 'avatar_upload_failed'], 400);
            }
            $this->flash('error', 'avatar_upload_failed');
            $this->redirect('/settings');
        }

        $avatarError = $this->handleAvatarUpload($userId, $_FILES['avatar']);
        if ($avatarError !== null) {
            if ($isAjax) {
                $this->json(['error' => $avatarError], 400);
            }
            $this->flash('error', $avatarError);
            $this->redirect('/settings');
        }

        if ($isAjax) {
            $updatedUser = Database::query("SELECT * FROM users WHERE id = ?", [$userId])->fetch();
            $avatarUrl = User::avatarUrl($updatedUser);
            $this->json(['success' => true, 'avatar_url' => $avatarUrl]);
        }

        $this->flash('success', 'avatar_saved');
        $this->redirect('/settings');
    }

    public function deleteAvatarSettings() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $userId = Auth::user()->id;
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        $this->removeAvatarForUser($userId);

        if ($isAjax) {
            $this->json(['success' => true]);
        }

        $this->flash('success', 'avatar_removed');
        $this->redirect('/settings');
    }

    public function saveNotificationSettings() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $userId = (int)Auth::user()->id;

        $allowedSettings = [
            'browser_notifications' => 'browser_notifications_',
            'web_push_notifications' => 'web_push_notifications_',
            'sound_poke' => 'sound_poke_',
            'sound_friend_request' => 'sound_poke_',
            'sound_new_message' => 'sound_new_message_',
            'sound_other_notifications' => 'sound_other_notifications_',
            'sound_outgoing_call_ring' => 'sound_outgoing_call_ring_'
        ];

        $requestedSetting = trim((string)($_POST['setting'] ?? ''));
        if ($requestedSetting !== '') {
            if (!isset($allowedSettings[$requestedSetting])) {
                $this->json(['error' => 'Invalid notification setting'], 400);
            }

            $enabledRaw = (string)($_POST['enabled'] ?? '0');
            $enabled = $enabledRaw === '1' ? '1' : '0';
            Setting::set($allowedSettings[$requestedSetting] . $userId, $enabled);

            $this->json([
                'success' => true,
                'setting' => $requestedSetting,
                'enabled' => $enabled === '1'
            ]);
        }

        $enableBrowser = isset($_POST['browser_notifications']) ? '1' : '0';
        Setting::set('browser_notifications_' . $userId, $enableBrowser);

        $acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($isAjax || strpos($acceptHeader, 'application/json') !== false) {
            $this->json(['success' => true, 'setting' => 'browser_notifications', 'enabled' => $enableBrowser === '1']);
        }

        $this->flash('success', 'notifications_saved');
        $this->redirect('/settings');
    }

    public function saveTimezoneSettings() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $userId = (int)Auth::user()->id;

        $allowedTimezones = TimezoneCatalog::getAllowedOffsets();

        $timezone = trim((string)($_POST['timezone'] ?? 'UTC+0'));
        if (!in_array($timezone, $allowedTimezones, true)) {
            $timezone = 'UTC+0';
        }

        Setting::set('timezone_' . $userId, $timezone);

        $acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($isAjax || strpos($acceptHeader, 'application/json') !== false) {
            $this->json([
                'success' => true,
                'timezone' => $timezone
            ]);
        }

        $this->flash('success', 'timezone_saved');
        $this->redirect('/settings');
    }

    public function savePreferencesSettings() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $userId = (int)Auth::user()->id;

        $topLoadingBarEnabled = isset($_POST['top_loading_bar_enabled']) ? '1' : '0';
        Setting::set('top_loading_bar_enabled_' . $userId, $topLoadingBarEnabled);

        $systemMessagesAutoCombineEnabled = isset($_POST['system_messages_auto_combine']) ? '1' : '0';
        Setting::set('system_messages_auto_combine_' . $userId, $systemMessagesAutoCombineEnabled);

        $fontSizePreference = strtolower(trim((string)($_POST['font_size'] ?? 'default')));
        if (!in_array($fontSizePreference, ['default', 'large'], true)) {
            $fontSizePreference = 'default';
        }
        Setting::set('font_size_' . $userId, $fontSizePreference);

        $this->flash('success', 'preferences_saved');
        $this->redirect('/settings');
    }

    private function usernameChangeAvailableAt($user) {
        if (!$user || empty($user->username_changed_at)) {
            return null;
        }

        $changedAt = strtotime((string)$user->username_changed_at);
        if ($changedAt === false) {
            return null;
        }

        $availableAt = strtotime('+30 days', $changedAt);
        if ($availableAt === false || time() >= $availableAt) {
            return null;
        }

        return date('F j, Y \a\t g:i A', $availableAt);
    }

    private function removeAvatarForUser($userId) {
        $oldFilename = Database::query("SELECT avatar_filename FROM users WHERE id = ?", [$userId])->fetchColumn();
        $safeOldFilename = $this->sanitizeAvatarFilename((string)$oldFilename);
        Database::query("UPDATE users SET avatar_filename = NULL WHERE id = ?", [$userId]);

        if ($safeOldFilename) {
            $storageRoot = rtrim((string)(defined('STORAGE_FILESYSTEM_ROOT') ? STORAGE_FILESYSTEM_ROOT : (dirname(__DIR__, 3) . '/storage')), '/');
            $oldPath = $storageRoot . '/avatars/' . $safeOldFilename;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            return true;
        }

        return false;
    }

    private function handleAvatarUpload($userId, $avatarFile) {
        $errorCode = (int)($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            return 'avatar_upload_failed';
        }

        $tmpPath = $avatarFile['tmp_name'] ?? '';
        if (!is_string($tmpPath) || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return 'avatar_upload_failed';
        }

        $imageInfo = @getimagesize($tmpPath);
        if (!$imageInfo) {
            return 'avatar_invalid_type';
        }

        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        $allowedMime = ['image/jpeg', 'image/png'];

        if (!in_array($mime, $allowedMime, true)) {
            return 'avatar_invalid_type';
        }

        $width = (int)($imageInfo[0] ?? 0);
        $height = (int)($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1) {
            return 'avatar_upload_failed';
        }

        if ($width > 2048 || $height > 2048) {
            return 'avatar_too_large';
        }

        if ($mime === 'image/jpeg') {
            $src = @imagecreatefromjpeg($tmpPath);
        } else {
            $src = @imagecreatefrompng($tmpPath);
        }

        if (!$src) {
            return 'avatar_upload_failed';
        }

        $targetSize = 256;
        $needsResize = ($width !== $height || $width > $targetSize || $height > $targetSize);

        if ($needsResize) {
            $squareSize = min($width, $height);
            $srcX = (int)(($width - $squareSize) / 2);
            $srcY = (int)(($height - $squareSize) / 2);

            $dst = imagecreatetruecolor($targetSize, $targetSize);
            if (!$dst) {
                return 'avatar_upload_failed';
            }

            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $targetSize - 1, $targetSize - 1, $transparent);
            imagealphablending($dst, true);

            if (!imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetSize, $targetSize, $squareSize, $squareSize)) {
                return 'avatar_upload_failed';
            }

            $finalImage = $dst;
        } else {
            $finalImage = $src;
        }

        $storageRoot = rtrim((string)(defined('STORAGE_FILESYSTEM_ROOT') ? STORAGE_FILESYSTEM_ROOT : (dirname(__DIR__, 3) . '/storage')), '/');
        $avatarsDir = $storageRoot . '/avatars';
        if (!is_dir($avatarsDir) && !mkdir($avatarsDir, 0755, true) && !is_dir($avatarsDir)) {
            return 'avatar_upload_failed';
        }

        $userRow = Database::query("SELECT user_number FROM users WHERE id = ?", [(int)$userId])->fetch();
        $userNumber = (string)($userRow->user_number ?? '');
        if (!preg_match('/^\d{16}$/', $userNumber)) {
            return 'avatar_upload_failed';
        }

        $newFilename = $userNumber . '.png';
        $targetPath = $avatarsDir . '/' . $newFilename;

        $saveResult = imagepng($finalImage, $targetPath, 6);

        if (!$saveResult) {
            return 'avatar_upload_failed';
        }

        @chmod($targetPath, 0644);

        $oldFilename = Database::query("SELECT avatar_filename FROM users WHERE id = ?", [$userId])->fetchColumn();
        Database::query("UPDATE users SET avatar_filename = ? WHERE id = ?", [$newFilename, $userId]);

        $safeOldFilename = $this->sanitizeAvatarFilename((string)$oldFilename);
        if ($safeOldFilename && $safeOldFilename !== $newFilename) {
            $oldPath = $avatarsDir . '/' . $safeOldFilename;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return null;
    }

    private function sanitizeAvatarFilename($filename) {
        $filename = trim($filename);
        if ($filename === '') {
            return null;
        }

        $safeFilename = basename($filename);
        if ($safeFilename !== $filename) {
            return null;
        }

        if (!preg_match('/^\d{16}\.png$/', $safeFilename)) {
            return null;
        }

        return $safeFilename;
    }

    private function generateInviteCode() {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
            $exists = Database::query("SELECT id FROM invite_codes WHERE code = ?", [$code])->fetch();
            if (!$exists) {
                return $code;
            }
        }

        throw new RuntimeException('Failed to generate a unique invite code.');
    }

    private function requireSettingsPassword(?object $account, string $error): void {
        if (!$account) {
            $this->redirect('/login');
        }
        $password = $_POST['password'] ?? '';
        if (!AuthBudget::allow('settings_password', (int)$account->id, $_SERVER['REMOTE_ADDR'] ?? '', 8)
            || !is_string($password) || !password_verify($password, (string)$account->password)
        ) {
            $this->flash('error', $error);
            $this->redirect('/settings');
        }
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
            $mail->Subject = $subject;
            $mail->Body = $body;
            return $mail->send();
        } catch (Exception | RuntimeException) {
            return false;
        }
    }

    public function totpSetupBegin() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $this->requireSettingsPassword($user, 'totp_password_invalid');
        $userId = (int)$user->id;
        if (Database::query('SELECT id FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId])->fetch()) {
            $this->flash('error', 'totp_already_enabled');
            $this->redirect('/settings');
        }

        $secret = TOTP::generateSecret();
        $id = AuthChallenge::begin($user, AuthChallenge::ENROLL_TOTP, $_SERVER['REMOTE_ADDR'] ?? '', null, hash('sha256', $secret));
        if ($id === null) {
            $this->flash('error', 'totp_challenge_limit');
            $this->redirect('/settings');
        }

        $_SESSION['totp_setup_secret'] = $secret;
        $_SESSION['totp_setup_challenge'] = $id;
        $this->flash('success', 'totp_setup_started');
        $this->redirect('/settings');
    }

    public function totpSetupConfirm() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
        }
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        $id = $_SESSION['totp_setup_challenge'] ?? '';
        $code = is_string($_POST['totp_code'] ?? null) ? trim($_POST['totp_code']) : '';
        $codes = [];
        $verified = AuthChallenge::attempt($user, AuthChallenge::ENROLL_TOTP, $id, $_SERVER['REMOTE_ADDR'] ?? '',
            static function (object $account, array $challenge) use ($secret, $code, &$codes): bool {
                if ($secret === '' || !hash_equals($challenge['context'], hash('sha256', $secret))
                    || preg_match('/\A[0-9]{6}\z/', $code) !== 1
                ) {
                    return false;
                }

                $step = TOTP::matchingStep($secret, $code);
                $userId = (int)$account->id;
                if ($step === null || Database::query(
                    'SELECT id FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId]
                )->fetch() || !TOTP::consumeStep($userId, $step)) {
                    return false;
                }

                Database::query('DELETE FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NULL', [$userId]);
                Database::query('INSERT INTO totp_secrets (user_id, secret_encrypted, confirmed_at) VALUES (?, ?, NOW())',
                    [$userId, TOTP::encrypt($secret)]);
                Database::query('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
                $codes = TOTP::generateRecoveryCodes();
                foreach ($codes as $recoveryCode) {
                    Database::query('INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)',
                        [$userId, hash('sha256', $recoveryCode)]);
                }
                TrustedBrowser::revokeForAccount($userId);
                return true;
            });

        if ($verified !== false) {
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_challenge']);
        }
        if ($verified !== true) {
            $this->flash('error', $verified === null ? 'totp_setup_expired' : 'totp_invalid_code');
            $this->redirect('/settings');
        }

        $_SESSION['totp_recovery_codes'] = $codes;
        $this->flash('success', 'totp_enabled');
        $this->redirect('/settings');
    }

    public function totpDisable() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $this->requireSettingsPassword($user, 'totp_password_invalid');
        $userId = (int)$user->id;

        $changed = AuthChallenge::withAccount($user, static function () use ($userId): bool {
            Database::query('DELETE FROM totp_secrets WHERE user_id = ?', [$userId]);
            Database::query('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
            Database::query('DELETE FROM settings WHERE `key` IN (?, ?)', ['twofa_frequency_' . $userId, 'auth_totp_step_' . $userId]);
            TrustedBrowser::revokeForAccount($userId);
            AuthChallenge::clearForAccount($userId);
            return true;
        });

        if ($changed !== true) {
            $this->flash('error', 'totp_password_invalid');
            $this->redirect('/settings');
        }
        TrustedBrowser::clearCookie();
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_challenge'], $_SESSION['totp_recovery_codes']);
        $this->flash('success', 'totp_disabled');
        $this->redirect('/settings');
    }

    public function saveTwofaFrequency() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        $this->requireSettingsPassword($user, 'totp_password_invalid');
        $userId = (int)$user->id;
        $frequency = $_POST['frequency'] ?? '';
        if (!in_array($frequency, ['always', 'trusted'], true) || ($user->role === 'admin' && $frequency === 'trusted')) {
            $this->flash('error', 'totp_frequency_invalid');
            $this->redirect('/settings');
        }

        $changed = AuthChallenge::withAccount($user, static function () use ($userId, $frequency): bool {
            if (!Database::query('SELECT id FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId])->fetch()) {
                return false;
            }

            Setting::set('twofa_frequency_' . $userId, $frequency);
            if ($frequency === 'always') {
                TrustedBrowser::revokeForAccount($userId);
            }
            return true;
        });
        if ($changed !== true) {
            $this->flash('error', 'totp_frequency_invalid');
            $this->redirect('/settings');
        }
        if ($frequency === 'always') {
            TrustedBrowser::clearCookie();
        }
        $this->flash('success', 'totp_frequency_saved');
        $this->redirect('/settings');
    }

    public function revokeTrustedBrowsers() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $user = Auth::user();
        if (!$user) {
            $this->redirect('/login');
        }
        AuthChallenge::withAccount($user, static function (object $locked): void {
            TrustedBrowser::revokeForAccount((int)$locked->id);
        });
        TrustedBrowser::clearCookie();
        $this->flash('success', 'totp_trust_revoked');
        $this->redirect('/settings');
    }
}
