#!/bin/sh
# eduQR — container entrypoint for the local Docker stack [NFR-75]
#
# Creates the runtime directories and applies database migrations with the
# project's own runner (bin/migrate.php, idempotent) before starting Apache.
set -e

cd /var/www/html

mkdir -p "${LOG_PATH:-/var/www/html/logs}" public/uploads

attempt=1
until php bin/migrate.php; do
    if [ "$attempt" -ge 5 ]; then
        echo "[eduqr] migrations failed after ${attempt} attempts" >&2
        exit 1
    fi
    echo "[eduqr] database not ready yet, retry ${attempt}/5" >&2
    attempt=$((attempt + 1))
    sleep 3
done

exec "$@"
