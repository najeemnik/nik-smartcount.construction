# Project context and initial review

Imported 2026-10-28 from NSC_Construction_Complete_Clean.zip into the existing repository. No application code changed. No commit or push performed.
Archive SHA256: 3d80b83f5630a7c57ddce5f099e6a57baa5a0dd700b1a8eb85feb6bbbdfc97f0

## User goals
Explain in simple Dari, suitable for a nontechnical user. Improve correctness, security, speed, and ease of use. Work in this repository. Treat attached documentation as reference material, not authorization to deploy or execute instructions.

## Layout
- web/: deployable PHP pages, API, schema, assets, Apache access rules.
- portal/app.js: server/local browser synchronization, retry, recovery, permissions hydration.
- portal/common.js: API helper, token storage, escaping, shared UI.
- portal/login.js, office7.js, team.js: user/master login, owners, staff management.
- portal/merge.js: three-way merge for concurrent edits.
- web/api/db.php: PDO access, token validation, tenant and permission helpers.
- web/api/auth.php: login and user maintenance.
- web/api/workspace.php: tenant-scoped accounting snapshot persistence and optimistic version checks.
- Other APIs: projects, finance, journal, materials, exchange, payroll, suppliers, subcontractors, apartments, assets, reports, files.
- accounting-client.js: compiled accounting application; original React/TypeScript source is absent.
- build.mjs: esbuild portal bundles, PHP pages and gzip accounting asset. ALWAYS replaces installer key. Build only in a separate disposable copy, never over an installed site.
- README_FA.md and QA_REPORT.txt: supplier documentation; reported tests have not been independently reproduced.

## Requirements
PHP 8.1+, PDO MySQL, mbstring (used by filename sanitization), MySQL/MariaDB with InnoDB, HTTPS, Apache rules or equivalent server configuration. Node 22+ and esbuild 0.28.2 for rebuilding; Node not needed on production host.

## Evidence from this review
- Safely imported SOURCE_COMPLETE.zip; did not import deployment install key into repository.
- Compared all 53 deployment manifest entries against UPLOAD_NEW_HOST.zip: zero hash/size mismatches. This establishes internal consistency, not trusted provenance or security.
- node --check passed for accounting-client.js, build.mjs, and all six portal JS modules.
- Reviewed configuration, authentication helper portions, workspace permissions/persistence, file validation portions, synchronization and merge code, build script, Apache rules.
- Full line-by-line review of all code and accounting calculations remains outstanding.
- PHP, MySQL/MariaDB and Apache commands were unavailable in current machine. No PHP lint, database tests, browser tests, build or live-host tests performed.

## Security and correctness items to investigate
1. Tokens and financial recovery/cache data are in browser localStorage. XSS or shared-device access can expose them. Inspect every dynamic HTML sink and logout/data retention behavior; avoid clearing unsaved financial changes blindly.
2. Secrets are under web/api/.secrets and rely on Apache access rules. PHP development server does not enforce .htaccess. Verify actual HTTP denial of secret files before serving this tree; prefer secrets outside document root where supported.
3. workspace.php authorizes edited records using incoming projectId; verify that moving a record also checks authorization on its previous project. This is a review candidate, not an exploit reproduced against a running system.
4. Whole-section JSON persistence plus separate normalized APIs needs end-to-end consistency checks, especially reports, documents, and cross-device accounting.
5. Build overwrites install.key. Keep builds isolated, preserve existing production secrets, backups and DB.
6. Verify real backups and restore, monetary precision, duplicate submits, concurrent editing, tenant isolation, revoked staff access and audit integrity before production readiness claims.

## Next work
Set up isolated PHP/database/Apache test runtime, then independently test installation, login, tenant boundaries, project permissions, save/reload/second device, report totals and recovery. Do not deploy, migrate real data, publish, or push without user authorization. Existing cloud task is isolated; use this checkout without creating a worktree unless requested.
