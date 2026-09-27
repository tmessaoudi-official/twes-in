#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The web server's nginx.conf, run for real in the image the web container uses, in front of a stand-in API that
# answers with the X-Forwarded-Proto it was given. Behind a TLS proxy (the LAN door) the browser's scheme must reach
# the API, or every write from an https origin is refused as cross-site; a value the map does not know is not passed on.
# Every request here comes from a private address, as the LAN door's do; the untrusted branch (a public client) cannot
# be reached from this network and is not exercised.
set -uo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
image=$(sed -n 's/^FROM \(nginx:[^ ]*\).*/\1/p' "$here/Dockerfile")
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (got '$2', wanted '$3')"; fi; }
command -v docker >/dev/null || { echo "docker is not installed"; exit 1; }
echo "web nginx: the forwarded scheme ($image)"
check "the web image's nginx is found in its Dockerfile" "$([[ -n "$image" ]] && echo yes)" yes

id=twes-fwd-$$
dir=$(mktemp -d)
cleanup() { docker rm -f "$id-web" "$id-api" >/dev/null 2>&1; docker network rm "$id" >/dev/null 2>&1; rm -rf "$dir"; }
trap cleanup EXIT
printf 'server { listen 80; location / { return 200 "[$http_x_forwarded_proto]"; } }\n' > "$dir/echo.conf"
docker pull -q "$image" >/dev/null
docker network create "$id" >/dev/null
docker run -d --name "$id-api" --network "$id" --network-alias api --network-alias centrifugo \
    -v "$dir/echo.conf:/etc/nginx/conf.d/default.conf:ro" "$image" >/dev/null
docker run -d --name "$id-web" --network "$id" --network-alias web \
    -v "$here/nginx.conf:/etc/nginx/conf.d/default.conf:ro" "$image" >/dev/null
for _ in $(seq 1 50); do
    docker exec "$id-api" wget -qO- http://web/api/ >/dev/null 2>&1 && break
    sleep 0.2
done

asked() { docker exec "$id-api" wget -qO- ${1:+--header "X-Forwarded-Proto: $1"} "http://web$2" 2>/dev/null; }
check "a proxy's https reaches the API" "$(asked https /api/x)" "[https]"
check "and reaches security.txt" "$(asked https /.well-known/security.txt)" "[https]"
check "a proxy's http reaches the API" "$(asked http /api/x)" "[http]"
check "no header: the scheme the request came in on" "$(asked '' /api/x)" "[http]"
check "a value the map does not know is not passed on" "$(asked javascript /api/x)" "[http]"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
