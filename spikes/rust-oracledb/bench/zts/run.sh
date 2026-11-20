#!/bin/sh
# Runs a command in the ZTS bench image, on the ddev network so `oracle` resolves.
# Usage (from the repo root): spikes/rust-oracledb/bench/zts/run.sh '<shell command>'
set -eu
root="$(cd "$(dirname "$0")/../../../.." && pwd)"
exec docker run --rm -i --network ddev-php-oracle_default \
  -v "$root:/app" \
  -v php-oracle-bench-zts-registry:/usr/local/cargo/registry \
  -v php-oracle-bench-zts-target:/var/cache/cargo-target \
  -e ORACLE_CONNECT_STRING=oracle:1521/FREEPDB1 -e ORACLE_USER=rajska -e ORACLE_PASSWORD=rajska \
  ${ZTS_DOCKER_ARGS:-} php-oracle-bench-zts sh -c "$1"
