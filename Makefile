# Developer entry points. Everything here is also what CI runs (.github/workflows/ci.yml).
SHELL := /bin/sh
.PHONY: up down reset logs migrate seed fixtures operator-code versions api-openapi api-types gate gate-api gate-web gate-licences test-api test-web e2e gallery notices

up:            ## build and start the whole stack (web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094), then seed
	docker compose up -d --build --wait
	$(MAKE) seed

migrate:       ## apply pending migrations inside a running api container (the image entrypoint already did at start)
	docker compose exec -T api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

seed:          ## built-in roles, the operator (operator@twes.local) with a known authenticator and the Demo company; idempotent. Dev secrets only.
	docker compose exec -T api bin/console app:seed --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP

fixtures:      ## the demo companies Carthage Conseil (TN) and Atelier Mercier (FR), five months of activity written through the use cases; after make seed; appends, never empties the database; a company already there is left as it is
	docker compose exec -T api bin/console doctrine:fixtures:load --append --no-interaction

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
	bash scripts/gates/tests/setting-labels.test.sh
	bash infra/self-hosted/tests/logrotate.test.sh
	php scripts/gates/dependency-licences.php
	bash scripts/gates/spdx-headers.sh
	bash scripts/gates/executable-bits.sh
	bash scripts/gates/icon-buttons-named.sh
	bash scripts/gates/outcomes-as-toasts.sh
	bash scripts/gates/compose-log-rotation.sh
	bash scripts/gates/version-pins.sh
	bash scripts/gates/design-tokens.sh
	bash scripts/gates/permission-labels.sh
	bash scripts/gates/setting-labels.sh

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
