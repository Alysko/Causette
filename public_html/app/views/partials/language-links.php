<nav class="language-links" aria-label="<?= $locale->html('common.language') ?>">
    <?php foreach ($locale::languages() as $language => $label): ?>
        <a href="<?= htmlspecialchars($languagePath . '?lang=' . $language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" lang="<?= $language ?>" hreflang="<?= $language ?>"<?= $language === $locale->language() ? ' aria-current="true"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</nav>
