<?php require_once __DIR__ . '/../../core/AssetUrl.php'; ?>
<!DOCTYPE html>
<html lang="<?= \Causette\Localization\Locale::current()->language() ?>" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Causette', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?= \Causette\View\AssetUrl::html('/assets/img/favicon.png') ?>">
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/causette.css') ?>">
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/layout.css') ?>">
    <?php require __DIR__ . '/../partials/typography.php'; ?>
</head>
<body class="min-h-screen text-gray-200 flex items-center justify-center p-4">
<div id="app-top-progress" aria-hidden="true"></div>
<?= $content ?? '' ?>
</body>
</html>
