#!/usr/bin/env bash
# Verify that database/migrations/*.sql apply cleanly to a real MySQL 8.4 and
# that the result still matches database/schema.sql. [NFR-86]
#
#   bash bin/verify-migrations.sh
#
# Starts a throwaway mysql:8.4 container on 127.0.0.1:3308 with no volume,
# runs the migration runner against it, dumps the schema, diffs it against the
# reference schema, and removes the container. The docker compose stack and its
# db-data volume are never touched.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP_BIN:-php}"
CONTAINER="eduqr-migrate-check"
PORT=3308
DB_NAME="eduqr_migrate_check"
ROOT_PASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=')"
WORK="$(mktemp -d)"

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "Removing a leftover $CONTAINER container."
    docker rm -f "$CONTAINER" >/dev/null
fi

echo "Starting mysql:8.4 on 127.0.0.1:${PORT} ..."
docker run -d --rm \
    --name "$CONTAINER" \
    -e MYSQL_ROOT_PASSWORD="$ROOT_PASS" \
    -e MYSQL_DATABASE="$DB_NAME" \
    -p "127.0.0.1:${PORT}:3306" \
    mysql:8.4 \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_unicode_ci >/dev/null

echo -n "Waiting for the server "
for _ in $(seq 1 60); do
    if docker exec "$CONTAINER" mysqladmin ping -h 127.0.0.1 -u root -p"$ROOT_PASS" --silent >/dev/null 2>&1; then
        echo " ready."
        break
    fi
    echo -n "."
    sleep 2
done

if ! docker exec "$CONTAINER" mysqladmin ping -h 127.0.0.1 -u root -p"$ROOT_PASS" --silent >/dev/null 2>&1; then
    echo "MySQL did not become ready in 120s." >&2
    exit 1
fi

cat > "$WORK/.env" <<ENVFILE
APP_ENV=testing
APP_DEBUG=true
DB_HOST=127.0.0.1
DB_PORT=${PORT}
DB_NAME=${DB_NAME}
DB_USER=root
DB_PASS=${ROOT_PASS}
ENVFILE

echo "Applying migrations ..."
"$PHP" "$ROOT/bin/migrate.php" --env="$WORK/.env"

APPLIED="$(docker exec "$CONTAINER" mysql -u root -p"$ROOT_PASS" -N -B -e \
    "SELECT COUNT(*) FROM ${DB_NAME}.schema_migrations" 2>/dev/null)"
echo "Applied migrations recorded: ${APPLIED}"

normalize() {
    # Drop dump noise and values that differ per run: comments, AUTO_INCREMENT
    # counters, and blank lines. Sort nothing — table order is part of the diff.
    sed -e 's/ AUTO_INCREMENT=[0-9]*//' \
        -e '/^\/\*!/d' \
        -e '/^--/d' \
        -e '/^$/d' \
        -e 's/[[:space:]]*$//'
}

docker exec "$CONTAINER" mysqldump -u root -p"$ROOT_PASS" \
    --no-data --skip-comments --skip-set-charset --compact \
    --ignore-table="${DB_NAME}.schema_migrations" \
    "$DB_NAME" 2>/dev/null | normalize > "$WORK/from-migrations.sql"

echo "Schema dumped: $(wc -l < "$WORK/from-migrations.sql") lines."

if [ "${DUMP_ONLY:-0}" = "1" ]; then
    cp "$WORK/from-migrations.sql" "$ROOT/schema-from-migrations.sql"
    echo "DUMP_ONLY=1 — wrote schema-from-migrations.sql for inspection."
    exit 0
fi

if ! diff -u <(normalize < "$ROOT/database/schema.sql") "$WORK/from-migrations.sql"; then
    echo "database/schema.sql does not match what the migrations produce." >&2
    echo "The migrations are authoritative; correct the reference file." >&2
    exit 1
fi

echo "OK — migrations apply cleanly and schema.sql matches."
