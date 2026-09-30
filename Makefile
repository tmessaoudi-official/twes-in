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
export LAN_HOST LAN_ORIGIN COMPOSE_PROFILES COMPOSE_FILE
.PHONY: up up-images live-refresh down reset logs migrate seed fixtures operator-code versions scale-data api-openapi api-types gate gate-api gate-web gate-licences test-api test-web e2e gallery notices

up:            ## start the whole stack LIVE: an edit under api/ or web/ shows without a rebuild (web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094, a phone's HTTPS door :8443), then seed
	@# The live volumes mount inside the host's api/ and web/; made here, as this user, or Docker makes them as root.
	mkdir -p api/vendor api/var web/node_modules web/.angular
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
	cd api && bin/console api:openapi:export --output=var/openapi.json

api-types: api-openapi   ## regenerate web/src/app/api (types only, gitignored)
	cd web && npm run api:types

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
	bash scripts/versions.sh

logs:
	docker compose logs -f --tail=100

gate: gate-licences gate-api gate-web   ## everything CI checks except e2e

gate-licences:
	bash scripts/gates/tests/dependency-licences.test.sh
	bash scripts/gates/tests/spdx-headers.test.sh
	bash scripts/gates/tests/executable-bits.test.sh
	bash scripts/gates/tests/icon-buttons-named.test.sh
	bash scripts/gates/tests/outcomes-as-toasts.test.sh
	bash scripts/gates/tests/compose-log-rotation.test.sh
	bash scripts/gates/tests/version-pins.test.sh
	bash scripts/gates/tests/design-tokens.test.sh
	bash scripts/gates/tests/permission-labels.test.sh
	bash scripts/gates/tests/planned-module-labels.test.sh
	bash scripts/gates/tests/setting-labels.test.sh
	bash scripts/gates/tests/presentation-settings-parity.test.sh
	bash scripts/gates/tests/tour-anchors.test.sh
	bash scripts/gates/tests/stored-items.test.sh
	bash scripts/gates/tests/production-image.test.sh
	bash scripts/gates/tests/icons-declared.test.sh
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
	bash scripts/gates/design-tokens.sh
	bash scripts/gates/permission-labels.sh
	bash scripts/gates/planned-module-labels.sh
	bash scripts/gates/setting-labels.sh
	bash scripts/gates/presentation-settings-parity.sh
	bash scripts/gates/tour-anchors.sh
	bash scripts/gates/stored-items.sh
	bash scripts/gates/icons-declared.sh

gate-api:      ## needs the postgres service up (make up, or docker compose up -d postgres)
	cd api && composer gate

gate-web: api-openapi   ## npm run gate starts with api:types, which reads api/var/openapi.json
	cd web && npm run gate

test-api:
	cd api && vendor/bin/phpunit

test-web:
	cd web && npx ng test --watch=false

e2e:           ## needs the full stack up
	cd web && npx playwright test

gallery:       ## every screen, desktop and phone, light and dark, into var/claude/gallery (needs the full stack up and make fixtures; GALLERY_COMPANY picks the company)
	cd web && npx playwright test -c playwright.gallery.config.ts

notices:       ## regenerate THIRD-PARTY-NOTICES.md after any dependency change
	php scripts/notices/generate-third-party-notices.php
