# Check a validation package

Use three version components in `public_html/version.php`, ZIP names, manifests and release tags. For example, the 1.1 release is recorded as `1.1.0`.

- Increment the patch version for bug fixes and small refinements, such as `1.1.1` and `1.1.2`.
- Increment the minor version for new features or substantial improvements, such as `1.2.0` and `1.3.0`.
- Increment the major version for broad changes, such as `2.0.0` and `3.0.0`.

Classify each release by its effect on what users can do. Small changes to an existing feature's settings, layout, animations or colors count as patch changes. A batch of these adjustments is still a patch. Use a minor version for a distinct capability or a substantial improvement to an existing workflow.

Choose the database version separately. Increment it when the release requires a newer database schema or data migration and record the supported source markers in `compatible_database_versions`.

Build the ZIP on a development workstation or trusted CI with Git and PowerShell 7.4 or later. Review the modified and untracked source files first. The tool packages the working tree, and the manifest records its base commit.

```powershell
pwsh -NoProfile -File scripts/package-validation.ps1 -OutputPath output/Causette-validation.zip
pwsh -NoProfile -File scripts/package-validation.ps1 -VerifyPath output/Causette-validation.zip
```

Create the output directory before building. Verification needs the ZIP and its adjacent `.sha256` file. When you have an expected digest from a trusted channel, pass it with `-ExpectedSha256`.

The archive contains `public_html/`, sibling `storage/`, the license and operator documentation. It includes the bundled OpenMoji files and placeholders for private writable directories. The distribution allowlist excludes local configuration, database exports, logs, user uploads, tests and project journals. Update the allowlist when adding a runtime directory or file type. Review new public assets for embedded credentials before packaging. See [distributed dependencies](dependencies.md) for the component sources and license notices.

All ZIP entries use the actual build time at ZIP's two-second precision. When a file manager preserves those times on extraction, an FTP client set to overwrite newer files can replace files from an older build. To reproduce an existing ZIP from the same source and toolchain, pass its manifest's `builtAt` value as `-BuildTime`. If PHP still runs old scripts after extraction, check the host's OPcache settings and use its supported cache refresh for the site.

`MANIFEST.json` records the application version, base commit, build time, build tool, SHA-256 hash and byte length of each payload entry. `SBOM.cdx.json` inventories the included components. The verification command checks the ZIP digest and rejects missing, duplicate, modified or disallowed files before extraction. The digest establishes integrity against its supplied reference. Publisher identity requires separate signing or attestation evidence. Test the verified archive on a separate site with synthetic data before publishing it.
