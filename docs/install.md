# Fresh installation on shared hosting

Use this procedure on a dedicated test site. The first release still requires browser and hosting validation. Keep the test site private and use synthetic account data.

## Prepare the site in the hosting panel

1. Create a dedicated test domain or subdomain with a valid TLS certificate. Use the hosting panel to make PHP 8.5 effective for that domain and enable PDO MySQL, mbstring, Fileinfo, GD, OpenSSL, ctype and sessions. Disable automatic PHP session start. The distributed `.htaccess` inherits the host's PHP handler. Use direct HTTPS or an explicitly approved reverse proxy, with a dedicated PHP document root.
2. Use the panel's database tool to create an empty database and a dedicated database account. Grant `CREATE`, `REFERENCES`, `SELECT`, `INSERT`, `UPDATE` and `DELETE` on that database. Keep grants confined to the dedicated database. In the database web interface, check the server version. MySQL and MariaDB are different engines even when the panel calls the database tool “MySQL”. The supplied schema requires InnoDB pages of at least 16 KiB.
3. In the file manager, upload and extract the package in a site directory outside all existing document roots. Set the test domain's document root to the extracted `public_html/` directory. Its sibling `storage/` must be outside every domain's document root, with no server alias. Keep the uploaded archive in the private site directory.
4. Make `storage/` accessible only to the account running PHP, using mode `0700` on compatible shared hosting. Ensure PHP can write to `storage/`, `storage/attachments/`, `storage/avatars/` and `storage/logs/`. The bundled `storage/emojis/` directory must be readable.
5. Use the file manager to create `storage/setup.json` with mode `0600`. Substitute the exact HTTPS origin without a final slash and the dedicated database details. Generate a unique operator token with a password manager. Use 64 random letters and digits.

```json
{
  "url": "https://your-test-domain.example",
  "bootstrap_token": "REPLACE_WITH_A_GENERATED_OPERATOR_TOKEN",
  "database": {
    "host": "localhost",
    "name": "your_test_database",
    "user": "your_test_database_user",
    "password": "REPLACE_WITH_THE_DEDICATED_DATABASE_PASSWORD"
  }
}
```

Keep the operator token and database password private. Use the setup form for the token and the hosting panel for the database password.

## Configure HTTPS behind a reverse proxy

Use the full public HTTPS address in `url`, including a non-default port or mounted path when applicable. Leave `trusted_proxies` absent for direct HTTPS. When a proxy terminates the browser's TLS connection, add the proxy IPs as a `trusted_proxies` array in the private configuration. Setup carries this value into `storage/causette.json`. Use addresses assigned by the hosting provider to the immediate proxy that PHP sees in `REMOTE_ADDR`.

Configure the public edge through the hosting panel to enforce HTTPS for the whole site, including static files. The approved proxy must replace every received `X-Forwarded-Proto` value with one `https` or `http` value based on the browser connection. Preserve the public `Host` and its configured port, and restrict direct access to the backend. Strip other client-supplied forwarding headers. Causette ignores forwarded hosts, ports and client addresses. Missing or ambiguous scheme reports from an approved proxy produce an error.

For a mounted path, the web server must route that public prefix to the dedicated `public_html/` directory while preserving the request path. Keep `storage/` private and outside every document root. Setup, sign-in and password recovery under a prefix were exercised in Docker. Use a dedicated domain or subdomain for the hosted trial while the full browser workflow under a prefix is being verified.

## Install in the browser

1. Open `/install` at the exact HTTPS address in the setup file. Enter the operator token. The administrator form appears after verification, with a ten-minute authorization window.
2. Enter a synthetic administrator email, choose a username and generate a unique password. Submit the form and record the PHP and database server versions shown on the completion page.
3. Sign in with the new administrator account. Check the database web interface for the administrator and the application version setting. Reopen `/install` in a separate browser session and verify that setup is unavailable.
4. In the file manager, create a temporary plain text file with a random filename and harmless text under `storage/`. Confirm that the corresponding HTTPS URL under `/storage/` returns 403 or 404 without serving the file. Check `/app/install/schema-v1.sql` the same way and confirm the schema file is present in the extracted package through the file manager. If the browser shows a generic error page, check the HTTP status in the hosting panel's access log. Remove the temporary file through the file manager.

Completion writes `storage/causette.json` with the connection settings, the installation identifier and two generated keys. It also creates `storage/integration-keys.json` with a separate encryption key for stored SMTP and VAPID secrets. Both files require mode `0600`. Causette checks the installation identifier against the database before serving the application. Keep both files in the matching private backup. A successful installation removes `setup.json` when the host permits deletion. If the panel still shows it, remove it there once sign-in and the setup lockout have been verified.

## Resume an interrupted installation

Use the same site directory, package, setup file and database. Follow any error message about permissions, available extensions or a setup request already in progress. After the prior request finishes, reopen `/install`, reauthorize and submit the original administrator details again. Keep `install-state.json` in place while resuming. It records the schema steps associated with this database.

If the setup reports a database or journal mismatch, stop and check the database selection and package in the hosting panel. Preserve any database and files you need to retain. Start a fresh attempt in a separate site directory with a new empty database if the original state cannot be restored.

Use the [import guide](import.md) to move an existing upstream backup. The [maintenance guide](maintenance.md) covers backups, updates and restore rehearsals.
