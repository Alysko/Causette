<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $escape(\Causette\Localization\Locale::current()->language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $tr('ui.causette_credential_storage') ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?= $escape(base_path('/assets/img/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= $escape(base_path('/assets/css/install.css')) ?>">
</head>
<body>
<main>
    <h1><?= $tr('ui.protect_stored_credentials') ?></h1>
    <?php if ($message !== ''): ?>
        <p role="alert"><?= $tr($message) ?></p>
    <?php endif; ?>
    <?php if ($result !== null): ?>
        <p role="status"><?= $tr('ui.credential_maintenance_counts', [
            'keys' => (int)$result['apiKeys'], 'links' => (int)$result['resetLinks'],
        ]) ?></p>
        <?php if ($result['complete']): ?>
            <p><?= $tr('ui.credential_storage_maintenance_completed_back_up') ?></p>
        <?php else: ?>
            <p><?= $tr('ui.submit_another_batch_to_finish_protecting') ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($result === null || !$result['complete']): ?>
        <form method="post" action="<?= $escape(base_path('/credential-upgrade')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
            <label for="credential-operator-token"><?= $tr('ui.operator_token') ?></label>
            <input id="credential-operator-token" type="password" name="operator_token" autocomplete="off" maxlength="128" required>
            <label><input type="checkbox" name="backup_confirmed" value="1" required> <?= $tr('ui.i_saved_a_matching_database_and') ?></label>
            <button type="submit"><?= $tr('ui.protect_credentials') ?></button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
