#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The web server's nginx.conf, run for real in the image the web container uses: its answers name no nginx version (a
# scanner reads one as a list of advisories to try), and every page denies the browser features the application never
# calls, leaving those it does (the camera, full screen, the clipboard, the wake lock, passkeys) at the browser's own
# default, this origin only. Each answer is read: a page, a proxied API answer and a page that is not there.
set -uo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
image=$(sed -n 's/^FROM \(nginx:[^ ]*\).*/\1/p' "$here/Dockerfile")
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (got '$2', wanted '$3')"; fi; }
command -v docker >/dev/null || { echo "docker is not installed"; exit 1; }
echo "web nginx: the response headers ($image)"
check "the web image's nginx is found in its Dockerfile" "$([[ -n "$image" ]] && echo yes)" yes

id=twes-hdr-$$
dir=$(mktemp -d) || exit 1
cleanup() { docker rm -f "$id-web" "$id-api" >/dev/null 2>&1; docker network rm "$id" >/dev/null 2>&1; rm -rf "$dir"; }
trap cleanup EXIT
printf 'server { listen 80; location / { return 200 "api"; } }\n' > "$dir/echo.conf"
printf '<!doctype html><title>t</title>\n' > "$dir/index.html"
docker pull -q "$image" >/dev/null
docker network create "$id" >/dev/null
docker run -d --name "$id-api" --network "$id" --network-alias api --network-alias centrifugo \
    -v "$dir/echo.conf:/etc/nginx/conf.d/default.conf:ro" "$image" >/dev/null
docker run -d --name "$id-web" --network "$id" --network-alias web \
    -v "$here/nginx.conf:/etc/nginx/conf.d/default.conf:ro" \
    -v "$dir/index.html:/usr/share/nginx/html/index.html:ro" "$image" >/dev/null
for _ in $(seq 1 50); do
    docker exec "$id-api" wget -qO- http://web/api/ >/dev/null 2>&1 && break
    sleep 0.2
done

# The headers of one answer, lower-cased names, whatever its status (wget -S prints them, -O /dev/null drops the body).
headers() { docker exec "$id-api" wget -S -O /dev/null "http://web$1" 2>&1 | sed -n 's/^  \([A-Za-z-]*\): \(.*\)$/\L\1\E: \2/p'; }
header() { headers "$1" | sed -n "s/^$2: //p" | head -n 1; }
denied='accelerometer=(), bluetooth=(), browsing-topics=(), display-capture=(), geolocation=(), gyroscope=(), hid=(), magnetometer=(), microphone=(), midi=(), payment=(), serial=(), usb=(), xr-spatial-tracking=()'

for path in / /api/x /not-there.js; do
    check "$path: the server names no version" "$(header "$path" server)" nginx
    check "$path: the features never called are denied" "$(header "$path" permissions-policy)" "$denied"
done

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
