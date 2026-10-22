#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Modelled on the official Symfony Docker entrypoint: when the container starts the server or the console, wait
# for the database, then bring the rows this release expects up to date. The schema itself is the migrate service's
# (infra/api/migrate.sh), which runs as its owner and has finished before this starts: this container connects as the
# application's role, which cannot change the schema. Anything else (a one-off shell) runs untouched.
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	# Production names its address by a template the console fills from the secrets (ContainerSecrets).
	if [ -n "${DATABASE_URL:-}${DATABASE_URL_TEMPLATE:-}" ]; then
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
		# A container started once the api is up (compose.yaml, demo-worker) leaves the steps below to the api, which ran
		# them before it answered, rather than run them beside another container's.
		if [ "${TWES_RELEASE_STEPS:-run}" = 'run' ]; then
			# A module arrives with a release: tell the companies that asked « Me prévenir » (docs/SPEC.md § 7,
			# 2026-09-26). Each wait is told once, so every start may run it.
			php bin/console app:modules:announce-arrivals --no-interaction
			# What this release expects of rows an earlier one wrote: the built-in roles' permissions and each company's copy
			# of its fiscal preset, such as a new kind of document's numbering series, which a migration cannot read. It
			# creates no operator or company, sets the built-in roles to this release's sets and adds only what a company
			# lacks, so every start may run it.
			php bin/console app:platform:converge --no-interaction
			# The legal pages' shipped drafts, where a page has no version yet (docs/SPEC.md § 8 row 148). Never over a
			# version that exists, so every start may run it.
			php bin/console app:legal:seed-drafts --no-interaction
			# Issued documents no hash chain holds yet, such as those issued before the chain existed, chained in their
			# issue order. Each series is held while it is sealed, so every start may run it, the worker's beside the API's.
			php bin/console app:invoices:seal-chain --no-interaction
		fi
	fi
fi

exec docker-php-entrypoint "$@"
