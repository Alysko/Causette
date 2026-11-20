<?php
$locale = \Causette\Localization\Locale::current();
$tr = $locale->html(...);
require_once __DIR__ . '/../core/HtmlJson.php';
?>
<div class="p-8 overflow-auto space-y-8">
    <h1 class="text-3xl font-bold"><?= $tr('ui.settings') ?></h1>

    <?php
        $toastMessage = '';
        $toastKind = 'info';
        $currentAvatarUrl = User::avatarUrl($user);
        $autoOpenModalId = '';

        $flashSuccess = flash_get('success');
        $flashError = flash_get('error');
        $mailTestError = flash_get('mail_test_error');

        // Settings flash messages
        if ($flashError === 'integration_storage_error') {
            $toastMessage = $locale->text('feedback.integration_storage_error');
            $toastKind = 'error';
        } elseif ($flashSuccess === 'email_saved') {
            $toastMessage = $locale->text('feedback.email_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'email_change_sent') {
            $toastMessage = $locale->text('feedback.email_change_sent');
            $toastKind = 'success';
            $autoOpenModalId = 'cp-email-modal';
        } elseif ($flashSuccess === 'password_saved') {
            $toastMessage = $locale->text('feedback.password_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'username_saved') {
            $toastMessage = $locale->text('feedback.username_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'avatar_saved') {
            $toastMessage = $locale->text('feedback.avatar_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'avatar_removed') {
            $toastMessage = $locale->text('feedback.avatar_removed');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'notifications_saved') {
            $toastMessage = $locale->text('feedback.notifications_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'timezone_saved') {
            $toastMessage = $locale->text('feedback.timezone_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'preferences_saved') {
            $toastMessage = $locale->text('feedback.preferences_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'invite_created') {
            $toastMessage = $locale->text('feedback.invite_created');
            $toastKind = 'success';
            $autoOpenModalId = 'cp-invites-modal';
        } elseif ($flashSuccess === 'invite_deleted') {
            $toastMessage = $locale->text('feedback.invite_deleted');
            $toastKind = 'success';
            $autoOpenModalId = 'cp-invites-modal';
        } elseif ($flashSuccess === 'session_exited') {
            $toastMessage = $locale->text('feedback.session_exited');
            $toastKind = 'success';
        // Admin flash messages
        } elseif ($flashSuccess === 'mail_saved') {
            $toastMessage = $locale->text('feedback.mail_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'push_saved') {
            $toastMessage = $locale->text('feedback.push_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'mail_test_sent') {
            $toastMessage = $locale->text('feedback.mail_test_sent');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'accounts_saved') {
            $toastMessage = $locale->text('feedback.accounts_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'more_saved') {
            $toastMessage = $locale->text('feedback.settings_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'attachments_saved') {
            $toastMessage = $locale->text('feedback.attachments_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'captcha_saved') {
            $toastMessage = $locale->text('feedback.captcha_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'announcement_saved') {
            $toastMessage = $locale->text('feedback.announcement_saved');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'storage_recalculated') {
            $toastMessage = $locale->text('feedback.storage_recalculated');
            $toastKind = 'success';
        // Error flash messages
        } elseif ($flashError === 'invite_limit') {
            $toastMessage = $locale->text('feedback.invite_limit');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-invites-modal';
        } elseif ($flashError === 'invite_disabled') {
            $toastMessage = $locale->text('feedback.invite_disabled');
            $toastKind = 'error';
        } elseif ($flashError === 'invite_delete_unavailable') {
            $toastMessage = $locale->text('feedback.invite_delete_unavailable');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-invites-modal';
        } elseif ($flashError === 'email_invalid') {
            $toastMessage = $locale->text('feedback.email_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-email-modal';
        } elseif ($flashError === 'email_taken') {
            $toastMessage = $locale->text('feedback.email_taken');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-email-modal';
        } elseif ($flashError === 'email_change_code_invalid') {
            $toastMessage = $locale->text('feedback.email_change_code_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-email-modal';
        } elseif ($flashError === 'email_change_expired') {
            $toastMessage = $locale->text('feedback.email_change_expired');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-email-modal';
        } elseif (in_array($flashError, ['email_change_password_invalid', 'email_change_limit', 'email_change_delivery_failed'], true)) {
            $toastMessage = match ($flashError) {
                'email_change_limit' => $locale->text('feedback.email_change_limit'),
                'email_change_delivery_failed' => $locale->text('feedback.email_change_delivery_failed'),
                default => $locale->text('feedback.email_change_password_invalid'),
            };
            $toastKind = 'error';
            $autoOpenModalId = 'cp-email-modal';
        } elseif ($flashError === 'password_current_invalid') {
            $toastMessage = $locale->text('feedback.password_current_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-password-modal';
        } elseif ($flashError === 'password_invalid') {
            $toastMessage = $locale->text('feedback.password_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-password-modal';
        } elseif ($flashError === 'password_mismatch') {
            $toastMessage = $locale->text('feedback.password_mismatch');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-password-modal';
        } elseif ($flashError === 'username_invalid') {
            $toastMessage = $locale->text('feedback.username_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-username-modal';
        } elseif ($flashError === 'username_same') {
            $toastMessage = $locale->text('feedback.username_same');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-username-modal';
        } elseif ($flashError === 'username_taken') {
            $toastMessage = $locale->text('feedback.username_taken');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-username-modal';
        } elseif ($flashError === 'username_cooldown') {
            $toastMessage = $locale->text('feedback.username_cooldown');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-username-modal';
        } elseif ($flashError === 'avatar_invalid_type') {
            $toastMessage = $locale->text('feedback.avatar_invalid_type');
            $toastKind = 'error';
        } elseif ($flashError === 'avatar_too_large') {
            $toastMessage = $locale->text('feedback.avatar_too_large');
            $toastKind = 'error';
        } elseif ($flashError === 'avatar_upload_failed') {
            $toastMessage = $locale->text('feedback.avatar_upload_failed');
            $toastKind = 'error';
        } elseif ($flashError === 'session_not_found') {
            $toastMessage = $locale->text('feedback.session_not_found');
            $toastKind = 'error';
        } elseif ($flashError === 'update_check_unconfigured') {
            $toastMessage = $locale->text('ui.automatic_update_checks_are_unavailable_until');
            $toastKind = 'error';
        } elseif ($flashError === 'storage_recalculate_failed') {
            $toastMessage = $locale->text('feedback.storage_recalculate_failed');
            $toastKind = 'error';
        } elseif ($flashSuccess === 'totp_setup_started') {
            $autoOpenModalId = 'cp-2fa-modal';
        } elseif ($flashSuccess === 'totp_enabled') {
            $toastMessage = $locale->text('feedback.totp_enabled');
            $toastKind = 'success';
            $autoOpenModalId = 'cp-2fa-modal';
        } elseif ($flashSuccess === 'totp_disabled') {
            $toastMessage = $locale->text('feedback.totp_disabled');
            $toastKind = 'success';
        } elseif (in_array($flashSuccess, ['totp_frequency_saved', 'totp_trust_revoked'], true)) {
            $toastMessage = $flashSuccess === 'totp_trust_revoked' ? $locale->text('feedback.totp_trust_revoked') : $locale->text('feedback.totp_frequency_saved');
            $toastKind = 'success';
            $autoOpenModalId = 'cp-2fa-modal';
        } elseif (in_array($flashError, ['totp_frequency_invalid', 'totp_challenge_limit'], true)) {
            $toastMessage = $flashError === 'totp_challenge_limit' ? $locale->text('feedback.totp_challenge_limit') : $locale->text('feedback.totp_frequency_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-2fa-modal';
        } elseif ($flashError === 'totp_already_enabled') {
            $toastMessage = $locale->text('feedback.totp_already_enabled');
            $toastKind = 'error';
        } elseif ($flashError === 'totp_setup_expired') {
            $toastMessage = $locale->text('feedback.totp_setup_expired');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-2fa-modal';
        } elseif ($flashError === 'totp_invalid_code') {
            $toastMessage = $locale->text('feedback.totp_invalid_code');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-2fa-modal';
        } elseif ($flashError === 'totp_password_invalid') {
            $toastMessage = $locale->text('feedback.totp_password_invalid');
            $toastKind = 'error';
            $autoOpenModalId = 'cp-2fa-modal';
        }
    ?>

    <?php if ($toastMessage !== ''): ?>
        <div
            id="page-toast"
            data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>"
            data-toast-kind="<?= htmlspecialchars($toastKind, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden"
            aria-hidden="true"
        ></div>
    <?php endif; ?>

    <?php if (!empty($mailTestError)): ?>
        <div class="max-w-2xl rounded-xl border border-red-700 bg-red-950/60 px-5 py-4">
            <p class="text-sm font-semibold text-red-300 mb-1"><?= $tr('ui.test_email_failed') ?></p>
            <p class="text-sm text-red-200 break-words"><?= htmlspecialchars($mailTestError, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php endif; ?>

    <?php if ($autoOpenModalId !== ''): ?>
        <div id="cp-modal-autoload" data-modal-id="<?= htmlspecialchars($autoOpenModalId, ENT_QUOTES, 'UTF-8') ?>" class="hidden" aria-hidden="true"></div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- ADMINISTRATION (admin only) -->
    <!-- ============================================================ -->
    <?php if (!empty($isAdmin)): ?>
    <div>
        <h2 class="text-xl font-semibold mb-4"><?= $tr('ui.administration') ?></h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <a href="<?= htmlspecialchars(base_url('/reports'), ENT_QUOTES, 'UTF-8') ?>" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1] relative">
                <i class="fa-regular fa-flag text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.reports') ?></span>
                <?php if (((int)($pendingReportCount ?? 0)) > 0): ?>
                    <span class="absolute top-3 right-3 min-w-[1.25rem] h-5 px-1 rounded-full bg-red-600 text-white text-xs inline-flex items-center justify-center">
                        <?= htmlspecialchars((string)min(99, (int)($pendingReportCount ?? 0)) . (((int)($pendingReportCount ?? 0) > 99) ? '+' : ''), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= htmlspecialchars(base_url('/users'), ENT_QUOTES, 'UTF-8') ?>" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-user-shield text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.users') ?></span>
            </a>
            <a href="<?= htmlspecialchars(base_url('/tree'), ENT_QUOTES, 'UTF-8') ?>" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-diagram-project text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.tree') ?></span>
            </a>
            <a href="<?= htmlspecialchars(base_url('/trash'), ENT_QUOTES, 'UTF-8') ?>" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-regular fa-trash-can text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.trash') ?></span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- SYSTEM (admin only) -->
    <!-- ============================================================ -->
    <?php if (!empty($isAdmin)): ?>
    <div>
        <h2 class="text-xl font-semibold mb-4"><?= $tr('ui.system') ?></h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <button type="button" data-modal-open="cp-mail-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-envelope text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.mail') ?></span>
            </button>
            <button type="button" data-modal-open="cp-accounts-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-users-gear text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.accounts') ?></span>
            </button>
            <button type="button" data-modal-open="cp-attachments-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-paperclip text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.attachments') ?></span>
            </button>
            <button type="button" data-modal-open="cp-security-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-shield-halved text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.security') ?></span>
            </button>
            <button type="button" data-modal-open="cp-errors-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-bug text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.errors_logging') ?></span>
            </button>
            <button type="button" data-modal-open="cp-updates-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-rotate text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.updates') ?></span>
            </button>
            <button type="button" data-modal-open="cp-announcement-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-bullhorn text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.announcement') ?></span>
            </button>
            <button type="button" data-modal-open="cp-push-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-bell text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.push_notifications') ?></span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- ACCOUNT (all users) -->
    <!-- ============================================================ -->
    <div>
        <h2 class="text-xl font-semibold mb-4"><?= $tr('ui.account') ?></h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <?php if (!empty($invitesEnabled)): ?>
            <button type="button" data-modal-open="cp-invites-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-ticket text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.invites') ?></span>
            </button>
            <?php endif; ?>
            <button type="button" data-modal-open="cp-username-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-at text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.username') ?></span>
            </button>
            <button type="button" data-modal-open="cp-email-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-envelope-open-text text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.email_address_2') ?></span>
            </button>
            <button type="button" data-modal-open="cp-password-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-lock text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.change_password') ?></span>
            </button>
            <button type="button" data-modal-open="cp-avatar-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-image text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.avatar') ?></span>
            </button>
            <button type="button" data-modal-open="cp-timezone-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-clock text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.time_zone') ?></span>
            </button>
            <button type="button" data-modal-open="cp-sessions-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-right-from-bracket text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.sessions') ?></span>
            </button>
            <button type="button" data-modal-open="cp-2fa-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-mobile-screen text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.two_factor_short') ?></span>
            </button>
            <button type="button" data-modal-open="cp-notifications-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-bell text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.notifications') ?></span>
            </button>
            <a href="<?= htmlspecialchars(base_url('/apikeys'), ENT_QUOTES, 'UTF-8') ?>" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-key text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.api_keys') ?></span>
            </a>
            <button type="button" data-modal-open="cp-preferences-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-sliders text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.preferences') ?></span>
            </button>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MORE (all users) -->
    <!-- ============================================================ -->
    <div>
        <h2 class="text-xl font-semibold mb-4"><?= $tr('ui.more') ?></h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <button type="button" data-modal-open="cp-browser-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-globe text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium"><?= $tr('ui.test_browser_features') ?></span>
            </button>
            <button type="button" data-modal-open="cp-about-modal" class="bg-zinc-900 border border-zinc-700 rounded-2xl p-4 flex flex-col items-center justify-center gap-2 hover:bg-zinc-800 transition cursor-pointer aspect-[2/1]">
                <i class="fa-solid fa-circle-info text-2xl text-emerald-400"></i>
                <span class="text-sm text-zinc-200 font-medium text-center"><?= $tr('ui.about') ?></span>
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODALS -->
<!-- ============================================================ -->

<?php
    // Shared modal wrapper classes
    $modalOverlayClass = 'fixed inset-0 z-50 hidden';
    $modalBackdropClass = 'absolute inset-0 bg-black/70';
    $modalCenterClass = 'relative z-10 flex min-h-full items-center justify-center p-4 lg:p-8';
    $modalBoxClass = 'w-full max-w-lg rounded-2xl border border-zinc-700 bg-zinc-900 p-6 max-h-[90vh] overflow-y-auto max-lg:!max-w-none max-lg:!rounded-none max-lg:!border-0 max-lg:min-h-[100dvh] max-lg:!max-h-none max-lg:flex max-lg:flex-col';
?>

<!-- ==================== ADMIN MODALS ==================== -->
<?php if (!empty($isAdmin)): ?>

<!-- Mail Modal -->
<div id="cp-mail-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-mail-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.mail') ?></h3>
                <button type="button" data-modal-close="cp-mail-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.smtp_settings_used_to_send_verification') ?></p>
            <form method="POST" action="<?= htmlspecialchars(base_path('/admin/mail'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label for="mail_host" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.host') ?></label>
                        <input type="text" id="mail_host" name="mail_host" value="<?= htmlspecialchars((string)($mail_host ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.example.com" autocomplete="off" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                    </div>
                    <div>
                        <label for="mail_port" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.port') ?></label>
                        <input type="number" id="mail_port" name="mail_port" value="<?= htmlspecialchars((string)($mail_port ?? '587'), ENT_QUOTES, 'UTF-8') ?>" placeholder="587" min="1" max="65535" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                    </div>
                </div>
                <div>
                    <label for="mail_user" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.username') ?></label>
                    <input type="text" id="mail_user" name="mail_user" value="<?= htmlspecialchars((string)($mail_user ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="user@example.com" autocomplete="off" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                </div>
                <div>
                    <label for="mail_pass" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.password') ?></label>
                    <input type="password" id="mail_pass" name="mail_pass" value="" placeholder="<?= $tr('ui.leave_blank_to_keep_current_password') ?>" autocomplete="new-password" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                </div>
                <div>
                    <label for="mail_from" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.from_address') ?></label>
                    <input type="text" id="mail_from" name="mail_from" value="<?= htmlspecialchars((string)($mail_from ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="noreply@example.com" autocomplete="off" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                </div>
                <div>
                    <label for="mail_from_name" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.from_name') ?></label>
                    <input type="text" id="mail_from_name" name="mail_from_name" value="<?= htmlspecialchars((string)($mail_from_name ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $tr('ui.my_app') ?>" autocomplete="off" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                </div>
                <div class="pt-2 flex flex-wrap items-center gap-3">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save_mail_settings') ?></button>
                </div>
            </form>
            <div class="mt-5 pt-5 border-t border-zinc-700">
                <p class="text-sm text-zinc-400 mb-3"><?= $tr('ui.send_a_test_email_to_your') ?></p>
                <form method="POST" action="<?= htmlspecialchars(base_path('/admin/mail/test'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-zinc-700 hover:bg-zinc-600 text-zinc-100 rounded-xl font-medium transition">
                        <i class="fa-regular fa-paper-plane"></i>
                        <?= $tr('ui.send_test_email') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Accounts Modal -->
<div id="cp-accounts-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-accounts-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.accounts') ?></h3>
                <button type="button" data-modal-close="cp-accounts-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.control_how_new_accounts_are_created') ?></p>
            <form method="POST" action="<?= htmlspecialchars(base_path('/admin/accounts'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.require_invite_code_to_register') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.new_accounts_must_provide_a_valid') ?></span>
                    </div>
                    <input type="checkbox" name="invite_code_required" value="1" <?= !empty($invite_code_required) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 shrink-0">
                </label>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.allow_users_to_generate_invite_codes') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.users_can_create_and_share_invite') ?></span>
                    </div>
                    <input type="checkbox" name="invites_enabled" value="1" <?= !empty($admin_invites_enabled) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 shrink-0">
                </label>
                <div>
                    <label for="invite_codes_per_user" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.invite_codes_per_user') ?></label>
                    <input type="number" id="invite_codes_per_user" name="invite_codes_per_user" value="<?= (int)($invite_codes_per_user ?? 3) ?>" min="0" max="100" class="w-32 bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                    <p class="text-xs text-zinc-500 mt-1"><?= $tr('ui.maximum_invite_codes_each_user_can') ?></p>
                </div>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.require_email_verification') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.new_accounts_must_verify_their_email') ?></span>
                    </div>
                    <input type="checkbox" name="email_verification_required" value="1" <?= !empty($email_verification_required) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 shrink-0">
                </label>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.email_admin_on_new_registration') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.send_a_notification_email_to_all') ?></span>
                    </div>
                    <input type="checkbox" name="new_user_notification" value="1" <?= !empty($new_user_notification) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 shrink-0">
                </label>
                <div>
                    <label for="new_user_auto_join_group" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.auto_join_new_users_to_group') ?></label>
                    <select id="new_user_auto_join_group" name="new_user_auto_join_group" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                        <option value=""><?= $tr('ui.none') ?></option>
                        <?php foreach (($all_group_chats ?? []) as $gc): ?>
                            <?php
                                $gcTitle = trim((string)($gc->title ?? ''));
                                $gcLabel = $gcTitle !== '' ? $gcTitle : User::formatUserNumber($gc->chat_number);
                            ?>
                            <option value="<?= (int)$gc->id ?>" <?= ((string)($new_user_auto_join_group ?? '') === (string)$gc->id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gcLabel, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-zinc-500 mt-1"><?= $tr('ui.automatically_add_new_users_to_this') ?></p>
                </div>
                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save_account_settings') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Attachments Modal -->
<div id="cp-attachments-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-attachments-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.attachments') ?></h3>
                <button type="button" data-modal-close="cp-attachments-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.control_which_file_types_users_can') ?></p>
            <?php
                $rawTypes = strtolower((string)($attachments_accepted_file_types ?? 'png,jpg'));
                $enabledTypes = array_flip(array_map('trim', explode(',', $rawTypes)));
                $typeCheck = function(string $ext) use ($enabledTypes): string {
                    return isset($enabledTypes[$ext]) ? 'checked' : '';
                };
            ?>
            <form method="POST" action="<?= htmlspecialchars(base_path('/admin/attachments'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-5" id="cp-attachments-type-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="flex items-center justify-end">
                    <button type="button" id="cp-attachments-select-all-btn" class="text-xs text-zinc-400 hover:text-zinc-200 underline underline-offset-2"><?= $tr('ui.select_all') ?></button>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-zinc-500 mb-2"><?= $tr('ui.images') ?></p>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ([['png', 'PNG'], ['jpg', 'JPG'], ['webp', 'WebP']] as [$ext, $label]): ?>
                        <label class="flex items-center gap-2.5 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="type_<?= $ext ?>" value="1" <?= $typeCheck($ext) ?> class="w-4 h-4 accent-emerald-500">
                            <span class="text-zinc-100 text-sm"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-zinc-500 mb-2"><?= $tr('ui.video') ?></p>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ([['mp4', 'MP4'], ['webm', 'WebM']] as [$ext, $label]): ?>
                        <label class="flex items-center gap-2.5 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="type_<?= $ext ?>" value="1" <?= $typeCheck($ext) ?> class="w-4 h-4 accent-emerald-500">
                            <span class="text-zinc-100 text-sm"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-zinc-500 mb-2"><?= $tr('ui.files') ?></p>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ([['pdf', 'PDF'], ['odt', 'ODT'], ['doc', 'DOC'], ['docx', 'DOCX'], ['zip', 'ZIP'], ['7z', '7Z']] as [$ext, $label]): ?>
                        <label class="flex items-center gap-2.5 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="type_<?= $ext ?>" value="1" <?= $typeCheck($ext) ?> class="w-4 h-4 accent-emerald-500">
                            <span class="text-zinc-100 text-sm"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label for="cp_attachments_maximum_file_size_mb" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.maximum_file_size_mb') ?></label>
                    <input type="number" id="cp_attachments_maximum_file_size_mb" name="attachments_maximum_file_size_mb" value="<?= (int)($attachments_maximum_file_size_mb ?? 10) ?>" min="1" max="512" class="w-32 bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                    <p class="text-xs text-zinc-500 mt-1"><?= $tr('ui.upload_limit_php', [
                        'upload' => 'upload_max_filesize', 'post' => 'post_max_size',
                    ]) ?></p>
                </div>
                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save_attachment_settings') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Security Modal -->
<div id="cp-security-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-security-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.security') ?></h3>
                <button type="button" data-modal-close="cp-security-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.rate_limit_status_for_failed_authentication') ?></p>
            <div class="space-y-3">
                <div class="rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 flex items-center justify-between gap-4">
                    <span class="text-zinc-200"><?= $tr('ui.failed_login_attempts_last_24h') ?></span>
                    <span class="text-zinc-100 font-semibold"><?= (int)($failed_login_attempts_24h ?? 0) ?></span>
                </div>
                <div class="rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 flex items-center justify-between gap-4">
                    <span class="text-zinc-200"><?= $tr('ui.failed_account_creation_attempts_last_24h') ?></span>
                    <span class="text-zinc-100 font-semibold"><?= (int)($failed_registration_attempts_24h ?? 0) ?></span>
                </div>
            </div>
            <div class="mt-5 pt-5 border-t border-zinc-700">
                <h4 class="text-sm font-semibold text-zinc-200 mb-3"><?= $tr('ui.currently_banned_ips') ?></h4>
                <?php $activeBannedIps = $active_banned_ips ?? []; ?>
                <?php if (!empty($activeBannedIps)): ?>
                    <div class="space-y-2">
                        <?php foreach ($activeBannedIps as $bannedIp): ?>
                            <div class="rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 flex items-center justify-between gap-4">
                                <span class="text-zinc-200 font-mono"><?= htmlspecialchars((string)$bannedIp->ip_address, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-zinc-100 text-sm">
                                    <?= (int)($bannedIp->minutes_left ?? 0) ?> minute<?= ((int)($bannedIp->minutes_left ?? 0) === 1) ? '' : 's' ?> left
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-zinc-500"><?= $tr('ui.no_ips_are_currently_banned') ?></p>
                <?php endif; ?>
            </div>
            <div class="mt-5 pt-5 border-t border-zinc-700">
                <h4 class="text-sm font-semibold text-zinc-200 mb-3"><?= $tr('ui.captcha') ?></h4>
                <p class="text-xs text-zinc-500 mb-4"><?= $tr('ui.protect_login_registration_and_password_reset') ?></p>
                <form method="POST" action="<?= htmlspecialchars(base_path('/admin/captcha'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-4">
                        <label for="cp_captcha_provider" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.provider') ?></label>
                        <select id="cp_captcha_provider" name="captcha_provider" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                            <option value="" <?= ($captcha_provider ?? '') === '' ? 'selected' : '' ?>><?= $tr('ui.none') ?></option>
                            <option value="cloudflare-turnstile" <?= ($captcha_provider ?? '') === 'cloudflare-turnstile' ? 'selected' : '' ?>>Cloudflare Turnstile</option>
                            <option value="google-recaptcha-v2" <?= ($captcha_provider ?? '') === 'google-recaptcha-v2' ? 'selected' : '' ?>>Google reCAPTCHA v2</option>
                        </select>
                    </div>
                    <div id="cp-captcha-keys" class="<?= ($captcha_provider ?? '') === '' ? 'hidden' : '' ?>">
                        <div class="mb-4">
                            <label for="cp_captcha_site_key" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.site_key') ?></label>
                            <input type="text" id="cp_captcha_site_key" name="captcha_site_key" value="<?= htmlspecialchars($captcha_site_key ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100" autocomplete="off">
                        </div>
                        <div class="mb-4">
                            <label for="cp_captcha_secret_key" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.secret_key') ?></label>
                            <input type="password" id="cp_captcha_secret_key" name="captcha_secret_key" placeholder="<?= ($captcha_provider ?? '') !== '' ? 'Unchanged' : '' ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100" autocomplete="off">
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save_captcha_settings') ?></button>
                    </div>
                </form>
                <script data-page-script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>">
                    document.getElementById('cp_captcha_provider').addEventListener('change', function() {
                        var keysDiv = document.getElementById('cp-captcha-keys');
                        if (this.value === '') {
                            keysDiv.classList.add('hidden');
                        } else {
                            keysDiv.classList.remove('hidden');
                        }
                    });
                </script>
            </div>
        </div>
    </div>
</div>

<!-- Errors & Logging Modal -->
<div id="cp-errors-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-errors-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.errors_logging') ?></h3>
                <button type="button" data-modal-close="cp-errors-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars(base_path('/admin/more'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.show_error_details') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.display_debug_information_file_line_stack') ?></span>
                    </div>
                    <input type="checkbox" name="error_display" value="1" <?= !empty($error_display) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 shrink-0">
                </label>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.detailed_attachment_logging') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.log_every_attachment_upload_attempt_success') ?></span>
                    </div>
                    <input type="checkbox" name="attachment_logging" value="1" <?= !empty($attachment_logging) ? 'checked' : '' ?> class="w-5 h-5 accent-emerald-500 shrink-0">
                </label>
                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Updates Modal -->
<div id="cp-updates-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-updates-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.updates') ?></h3>
                <button type="button" data-modal-close="cp-updates-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-300"><?= $tr('ui.automatic_update_checks_are_unavailable_until') ?></p>
        </div>
    </div>
</div>

<!-- Announcement Modal -->
<div id="cp-announcement-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-announcement-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.announcement') ?></h3>
                <button type="button" data-modal-close="cp-announcement-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.show_a_plain_text_alert_on') ?></p>
            <form method="POST" action="<?= htmlspecialchars(base_path('/admin/announcement'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <?php $currentAnnouncementStyle = (string)($announcement_style ?? 'orange'); ?>
                <div>
                    <label for="cp_announcement_style" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.style') ?></label>
                    <select id="cp_announcement_style" name="announcement_style" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                        <option value="orange" <?= $currentAnnouncementStyle === 'orange' ? 'selected' : '' ?>><?= $tr('ui.orange') ?></option>
                        <option value="green" <?= $currentAnnouncementStyle === 'green' ? 'selected' : '' ?>><?= $tr('ui.green') ?></option>
                        <option value="blue" <?= $currentAnnouncementStyle === 'blue' ? 'selected' : '' ?>><?= $tr('ui.blue') ?></option>
                    </select>
                </div>
                <div>
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <label for="cp_announcement_message" class="block text-sm text-zinc-400"><?= $tr('ui.message_max_1000_characters') ?></label>
                        <span id="cp_announcement_counter" class="text-xs text-zinc-500">0/1000</span>
                    </div>
                    <textarea id="cp_announcement_message" name="announcement_message" maxlength="1000" rows="3" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100" placeholder="<?= $tr('ui.scheduled_maintenance_tonight_at_10_00') ?>"><?= htmlspecialchars((string)($announcement_message ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save_announcement') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Push Notifications Modal -->
<div id="cp-push-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-push-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.push_notifications') ?></h3>
                <button type="button" data-modal-close="cp-push-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.vapid_keys_are_used_to_send') ?></p>
            <?php if (!empty($push_vapid_configured)): ?>
                <p class="text-sm text-emerald-400 mb-5"><i class="fa-solid fa-circle-check mr-1"></i> <?= $tr('ui.push_notifications_are_configured_and_active') ?></p>
            <?php endif; ?>
            <form method="POST" action="<?= htmlspecialchars(base_path('/admin/push'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="push_vapid_public_key" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.vapid_public_key') ?></label>
                    <input type="text" id="push_vapid_public_key" name="push_vapid_public_key" value="<?= htmlspecialchars((string)($push_vapid_public_key ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $tr('ui.base64url_encoded_public_key') ?>" autocomplete="off" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100">
                </div>
                <div>
                    <label for="push_vapid_private_key" class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.vapid_private_key_pem') ?></label>
                    <textarea id="push_vapid_private_key" name="push_vapid_private_key" rows="5" placeholder="<?= $tr('ui.leave_blank_to_keep_current_key') ?>" autocomplete="off" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2.5 text-zinc-100 font-mono text-xs"></textarea>
                </div>
                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition"><?= $tr('ui.save_push_settings') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ==================== ACCOUNT MODALS ==================== -->

<!-- Invites Modal -->
<?php if (!empty($invitesEnabled)): ?>
<div id="cp-invites-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-invites-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.invites') ?></h3>
                <button type="button" data-modal-close="cp-invites-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <p class="text-sm text-zinc-400">Generated: <?= (int)$inviteCount ?> / <?= (int)$inviteLimit ?></p>
                <form method="POST" action="<?= htmlspecialchars(base_path('/settings/invites/generate'), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 px-4 py-2 rounded-lg disabled:bg-zinc-700 disabled:text-zinc-400 disabled:cursor-not-allowed" <?= $inviteCount >= $inviteLimit ? 'disabled' : '' ?>>
                        <?= $tr('ui.generate_invite_code') ?>
                    </button>
                </form>
            </div>
            <div class="space-y-2 text-sm">
                <?php if (empty($invites)): ?>
                    <p class="text-zinc-400"><?= $tr('ui.no_invite_codes_available') ?></p>
                <?php else: ?>
                    <?php foreach ($invites as $invite): ?>
                        <div class="flex items-center justify-between gap-3 bg-zinc-800 rounded-lg px-3 py-2">
                            <div class="flex items-center gap-2">
                                <span class="font-mono"><?= htmlspecialchars($invite->code, ENT_QUOTES, 'UTF-8') ?></span>
                                <button type="button" class="text-zinc-400 hover:text-zinc-200" title="<?= $tr('ui.copy_to_clipboard') ?>" data-copy-invite data-copy-value="<?= htmlspecialchars($invite->code, ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                                <?php if (!$invite->used_by): ?>
                                    <form method="POST" action="<?= htmlspecialchars(base_path('/settings/invites/delete'), ENT_QUOTES, 'UTF-8') ?>" data-confirm-message="<?= $tr('ui.confirm_delete_invite_code') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="invite_code" value="<?= htmlspecialchars($invite->code, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="text-zinc-500 hover:text-red-300" title="<?= $tr('ui.delete_invite_code') ?>"><i class="fa-regular fa-trash-can"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <span class="block <?= $invite->used_by ? 'text-zinc-400' : 'text-emerald-400' ?>"><?= $invite->used_by ? 'Used' : 'Available' ?></span>
                                <?php if (!empty($invite->used_by_username)): ?>
                                    <span class="block text-xs text-zinc-500">by <?= htmlspecialchars($invite->used_by_username, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Username Modal -->
<div id="cp-username-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-username-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.change_username') ?></h3>
                <button type="button" data-modal-close="cp-username-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <div class="mb-4">
                <p class="text-sm text-zinc-400 mb-1"><?= $tr('ui.current_username') ?></p>
                <p class="text-zinc-100"><?= htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php if (!$usernameCanChangeNow && !empty($usernameChangeAvailableAt)): ?>
                <p class="text-sm text-amber-300 mb-4">Username recently changed. You can change it again on <?= htmlspecialchars($usernameChangeAvailableAt, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/profile/username'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label class="block text-sm text-zinc-300"><?= $tr('ui.new_username') ?></label>
                <input type="text" name="username" value="<?= htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8') ?>" minlength="4" maxlength="32" pattern="[a-z][a-z0-9]{3,31}" autocapitalize="none" spellcheck="false" data-modal-autofocus required <?= $usernameCanChangeNow ? '' : 'disabled' ?> class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50">
                <div class="mt-4 flex gap-2">
                    <button type="submit" <?= $usernameCanChangeNow ? '' : 'disabled' ?> class="bg-emerald-600 hover:bg-emerald-500 px-6 py-2 rounded-xl disabled:bg-zinc-700 disabled:text-zinc-400 disabled:cursor-not-allowed"><?= $tr('ui.save_username') ?></button>
                    <button type="button" data-modal-close="cp-username-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl"><?= $tr('ui.cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Email Modal -->
<div id="cp-email-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-email-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.change_email') ?></h3>
                <button type="button" data-modal-close="cp-email-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <?php
                $pendingEmailValue = !empty($pendingEmailChange) ? (string)$pendingEmailChange->new_email : (string)$user->email;
                $pendingEmailSeconds = !empty($pendingEmailChange) ? max(0, (int)$pendingEmailChange->seconds_remaining) : 0;
                $pendingEmailMinutes = (int)ceil($pendingEmailSeconds / 60);
                if ($pendingEmailMinutes < 1) { $pendingEmailMinutes = 1; }
            ?>
            <?php if (!empty($pendingEmailChange)): ?>
                <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200 mb-4">
                    <?= $tr('ui.email_change_pending_for') ?> <span class="font-semibold"><?= htmlspecialchars($pendingEmailChange->new_email, ENT_QUOTES, 'UTF-8') ?></span>.
                    Enter the verification code within <?= (int)$pendingEmailMinutes ?> minute<?= $pendingEmailMinutes === 1 ? '' : 's' ?>.
                </div>
            <?php endif; ?>
            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/account/email'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label class="block text-sm text-zinc-300"><?= $tr('ui.new_email_address') ?></label>
                <input type="email" name="email" id="cp-change-email-input" data-current-email="<?= htmlspecialchars((string)$user->email, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($pendingEmailValue, ENT_QUOTES, 'UTF-8') ?>" <?= empty($pendingEmailChange) ? 'data-modal-autofocus' : '' ?> inputmode="email" autocomplete="email" required class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <label for="email-change-current-password" class="block text-sm text-zinc-300"><?= $tr('ui.current_password') ?></label>
                <input id="email-change-current-password" type="password" name="password" autocomplete="current-password" required class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100">
                <p class="text-xs text-zinc-400"><?= $tr('ui.we_send_a_6_digit_verification') ?></p>
                <div class="mt-4 flex gap-2">
                    <button type="submit" id="cp-change-email-send-button" class="bg-emerald-600 hover:bg-emerald-500 px-6 py-2 rounded-xl"><?= $tr('ui.send_verification_code') ?></button>
                    <button type="button" data-modal-close="cp-email-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl"><?= $tr('ui.close') ?></button>
                </div>
            </form>
            <?php if (!empty($pendingEmailChange)): ?>
                <div class="my-5 h-px bg-zinc-700"></div>
                <form method="POST" action="<?= htmlspecialchars(base_path('/settings/account/email/verify'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <p class="text-sm text-zinc-300"><?= $tr('ui.enter_the_code_sent_to') ?> <span class="font-semibold text-zinc-100"><?= htmlspecialchars($pendingEmailChange->new_email, ENT_QUOTES, 'UTF-8') ?></span>.</p>
                    <label class="block text-sm text-zinc-300"><?= $tr('ui.verification_code') ?></label>
                    <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" <?= !empty($pendingEmailChange) ? 'data-modal-autofocus' : '' ?> required class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 tracking-widest focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <div class="mt-4 flex gap-2">
                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 px-6 py-2 rounded-xl"><?= $tr('ui.verify_email') ?></button>
                        <button type="button" data-modal-close="cp-email-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl"><?= $tr('ui.cancel') ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Password Modal -->
<div id="cp-password-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-password-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.change_password') ?></h3>
                <button type="button" data-modal-close="cp-password-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/account/password'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label class="block text-sm text-zinc-300"><?= $tr('ui.current_password') ?></label>
                <input type="password" name="current_password" data-modal-autofocus required class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <label class="block text-sm text-zinc-300"><?= $tr('ui.new_password') ?></label>
                <input type="password" name="new_password" required minlength="8" class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <label class="block text-sm text-zinc-300"><?= $tr('ui.confirm_new_password') ?></label>
                <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <p class="text-sm text-zinc-400"><?= $tr('ui.changing_your_password_signs_out_other') ?></p>
                <div class="mt-4 flex gap-2">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 px-6 py-2 rounded-xl"><?= $tr('ui.save_password') ?></button>
                    <button type="button" data-modal-close="cp-password-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl"><?= $tr('ui.cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Avatar Modal -->
<div id="cp-avatar-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-avatar-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.avatar') ?></h3>
                <button type="button" data-modal-close="cp-avatar-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-4"><?= $tr('ui.jpg_or_png_images_are_resized') ?></p>
            <div class="flex items-center gap-5">
                <div id="avatar-preview-wrap" class="relative shrink-0" data-initial="<?= htmlspecialchars(User::avatarInitial($user->username), ENT_QUOTES, 'UTF-8') ?>" data-initials-class="w-16 h-16 rounded-full border border-zinc-700 flex items-center justify-center text-xl font-semibold <?= htmlspecialchars(User::avatarColorClasses($user->user_number), ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($currentAvatarUrl): ?>
                        <img id="avatar-preview-img" src="<?= htmlspecialchars($currentAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $tr('ui.your_avatar') ?>" class="w-16 h-16 rounded-full object-cover border border-zinc-700">
                    <?php else: ?>
                        <div id="avatar-preview-initials" class="w-16 h-16 rounded-full border border-zinc-700 flex items-center justify-center text-xl font-semibold <?= htmlspecialchars(User::avatarColorClasses($user->user_number), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(User::avatarInitial($user->username), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <div id="avatar-upload-spinner" class="hidden absolute inset-0 rounded-full bg-black/60 flex items-center justify-center">
                        <i class="fa-solid fa-spinner fa-spin text-white text-lg"></i>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <label id="avatar-upload-label" class="cursor-pointer bg-zinc-700 hover:bg-zinc-600 px-4 py-2 rounded-lg text-sm select-none">
                        <span id="avatar-upload-label-text"><?= $currentAvatarUrl ? 'Change avatar' : 'Upload avatar' ?></span>
                        <input id="avatar-file-input" type="file" accept="image/jpeg,image/png" class="hidden">
                    </label>
                    <button id="avatar-delete-btn" type="button" class="<?= $currentAvatarUrl ? '' : 'hidden ' ?>px-4 py-2 rounded-lg text-sm bg-zinc-800 border border-zinc-600 hover:border-red-500 hover:text-red-400 text-zinc-300">
                        <?= $tr('ui.delete_avatar') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Avatar Confirmation Modal -->
<div id="cp-delete-avatar-modal" class="fixed inset-0 z-[60] hidden" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/70" data-modal-close="cp-delete-avatar-modal"></div>
    <div class="relative z-10 flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-sm rounded-2xl border border-zinc-700 bg-zinc-900 p-6" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.delete_avatar_2') ?></h3>
                <button type="button" data-modal-close="cp-delete-avatar-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-300 mb-6"><?= $tr('ui.your_avatar_will_be_removed_and') ?></p>
            <div class="flex gap-3">
                <button id="avatar-delete-confirm-btn" type="button" class="bg-red-600 hover:bg-red-500 px-6 py-2 rounded-xl text-sm"><?= $tr('ui.delete') ?></button>
                <button type="button" data-modal-close="cp-delete-avatar-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl text-sm"><?= $tr('ui.cancel') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Timezone Modal -->
<div id="cp-timezone-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-timezone-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.time_zone') ?></h3>
                <button type="button" data-modal-close="cp-timezone-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/timezone'), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <p id="timezone-help-text" class="text-sm text-zinc-300 mb-3"><?= $tr('ui.all_times_are_stored_in_utc') ?></p>
                <input type="hidden" name="timezone" id="timezone-hidden-input" value="<?= htmlspecialchars((string)($userTimezone ?? 'UTC+0'), ENT_QUOTES, 'UTF-8') ?>">
                <div class="space-y-3" id="timezone-combobox" data-timezone-combobox>
                    <label for="timezone-search-input" class="block text-xs font-semibold uppercase tracking-wide text-zinc-400"><?= $tr('ui.search_time_zone') ?></label>
                    <input
                        id="timezone-search-input"
                        type="search"
                        placeholder="<?= $tr('ui.try_america_new_york_berlin_utc') ?>"
                        autocomplete="off"
                        class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="timezone-options"
                        aria-expanded="false"
                        aria-describedby="timezone-help-text timezone-selected-label"
                    >
                    <p id="timezone-selected-label" class="text-xs text-emerald-300">Selected: <?= htmlspecialchars((string)($selectedTimezoneLabel ?? ('UTC (' . (string)($userTimezone ?? 'UTC+0') . ')')), ENT_QUOTES, 'UTF-8') ?></p>
                    <div id="timezone-options" class="max-h-64 overflow-y-auto rounded-xl border border-zinc-700 bg-zinc-800/70 p-1.5 space-y-1" role="listbox" aria-label="<?= $tr('ui.time_zone_options') ?>">
                        <?php foreach (($timezoneEntries ?? []) as $index => $timezoneEntry): ?>
                            <?php
                                $tzOffset = (string)($timezoneEntry['offset'] ?? 'UTC+0');
                                $tzLabel = (string)($timezoneEntry['label'] ?? ('UTC (' . $tzOffset . ')'));
                                $tzSearch = (string)($timezoneEntry['search'] ?? '');
                                $isSelected = ((string)($userTimezone ?? 'UTC+0') === $tzOffset);
                            ?>
                            <button
                                id="timezone-option-<?= (int)$index ?>"
                                type="button"
                                class="timezone-option w-full rounded-lg border px-3 py-2 text-left text-sm transition <?= $isSelected ? 'border-emerald-500 bg-emerald-500/15 text-emerald-200' : 'border-transparent bg-zinc-800 text-zinc-200 hover:border-zinc-600 hover:bg-zinc-700/70' ?>"
                                role="option"
                                aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                data-timezone-option
                                data-timezone-offset="<?= htmlspecialchars($tzOffset, ENT_QUOTES, 'UTF-8') ?>"
                                data-timezone-label="<?= htmlspecialchars($tzLabel, ENT_QUOTES, 'UTF-8') ?>"
                                data-timezone-search="<?= htmlspecialchars($tzSearch, ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <?= htmlspecialchars($tzLabel, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php endforeach; ?>
                        <p class="hidden rounded-lg border border-zinc-700 bg-zinc-900/60 px-3 py-2 text-sm text-zinc-400" data-timezone-empty><?= $tr('ui.no_matches_found_try_another_city') ?></p>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 px-6 py-2 rounded-xl"><?= $tr('ui.save_time_zone') ?></button>
                    <button type="button" data-modal-close="cp-timezone-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl"><?= $tr('ui.cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Sessions Modal -->
<div id="cp-sessions-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-sessions-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.sessions') ?></h3>
                <button type="button" data-modal-close="cp-sessions-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <div class="space-y-2 text-sm">
                <?php if (empty($sessions)): ?>
                    <p class="text-zinc-400"><?= $tr('ui.no_active_sessions') ?></p>
                <?php else: ?>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                            $loggedInAt = strtotime((string)$session->logged_in_at . ' UTC');
                            if ($loggedInAt && preg_match('/^UTC([+-])(\d{1,2})(?::(\d{2}))?$/', $userTimezone ?? 'UTC+0', $_tzm)) {
                                $loggedInAt += ($_tzm[1] === '+' ? 1 : -1) * ((int)$_tzm[2] * 3600 + (int)($_tzm[3] ?? 0) * 60);
                            }
                            $loggedInLabel = $loggedInAt ? date('M j, Y H:i', $loggedInAt) : (string)$session->logged_in_at;
                        ?>
                        <div class="bg-zinc-800 rounded-lg px-3 py-3 flex items-start justify-between gap-3">
                            <div class="space-y-1">
                                <p class="text-zinc-100">
                                    <span class="text-zinc-400"><?= $tr('ui.logged_in') ?></span>
                                    <?= htmlspecialchars((string)$loggedInLabel, ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($session->is_current)): ?>
                                        <span class="ml-2 text-emerald-400 text-xs"><?= $tr('ui.current_session') ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-zinc-300"><span class="text-zinc-400"><?= $tr('ui.ip') ?></span> <?= htmlspecialchars((string)$session->ip_address, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-zinc-300"><span class="text-zinc-400"><?= $tr('ui.browser') ?></span> <?= htmlspecialchars((string)$session->browser, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/sessions/exit'), ENT_QUOTES, 'UTF-8') ?>" data-confirm-message="<?= $tr('ui.confirm_end_session') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="session_id" value="<?= (int)$session->id ?>">
                                <button type="submit" class="text-red-300 hover:text-red-200 underline decoration-red-400 whitespace-nowrap"><?= $tr('ui.exit_session') ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 2FA Modal -->
<div id="cp-2fa-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-2fa-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.two_factor_authentication_2') ?></h3>
                <button type="button" data-modal-close="cp-2fa-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>

            <?php if (!empty($totpRecoveryCodes)): ?>
                <!-- Recovery codes display (shown once after setup) -->
                <div class="space-y-4">
                    <p class="text-zinc-300"><?= $tr('ui.save_these_recovery_codes_in_a') ?></p>
                    <div class="bg-zinc-950 border border-zinc-700 rounded-xl p-4 font-mono text-sm grid grid-cols-2 gap-2" id="recovery-codes-grid">
                        <?php foreach ($totpRecoveryCodes as $code): ?>
                            <span class="text-zinc-200"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" data-app-action="copy-recovery-codes" data-copy-label="<?= $tr('ui.copy_all_codes') ?>"
                            data-copied-label="<?= $tr('ui.recovery_codes_copied') ?>" class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-600 py-2.5 rounded-xl text-sm font-medium transition"><?= $tr('ui.copy_all_codes') ?></button>
                </div>

            <?php elseif ($totpSetupPending): ?>
                <!-- Setup in progress: show QR code and confirmation -->
                <div class="space-y-4">
                    <p class="text-zinc-400"><?= $tr('ui.scan_this_qr_code_with_your') ?></p>
                    <?php if ($totpQrDataUri !== ''): ?>
                        <div class="flex justify-center">
                            <img src="<?= htmlspecialchars($totpQrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $tr('ui.totp_qr_code') ?>" class="rounded-xl bg-white p-2" width="228" height="228">
                        </div>
                    <?php endif; ?>
                    <div>
                        <p class="text-xs text-zinc-500 mb-1"><?= $tr('ui.or_enter_this_key_manually') ?></p>
                        <div class="bg-zinc-950 border border-zinc-700 rounded-lg px-3 py-2 font-mono text-sm text-zinc-300 break-all select-all"><?= htmlspecialchars($totpSecretDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <form method="POST" action="<?= htmlspecialchars(base_path('/settings/2fa/totp/confirm'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <div>
                            <label class="block text-sm text-zinc-400 mb-1"><?= $tr('ui.enter_the_6_digit_code_from_2') ?></label>
                            <input type="text" name="totp_code" maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-3 text-center text-2xl tracking-widest focus:outline-none focus:border-emerald-500">
                        </div>
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 py-3 rounded-xl font-semibold transition"><?= $tr('ui.verify_and_enable') ?></button>
                    </form>
                </div>

            <?php elseif ($totpEnabled): ?>
                <!-- TOTP is active -->
                <div class="space-y-5">
                    <div class="flex items-center gap-3 bg-emerald-950/40 border border-emerald-800 rounded-xl px-4 py-3">
                        <i class="fa-solid fa-circle-check text-emerald-400"></i>
                        <span class="text-emerald-300 font-medium"><?= $tr('ui.authenticator_app_is_enabled') ?></span>
                    </div>

                    <?php if (!$isAdmin): ?>
                        <form method="POST" action="<?= htmlspecialchars(base_path('/settings/2fa/frequency'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <label for="2fa-frequency" class="block text-sm text-zinc-300"><?= $tr('ui.verification_frequency') ?></label>
                            <select id="2fa-frequency" name="frequency" class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3">
                                <option value="always" <?= $twofaFrequency !== 'trusted' ? 'selected' : '' ?>><?= $tr('ui.every_sign_in') ?></option>
                                <option value="trusted" <?= $twofaFrequency === 'trusted' ? 'selected' : '' ?>><?= $tr('ui.allow_manually_trusted_browsers') ?></option>
                            </select>
                            <label for="2fa-frequency-password" class="block text-sm text-zinc-300"><?= $tr('ui.current_password') ?></label>
                            <input id="2fa-frequency-password" type="password" name="password" autocomplete="current-password" required class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3">
                            <button type="submit" class="rounded-xl bg-zinc-700 px-4 py-2 hover:bg-zinc-600"><?= $tr('ui.save_preference') ?></button>
                        </form>
                        <?php if ($twofaFrequency === 'trusted'): ?>
                            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/2fa/trusted/revoke'), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="rounded-xl bg-zinc-700 px-4 py-2 hover:bg-zinc-600"><?= $tr('ui.revoke_all_trusted_browsers') ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 flex items-center justify-between gap-4">
                        <span class="text-sm text-zinc-400"><?= $tr('ui.recovery_codes_remaining') ?></span>
                        <span class="text-zinc-200 font-medium"><?= $totpRecoveryCodesRemaining ?> <span class="text-zinc-500 font-normal"><?= $tr('ui.of_8') ?></span></span>
                    </div>

                    <form method="POST" action="<?= htmlspecialchars(base_path('/settings/2fa/totp/disable'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3 border-t border-zinc-700 pt-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <p class="text-sm text-zinc-400"><?= $tr('ui.to_disable_enter_your_current_password') ?></p>
                        <input type="password" name="password" placeholder="<?= $tr('ui.current_password') ?>" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-3 focus:outline-none focus:border-emerald-500">
                        <button type="submit" class="w-full bg-red-600 hover:bg-red-500 py-3 rounded-xl font-semibold transition"><?= $tr('ui.disable_authenticator_app') ?></button>
                    </form>
                </div>

            <?php else: ?>
                <!-- TOTP not configured -->
                <div class="space-y-4">
                    <p class="text-zinc-400"><?= $tr('ui.add_an_extra_layer_of_security') ?></p>
                    <form method="POST" action="<?= htmlspecialchars(base_path('/settings/2fa/totp/setup'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <label for="2fa-setup-password" class="block text-sm text-zinc-300"><?= $tr('ui.current_password') ?></label>
                        <input id="2fa-setup-password" type="password" name="password" autocomplete="current-password" required class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3">
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 py-3 rounded-xl font-semibold transition"><?= $tr('ui.set_up_authenticator_app') ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Notifications Modal -->
<div id="cp-notifications-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-notifications-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.notifications') ?></h3>
                <button type="button" data-modal-close="cp-notifications-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.changes_save_automatically') ?></p>
            <div class="space-y-4" id="notification-settings-toggles">
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.browser_notifications') ?></span>
                    <input type="checkbox" data-notification-setting="browser_notifications" <?= $browserNotif ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500">
                </label>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <div>
                        <span class="text-zinc-100"><?= $tr('ui.web_push_notifications') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.receive_alerts_even_when_this_tab') ?></span>
                    </div>
                    <input type="checkbox" data-notification-setting="web_push_notifications" <?= !empty($webPushNotif) ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500">
                </label>
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.sound_poke') ?></span>
                    <div class="flex items-center gap-3">
                        <button type="button" data-notification-sound-preview="poke" class="px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.preview') ?></button>
                        <input type="checkbox" data-notification-setting="sound_poke" <?= $pokeSoundNotif ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500">
                    </div>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.sound_new_chat_message') ?></span>
                    <div class="flex items-center gap-3">
                        <button type="button" data-notification-sound-preview="new_message" class="px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.preview') ?></button>
                        <input type="checkbox" data-notification-setting="sound_new_message" <?= $newMessageSoundNotif ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500">
                    </div>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.sound_other_notifications') ?></span>
                    <div class="flex items-center gap-3">
                        <button type="button" data-notification-sound-preview="other" class="px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.preview') ?></button>
                        <input type="checkbox" data-notification-setting="sound_other_notifications" <?= $otherNotificationSoundNotif ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500">
                    </div>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.sound_outgoing_call_ringing') ?></span>
                    <div class="flex items-center gap-3">
                        <button type="button" data-notification-sound-preview="call" class="px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.preview') ?></button>
                        <input type="checkbox" data-notification-setting="sound_outgoing_call_ring" <?= $outgoingCallRingSoundNotif ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500">
                    </div>
                </div>
            </div>
            <p id="notification-settings-status" class="mt-3 text-xs text-zinc-500" aria-live="polite"></p>
        </div>
    </div>
</div>

<!-- Preferences Modal -->
<div id="cp-preferences-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-preferences-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.preferences') ?></h3>
                <button type="button" data-modal-close="cp-preferences-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars(base_path('/settings/preferences'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.enable_top_loading_bar') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.shows_the_3px_top_progress_bar') ?></span>
                    </div>
                    <input type="checkbox" name="top_loading_bar_enabled" value="1" <?= !empty($topLoadingBarEnabled) ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500 shrink-0">
                </label>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3 cursor-pointer">
                    <div>
                        <span class="block text-zinc-100"><?= $tr('ui.auto_combine_system_messages_in_chats') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.groups_consecutive_causette_system_events_with') ?></span>
                    </div>
                    <input type="checkbox" name="system_messages_auto_combine" value="1" <?= !empty($systemMessagesAutoCombineEnabled) ? 'checked' : '' ?> class="w-6 h-6 accent-emerald-500 shrink-0">
                </label>
                <div class="rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <div class="mb-2">
                        <span class="block text-zinc-100"><?= $tr('ui.font_size') ?></span>
                        <span class="block text-xs text-zinc-500 mt-0.5"><?= $tr('ui.choose_your_preferred_app_text_size') ?></span>
                    </div>
                    <div class="space-y-2">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="font_size" value="default" <?= (($fontSizePreference ?? 'default') === 'default') ? 'checked' : '' ?> class="w-4 h-4 accent-emerald-500">
                            <span class="text-zinc-100"><?= $tr('ui.default') ?></span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="font_size" value="large" <?= (($fontSizePreference ?? 'default') === 'large') ? 'checked' : '' ?> class="w-4 h-4 accent-emerald-500">
                            <span class="text-zinc-100"><?= $tr('ui.large') ?></span>
                        </label>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 px-6 py-2 rounded-xl"><?= $tr('ui.save_preferences') ?></button>
                    <button type="button" data-modal-close="cp-preferences-modal" class="bg-zinc-700 hover:bg-zinc-600 px-6 py-2 rounded-xl"><?= $tr('ui.cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== MORE MODALS ==================== -->

<!-- Test Browser Features Modal -->
<div id="cp-browser-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-browser-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.test_browser_features') ?></h3>
                <button type="button" data-modal-close="cp-browser-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <p class="text-sm text-zinc-400 mb-5"><?= $tr('ui.this_applies_to_your_current_browser') ?></p>
            <div class="space-y-3" id="browser-permissions-list">
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.sound') ?></span>
                    <div class="flex items-center gap-2">
                        <button type="button" data-browser-permission-test="sound" class="hidden px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.test') ?></button>
                        <span data-browser-permission-status="sound" class="text-xs px-2.5 py-1 rounded-full border border-zinc-600 bg-zinc-700/50 text-zinc-200"><?= $tr('ui.checking') ?></span>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.webcam') ?></span>
                    <div class="flex items-center gap-2">
                        <button type="button" data-browser-permission-test="camera" class="hidden px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.test') ?></button>
                        <span data-browser-permission-status="camera" class="text-xs px-2.5 py-1 rounded-full border border-zinc-600 bg-zinc-700/50 text-zinc-200"><?= $tr('ui.checking') ?></span>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-700 bg-zinc-800/30 px-4 py-3">
                    <span class="text-zinc-100"><?= $tr('ui.microphone') ?></span>
                    <div class="flex items-center gap-2">
                        <button type="button" data-browser-permission-test="microphone" class="hidden px-3 py-1.5 text-xs rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-100"><?= $tr('ui.test') ?></button>
                        <span data-browser-permission-status="microphone" class="text-xs px-2.5 py-1 rounded-full border border-zinc-600 bg-zinc-700/50 text-zinc-200"><?= $tr('ui.checking') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- About Modal -->
<div id="cp-about-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="cp-about-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.about') ?></h3>
                <button type="button" data-modal-close="cp-about-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>
            <div class="space-y-3 text-zinc-200">
                <div class="border border-zinc-700 bg-zinc-800/40 rounded-xl px-4 py-3">
                    <span class="text-zinc-400"><?= $tr('ui.current_app_version') ?></span>
                    <div class="font-semibold mt-1"><?= htmlspecialchars((string)($app_version ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="border border-zinc-700 bg-zinc-800/40 rounded-xl px-4 py-3">
                    <span class="text-zinc-400"><?= $tr('ui.current_database_version') ?></span>
                    <div class="font-semibold mt-1"><?= htmlspecialchars((string)($database_version ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="border border-zinc-700 bg-zinc-800/40 rounded-xl px-4 py-3">
                    <span class="text-zinc-400"><?= $tr('ui.open_source_license') ?></span>
                    <div class="font-semibold mt-1">GPL-3.0</div>
                </div>
                <div class="border border-zinc-700 bg-zinc-800/40 rounded-xl px-4 py-3">
                    <span class="text-zinc-400"><?= $tr('ui.front_end_framework') ?></span>
                    <div class="font-semibold mt-1">Tailwind</div>
                </div>
                <div class="border border-zinc-700 bg-zinc-800/40 rounded-xl px-4 py-3">
                    <span class="text-zinc-400"><?= $tr('ui.icon_font') ?></span>
                    <div class="font-semibold mt-1">Font Awesome Free</div>
                </div>
                <div class="border border-zinc-700 bg-zinc-800/40 rounded-xl px-4 py-3">
                    <span class="text-zinc-400"><?= $tr('ui.emojis') ?></span>
                    <div class="font-semibold mt-1">OpenMoji</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script data-page-script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>">
(function () {
    // ---- Generic modal open/close ----
    var openButtons = document.querySelectorAll('[data-modal-open]');
    var closeButtons = document.querySelectorAll('[data-modal-close]');

    function openModal(modalId) {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (modalId === 'cp-email-modal') {
            updateEmailSendButtonState();
        }

        var autofocusInput = modal.querySelector('[data-modal-autofocus]');
        if (autofocusInput && typeof autofocusInput.focus === 'function') {
            setTimeout(function () {
                autofocusInput.focus();
                if (typeof autofocusInput.select === 'function') {
                    autofocusInput.select();
                }
            }, 0);
        }
    }

    function closeModal(modalId) {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.add('hidden');

        if (!Array.from(document.querySelectorAll('[role="dialog"]')).some(function (el) {
            return !el.classList.contains('hidden');
        })) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var modalId = button.getAttribute('data-modal-open');
            if (modalId) openModal(modalId);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var modalId = button.getAttribute('data-modal-close');
            if (modalId) closeModal(modalId);
        });
    });

    // ---- Close modal when clicking outside the modal box ----
    document.querySelectorAll('[role="dialog"]').forEach(function (overlay) {
        overlay.addEventListener('mousedown', function (event) {
            // Only close if the click target is not inside the modal box.
            // The modal box is the deepest nested wrapper (overlay > backdrop, overlay > center > box).
            if (!event.target.closest('[data-modal-box]')) {
                closeModal(overlay.id);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[role="dialog"]').forEach(function (modal) {
            if (!modal.classList.contains('hidden')) {
                modal.classList.add('hidden');
            }
        });
        document.body.classList.remove('overflow-hidden');
    });

    // ---- Auto-open modal from flash ----
    var autoloadModal = document.getElementById('cp-modal-autoload');
    if (autoloadModal) {
        var modalId = autoloadModal.getAttribute('data-modal-id');
        if (modalId) openModal(modalId);
    }

    // ---- Email validation ----
    var changeEmailInput = document.getElementById('cp-change-email-input');
    var changeEmailSendButton = document.getElementById('cp-change-email-send-button');

    function updateEmailSendButtonState() {
        if (!changeEmailInput || !changeEmailSendButton) return;
        var enteredEmail = String(changeEmailInput.value || '').trim().toLowerCase();
        var currentEmail = String(changeEmailInput.getAttribute('data-current-email') || '').trim().toLowerCase();
        var isDifferentEmail = enteredEmail !== '' && enteredEmail !== currentEmail;
        var isValidEmail = typeof changeEmailInput.checkValidity === 'function'
            ? changeEmailInput.checkValidity()
            : /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(enteredEmail);
        var canSend = isDifferentEmail && isValidEmail;
        changeEmailSendButton.disabled = !canSend;
        changeEmailSendButton.classList.toggle('hidden', !canSend);
    }

    if (changeEmailInput) {
        changeEmailInput.addEventListener('input', updateEmailSendButtonState);
        changeEmailInput.addEventListener('change', updateEmailSendButtonState);

        var changeEmailForm = changeEmailInput.closest('form');
        if (changeEmailForm) {
            changeEmailForm.addEventListener('submit', function (event) {
                updateEmailSendButtonState();
                if (typeof changeEmailInput.checkValidity === 'function' && !changeEmailInput.checkValidity()) {
                    event.preventDefault();
                    if (typeof changeEmailInput.reportValidity === 'function') {
                        changeEmailInput.reportValidity();
                    }
                    return;
                }
                if (changeEmailSendButton && changeEmailSendButton.disabled) {
                    event.preventDefault();
                }
            });
        }

        updateEmailSendButtonState();
    }

    // ---- Avatar upload/delete ----
    var avatarFileInput = document.getElementById('avatar-file-input');
    var avatarPreviewWrap = document.getElementById('avatar-preview-wrap');
    var avatarUploadSpinner = document.getElementById('avatar-upload-spinner');
    var avatarUploadLabel = document.getElementById('avatar-upload-label');
    var avatarUploadLabelText = document.getElementById('avatar-upload-label-text');
    var avatarDeleteBtn = document.getElementById('avatar-delete-btn');
    var avatarDeleteConfirmBtn = document.getElementById('avatar-delete-confirm-btn');
    var deleteAvatarModal = document.getElementById('cp-delete-avatar-modal');

    function getAvatarInitials() {
        return document.getElementById('avatar-preview-initials') || null;
    }

    function setAvatarImg(url) {
        var img = document.getElementById('avatar-preview-img');
        var initials = getAvatarInitials();
        if (initials) initials.remove();
        if (!img) {
            img = document.createElement('img');
            img.id = 'avatar-preview-img';
            img.alt = 'Your avatar';
            img.className = 'w-16 h-16 rounded-full object-cover border border-zinc-700';
            avatarPreviewWrap.insertBefore(img, avatarUploadSpinner);
        }
        img.src = url;
        avatarUploadLabelText.textContent = 'Change avatar';
        if (avatarDeleteBtn) avatarDeleteBtn.classList.remove('hidden');
    }

    function clearAvatarImg() {
        var img = document.getElementById('avatar-preview-img');
        if (img) img.remove();
        if (!getAvatarInitials()) {
            var div = document.createElement('div');
            div.id = 'avatar-preview-initials';
            div.className = avatarPreviewWrap.dataset.initialsClass || 'w-16 h-16 rounded-full border border-zinc-700 flex items-center justify-center text-xl font-semibold bg-emerald-700 text-emerald-100';
            div.textContent = avatarPreviewWrap.dataset.initial || '?';
            avatarPreviewWrap.insertBefore(div, avatarUploadSpinner);
        }
        avatarUploadLabelText.textContent = 'Upload avatar';
        if (avatarDeleteBtn) avatarDeleteBtn.classList.add('hidden');
    }

    if (avatarFileInput) {
        avatarFileInput.addEventListener('change', function () {
            var file = avatarFileInput.files && avatarFileInput.files[0];
            if (!file) return;

            var allowed = ['image/jpeg', 'image/png'];
            if (!allowed.includes(file.type)) {
                showToast('Avatar must be a JPG or PNG image.', 'error');
                avatarFileInput.value = '';
                return;
            }

            var formData = new FormData();
            formData.append('csrf_token', window.CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute?.('content') || '');
            formData.append('avatar', file);

            if (avatarUploadSpinner) avatarUploadSpinner.classList.remove('hidden');
            if (avatarUploadLabel) avatarUploadLabel.style.pointerEvents = 'none';

            fetch(<?= \Causette\View\HtmlJson::encode(base_url('/settings/avatar')) ?>, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success && data.avatar_url) {
                    setAvatarImg(data.avatar_url);
                    showToast('Avatar updated.', 'success');
                } else {
                    var errorMap = {
                        'avatar_invalid_type': 'Avatar must be a JPG or PNG image.',
                        'avatar_too_large': 'Image is too large. Maximum size is 2048 \u00d7 2048 pixels.',
                        'avatar_upload_failed': 'Avatar upload failed. Please try again.'
                    };
                    showToast(errorMap[data && data.error] || 'Avatar upload failed. Please try again.', 'error');
                }
            })
            .catch(function () {
                showToast('Avatar upload failed. Please try again.', 'error');
            })
            .finally(function () {
                if (avatarUploadSpinner) avatarUploadSpinner.classList.add('hidden');
                if (avatarUploadLabel) avatarUploadLabel.style.pointerEvents = '';
                avatarFileInput.value = '';
            });
        });
    }

    if (avatarDeleteBtn && deleteAvatarModal) {
        avatarDeleteBtn.addEventListener('click', function () {
            deleteAvatarModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        });
    }

    if (avatarDeleteConfirmBtn) {
        avatarDeleteConfirmBtn.addEventListener('click', function () {
            avatarDeleteConfirmBtn.disabled = true;
            avatarDeleteConfirmBtn.textContent = 'Deleting\u2026';

            var formData = new URLSearchParams();
            formData.append('csrf_token', window.CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute?.('content') || '');

            fetch(<?= \Causette\View\HtmlJson::encode(base_url('/settings/avatar/delete')) ?>, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData.toString()
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    clearAvatarImg();
                    showToast('Avatar removed.', 'success');
                    if (deleteAvatarModal) {
                        deleteAvatarModal.classList.add('hidden');
                        if (!Array.from(document.querySelectorAll('[role="dialog"]')).some(function (el) {
                            return !el.classList.contains('hidden');
                        })) {
                            document.body.classList.remove('overflow-hidden');
                        }
                    }
                } else {
                    showToast('Could not remove avatar. Please try again.', 'error');
                }
            })
            .catch(function () {
                showToast('Could not remove avatar. Please try again.', 'error');
            })
            .finally(function () {
                avatarDeleteConfirmBtn.disabled = false;
                avatarDeleteConfirmBtn.textContent = 'Delete';
            });
        });
    }

    // ---- Copy invite code ----
    document.querySelectorAll('[data-copy-invite]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = btn.getAttribute('data-copy-value');
            if (code && navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function () {
                    showToast('Invite code copied.', 'success');
                });
            }
        });
    });

    // ---- Announcement character counter ----
    var announcementInput = document.getElementById('cp_announcement_message');
    var announcementCounter = document.getElementById('cp_announcement_counter');
    if (announcementInput && announcementCounter) {
        var maxLen = Number(announcementInput.getAttribute('maxlength') || 1000);
        var updateAnnouncementCounter = function () {
            announcementCounter.textContent = String(announcementInput.value.length) + '/' + String(maxLen);
        };
        announcementInput.addEventListener('input', updateAnnouncementCounter);
        updateAnnouncementCounter();
    }

    // ---- Attachments select all/deselect all ----
    var attachBtn = document.getElementById('cp-attachments-select-all-btn');
    var attachForm = document.getElementById('cp-attachments-type-form');
    if (attachBtn && attachForm) {
        var getBoxes = function () { return Array.from(attachForm.querySelectorAll('input[type="checkbox"]')); };
        var allChecked = function () { return getBoxes().every(function (cb) { return cb.checked; }); };
        var updateAttachBtn = function () { attachBtn.textContent = allChecked() ? 'Deselect all' : 'Select all'; };
        updateAttachBtn();
        attachBtn.addEventListener('click', function () {
            var check = !allChecked();
            getBoxes().forEach(function (cb) { cb.checked = check; });
            updateAttachBtn();
        });
        attachForm.addEventListener('change', updateAttachBtn);
    }
})();
</script>
