#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Live mode only (compose.live.yaml): the api serves a migration the moment it is written into the mounted source, while
# only the migrate service applies one, at `make up`. Until then the api serves code ahead of its database: a sign-in
# once failed on a table a new migration added. This applies whatever is pending each time the migrations folder
# changes, through the migrate service's own script, so the owner migrates and the application's role gets its grants.
set -u
trap 'exit 0' TERM INT

# Names, sizes and times: a file added, rewritten or removed changes it.
fingerprint() { ls -l --full-time migrations | cksum; }

# Empty, so the first round checks too: a watch started again after a stop finds what was written meanwhile.
seen=''
echo 'migrate-watch: watching migrations/'
while :; do
	sleep 3 &
	wait $!
	now=$(fingerprint)
	[ "$now" = "$seen" ] && continue
	# A file still being written settles before it is read.
	sleep 2 &
	wait $!
	seen=$(fingerprint)
	php bin/console doctrine:migrations:up-to-date --no-interaction -q && continue
	echo 'migrate-watch: migrations pending, applying them'
	# A migration half written, or wrong, fails here and is said in this service's log; the watch goes on, and the
	# next change of migrations/ tries again.
	twes-migrate || echo 'migrate-watch: the migrations above failed; fix them and save, or run make up'
done
