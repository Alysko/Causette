<?php require_once __DIR__ . '/../../core/AssetUrl.php'; ?>
<!DOCTYPE html>
<html lang="<?= \Causette\Localization\Locale::current()->language() ?>" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Causette', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= \Causette\View\AssetUrl::html('/assets/css/causette.css') ?>">
    <style>
        body { background: #09090b; }
        #app-top-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: #000;
            z-index: 10000;
            pointer-events: none;
        }
    </style>
</head>
<body class="min-h-screen text-gray-200 flex items-center justify-center p-4">
<div id="app-top-progress" aria-hidden="true"></div>
<?= $content ?? '' ?>
</body>
</html>
