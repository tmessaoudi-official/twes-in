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
# The repository's own git directory, which a linked worktree's `.git` file names outside the tree (compose.yaml).
HOST_GIT := $(or $(shell git -C $(CURDIR) rev-parse --path-format=absolute --git-common-dir 2>/dev/null),$(CURDIR)/.git)
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
DOCKER_GID := $(shell stat -c %g /var/run/docker.sock 2>/dev/null)
TOOLS_TMP := /tmp/twes-in-tools-$(HOST_UID)
export HOST_REPO HOST_GIT HOST_UID HOST_GID DOCKER_GID TOOLS_TMP
export LAN_HOST LAN_ORIGIN COMPOSE_PROFILES COMPOSE_FILE
# WEB_ and API_BUILD_VERSION and _COMMIT for the images' build arguments, worked out only where an image is built.
BUILD_ENV = $(shell bash scripts/build-version.sh --env)
# A directory of the host's own for the toolchain's temporary files, made here as this user or Docker makes it as root.
# A worker count handed in (`make test-api PARATEST_PROCESSES=4`) reaches the suite that reads it (api/tests/parallel.php).
TOOLS := mkdir -p $(TOOLS_TMP) && docker compose --progress quiet --profile tools run --rm -T $(if $(PARATEST_PROCESSES),-e PARATEST_PROCESSES=$(PARATEST_PROCESSES)) tools
WEB_TOOLS := mkdir -p $(TOOLS_TMP) && docker compose --progress quiet --profile tools run --rm -T web-tools
# `npm ci` only when package-lock.json is newer than the last one that finished here.
NPM_INSTALL := [ node_modules/.twes-installed -nt package-lock.json ] || { npm ci --no-audit --no-fund && touch node_modules/.twes-installed; }
.PHONY: playwright-browser tools web-tools php-lint up up-images live-refresh down reset backup-drill deploy-check monitor-check staging-check onprem-check logs migrate seed fixtures operator-code versions scale-data api-openapi api-types check mutants gate gate-snapshot commit-gated gate-api gate-web gate-web-checks gate-licences test-api test-web e2e gallery videos notices audit tools-image web-tools-image ps-tools-image gate-powershell in-gate-licences in-gate-api in-test-api in-api-openapi in-versions in-notices

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

ps-tools-image: ## build the PowerShell toolchain image: the Windows installer's parser and PSScriptAnalyzer
	docker build -q --load -f infra/powershell/Dockerfile -t twes-in-ps-tools infra/powershell >/dev/null

gate-powershell: ps-tools-image   ## the Windows installer, scripts/onprem/install.ps1: parsed, PSScriptAnalyzer clean, its .wslconfig editing run
	@# Read-only and with no network: the test reads the installer and writes only inside the container.
	docker run --rm --network none -v "$(CURDIR)/scripts:/scripts:ro" twes-in-ps-tools pwsh -NoLogo -NoProfile -File /scripts/tests/install-ps1.test.ps1

up:            ## start the whole stack LIVE: an edit under api/ or web/ shows without a rebuild (web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094, a phone's HTTPS door :8443), then seed
	@# The live volumes mount inside the host's api/ and web/; made here, as this user, or Docker makes them as root.
	mkdir -p api/vendor api/var web/node_modules web/.angular var/tmp var/cache
	@# Each image is built as the version git says it is (scripts/build-version.sh); the footer shows it.
	env $(BUILD_ENV) docker compose up -d --build --wait
	$(MAKE) seed
	@echo "On this computer: http://localhost:$${WEB_PORT:-8090}"
	@$(if $(LAN_ORIGIN),echo "From a phone on this network: $(LAN_ORIGIN) (trust once: http://$(LAN_HOST):8095/root.crt)",echo "No network address found: the phone door is off.")

up-images:     ## the same stack from the built images, exactly as CI runs it: nginx, the static bundle, no watcher. Every later make target follows it only with COMPOSE_FILE=compose.yaml
	$(MAKE) up COMPOSE_FILE=compose.yaml

live-refresh:  ## after changing an API resource's properties under make up: empties the API's metadata caches, restarts its workers and regenerates the web tier's types from what the API now serves
	docker compose exec -T api sh -c 'bin/console cache:pool:clear --all && curl -fsS -o /dev/null -X POST http://localhost:2019/frankenphp/workers/restart'
	docker compose exec -T web sh -c 'wget -q -O /tmp/openapi.json http://api/api/docs.jsonopenapi && OPENAPI_JSON=/tmp/openapi.json npm run api:types'

migrate:       ## apply pending migrations and the application role's grants again: the one-shot migrate service (make up already ran it)
	docker compose run --rm migrate

seed:          ## built-in roles, the operators (operator@twes.local, codes@twes.local) with a known authenticator and the Demo company; idempotent. Dev secrets only.
	docker compose exec -T api bin/console app:seed --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP
	@# The browser scenarios that sign in with a code use this second one, so the first one's codes and proofs, five in
	@# five minutes, are left to the scenarios sharing its session (web/e2e/session.ts).
	docker compose exec -T api bin/console app:seed --operator-email=codes@twes.local --operator-name='Operator Codes' --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP

fixtures:      ## the demo companies Carthage Conseil (TN) and Atelier Mercier (FR), five months of activity written through the use cases; after make seed; appends, never empties the database; a company already there is left as it is
	docker compose exec -T api bin/console doctrine:fixtures:load --append --no-interaction

# A large company, in its own database (docs/SPEC.md § 7, 2026-09-27, row 181). SIZE is the invoice count: 100k, 1m, 5m, 10m.
SIZE ?= 100k
SCALE_INVOICES := $(subst m,000000,$(subst k,000,$(SIZE)))
scale-data:    ## SIZE=100k|1m|5m|10m: a large company (the demo's Carthage Conseil, grown by cloning its invoice graph) in its own database twes_scale; the development data is never touched; resumable, so run it again if it stops
	printf '%s\n' "SELECT 'CREATE DATABASE twes_scale OWNER twes' WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'twes_scale')\\gexec" | docker compose exec -T postgres psql -U twes -d postgres
	@# Built as the schema's owner on both connections, then granted to the application's role, as the migrate service does.
	docker compose exec -T -e DATABASE_URL="postgresql://twes:$${POSTGRES_PASSWORD:-twes}@postgres:5432/twes_scale?serverVersion=18" -e DATABASE_MIGRATION_URL="postgresql://twes:$${POSTGRES_PASSWORD:-twes}@postgres:5432/twes_scale?serverVersion=18" -e POSTGRES_APP_PASSWORD="$${POSTGRES_APP_PASSWORD:-twes-app}" api sh -c 'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration && bin/console app:database:app-role --no-interaction && bin/console app:seed --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP && bin/console doctrine:fixtures:load --append --no-interaction && bin/console app:scale:generate --no-debug --company="Carthage Conseil" --invoices=$(SCALE_INVOICES)'
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

backup-drill:  ## the restore drill (docs/START.md § 13; DRILL=local for a shop's folder and off-site copy, § 16): the production images with the backup overlay, backed up, restored from the bucket alone into empty volumes to a moment and to the latest, checked; its own compose project (twes-drill), removed at the end
	bash scripts/backup/restore-drill.sh $(DRILL)

deploy-check:  ## the deploy check (docs/START.md § 14): a deployment booted from new-instance.sh's values with the four compose files behind its Caddy edge, then asked through it for HSTS, the HTTPS redirect, health, a sign-in, the realtime socket and the client address; its own compose project (twes-deploy-check), removed at the end
	bash scripts/deploy/deploy-check.sh

onprem-check:  ## the on-premises check (docs/START.md § 16): a shop's installation from a clone of release tags in a local registry, run by scripts/onprem/twes: installed, both doors and a sign-in, a release with a migration more and an unhealthy api rolled back on its schema, then the restore commands taken back to the backup before it; its own compose project (twes-onprem-check) on 80 and 443, removed at the end
	bash scripts/onprem/onprem-check.sh
staging-check:  ## the staging check (docs/RUNBOOK.md): the working tree published to a local registry by publish.sh, followed by staging-follow.sh into a staging of its own, every container proved to run its digest with nothing built, then redeployed from the recorded release after its images were removed; its own compose project (twes-staging-check), removed at the end
	bash scripts/staging/staging-check.sh

monitor-check:  ## the monitor check (docs/START.md § 15): a deployment and its monitor (infra/monitor/) booted side by side from their values files, then Gatus must read the deep health green and take a backup's heartbeat, and an API and a web error must reach GlitchTip masked; its own compose projects (twes-monitor-check, twes-monitor-check-app), removed at the end
	bash scripts/monitor/monitor-check.sh

versions:      ## every version pin, read from the file that holds it (docs/UPDATE.md says where each is copied and how to bump it)
versions: tools-image
	$(TOOLS) make --no-print-directory in-versions
in-versions:
	bash scripts/versions.sh

logs:
	docker compose logs -f --tail=100

check:         ## the quick look before the gate: each tool on what changed against HEAD and the untracked files (FILES='a b' names others), FIX=1 rewrites the format; never beside a gate, both use api/var/cache
check: tools-image web-tools-image
	@# scripts/check/check.sh says which tool takes which file; a toolchain with nothing to check is not started.
	@CHECK_TOOLS='$(TOOLS)' CHECK_WEB_TOOLS='$(WEB_TOOLS)' CHECK_NPM_INSTALL='$(NPM_INSTALL)' bash scripts/check/check.sh host $(if $(FIX),--fix) $(FILES)

mutants:       ## SPEC=<file in the tree>: each mutant written in, its test run, the file put back byte for byte (scripts/mutants/mutants.sh says how a spec reads); never beside a gate or make check
mutants: tools-image web-tools-image
	@MUTANTS_TOOLS='$(TOOLS)' MUTANTS_WEB_TOOLS='$(WEB_TOOLS)' MUTANTS_NPM_INSTALL='$(NPM_INSTALL)' bash scripts/mutants/mutants.sh host $(SPEC)

gate:          ## everything CI checks except e2e: the api half, the web half, then the licences
	@# One after the other: side by side, each suite taking every processor, 41 web specs timed out at a load of 15 on 8
	@# processors; given half each, one still did, and the whole took as long as in turn (about 15 minutes either way).
	@# The OpenAPI document first and once, which gate-web-checks reads without exporting it again.
	$(MAKE) --no-print-directory api-openapi
	$(MAKE) --no-print-directory gate-api
	$(MAKE) --no-print-directory gate-web-checks
	$(MAKE) --no-print-directory gate-licences

gate-snapshot: ## the gate on what is STAGED, in a worktree of its own (.claude/worktrees/gate), while work goes on here; green trees recorded in var/claude/gates
	@bash scripts/gate/snapshot-gate.sh run
commit-gated:  ## MSG=<file>: commit exactly the last green snapshot's tree onto HEAD; refused when it is red or master moved since
	@bash scripts/gate/snapshot-gate.sh commit $(MSG)

gate-licences: tools-image
	$(TOOLS) make --no-print-directory in-gate-licences
	$(MAKE) --no-print-directory gate-powershell
gate-stamps: tools-image   ## the Decisions Log stamps alone: what a push touching only docs/SPEC.md is checked by
	$(TOOLS) make --no-print-directory in-gate-stamps
in-gate-stamps:
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/decision-stamps.test.sh
	bash scripts/gates/decision-stamps.sh
in-gate-licences:
	@# Each gate test runs through no-leftovers.sh: TMPDIR outlives every run on the host's tmpfs, and a test leaving
	@# a directory per case used its inodes up until no shell on the machine could start.
	bash scripts/gates/tests/no-leftovers.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/dependency-licences.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/spdx-headers.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/executable-bits.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/icon-buttons-named.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/password-eyes.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/dialog-content-layout.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/value-direction.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/scrollers-contain.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/outcomes-as-toasts.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/outcome-sentences.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/compose-log-rotation.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/deploy-overlay.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/onprem-overlay.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/secrets-overlay.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/action-pins.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/release-overlay.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/release/tests/publish.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/release/tests/tag-release.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/monitor-config.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/version-pins.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/host-tools.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/design-tokens.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/permission-labels.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/planned-module-labels.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/coming-texts.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/setting-labels.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/presentation-settings-parity.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/tour-anchors.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/effect-kinds.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/row-defaults.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/database-clock.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/coming-gated.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/live-reload.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/unsaved-guarded.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/list-stances.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/stored-items.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/check.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/mutants.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gate/tests/snapshot-gate.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/production-image.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/icons-declared.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/float-casts.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/refusal-answers.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/language-literals.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/logical-direction.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/additive-migrations.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/decision-stamps.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/gates/tests/screen-texts-budget.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/build-version.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/refactor/tests/move-classes.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/refactor/tests/layout-order.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/new-instance.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/new-monitor.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/deploy.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/twes.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/wsl-engine.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/deploy/tests/staging-follow.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/backup-heartbeat.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/backup-steps.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/twes-secrets.test.sh
	bash scripts/gates/tests/no-leftovers.sh scripts/tests/secrets-stay-files.test.sh
	bash scripts/gates/tests/no-leftovers.sh infra/self-hosted/tests/logrotate.test.sh
	bash scripts/gates/tests/no-leftovers.sh infra/web/tests/forwarded-proto.test.sh
	bash scripts/gates/tests/no-leftovers.sh infra/web/tests/access-log.test.sh
	bash scripts/gates/tests/no-leftovers.sh infra/web/tests/live-proxy.test.sh
	bash scripts/gates/tests/no-leftovers.sh infra/api/tests/migrate.test.sh
	bash scripts/gates/tests/no-leftovers.sh infra/api/tests/entrypoint.test.sh
	php scripts/gates/dependency-licences.php
	bash scripts/gates/spdx-headers.sh
	bash scripts/gates/executable-bits.sh
	bash scripts/gates/icon-buttons-named.sh
	bash scripts/gates/password-eyes.sh
	bash scripts/gates/dialog-content-layout.sh
	bash scripts/gates/value-direction.sh
	bash scripts/gates/scrollers-contain.sh
	bash scripts/gates/outcomes-as-toasts.sh
	bash scripts/gates/outcome-sentences.sh
	bash scripts/gates/compose-log-rotation.sh
	bash scripts/gates/deploy-overlay.sh
	bash scripts/gates/onprem-overlay.sh
	bash scripts/gates/secrets-overlay.sh
	bash scripts/gates/action-pins.sh
	bash scripts/gates/release-overlay.sh
	bash scripts/gates/monitor-config.sh
	bash scripts/gates/version-pins.sh
	bash scripts/gates/host-tools.sh
	bash scripts/gates/design-tokens.sh
	bash scripts/gates/permission-labels.sh
	bash scripts/gates/planned-module-labels.sh
	bash scripts/gates/coming-texts.sh
	bash scripts/gates/setting-labels.sh
	bash scripts/gates/presentation-settings-parity.sh
	bash scripts/gates/tour-anchors.sh
	bash scripts/gates/effect-kinds.sh
	bash scripts/gates/row-defaults.sh
	bash scripts/gates/database-clock.sh
	bash scripts/gates/coming-gated.sh
	bash scripts/gates/live-reload.sh
	bash scripts/gates/unsaved-guarded.sh
	bash scripts/gates/list-stances.sh
	bash scripts/gates/stored-items.sh
	bash scripts/gates/icons-declared.sh
	bash scripts/gates/float-casts.sh
	bash scripts/gates/refusal-answers.sh
	bash scripts/gates/language-literals.sh
	bash scripts/gates/logical-direction.sh
	bash scripts/gates/additive-migrations.sh
	bash scripts/gates/decision-stamps.sh
	bash scripts/gates/screen-texts-budget.sh

gate-api:      ## the postgres service is started for it (the tests need a real database)
gate-api: tools-image
	docker compose up -d --wait --no-recreate postgres
	$(TOOLS) make --no-print-directory in-gate-api
in-gate-api:
	mkdir -p api/vendor api/var var/tmp var/cache
	cd api && composer install --no-interaction --no-progress --prefer-dist
	cd api && composer gate

gate-web: api-openapi web-tools-image   ## npm run gate starts with api:types, which reads api/var/openapi.json
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npm run gate'
gate-web-checks: web-tools-image   # gate-web on the OpenAPI document already exported, for `make gate`
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npm run gate'

test-api: tools-image
	docker compose up -d --wait --no-recreate postgres
	$(TOOLS) make --no-print-directory in-test-api
in-test-api:   # composer test: the test database migrated, then the suite on paratest, a database copy a worker (api/tests/parallel.php)
	cd api && composer test

test-web: web-tools-image
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx ng test --watch=false'

E2E_ARGS ?=
# The browser builds (Chromium, and WebKit for the phone scenarios) the project's own @playwright/test names (the image installs the same version: scripts/gates/version-pins.sh),
# kept in var/cache/ms-playwright (gitignored). Downloaded on Docker's default network, NOT the host's the toolchain runs on:
# on the host network the download timed out in every try here (this machine's IPv6 route to Google's storage is dead,
# docs/START.md); on the default network it took 23 s (measured 2026-10-03, no environment variable needed).
playwright-browser: web-tools-image
	mkdir -p var/cache/ms-playwright
	docker run --rm -u $(HOST_UID):$(HOST_GID) -e HOME=/tmp -e PLAYWRIGHT_BROWSERS_PATH=$(HOST_REPO)/var/cache/ms-playwright -v $(HOST_REPO)/var/cache/ms-playwright:$(HOST_REPO)/var/cache/ms-playwright twes-in-web-tools playwright install chromium webkit >/dev/null

e2e: playwright-browser   ## needs the full stack up; E2E_ARGS passes options through, e.g. E2E_ARGS=--shard=1/3
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx playwright test $(E2E_ARGS)'

gallery: playwright-browser   ## every screen, desktop and phone, light and dark, into var/claude/gallery (needs the full stack up and make fixtures; GALLERY_COMPANY, GALLERY_AS, GALLERY_LANG, GALLERY_WIDTHS, GALLERY_AXE, GALLERY_FOCUS (the focus pass, into focus.json), GALLERY_ONLY, GALLERY_SCHEMES, GALLERY_DIR: web/e2e-gallery/gallery.spec.ts; E2E_ARGS=e2e-gallery/gallery.spec.ts runs that one alone)
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx playwright test -c playwright.gallery.config.ts $(E2E_ARGS)'

# Recording drops frames on a busy machine and must never share it with a gate (docs/START.md § 12). It refuses to start
# while a toolchain container runs or while the one-minute load is above MAX_LOAD; a gate started during a recording is
# not stopped by it.
MAX_LOAD ?= 6
videos: playwright-browser   ## the guide videos with their captions, into var/claude/videos (needs the full stack up and make fixtures; alone on the machine)
	@if docker ps --format '{{.Names}}' | grep -q 'tools-run'; then echo 'videos: a toolchain run (a gate?) is going on; record after it' >&2; exit 1; fi
	@awk -v max=$(MAX_LOAD) '{ if ($$1 + 0 > max + 0) { printf "videos: load %s is above %s (MAX_LOAD): recording drops frames under load\n", $$1, max > "/dev/stderr"; exit 1 } }' /proc/loadavg
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npx playwright test -c playwright.recorder.config.ts'

audit: tools-image web-tools-image   ## known vulnerabilities in what is shipped: every Composer package, npm's production packages, high and above
	$(TOOLS) sh -c 'cd api && composer audit --locked'
	$(WEB_TOOLS) sh -c '$(NPM_INSTALL) && npm audit --omit=dev --audit-level=high'

notices: tools-image   ## regenerate THIRD-PARTY-NOTICES.md after any dependency change
	$(TOOLS) make --no-print-directory in-notices
in-notices:
	php scripts/notices/generate-third-party-notices.php
