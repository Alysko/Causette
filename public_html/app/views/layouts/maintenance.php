<!doctype html>
<html lang="<?= htmlspecialchars(\Causette\Localization\Locale::current()->language(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(base_path('/assets/css/install.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<main>
    <?= $content ?>
</main>
</body>
</html>
