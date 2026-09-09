# Developer entry points. Everything here is also what CI runs (.github/workflows/ci.yml).
SHELL := /bin/sh
.PHONY: up down logs gate gate-api gate-web gate-licences test-api test-web e2e notices

up:            ## build and start the whole stack (web :8090, api :8091, mailpit :8092, postgres :5433)
	docker compose up -d --build --wait

down:          ## stop it, keep the database volume
	docker compose down

logs:
	docker compose logs -f --tail=100

gate: gate-licences gate-api gate-web   ## everything CI checks except e2e

gate-licences:
	bash scripts/gates/tests/dependency-licences.test.sh
	bash scripts/gates/tests/spdx-headers.test.sh
	bash scripts/gates/tests/executable-bits.test.sh
	php scripts/gates/dependency-licences.php
	bash scripts/gates/spdx-headers.sh
	bash scripts/gates/executable-bits.sh

gate-api:      ## needs the postgres service up (make up, or docker compose up -d postgres)
	cd api && composer gate

gate-web:
	cd web && npm run gate

test-api:
	cd api && vendor/bin/phpunit

test-web:
	cd web && npx ng test --watch=false

e2e:           ## needs the full stack up
	cd web && npx playwright test

notices:       ## regenerate THIRD-PARTY-NOTICES.md after any dependency change
	php scripts/notices/generate-third-party-notices.php
