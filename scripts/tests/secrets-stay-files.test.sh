#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The check make deploy-check and make onprem-check run once an installation is up (scripts/deploy/secrets-stay-files.sh):
# it goes red when a running container's environment holds the value of a secret the configuration mounts, naming the
# container and the secret, never the value; an empty value is not looked for, since every environment holds it; and
# it never passes on nothing, with no secret to look for or no container to read. Compose and Docker are stood in for.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
lacks() { if [[ "$2" != *"$3"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$3')"; echo "$2" | sed 's/^/       /'; fi; }

mkdir -p "$TMPDIR/bin"
# docker inspect: a container's environment from env-<id>, its name otherwise.
cat > "$TMPDIR/bin/docker" <<'EOF'
#!/usr/bin/env bash
[[ "$1" == inspect ]] || exit 9
if [[ "$3" == *Config.Env* ]]; then cat "$TMPDIR/env-$4"; else echo "/twes-$4-1"; fi
EOF
chmod +x "$TMPDIR/bin/docker"

merged() { # the secrets the configuration mounts, as `docker compose config` writes them
  { echo 'services:'; echo '  api:'; echo '    secrets:'; echo '      - app_secret'
    echo 'secrets:'; for name in "$@"; do echo "  ${name,,}:"; echo "    name: twes_${name,,}"; echo "    environment: $name"; done; } > "$TMPDIR/merged"
}
run() { # containers…
  printf '%s\n' "$@" > "$TMPDIR/containers"
  PATH="$TMPDIR/bin:$PATH" bash -c '
    dc() { case "$1" in config) cat "$TMPDIR/merged" ;; ps) cat "$TMPDIR/containers" ;; esac; }
    fail() { echo "FAIL: $*"; exit 1; }
    source "$1"; secrets_stay_files "$2"' _ "$ROOT/scripts/deploy/secrets-stay-files.sh" "$TMPDIR/values" 2>&1
}
printf 'APP_SECRET=s3cret-value-1\nMAILER_DSN=smtp://user:relay-pass@relay.invalid:587\nTWES_HOST=shop.invalid\n' > "$TMPDIR/values"

echo "secrets-stay-files"
merged APP_SECRET MAILER_DSN
printf 'PATH=/usr/bin\nAPP_ENV=prod\n' > "$TMPDIR/env-api"; printf 'PGDATA=/var/lib/postgresql\n' > "$TMPDIR/env-db"
out=$(run api db); check "no environment holding a secret's value passes, counting what it read" $? 0 "$out" "2 secrets in none of 2 containers"

printf 'PATH=/usr/bin\nMAILER_DSN=smtp://user:relay-pass@relay.invalid:587\n' > "$TMPDIR/env-api"
out=$(run api db); check "a container holding a secret's value goes red, naming it and the secret" $? 1 "$out" "/twes-api-1 holds the value of MAILER_DSN"
lacks "the refusal never says the value" "$out" "relay-pass"

printf 'PATH=/usr/bin\nDATABASE_URL=postgresql://twes_app:s3cret-value-1@postgres/twes\n' > "$TMPDIR/env-api"
out=$(run api db); check "a value inside another variable is found as well" $? 1 "$out" "holds the value of APP_SECRET"

printf 'PATH=/usr/bin\n' > "$TMPDIR/env-api"
printf 'APP_SECRET=s3cret-value-1\nMAILER_DSN=\n' > "$TMPDIR/values"
out=$(run api db); check "an empty value is not looked for, since every environment holds it" $? 0 "$out" "1 secrets in none of 2 containers"

printf 'APP_SECRET=\nMAILER_DSN=\n' > "$TMPDIR/values"
out=$(run api db); check "no secret with a value to look for fails rather than pass on nothing" $? 1 "$out" "mounts no secret with a value"

printf 'APP_SECRET=s3cret-value-1\n' > "$TMPDIR/values"
out=$(run); check "no container running fails rather than pass on nothing" $? 1 "$out" "no container is running"

echo "secrets-stay-files: $pass passed, $fail failed"
((fail == 0))
