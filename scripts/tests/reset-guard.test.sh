#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# make reset deletes the stack's database and stored files. On a shop's own computer that stack is the shop's (its
# compose project is twes-in too), so reset refuses there whatever CONFIRM says. The real target runs here from a copy
# of the Makefile in a directory of its own, docker a stand-in writing down what it was asked: nothing here can reach
# the tree's own volumes or files.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
mkdir -p "$TMPDIR/bin"
# The stand-in answers as a shop's stack would when asked: SHOP_ENV, a container of the project started with
# APP_INSTALLATION=onprem; SHOP_VOLUME, the volume a shop's local backups go to.
cat > "$TMPDIR/bin/docker" <<'FAKE'
#!/usr/bin/env bash
echo "docker $*" >> "$TMPDIR/docker.calls"
case "$1 $2" in
  "volume inspect") [[ -n "${SHOP_VOLUME:-}" && "$3" == twes-in_backup-local ]] && exit 0; exit 1 ;;
  "ps -aq") [[ "$*" == *label=com.docker.compose.project=twes-in* ]] && echo c0ffee; exit 0 ;;
  "inspect --format") echo DEPLOY_ENV=prod; [[ -z "${SHOP_ENV:-}" ]] || echo APP_INSTALLATION=onprem; exit 0 ;;
esac
exit 1
FAKE
chmod +x "$TMPDIR/bin/docker"
tree() { # a copy of what make reset reads, and a home whose values file is the one given (none when empty)
  local d; d=$(mktemp -d); mkdir -p "$d/scripts" "$d/home/.twes-in"
  cp "$ROOT/Makefile" "$d/Makefile"; cp "$ROOT/scripts/reset-guard.sh" "$d/scripts/reset-guard.sh" 2>/dev/null
  [[ -z "${1:-}" ]] || printf '%b' "$1" > "$d/home/.twes-in/install.env"
  echo "$d"
}
reset() { # the tree, then make's arguments
  local d=$1; shift; rm -f "$TMPDIR/docker.calls"
  (cd "$d" && env -u TWES_VALUES HOME="$d/home" PATH="$TMPDIR/bin:$PATH" make --no-print-directory reset "$@" 2>&1 < /dev/null)
}
calls() { cat "$TMPDIR/docker.calls" 2>/dev/null; }
echo "reset guard"

d=$(tree "TWES_INSTALL=onprem\nLAN_HOST=192.168.1.20\n"); out=$(reset "$d" CONFIRM=yes)
check "a shop's computer: refused, CONFIRM=yes or not" $? 2 "$out" "refused"
check "  naming the shop's values and the way a shop is taken back" 0 0 "$out" "twes restore-commands"
[[ -z "$(calls)" ]]; check "  and nothing asked of Docker" $? 0 "$(calls)" ""

d=$(tree "TWES_INSTALL=onprem\n"); out=$(reset "$d"); check "  asked without CONFIRM, refused before the question" $? 2 "$out" "refused"
[[ "$out" != *"Type yes"* ]]; check "  (the question never asked)" $? 0 "$out" ""

d=$(tree "TWES_INSTALL=server\nTWES_INSTALL=onprem\n"); out=$(reset "$d" CONFIRM=yes)
check "  the values file's last word decides, as twes reads it" $? 2 "$out" "refused"

d=$(tree "TWES_INSTALL=server\n"); out=$(reset "$d" CONFIRM=yes); check "a server's values: reset goes on" 0 0 "$(calls)" "docker compose down --volumes"
d=$(tree ""); out=$(reset "$d" CONFIRM=yes); check "no values at all, the developer's machine: reset goes on" 0 0 "$(calls)" "docker compose down --volumes"

# The values file is the caller's home: whoever runs make, a shop's stack is the shop's. Docker is asked too.
d=$(tree ""); out=$(SHOP_ENV=1 reset "$d" CONFIRM=yes)
check "no values here, but the project's containers were started as a shop's: refused" $? 2 "$out" "APP_INSTALLATION=onprem"
[[ "$(calls)" != *"down --volumes"* ]]; check "  nothing deleted" $? 0 "$(calls)" ""
d=$(tree ""); out=$(SHOP_VOLUME=1 reset "$d" CONFIRM=yes)
check "no values here, but the shop's local backup volume exists: refused" $? 2 "$out" "twes-in_backup-local"
[[ "$(calls)" != *"down --volumes"* ]]; check "  nothing deleted" $? 0 "$(calls)" ""

d=$(tree "TWES_INSTALL=onprem\n"); mv "$d/home/.twes-in/install.env" "$TMPDIR/elsewhere.env"
out=$(cd "$d" && TWES_VALUES="$TMPDIR/elsewhere.env" HOME="$d/home" PATH="$TMPDIR/bin:$PATH" make --no-print-directory reset CONFIRM=yes 2>&1 < /dev/null)
check "  values named by TWES_VALUES are read too" $? 2 "$out" "refused"

d=$(tree "TWES_INSTALL=onprem\n"); chmod 000 "$d/home/.twes-in/install.env"
if [[ -r "$d/home/.twes-in/install.env" ]]; then echo "  skip an unreadable values file (this account reads anything)"
else out=$(reset "$d" CONFIRM=yes); check "  a values file it cannot read: refused, not taken for none" $? 2 "$out" "cannot be read"; fi

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
