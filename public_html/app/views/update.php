<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<?php
$title = \Causette\Localization\Locale::current()->text('ui.update_causette');
ob_start();
?>
<h1><?= $tr('ui.update_causette') ?></h1>
<dl>
    <dt><?= $tr('ui.current_database_version') ?></dt>
    <dd><?= htmlspecialchars($dbVersion, ENT_QUOTES, 'UTF-8') ?></dd>
    <dt><?= $tr('ui.uploaded_causette_version') ?></dt>
    <dd><?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?></dd>
</dl>
<?php if ($recentAuthentication): ?>
    <?php if ($reactionSteps !== null && version_compare($dbVersion, '1.0.4', '<')): ?>
        <p><?= $tr('ui.reaction_upgrade_progress', ['count' => $reactionSteps]) ?></p>
        <p><?= $tr('ui.reaction_upgrade_permissions') ?></p>
    <?php elseif (empty($apiLimitsUpgrade) && empty($groupNotificationsUpgrade) && empty($openMojiUpgrade)): ?>
        <p><?= $tr('ui.this_update_uses_the_existing_database') ?></p>
    <?php endif; ?>
    <?php if (!empty($apiLimitsUpgrade)): ?>
        <p><?= $tr('ui.api_limit_upgrade_permissions') ?></p>
    <?php endif; ?>
    <?php if (!empty($groupNotificationsUpgrade)): ?>
        <p><?= $tr('ui.group_notifications_upgrade_permissions') ?></p>
    <?php endif; ?>
    <?php if (!empty($openMojiUpgrade)): ?>
        <p><?= $tr('ui.openmoji_upgrade') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars(base_path('/update'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label><input type="checkbox" name="backup_confirmed" value="1" required> <?= $tr('ui.i_have_saved_the_database_configuration') ?></label>
        <button type="submit"><?= $tr('ui.apply_update') ?></button>
    </form>
<?php else: ?>
    <p><?= $tr('ui.sign_in_again_to_confirm_this') ?></p>
    <form method="post" action="<?= htmlspecialchars(base_path('/update/reauthenticate'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit"><?= $tr('ui.sign_in_again') ?></button>
    </form>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/maintenance.php';
