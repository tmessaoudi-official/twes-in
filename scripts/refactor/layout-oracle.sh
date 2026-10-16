#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a move of classes must leave as it was, taken before and after and compared: the OpenAPI document the web's
# types come from, the routes, the Doctrine mapping and the schema it would create, and a container that still lints.
# A class keeps its short name through a move, so a fully-qualified name is read as its short name here: a difference
# left after that is a resource, a route or an entity that fell out of discovery. Run in the PHP toolchain:
#   make tools CMD='bash scripts/refactor/layout-oracle.sh snapshot var/claude/layout/before'
#   make tools CMD='bash scripts/refactor/layout-oracle.sh compare var/claude/layout/before var/claude/layout/after'
# The API Platform metadata pools outlive a cache clear, so snapshot clears them too (CLAUDE.md, Lessons).
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

# Every App\… name as its short name, so only what discovery found or lost remains.
short_names() { sed -E 's/App(\\\\|\\)([A-Za-z0-9_]+(\\\\|\\))*([A-Za-z0-9_]+)/\4/g'; }

snapshot() {
  local out=$1
  [[ "$out" == /* ]] || out="$root/$out"
  mkdir -p "$out" || return 2
  cd "$root/api" || return 2
  bin/console cache:clear -q && bin/console cache:pool:clear --all -q \
    || { echo "layout-oracle: the cache did not clear" >&2; return 2; }
  bin/console lint:container -q || { echo "layout-oracle: the container does not lint" >&2; return 1; }
  bin/console api:openapi:export --output="$out/openapi.json" -q || { echo "layout-oracle: no OpenAPI export" >&2; return 2; }
  bin/console debug:router --format=json | short_names > "$out/routes.json" \
    || { echo "layout-oracle: no route list" >&2; return 2; }
  bin/console doctrine:mapping:info | grep -E '^\s*\[OK\]' | short_names | sort > "$out/entities.txt"
  [[ -s "$out/entities.txt" ]] || { echo "layout-oracle: no mapped entity read" >&2; return 2; }
  bin/console doctrine:schema:create --dump-sql > "$out/schema.sql" || { echo "layout-oracle: no schema" >&2; return 2; }
  [[ -s "$out/schema.sql" ]] || { echo "layout-oracle: an empty schema" >&2; return 2; }
  printf 'layout-oracle: snapshot in %s: %s entities, %s routes\n' "$out" "$(wc -l < "$out/entities.txt")" \
    "$(grep -c '"path"' "$out/routes.json")"
}

compare() {
  local before=$1 after=$2 file differ=0
  [[ "$before" == /* ]] || before="$root/$before"
  [[ "$after" == /* ]] || after="$root/$after"
  for file in openapi.json routes.json entities.txt schema.sql; do
    [[ -s "$before/$file" && -s "$after/$file" ]] || { echo "layout-oracle: $file is missing from a snapshot" >&2; return 2; }
    if ! cmp -s "$before/$file" "$after/$file"; then
      echo "layout-oracle: $file differs:"
      diff "$before/$file" "$after/$file" | head -n 20
      differ=1
    fi
  done
  ((differ == 0)) && echo "layout-oracle: OK, the OpenAPI document, the routes, the entities and the schema are unchanged"
  return "$differ"
}

case "${1:-}" in
  snapshot) [[ $# -eq 2 ]] || { echo "usage: $0 snapshot <dir>" >&2; exit 2; }; snapshot "$2" ;;
  compare) [[ $# -eq 3 ]] || { echo "usage: $0 compare <before> <after>" >&2; exit 2; }; compare "$2" "$3" ;;
  *) echo "usage: $0 snapshot <dir> | compare <before> <after>" >&2; exit 2 ;;
esac
