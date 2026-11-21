<?php
$locale = \Causette\Localization\Locale::current();
$tr = $locale->html(...);
?>
<div class="bg-zinc-900 p-8 rounded-3xl border border-zinc-700 text-center">
    <h1 class="text-2xl font-bold mb-4"><?= $tr('ui.verify_your_email') ?></h1>
    <?php
        $toastMessage = '';
        $toastKind = 'info';
        $flashError = flash_get('error');
        $flashSuccess = flash_get('success');
        if ($flashError === 'invalid') {
            $toastMessage = $locale->text('feedback.invalid_or_expired_code');
            $toastKind = 'error';
        } elseif ($flashError === 'challenge_expired') {
            $toastMessage = $locale->text('feedback.verification_exhausted');
            $toastKind = 'error';
        } elseif ($flashError === 'too_many_attempts') {
            $toastMessage = $locale->text('feedback.verification_rate_limit');
            $toastKind = 'error';
        } elseif ($flashError === 'delivery_failed') {
            $toastMessage = $locale->text('feedback.verification_email_failed');
            $toastKind = 'error';
        } elseif ($flashSuccess === 'sent') {
            $toastMessage = $locale->text('feedback.verification_email_sent');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'resent') {
            $toastMessage = $locale->text('feedback.verification_code_renewed');
            $toastKind = 'success';
        }
    ?>
    <?php if ($toastMessage !== ''): ?>
        <div id="page-toast" data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>" data-toast-kind="<?= htmlspecialchars($toastKind, ENT_QUOTES, 'UTF-8') ?>" class="hidden" aria-hidden="true"></div>
    <?php endif; ?>

    <p class="mb-2 text-zinc-400"><?= $tr('ui.enter_the_6_digit_code_we') ?></p>
    <p class="mb-8 text-zinc-200 font-medium"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></p>

    <form method="POST" action="<?= htmlspecialchars(base_path('/verify-email'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="code" maxlength="6" placeholder="123456" class="w-64 text-center text-4xl tracking-widest bg-zinc-800 border border-zinc-700 rounded-2xl px-8 py-6" required>
        <button type="submit" class="mt-8 w-full bg-emerald-600 hover:bg-emerald-500 py-4 rounded-2xl font-semibold"><?= $tr('ui.verify_email') ?></button>
    </form>

    <form method="POST" action="<?= htmlspecialchars(base_path('/verify-email/resend'), ENT_QUOTES, 'UTF-8') ?>" class="mt-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="text-sm text-emerald-400 hover:text-emerald-300"><?= $tr('ui.resend_code') ?></button>
    </form>
</div>
