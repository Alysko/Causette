#!/bin/sh
set -eu

# Run inside a disposable Apache environment with a writable /tmp and loopback.
fixture=$(mktemp -d /tmp/causette-apache.XXXXXX)
public="$fixture/public"
mkdir -p "$public/storage/logs"
cp "$1" "$public/.htaccess"
printf '%s\n' 'public asset' > "$public/public.txt"
printf '%s\n' 'front controller' > "$public/index.php"
printf '%s\n' 'private fixture' > "$public/storage/logs/fixture.txt"

cat > "$fixture/httpd.conf" <<EOF
ServerRoot "$fixture"
ServerName localhost
Listen 127.0.0.1:8080
PidFile "$fixture/httpd.pid"
ErrorLog "$fixture/error.log"
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so
DocumentRoot "$public"
<Directory "$public">
    Options FollowSymLinks
    AllowOverride FileInfo Options
    Require all granted
</Directory>
EOF

apache2 -f "$fixture/httpd.conf" -DFOREGROUND &
server=$!
trap 'kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true' EXIT

curl_local() {
    curl --noproxy '*' --connect-timeout 1 --max-time 3 --silent --show-error "$@"
}

origin=http://127.0.0.1:8080
for attempt in 1 2 3 4 5; do
    if curl_local --fail --output /dev/null "$origin/public.txt" 2>/dev/null; then
        break
    fi
    sleep 1
done

test "$(curl_local --fail "$origin/public.txt")" = 'public asset'
test "$(curl_local --fail "$origin/a/fixture.txt")" = 'front controller'
for path in /storage /storage/ /storage/logs/fixture.txt /STORAGE/logs/fixture.txt; do
    status=$(curl_local --output /dev/null --write-out '%{http_code}' "$origin$path")
    if [ "$status" != 403 ]; then
        printf 'Expected HTTP 403 for %s, got %s\n' "$path" "$status" >&2
        exit 1
    fi
done
printf '%s\n' 'Apache checks passed for static assets, application routing and private storage denial.'
