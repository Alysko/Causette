# Causette

Causette is a self-hosted web application for direct and group messaging. It includes message attachments, reactions, mentions, polls, posts and browser-based voice calls, video calls and screen sharing.

## Development status

This repository contains development sources. Wait for a validated release archive and its installation instructions before deploying a live instance.

## Hosting layout

Causette targets ordinary Apache/PHP hosting with MySQL. An operator uses the hosting control panel, a database manager, file transfer and a browser for installation, updates and recovery.

- Point the site's document root at `public_html/`.
- Keep `storage/` beside `public_html/`, outside the document root. The public attachment, avatar and emoji routes are handled by the application. Never publish the storage directory through an Apache alias.
- Plan for HTTPS and a dedicated database user scoped to the application database.

PHP 8.5 is the target minimum for the first release. The verification matrix includes PHP 8.5 and 8.6. Use the latest security patch available from the hosting provider. The release archive will specify tested versions, extensions and server settings.

See the [shared-hosting installation procedure](docs/install.md) for the fresh installation and recovery workflow.

## Development checks

Run the following in a Git checkout with PHP and Node.js installed.

```sh
node scripts/check-syntax.mjs
node --test tests/source-distribution.test.mjs
```

Set `CAUSETTE_PHP` to the PHP executable path if `php` is unavailable on PATH. These checks parse source and verify the source layout. Installation, upgrades and application workflows require separate integration tests.

## License and upstream

Causette is derived from [Prologue v1.0.1](https://github.com/michaelstaake/Prologue) by Michael Staake and contributors. See [LICENSE](LICENSE) for the GPL terms and the existing source notices for component attribution.
