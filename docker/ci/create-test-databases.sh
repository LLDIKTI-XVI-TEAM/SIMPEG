#!/bin/sh
# Membuat database simpeg_test beserta varian per-token paratest.
# Paratest menyetel TEST_TOKEN berbeda per worker dan config/database.php
# menambahkan suffix tersebut ke DB_DATABASE, sehingga tiap proses paralel
# bermigrasi pada databasenya sendiri. Cakup 0..N karena basis token
# (0-based vs 1-based) bergantung versi paratest; kelebihan database tidak
# dipakai dan tidak merugikan apa pun.
set -eu

MAX_SUFFIX="${1:-16}"
BASE_DB="${DB_DATABASE:-simpeg_test}"
PGUSER="${DB_USERNAME:-simpeg}"

psql_exec() {
    podman compose exec -T db psql -U "$PGUSER" -d postgres -v ON_ERROR_STOP=1 "$@"
}

db_exists() {
    psql_exec -tc "SELECT 1 FROM pg_database WHERE datname = '$1'" | grep -q 1
}

create_if_missing() {
    if ! db_exists "$1"; then
        psql_exec -c "CREATE DATABASE \"$1\""
    fi
}

create_if_missing "$BASE_DB"

i=0
while [ "$i" -le "$MAX_SUFFIX" ]; do
    create_if_missing "${BASE_DB}${i}"
    i=$((i + 1))
done
