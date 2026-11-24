<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$installPath = $setupBasePath . '/install';
?>
<!doctype html>
<html lang="<?= $escape($locale->language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $locale->html('install.title') ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?= $escape($setupBasePath) ?>/assets/img/favicon.png">
    <link rel="stylesheet" href="<?= $escape($setupBasePath) ?>/assets/css/install.css">
    <?php require __DIR__ . '/partials/typography.php'; ?>
</head>
<body>
<main>
    <?php if (!in_array($screen, ['success', 'imported'], true)): ?>
        <?php $languagePath = $installPath; require __DIR__ . '/partials/language-links.php'; ?>
    <?php endif; ?>
    <h1><?= $locale->html('install.title') ?></h1>
    <?php if ($messageKey !== '' || $fieldErrors !== []): ?>
        <section role="alert" aria-labelledby="setup-errors-title">
            <h2 id="setup-errors-title"><?= $locale->html('install.error_summary') ?></h2>
            <?php if ($messageKey !== ''): ?>
                <p><?= $locale->html($messageKey, $parameters) ?></p>
            <?php endif; ?>
            <?php if ($fieldErrors !== []): ?>
                <ul>
                    <?php foreach ($fieldErrors as $field => $key): ?>
                        <li><a href="#<?= $escape($field) ?>"><?= $locale->html($key) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($screen === 'operator'): ?>
        <p><?= $locale->html('install.operator_intro') ?></p>
        <form method="post" action="<?= $escape($installPath) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="stage" value="authorize">
            <label for="bootstrap-token"><?= $locale->html('install.operator_token') ?></label>
            <input id="bootstrap-token" type="password" name="bootstrap_token" maxlength="128" required autofocus>
            <button type="submit"><?= $locale->html('install.continue') ?></button>
        </form>
    <?php elseif ($screen === 'administrator'): ?>
        <p><?= $locale->html('install.admin_intro') ?></p>
        <form method="post" action="<?= $escape($installPath) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="stage" value="install">
            <label for="admin-username"><?= $locale->html('install.username') ?></label>
            <input id="admin-username" name="username" autocomplete="username" maxlength="32" pattern="[a-z][a-z0-9]{3,31}" aria-describedby="username-format" <?= isset($fieldErrors['admin-username']) ? 'aria-invalid="true"' : '' ?> required autofocus>
            <small id="username-format"><?= $locale->html('install.username_help') ?></small>
            <label for="admin-email"><?= $locale->html('install.email') ?></label>
            <input id="admin-email" type="email" name="email" maxlength="254" autocomplete="email" <?= isset($fieldErrors['admin-email']) ? 'aria-invalid="true"' : '' ?> required>
            <label for="admin-password"><?= $locale->html('install.password') ?></label>
            <input id="admin-password" type="password" name="password" autocomplete="new-password" aria-describedby="password-length" <?= isset($fieldErrors['admin-password']) ? 'aria-invalid="true"' : '' ?> required>
            <small id="password-length"><?= $locale->html('install.password_help') ?></small>
            <button type="submit"><?= $locale->html('install.submit') ?></button>
        </form>
    <?php elseif ($screen === 'import'): ?>
        <h2><?= $locale->html('install.import_title') ?></h2>
        <p><?= $locale->html('install.import_intro') ?></p>
        <form method="post" action="<?= $escape($installPath) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="stage" value="import">
            <label for="import-username"><?= $locale->html('install.username') ?></label>
            <input id="import-username" name="username" autocomplete="username" maxlength="32" required>
            <label for="import-password"><?= $locale->html('install.password') ?></label>
            <input id="import-password" name="password" type="password" autocomplete="current-password" maxlength="4096" required>
            <label><input type="checkbox" name="backup_confirmed" value="1" required> <?= $locale->html('install.import_backup') ?></label>
            <button type="submit"><?= $locale->html('install.import_submit') ?></button>
        </form>
    <?php elseif ($screen === 'success' || $screen === 'imported'): ?>
        <p><?= $locale->html($screen === 'imported' ? 'install.import_success' : 'install.success') ?></p>
        <?php if ($screen === 'imported'): ?>
            <p><?= $locale->html('install.import_next') ?></p>
        <?php endif; ?>
        <dl>
            <dt>PHP</dt><dd><?= $escape(PHP_VERSION) ?></dd>
            <dt><?= $locale->html('install.database_version') ?></dt><dd><?= $escape($databaseVersion) ?></dd>
        </dl>
        <p><a href="<?= $escape($setupBasePath) ?>/login"><?= $locale->html('install.sign_in') ?></a></p>
    <?php endif; ?>
</main>
</body>
</html>
