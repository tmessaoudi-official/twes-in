#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The on-premises check (docs/START.md § 16): a shop's installation on this machine, run by scripts/onprem/twes as a
# shop's scheduled tasks run it, from a clone of an origin holding release tags, with a local registry standing in for
# GHCR:
#   install      twes update on a machine where nothing runs deploys the one release out (A) by digest
#   two doors    https://localhost and https://<this machine's address> answer, trusting the edge's own authority,
#                whose root certificate plain HTTP serves for a phone, and plain HTTP sends the rest on to HTTPS,
#                the authority's private keys beside it included, however their path is written
#   signing in   the operator app:seed --without-company makes signs in at https://localhost
#   rollback     a second release (B), with one migration more and an api that fails its deep health: twes update
#                backs up first, deploys B, reads it unhealthy and starts A again on B's schema, where A answers; B is
#                written down as failed here, the operator is told in the application, and still signs in
#   restore      the commands twes restore-commands prints, the same a failed rollback's alert mails, run as written,
#                take the database back to the backup made before B: B's table gone, the operator there, A healthy
#   signatures   A and B are tagged by tag-release.sh with a key made for the check, which the shop pins as it would the
#                developer's; a newer tag nobody signed (C) is refused before anything moves, written down, and the
#                operator told in the application
# What it checks is the working tree as it is, committed or not: a snapshot commit made through a temporary index (the
# real index is never touched), as make staging-check makes. B is that snapshot with one migration more, and an api
# handed another MONITOR_TOKEN than the one its health is read with, which reads as unhealthy as a broken release does.
# The edge publishes 80 and 443, as a shop's does, so both must be free. Runs under the compose project
# `twes-onprem-check` only, with a registry of its own on 127.0.0.1, and removes its containers, volumes, images and
# directories at the end.
# Usage: scripts/onprem/onprem-check.sh   (make onprem-check)
set -euo pipefail

PROJECT=twes-onprem-check
REGISTRY_PORT=5099
PREFIX=localhost:$REGISTRY_PORT/twes-onprem-check
REGISTRY_NAME=$PROJECT-registry
REGISTRY_IMAGE=registry:3.1.2
A=v2026.10.20.1 B=v2026.10.21.1
OPERATOR=operator@onprem-check.twes.test
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "onprem-check: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
# The project's name overrides the one compose.yaml gives, which is the development stack's; no profile of the
# Makefile's (its LAN door) reaches the shop's compose.
export COMPOSE_PROJECT_NAME=$PROJECT COMPOSE_PROFILES=
unset COMPOSE_FILE
WORK=$(mktemp -d)
shop=$WORK/shop/twes-in
values=$WORK/shop/etc/install.env
state=$WORK/shop/etc/state
export TWES_VALUES=$values
# The publisher's keyring holds the check's own key, never the developer's, and the host's git settings (a global
# commit.gpgsign among them) reach none of the check's repositories.
export GNUPGHOME=$WORK/gnupg GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
C=v2026.10.22.1
revisions=()

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'onprem-check: %-62s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "onprem-check: FAIL — $*" >&2; exit 1; }
twes() { bash "$shop/scripts/onprem/twes" "$@"; }
dc() { (cd "$shop" && LAN_HOST=$(cat "$state/lan-host") bash scripts/deploy/deploy.sh --compose "$values" "$@"); }
sql() { dc exec -T postgres psql -U twes -d twes -tAc "$1"; }
value() { awk -v k="$1" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2) } END { print v }' "$values"; }

cleanup() {
  local status=$? revision part
  if ((status != 0)) && [[ -f "$state/lan-host" ]]; then dc logs --no-color --tail 60 edge web api migrate backup 2>/dev/null || true; fi
  # By the project's name alone: whichever release's checkout is current, its containers and volumes go.
  docker compose -p "$PROJECT" down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" | xargs -r docker volume rm -f >/dev/null 2>&1 || true
  docker rm -f "$REGISTRY_NAME" >/dev/null 2>&1 || true
  # Only what this check built and pulled: its two revisions' images, and whatever sits under its own prefix.
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

for port in 80 443 "$REGISTRY_PORT"; do
  (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && fail "127.0.0.1:$port is taken: a shop's edge needs 80 and 443, the check's registry $REGISTRY_PORT"
done

# The working tree as one commit on top of HEAD, made in a copy of the index.
git_dir=$(git -C "$root" rev-parse --absolute-git-dir)
cp "$git_dir/index" "$WORK/index"
GIT_INDEX_FILE=$WORK/index git -C "$root" add -A
tree=$(GIT_INDEX_FILE=$WORK/index git -C "$root" write-tree)
snapshot=$(git -C "$root" -c user.name=onprem-check -c user.email=onprem-check@twes.test commit-tree "$tree" -p HEAD -m "onprem-check snapshot")
git init -q --bare "$WORK/origin.git"
git -C "$root" push -q "$WORK/origin.git" "$snapshot:refs/heads/master"
git clone -q "$WORK/origin.git" "$WORK/publish"
git -C "$WORK/publish" config user.name onprem-check
git -C "$WORK/publish" config user.email onprem-check@twes.test
mkdir -m 700 "$GNUPGHOME"
gpg --batch --quiet --passphrase '' --quick-generate-key 'onprem-check <onprem-check@twes.test>' ed25519 sign never
export TWES_RELEASE_KEY
TWES_RELEASE_KEY=$(gpg --batch --with-colons --list-secret-keys | awk -F: '$1 == "fpr" { print $10; exit }')
gpg --batch --armor --export "$TWES_RELEASE_KEY" > "$WORK/release-key.asc"
step "snapshot $snapshot cloned to an origin and a publisher, its release key made"

docker run -d --name "$REGISTRY_NAME" --log-opt max-size=10m --log-opt max-file=5 -p "127.0.0.1:$REGISTRY_PORT:5000" "$REGISTRY_IMAGE" >/dev/null
for _ in $(seq 1 30); do (exec 3<>"/dev/tcp/127.0.0.1/$REGISTRY_PORT") 2>/dev/null && break; sleep 1; done
release() { # tag: the publisher's HEAD built, pushed, tagged by digest, and the tag pushed to the origin
  revisions+=("$(git -C "$WORK/publish" rev-parse HEAD)")
  bash "$WORK/publish/scripts/release/publish.sh" "$PREFIX" "$WORK/$1.env" > "$WORK/publish-$1.log" 2>&1 \
    || { tail -n 40 "$WORK/publish-$1.log" >&2; fail "publish.sh failed for $1"; }
  git -C "$WORK/publish" fetch -q origin
  (cd "$WORK/publish" && bash scripts/release/tag-release.sh "$PREFIX" "$1") > "$WORK/tag-$1.log" 2>&1 \
    || { cat "$WORK/tag-$1.log" >&2; fail "tag-release.sh failed for $1"; }
  git -C "$WORK/publish" push -q origin "$1"
}
release "$A"
step "$A built from the snapshot, pushed and tagged by digest"

# The shop: a clone of the origin, its values as new-instance.sh --onprem writes them, its mail going nowhere.
git clone -q "$WORK/origin.git" "$shop"
mkdir -p "$(dirname "$values")"
bash "$shop/scripts/deploy/new-instance.sh" --onprem "$values" >/dev/null
fact() { sed -i "s|^$1=.*|$1=$2|" "$values"; grep -q "^$1=$2\$" "$values" || fail "could not set $1"; }
fact MAILER_DSN null://null
fact MAILER_SENDER no-reply@onprem-check.twes.test
out=$(twes pin-key "$WORK/release-key.asc" 2>&1) || fail "the shop could not pin the release key: $out"
[[ "$out" == *"$TWES_RELEASE_KEY"* ]] || fail "pinning did not show the key's fingerprint: $out"

out=$(twes update 2>&1) || { echo "$out" >&2; fail "the first install did not come up"; }
[[ "$out" == *"updated to $A"* ]] || fail "the first install did not deploy $A: $out"
step "installed: $A deployed by twes update, healthy through its edge"

lan=$(cat "$state/lan-host")
curl -fsS --max-time 10 -o "$WORK/root.crt" http://localhost/root.crt || fail "plain HTTP did not serve the root certificate"
ask() { curl -sS --max-time 15 --cacert "$WORK/root.crt" "$@"; }
for door in localhost "$lan"; do
  ask "https://$door/api/health" | grep -q '"status":"ok"' || fail "https://$door does not answer with a certificate the edge's authority signed"
done
redirect=$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "http://$lan/invoices?x=1")
[[ "$redirect" == "308 https://$lan/invoices?x=1" ]] || fail "plain HTTP answered [$redirect], wanted 308 to https://$lan/invoices?x=1"
# The authority's private keys sit beside the root certificate plain HTTP serves to the shop's network: with one, anyone
# there could stand in for the installation before every phone that trusts it. Each way of naming them is sent on.
dc exec -T edge test -s /data/caddy/pki/authorities/local/root.key || fail "the edge's authority keeps no root.key where it was looked for"
for path in /root.key /intermediate.key /ROOT.KEY /root.crt/../root.key /root.crt%2F..%2Froot.key /%2e/root.key //root.key; do
  answer=$(curl -sS --path-as-is -o "$WORK/probe" -w '%{http_code}' "http://$lan$path")
  [[ "$answer" == 308 ]] && ! grep -q 'PRIVATE KEY' "$WORK/probe" || fail "plain HTTP answered $path with $answer, wanted the redirect and no key"
done
step "both doors answer: https://localhost and https://$lan; no key of the authority on plain HTTP"

password=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s\n' "$password" | dc exec -T api bin/console app:seed --no-interaction --operator-email="$OPERATOR" --operator-password-stdin --without-company >/dev/null \
  || fail "app:seed --without-company could not make the operator"
signs_in() {
  local csrf code me
  csrf=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
  rm -f "$WORK/jar"
  code=$(ask -c "$WORK/jar" -o /dev/null -w '%{http_code}' -H 'Origin: https://localhost' -H "csrf-token: $csrf" -H 'Content-Type: application/json' \
    --data "{\"email\":\"$OPERATOR\",\"password\":\"$password\"}" https://localhost/api/auth/login)
  [[ "$code" == 200 ]] || fail "$1: signing in at https://localhost answered $code"
  me=$(ask -b "$WORK/jar" -H 'Origin: https://localhost' -H "csrf-token: $csrf" https://localhost/api/auth/me)
  [[ "$me" == *"\"$OPERATOR\""* ]] || fail "$1: the session did not read /api/auth/me: $me"
}
deep() { ask -o /dev/null -w '%{http_code}' -H @"$WORK/health.header" https://localhost/api/health/deep; }
printf 'Authorization: Bearer %s\n' "$(value MONITOR_TOKEN)" > "$WORK/health.header"
signs_in "installed"
step "the operator signs in at https://localhost"

# B: one migration more, and an api that fails its deep health.
cat > "$WORK/publish/api/migrations/Version20991231000000.php" <<'PHP'
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20991231000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE onprem_check_b (id INT NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE onprem_check_b');
    }
}
PHP
python3 - "$WORK/publish/compose.onprem.yaml" <<'PY'
import sys
path = sys.argv[1]
text = open(path).read()
old = "  api:\n    ports: !reset []\n    environment: *onprem-environment\n"
new = "  api:\n    ports: !reset []\n    environment:\n      <<: *onprem-environment\n      MONITOR_TOKEN: not-the-shops\n"
if text.count(old) != 1:
    sys.exit("compose.onprem.yaml no longer declares the api as the check expects")
open(path, "w").write(text.replace(old, new))
PY
git -C "$WORK/publish" add -A
git -C "$WORK/publish" commit -qm "onprem-check: a release with one migration more and an unhealthy api"
git -C "$WORK/publish" push -q origin HEAD:master
release "$B"
step "$B built: one migration more, its api unhealthy"

out=$(twes update 2>&1) && fail "an unhealthy $B was kept: $out"
[[ "$out" == *"$B is unhealthy"*"starting $A again"* ]] || fail "twes update did not start $A again: $out"
[[ "$(tail -n 1 "$WORK/shop/etc/releases/history")" == *" started $snapshot again" ]] \
  || fail "the history does not end on $A started again: $(tail -n 3 "$WORK/shop/etc/releases/history")"
[[ "$(cat "$state/failed-releases")" == "$B" ]] || fail "$B is not written down as failed here"
code=$(deep); [[ "$code" == 200 ]] || fail "$A started again answers its deep health $code"
[[ "$(sql "SELECT to_regclass('onprem_check_b') IS NOT NULL")" == t ]] || fail "$B's migration did not run: the rollback was not tried on a newer schema"
signs_in "rolled back"
[[ "$(sql "SELECT count(*) FROM inbox_item WHERE type = 'installation.rolled_back'")" == 1 ]] || fail "the operator was not told of the rollback in the application"
label=$(awk '{ print $3 }' "$state/last-update")
dc exec -T backup gosu postgres pgbackrest --stanza=twes info | grep -q "$label" || fail "the backup made before $B, $label, is not in the repository"
step "$B unhealthy: $A again on $B's schema, told, nothing lost"

twes restore-commands > "$WORK/restore.txt" || fail "twes restore-commands printed nothing to run"
grep -q -- "--set=$label " "$WORK/restore.txt" || fail "the restore commands do not name the backup made before $B: $(cat "$WORK/restore.txt")"
ran=0
while IFS= read -r line; do
  [[ "$line" == "  "* ]] || continue
  ran=$((ran + 1))
  echo "\$ ${line#  }" >> "$WORK/restore.log"
  # Run with this loop's input, as a block pasted into a terminal shares it: a command reading it cuts the list short.
  (cd "$shop" && bash -euo pipefail -c "${line#  }") >> "$WORK/restore.log" 2>&1 || { cat "$WORK/restore.log" >&2; fail "a restore command failed: ${line#  }"; }
done < "$WORK/restore.txt"
# The checkout, the stop, the two restores and the start: fewer ran means the list was cut, not that it worked.
((ran == 5)) || { cat "$WORK/restore.log" >&2; fail "$ran restore commands ran, not the 5 twes restore-commands prints"; }
gone=$(sql "SELECT to_regclass('onprem_check_b') IS NULL" 2>&1) || true
if [[ "$gone" != t ]]; then
  cat "$WORK/restore.log" >&2
  dc logs --no-color postgres 2>&1 | grep -iE 'recovery|restor|promot|redo|consistent|timeline|archive' | tail -n 40 >&2 || true
  fail "the database was not taken back to before $B (asked whether B's table is gone, it answered: $gone)"
fi
code=$(deep); [[ "$code" == 200 ]] || fail "$A after the restore answers its deep health $code"
signs_in "restored"
step "the restore commands took it back to before $B"

# C: a newer tag nobody signed, as one pushed by someone who is not the developer would be.
before=$(tail -n 1 "$WORK/shop/etc/releases/history")
git -C "$WORK/publish" tag -a "$C" -m "$C" --no-sign
git -C "$WORK/publish" push -q origin "$C"
out=$(twes update 2>&1) && fail "an unsigned $C was applied: $out"
[[ "$out" == *"refused: $C is not signed by the release key pinned here"* ]] || fail "twes update did not refuse $C for its signature: $out"
[[ "$(tail -n 1 "$WORK/shop/etc/releases/history")" == "$before" ]] || fail "refusing $C moved the release: $(tail -n 2 "$WORK/shop/etc/releases/history")"
grep -qxF -e "$C" "$state/refused-releases" || fail "$C is not written down as refused here"
code=$(deep); [[ "$code" == 200 ]] || fail "$A after refusing $C answers its deep health $code"
[[ "$(sql "SELECT count(*) FROM inbox_item WHERE type = 'installation.update_refused'")" == 1 ]] || fail "the operator was not told of the refused $C in the application"
step "$C unsigned: refused before anything moved, told"

printf 'onprem-check: OK — installed, rolled back, restored and an unsigned release refused in %ss in all\n' "$(($(date +%s) - started))"
