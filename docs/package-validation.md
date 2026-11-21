# Check a validation package

Build the ZIP on a development workstation or trusted CI. Use the approved distribution paths and inspect the working tree first. The source revision in the manifest identifies the base commit and can differ from the packaged contents.

The archive contains `public_html/`, sibling `storage/`, the license and operator documentation. It includes the bundled OpenMoji files and placeholders for private writable directories. The distribution allowlist excludes local configuration, database exports, logs, user uploads, tests and project journals. Update the allowlist when adding a runtime directory or file type. Review new public assets for embedded credentials before packaging.

`MANIFEST.json` records the application version, base commit, and SHA-256 hash and byte length of each payload entry. The adjacent `.sha256` file covers the ZIP itself. Verify that checksum before extracting. Test the archive on a separate site with synthetic data before publishing it.
