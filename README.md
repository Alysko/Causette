# Causette

Causette is a self-hosted chat app with direct and group messages, file sharing, mentions, reactions, posts and polls. Voice and video calls support screen sharing in the browser.

## Improvements since the initial fork

- **Faster messaging.** A message appears immediately and uses the same layout before and after server confirmation. If sending fails, the draft stays in the composer. In-app navigation loads the conversation view on its own, and background checks skip downloading message history when it has not changed.
- **Easier-to-scan conversations.** Recent dates use “today”, “yesterday” or a weekday. The timeline omits a timestamp when the preceding entry is within one minute on the same day. Reply and reaction buttons sit alongside the text. The sidebar remembers the Unread filter, and routine actions such as renaming a group no longer trigger a redundant notification.
- **Display and language preferences.** Choose a theme, time zone and date format per account. The dark theme has higher contrast, and the interface uses locally hosted Atkinson Hyperlegible fonts. The interface, notifications and account emails support English and French.
- **Emoji and calls.** The composer and reaction picker share a set of common 3D emoji and retain support for older emoji. Webcam and screen sharing have separate quality presets, and an optional TURN relay helps calls connect when a direct media connection is unavailable.
- **Account and file protection.** Sign-in stops if a required email second-factor code cannot be delivered. Remembered sessions expire and can be revoked. API keys are shown once and stored as digests. Attachments stay in private storage, and each download checks conversation access. Uploads have storage and image-decoding limits.
- **Installations and updates on shared hosting.** Protected browser workflows handle setup, database updates and import of an upstream backup. Validation ZIPs include file hashes and a dependency inventory. When a ZIP is built against a previous package, unchanged files keep their timestamps so an FTP client can skip them.

See the [feature inventory and verification limits](docs/functional-coverage.md).

## Development status

Causette is still in development. Wait for a tested release ZIP before deploying a live instance.

## Hosting

Causette targets shared hosting with Apache, PHP and MySQL. Install, update and restore it with a browser, the hosting panel, a database manager and file transfer.

Serve `public_html/` over HTTPS. Keep `storage/` next to it, outside the document root, without an Apache alias. The app serves attachments, avatars and emoji. Use a database account scoped to Causette's database.

The first release targets PHP 8.5 or later. CI checks PHP 8.5 and 8.6. Use your host's latest PHP security patch. The release ZIP will list the tested versions, extensions and server settings.

[Installation and setup recovery](docs/install.md) · [Import an upstream backup](docs/import.md) · [Backup, update and restore](docs/maintenance.md)

## Development checks

Run these source checks in a Git checkout with PHP and Node.js installed.

```sh
node scripts/check-syntax.mjs
node --test tests/source-distribution.test.mjs
```

If `php` is missing from `PATH`, set `CAUSETTE_PHP` to the PHP executable. Use separate integration tests for installation, upgrades and browser workflows.

## License and upstream

Causette is a hardened fork of [Prologue v1.0.1](https://github.com/michaelstaake/Prologue) by Michael Staake and contributors. See [LICENSE](LICENSE) for the GPL terms, the source notices for component credits and the [branding record](docs/branding.md) for compatibility references.
