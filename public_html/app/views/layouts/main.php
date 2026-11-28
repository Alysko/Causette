<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<?php
$locale = \Causette\Localization\Locale::current();
$displayFormats = \Causette\Localization\DisplayPreferences::effective();
require_once __DIR__ . '/../../core/HtmlJson.php';
require_once __DIR__ . '/../../core/ContentSecurityPolicy.php';
require_once __DIR__ . '/../../core/AssetUrl.php';
require_once __DIR__ . '/../../core/ReactionCatalog.php';
require_once __DIR__ . '/../../core/CallIceServers.php';
require_once __DIR__ . '/../../core/CallVideoPreferences.php';
$scripts = ['notification.js', 'chat.js', 'call.js', 'user.js', 'threads.js', 'app.js'];
$brandTitle = htmlspecialchars('Causette ' . APP_VERSION, ENT_QUOTES, 'UTF-8');
$initialTheme = $displayFormats['theme'] === 'system' ? 'dark' : $displayFormats['theme'];
?>
<!DOCTYPE html>
<html lang="<?= $locale->language() ?>" data-date-format="<?= $displayFormats['date_format'] ?>" data-time-format="<?= $displayFormats['time_format'] ?>"
      data-theme="<?= $initialTheme ?>" data-message-density="<?= $displayFormats['message_density'] ?>"
      data-chat-width="<?= $displayFormats['chat_width'] ?>" data-chat-open-at="<?= $displayFormats['chat_open_at'] ?>"
      data-image-preview="<?= $displayFormats['image_preview'] ?>" data-unread-grouping="<?= $displayFormats['unread_grouping'] ?>"<?= $initialTheme === 'light' ? '' : ' class="dark"' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="<?= \Causette\View\AssetUrl::html('/assets/img/favicon.png') ?>">
    <title>Causette</title>
    <?php if ($displayFormats['theme'] === 'system' && !($renderMainOnly ?? false)): ?>
        <script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>" src="<?= \Causette\View\AssetUrl::html('/assets/js/system-theme.js') ?>"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/causette.css') ?>">
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/fontawesome.min.css') ?>">
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/layout.css') ?>">
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/reactions.css') ?>">
    <?php require __DIR__ . '/../partials/typography.php'; ?>
</head>
<?php if ($renderMainOnly ?? false): ?>
<body>
    <?php require __DIR__ . '/../partials/app-main.php'; ?>
</body>
<?php else: ?>
<?php
    $currentUser = $pageViewer;
    $appFontSizePreference = 'default';
    if ($currentUser) {
        $fontSizeSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['font_size_' . $currentUser->id])->fetchColumn();
        if ($fontSizeSetting !== false) {
            $normalizedFontSizeSetting = strtolower(trim((string)$fontSizeSetting));
            if (in_array($normalizedFontSizeSetting, ['default', 'large'], true)) {
                $appFontSizePreference = $normalizedFontSizeSetting;
            }
        }
    }
?>
<body class="text-gray-200 <?= $currentUser ? 'h-screen overflow-hidden' : 'min-h-screen overflow-y-auto' ?> <?= $appFontSizePreference === 'large' ? 'app-font-large' : '' ?>">
    <div id="app-top-progress" aria-hidden="true"><div id="app-top-progress-fill"></div></div>
    <a href="#app-main-content" class="sr-only focus:not-sr-only"><?= $tr('ui.skip_to_main_content') ?></a>
    <?php
        $browserNotificationsEnabled = false;
        $webPushNotificationsEnabled = false;
        $pokeSoundEnabled = true;
        $newMessageSoundEnabled = true;
        $otherNotificationSoundEnabled = true;
        $outgoingCallRingSoundEnabled = true;
        $notificationSidebarExpanded = false;
        $topLoadingBarEnabled = true;
        $systemMessagesAutoCombineEnabled = true;
        $fontSizePreference = $appFontSizePreference;
        $userTimezone = \Causette\Localization\DisplayPreferences::timezone();
        if ($currentUser) {
            $notificationSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['browser_notifications_' . $currentUser->id])->fetchColumn();
            $browserNotificationsEnabled = ((int)$notificationSetting) === 1;
            $webPushSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['web_push_notifications_' . $currentUser->id])->fetchColumn();
            $webPushNotificationsEnabled = ((int)$webPushSetting) === 1;
            $pokeSoundSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['sound_poke_' . $currentUser->id])->fetchColumn();
            if ($pokeSoundSetting === false) {
                $pokeSoundSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['sound_friend_request_' . $currentUser->id])->fetchColumn();
            }
            $newMessageSoundSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['sound_new_message_' . $currentUser->id])->fetchColumn();
            $otherNotificationSoundSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['sound_other_notifications_' . $currentUser->id])->fetchColumn();
            $outgoingCallRingSoundSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['sound_outgoing_call_ring_' . $currentUser->id])->fetchColumn();

            $pokeSoundEnabled = $pokeSoundSetting === false ? true : ((int)$pokeSoundSetting) === 1;
            $newMessageSoundEnabled = $newMessageSoundSetting === false ? true : ((int)$newMessageSoundSetting) === 1;
            $otherNotificationSoundEnabled = $otherNotificationSoundSetting === false ? true : ((int)$otherNotificationSoundSetting) === 1;
            $outgoingCallRingSoundEnabled = $outgoingCallRingSoundSetting === false ? true : ((int)$outgoingCallRingSoundSetting) === 1;

            $notificationSidebarSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['notif_sidebar_expanded_' . $currentUser->id])->fetchColumn();
            $notificationSidebarExpanded = ((int)$notificationSidebarSetting) === 1;

            $topLoadingBarSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['top_loading_bar_enabled_' . $currentUser->id])->fetchColumn();
            $topLoadingBarEnabled = $topLoadingBarSetting === false ? true : ((int)$topLoadingBarSetting) === 1;

            $systemMessagesAutoCombineSetting = Database::query('SELECT `value` FROM settings WHERE `key` = ?', ['system_messages_auto_combine_' . $currentUser->id])->fetchColumn();
            $systemMessagesAutoCombineEnabled = $systemMessagesAutoCombineSetting === false ? true : ((int)$systemMessagesAutoCombineSetting) === 1;

            $fontSizePreference = $appFontSizePreference;

            if (isset($incomingRequestCount)) {
                $incomingFriendRequestCount = (int)$incomingRequestCount;
            } else {
                $incomingFriendRequestCount = (int)Database::query(
                    "SELECT COUNT(*) FROM friends WHERE friend_id = ? AND status = 'pending'",
                    [$currentUser->id]
                )->fetchColumn();
            }
        }

        $openMojiDirs = [];
        if (defined('STORAGE_FILESYSTEM_ROOT')) {
            $configuredStorageRoot = trim((string)STORAGE_FILESYSTEM_ROOT);
            if ($configuredStorageRoot !== '') {
                $openMojiDirs[] = rtrim($configuredStorageRoot, '/') . '/emojis';
            }
        }
        $openMojiDirs[] = dirname(__DIR__, 4) . '/storage/emojis';
        $openMojiDirs[] = dirname(__DIR__, 3) . '/assets/emojis';
        $openMojiDirs = array_values(array_unique($openMojiDirs));

        $openMojiDir = '';
        $openMojiFiles = [];
        foreach ($currentUser ? $openMojiDirs : [] as $candidateDir) {
            $candidateFiles = glob(rtrim($candidateDir, '/') . '/*.svg') ?: [];
            if (count($candidateFiles) > 0) {
                $openMojiDir = $candidateDir;
                $openMojiFiles = $candidateFiles;
                break;
            }
        }

        if ($openMojiDir === '' && count($openMojiDirs) > 0) {
            $openMojiDir = $openMojiDirs[0];
        }

        $openMojiFileNames = array_map(static fn($path) => basename($path), $openMojiFiles);
        sort($openMojiFileNames, SORT_STRING);

        $openMojiMetadata = [];
        $pickerKeys = array_fill_keys(\Causette\Chat\ReactionCatalog::CODES, true);

        $openMojiCsvPath = $openMojiDir . '/openmoji.csv';
        if ($openMojiFiles && is_readable($openMojiCsvPath)) {
            $openMojiHandle = fopen($openMojiCsvPath, 'rb');
            if ($openMojiHandle !== false) {
                $header = fgetcsv($openMojiHandle, 0, ',', '"', '\\');
                if (is_array($header)) {
                    $headerIndex = array_flip($header);
                    $hexIdx = $headerIndex['hexcode'] ?? null;
                    $annotationIdx = $headerIndex['annotation'] ?? null;
                    $tagsIdx = $headerIndex['tags'] ?? null;
                    $openmojiTagsIdx = $headerIndex['openmoji_tags'] ?? null;
                    $groupIdx = $headerIndex['group'] ?? null;
                    $subgroupsIdx = $headerIndex['subgroups'] ?? null;

                    while (($row = fgetcsv($openMojiHandle, 0, ',', '"', '\\')) !== false) {
                        if ($hexIdx === null || !isset($row[$hexIdx])) {
                            continue;
                        }

                        $hex = strtoupper(trim((string)$row[$hexIdx]));
                        if ($hex === '') {
                            continue;
                        }

                        $hexWithoutFe0f = preg_replace('/(?:-)?FE0F/i', '', $hex);
                        if (!isset($pickerKeys[$hex]) && !isset($pickerKeys[$hexWithoutFe0f])) {
                            continue;
                        }

                        $meta = [
                            'annotation' => ($annotationIdx !== null && isset($row[$annotationIdx])) ? trim((string)$row[$annotationIdx]) : '',
                            'tags' => ($tagsIdx !== null && isset($row[$tagsIdx])) ? trim((string)$row[$tagsIdx]) : '',
                            'openmoji_tags' => ($openmojiTagsIdx !== null && isset($row[$openmojiTagsIdx])) ? trim((string)$row[$openmojiTagsIdx]) : '',
                            'group' => ($groupIdx !== null && isset($row[$groupIdx])) ? trim((string)$row[$groupIdx]) : '',
                            'subgroups' => ($subgroupsIdx !== null && isset($row[$subgroupsIdx])) ? trim((string)$row[$subgroupsIdx]) : '',
                        ];

                        if (isset($pickerKeys[$hex])) {
                            $openMojiMetadata[$hex] = $meta;
                        }
                        if (isset($pickerKeys[$hexWithoutFe0f]) && !isset($openMojiMetadata[$hexWithoutFe0f])) {
                            $openMojiMetadata[$hexWithoutFe0f] = $meta;
                        }
                    }
                }

                fclose($openMojiHandle);
            }
        }
    ?>
    <?php if ($currentUser): ?>
    <div class="h-screen flex flex-col app-shell">
        <div id="chat-call-status-bar" class="hidden w-full px-6 py-2 border-b border-transparent text-sm font-medium flex items-center gap-3 shrink-0">
            <span id="chat-call-status-label"></span>
            <span id="chat-call-status-duration" class="hidden text-xs opacity-80 tabular-nums">00:00</span>
            <span id="chat-call-show-overlay-hint" class="hidden text-xs opacity-60"><i class="fa-solid fa-up-right-from-square"></i> <?= $tr('ui.show') ?></span>
            <button id="join-call-btn" data-app-action="join-call" class="hidden bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1 rounded-full text-xs font-medium"><?= $tr('ui.join_call') ?></button>
            <button id="accept-call-btn" data-app-action="accept-call" class="hidden bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1 rounded-full text-xs font-medium"><?= $tr('ui.accept') ?></button>
            <button id="decline-call-btn" data-app-action="decline-call" class="hidden bg-red-600 hover:bg-red-500 text-white px-3 py-1 rounded-full text-xs font-medium"><?= $tr('ui.decline') ?></button>
            <button id="exit-call-btn" data-app-action="end-call" class="hidden bg-red-600 hover:bg-red-500 text-white px-3 py-1 rounded-full text-xs font-medium"><?= $tr('ui.end_call') ?></button>
            <button id="end-ringing-call-btn" data-app-action="end-call" class="hidden bg-red-600 hover:bg-red-500 text-white px-3 py-1 rounded-full text-xs font-medium"><?= $tr('ui.end') ?></button>
        </div>

        <div id="incoming-call-waiting-overlay" class="hidden fixed inset-0 z-[90] bg-black/70 backdrop-blur-sm p-4">
            <div class="h-full w-full flex items-center justify-center">
                <div class="relative z-[91] w-full max-w-md bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6">
                    <h2 id="incoming-call-waiting-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.incoming_call_waiting') ?></h2>
                    <p id="incoming-call-waiting-subtitle" class="mt-2 text-sm text-zinc-400"><?= $tr('ui.you_are_currently_in_an_active') ?></p>
                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" id="incoming-call-waiting-decline-btn" data-app-action="decline-incoming-call" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200"><?= $tr('ui.decline_new_call') ?></button>
                        <button type="button" id="incoming-call-waiting-switch-btn" data-app-action="switch-incoming-call" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white"><?= $tr('ui.end_current_join') ?></button>
                    </div>
                </div>
            </div>
        </div>

    <div id="mobile-top-bar" role="banner" class="lg:hidden sticky top-0 z-30 flex items-center justify-between px-4 py-4 bg-zinc-900/95 backdrop-blur border-b border-zinc-800">
        <button id="sidebar-toggle-mobile" type="button" class="flex items-center gap-3" title="<?= $brandTitle ?>" aria-label="Causette – <?= $tr('ui.chats') ?>" aria-controls="app-sidebar" aria-expanded="false">
            <i class="fa-solid fa-bars text-zinc-300" aria-hidden="true"></i>
            <img src="<?= \Causette\View\AssetUrl::html('/assets/img/logo.png') ?>" width="32" height="32" alt="" class="shrink-0">
            <span class="text-2xl font-bold text-zinc-100">Causette</span>
        </button>
        <button type="button" id="notification-history-button-mobile" class="relative w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-700 hover:bg-zinc-800"
                aria-label="<?= $tr('ui.toggle_notification_history') ?>" aria-controls="notification-history-list" aria-expanded="false">
            <i class="fa-regular fa-bell text-zinc-300"></i>
            <span id="notification-history-count-mobile" class="hidden absolute -top-2 -right-2 min-w-[1.25rem] h-5 px-1 rounded-full bg-emerald-600 text-white text-xs flex items-center justify-center">0</span>
        </button>
    </div>

    <div id="app-route-status" role="status"
         data-chat-label="<?= $tr('ui.opening_conversation') ?>"
         data-page-label="<?= $tr('ui.loading_page') ?>"
         data-error-label="<?= $tr('ui.could_not_open_page') ?>"></div>

    <div id="app-layout" class="flex flex-1 min-h-0">
        <aside id="app-sidebar" role="complementary" tabindex="-1" class="sidebar border-r border-zinc-800 flex flex-col">
            <a id="app-brand" href="<?= htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8') ?>" title="<?= $brandTitle ?>" class="p-4 border-b border-zinc-700 flex items-center gap-3 hover:bg-zinc-800/40 transition">
                <img src="<?= \Causette\View\AssetUrl::html('/assets/img/logo.png') ?>" width="32" height="32" alt="" class="shrink-0">
                <span class="text-2xl font-bold">Causette</span>
            </a>
            <div id="sidebar-resize-handle" role="separator" tabindex="0" aria-orientation="vertical"
                 aria-controls="app-sidebar" aria-label="<?= $tr('ui.resize_sidebar') ?>"></div>
            <div class="flex-1 min-h-0 p-4 flex flex-col gap-3">
                <button id="open-chat-switcher" type="button" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K"
                        class="conversation-switcher-button">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span><?= $tr('ui.switch_conversation') ?></span>
                </button>
                <a href="<?= htmlspecialchars(base_url('/search?mode=saved'), ENT_QUOTES, 'UTF-8') ?>" class="conversation-switcher-button">
                    <i class="fa-regular fa-bookmark" aria-hidden="true"></i>
                    <span><?= $tr('ui.saved_messages') ?></span>
                </a>
                <div class="inline-flex flex-wrap self-start max-w-full items-center rounded-lg border border-zinc-700 p-1 text-xs" id="chat-sidebar-mode-toggle">
                    <button type="button" data-chat-sidebar-mode="all" aria-pressed="true" class="px-2.5 py-1 rounded-md transition bg-zinc-700 text-zinc-100"><?= $tr('ui.all') ?></button>
                    <button type="button" data-chat-sidebar-mode="unread" aria-pressed="false" class="px-2.5 py-1 rounded-md transition text-zinc-400 hover:text-zinc-200"><?= $tr('ui.unread') ?></button>
                    <button type="button" data-chat-sidebar-mode="pm" aria-pressed="false" class="px-2.5 py-1 rounded-md transition text-zinc-400 hover:text-zinc-200"><?= $tr('ui.pm') ?></button>
                    <button type="button" data-chat-sidebar-mode="group" aria-pressed="false" class="px-2.5 py-1 rounded-md transition text-zinc-400 hover:text-zinc-200"><?= $tr('ui.group') ?></button>
                    <button type="button" data-chat-sidebar-mode="custom" aria-pressed="false" class="px-2.5 py-1 rounded-md transition text-zinc-400 hover:text-zinc-200" title="<?= $tr('ui.right_click_to_rename') ?>" aria-label="<?= $tr('ui.custom_category_right_click_to_rename') ?>"><span id="chat-sidebar-custom-mode-label"><?= $tr('ui.custom') ?></span></button>
                </div>

                <nav class="flex-1 min-h-0 flex flex-col">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-2">
                        <span class="text-xs uppercase tracking-wide text-zinc-500"><?= $tr('ui.chats') ?></span>
                        <button type="button" data-app-action="create-group-chat" class="inline-flex items-center gap-2 rounded-lg border border-zinc-700 px-3 py-2 text-xs text-zinc-100 hover:bg-zinc-800">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= $tr('ui.new_group') ?>
                        </button>
                    </div>
                    <div id="chat-sidebar-list" class="space-y-1 overflow-y-auto min-h-0"></div>
                </nav>
                <div id="chat-sidebar-custom-controls" class="hidden space-y-2">
                    <div id="chat-sidebar-custom-count" class="px-1 text-xs uppercase tracking-wide text-zinc-500"><?= $tr('ui.custom_0_8') ?></div>
                    <div id="chat-sidebar-custom-help" class="px-1 text-xs text-zinc-500"><?= $tr('ui.add_a_chat_to_custom_by') ?></div>
                </div>
                <div id="chat-sidebar-custom-menu" class="hidden fixed z-50 min-w-[14rem] bg-zinc-900 border border-zinc-700 rounded-xl p-1.5 shadow-lg">
                    <button type="button" data-custom-menu-action="open" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 text-sm whitespace-nowrap"><?= $tr('ui.open_in_new_tab') ?></button>
                    <button type="button" data-custom-menu-action="add" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 text-sm whitespace-nowrap"><?= $tr('ui.add_to_custom') ?></button>
                    <button type="button" data-custom-menu-action="copy-link" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 text-sm whitespace-nowrap"><?= $tr('ui.copy_link_to_group') ?></button>
                    <button type="button" data-custom-menu-action="copy-number" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 text-sm whitespace-nowrap"><?= $tr('ui.copy_group_number') ?></button>
                    <button type="button" data-custom-menu-action="remove" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 text-sm text-red-300 whitespace-nowrap"><?= $tr('ui.remove_from_custom_list') ?></button>
                </div>
            </div>
            <div class="p-4 border-t border-zinc-700 text-sm text-zinc-400">
                <?php $currentUserAvatarUrl = User::avatarUrl($currentUser); ?>
                <details id="account-menu" class="relative">
                    <summary class="flex items-center gap-3 cursor-pointer hover:bg-zinc-800/40 rounded-xl p-2 transition"
                             aria-label="<?= $tr('ui.account_menu', ['username' => $currentUser->username]) ?>">
                        <?php if ($currentUserAvatarUrl): ?>
                            <img src="<?= htmlspecialchars($currentUserAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="w-10 h-10 shrink-0 rounded-full object-cover border border-zinc-700">
                        <?php else: ?>
                            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full border border-zinc-700 flex items-center justify-center font-semibold <?= htmlspecialchars(User::avatarColorClasses($currentUser->user_number), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(User::avatarInitial($currentUser->username), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                        <span class="flex-1 min-w-0">
                            <span class="block truncate font-medium text-zinc-200"><?= htmlspecialchars($currentUser->username, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="block"><?= htmlspecialchars(User::formatUserNumber($currentUser->user_number), ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <i class="fa fa-chevron-up text-xs" aria-hidden="true"></i>
                    </summary>
                    <div class="absolute bottom-full left-0 right-0 z-40 mb-2 p-2 rounded-xl border border-zinc-700 bg-zinc-900 shadow-lg">
                        <a href="<?= htmlspecialchars(base_url('/u/' . User::formatUserNumber($currentUser->user_number)), ENT_QUOTES, 'UTF-8') ?>"
                           class="flex items-center gap-2 px-3 py-3 rounded-lg text-zinc-100 hover:bg-zinc-800">
                            <i class="fa-regular fa-user" aria-hidden="true"></i> <?= $tr('ui.view_profile') ?>
                        </a>
                        <form method="post" action="<?= htmlspecialchars(base_path('/logout'), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="w-full flex items-center gap-2 px-3 py-3 rounded-lg text-red-300 hover:bg-zinc-800">
                                <i class="fa fa-right-from-bracket" aria-hidden="true"></i> <?= $tr('ui.sign_out') ?>
                            </button>
                        </form>
                    </div>
                </details>
                <div class="mt-3 flex items-center gap-4">
                    <div class="relative">
                        <button type="button" id="status-menu-toggle" class="text-zinc-300 hover:text-zinc-100 inline-flex items-center gap-2">
                            <span class="flex items-center gap-2 <?= htmlspecialchars($currentUser->effective_status_text_class ?? User::presenceStatusTextClass($currentUser->effective_status ?? 'online'), ENT_QUOTES, 'UTF-8') ?>" id="sidebar-user-status">
                                <span class="presence-dot inline-block w-2 h-2 rounded-full <?= htmlspecialchars($currentUser->effective_status_dot_class ?? User::presenceStatusDotClass($currentUser->effective_status ?? 'online'), ENT_QUOTES, 'UTF-8') ?>" id="sidebar-user-status-dot"></span>
                                <span id="sidebar-user-status-label"><?= $tr('ui.' . (User::normalizePresenceStatus($currentUser->effective_status ?? null) ?? 'online')) ?></span>
                            </span>
                            <i class="fa fa-chevron-up text-[10px] text-zinc-500"></i>
                        </button>
                        <div id="status-menu" class="hidden absolute bottom-full mb-2 left-0 w-44 bg-zinc-900 border border-zinc-700 rounded-xl p-2 space-y-1 z-30">
                            <button type="button" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 flex items-center justify-between" data-status-choice="online">
                                <span class="flex items-center gap-2"><span class="presence-dot inline-block w-2 h-2 rounded-full bg-emerald-500"></span><?= $tr('ui.online') ?></span>
                                <i class="fa fa-check text-xs text-emerald-400 hidden" data-status-check="online"></i>
                            </button>
                            <button type="button" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 flex items-center justify-between" data-status-choice="busy">
                                <span class="flex items-center gap-2"><span class="presence-dot inline-block w-2 h-2 rounded-full bg-amber-500"></span><?= $tr('ui.busy') ?></span>
                                <i class="fa fa-check text-xs text-amber-400 hidden" data-status-check="busy"></i>
                            </button>
                            <button type="button" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 flex items-center justify-between" data-status-choice="offline">
                                <span class="flex items-center gap-2"><span class="presence-dot inline-block w-2 h-2 rounded-full bg-red-500"></span><?= $tr('ui.offline') ?></span>
                                <i class="fa fa-check text-xs text-red-400 hidden" data-status-check="offline"></i>
                            </button>
                            <button type="button" id="enable-system-pause" class="hidden w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-800 text-sm"><?= $tr('ui.enable_system_pause') ?></button>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars(base_url('/settings'), ENT_QUOTES, 'UTF-8') ?>" class="text-zinc-300 hover:text-zinc-100"><i class="fa fa-cog"></i> <?= $tr('ui.settings') ?></a>
                    <button type="button" id="notification-history-launcher" class="ml-auto h-8 w-8 shrink-0 items-center justify-center rounded-lg text-zinc-300 hover:bg-zinc-800"
                            aria-label="<?= $tr('ui.toggle_notification_history') ?>" aria-controls="notification-history-list" aria-expanded="false">
                        <i class="fa-regular fa-bell" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </aside>

        <?php require __DIR__ . '/../partials/app-main.php'; ?>

        <dialog id="chat-switcher" class="conversation-switcher" aria-labelledby="chat-switcher-label" data-empty-label="<?= $tr('ui.no_chat_matches') ?>">
            <div class="conversation-switcher-heading">
                <h2 id="chat-switcher-label"><?= $tr('ui.switch_conversation') ?></h2>
                <button type="button" id="close-chat-switcher" class="conversation-switcher-close" aria-label="<?= $tr('ui.close') ?>">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <form id="chat-switcher-form" role="search">
                <input type="search" id="chat-switcher-input" name="find" autofocus autocomplete="off" maxlength="80"
                       aria-label="<?= $tr('ui.filter_conversations') ?>" placeholder="<?= $tr('ui.filter_conversations') ?>">
            </form>
            <p id="chat-switcher-empty" role="status" hidden></p>
            <ul id="chat-switcher-results"></ul>
        </dialog>
        <?php require __DIR__ . '/../partials/thread-dialog.php'; ?>
        <div id="sidebar-custom-rename-modal" class="hidden fixed inset-0 bg-black/70 z-50 p-4 md:p-6" aria-hidden="true">
            <div class="h-full w-full flex items-center justify-center">
                <div class="w-full max-w-md bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6" role="dialog" aria-modal="true" aria-labelledby="sidebar-custom-rename-title">
                    <h2 id="sidebar-custom-rename-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.rename_custom_category') ?></h2>
                    <p class="mt-2 text-sm text-zinc-400"><?= $tr('ui.choose_a_category_name_leave_blank') ?></p>
                    <form id="sidebar-custom-rename-form" class="mt-4 space-y-4">
                        <label for="sidebar-custom-rename-input" class="block text-sm text-zinc-300"><?= $tr('ui.category_name') ?></label>
                        <input type="text" id="sidebar-custom-rename-input" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100" placeholder="<?= $tr('ui.custom') ?>" maxlength="10">
                        <div class="flex items-center justify-end gap-3">
                            <button type="button" id="sidebar-custom-rename-cancel" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200"><?= $tr('ui.cancel') ?></button>
                            <button type="submit" id="sidebar-custom-rename-submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white"><?= $tr('ui.save') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <aside id="notification-history-panel" class="notification-history-empty w-20 shrink-0 border-l border-zinc-800 bg-zinc-900/90 flex flex-col"
               aria-label="<?= $tr('ui.notifications') ?>" inert>
            <div class="p-4 border-b border-zinc-700 flex shrink-0 items-center justify-center" id="notification-history-header">
                <div id="notification-history-title" class="hidden font-semibold text-zinc-100 mr-3"><?= $tr('ui.notifications') ?></div>
                <button id="notification-clear-all-btn" class="hidden text-xs text-zinc-400 hover:text-zinc-200 mr-auto" data-app-action="clear-notifications"><?= $tr('ui.clear_all') ?></button>
                <button type="button" id="notification-history-button" class="relative w-12 h-12 rounded-2xl bg-zinc-900 border border-zinc-700 hover:bg-zinc-800"
                        aria-label="<?= $tr('ui.toggle_notification_history') ?>" aria-controls="notification-history-list" aria-expanded="false">
                    <i class="fa-regular fa-bell"></i>
                    <span id="notification-history-count" class="hidden absolute -top-2 -right-2 min-w-[1.25rem] h-5 px-1 rounded-full bg-emerald-600 text-white text-xs flex items-center justify-center">0</span>
                </button>
            </div>
            <div id="notification-icon-preview" class="flex-1" role="group" tabindex="0" aria-label="<?= $tr('ui.notifications') ?>"></div>
            <div id="notification-history-list" class="hidden flex-1" role="group" tabindex="0" aria-labelledby="notification-history-title"></div>
        </aside>
    </div>

    <!-- Call overlay -->
    <div id="call-overlay" class="hidden fixed bg-black/95 z-40 flex flex-col">
        <div id="call-connecting-overlay" class="hidden absolute inset-0 z-20 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="text-center">
                <div id="call-connecting-overlay-title" class="text-lg md:text-xl font-semibold text-zinc-100"><?= $tr('ui.connecting_call') ?></div>
                <div id="call-connecting-overlay-subtitle" class="text-sm md:text-base text-zinc-300 mt-1"><?= $tr('ui.please_wait') ?></div>
            </div>
        </div>

        <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-800/60 shrink-0">
            <div class="flex items-center gap-2">
                <div class="text-sm font-medium text-zinc-400"><?= $tr('ui.call_in_progress') ?></div>
                <span id="call-overlay-duration" class="hidden text-xs text-zinc-500 tabular-nums">00:00</span>
            </div>
            <div class="flex items-center gap-1.5">
                <button data-app-action="toggle-call-participants" id="call-participants-toggle-btn" class="hidden h-8 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-xs px-2.5" title="<?= $tr('ui.show_participants_panel') ?>" aria-label="<?= $tr('ui.toggle_participants_panel') ?>" aria-expanded="false"><i class="fa-solid fa-users"></i></button>
                <button type="button" id="toggle-call-self-view" data-app-action="toggle-call-self-view" class="w-8 h-8 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-xs" title="<?= $tr('ui.call_hide_self_view') ?>" aria-label="<?= $tr('ui.call_hide_self_view') ?>" aria-pressed="false"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i></button>
                <button data-app-action="open-call-settings" id="call-settings-btn" class="w-8 h-8 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-xs" title="<?= $tr('ui.call_settings') ?>"><i class="fa-solid fa-gear"></i></button>
                <button data-app-action="set-call-overlay" data-mode="hidden" id="call-overlay-hidden-btn" class="w-8 h-8 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-xs" title="<?= $tr('ui.hide_call_window_reopen_from_call') ?>"><i class="fa-solid fa-chevron-up"></i></button>
                <button data-app-action="set-call-overlay" data-mode="half" id="call-overlay-half-btn" class="w-8 h-8 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-xs" title="<?= $tr('ui.split_screen') ?>"><i class="fa-solid fa-down-left-and-up-right-to-center"></i></button>
                <button data-app-action="set-call-overlay" data-mode="full" id="call-overlay-full-btn" class="w-8 h-8 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-zinc-300 text-xs" title="<?= $tr('ui.full_size') ?>"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
            </div>
        </div>

        <div id="call-stage" class="flex-1 min-h-0 flex flex-col md:flex-row-reverse gap-3 p-3 md:p-5 overflow-hidden">
            <div class="flex-1 min-h-0 flex flex-col">
                <div id="call-videos" class="flex-1 min-h-0 flex items-center justify-center gap-6 flex-wrap overflow-auto">
                    <div id="remote-user-tile" class="flex flex-col items-center gap-2">
                        <div id="remote-video-container" class="relative rounded-2xl overflow-hidden bg-zinc-900 border border-zinc-700 flex items-center justify-center">
                            <video id="remote-camera-video" autoplay playsinline class="hidden absolute inset-0 w-full h-full object-contain"></video>
                            <video id="remote-screen-video" autoplay playsinline class="hidden absolute inset-0 w-full h-full object-contain"></video>
                            <div id="remote-video-placeholder" class="absolute inset-0 flex items-center justify-center"><i class="fa fa-user text-5xl text-zinc-700"></i></div>
                        </div>
                        <button id="remote-username-btn" type="button" class="text-sm text-zinc-300 px-1 select-none" disabled><?= $tr('ui.participant') ?></button>
                    </div>

                    <div id="local-user-tile" class="flex flex-col items-center gap-2">
                        <div id="local-media-container" class="relative rounded-2xl overflow-hidden bg-zinc-900 border border-zinc-700 flex items-center justify-center">
                            <div id="local-video-placeholder" class="absolute inset-0 flex items-center justify-center"><i class="fa fa-microphone text-4xl text-zinc-700"></i></div>
                            <video id="local-video" autoplay playsinline muted class="hidden absolute inset-0 w-full h-full object-contain"></video>
                            <video id="screen-share-video" autoplay playsinline muted class="hidden absolute inset-0 w-full h-full object-contain"></video>
                            <button id="pip-toggle-btn" type="button" class="hidden absolute bottom-2 left-2 z-10 text-xs bg-black/70 hover:bg-black/90 text-white rounded-lg px-2 py-1 gap-1 flex items-center" data-app-action="toggle-pip" title="<?= $tr('ui.swap_picture_in_picture') ?>"><i class="fa-solid fa-rotate"></i><span><?= $tr('ui.swap') ?></span></button>
                        </div>
                        <span class="text-sm text-zinc-300 select-none" id="local-username-label"><?= $tr('ui.you') ?></span>
                    </div>
                </div>

                <div class="shrink-0 flex gap-5 flex-wrap justify-center px-6 pb-6 pt-2">
                    <div class="flex flex-col items-center gap-1">
                        <button data-app-action="toggle-mute" id="mute-btn" class="bg-zinc-800 hover:bg-zinc-700 w-16 h-16 rounded-2xl text-xl border border-zinc-700" title="<?= $tr('ui.toggle_mute') ?>"><i class="fa fa-microphone"></i></button>
                        <span class="text-xs text-zinc-400"><?= $tr('ui.mute') ?></span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <button data-app-action="toggle-video" id="toggle-video-btn" class="bg-zinc-800 hover:bg-zinc-700 w-16 h-16 rounded-2xl text-xl border border-zinc-700" title="<?= $tr('ui.toggle_camera') ?>"><i class="fa fa-video-slash"></i></button>
                        <span class="text-xs text-zinc-400"><?= $tr('ui.camera') ?></span>
                    </div>
                    <div id="screenshare-btn-wrap" class="flex flex-col items-center gap-1 hidden">
                        <button data-app-action="toggle-screen-share" id="screenshare-btn" class="bg-zinc-800 hover:bg-zinc-700 w-16 h-16 rounded-2xl text-xl border border-zinc-700" title="<?= $tr('ui.share_screen') ?>"><i class="fa fa-display"></i></button>
                        <span class="text-xs text-zinc-400"><?= $tr('ui.share') ?></span>
                    </div>
                    <div class="flex flex-col items-center gap-1">
                        <button data-app-action="end-call" class="bg-red-600 hover:bg-red-500 w-16 h-16 rounded-2xl text-xl border border-red-700" title="<?= $tr('ui.end_call_2') ?>"><i class="fa fa-phone-slash"></i></button>
                        <span class="text-xs text-zinc-400"><?= $tr('ui.end') ?></span>
                    </div>
                </div>
            </div>

            <aside id="call-participants-panel" class="hidden shrink-0 w-full md:w-56 md:min-w-56 bg-zinc-900/80 border border-zinc-700 rounded-2xl p-2.5 min-h-0 overflow-hidden flex flex-col" aria-hidden="true">
                <div class="text-xs uppercase tracking-wide text-zinc-400 px-1 pb-2"><?= $tr('ui.participants') ?></div>
                    <div id="call-participants-list" class="flex-1 flex gap-2 md:flex-col flex-row overflow-x-auto md:overflow-y-auto min-h-0"></div>
            </aside>
        </div>

    </div>

    <div id="screenshare-modal" class="hidden fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4">
        <div class="w-full max-w-sm bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6">
            <h3 class="text-lg font-semibold text-zinc-100 mb-1"><?= $tr('ui.share_your_screen') ?></h3>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.choose_a_quality_setting_your_browser') ?></p>
            <div class="space-y-3">
                <button data-app-action="start-screen-share" data-quality="eco" class="w-full text-left bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-xl px-4 py-3 transition-colors">
                    <div class="font-medium text-zinc-100 text-sm"><?= $tr('ui.video_quality_eco') ?></div>
                    <div class="text-xs text-zinc-400 mt-0.5"><?= $tr('ui.low_bandwidth_ideal_for_slow_connections') ?></div>
                </button>
                <button data-app-action="start-screen-share" data-quality="1080p-30fps" class="w-full text-left bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-xl px-4 py-3 transition-colors">
                    <div class="font-medium text-zinc-100 text-sm"><?= $tr('ui.1080p_30_fps') ?></div>
                    <div class="text-xs text-zinc-400 mt-0.5"><?= $tr('ui.balanced_quality_and_performance') ?></div>
                </button>
                <button data-app-action="start-screen-share" data-quality="native-60fps" class="w-full text-left bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-xl px-4 py-3 transition-colors">
                    <div class="font-medium text-zinc-100 text-sm"><?= $tr('ui.native_resolution_60_fps') ?></div>
                    <div class="text-xs text-zinc-400 mt-0.5"><?= $tr('ui.highest_quality_requires_fast_connection') ?></div>
                </button>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm text-zinc-300">
                <input type="checkbox" id="remember-screenshare-quality" class="accent-emerald-500">
                <?= $tr('ui.remember_video_quality') ?>
            </label>
            <button data-app-action="close-screen-share" class="mt-4 w-full text-center text-sm text-zinc-400 hover:text-zinc-200 py-2"><?= $tr('ui.cancel') ?></button>
        </div>
    </div>

    <div id="call-settings-modal" class="hidden fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4">
        <div class="max-h-[90vh] w-full max-w-sm overflow-y-auto bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6">
            <h3 class="text-lg font-semibold text-zinc-100 mb-1"><?= $tr('ui.call_settings_2') ?></h3>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.select_your_audio_and_video_devices') ?></p>
            <div class="space-y-4">
                <div>
                    <label for="call-settings-mic" class="block text-sm font-medium text-zinc-300 mb-1.5"><?= $tr('ui.microphone') ?></label>
                    <select id="call-settings-mic" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-3 py-2.5 text-sm text-zinc-100 focus:outline-none focus:border-zinc-500 disabled:opacity-50 disabled:cursor-not-allowed">
                        <option value=""><?= $tr('ui.loading_devices') ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-300 mb-1.5"><?= $tr('ui.microphone_volume') ?></label>
                    <input id="call-settings-volume" type="range" min="0" max="100" value="100" class="w-full accent-emerald-500 h-1.5 bg-zinc-700 rounded-full appearance-none cursor-pointer">
                    <div class="mt-2 w-full h-1 bg-zinc-800 rounded-full overflow-hidden">
                        <div id="call-settings-level" class="h-full bg-emerald-500 rounded-full transition-none"></div>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-zinc-300">
                    <input type="checkbox" id="call-noise-suppression" class="accent-emerald-500">
                    <?= $tr('ui.call_noise_suppression') ?>
                </label>
                <div>
                    <label class="block text-sm font-medium text-zinc-300 mb-1.5"><?= $tr('ui.speaker_volume') ?></label>
                    <input id="call-settings-speaker-volume" type="range" min="0" max="100" value="100" class="w-full accent-emerald-500 h-1.5 bg-zinc-700 rounded-full appearance-none cursor-pointer">
                </div>
                <div id="call-settings-camera-wrap" class="hidden">
                    <label for="call-settings-camera" class="block text-sm font-medium text-zinc-300 mb-1.5"><?= $tr('ui.camera') ?></label>
                    <select id="call-settings-camera" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-3 py-2.5 text-sm text-zinc-100 focus:outline-none focus:border-zinc-500 disabled:opacity-50 disabled:cursor-not-allowed">
                        <option value=""><?= $tr('ui.loading_devices') ?></option>
                    </select>
                </div>
                <form id="call-camera-quality-form" class="space-y-3">
                    <label for="call-camera-quality" class="block text-sm font-medium text-zinc-300"><?= $tr('ui.webcam_quality') ?></label>
                    <select id="call-camera-quality" class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-3 py-2.5 text-sm text-zinc-100">
                        <?php foreach (\Causette\Calls\CallVideoPreferences::labels('camera') as $quality => $qualityLabel): ?>
                            <option value="<?= $quality ?>"><?= $tr($qualityLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="flex items-center gap-2 text-sm text-zinc-300">
                        <input type="checkbox" id="remember-camera-quality" class="accent-emerald-500">
                        <?= $tr('ui.remember_video_quality') ?>
                    </label>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm text-white hover:bg-emerald-500 disabled:opacity-50"><?= $tr('ui.apply_video_quality') ?></button>
                </form>
            </div>
            <button data-app-action="close-call-settings" class="mt-5 w-full text-center text-sm text-zinc-400 hover:text-zinc-200 py-2"><?= $tr('ui.close') ?></button>
        </div>
    </div>
    </div>

    <div id="mobile-overlay-backdrop"></div>


    <div id="report-modal" class="hidden fixed inset-0 bg-black/70 z-50 p-4 md:p-6" aria-hidden="true">
        <div class="h-full w-full flex items-center justify-center">
            <div class="w-full max-w-md bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6" role="dialog" aria-modal="true" aria-labelledby="report-modal-title">
                <h2 id="report-modal-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.report_content') ?></h2>
                <p id="report-modal-description" class="mt-2 text-sm text-zinc-400"><?= $tr('ui.help_us_understand_the_issue_by') ?></p>
                <form id="report-form" class="mt-4 space-y-4">
                    <label for="report-reason-input" class="block text-sm text-zinc-300"><?= $tr('ui.reason_2') ?></label>
                    <textarea id="report-reason-input" rows="4" maxlength="200" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100 resize-y" placeholder="<?= $tr('ui.describe_the_issue') ?>" required></textarea>
                    <div class="text-xs text-zinc-400 text-right" id="report-reason-counter" aria-live="polite">0/200</div>
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" id="report-cancel" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200"><?= $tr('ui.cancel') ?></button>
                        <button type="submit" id="report-submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white"><?= $tr('ui.submit_report') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="timezone-suggest-modal" class="hidden fixed inset-0 bg-black/70 z-50 p-4 md:p-6" aria-hidden="true">
        <div class="h-full w-full flex items-center justify-center">
            <div class="w-full max-w-md bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6" role="dialog" aria-modal="true" aria-labelledby="timezone-suggest-title">
                <h2 id="timezone-suggest-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.update_time_zone') ?></h2>
                <p class="mt-2 text-sm text-zinc-400"><?= $tr('ui.your_account_uses_utc_0_your') ?> <span id="timezone-suggest-value" class="text-zinc-200 font-medium">UTC+0</span>.</p>
                <p class="mt-2 text-sm text-zinc-400"><?= $tr('ui.would_you_like_to_switch_automatically') ?></p>
                <p id="timezone-suggest-status" role="status" class="mt-2 text-sm text-zinc-200"></p>
                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" id="timezone-suggest-decline" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200"><?= $tr('ui.keep_utc_0') ?></button>
                    <button type="button" id="timezone-suggest-accept" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white"><?= $tr('ui.switch_time_zone') ?></button>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="min-h-[100dvh] flex items-start sm:items-center justify-center px-4 py-6 sm:py-8">
        <div class="w-full max-w-md">
            <div class="text-center mb-6">
                <div class="inline-flex items-center gap-2 text-2xl font-bold">
                    <img src="<?= \Causette\View\AssetUrl::html('/assets/img/logo.png') ?>" width="40" height="40" alt="" class="shrink-0">
                    <span>Causette</span>
                </div>
            </div>
            <?= $content ?>
        </div>
    </div>
    <?php endif; ?>
    <div id="toast-host" class="fixed top-20 right-5 max-w-[calc(100%-2.5rem)] space-y-2 z-40" role="status" aria-live="polite"></div>

    <div id="new-post-modal" class="hidden fixed inset-0 bg-black/70 z-50 p-4 md:p-6" aria-hidden="true">
        <div class="h-full w-full flex items-center justify-center">
            <div class="w-full max-w-lg bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6" role="dialog" aria-modal="true" aria-labelledby="new-post-modal-title">
                <div class="flex items-center justify-between mb-4">
                    <h2 id="new-post-modal-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.new_post') ?></h2>
                    <button type="button" id="new-post-modal-close" class="w-8 h-8 flex items-center justify-center rounded-lg text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800 transition" aria-label="<?= $tr('ui.close') ?>">
                        <i class="fa fa-xmark"></i>
                    </button>
                </div>
                <form id="new-post-modal-form" class="space-y-3">
                    <textarea id="new-post-modal-input" rows="5" maxlength="500" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-3 text-zinc-100 resize-y" placeholder="<?= $tr('ui.share_what_you_re_thinking') ?>" required></textarea>
                    <div class="flex items-center justify-between gap-3">
                        <span id="new-post-modal-counter" class="text-xs text-zinc-400">0/500</span>
                        <div class="flex items-center gap-3">
                            <button type="button" id="new-post-modal-cancel" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200 text-sm transition"><?= $tr('ui.cancel') ?></button>
                            <button type="submit" id="new-post-modal-submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm transition"><?= $tr('ui.publish') ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="profile-post-delete-modal" class="hidden fixed inset-0 bg-black/70 z-50 p-4 md:p-6" aria-hidden="true">
        <div class="h-full w-full flex items-center justify-center">
            <div class="w-full max-w-md bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6" role="dialog" aria-modal="true" aria-labelledby="profile-post-delete-title">
                <h2 id="profile-post-delete-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.delete_post') ?></h2>
                <p id="profile-post-delete-description" class="mt-2 text-sm text-zinc-400"><?= $tr('ui.are_you_sure_you_want_to_6') ?></p>
                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" id="profile-post-delete-cancel" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200"><?= $tr('ui.cancel') ?></button>
                    <button type="button" id="profile-post-delete-submit" class="px-4 py-2 rounded-xl bg-red-700 hover:bg-red-600 text-white"><?= $tr('ui.delete') ?></button>
                </div>
            </div>
        </div>
    </div>
    <?php if ($currentUser): ?>
    <div id="server-connection-overlay" class="hidden fixed inset-0 bg-black/90 z-[9998] flex items-center justify-center p-4 md:p-6" aria-hidden="true">
        <div class="w-full max-w-md bg-zinc-900 p-8 rounded-3xl border border-zinc-700 text-center shadow-2xl">
            <div class="mb-4 text-4xl text-amber-400"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <h2 class="text-2xl font-bold mb-2 text-zinc-100"><?= $tr('ui.unable_to_connect_to_server') ?></h2>
            <p class="text-zinc-400 mb-5"><?= $tr('ui.we_lost_connection_and_are_trying') ?></p>
            <ul class="text-left text-sm text-zinc-300 space-y-2 mb-6">
                <li class="flex items-start gap-2"><i class="fa-solid fa-circle-check mt-1 text-zinc-500 text-[10px]"></i><span><?= $tr('ui.check_your_internet_connection') ?></span></li>
                <li class="flex items-start gap-2"><i class="fa-solid fa-circle-check mt-1 text-zinc-500 text-[10px]"></i><span><?= $tr('ui.check_your_server_status') ?></span></li>
                <li class="flex items-start gap-2"><i class="fa-solid fa-circle-check mt-1 text-zinc-500 text-[10px]"></i><span><?= $tr('ui.try_again_in_a_few_minutes') ?></span></li>
            </ul>
            <button id="server-connection-retry" type="button" class="w-full py-3 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold transition"><?= $tr('ui.retry_now') ?></button>
        </div>
    </div>
    <?php endif; ?>
    <div id="version-update-overlay" class="hidden fixed inset-0 bg-black/90 z-[9999] flex items-center justify-center p-6">
        <div class="text-center">
            <div class="mb-5 text-5xl text-emerald-400"><i class="fa-solid fa-rotate"></i></div>
            <h2 class="text-xl font-semibold text-zinc-100 mb-2"><?= $tr('ui.system_update_installed') ?></h2>
            <p class="text-zinc-400 mb-6"><?= $tr('ui.please_refresh_the_page_to_continue') ?></p>
            <button data-app-action="reload-page" class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-medium transition"><?= $tr('ui.refresh_page') ?></button>
        </div>
    </div>
</body>
<script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>">
    window.CAUSETTE_BASE_PATH = <?= \Causette\View\HtmlJson::encode(defined('CAUSETTE_PUBLIC_PATH') ? CAUSETTE_PUBLIC_PATH : '') ?>;
    window.CAUSETTE_UI = <?= \Causette\View\HtmlJson::encode([
        'language' => $locale->language(),
        'labels' => $locale->catalog('browser'),
        'dates' => $locale->catalog('dates'),
    ]) ?>;
    window.CAUSETTE_CALLS = <?= \Causette\View\HtmlJson::encode([
        'labels' => $locale->catalog('calls'),
        'basePath' => defined('CAUSETTE_PUBLIC_PATH') ? CAUSETTE_PUBLIC_PATH : '',
        'iceServers' => \Causette\Calls\CallIceServers::configured($currentUser ? Setting::get('webrtc_stun_servers') : null),
        'videoQuality' => $currentUser ? ($callVideoDefaults ?? \Causette\Calls\CallVideoPreferences::read((int)$currentUser->id)) : [],
    ]) ?>;
    window.APP_VERSION = <?= \Causette\View\HtmlJson::encode(APP_VERSION) ?>;
    window.CSRF_TOKEN = <?= \Causette\View\HtmlJson::encode($csrf) ?>;
    window.BROWSER_NOTIFICATIONS_ENABLED = <?= $browserNotificationsEnabled ? 'true' : 'false' ?>;
    window.WEB_PUSH_NOTIFICATIONS_ENABLED = <?= $webPushNotificationsEnabled ? 'true' : 'false' ?>;
    window.NOTIFICATION_SOUND_POKE_ENABLED = <?= $pokeSoundEnabled ? 'true' : 'false' ?>;
    window.NOTIFICATION_SOUND_NEW_MESSAGE_ENABLED = <?= $newMessageSoundEnabled ? 'true' : 'false' ?>;
    window.NOTIFICATION_SOUND_OTHER_ENABLED = <?= $otherNotificationSoundEnabled ? 'true' : 'false' ?>;
    window.NOTIFICATION_SOUND_OUTGOING_CALL_RING_ENABLED = <?= $outgoingCallRingSoundEnabled ? 'true' : 'false' ?>;
    window.NOTIFICATION_SIDEBAR_EXPANDED = <?= $notificationSidebarExpanded ? 'true' : 'false' ?>;
    window.USER_TIMEZONE = <?= \Causette\View\HtmlJson::encode($userTimezone) ?>;
    window.CURRENT_USER_ID = <?= \Causette\View\HtmlJson::encode((int)($currentUser->id ?? 0)) ?>;
    window.CURRENT_USER_NUMBER = <?= \Causette\View\HtmlJson::encode((string)($currentUser->user_number ?? '')) ?>;
    window.CURRENT_USERNAME = <?= \Causette\View\HtmlJson::encode((string)($currentUser->username ?? '')) ?>;
    window.CURRENT_USER_AVATAR_URL = <?= \Causette\View\HtmlJson::encode($currentUserAvatarUrl ?? '') ?>;
    window.TOP_LOADING_BAR_ENABLED = <?= $topLoadingBarEnabled ? 'true' : 'false' ?>;
    window.SYSTEM_MESSAGES_AUTO_COMBINE_ENABLED = <?= $systemMessagesAutoCombineEnabled ? 'true' : 'false' ?>;
    window.CURRENT_USER_PRESENCE_STATUS = <?= \Causette\View\HtmlJson::encode(User::normalizePresenceStatus($currentUser->presence_status ?? null) ?? 'online') ?>;
    window.CAUSETTE_REACTION_LABELS = <?= \Causette\View\HtmlJson::encode(\Causette\Chat\ReactionCatalog::labels($locale)) ?>;
    window.CAUSETTE_REACTION_GROUPS = <?= \Causette\View\HtmlJson::encode(\Causette\Chat\ReactionCatalog::GROUPS) ?>;
    window.CAUSETTE_EMOJI_ARTWORK = <?= \Causette\View\HtmlJson::encode($currentUser ? \Causette\Chat\ReactionCatalog::artwork() : []) ?>;
    window.OPENMOJI_FILES = <?= \Causette\View\HtmlJson::encode($openMojiFileNames) ?>;
    window.OPENMOJI_METADATA = <?= \Causette\View\HtmlJson::encode($openMojiMetadata) ?>;
    window.CAUSETTE_EMOJI_ANNOTATIONS_URL = <?= \Causette\View\HtmlJson::encode($locale->language() === 'fr' ? base_path('/assets/locales/emoji-fr.json') : null) ?>;
</script>
<?php endif; ?>
<?php foreach ($scripts as $script): ?>
    <script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>" data-app-script="<?= $script ?>" src="<?= \Causette\View\AssetUrl::html('/assets/js/' . $script) ?>"></script>
<?php endforeach; ?>
</html>
