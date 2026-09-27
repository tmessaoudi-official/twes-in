#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# The api container in live development (compose.live.yaml): api/ is mounted from the host and vendor/ is a volume of
# its own, so the dependencies follow the lock file the host edits and the autoloader finds a class the moment it is
# written (the image's is class-map authoritative, which only knows the classes it was built with). Then the image's
# own entrypoint: the database, the migrations, the server.
set -e
composer install --no-interaction --no-progress
exec docker-entrypoint "$@"
