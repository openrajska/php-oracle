#!/bin/sh
# The ZTS side of the benchmark, from the host: builds the image if needed,
# builds the Extension for ZTS (release), checks both drivers return the
# same values, then runs FrankenPHP in worker mode under wrk (load.sh).
#   packages/php-oracle/bench/zts/run.sh [tag]      (ddev bench-zts [tag])
# Needs the ddev project running (Oracle on the ddev network); schema.php
# creates the objects the cases read.
# Environment for load.sh: WORKERS, ROUNDS, DUR, CONC, WORKLOADS, DRIVERS.
set -eu
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../../../.." && pwd)"
tag="${1:-zts}"
image=php-oracle-bench-zts
docker image inspect "$image" >/dev/null 2>&1 || docker build -t "$image" "$here"
in_image() {
  docker run --rm -i --network ddev-php-oracle_default \
    -v "$root:/app" \
    -v php-oracle-bench-zts-registry:/usr/local/cargo/registry \
    -v php-oracle-bench-zts-git:/usr/local/cargo/git \
    -v php-oracle-bench-zts-target:/var/cache/cargo-target \
    -e ORACLE_CONNECT_STRING=oracle:1521/FREEPDB1 \
    -e ORACLE_USER="${ORACLE_USER:-bench}" -e ORACLE_PASSWORD="${ORACLE_PASSWORD:-bench}" \
    -e ORACLE_ADMIN_USER -e ORACLE_ADMIN_PASSWORD \
    -e WORKERS -e ROUNDS -e DUR -e CONC -e WORKLOADS -e DRIVERS \
    "$image" sh -c "$1"
}
in_image 'cd /app/packages/php-oracle-ext && cargo build --release --locked --target-dir /var/cache/cargo-target/core-bench'
in_image 'cd /app/packages/php-oracle/bench && PHP_INI_SCAN_DIR= php -c php.ini -d extension=/var/cache/cargo-target/core-bench/release/libphp_oracledb.so schema.php'
in_image 'cd /app/packages/php-oracle/bench && PHP_INI_SCAN_DIR= php -c php.ini -d extension=oci8.so -d extension=/var/cache/cargo-target/core-bench/release/libphp_oracledb.so crosscheck.php >/dev/null && echo "crosscheck ok"'
mkdir -p "$here/../output"
in_image 'sh /app/packages/php-oracle/bench/zts/load.sh' | tee "$here/../output/frankenphp-$tag.txt"
