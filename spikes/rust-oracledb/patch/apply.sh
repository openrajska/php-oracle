#!/usr/bin/env bash
# Copies the pristine oracledb 26.0.0-beta.6 source from the cargo registry
# into spikes/rust-oracledb/vendor/oracledb and applies every patch:
#   implicit-results.diff  ExecResult::implicit_results()
#   object-binds.diff      IN / OUT / IN OUT binds of objects and Collections
# The two touch disjoint files, so each also applies alone to the pristine copy.
# Run inside ddev (the registry lives in the web container):
#   ddev exec bash spikes/rust-oracledb/patch/apply.sh
# Idempotent: the vendor copy is wiped and recopied every time.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
spike="$(dirname "$here")"
version="26.0.0-beta.6"
dest="$spike/vendor/oracledb"

src="$(ls -d "${CARGO_HOME:-/usr/local/cargo}"/registry/src/*/oracledb-"$version" 2>/dev/null | head -n1 || true)"
if [ -z "$src" ]; then
    # Not downloaded yet: let cargo fetch it through the unpatched probe crate.
    (cd "$spike/probe" && cargo fetch -q)
    src="$(ls -d "${CARGO_HOME:-/usr/local/cargo}"/registry/src/*/oracledb-"$version" | head -n1)"
fi

rm -rf "$dest"
mkdir -p "$(dirname "$dest")"
cp -R "$src" "$dest"

if [ "${1:-}" != "--no-patch" ]; then
    for diff in implicit-results.diff object-binds.diff; do
        patch -d "$dest" -p1 --forward --quiet < "$here/$diff"
    done
    echo "patched $dest"
else
    echo "copied pristine source to $dest"
fi
