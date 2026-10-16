#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ORDER=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/layout-order.py
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# A route list as debug:router writes it, from lines "name method path regex [requirements as JSON]", in that order.
routes() {
  python3 -I -c '
import json, sys
out = {}
for line in sys.argv[1:]:
    name, method, path, regex, *rest = line.split(" ", 4)
    out[name] = {"path": path, "pathRegex": "{^" + regex + "$}sDu", "method": method,
                 "requirements": json.loads(rest[0]) if rest else {}}
print(json.dumps(out))' "$@"
}
# Two snapshots: the OpenAPI documents and the route lists as given.
snapshots() {
  local d; d=$(mktemp -d); mkdir -p "$d/a" "$d/b"
  printf '%s\n' "$1" > "$d/a/openapi.json"; printf '%s\n' "$2" > "$d/b/openapi.json"
  printf '%s\n' "$3" > "$d/a/routes.json"; printf '%s\n' "$4" > "$d/b/routes.json"
  echo "$d"
}
run() { python3 -I "$ORDER" "$1/a" "$1/b" 2>&1; }
TAGS='{"tags":[{"name":"One"},{"name":"Two"}],"paths":{"/a":{}}}'
TURNED='{"tags":[{"name":"Two"},{"name":"One"}],"paths":{"/a":{}}}'
UUID='[0-9a-f]{8}-[0-9a-f]{4}-[13-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'
NEW="new GET /a/new /a/new"
ONE="one GET /a/{id} /a/(?P<id>$UUID) {\"id\":\"$UUID\"}"
ANY="one GET /a/{id} /a/(?P<id>[^/]++)"
B="b POST /b /b"
echo "layout-order"

R=$(routes "$NEW" "$ONE" "$B")
d=$(snapshots "$TAGS" "$TURNED" "$R" "$R")
out=$(run "$d"); check "tags in another order are the same document" $? 0 "$out" "0 route pairs changed places"

d=$(snapshots "$TAGS" '{"tags":[{"name":"One"}],"paths":{"/a":{}}}' "$R" "$R")
out=$(run "$d"); check "a tag lost is other content" $? 1 "$out" "openapi.json holds other content"

d=$(snapshots "$TAGS" "$TAGS" "$R" "$(routes "$NEW" "$B")")
out=$(run "$d"); check "a route lost is other content" $? 1 "$out" "routes.json holds other content"

d=$(snapshots "$TAGS" "$TAGS" "$R" "$(routes "$B" "$NEW" "$ONE")")
out=$(run "$d"); check "routes that cannot meet may change places" $? 0 "$out" "2 route pairs changed places, 0 of them"

d=$(snapshots "$TAGS" "$TAGS" "$R" "$(routes "$ONE" "$NEW" "$B")")
out=$(run "$d"); check "a placeholder its requirement keeps off the literal may pass it" $? 0 "$out" "1 route pairs changed places, 0 of them"

d=$(snapshots "$TAGS" "$TAGS" "$(routes "$NEW" "$ANY")" "$(routes "$ANY" "$NEW")")
out=$(run "$d"); check "two routes that match one request may not change places" $? 1 "$out" "new and one changed places and can match /a/new"

d=$(snapshots "$TAGS" "$TAGS" "$(routes "$NEW" "${ANY/GET/PUT}")" "$(routes "${ANY/GET/PUT}" "$NEW")")
out=$(run "$d"); check "routes with no method in common may change places" $? 0 "$out" "1 route pairs changed places, 0 of them"

d=$(snapshots "$TAGS" "$TAGS" "$(routes "${NEW/GET/ANY}" "${ANY/GET/PUT}")" "$(routes "${ANY/GET/PUT}" "${NEW/GET/ANY}")")
out=$(run "$d"); check "a route of any method meets every method" $? 1 "$out" "can match"

W1='one GET /a/{p} /a/(?P<p>[a-z]+) {"p":"[a-z]+"}'
W2='two GET /a/{q} /a/(?P<q>[a-z]+) {"q":"[a-z]+"}'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$W1" "$W2")" "$(routes "$W2" "$W1")")
out=$(run "$d"); check "two routes taking a word, not an identifier, meet too" $? 1 "$out" "one and two changed places and can match"

D1='one GET /a/{p} /a/(?P<p>\d+) {"p":"\\d+"}'
D2='two GET /a/{q} /a/(?P<q>\d+) {"q":"\\d+"}'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$D1" "$D2")" "$(routes "$D2" "$D1")")
out=$(run "$d"); check "two routes taking a number meet" $? 1 "$out" "one and two changed places and can match /a/1"

F1="one GET /a/{id}.{_format} /a/(?P<id>[^/\\.]++)(?:\\.(?P<_format>[^/]++))?"
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$NEW" "$F1")" "$(routes "$F1" "$NEW")")
out=$(run "$d"); check "an optional format leaves the identifier free to meet a word" $? 1 "$out" "can match /a/new"

U1='one GET /a/{p} /a/(?P<p>[a-z]{3}) {"p":"[a-z]{3}"}'
U2='two GET /a/{q} /a/(?P<q>[a-z]{4}) {"q":"[a-z]{4}"}'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$U1" "$U2")" "$(routes "$U2" "$U1")")
out=$(run "$d"); check "a pair neither met nor kept apart is not passed" $? 1 "$out" "one and two changed places and could not be told apart"

L1='one GET /a/{p} /a/(?P<p>fr|en) {"p":"fr|en"}'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$NEW" "$L1")" "$(routes "$L1" "$NEW")")
out=$(run "$d"); check "a literal outside a placeholder's list keeps them apart" $? 0 "$out" "1 route pairs changed places, 0 of them"

S1='one GET /a/{id}/b /a/(?P<id>[^/]++)/b'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$NEW" "$S1")" "$(routes "$S1" "$NEW")")
out=$(run "$d"); check "paths of other lengths keep apart" $? 0 "$out" "1 route pairs changed places, 0 of them"

O1="old GET /a/old /a/old"
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$NEW" "$O1")" "$(routes "$O1" "$NEW")")
out=$(run "$d"); check "two words keep paths of one length apart" $? 0 "$out" "1 route pairs changed places, 0 of them"

X1='one GET /a/{p}/new /a/(?P<p>[^/]++)/new'
X2='two GET /a/new/{q} /a/new/(?P<q>[^/]++)'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$X1" "$X2")" "$(routes "$X2" "$X1")")
out=$(run "$d"); check "a placeholder takes the word the other route writes there" $? 1 "$out" "can match /a/new/new"

J1='json GET /a/b.json /a/b\.json'
d=$(snapshots "$TAGS" "$TAGS" "$(routes "$F1" "$J1")" "$(routes "$J1" "$F1")")
out=$(run "$d"); check "what only the other route's side finds is found" $? 1 "$out" "can match /a/b.json"

echo "layout-order: $pass passed, $fail failed"
((fail == 0))
