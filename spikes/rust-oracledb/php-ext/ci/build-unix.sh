#!/bin/sh
# Builds the probe extension, renames it as PIE ships it (rajska_oracle.so) and smoke-tests it.
# Used by the Linux containers and the macOS runners of .github/workflows/spike-pie-targets.yml.
set -eu
cd "$(dirname "$0")/.."
echo "== toolchain"; rustc -V; cargo -V; php -v | head -1; php-config --version
cargo build --release --locked --target-dir target
lib=target/release/librajska_oracle.so
[ -f "$lib" ] || lib=target/release/librajska_oracle.dylib
mkdir -p out && cp "$lib" out/rajska_oracle.so
echo "== artifact"; ls -l out/rajska_oracle.so
if command -v objdump >/dev/null 2>&1 && [ "$(uname)" = Linux ]; then
  echo "== glibc floor"; objdump -T out/rajska_oracle.so | grep -o 'GLIBC_[0-9.]*' | sort -Vu | tail -1 || echo "(no GLIBC symbols)"
  echo "== ldd"; ldd out/rajska_oracle.so || true
fi
if [ "$(uname)" = Darwin ]; then echo "== otool"; otool -L out/rajska_oracle.so; fi
echo "== load"; php -n -d extension="$PWD/out/rajska_oracle.so" -m | grep -x rajska_oracle
echo "== smoke"; php -n -d extension="$PWD/out/rajska_oracle.so" smoke.php
