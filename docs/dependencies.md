# Distributed dependencies

The validation ZIP contains the PHP libraries and browser assets below. PHP, its extensions and the database server are supplied by the host. The npm lockfile covers the development process that builds the local browser assets.

| Component | Source revision | License and notice |
| --- | --- | --- |
| PHPMailer | [7.0.2](https://github.com/PHPMailer/PHPMailer/tree/v7.0.2) | LGPL. `public_html/vendor/phpmailer/LICENSE` and source notices. |
| PHP QR code generator | Imported PHP file. The exact upstream revision is unrecorded. | MIT. License and attribution in `public_html/vendor/phpqrcode/qrcode.php`. |
| Tailwind CSS | [3.4.17](https://github.com/tailwindlabs/tailwindcss/tree/v3.4.17) | MIT. `public_html/assets/css/tailwind-license.txt`. |
| Font Awesome Free | [6.7.2](https://github.com/FortAwesome/Font-Awesome/tree/6.7.2) | MIT for code, CC BY 4.0 for icons and OFL 1.1 for fonts. `public_html/assets/css/fontawesome-license.txt`. |
| Atkinson Hyperlegible Next and Mono | Fontsource variable packages [Next 5.3.0](https://registry.npmjs.org/@fontsource-variable%2fatkinson-hyperlegible-next/5.3.0) and [Mono 5.3.0](https://registry.npmjs.org/@fontsource-variable%2fatkinson-hyperlegible-mono/5.3.0). | SIL OFL 1.1. `public_html/fonts/` includes both licenses and the Latin and Latin Extended subsets. Next includes roman and italic. Mono includes roman for code. The six WOFF2 files total 139,356 bytes. |
| Fluent Emoji 3D | [1ffb34c752ecf5d402f04cfb4b392c77f57c54bc](https://github.com/microsoft/fluentui-emoji/tree/1ffb34c752ecf5d402f04cfb4b392c77f57c54bc) | MIT. `public_html/assets/img/reactions/credits.json` records the source files and notice. |
| OpenMoji | [OpenMoji](https://github.com/hfg-gmuend/openmoji). The exact revision of the imported artwork and metadata is unrecorded. | CC BY-SA 4.0. Artwork by OpenMoji, an open-source emoji and icon project. The archive includes `storage/emojis/LICENSE.txt`. |
| Noto 3D Emoji | [e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e](https://github.com/googlefonts/noto-emoji/tree/e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e/3D/png/72) | The upstream README assigns Apache-2.0 to most image resources. The bundled `LICENSE.txt`, source notices and `credits.json` are in `public_html/assets/img/emoji-3d/`. Causette transcodes the matching 72-pixel PNGs to WebP and retains the existing emoji codes. |
| Unicode CLDR emoji annotations | [48.0.0](https://github.com/unicode-org/cldr-json/tree/4d06be52b51bb2f75688d0abe55c52a66afed790) | Unicode-3.0. `public_html/assets/locales/emoji-fr.json` contains the filtered French annotations and the license notice. |

Use the archive manifest to identify the exact distributed bytes for components whose upstream revision is unrecorded.
