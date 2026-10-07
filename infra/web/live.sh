#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# The web container in live development (compose.live.yaml): dependencies installed when the lock file changed, the
# TypeScript types generated from the running API's contract, then the Angular dev server, which rebuilds and reloads
# the page on every edit. It proxies the API and Centrifugo as nginx does (web/proxy.live.json).
set -eu
cd /app

stamp=node_modules/.twes-lock-sum
sum=$(sha256sum package-lock.json | cut -d ' ' -f 1)
if [ ! -f "$stamp" ] || [ "$(cat "$stamp")" != "$sum" ]; then
	npm ci --no-audit --no-fund
	echo "$sum" >"$stamp"
fi

# The contract the API serves now: a restart of this container brings the types up to date with it.
wget -q -O /tmp/openapi.json http://api/api/docs.jsonopenapi
OPENAPI_JSON=/tmp/openapi.json npm run api:types

# The build `make up` started from (scripts/build-version.sh), as the image writes it: the source then moves on under it.
printf '{"version":"%s","commit":"%s"}\n' "${WEB_BUILD_VERSION:-}" "${WEB_BUILD_COMMIT:-}" >public/version.json

exec npm start -- --host 0.0.0.0 --port 80 --proxy-config proxy.live.json
