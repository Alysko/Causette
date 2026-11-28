<?php
use Causette\Installation\SetupConfiguration;
use Causette\Localization\Locale;
use Causette\Security\BrowserSession;
use Causette\Security\ContentSecurityPolicy;
use Causette\Security\IntegrationKeyring;
use Causette\Security\LegacySite;
use Causette\Security\RequestOrigin;

require_once __DIR__ . '/app/core/Locale.php';

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
if (session_status() !== PHP_SESSION_NONE) {
    header_remove('Set-Cookie');
    http_response_code(503);
    echo Locale::current()->text('error.session_auto_start');
    exit;
}

require_once __DIR__ . '/app/core/RequestOrigin.php';
require_once __DIR__ . '/app/core/BrowserSession.php';
require_once __DIR__ . '/app/core/ContentSecurityPolicy.php';
require_once __DIR__ . '/app/core/IntegrationKeyring.php';
require_once __DIR__ . '/app/install/SetupConfiguration.php';

$publicPath = RequestOrigin::requestPath($_SERVER);
if ($publicPath === null) {
    http_response_code(400);
    exit;
}

$privateRoot = SetupConfiguration::privateRoot();
$runtimePath = dirname(__DIR__) . '/storage/' . SetupConfiguration::RUNTIME_FILE;
$legacyConfiguration = __DIR__ . '/app/config/config.php';
$privateConfiguration = null;
$setupOnly = false;
try {
    if (SetupConfiguration::exists($runtimePath)) {
        if ($privateRoot === null) {
            throw new RuntimeException('The private root could not be validated.');
        }
        $privateConfiguration = SetupConfiguration::read($runtimePath);
        SetupConfiguration::validateRuntime($privateConfiguration);
        $origin = SetupConfiguration::siteOrigin($privateConfiguration);
    } elseif (is_file($legacyConfiguration)) {
        require_once __DIR__ . '/app/core/LegacySite.php';
        $origin = LegacySite::origin($legacyConfiguration);
    } else {
        if ($privateRoot === null || !SetupConfiguration::exists($privateRoot . '/' . SetupConfiguration::OPERATOR_FILE)) {
            http_response_code($publicPath === '/install' ? 404 : 424);
            header('Content-Type: text/plain; charset=UTF-8');
            echo Locale::current()->text('install.unavailable_config');
            exit;
        }
        $configuration = SetupConfiguration::read($privateRoot . '/' . SetupConfiguration::OPERATOR_FILE);
        SetupConfiguration::validateSite($configuration);
        $origin = SetupConfiguration::siteOrigin($configuration);
        $setupOnly = true;
    }
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo Locale::current()->text('install.configuration_error');
    exit;
}

$scheme = $origin->scheme($_SERVER);
if (!$origin->acceptsHost($_SERVER, $scheme)) {
    http_response_code(421);
    echo Locale::current()->text('error.configured_https_required');
    exit;
}
if ($scheme === null) {
    http_response_code(400);
    echo Locale::current()->text('error.invalid_proxy_scheme');
    exit;
}

$requestPath = $origin->routePath($publicPath);
if ($requestPath === null) {
    http_response_code(404);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($scheme !== 'https') {
    header_remove('Set-Cookie');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    if (in_array($requestMethod, ['GET', 'HEAD'], true)) {
        // A cleartext URL may contain a recovery token. Do not forward its query.
        header('Location: ' . $origin->urlFor($requestPath), true, 302);
    } else {
        http_response_code(403);
        echo Locale::current()->text('error.https_page_required');
    }
    exit;
}

define('CAUSETTE_CANONICAL_URL', $origin->url);
define('CAUSETTE_PUBLIC_PATH', $origin->basePath);
if ($privateRoot !== null) {
    define('CAUSETTE_INTEGRATION_KEY_FILE', $privateRoot . '/' . IntegrationKeyring::FILE);
}
function is_https_request(): bool
{
    return true;
}

$isRecoveryPage = in_array($requestPath, ['/forgot-password', '/reset-password'], true);
if ($isRecoveryPage) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
}

if ($requestPath === '/install') {
    Locale::initialize($requestMethod, CAUSETTE_PUBLIC_PATH);
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=UTF-8');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    if (!$setupOnly) {
        http_response_code(404);
        header('Content-Language: ' . Locale::current()->language());
        echo Locale::current()->html('install.unavailable');
        exit;
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        exit;
    }
    if (!in_array($requestMethod, ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        exit;
    }
    start_causette_session(CAUSETTE_PUBLIC_PATH . '/install', 'CAUSETTESETUP', 'Strict');

    require_once __DIR__ . '/app/core/Controller.php';
    require_once __DIR__ . '/app/controllers/InstallController.php';
    $installer = new InstallController();
    if ($requestMethod === 'POST') {
        $installer->install();
    } else {
        $installer->showInstall();
    }
    exit;
}

if ($setupOnly) {
    http_response_code(424);
    header('Content-Type: text/plain; charset=UTF-8');
    echo Locale::current()->text('install.required');
    exit;
}

function start_causette_session(string $path, ?string $name = null, string $sameSite = 'Lax'): void
{
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)BrowserSession::IDLE_SECONDS);
    if ($name !== null) {
        session_name($name);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    if (!session_start()) {
        header_remove('Set-Cookie');
        http_response_code(503);
        echo Locale::current()->text('error.session_start_failed');
        exit;
    }
}

function send_security_headers(bool $isRecoveryPage, ?string $captchaProvider): void {
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    if (!$isRecoveryPage) {
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    header('Permissions-Policy: geolocation=(), payment=(), usb=(), camera=(self), microphone=(self)');
    header('Cache-Control: no-store');
    header('Content-Security-Policy: ' . ContentSecurityPolicy::forPage($captchaProvider));

    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

start_causette_session(CAUSETTE_PUBLIC_PATH . '/');
$causetteVersion = require __DIR__ . '/version.php';
define('CAUSETTE_DATABASE_VERSION', $causetteVersion['database_version']);
if ($privateConfiguration !== null) {
    require __DIR__ . '/app/config/private-runtime.php';
} else {
    require $legacyConfiguration;
}

function flash_get(string $key): ?string {
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }
    $value = (string)$_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $value;
}

spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/app/core/' . $class . '.php',
        __DIR__ . '/app/controllers/' . $class . '.php',
        __DIR__ . '/app/models/' . $class . '.php'
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

ErrorHandler::register();

try {
    $installationId = defined('CAUSETTE_INSTALLATION_ID') ? CAUSETTE_INSTALLATION_ID : null;
    $runtimeSettings = Setting::getMany(['causette_installation_id', 'database_version', 'error_display']);
    if ($installationId !== null && ($runtimeSettings['causette_installation_id'] ?? null) !== $installationId) {
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=UTF-8');
        http_response_code(503);
        echo Locale::current()->text('error.database_configuration_mismatch');
        exit;
    }
    $dbVersion = $runtimeSettings['database_version'] ?? null;
} catch (Throwable $exception) {
    ErrorHandler::abort(503, 'The Causette database is unavailable.');
}
if (!is_string($dbVersion) || $dbVersion === '') {
    ErrorHandler::abort(424, 'Causette has not been installed yet.');
}
$captchaProvider = null;
if (in_array($requestPath, ['/login', '/register', '/forgot-password'], true) && in_array($requestMethod, ['GET', 'HEAD'], true)) {
    require_once __DIR__ . '/app/modules/captcha/CaptchaManager.php';
    $captchaProvider = CaptchaManager::getActiveProvider()?->getName();
}
send_security_headers($isRecoveryPage, $captchaProvider);
if ($requestPath === '/credential-upgrade') {
    (new CredentialUpgradeController())->handle($requestMethod, $dbVersion);
    exit;
}
if ($requestPath === '/reset-password') {
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}
if ($dbVersion !== CAUSETTE_DATABASE_VERSION) {
    header('Cache-Control: no-store');
    $updateAvailable = UpdateController::supportsVersion($dbVersion);
    if (!UpdateController::allowsDuringMaintenance($requestPath, $_SERVER['REQUEST_METHOD'] ?? 'GET', $updateAvailable)) {
        header('X-App-Version: ' . APP_VERSION);
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'");
        http_response_code(503);
        require __DIR__ . '/app/views/update-required.php';
        exit;
    }
} else {
    ErrorHandler::setDebugMode(($runtimeSettings['error_display'] ?? null) === '1');
}
if (strpos($requestPath, '/api') === 0) {
    RateLimiter::enforceApiLimit($requestPath, $_SERVER['REQUEST_METHOD'] ?? 'GET');
}

$router = new Router();

// Auth
$router->get('/update', 'UpdateController@showUpdate');
$router->post('/update', 'UpdateController@runUpdate');
$router->post('/update/reauthenticate', 'UpdateController@reauthenticate');

$router->get('/login', 'AuthController@showLogin');
$router->post('/login', 'AuthController@login');
$router->get('/2fa', 'AuthController@show2FA');
$router->post('/2fa', 'AuthController@verify2FA');
$router->get('/verify-email', 'AuthController@showVerifyEmail');
$router->post('/verify-email', 'AuthController@verifyEmail');
$router->post('/verify-email/resend', 'AuthController@resendVerifyEmail');
$router->get('/register', 'AuthController@showRegister');
$router->post('/register', 'AuthController@register');
$router->post('/logout', 'AuthController@logout');
$router->get('/forgot-password', 'AuthController@showForgot');
$router->post('/forgot-password', 'AuthController@forgotPassword');
$router->get('/reset-password', 'AuthController@showReset');
$router->post('/reset-password', 'AuthController@resetPassword');

// Main
$router->get('/', 'HomeController@index');
$router->get('/posts', 'HomeController@posts');
$router->get('/search', 'HomeController@search');

// User
$router->get('/u/{user_number}', 'UserController@profile');

// Admin users
$router->get('/users', 'AdminUserController@index');
$router->post('/users/change-group', 'AdminUserController@changeGroup');
$router->post('/users/ban', 'AdminUserController@ban');
$router->post('/users/delete', 'AdminUserController@delete');
$router->get('/tree', 'TreeController@index');

// Friends
$router->post('/friends/request', 'FriendController@sendRequest');
$router->post('/friends/accept', 'FriendController@acceptRequest');
$router->post('/friends/cancel', 'FriendController@cancelRequest');
$router->post('/friends/unfriend', 'FriendController@unfriend');
$router->post('/friends/favorite', 'FriendController@toggleFavorite');

// Attachments
$router->get('/a/{user_number}/{filename}', 'AttachmentController@serve');

// Avatars
$router->get('/avatars/{filename}', 'AvatarController@serve');

// Emojis
$router->get('/emojis/{filename}', 'EmojiController@serve');

// Chat
$router->get('/c/{chat_number}/delete', 'ChatController@deletePersonalByNumber');
$router->post('/c/{chat_number}/delete', 'ChatController@deletePersonalByNumberConfirm');
$router->get('/c/{chat_number}', 'ChatController@show');
$router->post('/api/bot/send', 'ApiKeyController@botSendMessage');
$router->post('/api/messages', 'ChatController@sendMessage'); // also used by web
$router->post('/api/messages/react', 'ChatController@reactMessage');
$router->post('/api/messages/edit', 'ChatController@editMessage');
$router->post('/api/messages/delete', 'ChatController@deleteMessage');
$router->post('/api/messages/pin', 'ApiController@pinMessage');
$router->post('/api/messages/unpin', 'ApiController@unpinMessage');
$router->post('/api/posts', 'PostController@create');
$router->post('/api/posts/react', 'PostController@react');
$router->post('/api/posts/delete', 'PostController@delete');
$router->post('/api/attachments/upload', 'ChatController@uploadAttachment');
$router->post('/api/attachments/delete', 'ChatController@deleteAttachment');
$router->post('/api/attachments/expiry', 'ChatController@updateAttachmentExpiry');
$router->post('/api/chats/typing', 'ApiController@updateTyping');
$router->post('/api/chats/group/create', 'ChatController@createGroup');
$router->post('/api/chats/group/add-member', 'ChatController@addGroupMember');
$router->post('/api/chats/group/remove-member', 'ChatController@removeGroupMember');
$router->post('/api/chats/group/promote-moderator', 'ChatController@promoteGroupModerator');
$router->post('/api/chats/group/demote-moderator', 'ChatController@demoteGroupModerator');
$router->post('/api/chats/group/mute-member', 'ChatController@muteGroupMember');
$router->post('/api/chats/group/unmute-member', 'ChatController@unmuteGroupMember');
$router->post('/api/chats/group/poll/create', 'ChatController@createGroupPoll');
$router->post('/api/chats/group/poll/vote', 'ChatController@voteGroupPoll');
$router->post('/api/chats/group/poll/expire', 'ChatController@expireGroupPoll');
$router->post('/api/chats/group/request-join', 'ChatController@requestGroupJoin');
$router->post('/api/chats/group/cancel-request', 'ChatController@cancelGroupJoinRequest');
$router->post('/api/chats/group/approve-request', 'ChatController@approveGroupJoinRequest');
$router->post('/api/chats/group/deny-request', 'ChatController@denyGroupJoinRequest');
$router->post('/api/chats/group/take-ownership', 'ChatController@takeGroupOwnership');
$router->post('/api/chats/group/leave', 'ChatController@leaveGroup');
$router->post('/api/chats/group/delete', 'ChatController@deleteGroup');
$router->post('/api/chats/rename', 'ChatController@renameChat');
$router->post('/api/chats/group/message-settings', 'ChatController@updateGroupMessageSettings');
$router->post('/api/status', 'ApiController@updateStatus');

// Calls
$router->post('/api/calls/start', 'CallController@startCall');
$router->post('/api/calls/decline', 'CallController@declineCall');
$router->post('/api/calls/signal', 'CallController@signal');
$router->post('/api/calls/end', 'CallController@endCall');
$router->post('/api/calls/leave', 'CallController@leaveCall');
$router->post('/api/calls/poke', 'CallController@pokeMember');

// Notifications
$router->get('/notifications', 'NotificationController@getAll');
$router->get('/api/push/status', 'PushController@status');
$router->post('/api/push/subscribe', 'PushController@subscribe');
$router->post('/api/push/unsubscribe', 'PushController@unsubscribe');
$router->post('/api/notifications/seen', 'NotificationController@markSeen');
$router->post('/api/notifications/sidebar-state', 'NotificationController@updateSidebarState');
$router->post('/api/notifications/read', 'NotificationController@markRead');
$router->post('/api/notifications/clear', 'NotificationController@clearAll');

// Reports
$router->get('/reports', 'ReportController@index');
$router->post('/reports/mark-handled', 'ReportController@markHandled');
$router->post('/api/report', 'ReportController@submit');

// Trash (admin only)
$router->get('/trash', 'TrashController@index');
$router->get('/trash/{chat_number}', 'TrashController@show');
$router->post('/trash/delete', 'TrashController@delete');

// Settings
$router->get('/settings', 'SettingsController@index');
$router->post('/settings/display-preferences', 'SettingsController@saveDisplayPreferences');
$router->post('/settings/call-video', 'SettingsController@saveCallVideoPreference');
$router->get('/apikeys', 'ApiKeyController@index');
$router->post('/apikeys/create', 'ApiKeyController@create');
$router->post('/apikeys/expire', 'ApiKeyController@expire');
$router->post('/apikeys/update', 'ApiKeyController@update');
$router->get('/apikeys/docs', 'ApiKeyController@docs');

// Admin (admin only)
$router->post('/admin/mail', 'AdminController@saveMailSettings');
$router->post('/admin/mail/test', 'AdminController@sendTestMail');
$router->post('/admin/storage/recalculate', 'AdminController@recalculateStorageStats');
$router->post('/admin/check-updates', 'AdminController@checkForUpdatesNow');
$router->post('/admin/accounts', 'AdminController@saveAccountSettings');
$router->post('/admin/display-defaults', 'AdminController@saveDisplayDefaults');
$router->post('/admin/attachments', 'AdminController@saveAttachmentSettings');
$router->post('/admin/announcement', 'AdminController@saveAnnouncementSettings');
$router->post('/admin/more', 'AdminController@saveMoreSettings');
$router->post('/admin/captcha', 'AdminController@saveCaptchaSettings');
$router->post('/admin/push', 'AdminController@savePushSettings');

// Settings
$router->post('/settings/account/email', 'HomeController@saveAccountEmail');
$router->post('/settings/account/email/verify', 'HomeController@verifyAccountEmailChange');
$router->post('/settings/account/password', 'HomeController@saveAccountPassword');
$router->post('/settings/profile/username', 'HomeController@saveProfileUsername');
$router->post('/settings/avatar', 'HomeController@saveAvatarSettings');
$router->post('/settings/avatar/delete', 'HomeController@deleteAvatarSettings');
$router->post('/settings/notifications', 'HomeController@saveNotificationSettings');
$router->post('/settings/timezone', 'HomeController@saveTimezoneSettings');
$router->post('/settings/preferences', 'HomeController@savePreferencesSettings');
$router->post('/settings/invites/generate', 'HomeController@generateInvite');
$router->post('/settings/invites/delete', 'HomeController@deleteInvite');
$router->post('/settings/sessions/exit', 'HomeController@exitSession');
$router->post('/settings/2fa/totp/setup', 'HomeController@totpSetupBegin');
$router->post('/settings/2fa/totp/confirm', 'HomeController@totpSetupConfirm');
$router->post('/settings/2fa/totp/disable', 'HomeController@totpDisable');
$router->post('/settings/2fa/frequency', 'HomeController@saveTwofaFrequency');
$router->post('/settings/2fa/trusted/revoke', 'HomeController@revokeTrustedBrowsers');

// API routes (JSON only)
$router->group('/api', function($r) {
    $r->get('/users/search', 'ApiController@searchUsers');
    $r->get('/friends', 'ApiController@getFriends');
    $r->get('/chats', 'ApiController@getChats');
    $r->get('/chats/sidebar-custom', 'ApiController@getSidebarCustomChats');
    $r->post('/chats/sidebar-custom', 'ApiController@saveSidebarCustomChats');
    $r->get('/chats/sidebar-custom-label', 'ApiController@getSidebarCustomLabel');
    $r->post('/chats/sidebar-custom-label', 'ApiController@saveSidebarCustomLabel');
    $r->post('/chats/notifications', 'ChatController@setConversationNotifications');
    $r->get('/messages/search', 'ApiController@searchMessages');
    $r->post('/messages/saved', 'ApiController@setSavedMessage');
    $r->get('/messages/{chat_id}', 'ApiController@getMessages');
    $r->get('/threads/{message_id}', 'ChatController@getThread');
    $r->post('/threads/{message_id}', 'ChatController@replyToThread');
    $r->post('/threads/{message_id}/follow', 'ChatController@setThreadFollow');
    $r->get('/posts/search', 'ApiController@searchPosts');
    $r->get('/notifications', 'ApiController@getNotifications');
    $r->get('/invites', 'ApiController@getInvites');
    $r->get('/calls/active/{chat_id}', 'ApiController@getActiveCall');
    $r->get('/calls/current', 'ApiController@getCurrentActiveCall');
    $r->get('/calls/signal/{call_id}', 'ApiController@getCallSignal');
    $r->get('/servers', 'ApiController@getServers');
    $r->get('/channels/{server_id}', 'ApiController@getChannels');
});

// Dispatch
$router->dispatch($requestPath);
