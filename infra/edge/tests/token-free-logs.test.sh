#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every Caddy of the tree (the deployment's edge, a shop's, the phone door, the monitor's, and FrankenPHP's own in the
# api image), run in the image compose names: a link's token (64 hexadecimal characters in the path, the query, or the
# Referer a page under such an address sends) is a password while it lives, and Caddy's error line on a failed upstream
# writes the whole request. Each config filters its logs to [token], the same way, and the edge proves it for real.
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
image=$(sed -n 's/^ *image: \(caddy:[^ ]*\)$/\1/p' "$root/compose.deploy.yaml")
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (got '$2', wanted '$3')"; fi; }
command -v docker >/dev/null || { echo "docker is not installed"; exit 1; }
command -v jq >/dev/null || { echo "jq is not installed"; exit 1; }
echo "Caddy: no link token in any log line ($image)"
check "the edge's Caddy image is found in compose.deploy.yaml" "$([[ -n "$image" ]] && echo yes)" yes

id=twes-caddy-log-$$
dir=$(mktemp -d)
cleanup() { docker rm -f "$id" >/dev/null 2>&1; docker network rm "$id" >/dev/null 2>&1; rm -rf "$dir"; }
trap cleanup EXIT
docker pull -q "$image" >/dev/null

# Every Caddyfile tracked is one of the four below: a fifth would escape this test.
check "the Caddyfiles of the tree are the four checked here" \
    "$(git -C "$root" ls-files '*Caddyfile' | sort | tr '\n' ' ')" \
    "infra/edge/Caddyfile infra/lan/Caddyfile infra/monitor/Caddyfile infra/onprem/Caddyfile "

# FrankenPHP's own Caddyfile takes its global options from CADDY_GLOBAL_OPTIONS, which the api image sets to import
# this file (infra/api/Dockerfile; scripts/gates/production-image.php reads it in the built image).
printf '{\n\timport /etc/caddy/api-log-filter.caddy\n}\n:80 {\n\trespond ok\n}\n' > "$dir/api.Caddyfile"
adapted() {
    docker run --rm -e TWES_HOST=x.test -e EDGE_TLS=internal -e LAN_HOST=192.0.2.1 -e STATUS_HOST=s.test \
        -e ERRORS_HOST=e.test -v "$1:/etc/caddy/Caddyfile:ro" \
        -v "$root/infra/api/log-filter.caddy:/etc/caddy/api-log-filter.caddy:ro" \
        "$image" caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile 2>/dev/null \
        | jq -cS '.logging.logs.default.encoder // "none"'
}
wanted='{"fields":{"request>headers>Referer":{"filter":"regexp","regexp":"[0-9A-Fa-f]{32,}","value":"[token]"},"request>headers>X-Sentry-Auth":{"filter":"regexp","regexp":"[0-9A-Fa-f]{32,}","value":"[token]"},"request>uri":{"filter":"regexp","regexp":"[0-9A-Fa-f]{32,}","value":"[token]"}},"format":"filter"}'
for file in infra/edge/Caddyfile infra/onprem/Caddyfile infra/lan/Caddyfile infra/monitor/Caddyfile; do
    check "$file filters every token from its logs" "$(adapted "$root/$file")" "$wanted"
done
check "the api image's Caddy filters every token from its logs" "$(adapted "$dir/api.Caddyfile")" "$wanted"

# The edge for real, its upstream gone: the error line is written, and nothing secret in it.
docker network create "$id" >/dev/null
docker run -d --name "$id" --network "$id" -e TWES_HOST=x.test -e EDGE_TLS=internal \
    -v "$root/infra/edge/Caddyfile:/etc/caddy/Caddyfile:ro" "$image" >/dev/null
token=$(printf '9f3a%.0s' $(seq 1 16))
other=$(printf 'C0DE%.0s' $(seq 1 16))
answered=000
for _ in $(seq 1 50); do
    answered=$(docker exec "$id" curl -sk -o /dev/null -w '%{http_code}' --resolve x.test:443:127.0.0.1 \
        -H "Referer: https://x.test/invitation/$token" -H "Cookie: twes_session=session-value-1" \
        -H "Authorization: Bearer bearer-value-2" -H "X-Sentry-Auth: Sentry sentry_key=$other" \
        "https://x.test/api/invitations/$token/accept?next=$other" 2>/dev/null)
    [[ "$answered" == 502 ]] && break
    sleep 0.2
done
check "the edge answers 502 with its upstream gone" "$answered" 502
sleep 0.5
# Both streams: Caddy writes its log to stderr.
logged=$(docker logs "$id" 2>&1)
check "the failed upstream is logged" "$(grep -c '"logger":"http.log.error"' <<<"$logged")" 1
check "no token in any line, in any case" "$(grep -ciE "$token|$other" <<<"$logged")" 0
check "the address keeps everything but the tokens" \
    "$(grep -c '"uri":"/api/invitations/\[token\]/accept?next=\[token\]"' <<<"$logged")" 1
check "the Referer is masked" "$(grep -c '"Referer":\["https://x.test/invitation/\[token\]"\]' <<<"$logged")" 1
check "no cookie and no bearer in any line" "$(grep -cE 'session-value-1|bearer-value-2' <<<"$logged")" 0

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
