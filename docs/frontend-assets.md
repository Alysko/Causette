# Frontend assets

The main and standalone layouts load a local Tailwind stylesheet. The main layout also loads Font Awesome from local CSS and web fonts. These files are part of the uploadable package and require no build tools on the host.

Run `npm ci --ignore-scripts` and `npm run build` from `frontend/` in a development environment after changing markup or utility classes. The lockfile pins the build dependencies. The build scans application views, models, controllers, core classes and browser JavaScript. Keep complete utility class names visible to Tailwind when generating styles in code.

- Tailwind CSS 3.4.17 is distributed under the MIT license. The compiled stylesheet is `public_html/assets/css/causette.css` and the upstream notice is `tailwind-license.txt` in the same directory.
- Font Awesome Free 6.7.2 is copied byte for byte from the official npm package into `public_html/assets/css/fontawesome.min.css` and `public_html/assets/webfonts/`. The upstream license file is `fontawesome-license.txt` in the CSS directory. It describes the licenses for icons, fonts and code.

The Docker package browser check with `-LocalAssets` blocks all third-party requests and verifies the login and dashboard. The application still has optional CAPTCHA providers and uses inline scripts and styles. Those are separate content security policy work.
