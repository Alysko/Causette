# Public identity and upstream references

Use Causette for the product name in interface text, mail, notifications, the TOTP issuer and release materials. The README credits the upstream project. Preserve its attribution, license notices and the historical audit. User-authored content and existing records retain their original text.

The Causette mark is a green C with a speech bubble tail. The sign-in page and desktop and mobile navigation display the local 128-pixel `public_html/assets/img/logo.png` next to the product name. Browser tabs and Web Push use the 64-pixel transparent `public_html/assets/img/favicon.png`. Both images were generated from the same original Causette artwork.

The source distribution test allows the following technical references to the upstream name.

| Location | Reason |
| --- | --- |
| `README.md` | Upstream attribution and source link. |
| `docs/import.md`, `public_html/app/install/PrologueImport.php`, `public_html/app/controllers/InstallController.php`, `public_html/app/locales/{en,fr}/install.php` | Supported import source, source-specific instructions, and the exact original defaults that the importer replaces. |
| `public_html/app/core/LegacySite.php` | Request-origin helper names used by existing executable configurations. |
| `public_html/app/controllers/AdminUserController.php`, `public_html/app/models/User.php` | Reserved legacy username and synthetic email identifiers attached to retained messages from deleted accounts. Stable identifiers let the application recognise and reuse those records. |
| `public_html/assets/js/app.js` | Internal navigation event names for existing browser scripts. |
| `public_html/assets/js/call.js`, `public_html/assets/js/chat.js` | Saved device, volume, call and chat preferences in browsers. |

Automatic update discovery is disabled until Causette has a trusted release feed. Existing administrators can still access database maintenance when the installed package requires it. The maintenance path requires separate security and browser acceptance testing.
