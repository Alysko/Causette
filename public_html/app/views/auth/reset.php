<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$flashError = flash_get('error');
?>
<!doctype html>
<html lang="<?= $escape(\Causette\Localization\Locale::current()->language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $tr('ui.reset_your_causette_password') ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?= $escape(base_path('/assets/img/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= $escape(base_path('/assets/css/install.css')) ?>">
    <?php require __DIR__ . '/../partials/typography.php'; ?>
</head>
<body>
<main>
    <h1><?= $tr('ui.choose_a_new_password') ?></h1>
    <?php if ($flashError !== null || $token === ''): ?>
        <p role="alert"><?= $tr('ui.invalid_or_missing_reset_link') ?> <a href="<?= $escape(base_path('/forgot-password')) ?>"><?= $tr('ui.request_a_new_link') ?></a>.</p>
    <?php endif; ?>
    <?php if ($token !== ''): ?>
        <form method="post" action="<?= $escape(base_path('/reset-password')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">
            <label for="reset-password"><?= $tr('ui.new_password') ?></label>
            <input id="reset-password" type="password" name="password" autocomplete="new-password" minlength="8" required>
            <label>
                <input type="checkbox" name="revoke_api_keys" value="1" aria-describedby="reset-api-keys-help">
                <?= $tr('ui.revoke_all_api_keys_including_bot') ?>
            </label>
            <small id="reset-api-keys-help"><?= $tr('ui.select_this_if_a_key_may') ?></small>
            <button type="submit"><?= $tr('ui.update_password') ?></button>
        </form>
    <?php endif; ?>
    <p><a href="<?= $escape(base_path('/login')) ?>"><?= $tr('ui.back_to_login') ?></a></p>
</main>
</body>
</html>
