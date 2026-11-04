#!/usr/bin/env bash
# Start only the Outing-specific user services installed on this computer.
set -euo pipefail
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
outing_state="$HOME/.local/share/outing-system"
php_bin="$outing_state/runtime/php"
site_url="http://127.0.0.1:8000"
cd "$project_dir"
if [[ ! -x "$php_bin" || ! -f "$outing_state/mariadb.cnf" ]]; then
    echo 'The user-local Outing runtime has not been set up on this computer.' >&2
    exit 1
fi
port_free() {
    python - "$1" <<'PY'
import socket,sys
with socket.socket() as s:
    s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
    try:s.bind(('127.0.0.1',int(sys.argv[1])))
    except OSError:
        print('Port '+sys.argv[1]+' is occupied. No existing service was changed.',file=sys.stderr)
        sys.exit(1)
PY
}
case "${1:-start}" in
    start)
        if ! systemctl --user is-active --quiet outing-system-mariadb.service; then port_free 33306; fi
        if ! systemctl --user is-active --quiet outing-system-web.service; then port_free 8000; fi
        systemctl --user start outing-system-local.target
        ready=false
        for ((i=0; i<60; i++)); do
            if "$outing_state/runtime/mariadb/usr/bin/mariadb-admin" --defaults-file="$outing_state/admin.cnf" ping --silent >/dev/null 2>&1 && curl --silent --fail "$site_url/login_student.php" >/dev/null; then
                ready=true
                break
            fi
            sleep 0.25
        done
        if [[ "$ready" != true ]]; then
            echo "Outing did not become ready; see $outing_state/logs or systemctl --user status 'outing-system-*'." >&2
            exit 1
        fi
        "$php_bin" artisan outing:audit
        systemctl --user is-active --quiet outing-system-mariadb.service outing-system-web.service outing-system-scheduler.service
        echo "Outing System is running at $site_url"
        ;;
    status)
        for label in mariadb web scheduler; do
            printf '%s: ' "$label"
            systemctl --user is-active "outing-system-$label.service" || true
        done
        echo "Website: $site_url"
        ;;
    stop)
        systemctl --user stop outing-system-local.target
        echo 'Stopped only Outing user services. Data retained.'
        ;;
    artisan)
        shift
        exec "$php_bin" artisan "$@"
        ;;
    composer)
        shift
        export PATH="$outing_state/runtime:$PATH"
        exec "$php_bin" "$outing_state/runtime/composer" "$@"
        ;;
    *) echo "Usage: $0 {start|status|stop|artisan ...|composer ...}" >&2; exit 2 ;;
esac
