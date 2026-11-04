# Campus Outing System — Laravel

Already configured on this computer: run `./scripts/local.sh start` and open **http://127.0.0.1:8000**. See [local setup and demo accounts](docs/LOCAL.md).

The PHP/MySQL application is migrated to Laravel **13** (locked at 13.40.0), with the original Poppins/Metronic-compatible theme, page markup, navigation, terminology and assets. No starter kit or frontend framework is used. Existing `.php` page URLs are Laravel routes. Wardens approve, guards record movements, and admins manage accounts, reports, imports, photos and settings.

## Requirements

- PHP **8.3–8.5**, Composer 2, and Laravel's standard extensions, plus `pdo_mysql`, `mbstring`, `fileinfo`, `gd` (JPEG/PNG/WebP), `exif` for phone-photo orientation, and XML/DOM for tests.
- MySQL **5.7+** or MariaDB **10.3+**, InnoDB and `utf8mb4`, matching Laravel's supported drivers. For a new production installation use a database release still supported by its vendor, such as MySQL 8.4 LTS or MariaDB 11.4 LTS. Tests here ran on MariaDB 13.0.2; MySQL itself was not available for verification.
- HTTPS for production sessions and phone camera access. The web root must be **`public/`**.
- The old PHP 8.0 runtime must be upgraded. No JavaScript build is required; Node is optional for browser tests.

Official references: [Laravel support policy](https://laravel.com/docs/13.x/releases), [server requirements](https://laravel.com/docs/13.x/deployment), [database support](https://laravel.com/docs/13.x/database).

## New installation

Create an **empty database** and a dedicated application database user through your database administration tools. Do not import historical reset scripts.

```sh
composer install
cp .env.example .env
# Edit .env: DB_DATABASE, DB_USERNAME, DB_PASSWORD and APP_URL.
php artisan key:generate
php artisan migrate
php artisan outing:audit
php artisan serve
```

On Windows use `copy .env.example .env`. PHP, Composer and the web server must use the same supported PHP version. For XAMPP, point an Apache virtual host's `DocumentRoot` and `<Directory>` to the repository's `public` folder and enable `mod_rewrite` / `AllowOverride All`; do not copy the repository into a public `htdocs` directory. A repository-root deny rule is included as a second guard, not a replacement for correct server configuration.

Laravel reads the root `.env`. Legacy `DB_NAME`, `DB_USER`, `DB_PASS` become `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. No root/empty-password connection fallback remains. Production startup rejects missing or root credentials. Keep `APP_DEBUG=false` in production and set `SESSION_SECURE_COOKIE=true` with HTTPS. Keep the same `APP_KEY` across deployments; encrypted sessions require it. `storage/` and `bootstrap/cache/` must be writable by the application user.

Optional development accounts:

```sh
php artisan db:seed --class=DemoSeeder
```

This seeder refuses production. It provides `ADM001` (admin), `STF001` (warden), `GRD001` (guard), password `Staff@123`; students `2026000001`–`2026000005`, password `Student@123`. Student `2026000002` must change their password. The default `DatabaseSeeder` creates no accounts. For production, provision your first administrator with `php artisan outing:admin` and its interactive password prompt.

## Existing installations

Follow [UPGRADE.md](docs/UPGRADE.md) before running migrations against real data. It covers backups, isolated rehearsal, maintenance, retained IDs/hashes/history, photo copying, verification, conflicts and rollback. **Never run `migrate:fresh`, `migrate:refresh`, test reset scripts or demo seeders against a real database.**

`database/schema.sql` and its private-folder copy are matching, non-destructive reference SQL for domain tables. Laravel migrations are the installation and upgrade authority and add operation, cache and delivery infrastructure. Historical SQL patches and seed entry points are retired. The original schema and sample data remain test fixtures, not installation scripts.

## Workflows

- Outside curfew, an active guard records a standard departure and eventual return. Curfew defaults to 23:00–06:00, start inclusive/end exclusive. An unapproved standard departure is blocked during curfew.
- A student submits a special **leaving** request for a curfew time. Only a warden can approve or reject it; only a guard can execute it, at or after its scheduled departure. The same record closes on return, including when overdue.
- **Arrival permission** requires an open outing. Unreviewed requests expire at `max(expected arrival − review lead, submission + review lead)`. They excuse a return only within their requested curfew window, and record `unreviewed_by_staff`. Returning never requires a new approval. Leftover arrival permissions are cancelled at return.
- Inactive students cannot log in or depart. An active guard can still record their return after checking physical identity; no student login is needed for this recovery. When manual entry is disabled, the gate provides a restricted inactive-return lookup that can only close the displayed open outing.
- Student QR codes rotate every 30–60 seconds. Only the current code window is accepted, once. After verification, the guard has 180 seconds to confirm identity and submit the displayed action. Grants are single-use and bound to guard session, subject, group roster and outing state. A changed state requires another lookup or scan. A QR screenshot is usable during its remaining live window; the physical photo/ID check is still necessary.
- Groups have one leader and at most four accepted invitees. Group gate actions use the standard track and a verified Master QR. Membership is fixed after departure. An expired group remains returnable while anyone is out; it closes after all departed students return. Nobody can close/remove a still-out member. Special requests are processed individually.
- CSV imports accept the original university column aliases. Invalid rows are reported and skipped. Existing passwords and activation status remain unchanged. New IC numbers require 12 digits, with their last six digits used only for a forced-change initial password.
- Photos are stored at `storage/app/private/photos/<std_no>.jpg`; only authenticated authorized `student_photo.php` responses expose them. Never link this folder into `public/storage`.

All business time and database sessions use Asia/Kuala_Lumpur / `+08:00`. Existing local `DATETIME` values are not converted to UTC. The header clock explicitly displays Kuala Lumpur time.

## Scheduling, mail and deployment

Run Laravel's scheduler once per minute:

```cron
* * * * * cd /path/to/outing-system-test && /usr/bin/php artisan schedule:run >> /var/log/outing-scheduler.log 2>&1
```

For Windows Task Scheduler, run `php.exe artisan schedule:run` every minute with the repository root as “Start in”. Disable both old scheduled PHP scripts. Laravel schedules `outing:overdue`, `outing:reminders` and `outing:mail`, with overlap prevention. These can also be run individually. Use a shared database cache for multiple application instances; file sessions require one host or shared storage. Do not run two different application releases as simultaneous writers during migration.

The default mail transport is `log`. For delivery set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` and `MAIL_FROM_ADDRESS` in `.env`; configure the actual relay's supported TLS mode. Decisions and overdue alerts commit a durable outbox in the same transaction. Failed deliveries remain available for retry. Bell notifications have durable unique delivery keys, even after the user clears them. SMTP cannot guarantee exactly-once delivery after a crash between relay acceptance and recording `sent_at`; deterministic Message-ID helps relays deduplicate, but this edge needs relay support. Inspect `mail_outbox.last_error` and attempts when diagnosing failures.

After configuration and migration:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan outing:audit
```

Serve only `public/index.php` as executable PHP. [deploy/nginx.conf](deploy/nginx.conf) gives a starting configuration. Back up the database, private photos and `APP_KEY`; use normal log retention and outbox/used-grant retention appropriate to your institution. Old public PHP files now dispatch through Laravel; retired libraries and schedulers cannot write to the database.

## Verification and implementation notes

See [TESTING.md](docs/TESTING.md) for actual results, test commands, concurrency/upgrade safeguards and browser limitations; [VISUALS.md](docs/VISUALS.md) for before/after screenshots; [CHANGES.md](docs/CHANGES.md) for bug fixes and affected files; and [INSPECTION.md](docs/INSPECTION.md) for permissions, states and relationships.

The original repository did not include a license. Theme/image licensing has not been changed by this migration.
# outing-system-fixed
# outing-system-fixed
