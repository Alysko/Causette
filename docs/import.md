# Import a Prologue 1.0.1 backup

The import uses a restored copy of the source database and private files. It keeps accounts, password hashes, conversations, posts, polls and attachments. The browser first prepares the private Causette configuration. An existing administrator then signs in and applies the database update.

## Restore a copy in cPanel

1. Pause writes to the source before making the final backup. Export its database in phpMyAdmin and archive the source files, private storage and configuration in File Manager. Keep the backups outside the web root. Record the counts for `users`, `chats`, `messages`, `poll_votes` and `attachments`. Check space for the extracted files and for the database update.
2. Create a separate database and a dedicated user in cPanel. Import the SQL file into the new database from phpMyAdmin. In `settings`, confirm that `database_version` is `1.0.1`. Check the counts against the export. The account needs `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER` and `REFERENCES` on this database only.
3. Extract the Causette ZIP into a new directory. Point the test site's HTTPS document root at its `public_html/`. Use Directory Privacy for the rehearsal. The sibling `storage/` must be outside every web root and protected at mode `0700`. Copy the source attachment and avatar directories into it without changing filenames. Keep the Causette emoji assets. Check that the attachment and avatar files match the backup. The import checks the size and location of up to 20 recent submitted attachments.
4. Keep the original executable PHP configuration in the private backup. Create `storage/setup.json` with mode `0600` using the settings below. Copy the database details for the restored database and the exact HTTPS URL of the new site. Use a password manager to generate a token with 64 random letters and digits.

```json
{
    "url": "https://your-test-domain.example",
    "bootstrap_token": "REPLACE_WITH_A_GENERATED_OPERATOR_TOKEN",
    "database": {
        "host": "localhost",
        "name": "your_restored_database",
        "user": "your_dedicated_database_user",
        "password": "REPLACE_WITH_THE_DEDICATED_DATABASE_PASSWORD"
    },
    "import": {
        "source": "prologue-1.0.1",
        "legacy_totp_secret": "REPLACE_WITH_THE_ORIGINAL_CSRF_SECRET_VALUE"
    }
}
```

The `legacy_totp_secret` value is the exact original `$CONFIG_CSRF_SECRET` from the source configuration. Omit it if the database contains no confirmed authenticator enrollment. If confirmed enrollments exist and the original value is unavailable, the import stops without changing the database. The new site generates separate secrets and re-encrypts confirmed enrollments. Keep the original key with the private backup.

### Older database encodings

Some source tables inherited their character set from the hosting panel. In phpMyAdmin, inspect the restored database and its text columns. The import requires `utf8mb4`. If you find `latin1`, `utf8mb3` or another encoding, select `utf8mb4_unicode_ci` under **Operations** for the restored database and apply it to all tables and columns. Changing the database default alone does not convert existing text. Compare accented characters and other non-ASCII text with the source before continuing. If conversion fails or changes the text, stop and restore a fresh copy from the original export. [phpMyAdmin character set operations](https://develdocs.phpmyadmin.net/phpmyadmin/PhpMyAdmin/Operations.html)

## Prepare the data and update

1. Open `/install` on the new site and enter the operator token. Enter the username and password of an active administrator whose email was verified on the source. Confirm that the database and file backups exist and that this site uses the copy.
2. After preparation, sign in using the same account. Complete the account's usual second factor if prompted. Follow the maintenance link to `/update`, confirm the backup and apply the update. Verify the dashboard, an existing conversation and attachment, and sign-in through an existing authenticator if one was configured.
3. In phpMyAdmin, compare the content counts with the recorded baseline and check that `database_version` matches the package. In File Manager, confirm that `storage/causette.json` and `storage/integration-keys.json` have mode `0600`. After sign-in and `/install` lockout are verified, remove `setup.json` from private storage if it remains. Back up the new private configuration and key file with the migrated database.

Old browser sessions, trusted IP grants, password-reset links and pending verification codes are retired. Users sign in again, and bots need replacement API keys. Confirmed authenticator enrollments and unused recovery codes are preserved. The import encrypts saved SMTP and Web Push secrets. Verify the providers on the new origin before relying on them. Old browser push subscriptions may need to be recreated after an origin change.

## Resume or restore

After an interrupted import, keep the same restored database, private storage and package. Return to `/install` and authorize again if prompted. If `causette.json` already exists, sign in and resume `/update`. Keep `prologue-import-state.json` and `integration-keys.json` with the restored copy while preparation is incomplete. When the original encryption secret is wrong, correct it in `setup.json` and authorize again. Keep configuration and keys out of support messages.

If you need to start over, restore the original SQL and files into a separate empty target. An existing import marker belongs to its original private configuration and journal. To return to the original application during a trial, leave the restored copy isolated and use the untouched source instance. Keep the source backup until the browser and data checks pass.

Credential preparation runs within one PHP request and one transaction. If the host interrupts the request before commit, the transaction rolls back and a retry starts that phase again. Hosts with short request limits or unusually large credential tables may need a separate migration plan. If the original authenticator key is lost, restore it from a protected source backup before proceeding. This importer preserves the second factor for affected accounts.
