<?php $fontPath = htmlspecialchars(isset($setupBasePath) ? $setupBasePath . '/fonts/' : base_path('/fonts/'), ENT_QUOTES, 'UTF-8'); ?>
<link rel="preload" href="<?= $fontPath ?>atkinson-hyperlegible-next-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $fontPath ?>atkinson.css">
