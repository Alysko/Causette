<?php
$locale = \Causette\Localization\Locale::current();
$tr = $locale->html(...);
?>
<div class="bg-zinc-900 p-8 rounded-3xl border border-zinc-700 text-center">
    <h1 class="text-2xl font-bold mb-6"><?= $tr('ui.two_factor_authentication') ?></h1>
    <?php
        $toastMessage = '';
        $toastKind = 'error';
        if (flash_get('error') !== null) {
            $toastMessage = $locale->text('feedback.invalid_or_expired_code');
        }
    ?>
    <?php if ($toastMessage !== ''): ?>
        <div id="page-toast" data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>" data-toast-kind="<?= htmlspecialchars($toastKind, ENT_QUOTES, 'UTF-8') ?>" class="hidden" aria-hidden="true"></div>
    <?php endif; ?>
    <?php if (($providerLabel ?? '') === 'Authenticator App'): ?>
        <p class="mb-6 text-zinc-400"><?= $tr('ui.enter_the_6_digit_code_from') ?></p>
    <?php elseif (($providerLabel ?? '') === 'Email'): ?>
        <p class="mb-6 text-zinc-400"><?= $tr('ui.enter_the_6_digit_code_sent') ?></p>
    <?php else: ?>
        <p class="mb-6 text-zinc-400"><?= $tr('ui.enter_the_6_digit_code') ?></p>
    <?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars(base_path('/2fa'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="code" maxlength="8" autocomplete="one-time-code" aria-label="<?= $tr('ui.verification_code') ?>" placeholder="123456" class="w-64 text-center text-4xl tracking-widest bg-zinc-800 border border-zinc-700 rounded-2xl px-8 py-6" required>
        <?php if (($providerLabel ?? '') === 'Authenticator App'): ?>
            <p class="mt-3 text-sm text-zinc-500"><?= $tr('ui.you_can_also_use_a_recovery') ?></p>
        <?php endif; ?>
        <?php if (!empty($mayTrustBrowser)): ?>
            <label class="mt-5 flex items-center justify-center gap-2 text-sm text-zinc-300">
                <input type="checkbox" name="trust_browser" value="1">
                <?= $tr('ui.trust_browser_for_days', ['days' => \Causette\Security\TrustedBrowser::LIFETIME_DAYS]) ?>
            </label>
        <?php endif; ?>
        <button type="submit" class="mt-8 w-full bg-emerald-600 hover:bg-emerald-500 py-4 rounded-2xl font-semibold"><?= $tr('ui.verify') ?></button>
        <button type="submit" formaction="<?= htmlspecialchars(base_path('/logout'), ENT_QUOTES, 'UTF-8') ?>" formnovalidate class="mt-4 text-sm text-zinc-400 hover:text-zinc-200"><?= $tr('ui.cancel') ?></button>
    </form>
</div>
