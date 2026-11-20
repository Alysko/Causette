<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<div class="bg-zinc-900 p-8 rounded-3xl border border-zinc-700">
    <h1 class="text-3xl font-bold text-center mb-7"><?= $tr('ui.reset_password') ?></h1>
    <?php
        $toastMessage = '';
        $toastKind = 'info';
        $flashError = flash_get('error');
        if (flash_get('success') !== null) {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.if_that_email_exists_a_reset');
            $toastKind = 'success';
        } elseif ($flashError === 'captcha_failed') {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.captcha_verification_failed_please_try_again');
            $toastKind = 'error';
        } elseif ($flashError !== null) {
            $toastMessage = \Causette\Localization\Locale::current()->text('ui.unable_to_process_reset_request');
            $toastKind = 'error';
        }
    ?>
    <?php if ($toastMessage !== ''): ?>
        <div id="page-toast" data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>" data-toast-kind="<?= htmlspecialchars($toastKind, ENT_QUOTES, 'UTF-8') ?>" class="hidden" aria-hidden="true"></div>
    <?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars(base_path('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label for="forgot-email" class="block text-sm text-zinc-300 mb-2"><?= $tr('ui.email_address') ?></label>
        <input id="forgot-email" type="email" name="email" placeholder="<?= $tr('ui.email') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 mb-6" required>
        <?php if (!empty($captchaWidgetHtml)): ?>
            <div class="mb-4"><?= $captchaWidgetHtml ?></div>
            <script src="<?= htmlspecialchars($captchaScriptUrl, ENT_QUOTES, 'UTF-8') ?>" async defer></script>
        <?php endif; ?>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 py-4 rounded-2xl font-semibold"><?= $tr('ui.send_reset_link') ?></button>
    </form>
    <div class="text-center mt-6">
        <a href="<?= htmlspecialchars(base_url('/login'), ENT_QUOTES, 'UTF-8') ?>" class="text-zinc-400 text-sm"><?= $tr('ui.back_to_login') ?></a>
    </div>
</div>
