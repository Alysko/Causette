# Causette

Causette is a self-hosted chat app with direct and group messages, file sharing, mentions, reactions, posts and polls. Voice and video calls support screen sharing in the browser.

## Development status

Causette is still in development. Wait for a tested release ZIP before deploying a live instance.

## Hosting

Causette targets shared hosting with Apache, PHP and MySQL. Install, update and restore it with a browser, the hosting panel, a database manager and file transfer.

Serve `public_html/` over HTTPS. Keep `storage/` next to it, outside the document root, without an Apache alias. The app serves attachments, avatars and emoji. Use a database account scoped to Causette's database.

The first release targets PHP 8.5 or later. CI checks PHP 8.5 and 8.6. Use your host's latest PHP security patch. The release ZIP will list the tested versions, extensions and server settings.

[Installation and setup recovery](docs/install.md) · [Backup, update and restore](docs/maintenance.md)

## Development checks

Run these source checks in a Git checkout with PHP and Node.js installed.

```sh
node scripts/check-syntax.mjs
node --test tests/source-distribution.test.mjs
```

If `php` is missing from `PATH`, set `CAUSETTE_PHP` to the PHP executable. Use separate integration tests for installation, upgrades and browser workflows.

## License and upstream

Causette is based on [Prologue v1.0.1](https://github.com/michaelstaake/Prologue) by Michael Staake and contributors. See [LICENSE](LICENSE) for the GPL terms, the source notices for component credits and the [branding record](docs/branding.md) for compatibility references.
