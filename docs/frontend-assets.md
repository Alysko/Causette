# Frontend assets

The main and standalone layouts load a local Tailwind stylesheet. The main layout also loads Font Awesome from local CSS and web fonts. These files are part of the uploadable package and require no build tools on the host.

Run `npm ci --ignore-scripts` and `npm run build` from `frontend/` in a development environment after changing markup or utility classes. The lockfile pins the build dependencies. The build scans application views, models, controllers, core classes and browser JavaScript. Keep complete utility class names visible to Tailwind when generating styles in code.

- Tailwind CSS 3.4.17 is distributed under the MIT license. The compiled stylesheet is `public_html/assets/css/causette.css` and the upstream notice is `tailwind-license.txt` in the same directory.
- The core, solid and regular Font Awesome Free 6.7.2 styles and fonts come from the official npm package and are included in `public_html/assets/css/fontawesome.min.css` and `public_html/assets/webfonts/`. The upstream license file is `fontawesome-license.txt` in the CSS directory. It describes the licenses for icons, fonts and code.
- Message and post reactions use Microsoft's [Fluent Emoji 3D](https://github.com/microsoft/fluentui-emoji/tree/1ffb34c752ecf5d402f04cfb4b392c77f57c54bc) PNGs. The MIT notice, source paths and Git blob IDs are recorded in `public_html/assets/img/reactions/credits.json`.

The package browser checks block third-party requests on the login and dashboard. They also exercise the enforced policy, client-side navigation and both CAPTCHA integrations with fixture scripts and frames.

Application HTML uses a fresh per-response nonce for trusted scripts and loads styles from local stylesheets. Serve these responses with their original CSP header and keep them out of shared caches. Use CSS classes for fixed styles and set individual CSS properties for dynamic values. Bind events in JavaScript. Page-specific inline scripts that must run during client-side navigation need the response nonce and the `data-page-script` marker. The navigation code checks the nonce against the fetched policy before running the script in the current document.

The selected CAPTCHA provider is loaded only on the sign-in, registration and password recovery request forms when both keys are configured. Turnstile uses `challenges.cloudflare.com` for scripts and frames. reCAPTCHA v2 uses the documented Google and gstatic reCAPTCHA paths for scripts, frames and connections. Token verification is sent from PHP to the selected provider's Siteverify endpoint. Configure the keys in the administration security panel. [Turnstile CSP documentation](https://developers.cloudflare.com/turnstile/reference/content-security-policy/), [reCAPTCHA CSP documentation](https://developers.google.com/recaptcha/docs/faq/#im_using_content-security-policy_csp_on_my_website._how_can_i_configure_it_to_work_with_recaptcha)
