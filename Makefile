# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# ==============================================================================================================
# twes-in — the one place the compose invocation lives.
#
# WHY A MAKEFILE RATHER THAN A README FULL OF COMMANDS. Every target below needs the same project directory and
# the same env-file chain. Getting either wrong is not a typo that fails loudly — it silently reads a different
# `.env`, or applies production hardening to a dev stack. A README cannot enforce that; this can.
#
# HOW THE COMPOSE FILES COMPOSE, which changed on 2026-08-02 and is the reason there is no `-f` on the dev
# targets. Docker Compose AUTO-LOADS `compose.override.yaml` next to `compose.yaml` — but ONLY when no `-f` is
# passed, because `-f` switches it into explicit mode and the override is then silently ignored. So the dev
# targets `cd infra` and let discovery do its job, which is both the conventional spelling and the one that
# cannot forget the overlay. Production is explicit, because `compose.prod.yaml` must NEVER be picked up by
# accident.
# ==============================================================================================================

SHELL := /bin/bash
.DEFAULT_GOAL := help

INFRA := infra

# `.env` is committed with safe defaults and EMPTY secrets; `.env.local` is gitignored and holds the real ones.
# Compose reads `.env` from the project directory automatically, but passing `--env-file` at all switches that
# off — so both are named explicitly, and `.env.local` only when it exists, or compose errors on a missing file.
ENV_FLAGS := --env-file .env $(if $(wildcard $(INFRA)/.env.local),--env-file .env.local,)

# THE HOST'S REAL uid/gid, exported so the dev image builds a user matching whoever is running make. Without this
# the dev container writes into the bind-mounted `api/` tree as the wrong owner, which fails one of two ways: it
# cannot create `vendor/` at all, or it creates root-owned files in the developer's working copy. `TWES_` prefixed
# because plain `UID` is special in some shells and cannot be exported reliably.
export TWES_UID := $(shell id -u)
export TWES_GID := $(shell id -g)

# ==============================================================================================================
# THE NAMING CONVENTION, and it is a rule rather than a habit (developer ruling, 2026-08-05).
#
#     A BARE TARGET NAME ACTS ON DEVELOPMENT.  `-prod` ACTS ON PRODUCTION.  NO OTHER SUFFIX MEANS AN ENVIRONMENT.
#
# WHY THAT DIRECTION and not the reverse: BLAST RADIUS. Muscle memory types the short name, so the short name must
# be the harmless one. `make down` and `make build-front` are typed dozens of times a day; if bare meant production,
# each of those reflexes would reach a live system. The dangerous operation should cost a deliberate suffix.
#
# WHAT IT REPLACED. The suffix used to answer TWO DIFFERENT QUESTIONS. `up`/`up-prod` marked which STACK a target
# drives; `build-front`/`build-front-dev` marked which ARTEFACT FLAVOUR it produces — so the bare form meant "dev"
# in one family and "prod" in the other. The proof was `build-front`, which was BOTH at once: it ran on the dev
# stack and produced a production bundle, with no suffix to say either. Since both write to the same shared volume,
# `make build-front-dev` followed by `make up-prod` served an unminified bundle WITH OUR TYPESCRIPT SOURCE MAPS out
# of "production", and neither name warned you.
#
# `scripts/gates/makefile-conventions.sh` enforces this now, so it cannot drift back.
#
# HOW ONE RECIPE SERVES BOTH: `DCX` is a TARGET-SCOPED variable, set to `$(DC)` for the bare name and `$(DC_PROD)`
# for the `-prod` one, and the recipe is then written ONCE for both targets. Two stanzas, one body — so a change to
# the dev behaviour cannot be forgotten on the production twin, which is the drift this whole section is about.
# The `name: ## help` lines carry no recipe and exist only so `make help` lists both.
# ==============================================================================================================

# Dev: no `-f`, so `compose.override.yaml` is auto-merged. Prod: explicit, so it never is.
DC      := cd $(INFRA) && docker compose $(ENV_FLAGS)
DC_PROD := cd $(INFRA) && docker compose $(ENV_FLAGS) -f compose.yaml -f compose.prod.yaml

# THE CHARACTER CLASS INCLUDES DIGITS, and it did not until `e2e` was added — so the target the panel had just asked
# for was reachable by name and invisible in the one command that enumerates targets. The closed P1 was "the owed step
# is unrunnable by the route a developer uses"; being listed is half of being runnable. There is no `git ls-files`-style
# derivation available to a grep over a Makefile, so the fix is the class itself: 40 documented targets match with
# digits and 39 without, and the difference was exactly `e2e`. [Measured.]
#
# The comment is HERE rather than in the recipe: a `#` line inside a recipe is passed to the shell and `make` echoes
# it, so the first version of this note printed itself above the help output.
.PHONY: help
help: ## Show this help.
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# --------------------------------------------------------------------------------------------------------------
# Environment
# --------------------------------------------------------------------------------------------------------------
.PHONY: env
env: ## Generate infra/.env.local with fresh random secrets. Refuses to overwrite an existing one.
	@if [ -f $(INFRA)/.env.local ]; then \
		echo "$(INFRA)/.env.local already exists — refusing to overwrite it."; \
		echo "Delete it first if you really want new secrets (this invalidates the existing database)."; \
		exit 1; \
	fi
	@command -v openssl >/dev/null || { echo "openssl is required to generate secrets."; exit 1; }
	@{ \
		echo "# Machine-local secrets. GITIGNORED — never commit this file."; \
		echo "# Generated by \`make env\`. Layered over the committed infra/.env by every compose invocation."; \
		echo "APP_SECRET=$$(openssl rand -hex 32)"; \
		echo "POSTGRES_PASSWORD=$$(openssl rand -hex 24)"; \
		echo "TWES_DB_RUNTIME_PASSWORD=$$(openssl rand -hex 24)"; \
		echo "TWES_DB_OWNER_PASSWORD=$$(openssl rand -hex 24)"; \
	} > $(INFRA)/.env.local
	@echo "Wrote $(INFRA)/.env.local with four fresh secrets. Nothing else needs editing to run locally."

.PHONY: check-env
check-env:
	@[ -f $(INFRA)/.env.local ] || { \
		echo "$(INFRA)/.env.local is missing — the stack has no secrets and would refuse to start."; \
		echo "    make env"; \
		exit 1; \
	}

# --------------------------------------------------------------------------------------------------------------
# Development
# --------------------------------------------------------------------------------------------------------------
.PHONY: up up-prod
up:      DCX := $(DC)
up-prod: DCX := $(DC_PROD)
up: ## Start the development stack (source-mounted, database published on localhost).
up-prod: ## Start the PRODUCTION stack (hardened, HTTPS, replicas). SERVER_NAME is required.
# `deps` ON THE DEV SIDE ONLY. It installs `api/vendor` onto the HOST for the bind mount; production has no bind
# mount and carries vendor inside the image. A shared prerequisite would install DEV dependencies as a side effect of
# starting production, which inverts the whole split.
up: deps
up up-prod: check-env
	$(DCX) up -d --build
	@echo
	@$(MAKE) --no-print-directory urls

.PHONY: urls
# THE HTML DOCUMENTATION PAGE, added 2026-08-05, and this comment block previously argued the opposite.
#
# It said `/api/docs` "returns 404 to a browser ... because `api_platform.yaml` deliberately ships no HTML
# documentation UI: they fetch remote assets". That was right about the 404 and WRONG about the reason. Swagger UI and
# ReDoc are shipped LOCALLY by API Platform and referenced through `asset()`; only SCALAR fetches from a CDN. The real
# cause was that `symfony/twig-bundle` was not installed, so there was no HTML renderer at all.
#
# With Twig installed and `html` back in `docs_formats`, `/api` is content-negotiated on ONE url:
#   a browser (`Accept: text/html`) gets the Swagger UI page; a client (`Accept: application/ld+json`) gets the Hydra
#   entrypoint; `application/vnd.openapi+json` gets the raw OpenAPI document.
#
# `.jsonopenapi` is still listed because it is the machine-readable form worth having in front of a reader, and it is
# the one a `curl` with no `Accept` header will not give you by default.
urls: ## Print the local URLs.
	@port=$$(grep -hE '^HTTP_PORT=' $(INFRA)/.env.local $(INFRA)/.env 2>/dev/null | head -1 | cut -d= -f2); \
	port=$${port:-8080}; \
	echo "  API docs http://localhost:$$port/api           (HTML in a browser, JSON-LD to a client)"; \
	echo "  ReDoc    http://localhost:$$port/api/docs?ui=re_doc"; \
	echo "  OpenAPI  http://localhost:$$port/api/docs.jsonopenapi"; \
	echo "  Health   http://localhost:$$port/health/ready"; \
	echo "  Admin    http://localhost:$$port/admin/   (run 'make build-front' first)"; \
	echo "  Flutter  http://localhost:$$port/app/     (run 'make build-front' first)"

.PHONY: down down-prod
down:      DCX := $(DC)
down-prod: DCX := $(DC_PROD)
down: ## Stop the development stack, keeping volumes.
down-prod: ## Stop the PRODUCTION stack, keeping volumes.
down down-prod:
	$(DCX) down

.PHONY: destroy
destroy: ## Stop the stack and DELETE ITS DATA. Irreversible.
	@printf 'This deletes the database volume. Type "yes" to continue: ' && read ans && [ "$$ans" = yes ]
	$(DC) down --volumes

.PHONY: logs logs-prod
logs:      DCX := $(DC)
logs-prod: DCX := $(DC_PROD)
logs: ## Follow logs from every service.
logs-prod: ## Follow logs from the PRODUCTION stack.
logs logs-prod:
	$(DCX) logs -f --tail=100

.PHONY: ps ps-prod
ps:      DCX := $(DC)
ps-prod: DCX := $(DC_PROD)
ps: ## Show the state of every service.
ps-prod: ## Show the state of the PRODUCTION stack.
ps ps-prod:
	$(DCX) ps

.PHONY: shell shell-prod
shell:      DCX := $(DC)
shell-prod: DCX := $(DC_PROD)
shell: ## Open a shell in the API container.
shell-prod: ## Open a shell in the PRODUCTION API container (incident access).
shell shell-prod:
	$(DCX) exec api sh

.PHONY: console console-prod
console:      DCX := $(DC)
console-prod: DCX := $(DC_PROD)
console: ## Run a Symfony console command: make console CMD="debug:router"
console-prod: ## Run a console command against PRODUCTION: make console-prod CMD="debug:router"
console console-prod:
	@[ -n "$(CMD)" ] || { echo 'CMD="<console command>" is required, e.g. make console CMD=debug:router'; exit 1; }
	$(DCX) exec api php bin/console $(CMD)

# --------------------------------------------------------------------------------------------------------------
# Build
# --------------------------------------------------------------------------------------------------------------
.PHONY: build build-prod
build:      DCX := $(DC)
build-prod: DCX := $(DC_PROD)
build: ## Build the DEV API image (Xdebug, Composer, writable cache).
build-prod: ## Build the PRODUCTION API image (no debugger, no Composer, read-only cache).
build build-prod: check-env
	$(DCX) build api

# THE SWAP, 2026-08-05. `build-front` used to build the PRODUCTION bundle and `build-front-dev` the development one —
# the inversion that made the whole convention incoherent, because every other bare name meant dev. Renamed rather
# than aliased: an alias would keep the ambiguous name working and the ambiguity is the defect.
#
# **IF YOU HAVE MUSCLE MEMORY FOR THE OLD BEHAVIOUR, `build-front` NOW PRODUCES A DEV BUNDLE.** Both write to the
# same shared volume that the prod stack also serves, so `make build-front && make up-prod` would serve an unminified
# bundle with our TypeScript source maps. `up-prod` therefore states the requirement in `infra/README.md`, and the
# bundles carry their flavour in the image tag so the two cannot collide in the build cache.
# `DCX` HERE TOO, so the `-prod` name is TRUE rather than merely intended. The first version of this stanza ran BOTH
# flavours through `$(DC)` — the builders live in the base compose file, so it worked — and `makefile-conventions.sh`
# immediately failed it: *"`build-front-prod` is named for production but its recipe drives DEV"*. That is the exact
# class of defect this whole rename is about, caught by the gate written in the same commit, in my own code. The
# production bundle is now built in the production configuration context, which also means a future prod-only
# override of a builder would apply. [Verified: the build-profile services resolve and run under the prod file chain
# without `SERVER_NAME`, which the serving services do require.]
.PHONY: build-front build-front-prod
build-front:      DCX := $(DC)
build-front-prod: DCX := $(DC_PROD)
build-front:      NG_CONFIGURATION := development
build-front:      FLUTTER_BUILD_MODE := profile
build-front-prod: NG_CONFIGURATION := production
build-front-prod: FLUTTER_BUILD_MODE := release
build-front build-front-prod: export NG_CONFIGURATION
build-front build-front-prod: export FLUTTER_BUILD_MODE
build-front: ## Build DEVELOPMENT front-end bundles (source maps, unminified).
build-front-prod: ## Build PRODUCTION front-end bundles (minified, no source maps).
build-front build-front-prod: check-env
	$(DCX) run --rm --build admin-build
	$(DCX) run --rm --build flutter-build
	@echo "Built NG_CONFIGURATION=$(NG_CONFIGURATION) FLUTTER_BUILD_MODE=$(FLUTTER_BUILD_MODE) into the shared volumes."

# --------------------------------------------------------------------------------------------------------------
# PHP dependencies — installed BY THE CONTAINER, ONTO THE HOST TREE.
#
# This is what makes an IDE work, and the reason it is not simply "run composer on your machine": the container's
# PHP is the one that must resolve `ext-bcmath`, `ext-intl` and `ext-pdo_pgsql` and pick versions against PHP 8.5,
# so a host Composer with a different PHP would produce a subtly different `vendor/` — the classic "works on my
# machine". Running it in the container and letting the bind mount put the bytes on the host gives both: resolved by
# the container, readable by the IDE.
#
# `--no-deps` so this does not start the database, and `--entrypoint composer` to bypass the API entrypoint, which
# would otherwise demand APP_SECRET and wait for a database that is not running.
# --------------------------------------------------------------------------------------------------------------
# `COMPOSER_FLAGS` is the one escape hatch for a network where Composer cannot fetch dist zipballs — pass
# `--prefer-source` and it clones instead. This project's own development container needs exactly that (CLAUDE.md
# § Gotchas, 2026-07-29), and it was reachable only by reading this file until 2026-08-05:
#
#     make install COMPOSER_FLAGS=--prefer-source
#
COMPOSER_FLAGS ?=

.PHONY: install
install: ## (Re)install PHP deps into api/vendor using the container's PHP. COMPOSER_FLAGS=--prefer-source if needed.
	$(DC) run --rm --no-deps --build --entrypoint composer api \
		install --no-interaction --no-progress $(COMPOSER_FLAGS)
	# THE BUNDLE ASSETS TOO, and in the same target for the same reason as vendor: the dev tree is bind-mounted, so
	# both have to exist ON THE HOST. `assets:install` publishes API Platform's Swagger UI css/js/fonts into
	# `api/public/bundles/`, which the HTML documentation page at `/api` loads through `asset()`. Without it the page
	# renders with no stylesheet — a 200, a working document, and something that looks broken.
	#
	# It CANNOT be done in the dev image: that stage has no `vendor/` (by design), so `bin/console` will not run there.
	$(DC) run --rm --no-deps --entrypoint php api bin/console assets:install public --no-interaction
	@echo "api/vendor and api/public/bundles are on the host now — point your IDE's PHP interpreter at the container."

.PHONY: deps
# The cheap guard `up` depends on. A bind-mounted `api/` REPLACES the image's vendor tree, so if the host has no
# `vendor/` the container has none either and every command dies with `Failed to open stream: autoload.php`. This
# makes the first `make up` on a fresh clone work without the developer having to know that.
deps:
	@if [ ! -f api/vendor/autoload.php ] || [ ! -d api/public/bundles/apiplatform ]; then \
		echo "api/vendor or api/public/bundles is missing — setting up the dev tree first (one time)."; \
		$(MAKE) --no-print-directory install; \
	fi

.PHONY: composer
composer: ## Run Composer in the container: make composer CMD="require symfony/uid"
	@[ -n "$(CMD)" ] || { echo 'CMD="<composer args>" is required, e.g. make composer CMD="require symfony/uid"'; exit 1; }
	$(DC) run --rm --no-deps --entrypoint composer api $(CMD)

# --------------------------------------------------------------------------------------------------------------
# Xdebug. OFF by default because `xdebug.mode=debug` costs roughly 2-5x on EVERY request even with no IDE attached,
# which is how a debugger becomes something a developer disables on day two and never switches back on.
#
# `start_with_request=trigger` means even when armed, only requests carrying `XDEBUG_TRIGGER` (or the
# `XDEBUG_SESSION` cookie every IDE browser extension sets) are debugged — so the stack stays fast while you debug.
#
# IDE path mapping, which is the other half and is configured in the IDE:  /app  <->  <this repo>/api
# --------------------------------------------------------------------------------------------------------------
# A TARGET-SCOPED `export`, and NOT `XDEBUG_MODE=... $(DC) up`, which is what these said first and which silently
# did nothing. `$(DC)` expands to `cd infra && docker compose ...`, so a leading assignment applies to the `cd` —
# the command right after it — and `docker compose` then runs without the variable at all. The target reported
# "Xdebug ARMED" while the container still had `xdebug.mode=off`. [Verified: `container XDEBUG_MODE=[off]` after
# `make debug-on`.] `export` puts it in the recipe's whole environment, where compose interpolation can see it.
.PHONY: debug-on
debug-on: export XDEBUG_MODE := debug,develop
# 0 ONLY while the debugger is armed. A held breakpoint must not be killed by the request time limit — but the
# unconditional `max_execution_time=0` this replaced applied with the debugger OFF too, so an accidental infinite
# loop in ordinary dev hung forever instead of dying at 120s.
debug-on: export TWES_MAX_EXECUTION_TIME := 0
debug-on: ## Arm Xdebug (step debugger) and recreate the API containers.
	$(DC) up -d --force-recreate api worker scheduler
	@$(MAKE) --no-print-directory debug-status
	@echo "  Listen on port 9003, ide key 'twes', and map  /app  ->  $(CURDIR)/api"
	@trig=$$($(DC) exec -T api php -d display_errors=0 -r 'echo ini_get("xdebug.trigger_value");' 2>/dev/null | tail -1); \
	echo "  Trigger value: $${trig:-<unreadable>}  — requests need XDEBUG_TRIGGER=$${trig:-?} (cookie or query param)."; \
	echo "  That secret is what stops any page you visit opening a debugger into this container while it is armed."
	@echo "  Only requests carrying XDEBUG_TRIGGER are debugged, so the stack stays fast."

.PHONY: debug-off
debug-off: export XDEBUG_MODE := off
debug-off: ## Disarm Xdebug and recreate the API containers.
	$(DC) up -d --force-recreate api worker scheduler
	@$(MAKE) --no-print-directory debug-status

.PHONY: debug-status
# READ THE VALUE BACK OUT OF THE RUNNING CONTAINER rather than echoing what we intended. The first version of
# `debug-on` printed "ARMED" unconditionally and was wrong for exactly one commit; a target that asserts its own
# success is how that goes unnoticed. This asks PHP.
debug-status: ## Report whether Xdebug is armed, as the running container sees it.
	@err=$$($(DC) exec -T api php -d display_errors=0 -r 'echo ini_get("xdebug.mode") ?: "off";' 2>&1 >/dev/null); \
	mode=$$($(DC) exec -T api php -d display_errors=0 -r 'echo ini_get("xdebug.mode") ?: "off";' 2>/dev/null | tail -1); \
	if [ -z "$$mode" ]; then \
		echo "Xdebug state UNKNOWN — could not read it from the container. $${err:-(no error output)}"; \
		echo "  Reported as unknown rather than 'off' on purpose: 'off' would be a guess, and guessing the SAFE"; \
		echo "  direction here is the dangerous one — an armed debugger reported as disarmed."; \
		exit 1; \
	elif [ "$$mode" = off ]; then \
		echo "Xdebug is INSTALLED and DISARMED (xdebug.mode=off) — no cost per request."; \
	else \
		echo "Xdebug is ARMED (xdebug.mode=$$mode)."; \
	fi

.PHONY: test
# `unit,functional` BY DEFAULT, AND THE OMISSION OF `integration` IS DELIBERATE AND DOCUMENTED RATHER THAN A GAP.
#
# The tenancy proof needs the TWELVE-role fixture `scripts/dev/provision-test-database.sh` builds — a `BYPASSRLS`
# role, a `REPLICATION` role, roles granted `WITH INHERIT FALSE`. `infra/database/init/10-roles.sh` deliberately
# creates only TWO, and says so: *"Production must be able to express NONE of them ... a shared script would either
# weaken the tests or ship attack roles to production."* Provisioning attack roles into the stack's database to make
# one `make` target tidier would undo that, so the split is respected here instead.
#
# Run the tenancy suite on the host (or in CI) against a provisioned cluster — CLAUDE.md § "Quality gate" has the
# command. `make test ARGS="--testsuite integration"` will attempt it and fail honestly rather than skip.
#
# `-e DATABASE_URL` because `phpunit.xml`'s value is `127.0.0.1`, which inside a container is the container. It is
# passed here rather than forced in `phpunit.xml` precisely so each environment can point it somewhere real.
#
# `TEST_SUITES` IS A VARIABLE BECAUSE OF A MAKE FOOTGUN: `$(or $(ARGS),--testsuite unit,functional)` splits on the
# comma INSIDE `unit,functional`, so make passed `--testsuite unit` and silently discarded `functional`. It ran 592
# tests instead of 601 and reported OK. Function arguments are split on LITERAL commas before variable contents are
# expanded, so holding the list in a variable makes the comma invisible to the split.
TEST_SUITES ?= unit,functional
test: ## Run the API unit+functional suites inside the container.
	$(DC) exec \
		-e DATABASE_URL='postgresql://$(or $(TWES_DB_RUNTIME_ROLE),twes):$(shell grep -hE "^TWES_DB_RUNTIME_PASSWORD=" $(INFRA)/.env.local | cut -d= -f2)@database:5432/$(or $(POSTGRES_DB),twes)?serverVersion=18&charset=utf8' \
		api php tools/bin/phpunit-12.phar $(if $(ARGS),$(ARGS),--testsuite $(TEST_SUITES))

# --------------------------------------------------------------------------------------------------------------
# THE `e2e` SUITE, WHICH `make gate` DELIBERATELY DOES NOT REACH AND HAD NO PATH TO AT ALL.
#
# `composer gate:e2e` asks a REALLY-RUNNING FrankenPHP/Caddy what it actually sent — the two disjoint CSP policies,
# the `/bundles/*` file server, the catch-all's 404, the site-wide headers, and that no field is sent twice. None of
# that is visible through the kernel, so it cannot live in `gate:test`, and it FAILS rather than skipping when no
# server answers — which is why it is outside `composer gate` (wiring it in would make every gate run require a
# built image and a live stack).
#
# The consequence, which the panel filed: it was reachable through no `make` target whatsoever, while `make` is how
# a developer drives the stack in the first place. So the owed step was owed AND unrunnable by the documented route.
#
# `$(DC) exec api` rather than the host, so `TWES_E2E_BASE_URL` can default to the service name on the compose
# network instead of a published port a developer may not have mapped. Override it for a host-side run.
# --------------------------------------------------------------------------------------------------------------
TWES_E2E_BASE_URL ?= http://api

.PHONY: e2e
e2e: ## Run the e2e suite against the RUNNING development stack (needs `make up` first).
	$(DC) exec -e TWES_E2E_BASE_URL='$(TWES_E2E_BASE_URL)' api composer gate:e2e

# --------------------------------------------------------------------------------------------------------------
# Database
# --------------------------------------------------------------------------------------------------------------
.PHONY: migrate migrate-prod
migrate:      DCX := $(DC)
migrate-prod: DCX := $(DC_PROD)
migrate: ## Run migrations. Uses the OWNING role -- the only place that credential is used.
migrate-prod: ## Run migrations against PRODUCTION, as the OWNING role.
migrate migrate-prod: check-env
	$(DCX) run --rm migrate

.PHONY: psql psql-prod
psql:      DCX := $(DC)
psql-prod: DCX := $(DC_PROD)
psql: ## Open psql against the stack's database as the superuser.
psql-prod: ## Open psql against the PRODUCTION database as the superuser.
psql psql-prod:
	$(DCX) exec database psql --username "$${POSTGRES_USER:-postgres}" --dbname "$${POSTGRES_DB:-twes}"

.PHONY: backup backup-prod
backup:      DCX := $(DC)
backup-prod: DCX := $(DC_PROD)
backup: ## Dump the database to infra/backups/ with a UTC timestamp.
backup-prod: ## Dump the PRODUCTION database to infra/backups/ with a UTC timestamp.
backup backup-prod:
	@mkdir -p $(INFRA)/backups
	@stamp=$$(date -u +%Y%m%dT%H%M%SZ); \
	$(DCX) exec -T database \
		pg_dump --username "$${POSTGRES_USER:-postgres}" --dbname "$${POSTGRES_DB:-twes}" --format=custom \
		> "backups/twes-$$stamp.dump" && echo "wrote $(INFRA)/backups/twes-$$stamp.dump"

.PHONY: restore restore-prod
restore:      DCX := $(DC)
restore-prod: DCX := $(DC_PROD)
restore: ## Restore from a dump: make restore DUMP=backups/twes-....dump  (path relative to infra/)
restore-prod: ## Restore PRODUCTION from a dump. Prompts — it overwrites live data.
restore restore-prod:
	@[ -n "$(DUMP)" ] || { echo "DUMP=<path relative to infra/> is required"; exit 1; }
	@printf 'This OVERWRITES the current database. Type "yes" to continue: ' && read ans && [ "$$ans" = yes ]
	$(DCX) exec -T database \
		pg_restore --username "$${POSTGRES_USER:-postgres}" --dbname "$${POSTGRES_DB:-twes}" --clean --if-exists \
		< "$(DUMP)"

# --------------------------------------------------------------------------------------------------------------
# Production
# --------------------------------------------------------------------------------------------------------------
# `up-prod`, `down-prod` and every other `-prod` target are defined BESIDE THEIR DEV TWIN above, sharing one recipe
# through the target-scoped `DCX`. They are not repeated here: two copies of a recipe is exactly the drift the
# convention exists to prevent, and it is what let `build-front` and `build-front-dev` diverge in the first place.
#
# `config-prod` is the one production target with NO dev twin, and deliberately: rendering the dev configuration is
# what `docker compose config` does by default, and `gate-infra` already checks both.
.PHONY: config-prod
config-prod: ## Render the production configuration without starting anything.
	$(DC_PROD) config

# THERE IS NO `destroy-prod`, AND ITS ABSENCE IS THE POINT. `destroy` deletes the database volume; a one-word command
# that does that to production is a foot-gun no convention should require for symmetry's sake. Deleting production
# data is a deliberate, manual act with a backup taken first (`make backup-prod`).

# --------------------------------------------------------------------------------------------------------------
# Quality
# --------------------------------------------------------------------------------------------------------------
# THE SECOND AXIS THE OLD NAMING CONFLATED: SCOPE. `gate` used to mean "the API tier only" while `gate-infra` meant
# infra — so a bare name meant "dev" in one family, "prod" in another, and "just one tier" in a third. The rule now
# matches the environment one: **the bare name is the WHOLE thing, a suffix NARROWS it.**
#
# AND `gate` DID NOT KEEP THAT PROMISE FOR TWO COMMITS. It said "EVERY tier's" and invoked api, infra and make —
# `admin/` and `mobile/` were reachable from no target at all, so the two client tiers' gates existed only as prose
# in their own READMEs and in CLAUDE.md § "Quality gate". That is the SAME defect the ruling above closed, one level
# up: a bare name claiming the whole and delivering a subset. Narrowing the help text instead would have been the
# tempting fix and the wrong one — it would re-open the axis by writing the exception down.
#
# EACH TIER GATE FAILS RATHER THAN SKIPPING WHEN ITS TOOLCHAIN IS ABSENT, and that is deliberate rather than an
# oversight. It matches what `gate:licences` and `gate:schema` already do inside the API tier, and CLAUDE.md
# § Gotchas records four separate controls that silently did not run. The cost is real and is stated here rather
# than discovered: on a machine with no Flutter, `make gate` is RED — run `make gate-api` while working on the API.
.PHONY: gate
gate: ## Run EVERY tier's quality gate. Needs every tier's toolchain; use a narrow sibling otherwise.
	@$(MAKE) --no-print-directory gate-api
	@$(MAKE) --no-print-directory gate-admin
	@$(MAKE) --no-print-directory gate-mobile
	@$(MAKE) --no-print-directory gate-infra
	@$(MAKE) --no-print-directory gate-make

.PHONY: gate-api
gate-api: ## Run the API tier's quality gate on the host.
	cd api && COMPOSER_ALLOW_SUPERUSER=1 composer gate

.PHONY: gate-admin
gate-admin: ## Run the Angular admin tier's quality gate (lint, tests, production build).
	cd admin && npm run lint && npm test -- --no-watch && npm run build

# THE ORDER IS LOAD-BEARING AND IS NOT ALPHABETICAL: `flutter build web` must run BEFORE `flutter test`, because
# `test/no_external_origin_test.dart` READS `build/web`. Reversed, those cases SKIP and the suite still exits 0 with
# "All tests passed!" — a GDPR control that silently does not run. `mobile/README.md` records the same order for the
# same reason; this recipe is the enforcement.
.PHONY: gate-mobile
gate-mobile: ## Run the Flutter tier's quality gate. Order matters: analyze, BUILD, then test.
	cd mobile && flutter analyze && flutter build web --release --no-web-resources-cdn && flutter test

.PHONY: gate-infra
gate-infra: ## Validate every compose configuration renders and holds its security properties.
	bash scripts/gates/compose-config.sh

.PHONY: gate-make
gate-make: ## Check this Makefile's own naming convention (bare = dev, -prod = production).
	bash scripts/gates/makefile-conventions.sh
