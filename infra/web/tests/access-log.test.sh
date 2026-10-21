#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The web server's nginx.conf, run for real in the image the web container uses: a link's token (an invitation, a
# sign-up, a password reset, a mail's stop link, 64 hexadecimal characters in the path) is a password while it lives,
# so the access log keeps where a request went with the token written [token], in the request line and in the Referer
# a page under such an address sends with its own requests.
set -uo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
image=$(sed -n 's/^FROM \(nginx:[^ ]*\).*/\1/p' "$here/Dockerfile")
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (got '$2', wanted '$3')"; fi; }
command -v docker >/dev/null || { echo "docker is not installed"; exit 1; }
echo "web nginx: no link token in the access log ($image)"
check "the web image's nginx is found in its Dockerfile" "$([[ -n "$image" ]] && echo yes)" yes

id=twes-log-$$
dir=$(mktemp -d)
cleanup() { docker rm -f "$id-web" "$id-api" >/dev/null 2>&1; docker network rm "$id" >/dev/null 2>&1; rm -rf "$dir"; }
trap cleanup EXIT
printf 'server { listen 80; access_log off; location / { return 200 "ok"; } }\n' > "$dir/echo.conf"
docker pull -q "$image" >/dev/null
docker network create "$id" >/dev/null
docker run -d --name "$id-api" --network "$id" --network-alias api --network-alias centrifugo \
    -v "$dir/echo.conf:/etc/nginx/conf.d/default.conf:ro" "$image" >/dev/null
docker run -d --name "$id-web" --network "$id" --network-alias web \
    -v "$here/nginx.conf:/etc/nginx/conf.d/default.conf:ro" "$image" >/dev/null
for _ in $(seq 1 50); do
    docker exec "$id-api" wget -qO- http://web/api/ready >/dev/null 2>&1 && break
    sleep 0.2
done

first=$(printf '9f3a%.0s' $(seq 1 16))
second=$(printf 'c0de%.0s' $(seq 1 16))
upper=$(tr 'a-f' 'A-F' <<<"$first")
asked() { docker exec "$id-api" wget -qO- ${2:+--header "Referer: $2"} "http://web$1" >/dev/null 2>&1; }
asked "/api/invitations/$first/accept"
asked "/api/twes/x" "http://web/invitation/$upper"
asked "/api/signup/$first/complete?next=$second"
asked "/reset-password/$second"
sleep 0.5
logged=$(docker logs "$id-web" 2>/dev/null)

check "the requests were logged" "$(grep -c '/api/twes/x' <<<"$logged")" 1
check "no token in any line, in any case" "$(grep -ciE "$first|$second" <<<"$logged")" 0
check "the path keeps everything but the token" "$(grep -c '"GET /api/invitations/\[token\]/accept HTTP/1.1"' <<<"$logged")" 1
check "a Referer under a token's page is masked" "$(grep -c '"http://web/invitation/\[token\]"' <<<"$logged")" 1
check "two tokens on one line are both masked" "$(grep -c '"GET /api/signup/\[token\]/complete?next=\[token\] HTTP/1.1"' <<<"$logged")" 1
check "a page's own address is masked too" "$(grep -c '"GET /reset-password/\[token\] HTTP/1.1"' <<<"$logged")" 1
check "a hashed bundle name is no token and stays" "$(asked /main-AB12cd34.js; sleep 0.3; docker logs "$id-web" 2>/dev/null | grep -c '/main-AB12cd34.js')" 1

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
