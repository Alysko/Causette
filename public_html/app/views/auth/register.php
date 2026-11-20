<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<div class="bg-zinc-900 p-8 rounded-3xl border border-zinc-700">
    <h1 class="text-3xl font-bold text-center mb-7"><?= $tr('ui.create_account') ?></h1>
    <?php
        $toastMessage = '';
        $toastKind = 'error';
        $flashError = flash_get('error');
        if ($flashError === 'username_taken') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.username_is_already_taken');
        } elseif ($flashError === 'too_many_attempts') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.too_many_attempts_please_try_again');
        } elseif ($flashError === 'invalid') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.username_must_be_4_32_characters_3');
        } elseif ($flashError === 'email_taken') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.email_is_already_in_use');
        } elseif ($flashError === 'invalid_invite') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.invalid_or_already_used_invite_code');
        } elseif ($flashError === 'captcha_failed') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.captcha_verification_failed_please_try_again');
        } elseif ($flashError !== null) {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.registration_failed_check_invite_code_and');
        }
    ?>
    <?php if ($toastMessage !== ''): ?>
        <div id="page-toast" data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>" data-toast-kind="<?= htmlspecialchars($toastKind, ENT_QUOTES, 'UTF-8') ?>" class="hidden" aria-hidden="true"></div>
    <?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars(base_path('/register'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label for="register-username" class="block text-sm text-zinc-300 mb-2"><?= $tr('ui.username') ?></label>
        <input id="register-username" type="text" name="username" placeholder="<?= $tr('ui.username') ?>" minlength="4" maxlength="32" pattern="[a-z][a-z0-9]{3,31}" title="<?= $tr('ui.username_must_be_4_32_characters') ?>" autocapitalize="none" spellcheck="false" aria-describedby="username-requirements" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4" required>
        <p id="username-requirements" class="text-xs text-zinc-400 mt-2 mb-4"><?= $tr('ui.username_must_be_4_32_characters_2') ?></p>
        <label for="register-email" class="block text-sm text-zinc-300 mb-2"><?= $tr('ui.email_address') ?></label>
        <input id="register-email" type="email" name="email" placeholder="<?= $tr('ui.email') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 mb-4" required>
        <label for="register-password" class="block text-sm text-zinc-300 mb-2"><?= $tr('ui.password') ?></label>
        <input id="register-password" type="password" name="password" placeholder="<?= $tr('ui.password_8_chars') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 mb-4" required>
        <?php if (!isset($invitesEnabled) || $invitesEnabled): ?>
            <label for="register-invite-code" class="block text-sm text-zinc-300 mb-2"><?= $tr('ui.invite_code') ?></label>
            <input id="register-invite-code" type="text" name="invite_code" placeholder="<?= $tr('ui.invite_code_1234_5678') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 mb-6" required>
        <?php endif; ?>
        <?php if (!empty($captchaWidgetHtml)): ?>
            <div class="mb-4"><?= $captchaWidgetHtml ?></div>
            <script src="<?= htmlspecialchars($captchaScriptUrl, ENT_QUOTES, 'UTF-8') ?>" async defer></script>
        <?php endif; ?>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 py-4 rounded-2xl font-semibold"><?= $tr('ui.register') ?></button>
    </form>
</div>
</div>