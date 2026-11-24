#!/bin/sh
set -eu

directory=$(mktemp -d /tmp/causette-smtp.XXXXXX)
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1' \
    -out "$directory/certificate.pem" -keyout "$directory/key.pem" >/dev/null 2>&1

export CAUSETTE_SMTP_FIXTURE=1
script=/tmp/project/tests/smtp-transport.test.php
for port in 465 587; do
    php "$script" server "$port" "$directory" &
    server=$!
    trap 'kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true' EXIT
    for attempt in 1 2 3 4 5 6 7 8 9 10; do
        if test -f "$directory/ready"; then
            break
        fi
        sleep 0.2
    done
    test -f "$directory/ready"
    php "$script" client "$port" "$directory"
    wait "$server"
    trap - EXIT
    rm "$directory/ready"
done
