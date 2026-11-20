<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<?php
$title = \Causette\Localization\Locale::current()->text('ui.causette_maintenance');
ob_start();
?>
<h1><?= $tr('ui.causette_maintenance') ?></h1>
<?php if ($updateAvailable): ?>
    <p><?= $tr('ui.an_administrator_needs_to_confirm_the') ?></p>
    <p><a href="<?= htmlspecialchars(base_path('/login'), ENT_QUOTES, 'UTF-8') ?>"><?= $tr('ui.sign_in_as_an_administrator') ?></a></p>
    <p><a href="<?= htmlspecialchars(base_path('/update'), ENT_QUOTES, 'UTF-8') ?>"><?= $tr('ui.open_the_update_page') ?></a></p>
<?php else: ?>
    <p><?= $tr('ui.the_application_and_database_versions_differ') ?></p>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layouts/maintenance.php';
