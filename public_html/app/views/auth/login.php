<?php $locale = \Causette\Localization\Locale::current(); ?>
<div class="bg-zinc-900 p-8 rounded-3xl border border-zinc-700">
    <?php $languagePath = base_path('/login'); require __DIR__ . '/../partials/language-links.php'; ?>
    <div class="flex items-center gap-3 mb-5" aria-hidden="true">
        <span class="h-px flex-1 bg-zinc-700"></span>
        <span class="text-xs uppercase tracking-[0.2em] text-zinc-500"><?= $locale->html('login.welcome') ?></span>
        <span class="h-px flex-1 bg-zinc-700"></span>
    </div>
    <?php
        $toastMessage = '';
        $toastKind = 'info';
        $flashError = flash_get('error');
        $flashSuccess = flash_get('success');
        if ($flashError === 'banned') {
            $toastMessage = $locale->text('login.banned');
            $toastKind = 'error';
        } elseif ($flashError === 'too_many_attempts') {
            $toastMessage = $locale->text('login.too_many');
            $toastKind = 'error';
        } elseif ($flashError === 'captcha_failed') {
            $toastMessage = $locale->text('login.captcha');
            $toastKind = 'error';
        } elseif ($flashError === 'mfa_unavailable') {
            $toastMessage = $locale->text('login.mfa_unavailable');
            $toastKind = 'error';
        } elseif ($flashError === 'mfa_expired') {
            $toastMessage = $locale->text('login.mfa_expired');
            $toastKind = 'error';
        } elseif ($flashError !== null) {
            $toastMessage = $locale->text('login.failed');
            $toastKind = 'error';
        } elseif ($flashSuccess === 'installed') {
            $toastMessage = $locale->text('login.installed');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'reset') {
            $toastMessage = $locale->text('login.reset');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'email_verified' || $flashSuccess === 'registered') {
            $toastMessage = $flashSuccess === 'email_verified' ? $locale->text('login.verified') : $locale->text('login.registered');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'session_exited') {
            $toastMessage = $locale->text('login.session_exited');
            $toastKind = 'success';
        } elseif ($flashSuccess === 'update_complete') {
            $toastMessage = $locale->text('login.update_complete');
            $toastKind = 'success';
        }
    ?>
    <?php if ($toastMessage !== ''): ?>
        <div id="page-toast" data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>" data-toast-kind="<?= htmlspecialchars($toastKind, ENT_QUOTES, 'UTF-8') ?>" class="hidden" aria-hidden="true"></div>
    <?php endif; ?>
    <form method="POST" action="<?= htmlspecialchars(base_path('/login'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label for="login-identifier" class="block text-sm text-zinc-300 mb-2"><?= $locale->html('login.identifier') ?></label>
        <input id="login-identifier" type="text" name="identifier" placeholder="<?= $locale->html('login.identifier') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 mb-4" required autofocus>
        <label for="login-password" class="block text-sm text-zinc-300 mb-2"><?= $locale->html('login.password') ?></label>
        <input id="login-password" type="password" name="password" placeholder="<?= $locale->html('login.password') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 mb-4" required>
        <div class="flex items-center mb-6">
            <label for="remember_me" class="flex items-center gap-3 cursor-pointer select-none group">
                <div class="relative w-4 h-4 flex-shrink-0">
                    <input type="checkbox" name="remember_me" id="remember_me" value="1" checked
                           class="peer absolute inset-0 opacity-0 w-full h-full cursor-pointer m-0">
                    <div class="w-4 h-4 rounded border border-zinc-600 bg-zinc-800 peer-checked:bg-emerald-600 peer-checked:border-emerald-600 peer-focus:ring-2 peer-focus:ring-emerald-500 peer-focus:ring-offset-1 peer-focus:ring-offset-zinc-900 transition-all duration-150 pointer-events-none"></div>
                    <svg class="absolute inset-0 w-4 h-4 text-white scale-0 peer-checked:scale-100 transition-transform duration-150 pointer-events-none" viewBox="0 0 16 16" fill="none">
                        <path d="M4 8.5l2.5 2.5 5.5-5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <span class="text-sm text-zinc-400 group-hover:text-zinc-300 transition-colors duration-150"><?= $locale->html('login.remember') ?></span>
            </label>
        </div>
        <?php if (!empty($captchaWidgetHtml)): ?>
            <div class="mb-4"><?= $captchaWidgetHtml ?></div>
            <script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>" src="<?= htmlspecialchars($captchaScriptUrl, ENT_QUOTES, 'UTF-8') ?>" async defer></script>
        <?php endif; ?>
        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 py-4 rounded-2xl font-semibold"><?= $locale->html('login.submit') ?></button>
    </form>
    <div class="mt-8">
        <div class="flex items-center gap-3 mb-5" aria-hidden="true">
            <span class="h-px flex-1 bg-zinc-700"></span>
            <span class="text-xs uppercase tracking-[0.2em] text-zinc-500"><?= $locale->html('login.join') ?></span>
            <span class="h-px flex-1 bg-zinc-700"></span>
        </div>
        <a href="<?= htmlspecialchars(base_url('/register'), ENT_QUOTES, 'UTF-8') ?>" class="group inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-emerald-400/40 bg-emerald-500/15 px-5 py-3.5 font-semibold text-emerald-300 transition-all duration-200 hover:border-emerald-300 hover:bg-emerald-500/25 hover:text-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-zinc-900">
                <span><?= $locale->html('login.create_account') ?></span>
            </a>
        <div class="mt-5">
            <div class="flex items-center gap-3 mb-3" aria-hidden="true">
                <span class="h-px flex-1 bg-zinc-700"></span>
                <span class="text-xs uppercase tracking-[0.2em] text-zinc-500"><?= $locale->html('login.forgot') ?></span>
                <span class="h-px flex-1 bg-zinc-700"></span>
            </div>
            <div class="text-center">
                <a href="<?= htmlspecialchars(base_url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>" class="inline-block text-zinc-400 text-sm hover:text-zinc-300 transition-colors duration-150"><?= $locale->html('login.reset_request') ?></a>
            </div>
        </div>
    </div>
</div>
