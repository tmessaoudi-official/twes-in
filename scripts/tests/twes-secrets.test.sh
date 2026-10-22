#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a container's secrets become (infra/postgres/twes-secrets): each file under the secrets directory the variable
# its name says in capitals, byte for byte, for the command it runs; a file the environment points at through
# <NAME>_FILE left to its reader; an empty file or a bad name refused by its name, never its value; no directory, the
# command run as it is. And twes-backup, started by `docker compose exec`, which skips the entrypoint, goes through it.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SHIM=$ROOT/infra/postgres/twes-secrets
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
lacks() { if [[ "$2" != *"$3"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$3')"; echo "$2" | sed 's/^/       /'; fi; }
D=$TMPDIR/secrets
fresh() { rm -rf "$D"; mkdir -p "$D"; }
shim() { env -i PATH="$PATH" TWES_SECRETS_DIRECTORY="$D" "$@" sh "$SHIM" sh -c 'printf "[%s]" "$PGBACKREST_REPO1_CIPHER_PASS" "${RESTIC_PASSWORD-unset}" "${TWES_SECRETS_READ-}"' 2>&1; }

fresh; printf 'cipher-pass' > "$D/pgbackrest_repo1_cipher_pass"; printf ' spaced\n' > "$D/restic_password"
out=$(shim); check "each file becomes its variable, byte for byte, for the command" $? 0 "$out" "[cipher-pass][ spaced
][1]"

fresh; printf 'cipher-pass' > "$D/pgbackrest_repo1_cipher_pass"; printf 'x' > "$D/restic_password"
out=$(shim RESTIC_PASSWORD_FILE=/run/secrets/restic_password); check "a file the environment points at is left to its reader" $? 0 "$out" "[cipher-pass][unset]"

fresh; : > "$D/pgbackrest_repo1_cipher_pass"
out=$(shim); check "an empty file is refused by its name" $? 1 "$out" "set PGBACKREST_REPO1_CIPHER_PASS in the values file"

fresh; printf 'value-never-said' > "$D/Bad-Name"
out=$(shim); check "a name no variable can carry is refused" $? 1 "$out" "Bad-Name"
lacks "a refusal never says the value" "$out" "value-never-said"

out=$(env -i PATH="$PATH" TWES_SECRETS_DIRECTORY="$TMPDIR/absent" PGBACKREST_REPO1_CIPHER_PASS=kept sh "$SHIM" sh -c 'printf "[%s]" "$PGBACKREST_REPO1_CIPHER_PASS"' 2>&1)
check "no directory runs the command as it is" $? 0 "$out" "[kept]"

# twes-backup re-runs itself through the shim when secrets are mounted and it was not started through it.
mkdir -p "$TMPDIR/bin"
printf '#!/bin/sh\nexec sh %q "$@"\n' "$SHIM" > "$TMPDIR/bin/twes-secrets"; chmod +x "$TMPDIR/bin/twes-secrets"
fresh; printf 'cipher-pass' > "$D/pgbackrest_repo1_cipher_pass"
out=$(env -i PATH="$TMPDIR/bin:$PATH" TWES_SECRETS_DIRECTORY="$D" bash "$ROOT/infra/postgres/backup/twes-backup" secrets 2>&1)
check "twes-backup started by docker compose exec reads the secrets" $? 0 "$out" "secrets read: PGBACKREST_REPO1_CIPHER_PASS"
lacks "twes-backup says no secret's value" "$out" "cipher-pass"

echo "twes-secrets: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
