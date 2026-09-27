#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Live development (compose.live.yaml, `make up`) serves the web tier with the Angular dev server instead of nginx, so
# web/proxy.live.json must send to the API and to Centrifugo every path nginx.conf sends there: a location added to one
# and not the other works in the images and answers the application's index page in live development, or the reverse.
# It must also leave Host and X-Forwarded-Proto as the browser's proxy gave them, which the API's same-origin check
# reads (infra/web/tests/forwarded-proto.test.sh covers nginx's side of that).
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
conf="$root/infra/web/nginx.conf"
proxy="$root/web/proxy.live.json"
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (got '$2', wanted '$3')"; fi; }
echo "live development: the dev server proxies what nginx proxies"

# nginx: each proxied location with the upstream it names, "path host:port"; `= ` and a trailing slash dropped.
nginx_routes=$(awk '
  /^[[:space:]]*location / { path = $2 == "=" ? $3 : $2; sub(/\/$/, "", path) }
  /proxy_pass/ && path != "" { up = $2; sub(/;$/, "", up); sub(/^http:\/\//, "", up); sub(/\/.*/, "", up); print path, up; path = "" }
' "$conf" | sort)
check "nginx.conf proxies at least the four known paths" "$(($(wc -l <<<"$nginx_routes") >= 4))" 1

if [[ ! -f "$proxy" ]]; then
  check "web/proxy.live.json exists" no yes
else
  live_routes=$(jq -r 'to_entries[] | "\(.key) \(.value.target | sub("^https?://"; "") | sub("/.*"; ""))"' "$proxy" | sort)
  check "the same paths reach the same upstreams" "$live_routes" "$nginx_routes"
  check "no route rewrites Host (changeOrigin)" "$(jq '[.[] | select(.changeOrigin == true)] | length' "$proxy")" 0
  check "no route writes its own X-Forwarded-* (xfwd)" "$(jq '[.[] | select(.xfwd == true)] | length' "$proxy")" 0
  check "the WebSocket route upgrades" "$(jq '.["/connection/websocket"].ws' "$proxy")" true
  check "security.txt reaches the API's own address" \
    "$(jq -r '.["/.well-known/security.txt"].pathRewrite["^/.well-known/security.txt"] // empty' "$proxy")" \
    "$(sed -n 's|.*proxy_pass http://api:80\(/api/legal/security.txt\);|\1|p' "$conf")"
fi

# The live override resolves on top of compose.yaml, and only the api and web services change.
if command -v docker >/dev/null; then
  out=$(cd "$root" && docker compose -f compose.yaml -f compose.live.yaml config -q 2>&1); code=$?
  check "compose.yaml + compose.live.yaml resolve without a warning" "$code:$out" "0:"
fi

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
