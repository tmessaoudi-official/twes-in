#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Modelled on the official Symfony Docker entrypoint: when the container starts the server or the console, wait
# for the database, then apply pending migrations all or nothing. A failed migration stops the container instead
# of serving a half-migrated schema. Anything else (a one-off shell) runs untouched.
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -n "${DATABASE_URL:-}" ]; then
		echo 'Waiting for the database...'
		attempts=60
		until [ "$attempts" -eq 0 ] || error="$(php bin/console dbal:run-sql -q 'SELECT 1' 2>&1)"; do
			sleep 1
			attempts=$((attempts - 1))
			echo "Still waiting for the database... $attempts attempts left."
		done
		if [ "$attempts" -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$error"
			exit 1
		fi
		if [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
		# A module arrives with a release: tell the companies that asked « Me prévenir » (docs/SPEC.md § 7,
		# 2026-09-26). Each wait is told once, so every start may run it.
		php bin/console app:modules:announce-arrivals --no-interaction
		# What a company's fiscal preset gained since it was provisioned, such as a new kind of document's numbering
		# series: a migration cannot read the presets. It copies only what a company lacks, so every start may run it.
		php bin/console app:companies:provision --no-interaction
		# The legal pages' shipped drafts, where a page has no version yet (docs/SPEC.md § 8 row 148). Never over a
		# version that exists, so every start may run it.
		php bin/console app:legal:seed-drafts --no-interaction
	fi
fi

exec docker-php-entrypoint "$@"
