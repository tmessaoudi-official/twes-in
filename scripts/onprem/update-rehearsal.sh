#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The update rehearsal (docs/RUNBOOK.md « Release to the shops »): a shop's installation of the release out, holding a
# shop's own files, taken to a candidate commit by scripts/onprem/twes as the shop's nightly update would take it.
#   install      the release out (FROM, a tag of this repository) published to a local registry, tagged with a key made
#                for the rehearsal, which the shop pins, and installed by twes update
#   the files    a first company (app:seed) takes the shop's files through the import's own requests, as the import
#                screen sends them: vendors, categories, products, the products' vendors, the purchase history
#   the update   the candidate (TO, a commit descending from FROM, HEAD by default) published and tagged the same way;
#                twes update backs up, runs every migration the candidate adds, and reads it healthy through its edge
#   nothing lost every table holds at least the rows it held before, the products list answers the same total, every
#                migration of the candidate ran, and the operator signs in again
# What it rehearses is two COMMITS, never the working tree: the update a shop will take is the one tagged. The edge
# publishes 80 and 443, as a shop's does, so both must be free, and make onprem-check cannot run beside it. Runs under the
# compose project `twes-update-rehearsal` only, with a registry of its own on 127.0.0.1, and removes its containers,
# volumes, images and directories at the end.
# Usage: scripts/onprem/update-rehearsal.sh <files directory> [FROM tag] [TO commit]   (make update-rehearsal)
set -euo pipefail

PROJECT=twes-update-rehearsal
REGISTRY_PORT=5098
PREFIX=localhost:$REGISTRY_PORT/$PROJECT
REGISTRY_NAME=$PROJECT-registry
REGISTRY_IMAGE=registry:3.1.2
OPERATOR=operator@update-rehearsal.twes.test
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
FILES=${1:?usage: update-rehearsal.sh <files directory> [FROM tag] [TO commit]}
FILES=$(cd "$FILES" && pwd)
A=${2:-$(git -C "$root" tag --list 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].[0-9]*' --sort=-v:refname | head -n 1)}
to=${3:-HEAD}
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "update-rehearsal: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
export COMPOSE_PROJECT_NAME=$PROJECT COMPOSE_PROFILES=
unset COMPOSE_FILE
WORK=$(mktemp -d)
shop=$WORK/shop/twes-in
values=$WORK/shop/etc/install.env
state=$WORK/shop/etc/state
export TWES_VALUES=$values
# The publisher's keyring holds the rehearsal's own key, never the developer's, and the host's git settings (a global
# commit.gpgsign among them) reach none of its repositories.
export GNUPGHOME=$WORK/gnupg GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
revisions=()

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'update-rehearsal: %-62s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "update-rehearsal: FAIL — $*" >&2; exit 1; }
twes() { bash "$shop/scripts/onprem/twes" "$@"; }
dc() { (cd "$shop" && LAN_HOST=$(cat "$state/lan-host") bash scripts/deploy/deploy.sh --compose "$values" "$@"); }
sql() { dc exec -T postgres psql -U twes -d twes -tAc "$1"; }
value() { awk -v k="$1" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2) } END { print v }' "$values"; }

cleanup() {
  local status=$? revision part
  if ((status != 0)) && [[ -f "$state/lan-host" ]]; then dc logs --no-color --tail 60 edge web api migrate 2>/dev/null || true; fi
  docker compose -p "$PROJECT" down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" | xargs -r docker volume rm -f >/dev/null 2>&1 || true
  docker rm -f "$REGISTRY_NAME" >/dev/null 2>&1 || true
  for revision in "${revisions[@]}"; do
    for part in api web database; do docker image rm -f "twes-release-$part:$revision" >/dev/null 2>&1 || true; done
  done
  docker image ls --format '{{.Repository}}:{{.Tag}}' | grep "^$PREFIX/" | xargs -r docker image rm -f >/dev/null 2>&1 || true
  docker image ls --format '{{.Repository}}@{{.Digest}}' | grep "^$PREFIX/" | xargs -r docker image rm -f >/dev/null 2>&1 || true
  for part in "$GNUPGHOME" "$WORK/shop/etc/release-keys"; do [[ -d "$part" ]] && GNUPGHOME=$part gpgconf --kill all; done
  rm -rf "$WORK"
  exit "$status"
}
trap cleanup EXIT

for file in 1-vendors.csv 2-categories.csv 3-products.csv 4-product-vendors.csv 5-price-history.csv; do
  [[ -s "$FILES/$file" ]] || fail "$FILES holds no $file"
done
for port in 80 443 "$REGISTRY_PORT"; do
  (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && fail "127.0.0.1:$port is taken: a shop's edge needs 80 and 443, the rehearsal's registry $REGISTRY_PORT"
done
from_commit=$(git -C "$root" rev-parse --verify "$A^{commit}") || fail "$A is not a tag of this repository"
to_commit=$(git -C "$root" rev-parse --verify "$to^{commit}") || fail "$to is not a commit of this repository"
git -C "$root" merge-base --is-ancestor "$from_commit" "$to_commit" || fail "$to does not descend from $A: a shop's update only moves forward"
# The candidate's tag: today's, and always after the release out, which twes update compares by version.
B=v$(date +%Y.%m.%d).90
[[ "$(printf '%s\n' "$A" "$B" | sort -V | tail -n 1)" == "$B" && "$A" != "$B" ]] || fail "the candidate's tag $B does not come after $A"

git init -q --bare "$WORK/origin.git"
git -C "$root" push -q "$WORK/origin.git" "$from_commit:refs/heads/master"
git clone -q "$WORK/origin.git" "$WORK/publish"
git -C "$WORK/publish" config user.name update-rehearsal
git -C "$WORK/publish" config user.email update-rehearsal@twes.test
mkdir -m 700 "$GNUPGHOME"
gpg --batch --quiet --passphrase '' --quick-generate-key 'update-rehearsal <update-rehearsal@twes.test>' ed25519 sign never
export TWES_RELEASE_KEY
TWES_RELEASE_KEY=$(gpg --batch --with-colons --list-secret-keys | awk -F: '$1 == "fpr" { print $10; exit }')
gpg --batch --armor --export "$TWES_RELEASE_KEY" > "$WORK/release-key.asc"
step "$A ($from_commit) and $to ($to_commit) taken, the rehearsal's key made"

docker run -d --name "$REGISTRY_NAME" --log-opt max-size=10m --log-opt max-file=5 -p "127.0.0.1:$REGISTRY_PORT:5000" "$REGISTRY_IMAGE" >/dev/null
for _ in $(seq 1 30); do (exec 3<>"/dev/tcp/127.0.0.1/$REGISTRY_PORT") 2>/dev/null && break; sleep 1; done
release() { # tag: the publisher's HEAD built by its own publish.sh, pushed, tagged by digest, and the tag pushed
  revisions+=("$(git -C "$WORK/publish" rev-parse HEAD)")
  bash "$WORK/publish/scripts/release/publish.sh" "$PREFIX" "$WORK/$1.env" > "$WORK/publish-$1.log" 2>&1 \
    || { tail -n 40 "$WORK/publish-$1.log" >&2; fail "publish.sh failed for $1"; }
  git -C "$WORK/publish" fetch -q origin
  (cd "$WORK/publish" && bash scripts/release/tag-release.sh "$PREFIX" "$1") > "$WORK/tag-$1.log" 2>&1 \
    || { cat "$WORK/tag-$1.log" >&2; fail "tag-release.sh failed for $1"; }
  git -C "$WORK/publish" push -q origin "$1"
}
release "$A"
step "$A built, pushed and tagged by digest"

git clone -q "$WORK/origin.git" "$shop"
mkdir -p "$(dirname "$values")"
bash "$shop/scripts/deploy/new-instance.sh" --onprem "$values" >/dev/null
fact() { sed -i "s|^$1=.*|$1=$2|" "$values"; grep -q "^$1=$2\$" "$values" || fail "could not set $1"; }
fact MAILER_DSN null://null
fact MAILER_SENDER no-reply@update-rehearsal.twes.test
out=$(twes pin-key "$WORK/release-key.asc" 2>&1) || fail "the shop could not pin the release key: $out"
out=$(twes update 2>&1) || { echo "$out" >&2; fail "the first install did not come up"; }
[[ "$out" == *"updated to $A"* ]] || fail "the first install did not deploy $A: $out"
step "installed: $A deployed by twes update, healthy through its edge"

curl -fsS --max-time 10 -o "$WORK/root.crt" http://localhost/root.crt || fail "plain HTTP did not serve the root certificate"
ask() { curl -sS --cacert "$WORK/root.crt" "$@"; }
csrf=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
api() { ask --max-time 30 -b "$WORK/jar" -c "$WORK/jar" -H 'Origin: https://localhost' -H "csrf-token: $csrf" "$@"; }
password=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
# The password reaches curl from files in the rehearsal's own folder, never on a command line any user can list.
printf '{"email":"%s","password":"%s"}' "$OPERATOR" "$password" > "$WORK/login-body.json"
printf '{"password":"%s"}' "$password" > "$WORK/step-up-body.json"
printf '%s\n' "$password" | dc exec -T api bin/console app:seed --no-interaction --operator-email="$OPERATOR" --operator-password-stdin --company-name='Répétition' >/dev/null \
  || fail "app:seed could not make the operator and the first company"
# A shop's install switches the demo off, so no visitor's sandbox is built there; without it the update's worker built
# two whole companies, which a shop never has.
dc exec -T api bin/console app:demo:switch-off-at-install >/dev/null || fail "the demo could not be switched off as twes install does"
# An operator acts on a company only once their authenticator is enrolled, and production refuses to seed one: the
# rehearsal enrols it as an operator does, and answers its code at each sign-in after. A code is taken once in its 30
# seconds, so each one asked waits for a step of its own. The secret reaches python through its environment, never its
# command line.
secret=''
last_step=0
next_code() {
  while (($(date +%s) / 30 == last_step)); do sleep 1; done
  last_step=$(($(date +%s) / 30))
  code_now=$(TOTP_SECRET="$secret" python3 -I -c 'import base64, hashlib, hmac, os, struct, time
secret = os.environ["TOTP_SECRET"]
key = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
mac = hmac.new(key, struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
at = mac[-1] & 15
print("%06d" % ((struct.unpack(">I", mac[at:at + 4])[0] & 0x7fffffff) % 1000000))')
}
signs_in() {
  local code
  rm -f "$WORK/jar"
  code=$(api -o "$WORK/login.json" -w '%{http_code}' -H 'Content-Type: application/json' \
    --data @"$WORK/login-body.json" https://localhost/api/auth/login)
  [[ "$code" == 200 ]] || fail "$1: signing in at https://localhost answered $code"
  if jq -e '.mfaRequired == true' "$WORK/login.json" >/dev/null; then
    [[ -n "$secret" ]] || fail "$1: a second factor was asked before the rehearsal enrolled one"
    next_code
    code=$(api -o "$WORK/verify.json" -w '%{http_code}' -H 'Content-Type: application/json' \
      --data "{\"code\":\"$code_now\"}" https://localhost/api/auth/mfa/verify)
    [[ "$code" == 200 ]] || fail "$1: the authenticator's code answered $code: $(head -c 300 "$WORK/verify.json")"
  fi
  api https://localhost/api/auth/me > "$WORK/me.json"
  jq -e --arg who "$OPERATOR" '.user.email == $who' "$WORK/me.json" >/dev/null || fail "$1: the session did not read /api/auth/me: $(head -c 400 "$WORK/me.json")"
}
deep() { ask --max-time 15 -o /dev/null -w '%{http_code}' -H @"$WORK/health.header" https://localhost/api/health/deep; }
printf 'Authorization: Bearer %s\n' "$(value MONITOR_TOKEN)" > "$WORK/health.header"
# A change on the step-up list is held back until the person proves who they are again (403 step_up_required), as
# the screens ask: the password is given, and the change sent again.
held_back() { [[ "$1" == 403 ]] && grep -q '"step_up_required"' "$2"; }
prove() {
  local code
  code=$(api -o "$WORK/step-up.json" -w '%{http_code}' -H 'Content-Type: application/json' \
    --data @"$WORK/step-up-body.json" https://localhost/api/auth/step-up)
  [[ "$code" == 204 ]] || fail "proving who the operator is answered $code: $(head -c 300 "$WORK/step-up.json")"
}
signs_in "installed"
code=$(api -o "$WORK/enrol.json" -w '%{http_code}' -X POST https://localhost/api/auth/mfa/enrolment)
if held_back "$code" "$WORK/enrol.json"; then
  prove
  code=$(api -o "$WORK/enrol.json" -w '%{http_code}' -X POST https://localhost/api/auth/mfa/enrolment)
fi
[[ "$code" == 200 ]] || fail "enrolling the operator's authenticator answered $code: $(head -c 300 "$WORK/enrol.json")"
secret=$(jq -r '.secret // empty' "$WORK/enrol.json")
[[ -n "$secret" ]] || fail "the enrolment gave no secret"
next_code
code=$(api -o "$WORK/confirm.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  --data "{\"code\":\"$code_now\"}" https://localhost/api/auth/mfa/enrolment/confirm)
[[ "$code" == 200 ]] || fail "confirming the operator's authenticator answered $code: $(head -c 300 "$WORK/confirm.json")"
signs_in "enrolled"
company=$(jq -r '.company.id // empty' "$WORK/me.json")
[[ -n "$company" ]] || fail "the operator signed in to no company: $(head -c 400 "$WORK/me.json")"
base=https://localhost/api/companies/$company
for module in products vendors; do
  code=$(api -o "$WORK/module.json" -w '%{http_code}' -X PUT -H 'Content-Type: application/json' --data '{"enabled":true}' "$base/modules/$module")
  if held_back "$code" "$WORK/module.json"; then
    prove
    code=$(api -o "$WORK/module.json" -w '%{http_code}' -X PUT -H 'Content-Type: application/json' --data '{"enabled":true}' "$base/modules/$module")
  fi
  [[ "$code" == 2* ]] || fail "switching $module on answered $code: $(head -c 400 "$WORK/module.json")"
done
step "the operator signs in to a first company, products and vendors on"

# One file as the import screen sends it: a heading its guide does not know is paired to nothing, and the file kept.
import_file() { # subject file switch...
  local subject=$1 file=$2 code columns header pairing args=()
  shift 2
  code=$(api -o "$WORK/guide.json" -w '%{http_code}' "$base/imports/$subject")
  [[ "$code" == 200 ]] || fail "the $subject guide answered $code: $(head -c 400 "$WORK/guide.json")"
  columns=$(jq -c '[.columns[].key]' "$WORK/guide.json")
  header=$(head -n 1 "$FILES/$file" | sed $'s/^\xef\xbb\xbf//; s/\r$//')
  pairing=$(jq -nc --argjson known "$columns" --arg header "$header" \
    '[$header | split(";")[] | select(. as $h | $known | index($h) | not)] | map({(.): ""}) | add // {}')
  for switch in "$@"; do args+=(-F "switches[]=$switch"); done
  [[ "$pairing" == '{}' ]] || args+=(-F "pairing=$pairing")
  # Sent again once proved under a key of its own: the first key already has its answer.
  for _ in 1 2; do
    code=$(api --max-time 900 -o "$WORK/import-$subject.json" -w '%{http_code}' -H "Idempotency-Key: $(cat /proc/sys/kernel/random/uuid)" \
      -F "file=@$FILES/$file;type=text/csv" -F mode=upsert -F dryRun=0 "${args[@]}" "$base/imports/$subject")
    held_back "$code" "$WORK/import-$subject.json" || break
    prove
  done
  [[ "$code" == 200 ]] || fail "importing $file answered $code: $(head -c 600 "$WORK/import-$subject.json")"
  jq -e '.committed == true' "$WORK/import-$subject.json" >/dev/null || fail "$file was not kept: $(head -c 600 "$WORK/import-$subject.json")"
  step "$(jq -r --arg f "$file" '"\($f): \(.created | length) created, \(.updated | length) updated, \(.rejected | length) refused"' "$WORK/import-$subject.json")"
}
import_file vendors 1-vendors.csv
import_file product-categories 2-categories.csv
import_file products 3-products.csv default_taxes unpriced_inactive
import_file product-vendors 4-product-vendors.csv
import_file purchase-history 5-price-history.csv

# Every table's rows, counted exactly, and the products list's total as a person's screen reads it.
counts() {
  local query
  query=$(sql "SELECT string_agg(format('SELECT %L, count(*) FROM %I', table_name, table_name), ' UNION ALL ' ORDER BY table_name) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")
  sql "$query" | LC_ALL=C sort
}
listed() { api -H 'Accept: application/ld+json' "$base/products?itemsPerPage=1" | jq -r '.totalItems // ."hydra:totalItems" // empty'; }
counts > "$WORK/before.txt"
products_before=$(listed)
[[ -n "$products_before" && "$products_before" -gt 0 ]] || fail "the products list read no total after the import"
migrations() { git -C "$root" ls-tree --name-only "$1" api/migrations/ | grep -c '/Version[0-9]*\.php$'; }
[[ "$(sql 'SELECT count(*) FROM doctrine_migration_versions')" == "$(migrations "$from_commit")" ]] || fail "$A did not run each of its own migrations"
step "$(wc -l < "$WORK/before.txt") tables counted, $products_before products listed"

git -C "$root" push -q "$WORK/origin.git" "$to_commit:refs/heads/master"
git -C "$WORK/publish" pull -q --ff-only origin master
release "$B"
step "$B ($to_commit) built, pushed and tagged by digest"

out=$(twes update 2>&1) || { echo "$out" >&2; fail "twes update did not take $B"; }
[[ "$out" == *"updated to $B"* ]] || fail "twes update did not deploy $B: $out"
code=$(deep); [[ "$code" == 200 ]] || fail "$B answers its deep health $code"
step "updated: $B deployed by twes update, its backup made first, healthy"

[[ "$(sql 'SELECT count(*) FROM doctrine_migration_versions')" == "$(migrations "$to_commit")" ]] || fail "$B did not run each of its migrations"
counts > "$WORK/after.txt"
# A table whose rows come and go by themselves (the queue, the sessions, the limiters, the submissions) may hold fewer;
# every other table holds at least what it held, and a table gone is lost data.
lost=$(LC_ALL=C join -t '|' -a 1 -e MISSING -o 0,1.2,2.2 "$WORK/before.txt" "$WORK/after.txt" | awk -F'|' '
  $3 == "MISSING" { print $1 " is gone (" $2 " rows)"; next }
  $3 + 0 < $2 + 0 && $1 !~ /^(messenger_messages|sessions?|.*_session|rate_limit.*|cache_items|lock_keys|submission.*)$/ { print $1 ": " $2 " rows before, " $3 " after" }')
[[ -z "$lost" ]] || fail "rows were lost in the update: $lost"
signs_in "updated"
products_after=$(listed)
[[ "$products_after" == "$products_before" ]] || fail "the products list read $products_before before the update, $products_after after"
# A company opened before the update is one opened after it: the column that says so is filled for the companies of before.
if [[ "$(sql "SELECT count(*) FROM information_schema.columns WHERE table_name = 'company' AND column_name = 'approved_at'")" == 1 ]]; then
  [[ "$(sql "SELECT count(*) FROM company WHERE status = 'active' AND approved_at IS NULL")" == 0 ]] || fail "an active company was left with no approval moment"
fi
grown=$(LC_ALL=C join -t '|' "$WORK/before.txt" "$WORK/after.txt" | awk -F'|' '$3 != $2 { printf "%s %s→%s, ", $1, $2, $3 }')
step "nothing lost: ${grown:-no table changed}$products_after products listed, the operator signs in"

printf 'update-rehearsal: OK — %s with the shop'"'"'s files updated to %s (%s) in %ss in all\n' "$A" "$B" "$to_commit" "$(($(date +%s) - started))"
