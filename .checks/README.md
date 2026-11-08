# Isolated audit checks

Never seed fixtures in a real company database. fixtures.php refuses any DB_NAME except nsc_audit. Tests create/modify accounts and transactions intentionally. The scripts contain no passwords: supply an external accounts JSON through NSC_TEST_ACCOUNTS_FILE. Its first entry is the test owner (username preview), second entry the master; entries use username/password fields. Docker fixture setup reads that JSON at /run/nsc-accounts.json. Existing local test credentials are outside the checkout at /workspace/nsc-preview/accounts.json.

Prepared local instance: nsc-audit-web (Apache/PHP 8.3 with PDO MySQL, fileinfo, mbstring), shared test MariaDB container nsc-preview-db, isolated database nsc_audit. Source copy is /workspace/nsc-audit/site; source edits require syncing to this copy. Docker access may require the environment's approved execution context. The browser scripts and test runtime files remain at /workspace/nsc-audit outside the deployment checkout.

After syncing corrected API files and applying admin.php?action=upgrade as test master, run:

    docker cp .checks/fixtures.php nsc-audit-web:/run/fixtures.php
    docker exec nsc-audit-web php /run/fixtures.php
    python3 .checks/regression.py
    python3 .checks/extended.py
    python3 .checks/financial.py
    docker cp .checks/token-tests.php nsc-audit-web:/run/token-tests.php
    docker exec nsc-audit-web php /run/token-tests.php

Fixtures restore the employee password changed by regression.py. Reseed before repeating the sequence. Extended tests update permissions and write additional financial data. NSC_TEST_BASE_URL defaults to http://127.0.0.1:8081; never point it at production. Apache denies the entire .checks directory. Do not upload test files to a production host unnecessarily.

Second-pass security cases (after the previous suites, reseed the isolated users):

    docker exec nsc-audit-web php /run/fixtures.php
    docker cp .checks/deep-fixtures.php nsc-audit-web:/run/deep-fixtures.php
    docker exec nsc-audit-web php /run/deep-fixtures.php
    python3 .checks/deep.py

The 24 deep checks cover adversarial rate IDs, legacy primary-key collisions, project-specific rate memory, role/service restrictions, unclaimed contractors, conflicting password fields, draft access revocation, and account transfers. The fixture guards DB_NAME=nsc_audit and contains only synthetic records. No live-site penetration test was performed.

Granular permissions and email verification:

    python3 .checks/permission-matrix.py
    node .checks/browser-permissions.cjs

Email tests REQUIRE the local fake sendmail sink (they never send actual email). Copy email-fixtures.php to /run/email-fixtures.php in nsc-audit-web. It refuses any other database. Configure the audit container's sendmail_path to a local executable that writes stdin into /tmp/nsc-test-mailbox, mode 0600, and returns failure when /tmp/nsc-mail-fail exists. Never install this sink on a production host. The tests temporarily toggle the audit site's ignored api/.secrets/email.php and restore disabled MFA for the legacy test suites. Run suites sequentially, because they intentionally mutate the same test configuration and accounts.

    python3 .checks/email-security.py
    node .checks/browser-email.cjs

The new default on a real installation is email MFA enabled, with security@nik-smartcount.com as sender. Before deployment, follow EMAIL_AND_PERMISSIONS_FA.md, especially correcting every login email before replacing the code. Tests disable MFA only in the isolated audit copy for the old suites. Never copy that private test override to production.

Browser checks use Playwright Core and Chromium from the external test runtime. The compiled-client adapter patch-client-permissions.cjs uses Node and acorn 8.15.0 (build time only; PHP hosting needs neither Node nor npm). It starts from the previous preserved upstream gzip bundle and refuses duplicate application or mismatched helper structure. Keep it for review/rebuilding; it is not a substitute for the missing original React/TypeScript source. workspace-permissions.js and login-email.js are readable production source files.

Preserved upstream client baseline for this adapter: commit bf6d83f, file nsc-accounting-r3.js.gz. The adapter makes no network requests. Its output is gzip-compressed after JavaScript parsing succeeds. If original frontend source becomes available, port these checks there and replace the adapter with the normal frontend build.


Installer and design review checks:

    python3 .checks/start-installer-test.py
    python3 .checks/installer-email.py
    python3 .checks/start-installer-test.py
    node .checks/browser-installer.cjs
    node .checks/browser-experience.cjs

The start helper uses the existing LOCAL nsc-preview-db container, creates a uniquely named nsc_install_audit_* database, and starts nsc-install-test-web on loopback port 8082. It uses external local audit env files and a fake mail sink; it must never be adapted to point at a production database. It creates no live email, never prints the generated key/password, and replaces only its dedicated test web container. Each API/browser installer suite needs a fresh instance because a completed install seals itself. Local database root access, Docker, the existing nsc-audit-php image and the external browser runtime are required. The source checkout is not used as the test document root. browser-experience.cjs uses the existing audit site on port 8081 with its fixture accounts; run it after legacy tests restore disabled MFA there.

November account-removal and save regression checks:

- `account-seed.php` initializes schema and two synthetic accounts, only in `nsc_audit`; it loads passwords from the external `/run/nsc-accounts.json` mount. Use once on a fresh isolated database.
- `accounts-flow.py` covers account removal, retained workspace records, revoked sessions, cross-company restrictions, owner-child protection and quota reuse (MFA enabled, local fake mail sink required).
- `browser-accounts.cjs` covers the unchanged master/owner/employee login flows and removal controls in both management screens. It uses the external Playwright runtime at `/workspace/nsc-test/tools/node_modules/playwright-core`, overrideable with `NSC_PLAYWRIGHT_MODULE`.
- `workspace-transport.py` covers standard form saves, binary photo/PDF transport and scoped saved-file references, spoofed files/digests, CSRF, tenant checks and request/file limits. Run the existing fixtures first and disable MFA only in this audit copy.
- `browser-workspace-save.cjs` injects a host-style non-JSON 403, verifies real server readback, binary logo upload/reuse, permission-denial preservation, retry, full reload recovery and incomplete acknowledgment rejection. Host rejection is simulated; it is not proof of a particular live WAF rule.

All new tests target loopback port 8081 and the throwaway nsc_audit database with an external credentials file at `/workspace/nsc-test/accounts.json`. Never point them at production. Run suites sequentially; they intentionally mutate synthetic records and settings. `.checks` is web-denied and should not be in the deployment update ZIP.

Current November runtime: PHP 8.4 image `nsc-test-php`, MariaDB 10.11 container `nsc-test-db`, network `nsc-test`, audit web `nsc-audit-web` on loopback 8081. External test runtime and synthetic credential files live under `/workspace/nsc-test`; `/workspace/nsc-preview` is a compatibility symlink. The installer helper now uses this runtime and disposable `nsc_install_audit_*` databases with a fake sendmail sink, on loopback 8082. It does not target production.

Session/mobile/language changes:

- Copy `session-fixtures.php` to `/run/session-fixtures.php` in the audit web container. `session-security.py` checks 44 scenarios: opaque/HttpOnly cookie sessions, one-time legacy version migration, CSRF/origin checks, logout replay, IP binding, remembered devices beyond five logins and expiry, account/company restrictions after correct credentials, separate portals, support authorization/validation, bounded notices, and lock/unlock revocation. It restores ordinary account state and disables MFA only in the audit copy afterward.
- `browser-session-state.cjs` verifies stale-session recovery while retaining a pending financial sentinel, branded expired-account state, literal HTML notices, correct support links, actual HttpOnly browser cookies, 320/390/768px navigation, interrupted logout recovery, and install guidance. Run with MFA disabled in the audit copy.
- `browser-language.cjs` uses a fresh browser to verify English defaults, retained user language choice, English account status and mobile install guidance. The older browser suites explicitly select Dari for their existing label assertions.
- `email-security.py` now checks 31 cases. Remembered devices skip codes without a five-login counter; the browser/API suites force expiry to verify the 30-day boundary. The five-attempt email-code lockout remains unchanged.

Do not include `.checks`, synthetic credentials, runtime overrides or local mailboxes in the deployment ZIP. Security report counts exclude browser assertions and syntax checks.

November 2 workflow and per-project employee access:

- `patch-client-workflow.cjs` applies the follow-up adapter to the preserved `0c5a360` bundle, parses the result with acorn and refuses an already-patched bundle. It exposes the displayed project ID, keeps selection consistent, fixes folder navigation/focus, corrects native AFN creditor balances and keeps payroll settlement in its own ledger. The original frontend source is still unavailable.
- `workflow-seed.php` refuses every DB name except `nsc_audit`. It resets only the dedicated synthetic `workflow-owner` workspace and its two fixture users; do not reuse these identities for other tests.
- Run `python3 .checks/workflow-run.py` after the ordinary audit schema/accounts and local fake mail sink are prepared. It uses the external Playwright runtime and `/workspace/nsc-test/accounts.json`; it writes only an external synthetic `workflow-account.json`, never credentials in the checkout. Browser tests guard the audit database before work.
- The seven sequential browser workflows cover real 100/75/25 bill entry/settlement, 2000/1500/500 AFN payroll, folder/subfolder/Enter/focus/duplicate-name handling, actual file upload and readback after reload, distinct rights in two assigned projects, client control visibility when switching projects, direct server denial and cross-currency settlement. The fixture includes two SQL projects for the legacy payroll side call and two snapshot projects for the actual client.
- Screenshots are educational data only and go to `/workspace/output/guide/screenshots`; the test logs go to `/workspace/nsc-test/results`. Tests intentionally mutate synthetic financial records. The driver disables MFA in the audit copy only; it must never run against production.
- `browser-permissions.cjs` now checks explicit project selection and seven actions per section per project in the owner's employee editor, including the viewer ceiling.

Deployment update includes only `nsc-accounting-r3.js.gz`, `workspace.php`, `workspace-permissions.js`, `team.php` and the new `team-projects.js`. Never upload these test fixtures, external account files or audit mail overrides.

November 3 password recovery:

- `api/password-reset.php` owns recovery-only challenges, separate from login MFA. The same public recovery actions support `portal=user` and `portal=master`, with no automatic session after reset. Account existence and the registered email are not disclosed.
- Copy `.checks/reset-fixtures.php` to `/run/reset-fixtures.php` in the isolated audit container; it refuses every DB name except `nsc_audit`. It creates/restores only `reset-test-user` and `reset-test-master`, but clears recovery rate-limit fixtures in the throwaway audit DB.
- Run `python3 .checks/password-reset.py`, then `node .checks/browser-password-reset.cjs` sequentially with the existing external credentials and fake local mail sink. The suites restore synthetic account passwords and disable MFA only in the audit copy afterward.
- Cases include recovery for both portals, registered-email lookup, generic unknown/wrong-portal/deleted account responses, mail failure, hashed codes, expiry, five failed guesses, resend/account/IP throttles, code replay and concurrent completion, changed account context, old password/session/device revocation and retained normal login MFA.
- Browser coverage includes Dari and English, 390px mobile layout, resend countdown using a test clock, confirmation mismatch, invalid code, successful reset and normal MFA sign-in to each portal. Codes and passwords must never be saved in browser Storage or shipped in update ZIPs.

November 4 verification presentation:

- `browser-code-design.cjs` exercises the actual login page and shared verification UI with a controlled verification callback: desktop/mobile, Dari/English, six decorative cells backed by one accessible input, Persian-digit normalization, spaced paste, cursor editing, error and pending feedback, reduced motion and Escape.
- Run it before `browser-password-reset.cjs` and `browser-email.cjs`, sequentially. The latter two still use the guarded audit DB and fake mail sink for real authentication/recovery flows. Design previews contain only synthetic data and are saved outside the checkout in `/workspace/output/code-design`.
- Deploy `code-input.css` and `code-input.js` with both login PHP entry points and updated `login-email.js` / `password-reset.js`; the updated asset URLs refresh browser caches.

Emerald login layout (November 4):

- `browser-login-design.cjs` checks the enhanced login pages at 1440/768/390/320px in Dari/English, native input labels/autocomplete, exactly one login form/recovery control, password visibility, language rerender, recovery cancellation and reduced motion. The screenshots use no real account details.
- Run `browser-password-reset.cjs` and `browser-email.cjs` sequentially afterward against the existing isolated audit/fake-mail runtime to verify the existing authentication handlers still complete real sign-ins from the new layout.
- `login-design.js` preserves and moves the existing form DOM nodes; it adds no separate authentication or storage path. `login-design.css` is scoped to the public login-page body. Ship both new assets with login.php and office7-login.php.

Removed identity reuse (November 6):

- `reused-identities.py` uses only loopback and the guarded audit fixture. Copy `reused-identities-inspect.php` to `/run/reused-identities-inspect.php` in the audit container. It checks explicit new-account reuse of removed identifiers, new IDs and workspace isolation, preservation of historical data/identity, active duplicate protection, owner scope, stale sync rejection, recovery of the fresh account and concurrent reuse.
- `browser-reused-identities.cjs` covers the real Office7 create/delete/recreate form and the new owner's forgot-password and MFA sign-in. Use the existing fake mail sink; the suite enables MFA only in the audit copy and disables it afterward.
- Existing `accounts-flow.py` requires MFA enabled and the fake sink; run it sequentially and restore the audit mail setting afterward. `password-reset.py` covers the unchanged recovery behavior and restores its synthetic fixture users.
- `api/account-identity.php` is private/web-denied. Only explicit `createOnly` requests may retire identifiers, after normal authorization and validation. The retirement and INSERT share one DB transaction; historical rows, workspaces and IDs are retained.
