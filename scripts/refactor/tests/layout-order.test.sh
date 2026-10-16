#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ORDER=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/layout-order.py
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# Two snapshots: the OpenAPI document's tags and the routes as given, each file one line of JSON.
snapshots() {
  local d; d=$(mktemp -d); mkdir -p "$d/a" "$d/b"
  printf '%s\n' "$1" > "$d/a/openapi.json"; printf '%s\n' "$2" > "$d/b/openapi.json"
  printf '%s\n' "$3" > "$d/a/routes.json"; printf '%s\n' "$4" > "$d/b/routes.json"
  echo "$d"
}
run() { python3 -I "$ORDER" "$1/a" "$1/b" 2>&1; }
TAGS='{"tags":[{"name":"One"},{"name":"Two"}],"paths":{"/a":{}}}'
TURNED='{"tags":[{"name":"Two"},{"name":"One"}],"paths":{"/a":{}}}'
ROUTES='{"new":{"path":"/a/new","method":"GET"},"one":{"path":"/a/{id}","method":"GET","requirements":{"id":"[0-9a-f-]{36}"}},"b":{"path":"/b","method":"POST"}}'
echo "layout-order"

d=$(snapshots "$TAGS" "$TURNED" "$ROUTES" "$ROUTES")
out=$(run "$d"); check "tags in another order are the same document" $? 0 "$out" "0 route pairs changed places"

d=$(snapshots "$TAGS" '{"tags":[{"name":"One"}],"paths":{"/a":{}}}' "$ROUTES" "$ROUTES")
out=$(run "$d"); check "a tag lost is other content" $? 1 "$out" "openapi.json holds other content"

d=$(snapshots "$TAGS" "$TAGS" "$ROUTES" '{"new":{"path":"/a/new","method":"GET"},"b":{"path":"/b","method":"POST"}}')
out=$(run "$d"); check "a route lost is other content" $? 1 "$out" "routes.json holds other content"

d=$(snapshots "$TAGS" "$TAGS" "$ROUTES" '{"b":{"path":"/b","method":"POST"},"new":{"path":"/a/new","method":"GET"},"one":{"path":"/a/{id}","method":"GET","requirements":{"id":"[0-9a-f-]{36}"}}}')
out=$(run "$d"); check "routes that cannot meet may change places" $? 0 "$out" "2 route pairs changed places, 0 of them"

d=$(snapshots "$TAGS" "$TAGS" "$ROUTES" '{"one":{"path":"/a/{id}","method":"GET","requirements":{"id":"[0-9a-f-]{36}"}},"new":{"path":"/a/new","method":"GET"},"b":{"path":"/b","method":"POST"}}')
out=$(run "$d"); check "a placeholder its requirement keeps off the literal may pass it" $? 0 "$out" "1 route pairs changed places, 0 of them"

R='{"new":{"path":"/a/new","method":"GET"},"one":{"path":"/a/{id}","method":"GET"}}'
d=$(snapshots "$TAGS" "$TAGS" "$R" '{"one":{"path":"/a/{id}","method":"GET"},"new":{"path":"/a/new","method":"GET"}}')
out=$(run "$d"); check "two routes that match one request may not change places" $? 1 "$out" "new and one changed places"

d=$(snapshots "$TAGS" "$TAGS" '{"new":{"path":"/a/new","method":"GET"},"one":{"path":"/a/{id}","method":"PUT"}}' '{"one":{"path":"/a/{id}","method":"PUT"},"new":{"path":"/a/new","method":"GET"}}')
out=$(run "$d"); check "routes with no method in common may change places" $? 0 "$out" "1 route pairs changed places, 0 of them"

d=$(snapshots "$TAGS" "$TAGS" '{"new":{"path":"/a/new"},"one":{"path":"/a/{id}","method":"PUT"}}' '{"one":{"path":"/a/{id}","method":"PUT"},"new":{"path":"/a/new"}}')
out=$(run "$d"); check "a route of any method meets every method" $? 1 "$out" "can match the same request"

L='{"one":{"path":"/a/{p}","method":"GET","requirements":{"p":"[a-z]+"}},"two":{"path":"/a/{q}","method":"GET","requirements":{"q":"[a-z]+"}}}'
d=$(snapshots "$TAGS" "$TAGS" "$L" '{"two":{"path":"/a/{q}","method":"GET","requirements":{"q":"[a-z]+"}},"one":{"path":"/a/{p}","method":"GET","requirements":{"p":"[a-z]+"}}}')
out=$(run "$d"); check "two routes taking a word, not an identifier, meet too" $? 1 "$out" "one and two changed places"

echo "layout-order: $pass passed, $fail failed"
((fail == 0))
