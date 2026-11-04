# Verification and reproducible tests

Development verification used PHP **8.4.14**, Laravel **13.40.0**, MariaDB **13.0.2**, SQLite (PDO), Chrome **156**, Playwright and isolated databases. The workspace initially had no PHP/database tools; temporary local runtimes and disposable databases were provisioned for these checks. No real database was accessed. Composer resolves the lockfile against PHP **8.3.0** to match the minimum deployment requirement; actual PHP 8.3 execution was not available.

## Automated behaviour tests

```sh
composer install
php artisan test
```

`phpunit.xml` defaults to SQLite **`:memory:`**, array mail/cache/session, and testing mode. `Tests/TestCase.php` refuses non-testing environments and database names other than `:memory:` or an explicit `*_test`. Tests reset only their isolated database. The suite covers login/logout, throttling, roles, forced changes and reset invalidation, ownership, activation, idle/absolute expiry, passive polling, requests/reviews, standard/special departures and returns, curfew boundaries/midnight, overdue returns, arrival fairness/window limits, stale grants, QR expiry/replay/session/subject binding, group membership/batching/rollback/expiry/closure, CSV validation, private photos, notification dedupe, reminder/overdue/mail jobs, settings, initial administrator provisioning, hostile names and database uniqueness.

Run the same suite on a separately created disposable MySQL/MariaDB database:

```sh
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=outing_test DB_USERNAME=outing_test DB_PASSWORD=TEST_PASSWORD \
php vendor/bin/phpunit
```

Use the platform's environment-variable syntax on Windows. Never use an existing installation, its account credentials, or a real-data backup here. The test database user should have access only to its disposable databases.

## Existing-data upgrade and real concurrency

Create another empty disposable database named `outing_upgrade_test`, then run:

```sh
APP_ENV=testing OUTING_ALLOW_TEST_RESET=1 DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=outing_upgrade_test \
DB_USERNAME=outing_test DB_PASSWORD=TEST_PASSWORD CACHE_STORE=array \
php tests/integration/upgrade.php
```

**This explicitly resets the selected test database.** The script refuses other environment/name combinations. It loads the original schema/sample data, compares every original row and column after migration (including password hashes and timestamps), checks the four open-trip registry entries, verifies the repaired enum/null fields, refuses conflicting legacy open history before domain changes, upgrades an older pre-group/pre-auth schema, and verifies reference SQL followed by fresh Laravel migrations. Fixtures contain demo data only.

Run concurrent actions using **two independent PHP processes and database connections**, against another disposable test database:

```sh
APP_ENV=testing OUTING_ALLOW_TEST_RESET=1 DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=outing_test \
DB_USERNAME=outing_test DB_PASSWORD=TEST_PASSWORD CACHE_STORE=array \
php tests/integration/concurrency.php
```

This resets `outing_test`. A start barrier releases two different guards together. Checks require exactly one successful individual checkout, one QR-window consumption, one atomic group batch with two accepted members, one notification for concurrent deliveries, and a consistent active-outing registry afterward. SQLite cannot substantiate InnoDB row-lock/concurrency behaviour; these checks were actually run on MariaDB.

## Browser workflows and visual evidence

Run a **separate isolated DemoSeeder installation**, with `MAIL_MAILER=log`, then:

```sh
npm ci
npx playwright install chromium
OUTING_BROWSER_DEMO=1 BASE_URL=http://127.0.0.1:8000 npm run test:browser
```

If Chrome is already installed, set `CHROME_BIN` to its executable. `OUTING_BROWSER_DEMO=1` acknowledges that this script writes outings/groups to the demo installation. Do not run it against real accounts. If passing database variables only through a shell when using `artisan serve`, use `--no-reload` so Laravel forwards those variables to its child server, or put them in that isolated installation's `.env`.

The browser checks real form submissions, cookies/CSRF, student QR rendering/issuance, guard QR verification, identity confirmation, standard checkout/return, invitation and acceptance, Master QR batch departure, individual member returns and automatic closure, forced-password redirects and mobile menu behaviour. It also verifies rejection of a tokenless non-browser POST and no page JavaScript errors. Scanner input is injected into the scanner's submitted field using a legitimately issued student QR; **physical camera/ID hardware is not simulated**.

Before/after desktop/mobile screenshots and layout measurements are in [VISUALS.md](VISUALS.md).

## Actual results and limits

The final executed result summary is recorded in [verification-results.txt](verification-results.txt). Fresh SQLite and MariaDB migrations, regression tests on both drivers, the legacy/older-schema upgrade scenarios, concurrent processes, browser workflows, Blade compilation/PHP syntax checks, route caching and dependency checks were run. See the exact counts and commands there.

Remaining deployment verification: actual PHP 8.3 and MySQL versions, your own backup/restore and real-data rehearsal, Apache/Nginx/PHP-FPM configuration, institutional SMTP delivery/failure recovery, camera permissions and scanning on real HTTPS phones, and physical identity procedures. Browser comparisons cover representative screens and do not certify every page or large-data performance. Reports retain the original all-history presentation and should be profiled with institutional data volumes. External Google Fonts / CDN QR libraries still need network access. SMTP cannot give an application an unconditional exactly-once guarantee across a relay-acceptance crash; this is documented in the README. No claim of a bug-free or production-certified system is made.
