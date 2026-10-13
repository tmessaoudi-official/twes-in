#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/deploy/new-instance.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
value() { grep -E "^$2=" "$1" | head -1 | cut -d= -f2-; }
echo "new-instance"

SECRETS=(APP_SECRET APP_MFA_KEY REALTIME_TOKEN_KEY REALTIME_API_KEY POSTGRES_PASSWORD POSTGRES_APP_PASSWORD BACKUP_DATABASE_PASSPHRASE BACKUP_FILES_PASSPHRASE
  MONITOR_TOKEN MONITOR_HEARTBEAT_TOKEN)
FACTS=(TWES_HOST EDGE_TLS MAILER_DSN MAILER_SENDER BACKUP_S3_ENDPOINT BACKUP_S3_BUCKET BACKUP_S3_REGION BACKUP_S3_KEY BACKUP_S3_SECRET
  MONITOR_URL SENTRY_DSN)

f="$TMPDIR/a/deploy.env"; mkdir -p "$TMPDIR/a"
out=$(bash "$SCRIPT" "$f" 2>&1); check "a new instance's file is written" $? 0 "$out" "$f"
check "only its owner reads it" 0 0 "$(stat -c %a "$f")" "600"
missing=(); for k in "${SECRETS[@]}"; do [[ -n "$(value "$f" "$k")" ]] || missing+=("$k"); done
check "every secret is filled in" "${#missing[@]}" 0 "${missing[*]:-}" ""
filled=(); for k in "${FACTS[@]}"; do grep -qE "^$k=$" "$f" || filled+=("$k"); done
check "every fact is left for the operator, blank, never guessed" "${#filled[@]}" 0 "${filled[*]:-}" ""
[[ "$(value "$f" POSTGRES_PASSWORD)" =~ ^[A-Za-z0-9]{32,}$ ]]; check "the database password sits in a URL as it is: letters and digits" $? 0 "" ""
[[ "$(value "$f" POSTGRES_APP_PASSWORD)" =~ ^[A-Za-z0-9]{32,}$ ]]; check "the application role's password sits in a URL as it is: letters and digits" $? 0 "" ""
[[ "$(value "$f" POSTGRES_APP_PASSWORD)" != "$(value "$f" POSTGRES_PASSWORD)" ]]; check "the application role's password is not the owner's" $? 0 "" ""
[[ "$(value "$f" MONITOR_HEARTBEAT_TOKEN)" =~ ^[A-Za-z0-9]{32,}$ ]]; check "the heartbeat token sits in a header as it is: letters and digits" $? 0 "" ""
check "the monitor's tokens are named for copying to it" 0 0 "$out" "MONITOR_HEARTBEAT_TOKEN"
[[ "$(value "$f" APP_MFA_KEY | base64 -d 2>/dev/null | wc -c)" -eq 32 ]]; check "the MFA key is 32 random bytes in base64" $? 0 "" ""
for k in APP_MFA_KEY APP_SECRET BACKUP_DATABASE_PASSPHRASE BACKUP_FILES_PASSPHRASE; do
  check "the escrow list names $k" 0 0 "$out" "$k"
done

g="$TMPDIR/b.env"; bash "$SCRIPT" "$g" >/dev/null 2>&1
[[ "$(value "$f" APP_SECRET)" != "$(value "$g" APP_SECRET)" ]]; check "two instances share no secret" $? 0 "" ""

before=$(cat "$f")
out=$(bash "$SCRIPT" "$f" 2>&1); check "an existing file is refused" $? 1 "$out" "exists"
[[ "$(cat "$f")" == "$before" ]]; check "and left as it was" $? 0 "" ""

out=$(bash "$SCRIPT" 2>&1); check "a file must be named" $? 2 "$out" "Usage"
out=$(bash "$SCRIPT" --production "$TMPDIR/c.env" 2>&1); check "only --staging may come before the file" $? 2 "$out" "Usage"

grep -qE '^(DEPLOY_ENV|BACKUP_S3_PREFIX)=' "$f"; check "a production instance says neither staging's environment nor its prefix" $? 1 "" ""
s="$TMPDIR/staging/deploy.env"
out=$(bash "$SCRIPT" --staging "$s" 2>&1); check "a staging instance's file is written" $? 0 "$out" "$s"
check "  saying it is staging, which its errors and deploy.sh read" 0 0 "$(value "$s" DEPLOY_ENV)" "staging"
[[ "$(value "$s" BACKUP_S3_PREFIX)" == staging/ ]]; check "  with its backups under a prefix of their own" $? 0 "$(value "$s" BACKUP_S3_PREFIX)" ""
check "  and the operator told to give it a bucket and keys of its own" 0 0 "$out" "never production's"
[[ "$(value "$s" APP_MFA_KEY)" != "$(value "$f" APP_MFA_KEY)" ]]; check "  sharing no secret with production" $? 0 "" ""

echo "new-instance: $pass passed, $fail failed"
((fail == 0))
