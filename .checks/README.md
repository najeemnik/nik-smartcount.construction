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
