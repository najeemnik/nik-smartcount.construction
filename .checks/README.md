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
