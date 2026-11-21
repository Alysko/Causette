# Build a local validation package

Run the builder with Git and PowerShell 7 on the development workstation. Choose a new ZIP path in an existing local directory.

```powershell
pwsh -NoProfile -File scripts/package-validation.ps1 -OutputPath Causette-1.0.1-validation.zip
```

The builder reads the current working-tree bytes under the approved distribution paths, including new files visible to Git. Inspect the working tree before building. The source revision in the manifest identifies the base commit and can differ from the packaged contents.

The archive contains `public_html/`, sibling `storage/`, the license and operator documentation. It includes the bundled OpenMoji files and placeholders for private writable directories. The distribution allowlist excludes local configuration, database exports, logs, user uploads, tests and project journals. Update the allowlist when adding a runtime directory or file type. Review new public assets for embedded credentials before packaging.

`MANIFEST.json` records the application version, base commit, and SHA-256 hash and byte length of each payload entry. The adjacent `.sha256` file covers the ZIP itself. Compare its digest with `Get-FileHash -Algorithm SHA256 -LiteralPath <archive>` before extracting. The archive is for local validation. Publication requires the release gates and provenance checks.

Run the builder boundary test with `pwsh -NoProfile -File tests/package-validation.test.ps1`. The test creates and removes a synthetic Git checkout in the temporary directory.
