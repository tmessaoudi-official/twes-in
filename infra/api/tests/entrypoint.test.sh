#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The api image's entrypoint (infra/api/docker-entrypoint.sh) against a stand-in console: a server or a consumer waits
# for the database, brings the rows up to date and starts; a container started once the api is up (the demo-worker)
# waits and starts, leaving the rows to the api rather than running the steps beside another container's; anything else
# starts untouched.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

mkdir -p "$TMPDIR/bin"
# `php bin/console <command>` as the entrypoint runs it: each command written down, the database answering at once.
cat > "$TMPDIR/bin/php" <<'EOF'
#!/usr/bin/env bash
echo "console ${2%% *}" >> "$TMPDIR/ran"
EOF
# What the official image's own entrypoint is handed, written down instead of run.
cat > "$TMPDIR/bin/docker-php-entrypoint" <<'EOF'
#!/usr/bin/env bash
echo "started $*" >> "$TMPDIR/ran"
EOF
chmod +x "$TMPDIR/bin/"*

start() { (rm -f "$TMPDIR/ran" && PATH="$TMPDIR/bin:$PATH" DATABASE_URL=postgresql://stand-in "$@" 2>&1); }
ran() { cat "$TMPDIR/ran" 2>/dev/null; }
steps="app:modules:announce-arrivals app:platform:converge app:legal:seed-drafts app:invoices:seal-chain"

start env -u TWES_RELEASE_STEPS sh "$HERE/docker-entrypoint.sh" php bin/console messenger:consume async >/dev/null
check "a consumer waits for the database first" $? 0 "$(ran | head -n 1)" "console dbal:run-sql"
for step in $steps; do check "  then runs $step" 0 0 "$(ran)" "console $step"; done
check "  then starts" 0 0 "$(ran | tail -n 1)" "started php bin/console messenger:consume async"

start env TWES_RELEASE_STEPS=api sh "$HERE/docker-entrypoint.sh" php bin/console messenger:consume demo >/dev/null
check "a container leaving the rows to the api waits for the database" $? 0 "$(ran | head -n 1)" "console dbal:run-sql"
check "  runs none of the steps" 0 0 "$(ran | grep -c 'console app:' || true)" "0"
check "  and starts" 0 0 "$(ran | tail -n 1)" "started php bin/console messenger:consume demo"

start env -u TWES_RELEASE_STEPS sh "$HERE/docker-entrypoint.sh" sh -c true >/dev/null
check "a shell starts untouched" $? 0 "$(ran)" "started sh -c true"
check "  asking the console nothing" 0 0 "$(ran | grep -c '^console' || true)" "0"

echo "entrypoint: $pass passed, $fail failed"
((fail == 0))
