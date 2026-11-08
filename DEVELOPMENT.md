# Development handoff

## Repository layout

- `workspace.php`: application entry point, bundled base styles, server-state synchronization and versioned browser asset URLs.
- `api/*.php`: server authentication, permission enforcement, company/project boundaries, accounting APIs and persistent workspace snapshots.
- `nsc-accounting-r3.js.gz`: production JavaScript ES module served through `nsc-runtime.php`.
- `client/nsc-accounting-r3.js`: exact uncompressed editable copy of that compiled module. It is **compiled JavaScript**, not original TypeScript and not a replacement modular frontend project.
- `tools/client-bundle.mjs`: dependency-free extraction and syntax-checked repacking using Node 18+.
- `about-ui.js`, `about-profile.js`, `navigation-ui.css`: information dialog, public developer/contact details, compact navigation styling and fine controls.
- `workspace-design.*`, `forms-ui.*`, `forms-camera.js`, `workspace-cache.js`: presentation, form normalization, camera/scanning and browser-cache fallback layers.
- `assets/brand-full.webp`: application logo. `assets/developer-nik.png`: original uploaded developer PNG; preserve it without recoloring/cropping.
- `.checks/`: guarded synthetic fixtures, browser/API checks and historical compiled-client transformation scripts. Do not rerun historical patches blindly; they expect particular earlier bundles and may reject or duplicate changes.

## Edit the client

1. For readable standalone modules/CSS, edit their source files directly.
2. For changes inside the compiled React app, edit `client/nsc-accounting-r3.js` with care. Identify the exact function; preserve unrelated logic and authorization.
3. Run `node tools/client-bundle.mjs pack`. It syntax-checks before replacing the production gzip.
4. Keep `client/nsc-accounting-r3.js` and `nsc-accounting-r3.js.gz` synchronized. If an existing adapter changes the gzip directly, run `node tools/client-bundle.mjs extract` afterward.
5. Bump the appropriate asset URL in `workspace.php` so hosted browsers receive the change. The `.gz` filename must remain compressed on the server; do not extract or rename it during hosting installation.
6. Run scenario-based checks for the affected feature. Parsing is not a functional test.

Do not invent a `src/` frontend or a build system and claim it is the missing original. If original frontend source is supplied later, port the verified changes to that source and use its normal build.

## Local/hosting setup

The runtime is PHP with Apache `.htaccess` support and MySQL/MariaDB. Enable PDO MySQL, fileinfo and mbstring; configure HTTPS for browser camera and secure sessions. The isolated checks in this work used PHP 8.4 and MariaDB 10.11.

- For an existing installation, preserve `api/.secrets/database.php`, email settings, signing key, installation marker and uploaded data. These are intentionally ignored by Git.
- For a fresh local instance, use a dedicated empty database and follow the existing `install.php` setup flow. See `api/.secrets/database.example.php`, `api/.secrets/email.example.php` and `api/.secrets/create-install-key.php`. Configuration may also use the documented environment variables in `api/config.php`.
- Never run fixture seeders against a real company database. Test seeders require the isolated `nsc_audit` name; keep that guard.
- `.checks/README.md` explains the fake-mail sink and external credentials required for authentication/recovery tests. Browser tests need Playwright Core and Chromium; current helper defaults point to the former `/workspace/nsc-test` runtime. That runtime and its account JSON are not shipped here. Adapt local paths without committing credentials or weakening database guards.
- Do not use plain PHP's development server as proof Apache deny rules work.

## Verification and limits

Past reviews are evidence for the versions/scenarios they name, not a blanket guarantee. The latest menu/About checks covered Dari/English, mobile/desktop widths, 18 theme/mode combinations, accessible controls, original logos and unchanged financial records. Financial invariants covered 283 cases. Physical iPhone DuckDuckGo, live external integrations and every print detail were not certified.

Useful commands after preparing the external test dependencies:

```sh
node --check about-ui.js
node --check about-profile.js
node tools/client-bundle.mjs pack
# With the guarded local test runtime and synthetic fixture accounts configured:
node .checks/full-financial-invariants.cjs
node .checks/browser-compact-navigation.cjs
node .checks/browser-about-theme.cjs
node .checks/browser-fine-controls.cjs
```

## Deployment

This is a complete code checkout; update ZIPs from the chat were replacement-file subsets. Preserve private settings/data, back up both files and database, deploy through Apache with the included access rules, refresh cached assets and verify a synthetic project workflow. Do not import schema over a live database as an ordinary UI update.
