# Existing-data upgrade and rollback

Run commands from the repository root. No production database or user files were accessed during development; only disposable test databases were used.

## Before the maintenance window

1. Upgrade PHP to 8.3+ and install the documented extensions and Composer dependencies. Keep the old application release available separately. Test database-driver compatibility before changing an old XAMPP installation.
2. Take a consistent database backup, including schema, routines/triggers and all tables, using your database's supported backup tool. Example for MySQL (adapt the tool for MariaDB):

   ```sh
   mysqldump --single-transaction --routines --triggers -h HOST -u BACKUP_USER -p outing_system > outing-before.sql
   ```

   Protect this file: it contains personal information and password hashes. Do not put passwords on the command line. InnoDB is required for transactional consistency. Prevent schema changes while backing up.
3. Back up `outing-system-private/storage/photos/` and deployment configuration. Keep existing secret files outside source control. Record table counts, primary key ranges, open standard trip IDs, open checkout request IDs, group status/logs, notification counts and photo names/checksums. Take a database-native checksum or ordered export if desired.
4. Restore the backup into a **different database on an isolated staging instance**, with separate credentials and `MAIL_MAILER=log`. Copy the old photos to `storage/app/private/photos/` without changing filenames. Rehearse the migration, audit and role/gate workflow checks there. Do not use a real backup in a test runner: test runners intentionally reset disposable databases.

## Cutover

1. Stop old scheduled scripts and disable every old application writer (Apache aliases, direct PHP endpoints, other gate PCs and cron jobs). Put the old application into web-server maintenance. Back up again after traffic stops if the earlier backup predates new movements.
2. Deploy this release with web root `public/`. Remove old public aliases; route the old page paths to Laravel. Copy the root `.env.example` to `.env`, set explicit database credentials for the existing database, HTTPS URL and production flags. Laravel uses `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, not the former names. Generate `APP_KEY` once for this migration; keep it for subsequent Laravel deployments. Existing PHP sessions will be invalidated and users must sign in again.
3. Copy **all** private photo files to `storage/app/private/photos/`. On Linux, for example:

   ```sh
   install -d -m 700 storage/app/private/photos
   cp -a /OLD_PRIVATE/storage/photos/. storage/app/private/photos/
   # Set ownership to the application user; keep the source backup intact.
   ```

   Never use `storage:link` for this folder. No migration renames or deletes photos.
4. Install production dependencies, clear stale Laravel configuration, and run:

   ```sh
   composer install --no-dev --optimize-autoloader
   php artisan config:clear
   php artisan migrate --pretend
   php artisan migrate --force
   php artisan outing:audit
   ```

   `--pretend` is not a substitute for the isolated rehearsal: conditional schema/data work cannot be fully demonstrated by printing SQL alone. A DBA account may be needed temporarily for DDL. The normal runtime account should have only the permissions its operations need.
5. Verify original rows, IDs, hashes, timestamps and relationships against the backup inventory. Added default settings and operational tables increase some counts. Check arrival `coming_from` values, matched `arrival_request_id` links, expired-state persistence, and private photos. Verify each role in a browser, especially a pending review, an open standard return, an overdue special return, group QR and closure, and a disabled already-out student's return. Configure the scheduler/mail transport and run a test delivery to a controlled recipient.
6. Cache configuration/routes/views, switch traffic to Laravel and start `schedule:run` every minute. Monitor logs and the outbox. Keep the old application disabled; it does not maintain Laravel's cross-track active-outing registry.

## What migrations do

The reconciliation migration creates only missing domain tables and adds missing fields: `coming_from`, `unreviewed_by_staff`, `arrival_request_id`, `must_change_password`, request `type` where absent, `expired` enum members, and nullable arrival destination. It copies existing check-in destination text into `coming_from` **without erasing the original text**. It backfills an arrival link only when exactly one completed arrival matches the same student within five seconds; ambiguous/unmatched links remain for review. Existing default settings are not overwritten. Identifiers, password hashes, original columns, history, groups, notifications and timestamps are retained.

Before domain changes it detects more than one open standard/special checkout for any student and stops with the student and trip IDs. It never deletes or chooses a historical trip. Reconcile the discrepancy against physical gate records in staging and through an operator-approved repair before retrying. An open arrival permission is not itself an open departure.

The operation migration backfills one `active_outing` record per open trip and adds single-use gate verification, durable notification-delivery keys and the mail outbox. Key collations match existing student/staff identifiers. The final integrity migration adds generated unique keys (MySQL/MariaDB) or partial unique indexes (SQLite) for one open standard and one open special departure per student. Cross-track exclusion uses the unique active registry and shared student-row locks on every application write. MySQL connections explicitly use READ COMMITTED so a gate action waiting for a row lock cannot reuse an earlier consistent-read snapshot. Existing duplicate notifications remain; reserving their delivery key prevents new copies. Operation-table creation and registry/ledger backfills tolerate a retry after interrupted DDL. The migration runner itself may create its own tracking table even when the domain preflight refuses a conflict.

Migrations require exclusive application-writer downtime. They do not wrap MySQL DDL in a fictitious transaction: MySQL/MariaDB may commit individual ALTER statements. After an interruption, inspect `migrate:status`, rehearse the retry on a clone and verify the registry. No database is dropped or recreated by the upgrade.

## Rollback and irreversible aspects

**Automatic `migrate:rollback` is intentionally refused** for the data-preserving domain and operation migrations. Reverse-shrinking enum values or removing fields would discard new arrival, verification and delivery history. There is no safe automatic reverse mapping after new movements have been recorded.

Before reopening traffic, rollback means keeping traffic stopped, restoring the verified pre-upgrade database backup into the agreed recovery database, restoring the old release/configuration and photo backup, verifying counts/history, then deliberately restarting the old scheduler and traffic. Restore to a separate recovery database first; do not overwrite a real database without your normal DBA approval and recovery procedure.

After Laravel has accepted new movements, blindly restoring the earlier backup would lose those movements. Stop writers, take another backup, export all post-cutover changes and reconcile them with gate records before deciding on a restore or a forward fix. Retain both snapshots and all photo changes. Treat this as an operator-led recovery, not a destructive Artisan command.
