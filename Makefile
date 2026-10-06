# Developer entry points. Everything here is also what CI runs (.github/workflows/ci.yml).
SHELL := /bin/sh

# A phone reaches the stack at this machine's address on the local network, over HTTPS (docs/SPEC.md § 7,
# 2026-09-23 14:08): the source address of the default route. With no route (offline, or no `ip`, as on macOS) it is
# empty, and the lan service stays off. Override with LAN_HOST=… make up.
ifndef LAN_HOST
LAN_HOST := $(shell ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')
endif
LAN_ORIGIN := $(if $(LAN_HOST),https://$(LAN_HOST):$(or $(LAN_PORT),8443))
COMPOSE_PROFILES ?= $(if $(LAN_HOST),lan)
# Live development by default (compose.live.yaml, docs/START.md § 1): every docker compose command below, and any a
# recipe starts, sees the same services. Never set in .env, which a plain `docker compose` (CI's) reads too.
COMPOSE_FILE ?= compose.yaml:compose.live.yaml
# Everything that parses, tests or builds the project runs in a container, never on the host's PHP, Composer or Node
# (docs/SPEC.md § 7): the host needs Docker, make, bash and git, nothing else. The services are `tools` (PHP, the gates'
# own tools, the Docker CLI) and `web-tools` (Node, Playwright's Chromium), compose.yaml, profile `tools`. They run as
# the host user, with the working tree mounted at its own path (see compose.yaml for why); these are what they read.
HOST_REPO := $(CURDIR)
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
DOCKER_GID := $(shell stat -c %g /var/run/docker.sock 2>/dev/null)
TOOLS_TMP := /tmp/twes-in-tools-$(HOST_UID)
export HOST_REPO HOST_UID HOST_GID DOCKER_GID TOOLS_TMP
export LAN_HOST LAN_ORIGIN COMPOSE_PROFILES COMPOSE_FILE
# A directory of the host's own for the toolchain's temporary files, made here as this user or Docker makes it as root.
TOOLS := mkdir -p $(TOOLS_TMP) && docker compose --progress quiet --profile tools run --rm -T tools
WEB_TOOLS := mkdir -p $(TOOLS_TMP) && docker compose --progress quiet --profile tools run --rm -T web-tools
# `npm ci` only when package-lock.json is newer than the last one that finished here.
NPM_INSTALL := [ node_modules/.twes-installed -nt package-lock.json ] || { npm ci --no-audit --no-fund && touch node_modules/.twes-installed; }
.PHONY: playwright-browser tools web-tools php-lint up up-images live-refresh down reset logs migrate seed fixtures operator-code versions scale-data api-openapi api-types gate gate-api gate-web gate-licences test-api test-web e2e gallery notices tools-image web-tools-image in-gate-licences in-gate-api in-test-api in-api-openapi in-versions in-notices

php-lint:      ## php -l FILE=<path> in the tools container: what .claude/hooks/lint-on-write.sh runs after every edit
	@$(TOOLS) php -l $(FILE)

tools:         ## CMD='…' in the PHP toolchain container, from the repo root: what used to run on the host's PHP, e.g. make tools CMD='cd api && bin/console cache:clear'
tools: tools-image
	$(TOOLS) sh -c '$(CMD)'

web-tools:     ## CMD='…' in the Node toolchain container, from web/: e.g. make web-tools CMD='npm run icons'
web-tools: web-tools-image
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && $(CMD)'

tools-image:   ## build the PHP toolchain image (a no-op when nothing under infra/api changed)
	docker compose --profile tools build -q tools

web-tools-image: ## build the Node + Playwright toolchain image
	docker build -q --load -f infra/web/tools.Dockerfile -t twes-in-web-tools infra/web >/dev/null

up:            ## start the whole stack LIVE: an edit under api/ or web/ shows without a rebuild (web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094, a phone's HTTPS door :8443), then seed
	@# The live volumes mount inside the host's api/ and web/; made here, as this user, or Docker makes them as root.
	mkdir -p api/vendor api/var web/node_modules web/.angular var/tmp var/cache
	docker compose up -d --build --wait
	$(MAKE) seed
	@echo "On this computer: http://localhost:$${WEB_PORT:-8090}"
	@$(if $(LAN_ORIGIN),echo "From a phone on this network: $(LAN_ORIGIN) (trust once: http://$(LAN_HOST):8095/root.crt)",echo "No network address found: the phone door is off.")

up-images:     ## the same stack from the built images, exactly as CI runs it: nginx, the static bundle, no watcher. Every later make target follows it only with COMPOSE_FILE=compose.yaml
	$(MAKE) up COMPOSE_FILE=compose.yaml

live-refresh:  ## after changing an API resource's properties under make up: empties the API's metadata caches, restarts its workers and regenerates the web tier's types from what the API now serves
	docker compose exec -T api sh -c 'bin/console cache:pool:clear --all && curl -fsS -o /dev/null -X POST http://localhost:2019/frankenphp/workers/restart'
	docker compose exec -T web sh -c 'wget -q -O /tmp/openapi.json http://api/api/docs.jsonopenapi && OPENAPI_JSON=/tmp/openapi.json npm run api:types'

migrate:       ## apply pending migrations inside a running api container (the image entrypoint already did at start)
	docker compose exec -T api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

seed:          ## built-in roles, the operator (operator@twes.local) with a known authenticator and the Demo company; idempotent. Dev secrets only.
	docker compose exec -T api bin/console app:seed --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP

fixtures:      ## the demo companies Carthage Conseil (TN) and Atelier Mercier (FR), five months of activity written through the use cases; after make seed; appends, never empties the database; a company already there is left as it is
	docker compose exec -T api bin/console doctrine:fixtures:load --append --no-interaction

# A large company, in its own database (docs/SPEC.md § 7, 2026-09-27, row 181). SIZE is the invoice count: 100k, 1m, 5m, 10m.
SIZE ?= 100k
SCALE_INVOICES := $(subst m,000000,$(subst k,000,$(SIZE)))
scale-data:    ## SIZE=100k|1m|5m|10m: a large company (the demo's Carthage Conseil, grown by cloning its invoice graph) in its own database twes_scale; the development data is never touched; resumable, so run it again if it stops
	printf '%s\n' "SELECT 'CREATE DATABASE twes_scale OWNER twes' WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'twes_scale')\\gexec" | docker compose exec -T postgres psql -U twes -d postgres
	docker compose exec -T -e DATABASE_URL="postgresql://twes:$${POSTGRES_PASSWORD:-twes}@postgres:5432/twes_scale?serverVersion=18" api sh -c 'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration && bin/console app:seed --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP && bin/console doctrine:fixtures:load --append --no-interaction && bin/console app:scale:generate --company="Carthage Conseil" --invoices=$(SCALE_INVOICES)'
	@# The planner's statistics are what the first measurement reads: without them page 1 of the invoices read 3 to 4 s at 100k, 0.8 s after.
	printf '%s\n' 'VACUUM (ANALYZE)' | docker compose exec -T postgres psql -U twes -d twes_scale

operator-code: ## the seeded operator's authenticator code, with how long it lives (a wrong, reused or EXPIRED one spends the 5-per-5-minutes budget e2e also spends)
	docker compose exec -T api php -r 'require "vendor/autoload.php"; $$left = 30 - (time() % 30); if ($$left < 12) { fwrite(STDERR, "waiting {$$left}s: the code now would expire while you type it\n"); sleep($$left); $$left = 30; } echo (new App\Identity\Infrastructure\Mfa\OtphpTotpCodes())->codeAt("JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP", new DateTimeImmutable()), "  (valid {$$left}s)", PHP_EOL;'

api-openapi:   ## export the OpenAPI document the TypeScript types are generated from
api-openapi: tools-image
	$(TOOLS) make --no-print-directory in-api-openapi
in-api-openapi:
	mkdir -p api/vendor api/var var/tmp var/cache
	cd api && composer install --no-interaction --no-progress --prefer-dist
	cd api && bin/console api:openapi:export --output=var/openapi.json

api-types: api-openapi web-tools-image   ## regenerate web/src/app/api (types only, gitignored)
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npm run api:types'

down:          ## stop it, keep the database volume
	docker compose down

reset:         ## DESTRUCTIVE clean start (docs/START.md): deletes the database and stored files (both volumes), the host caches and the e2e session, then make up. Asks first; CONFIRM=yes skips the question.
	@if [ "$(CONFIRM)" != yes ]; then \
		printf 'make reset deletes the database and the stored files (issued PDFs) of compose project %s, api/var/cache and the saved e2e session. Type yes to go on: ' "$${COMPOSE_PROJECT_NAME:-twes-in}"; \
		read -r answer; [ "$$answer" = yes ] || { echo 'Nothing deleted.'; exit 1; }; \
	fi
	docker compose down --volumes --remove-orphans
	rm -rf api/var/cache api/var/openapi.json web/src/app/api web/playwright/.auth
	$(MAKE) up

versions:      ## every version pin, read from the file that holds it (docs/UPDATE.md says where each is copied and how to bump it)
versions: tools-image
	$(TOOLS) make --no-print-directory in-versions
in-versions:
	bash scripts/versions.sh

logs:
	docker compose logs -f --tail=100

gate: gate-licences gate-api gate-web   ## everything CI checks except e2e

gate-licences: tools-image
	$(TOOLS) make --no-print-directory in-gate-licences
gate-stamps: tools-image   ## the Decisions Log stamps alone: what a push touching only docs/SPEC.md is checked by
	$(TOOLS) make --no-print-directory in-gate-stamps
in-gate-stamps:
	bash scripts/gates/tests/decision-stamps.test.sh
	bash scripts/gates/decision-stamps.sh
in-gate-licences:
	bash scripts/gates/tests/dependency-licences.test.sh
	bash scripts/gates/tests/spdx-headers.test.sh
	bash scripts/gates/tests/executable-bits.test.sh
	bash scripts/gates/tests/icon-buttons-named.test.sh
	bash scripts/gates/tests/outcomes-as-toasts.test.sh
	bash scripts/gates/tests/compose-log-rotation.test.sh
	bash scripts/gates/tests/version-pins.test.sh
	bash scripts/gates/tests/host-tools.test.sh
	bash scripts/gates/tests/design-tokens.test.sh
	bash scripts/gates/tests/permission-labels.test.sh
	bash scripts/gates/tests/planned-module-labels.test.sh
	bash scripts/gates/tests/setting-labels.test.sh
	bash scripts/gates/tests/presentation-settings-parity.test.sh
	bash scripts/gates/tests/tour-anchors.test.sh
	bash scripts/gates/tests/coming-gated.test.sh
	bash scripts/gates/tests/live-reload.test.sh
	bash scripts/gates/tests/stored-items.test.sh
	bash scripts/gates/tests/production-image.test.sh
	bash scripts/gates/tests/icons-declared.test.sh
	bash scripts/gates/tests/float-casts.test.sh
	bash scripts/gates/tests/decision-stamps.test.sh
	bash infra/self-hosted/tests/logrotate.test.sh
	bash infra/web/tests/forwarded-proto.test.sh
	bash infra/web/tests/live-proxy.test.sh
	php scripts/gates/dependency-licences.php
	bash scripts/gates/spdx-headers.sh
	bash scripts/gates/executable-bits.sh
	bash scripts/gates/icon-buttons-named.sh
	bash scripts/gates/outcomes-as-toasts.sh
	bash scripts/gates/compose-log-rotation.sh
	bash scripts/gates/version-pins.sh
	bash scripts/gates/host-tools.sh
	bash scripts/gates/design-tokens.sh
	bash scripts/gates/permission-labels.sh
	bash scripts/gates/planned-module-labels.sh
	bash scripts/gates/setting-labels.sh
	bash scripts/gates/presentation-settings-parity.sh
	bash scripts/gates/tour-anchors.sh
	bash scripts/gates/coming-gated.sh
	bash scripts/gates/live-reload.sh
	bash scripts/gates/stored-items.sh
	bash scripts/gates/icons-declared.sh
	bash scripts/gates/float-casts.sh
	bash scripts/gates/decision-stamps.sh

gate-api:      ## the postgres service is started for it (the tests need a real database)
gate-api: tools-image
	docker compose up -d --wait postgres
	$(TOOLS) make --no-print-directory in-gate-api
in-gate-api:
	mkdir -p api/vendor api/var var/tmp var/cache
	cd api && composer install --no-interaction --no-progress --prefer-dist
	cd api && composer gate

gate-web: api-openapi web-tools-image   ## npm run gate starts with api:types, which reads api/var/openapi.json
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npm run gate'

test-api: tools-image
	docker compose up -d --wait postgres
	$(TOOLS) make --no-print-directory in-test-api
in-test-api:
	cd api && vendor/bin/phpunit

test-web: web-tools-image
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx ng test --watch=false'

E2E_ARGS ?=
# The browser build the project's own @playwright/test names (the image installs the same version: scripts/gates/version-pins.sh),
# kept in var/cache/ms-playwright (gitignored). Downloaded on Docker's default network, NOT the host's the toolchain runs on:
# on the host network the download timed out in every try here (this machine's IPv6 route to Google's storage is dead,
# docs/START.md); on the default network it took 23 s (measured 2026-10-03, no environment variable needed).
playwright-browser: web-tools-image
	mkdir -p var/cache/ms-playwright
	docker run --rm -u $(HOST_UID):$(HOST_GID) -e HOME=/tmp -e PLAYWRIGHT_BROWSERS_PATH=$(HOST_REPO)/var/cache/ms-playwright -v $(HOST_REPO)/var/cache/ms-playwright:$(HOST_REPO)/var/cache/ms-playwright twes-in-web-tools playwright install chromium >/dev/null

e2e: playwright-browser   ## needs the full stack up; E2E_ARGS passes options through, e.g. E2E_ARGS=--shard=1/3
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx playwright test $(E2E_ARGS)'

gallery: playwright-browser   ## every screen, desktop and phone, light and dark, into var/claude/gallery (needs the full stack up and make fixtures; GALLERY_COMPANY picks the company)
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx playwright test -c playwright.gallery.config.ts'

notices: tools-image   ## regenerate THIRD-PARTY-NOTICES.md after any dependency change
	$(TOOLS) make --no-print-directory in-notices
in-notices:
	php scripts/notices/generate-third-party-notices.php
