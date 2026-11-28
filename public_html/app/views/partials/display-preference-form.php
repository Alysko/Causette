<?php
use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;

$displayFields = $displayForSite ? DisplayPreferences::read() : DisplayPreferences::read((int)$user->id);
$displayAction = $displayForSite ? '/admin/display-defaults' : '/settings/display-preferences';
$displayPrefix = $displayForSite ? 'site-display' : 'personal-display';
$displayOptions = [
    'language' => [
        'common.language',
        ['' => $locale->text($displayForSite ? 'ui.use_browser_language' : 'ui.use_site_default')] + Locale::languages(),
    ],
    'date_format' => ['ui.date_format', array_map($locale->text(...), DisplayPreferences::DATE_FORMATS)],
    'time_format' => ['ui.time_format', array_map($locale->text(...), DisplayPreferences::TIME_FORMATS)],
];
if (!$displayForSite) {
    foreach (DisplayPreferences::PERSONAL_OPTIONS as $field => [$label, $choices]) {
        $displayOptions[$field] = [$label, array_map($locale->text(...), $choices)];
    }
}
?>
<form method="POST" action="<?= htmlspecialchars(base_path($displayAction), ENT_QUOTES, 'UTF-8') ?>" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($displayOptions as $field => [$label, $choices]): ?>
        <?php
        $fieldId = $displayPrefix . '-' . $field;
        if (!$displayForSite && in_array($field, ['date_format', 'time_format'], true)) {
            $choices = ['' => $locale->text('ui.use_site_default')] + $choices;
        }
        ?>
        <div>
            <label for="<?= $fieldId ?>" class="block text-sm text-zinc-300 mb-1"><?= $tr($label) ?></label>
            <select id="<?= $fieldId ?>" name="<?= $field ?>" class="w-full bg-zinc-800 border border-zinc-700 px-3 py-2 rounded-xl">
                <?php foreach ($choices as $option => $optionLabel): ?>
                    <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"<?= $displayFields[$field] === $option ? ' selected' : '' ?>><?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>
    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-6 py-2 rounded-xl"><?= $tr('ui.save_display_preferences') ?></button>
</form>
