# Implementation and bug fixes

The original source baseline is commit `ea54541`. The implementation is now in Laravel; the old public PHP files are compatibility dispatchers and the private procedural libraries are retired. This is a source migration, not a second application wrapping the old runtime.

| Fix / preserved behaviour | Main files |
|---|---|
| Missing arrival columns, nullable destination, expired states; preserve IDs/hashes/history; old-key collation compatibility | `database/migrations/2026_11_04_000001_reconcile_legacy_schema.php`, matching `database/schema.sql` and private reference copy |
| Missing environment loading and root/empty-password defaults | `config/database.php`, `.env.example`, `app/Providers/AppServiceProvider.php` |
| Duplicate standard departures, overlapping special/standard outings, stale submissions | `app/Services/GateService.php`, `QrService.php`, migrations `000002` / `000004` |
| One open outing per student across public write paths; per-track database constraints | Unique `active_outing.std_no`, MySQL generated unique keys / SQLite partial indexes, shared student-row locking with READ COMMITTED so post-lock state checks see concurrent commits |
| Overdue special outings visible and returnable on the original record | `GateService.php`, `RequestService.php`, `ReadService.php` |
| Inactive departures denied; already-out inactive students can return even when manual entry is disabled | `AccountSession.php`, `GateService.php`, `QrService.php`, `PageController.php`, gate Blade view; active guard and physical identity confirmation required |
| Warden-only decisions, guard-only gate actions, student ownership and forced changes | `routes/web.php`, `AccountSession.php`, `OutingRequestPolicy.php`, Form Requests and controllers |
| Login throttling, session rotation, idle/absolute expiry and password-reset invalidation | `AuthController.php`, `AccountSession.php`, `config/auth.php`; passive QR/notification polling does not refresh idle time |
| Unreviewed arrival deadline and correct curfew-window coverage | `Curfew.php`, `RequestService.php`, `ScheduledWork.php`; earlier unrelated curfews cannot be excused |
| QR expiry/replay, guard-session binding, exact subject/state/roster, single-use verification | `QrService.php`, `GateService.php`, `GateFormRequest.php`, `gate_verification` table |
| Transactional group batches, membership locking, outsider rejection, returns after group expiry, closure only after all departed people return | `GroupService.php`, `GateService.php`, `QrService.php` |
| Durable notification dedupe across concurrent jobs and user deletion; transactional mail outbox | `Notifier.php`, `ScheduledWork.php`, `routes/console.php`, `delivery_key`, `mail_outbox` |
| Dashboard count no longer counts overdue arrival permissions as separate people out; arrival-linked trips shown once in history/reports | `ReadService.php`, `PageController.php` |
| Validate all settings before writing; staff resets force first-login change; temporary passwords are not emailed/stored in an outbox | `AdminController.php`, `SettingsFormRequest.php`, `Settings.php` |
| CSV aliases retained, malformed IC/length/email/duplicate-row handling, preserve hashes and activation | `ImportService.php`, `AdminController.php` |
| Private photos, owner/staff checks, activation/session checks, re-encoding and pixel limits | `PhotoService.php`, `AdminController.php`, `config/filesystems.php` |
| Apostrophes and markup in names cannot execute through confirmation dialogs | Gate/staff Blade `data-confirm` attributes and static handlers; regression test |
| Original assets, HTML hierarchy, sidebar, header, forms, tables and mobile navigation retained | `public/assets`, `public/images`, `resources/views/layouts`, `partials`, `pages`, `components` |
| Device-timezone clock mismatch | `resources/views/partials/clock_widget.blade.php` explicitly uses `Asia/Kuala_Lumpur` |

Intentional reliability changes: only a current QR time window is accepted (the old ±one-window allowance was removed); an approved departure cannot execute before its requested time; pending requests of the same type cannot be duplicated; group membership cannot be changed after departure; inactive-return recovery is narrowly limited to closing an open outing. Logout is POST, with a safe GET confirmation for old bookmarks. Staff temporary passwords are handed over securely by the administrator. Existing photo and mail workflows otherwise retain their roles and terminology.
