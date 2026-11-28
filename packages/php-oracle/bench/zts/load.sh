#!/bin/sh
# Inside the ZTS image (run.sh): starts FrankenPHP in worker mode with
# $WORKERS worker threads and drives every (workload, concurrency, driver)
# with wrk, in $ROUNDS rounds with the driver order reversed on even rounds.
# Prints one line per wrk run; run.sh keeps them in output/frankenphp-<tag>.txt.
set -eu
export WORKERS="${WORKERS:-8}" THREADS="$(( ${WORKERS:-8} + 2 ))"
export PHP_INI_SCAN_DIR=/app/packages/php-oracle/bench/zts/conf.d
DRIVERS="${DRIVERS:-oci8 core core-held}"
DUR="${DUR:-10s}"
frankenphp run --config /app/packages/php-oracle/bench/zts/Caddyfile >/tmp/frankenphp.log 2>&1 &
for _ in $(seq 1 100); do curl -fs 'http://127.0.0.1:8080/?d=oci8&w=scalar' >/dev/null 2>&1 && break; sleep 0.2; done
for d in $DRIVERS; do printf '%s: ' "$d"; curl -fsS "http://127.0.0.1:8080/?d=$d&w=scalar"; echo ' up'; done
echo "# FrankenPHP worker mode, $WORKERS worker threads; wrk $DUR per point after a 2 s warm-up"
for round in $(seq 1 "${ROUNDS:-2}"); do
  drivers="$DRIVERS"; [ $((round % 2)) = 0 ] && drivers="$(echo "$DRIVERS" | tr ' ' '\n' | tac | tr '\n' ' ')"
  for w in ${WORKLOADS:-scalar scalar-txn cursor fetch}; do
    for c in ${CONC:-1 8}; do
      for d in $drivers; do
        url="http://127.0.0.1:8080/?d=$d&w=$w"
        wrk -t1 -c"$c" -d2s "$url" >/dev/null # warm-up: fills the Pool and the statement caches
        out="$(wrk -t"$([ "$c" -ge 2 ] && echo 2 || echo 1)" -c"$c" -d"$DUR" --latency "$url")"
        rps="$(echo "$out" | awk '/Requests\/sec/ {print $2}')"
        p50="$(echo "$out" | awk '$1=="50%" {print $2}')"
        p99="$(echo "$out" | awk '$1=="99%" {print $2}')"
        errs="$(echo "$out" | awk '/Non-2xx|Socket errors/ {print}' | tr '\n' ' ')"
        echo "round=$round workload=$w conc=$c driver=$d rps=$rps p50=$p50 p99=$p99 $errs"
      done
    done
  done
done
grep -i "error\|panic\|fatal" /tmp/frankenphp.log | head -5 || true
