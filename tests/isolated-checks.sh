#!/bin/sh
set -eu

run_php_checks() {
    for name in chat-bootstrap mfa-delivery installer-authorization installer-keys \
        update-authorization message-response reaction-catalog attachment-access \
        request-origin web-push-delivery push-subscription-policy; do
        php -d session.save_path=/tmp "/tmp/project/tests/$name.test.php"
    done
}

run_database_checks() {
    php -r 'if (!extension_loaded("gd")) { fwrite(STDERR, "The attachment fixture requires GD.\n"); exit(1); }'
    data=$(mktemp -d)
    socket=/run/mysqld/mysqld.sock
    mariadb-install-db --no-defaults --datadir="$data" --auth-root-authentication-method=normal \
        --skip-test-db --skip-name-resolve >/dev/null
    mariadbd --no-defaults --datadir="$data" --socket="$socket" --skip-networking \
        --pid-file="$data/server.pid" --log-error="$data/server.log" &
    server=$!
    trap 'kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true' EXIT
    for attempt in $(seq 1 75); do
        if mariadb-admin --no-defaults --socket="$socket" -u root ping >/dev/null 2>&1; then
            break
        fi
        sleep 0.4
    done
    if ! mariadb-admin --no-defaults --socket="$socket" -u root ping >/dev/null 2>&1; then
        cat "$data/server.log" >&2
        return 1
    fi
    mariadb --no-defaults --socket="$socket" -u root <<'SQL'
CREATE USER 'causette_fixture'@'localhost' IDENTIFIED BY 'fixture-only-database-password';
GRANT ALL PRIVILEGES ON causette_recovery_fixture.* TO 'causette_fixture'@'localhost';
SQL
    export CAUSETTE_RECOVERY_FIXTURE=1
    for entry in mfa-lifecycle:email mfa-lifecycle:invitation password-recovery:change \
        reaction-upgrade:reactions attachment-upload:upload; do
        mariadb --no-defaults --socket="$socket" -u root <<'SQL'
DROP DATABASE IF EXISTS causette_recovery_fixture;
CREATE DATABASE causette_recovery_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
        php -d "pdo_mysql.default_socket=$socket" -d session.save_path=/tmp \
            "/tmp/project/tests/${entry%:*}.test.php" "${entry#*:}"
    done
}

case "${1:-}" in
    php-in-fixture) run_php_checks; exit ;;
    database-in-fixture) run_database_checks; exit ;;
    apache-in-fixture) exec sh /tmp/project/tests/apache-storage-routing.sh /tmp/project/public_html/.htaccess ;;
    php|database|apache) ;;
    *) printf 'Usage: sh %s php|database|apache\n' "$0" >&2; exit 2 ;;
esac

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
exec bwrap --unshare-all --die-with-parent --new-session --clearenv \
    --ro-bind /usr /usr --ro-bind /etc /etc --ro-bind /bin /bin --ro-bind /sbin /sbin \
    --ro-bind /lib /lib --ro-bind /lib64 /lib64 \
    --proc /proc --dev /dev --tmpfs /tmp --tmpfs /run --dir /run/mysqld \
    --ro-bind "$root/public_html" /tmp/project/public_html \
    --ro-bind "$root/tests" /tmp/project/tests --perms 0700 --tmpfs /tmp/project/storage \
    --setenv PATH "$PATH" --setenv HOME /tmp --setenv LANG C.UTF-8 \
    --chdir /tmp/project -- sh /tmp/project/tests/isolated-checks.sh "$1-in-fixture"
