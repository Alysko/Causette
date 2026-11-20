# Localization

The language picker, language cookie and browser preference select the display language in that order. English is the fallback. Changing the display language does not change stored messages, poll questions, options or names.

## Add a language

Add its code and display name to `public_html/app/locales/languages.php`. Use a lowercase language code such as `es`. Copy the catalog files from `public_html/app/locales/en/` to a directory with that code. Keep the keys and the named placeholders, such as `{creator}`, and translate their text. Missing entries fall back to English.

The catalogs are PHP arrays of plain UTF-8 strings. English and French ship together. User interface templates use `Locale::html()` for text and HTML attributes. Use `Locale::text()` when the caller is responsible for escaping, for example when building data for the browser. Send browser catalogs through the HTML-safe JSON encoder. Browser code uses `uiText()` for shared messages and `chatLabel()` for poll and pin labels. Escape strings before adding them to an existing HTML template, or use DOM text properties.

Provide whole sentences with named placeholders when a translation needs to reorder words. Vote counts use the browser's plural categories and number formatting. Add the categories used by the language and keep the `other` form as a fallback. Poll dates use the selected language and the account's display offset.

Run `php tests/localization.test.php` for English and French key and placeholder checks. The Docker package suite also accepts `-Localization` to exercise both languages in Chromium.
