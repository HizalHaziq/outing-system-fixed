# Local setup on this computer

The Laravel root is `/home/hizal/larping/outing-system-test`. The application is configured at **http://127.0.0.1:8000**.

Start next time:

```sh
cd /home/hizal/larping/outing-system-test
./scripts/local.sh start
```

The same script supports `status` and `stop`. It starts/stops only `outing-system-local.target` and its three user services: MariaDB, the Laravel web server and the scheduler. It checks port availability and never stops an unrelated listener. Services stay running when the terminal closes; they are not enabled for automatic login startup.

The isolated MariaDB listener uses `127.0.0.1:33306`, with persistent data and runtime binaries under `~/.local/share/outing-system/`. The web server uses `127.0.0.1:8000` and serves Laravel's `public/` directory. Company Training's files, services, ports and credentials were not changed. No administrator access was needed; PHP/Composer are scoped to this stack, with no global PATH or package changes.

The fresh application database is `outing_system_local`. A dedicated account has privileges only on that database. Its generated password is in the project's Git-ignored, owner-readable `.env`. The existing `APP_KEY` was retained. Private administration configuration, backups and logs live under `~/.local/share/outing-system/`; do not commit that directory or `.env`.

The earlier temporary verification databases were copied from their stopped Outing-only instance, dumped into a private backup before provisioning, and retained. No database was dropped or reset. The configured application database did not already exist. All four migrations were applied normally, then `DemoSeeder` created these development accounts:

| Role | Login ID | Password |
|---|---|---|
| Admin | `ADM001` | `Staff@123` |
| Warden | `STF001` | `Staff@123` |
| Guard | `GRD001` | `Staff@123` |
| Student | `2026000001`–`2026000005` | `Student@123` |

Student `2026000002` must change their password at first login. Browser verification creates completed example outings and a closed group in this new demo database; those records are retained.

Sessions use private files, cache and scheduler locks use the migrated database tables, and queues run synchronously without a separate worker. The scheduler runs as its own user service. Email uses Laravel's **log** transport and does not send messages externally. The original theme is unchanged.

Use the bundled PHP/Composer without changing another project's environment:

```sh
./scripts/local.sh artisan migrate:status
./scripts/local.sh artisan outing:audit
./scripts/local.sh composer check-platform-reqs
```

Runtime/service logs: `~/.local/share/outing-system/logs/`. Application and local email logs: `storage/logs/laravel.log`. Service definitions: `~/.config/systemd/user/outing-system-*.service` and `outing-system-local.target`.

This setup is local to this computer. The main README still documents portable installation/deployment for another environment.

Verified on this machine: database connection (+08:00 / READ COMMITTED), all four role logins, forced password change redirect, student QR checkout/return, group invitation and Master QR batch, individual returns/automatic closure, database cache, log-only mail, all three scheduled commands, and immediate stop/start. Two restart checks retained the same 5 students, 3 staff, 3 completed standard outings and 1 closed group. Repeated start is idempotent. No setup blocker remains.
