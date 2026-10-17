#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# The migrate service (compose.yaml): the one container that holds the schema owner's address. It waits for the
# database, applies pending migrations all or nothing, then gives the application's role its password and its grants
# (app:database:app-role), and exits. The api and the worker start only once it has succeeded, and connect as that role:
# a statement they are tricked into running can read and write rows, never rewrite the journal or reach the server.
set -e

# Until it takes writes, not only reads: a server restored from a backup answers reads while it replays its WAL, and
# refuses to assign a transaction id until it is promoted, which the migrations and the grants below both need.
echo 'Waiting for the database...'
attempts=60
until [ "$attempts" -eq 0 ] || error="$(php bin/console dbal:run-sql --connection=owner -q 'SELECT pg_current_xact_id()' 2>&1)"; do
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
php bin/console app:database:app-role --no-interaction
