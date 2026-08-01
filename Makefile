# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# ==============================================================================================================
# twes-in — the one place the compose invocation lives.
#
# WHY A MAKEFILE RATHER THAN A README FULL OF COMMANDS. Every target below needs the same `-f base -f overlay`
# chain and the same project directory. Getting either wrong is not a typo that fails loudly — it silently reads a
# different `.env`, or applies production hardening to a dev stack. A README cannot enforce that; this can.
# ==============================================================================================================

SHELL := /bin/bash
.DEFAULT_GOAL := help

INFRA        := infra
COMPOSE_BASE := -f $(INFRA)/compose.yaml
COMPOSE_DEV  := $(COMPOSE_BASE) -f $(INFRA)/compose.dev.yaml
COMPOSE_PROD := $(COMPOSE_BASE) -f $(INFRA)/compose.prod.yaml
COMPOSE      := docker compose --env-file $(INFRA)/.env

.PHONY: help
help: ## Show this help.
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

# --------------------------------------------------------------------------------------------------------------
# Environment
# --------------------------------------------------------------------------------------------------------------
$(INFRA)/.env:
	@echo "infra/.env is missing. Copy the template and fill in the secrets:"
	@echo "    cp $(INFRA)/.env.example $(INFRA)/.env"
	@echo "    openssl rand -hex 32   # for APP_SECRET, and a distinct one per database role"
	@exit 1

.PHONY: env
env: $(INFRA)/.env ## Verify infra/.env exists.

# --------------------------------------------------------------------------------------------------------------
# Development
# --------------------------------------------------------------------------------------------------------------
.PHONY: up
up: env ## Start the development stack (source-mounted, database published on localhost).
	$(COMPOSE) $(COMPOSE_DEV) up -d --build
	@echo
	@echo "  API      http://localhost:$${TWES_HTTP_PORT:-8080}/api"
	@echo "  Docs     http://localhost:$${TWES_HTTP_PORT:-8080}/api/docs"
	@echo "  Health   http://localhost:$${TWES_HTTP_PORT:-8080}/health/ready"
	@echo "  Admin    http://localhost:$${TWES_HTTP_PORT:-8080}/admin/   (run 'make build-front' first)"
	@echo "  Flutter  http://localhost:$${TWES_HTTP_PORT:-8080}/app/     (run 'make build-front' first)"

.PHONY: down
down: env ## Stop the development stack, keeping volumes.
	$(COMPOSE) $(COMPOSE_DEV) down

.PHONY: destroy
destroy: env ## Stop the stack and DELETE ITS DATA. Irreversible.
	@printf 'This deletes the database volume. Type "yes" to continue: ' && read ans && [ "$$ans" = yes ]
	$(COMPOSE) $(COMPOSE_DEV) down --volumes

.PHONY: logs
logs: env ## Follow logs from every service.
	$(COMPOSE) $(COMPOSE_DEV) logs -f --tail=100

.PHONY: shell
shell: env ## Open a shell in the API container.
	$(COMPOSE) $(COMPOSE_DEV) exec api sh

# --------------------------------------------------------------------------------------------------------------
# Build
# --------------------------------------------------------------------------------------------------------------
.PHONY: build
build: env ## Build every image.
	$(COMPOSE) $(COMPOSE_DEV) build

.PHONY: build-front
build-front: env ## Build the Angular and Flutter bundles into the volumes the API serves.
	$(COMPOSE) $(COMPOSE_DEV) run --rm admin-build
	$(COMPOSE) $(COMPOSE_DEV) run --rm flutter-build

# --------------------------------------------------------------------------------------------------------------
# Database
# --------------------------------------------------------------------------------------------------------------
.PHONY: migrate
migrate: env ## Run migrations. Uses the OWNING role -- the only place that credential is used.
	$(COMPOSE) $(COMPOSE_DEV) run --rm migrate

.PHONY: backup
backup: env ## Dump the database to infra/backups/ with a UTC timestamp.
	@mkdir -p $(INFRA)/backups
	$(COMPOSE) $(COMPOSE_DEV) exec -T postgres \
		pg_dump --username "$${POSTGRES_SUPERUSER:-postgres}" --dbname "$${POSTGRES_DB:-twes}" --format=custom \
		> "$(INFRA)/backups/twes-$$(date -u +%Y%m%dT%H%M%SZ).dump"
	@echo "wrote $(INFRA)/backups/twes-$$(date -u +%Y%m%dT%H%M%SZ).dump"

.PHONY: restore
restore: env ## Restore from a dump: make restore DUMP=infra/backups/twes-....dump
	@[ -n "$(DUMP)" ] || { echo "DUMP=<path> is required"; exit 1; }
	@printf 'This OVERWRITES the current database. Type "yes" to continue: ' && read ans && [ "$$ans" = yes ]
	$(COMPOSE) $(COMPOSE_DEV) exec -T postgres \
		pg_restore --username "$${POSTGRES_SUPERUSER:-postgres}" --dbname "$${POSTGRES_DB:-twes}" --clean --if-exists \
		< "$(DUMP)"

# --------------------------------------------------------------------------------------------------------------
# Production
# --------------------------------------------------------------------------------------------------------------
.PHONY: up-prod
up-prod: env ## Start the production stack (hardened, HTTPS, replicas).
	$(COMPOSE) $(COMPOSE_PROD) up -d --build

.PHONY: down-prod
down-prod: env ## Stop the production stack.
	$(COMPOSE) $(COMPOSE_PROD) down

# --------------------------------------------------------------------------------------------------------------
# Quality
# --------------------------------------------------------------------------------------------------------------
.PHONY: gate
gate: ## Run the API tier's full quality gate on the host.
	cd api && COMPOSER_ALLOW_SUPERUSER=1 composer gate

.PHONY: gate-infra
gate-infra: ## Validate every compose configuration renders. The infra tier's own gate.
	bash scripts/gates/compose-config.sh
