#!/usr/bin/env bash
# Prints the test matrix of ci.yml as `matrix=<json>` for $GITHUB_OUTPUT:
# Core's suite runs against the latest Extension (this checkout's
# packages/php-oracle-ext, NTS and ZTS) and against the declared minimum
# (spec #32 §15), which keeps that minimum honest.
#
# The minimum is Core's `ext-oracledb` constraint (`^0.1` → 0.1.0). Its
# Extension is built from the release tag `php-oracle-ext-v<minimum>`:
# - the tag exists: one more leg (NTS) builds the Extension from it;
# - no tag, and the minimum is this checkout's Extension version: the
#   minimum is not released yet and the latest legs already test it;
# - no tag, and another version: fail. The Extension is always released
#   before the Core release that raises the minimum.
#
# MINIMUM_REF (the workflow_dispatch input `minimum-ref`) forces the minimum
# leg to build the Extension from that ref instead, to try the mechanism.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
constraint=$(jq -r '.require["ext-oracledb"] // empty' "$root/packages/php-oracle/composer.json")
if [[ ! $constraint =~ ^\^([0-9]+)\.([0-9]+)(\.([0-9]+))?$ ]]; then
  echo "::error::Core's ext-oracledb constraint must be ^X.Y or ^X.Y.Z, got '$constraint'" >&2
  exit 1
fi
minimum="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[4]:-0}"
latest=$(sed -n 's/^version = "\(.*\)"$/\1/p' "$root/packages/php-oracle-ext/Cargo.toml" | head -n 1)
tag="php-oracle-ext-v$minimum"

legs='[{"ts":"nts","extension":"latest","ref":""},{"ts":"zts","extension":"latest","ref":""}]'
ref=""
if [[ -n ${MINIMUM_REF:-} ]]; then
  ref=$MINIMUM_REF
elif git ls-remote --exit-code --tags origin "refs/tags/$tag" > /dev/null; then
  ref="refs/tags/$tag"
elif [[ $minimum == "$latest" ]]; then
  echo "::notice::The minimum Extension, $minimum, is this checkout's and has no release tag ($tag) yet: the latest legs test it." >&2
else
  echo "::error::Core needs oracledb >= $minimum, but there is no tag $tag and this checkout's Extension is $latest. Release the Extension first." >&2
  exit 1
fi
if [[ -n $ref ]]; then
  legs=$(jq -c --arg minimum "$minimum" --arg ref "$ref" '. + [{"ts":"nts","extension":"minimum \($minimum)","ref":$ref}]' <<< "$legs")
fi

echo "minimum $minimum, latest $latest" >&2
echo "matrix={\"include\":$legs}"
