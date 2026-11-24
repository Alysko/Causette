# Backup, update and restore on shared hosting

These procedures target a Causette installation with a dedicated HTTPS document root and a sibling private storage directory. Use a separate test site with synthetic data for a restore rehearsal.

## Configure calls across networks

Causette exchanges WebRTC signaling through ordinary HTTPS requests. Browsers try a direct media connection by default. A direct connection may fail across separate networks, strict firewalls or certain NAT configurations.

To use a STUN service, add a `webrtc_stun_servers` setting in the site's `settings` table through the database manager. The value is a comma-separated list of up to four `stun:` or `stuns:` URLs, with optional ports. The browser connects to those services and discloses its network address.

Networks where direct media cannot connect can use an existing external TURN relay that supports coturn's TURN REST authentication. Use a dedicated relay realm or service for the site. With the hosting file manager, create `storage/webrtc-turn.json` with mode `0600`. Generate 32 random bytes in a password manager and encode them as 64 hexadecimal characters. Use the same text as the shared TURN REST secret on the relay and in this file.

```json
{
  "urls": [
    "turn:relay.example.test:3478?transport=udp",
    "turns:relay.example.test:443?transport=tcp"
  ],
  "shared_secret": "<64 hex characters>"
}
```

Use one or two URLs with a DNS name, port and explicit transport. A `turns:` URL uses TCP and requires a valid relay certificate. Each joined account gets a pseudonymous ticket for the active call. The browser requests the next ticket after 20 minutes, and each ticket can start new allocations for at most 30 minutes. Manage relay allocation, bandwidth and destination policies with the relay operator. The relay can observe IP addresses and traffic volume, and relayed media consumes the service's bandwidth. Choose limits and costs before enabling it. Protect the configuration file alongside other private keys in backups. [coturn TURN REST settings](https://github.com/coturn/coturn/blob/master/examples/etc/turnserver.conf)

Moving a group to trash stops Causette from issuing or renewing its relay tickets. Tickets issued before archiving retain their original expiry at the relay.

Signaling messages expire for recipients after two minutes. Writes periodically remove an expired batch during ordinary activity. A quiet installation can retain expired rows until new signaling writes trigger cleanup. Camera, screen and microphone data travel between browsers through the WebRTC connection.

## Save a backup

1. Use the hosting panel to block application traffic before exporting. Pause any scheduled requests and allow active requests to finish. Keep the database and files together as a single backup set.
2. In the database manager, export the entire Causette database as SQL, including the structure and every table's data. Check that the export finished and record the database server version and the value of `database_version` in the settings table.
3. Use the file manager to copy the matching application files and private storage, including hidden files, attachments, avatars and `storage/causette.json`. Keep archives and SQL exports outside every document root and retain them in access-controlled storage. Record which application package matches the backup.
4. Check that the SQL export, application files and private storage copy are readable from the backup location. Restore the complete set to a disposable site to verify it.

Keep the private configuration and `storage/integration-keys.json` with the matching database backup. They contain the keys needed to read encrypted data. Retain them and the installation identifier when restoring to a site with different connection credentials. Protect or remove older backups according to your retention policy. Backups made before credential conversion may still contain reusable secrets.

## Apply a code-only update

Compare `database_version` in the installed database with `database_version` in the incoming `public_html/version.php`. When they match, keep the existing document root and apply the release as described below. Then sign in normally and verify the new application version and a sample conversation. Causette 1.1.3 uses database version 1.0.6.

Keep a fixed installation directory with `public_html/` and private `storage/` next to each other. The subdomain always points to `public_html/`. Save a backup outside both directories and verify that the hosting panel preserves any site-specific PHP and access rules. On a Basic-protected recipe, keep those rules in the installation directory's own `.htaccess` if the host supports inheritance. Confirm that an anonymous request receives HTTP 401 and that the operator can sign in over HTTPS.

For an overlay-compatible release, extract the ZIP in the installation directory, where both directories are visible. The distributed `storage/` entries contain static emoji files and empty-directory placeholders. They can be extracted alongside the existing private configuration and uploads. Remove the uploaded archive after the update. If the incoming manifest drops or renames public files, replace the contents of `public_html/` using the file manager so obsolete files do not remain reachable. Keep the document root path and live `storage/` in place.

Older packages through 1.0.6 used `app_version` as the required database marker. A package can list older source markers in `compatible_database_versions`. Follow its migration instructions when it needs database changes. The historical 1.0.2 validation package used the sequence below to update the stored marker on a Causette 1.0.1 installation with private JSON configuration.

1. Block application traffic through the hosting panel and save a backup. Confirm the package identity and supported source version before changing files.
2. Extract the incoming package in a private staging directory. Use the file manager to replace the site's `public_html/` with the incoming public directory, including its hidden rewrite file. Preserve the live sibling `storage/`, the database and the host's TLS, PHP and access-control settings.
3. Once the file transfer is complete, allow operator access. If the previous package predates protected credential storage, complete the [credential storage upgrade](#protect-existing-credentials) before signing in. Sign in as a verified administrator and open `/update`. If the page asks you to sign in again, use its **Sign in again** button. Submit the update within five minutes of entering your password and confirm that you saved a backup.
4. Check that Causette reopens and that the database manager shows the incoming version in `database_version`. Confirm sign-in, a sample conversation and an attachment download before restoring normal access.

The update records one version change in the database. If the browser disconnects, reopen the site and check the marker. The old marker means the authorized update can be attempted again. The incoming marker means the update already finished. If the version is unexpected, keep the site restricted and identify the matching package and database before proceeding.

## Upgrade the reaction catalog to 1.0.4

The 1.0.4 updater accepts a private JSON installation at 1.0.1, 1.0.2 or 1.0.3. It expands the check constraints on `message_reactions` and `post_reactions`. Existing rows, their original codes and the one-reaction-per-user rule are preserved.

1. Restrict the site to the operator, verify the matching database and storage backup, and check the available database space and hosting quota. Allow space for the database engine to copy both reaction tables if needed.
2. In the database administration interface, ensure the site's database account can run `ALTER` on those two tables. Keep the existing permissions. Grant only what the migration needs through the hosting panel or database manager.
3. Upload the package and use the protected `/update` form over HTTPS after signing in again as a verified administrator. Confirm the matching backup as on a code-only update. The page shows how many of the two tables have been updated.
4. If interrupted, keep the site restricted and reopen the same page. The updater checks which table changes committed before retrying and opens the site after both checks are in place and the database marker reaches 1.0.4. Verify old reactions and add and remove a new reaction on a message and a post before revoking any temporary `ALTER` grant and reopening traffic.

Use the original files and matching database backup together for rollback. A database with newer reaction codes may not fit the older constraints.

## Update to 1.0.5

The 1.0.5 updater accepts a private JSON installation at versions 1.0.1 through 1.0.4. It performs the reaction migration when starting below 1.0.4. It creates `api_rate_limits` inside the protected update when the table is missing. If the table already exists, the updater checks it and preserves its rows. The scoped database user needs `CREATE` only when the table must be created. Keep the site restricted and reopen the update screen if the operation stops.

## Update to 1.0.6

The protected updater accepts private JSON installations at versions 1.0.1 through 1.0.5. It appends the three group request notification types and preserves existing notification rows. It also applies the reaction and API rate-limit table changes when needed. If the update stops, keep the site restricted and reopen the update screen. Completed schema changes are checked before the version advances.

## Update to Causette 1.2.0

Back up the database, application files and private storage, and restrict site access during the update. Extract the ZIP in the installation directory that contains `public_html/` and `storage/`. Keep those two directory paths and the live private contents in place.

Sign in as a verified administrator and use the protected `/update` form. The database user needs `ALTER` on `message_reactions` and `post_reactions` to widen the emoji code columns. The updater retains existing reactions and advances the database marker to 1.0.7 after both tables are ready. If interrupted, reopen the form with the same package in place. Older schemas also need the permissions for any previous migration that has yet to run.

Before reopening the site, verify that an old reaction is still displayed and a new compound emoji can be added to a message and a post. Enable MP4 and WebM in the admin attachment settings on installations that restrict uploads to images. Upload a short video and play it from the conversation as another authorized member.

Grant the scoped database user `ALTER` on `notifications`, and on `message_reactions` and `post_reactions` when upgrading from before 1.0.4. MySQL additionally requires `CREATE` and `INSERT` on each altered table. Ensure `CREATE` is available for `api_rate_limits` if that table is missing. [MySQL ALTER TABLE](https://dev.mysql.com/doc/refman/8.4/en/alter-table.html) · [MariaDB ALTER TABLE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table)

## Protect existing credentials

Run this data-only operation on a compatible Causette database with API keys or integration settings from an earlier package. It also initializes protected storage on an existing installation without SMTP or VAPID credentials. Use the hosting panel to restrict traffic, allow active requests to finish, and save a matching database and private storage backup before uploading the package.

1. In private storage, create `storage/credential-upgrade.json` with mode `0600`. Put a freshly generated 64-character hexadecimal operator token in the JSON field `operator_token`, as in the example below. Use a password manager so the token is unique and unpredictable.

       {"operator_token": "REPLACE_WITH_A_GENERATED_OPERATOR_TOKEN"}

2. Open `/credential-upgrade` at the configured HTTPS address. Enter the operator token and confirm the backup. Submit a batch. The operation can run before the first application sign-in, including on an installation that uses SMTP for its second factor.
3. If the page reports more work or a recoverable interruption, reopen it and submit another batch with the same token. A completed batch is transactional. Retrying preserves the key file, current bot scopes and protected credentials.
4. When the page reports completion, make a protected backup of the upgraded database and the new key file. Remove the operator file in the file manager, confirm that `/credential-upgrade` is closed, and verify application sign-in, a test email, and any bot integration before reopening traffic.

Existing API keys retain their values and scope after conversion. The interface displays the full value only when a key is created. To replace one, create a key with the required restrictions, update the client, and expire the old key. Request a new reset link if the original link was issued before the upgrade. New reset links remain single-use.

### Rotate the integration encryption key

Keep a verified matching backup and restrict traffic as above. In `storage/integration-keys.json`, add a newly generated 64-character hexadecimal key under a fresh key ID such as `k2` and set `active` to that ID. Keep the prior key in `keys` until the credential storage operation completes and both integration settings work. Run `/credential-upgrade`, then back up the new pair of database and key file. Remove the retired key from the live key file and remove the operator file. Keep the original key only with backups that still require it.

## Restore a backup to a disposable site

1. Prepare a separate, access-restricted HTTPS test site and an empty, dedicated database and account through the hosting panel. Keep the test site's document root separate from the original.
2. Import the SQL export through the database manager. Use the file manager to restore the matching application files and private storage. Set the document root to the restored `public_html/` and confirm that the sibling `storage/` has mode `0700`. Set `storage/causette.json` to `0600`.
3. Edit only the restored private configuration's URL and database connection values for the disposable site. Read the copied database name back before exposing the test site. If Causette reports an installation identity mismatch, check that the configuration and SQL export came from the same installation. Older configurations without an installation identifier still require a manual check.
4. Compare the database marker and representative row counts with the source backup. Sign in with a synthetic test account. Check conversation history, attachment contents and administrator access. If the fixture uses TOTP, verify a login with the enrolled synthetic factor. Confirm that a harmless probe file in private storage is inaccessible through HTTPS.

Use the same backup point for the database, files and private configuration when recovering a site. Restoring that set also restores the recorded version. A schema-changing upgrade or an import from an older format needs its own migration and recovery procedure.

## Verify attachment access and account recovery

Administrators can enable XLS and XLSX under **Settings → Administration → Attachments**. Both formats are downloaded using their original filenames. The upload checks the detected [registered media type](https://www.iana.org/assignments/media-types) for the selected extension. Existing file-type settings are preserved.

On the disposable site, download a private group's attachment as a member. Remove the test account from the group and verify that the same URL no longer grants access. A signed-in non-member can still read attachments linked to messages in a public group. Causette sends `Cache-Control: private, no-store` with attachment responses. Copies previously saved or cached on a device may remain accessible on that device.

Attachment storage defaults to 256 MiB per account, 512 MiB per conversation and 1 GiB per site. Each account can have 12 pending uploads. Each recorded attachment uses at least 4 KiB of its quotas, including attachments that share a file. Files awaiting expiry cleanup still count. The host's disk usage includes any untracked files left by earlier versions or bulk maintenance. A busy storage operation returns a retry message.

On a site with a different storage allowance, set positive integers in the `settings` table using the database manager. The keys are `attachments_user_quota_bytes`, `attachments_chat_quota_bytes`, `attachments_site_quota_bytes` and `attachments_pending_limit` (up to 100). Choose limits within the host's disk allowance and leave room for backups and uploads in progress. Lower quotas leave existing attachments accessible and pause further uploads until space is available.

Images have an eight-megapixel ceiling. Causette also checks the estimated decoder workload against a fixed cap and the PHP process's remaining memory before opening the image with GD. The actual encoded size counts toward the normal file and storage limits. The upload reserves its row before writing to private storage and publishes only after the full file has been written.

To check a voluntary password change, sign in to a synthetic account in two browser profiles. In one profile, change the password in **Settings** by entering the current password. That profile should stay signed in with a renewed session. The other profile should be signed out. Previous **Remember me** cookies are invalidated on all devices, including the browser used for the change. Existing API keys stay valid.

To check password recovery, sign in again in both profiles and enable **Remember me** in one. Complete the reset from a third profile. Both previous sessions and the persistent sign-in cookie should be rejected. Sign in again with the new password. Password changes and resets also clear recorded trusted locations and invalidate outstanding recovery and login challenges for that account.

When resetting a password, select **Revoke all API keys, including bot keys** if a key may have been exposed. Leaving the box unchecked preserves existing integrations. Selecting it expires all active keys owned by the account as part of the reset. Create replacement keys after signing in again and update any affected integration.

## Choose the mail transport

In **Settings → Administration → Mail**, choose **Custom SMTP server** or **PHP default (hosting provider)**. Existing installations use their current SMTP settings until an administrator selects PHP mail.

- **Custom SMTP server** needs the host, port, username, password and sender. Port 465 uses implicit TLS. Other configured ports use STARTTLS. Leave the password field empty to keep the stored credential.
- **PHP default** hands messages to the host's configured [`mail()` transport](https://www.php.net/manual/en/function.mail.php). Enter an address the hosting provider permits as a sender. Causette keeps the saved SMTP settings available for switching back.

The test button sends to the address on the administrator’s account. Account emails and email second factors use the selected transport. Verify receipt in the mailbox. A delivery failure prevents completion of a sign-in that requires an email code.

## Verify second-factor sign-in

Use two browser profiles on the disposable HTTPS site. Sign in with an account that has an authenticator app. A second profile on the same network must still ask for a code. Regular accounts can enable **Allow manually trusted browsers** in Settings and then choose **Trust this browser for 30 days** after entering a factor. The trust period starts with that verification and expires on the server. Previously issued trust keeps its original expiry. Administrators with a configured factor must complete it for every new sign-in.

In Settings, **Revoke all trusted browsers** clears the ability to skip a future second-factor check. Switching to **Every sign-in**, removing the authenticator, or changing or resetting the password also clears browser trust. Use the session controls if you need to sign out an already signed-in browser.

Start a new sign-in or request a new email code after an expired or exhausted challenge. Each verification flow allows one active challenge per account. A replacement invalidates the previous challenge. Challenges expire after ten minutes and close after five unsuccessful attempts, with a short delay after each failure. Each flow also limits starts and checks across browsers. Codes sent by email can be used only in the browser that requested them. Authenticator time steps and emergency recovery codes can each be accepted once.

When updating a legacy installation, ask anyone with a pending verification or authenticator enrollment to restart it. Prior IP trust records have no effect. The security state uses the existing database schema. Preserve the private configuration in backup and restore so email code verification uses the matching deployment key.

## Verify recovery links and HTTPS after a restore

Check that the private site's `url` matches its public HTTPS address exactly. In an installed legacy PHP configuration, supply a literal `$CONFIG_APP_URL` and the optional `$CONFIG_APP_SUBFOLDER`, or use the equivalent hosting environment values. Recovery mail uses this configured public address.

With a synthetic account, request a recovery email using its username, then repeat using its email address. Both go to the address recorded for the account and share the same rate limit. Open the latest link and confirm the host, port and path before submitting the form. The reset page loads its stylesheet from the same site and forbids scripts. Keep recovery-link queries out of exported logs and restrict access to the hosting logs that record them.

Check that a plain HTTP page request redirects to the configured HTTPS address without its query string. Plain HTTP form submissions are refused, so enter credentials on the HTTPS page. For a proxied site, confirm that a forwarded scheme from an unapproved client has no effect and that the approved proxy serves the HTTPS page without redirecting it again. Verify `Secure`, `HttpOnly` and the expected path on the session cookies.

## Verify browser session expiry and logout

An interactive session expires after 30 minutes without an authenticated request or 12 hours after sign-in or restoration. Active sessions renew the PHP session ID and its database binding on the first request after 15 minutes. This renewal preserves the session's original deadline. Login, restoration and a deliberate password change issue fresh credentials.

**Remember me** permits restoration for at most 30 days after sign-in and expires after seven days without authenticated activity. Each successful restoration replaces both the prior session binding and the remembered credential. The new remembered credential keeps the original absolute expiry. The database stores digests and expiry metadata. After upgrading from the previous token format, sign in again to create new credentials.

To rehearse revocation, sign in to the disposable HTTPS site with **Remember me** in two browsers and open **Settings → Sessions**. Choose **Exit session** for the other browser. Confirm that its entry disappears from the list and that the other browser needs to sign in again. Choose **Sign out** on the current session and verify that reopening a protected page also requires sign-in. A still-valid remembered browser remains listed even after its interactive session expires. A remembered login session and a browser trusted for MFA are separate controls.

## Configure and diagnose Web Push

In the administration settings, open **Push Notifications**, configure the matching P-256 VAPID public key and private PEM, and select the browser services this site can use. Clearing all providers switches off Web Push. Disabling a provider pauses its existing subscriptions. Leave optional push unconfigured if your hosting plan cannot make direct HTTPS connections. Existing in-app notifications and polling continue independently.

Causette recognizes Chromium at `fcm.googleapis.com`, Firefox at `updates.push.services.mozilla.com`, Safari at `web.push.apple.com`, and Edge WNS at one-label subdomains of `notify.windows.com`. It permits their subscription URL forms on port 443 and resolves the provider again for every attempt. It rejects any answer set with a non-public address and pins one validated address for cURL. TLS hostname and certificate verification remain enabled. Requests bypass proxies and redirects, and send an empty wake-up body with VAPID. The service worker retrieves the notification from Causette.

Each installation uses its own service worker scope. For a mounted site, serve `service-worker.js`, the notification endpoint and local notification icons under the same public mount. Notification links open inside that scope. Turn on Web Push from the mounted site after moving it to a new public path so the browser registers a subscription for the new scope.

Each account can register four devices. A permanently failed device can free a slot when a replacement registers. Existing registrations remain associated with their accounts. To replace an expired browser subscription or change the configured VAPID key, disable and re-enable push in that browser. Automatic registration refreshes preserve the retry cooldown.

Push is best effort. Each request can attempt four deliveries across all recipients and the site can reserve 60 attempts per minute. The HTTP requests share a 2.5-second budget after dispatch begins, with a maximum of 1.2 seconds per connection attempt including its response. DNS resolution uses the hosting resolver and its timeout. Transient errors delay the next attempt for that device by at least 60 seconds. After three consecutive failures, the subscription needs replacement. Notifications still appear in Causette when these limits are reached.

On a disposable installation, enable push in a browser and send a message from a second account. If a delivery is missed, the hosting database manager can show the last outcome in `push_subscriptions.last_http_status` and `last_error`. New delivery errors use fixed codes. Do not export endpoint columns or historical delivery logs in a diagnostic report. They may contain private subscription URLs. The update uses the existing database schema.
