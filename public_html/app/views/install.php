<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Causette setup</title>
    <link rel="stylesheet" href="/assets/css/install.css">
</head>
<body>
<main>
    <h1>Causette setup</h1>
    <?php if ($message !== ''): ?>
    <p role="alert"><?= $escape($message) ?></p>
    <?php endif; ?>
    <?php if ($screen === 'operator'): ?>
    <p>Enter the token you placed in the private setup file through your hosting panel.</p>
    <form method="post" action="/install">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="stage" value="authorize">
        <label for="bootstrap-token">Operator token</label>
        <input id="bootstrap-token" type="password" name="bootstrap_token" maxlength="128" required autofocus>
        <button type="submit">Continue</button>
    </form>
    <?php elseif ($screen === 'administrator'): ?>
    <p>Create the first administrator. Setup will use the dedicated database configured in the private file.</p>
    <form method="post" action="/install">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="stage" value="install">
        <label for="admin-username">Administrator username</label>
        <input id="admin-username" name="username" autocomplete="username" maxlength="32" pattern="[a-z][a-z0-9]{3,31}" required autofocus>
        <label for="admin-email">Administrator email</label>
        <input id="admin-email" type="email" name="email" maxlength="254" autocomplete="email" required>
        <label for="admin-password">Administrator password</label>
        <input id="admin-password" type="password" name="password" autocomplete="new-password" minlength="15" maxlength="72" aria-describedby="password-length" required>
        <small id="password-length">Use a unique password of 15–72 bytes.</small>
        <button type="submit">Install Causette</button>
    </form>
    <?php elseif ($screen === 'success'): ?>
    <p>Causette is installed. You can now sign in with the administrator account.</p>
    <dl>
        <dt>PHP</dt><dd><?= $escape(PHP_VERSION) ?></dd>
        <dt>Database server</dt><dd><?= $escape($databaseVersion) ?></dd>
    </dl>
    <p><a href="/login">Sign in</a></p>
    <?php endif; ?>
</main>
</body>
</html>
