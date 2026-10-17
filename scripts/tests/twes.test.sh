#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The shop's script (scripts/onprem/twes) over a checkout whose origin holds two release tags, with stand-ins for
# deploy.sh (it writes down how it was called and keeps the history as the real one does), curl (the edge's health and
# the mail relay) and the probes of the network address.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
OLD=v2026.10.20.1 NEW=v2026.10.21.1
LABEL=20261020-101500F_20261021-120000I
# 2026-10-21 12:30 UTC, 13:30 in Tunis.
NOON=1792585800

mkdir -p "$TMPDIR/bin" "$TMPDIR/stand-ins"
# deploy.sh: --release and --again keep releases/history as the real one does; --compose answers the backup's label, the
# application's console and the backups' outcomes as each case says.
cat > "$TMPDIR/stand-ins/deploy.sh" <<'EOF'
#!/usr/bin/env bash
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
mode=$1 values=$2; shift 2
records=$(dirname "$values")/releases; mkdir -p "$records"
rev=$(git -C "$root" rev-parse HEAD)
echo "LAN_HOST=${LAN_HOST:-} $mode $*" >> "$TMPDIR/deploy.calls"
case "$mode" in
  --release)
    if [[ "${RELEASE_EXIT:-0}" == 0 ]]; then echo "t deployed $rev release" >> "$records/history"; else echo "t failed $rev release" >> "$records/history"; exit 1; fi ;;
  --again)
    grep -q " $rev " "$records/history" 2>/dev/null || { echo "never deployed here" >&2; exit 1; }
    if [[ "${AGAIN_EXIT:-0}" == 0 ]]; then echo "t started $rev again" >> "$records/history"; else echo "t failed $rev again" >> "$records/history"; exit 1; fi ;;
  --compose)
    case "$*" in
      "exec -T backup twes-backup before-update") [[ -n "${BACKUP_LABEL:-}" ]] || exit 1; echo "2026 twes-backup before-update: $BACKUP_LABEL" ;;
      "exec -T api bin/console app:installation:tell"*) echo "$*" >> "$TMPDIR/told"; [[ -z "${TELL_SAYS:-}" ]] || echo "$TELL_SAYS"; exit "${TELL_EXIT:-0}" ;;
      "exec -T backup sh -c"*) [[ -z "${OUTCOMES_DOWN:-}" ]] || exit 1; printf '%b' "${OUTCOMES:-}" ;;
    esac ;;
esac
EOF
# curl: the edge's root certificate, the deep health (503 for the release named in BAD, or every release when ALL_BAD),
# and the relay, whose message and netrc it keeps.
cat > "$TMPDIR/bin/curl" <<'EOF'
#!/usr/bin/env bash
echo "$*" >> "$TMPDIR/curl.calls"
out='' url='' upload='' netrc=''
while (($#)); do
  case "$1" in -o) out=$2; shift ;; --url) url=$2; shift ;; --upload-file) upload=$2; shift ;; --netrc-file) netrc=$2; shift ;; http*) url=$1 ;; esac
  shift
done
case "$url" in
  */root.crt) [[ -z "${EDGE_DOWN:-}" ]] || exit 7; echo cert > "$out" ;;
  */api/health/deep)
    tag=$(git -C "$TWES_ROOT" describe --exact-match --tags HEAD 2>/dev/null)
    if [[ -n "${ALL_BAD:-}" || "$tag" == "${BAD:-none}" ]]; then echo '{"status":"failing","failing":["queue"]}' > "$out"; printf 503
    else echo '{"status":"ok","failing":[]}' > "$out"; printf 200; fi ;;
  smtp*) cp "$upload" "$TMPDIR/mail.eml"; cp "$netrc" "$TMPDIR/mail.netrc"; echo "$url" > "$TMPDIR/mail.url"; exit "${MAIL_EXIT:-0}" ;;
esac
EOF
printf '#!/usr/bin/env bash\n[[ -z "${NO_ROUTE:-}" ]] || { echo "RTNETLINK answers: Network is unreachable" >&2; exit 2; }\necho "1.1.1.1 via 192.168.1.1 dev eth0 src ${ADDRESS:-192.168.1.20} uid 1000"\n' > "$TMPDIR/bin/ip"
printf '#!/usr/bin/env bash\nprintf "%%s\\r\\n" "${ADDRESS:-192.168.1.30}"\n' > "$TMPDIR/bin/powershell.exe"
chmod +x "$TMPDIR/bin/"* "$TMPDIR/stand-ins/deploy.sh"

# An origin with two releases, each carrying this script and the stand-in deploy.sh, and a shop's clone of it at none.
origin() {
  local w; w=$(mktemp -d)
  mkdir -p "$w/scripts/onprem" "$w/scripts/deploy"
  cp "$HERE/onprem/twes" "$w/scripts/onprem/twes"; cp "$TMPDIR/stand-ins/deploy.sh" "$w/scripts/deploy/deploy.sh"
  git -C "$w" init -q -b master && git -C "$w" add -A && git -C "$w" commit -qm one && git -C "$w" tag -a "$OLD" -m "$OLD"
  echo two > "$w/two"; git -C "$w" add -A && git -C "$w" commit -qm two && git -C "$w" tag -a "$NEW" -m "$NEW"
  git clone -q --bare "$w" "$w.git"; echo "$w.git"
}
shop() { # the clone, and its values beside it under its own folder
  local o d; o=$(origin); d=$(mktemp -d)
  git clone -q "$o" "$d/twes-in"
  mkdir -p "$d/etc"; bash "$HERE/deploy/new-instance.sh" --onprem "$d/etc/install.env" >/dev/null
  sed -i -e 's|^MAILER_DSN=.*|MAILER_DSN=smtp://shop%40gmail.com:abcd%20efgh@smtp.gmail.com:587|' \
    -e 's|^MAILER_SENDER=.*|MAILER_SENDER=shop@gmail.com|' -e 's|^ALERT_EMAIL=.*|ALERT_EMAIL=dev@example.test|' "$d/etc/install.env"
  echo "$d"
}
twes() { # shop folder, then the command
  local d=$1; shift
  rm -f "$TMPDIR/deploy.calls" "$TMPDIR/curl.calls" "$TMPDIR/told" "$TMPDIR/mail.eml" "$TMPDIR/mail.netrc"
  (cd "$TMPDIR" && env PATH="$TMPDIR/bin:$PATH" TWES_VALUES="$d/etc/install.env" TWES_ROOT="$d/twes-in" TWES_PLATFORM="${PLATFORM:-linux}" \
    TWES_HEALTH_PAUSE=0 TWES_NOW="${AT:-$NOON}" bash "$d/twes-in/scripts/onprem/twes" "$@" 2>&1)
}
at_tag() { git -C "$1/twes-in" describe --exact-match --tags HEAD 2>/dev/null; }
deployed() { [[ -f "$TMPDIR/deploy.calls" ]] && tr '\n' '|' < "$TMPDIR/deploy.calls"; }
told() { [[ -f "$TMPDIR/told" ]] && tr '\n' '|' < "$TMPDIR/told"; }
mailed() { [[ -f "$TMPDIR/mail.eml" ]] && cat "$TMPDIR/mail.eml"; }
subject() { mailed | sed -n 's/^Subject: =?UTF-8?B?\(.*\)?=\r$/\1/p' | base64 -d; }
installed() { # a shop running the old release
  local d; d=$(shop); git -C "$d/twes-in" checkout -q --detach "$OLD"
  mkdir -p "$d/etc/releases"; echo "t deployed $(git -C "$d/twes-in" rev-parse HEAD) release" > "$d/etc/releases/history"
  mkdir -p "$d/etc/state"; echo 192.168.1.20 > "$d/etc/state/lan-host"; echo $((NOON - 7200)) > "$d/etc/state/started-at"
  echo "$d"
}
echo "twes"

# start
d=$(shop)
out=$(twes "$d" start); check "nothing deployed: start says how to install" $? 1 "$out" "twes update installs"
d=$(installed)
out=$(twes "$d" start); check "a deployed release starts again" $? 0 "$out" "https://192.168.1.20"
check "  at the address the network gave, nothing fetched" 0 0 "$(deployed)" "LAN_HOST=192.168.1.20 --again"
out=$(PLATFORM=wsl ADDRESS=10.0.0.7 twes "$d" start); check "under WSL, Windows' own address, its line end dropped" $? 0 "$(deployed)" "LAN_HOST=10.0.0.7 --again"
out=$(NO_ROUTE=1 twes "$d" start); check "no network: this computer alone, said so" $? 0 "$out" "only this computer opens https://localhost"
check "  at 127.0.0.1" 0 0 "$(deployed)" "LAN_HOST=127.0.0.1 --again"
echo 'LAN_HOST=192.168.1.99' >> "$d/etc/install.env"
out=$(twes "$d" start); check "an address fixed in the values wins" $? 0 "$(deployed)" "LAN_HOST=192.168.1.99 --again"

# update
d=$(shop)
out=$(twes "$d" update); check "a first install takes the newest release" $? 0 "$out" "updated to $NEW"
[[ "$(at_tag "$d")" == "$NEW" ]]; check "  checked out at its tag" $? 0 "$(at_tag "$d")" ""
[[ "$(deployed)" != *"before-update"* ]]; check "  with nothing to back up before it" $? 0 "$(deployed)" ""

d=$(installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "an update to the newest release" $? 0 "$out" "updated to $NEW"
check "  backs up first, through the release running" 0 0 "$(deployed)" "--compose exec -T backup twes-backup before-update|"
check "  then deploys the new one by digest" 0 0 "$(deployed)" "--release"
check "  keeping the backup's label" 0 0 "$(cat "$d/etc/state/last-update")" "$NEW"
check "  and the health read through the edge with the token from a file, never the command line" 0 0 "$(grep deep "$TMPDIR/curl.calls")" "-H @"
grep -q "$(awk -F= '$1=="MONITOR_TOKEN"{print $2}' "$d/etc/install.env")" "$TMPDIR/curl.calls"; check "  (the token is in no argument)" $? 1 "" ""
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "up to date: nothing done" $? 0 "$out" "up to date: $NEW"

d=$(installed)
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); check "an unhealthy release is rolled back" $? 1 "$out" "starting $OLD again"
[[ "$(at_tag "$d")" == "$OLD" ]]; check "  to the checkout it left" $? 0 "$(at_tag "$d")" ""
check "  started again from its own images" 0 0 "$(deployed)" "--release |LAN_HOST=192.168.1.20 --again"
check "  the developer mailed" 0 0 "$(subject)" "Mise à jour vers $NEW annulée"
check "  with the backup's label" 0 0 "$(mailed)" "$LABEL"
check "  and the operators told in the application" 0 0 "$(told)" "installation.rolled_back version=$NEW previous=$OLD label=$LABEL"
check "  the release written down as failed" 0 0 "$(cat "$d/etc/state/failed-releases")" "$NEW"
out=$(twes "$d" restore-commands); check "restore-commands: what takes it back to before the last update" $? 0 "$out" "--set=$LABEL --type=immediate"
check "  from the release it left" 0 0 "$out" "git checkout --detach $(git -C "$d/twes-in" rev-parse "$OLD^{commit}")"
check "  at this machine's address" 0 0 "$out" "LAN_HOST=192.168.1.20 bash scripts/deploy/deploy.sh --compose $d/etc/install.env stop"
check "  then started again" 0 0 "$out" "  bash scripts/onprem/twes start"
# Pasted as one block, a command reading its input would take the lines after it as that input, and they never run.
runs=$(grep -c ' run --rm ' <<<"$out"); safe=$(grep -c ' run --rm .* < /dev/null$' <<<"$out")
check "  safe to paste as one block: both restores read no input" 0 0 "$runs $safe" "2 2"
d=$(installed)
out=$(twes "$d" restore-commands); check "restore-commands with no update made here: refused" $? 1 "$out" "no update"
out=$(AT=$((NOON + 86400)) BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update)
check "a release that failed may be tried again by hand" $? 1 "$(deployed)" "--release"

d=$(installed)
out=$(ALL_BAD=1 BACKUP_LABEL=$LABEL twes "$d" update); check "the way back unhealthy too: an urgent mail" $? 1 "$(subject)" "URGENT"
check "  naming the restore to the backup before the update" 0 0 "$(mailed)" "--set=$LABEL --type=immediate"
check "  its files from that backup's copy" 0 0 "$(mailed)" "restic restore latest --tag before-update"
check "  the commit to check out first" 0 0 "$(mailed)" "git checkout --detach $(git -C "$d/twes-in" rev-parse "$OLD^{commit}")"
check "  and the operators told" 0 0 "$(told)" "installation.update_failed version=$NEW"

d=$(installed)
out=$(twes "$d" update); check "a backup that names nothing stops the update" $? 1 "$(told)" "installation.backup_failed version=$NEW step=before-update"
[[ "$(at_tag "$d")" == "$OLD" && "$(deployed)" != *"--release"* ]]; check "  and nothing changed" $? 0 "$(deployed)" ""
check "  the developer told why" 0 0 "$(subject)" "sauvegarde d'avant"

d=$(installed); mv "$d/twes-in/.git/config" "$d/config.kept"; printf '[core]\n\trepositoryformatversion = 0\n[remote "origin"]\n\turl = %s\n' "$TMPDIR/nowhere.git" > "$d/twes-in/.git/config"
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "no network: nothing changes" $? 1 "$out" "nothing changed"

# alerts
d=$(installed)
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update)
check "the relay is the shop's account, STARTTLS required" 0 0 "$(grep smtp "$TMPDIR/curl.calls")" "--ssl-reqd --netrc-file"
check "  at its host and port" 0 0 "$(cat "$TMPDIR/mail.url")" "smtp://smtp.gmail.com:587"
check "  the account and password decoded from the address, in a netrc" 0 0 "$(cat "$TMPDIR/mail.netrc")" "machine smtp.gmail.com login shop@gmail.com password abcd efgh"
grep -q "abcd" "$TMPDIR/curl.calls"; check "  never on curl's command line" $? 1 "" ""
[[ -z "$(ls "$d/etc/state" | grep -E 'netrc|eml')" ]]; check "  and not left behind" $? 0 "$(ls "$d/etc/state")" ""
check "  the mail to the developer" 0 0 "$(mailed)" "To: dev@example.test"

d=$(installed)
out=$(TELL_EXIT=1 BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); check "an application that does not answer: the alert held back" $? 1 "$out" "held back"
check "  written down" 0 0 "$(cat "$d/etc/state/pending-alerts")" "installation.rolled_back"
out=$(twes "$d" start); check "  delivered once it answers" $? 0 "$(told)" "installation.rolled_back"
[[ ! -e "$d/etc/state/pending-alerts" ]]; check "  and forgotten" $? 0 "" ""
d=$(installed)
out=$(TELL_EXIT=2 TELL_SAYS="unhealthy needs check." BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update)
check "a line the application refuses: said with its reason" $? 1 "$out" "refused installation.rolled_back: unhealthy needs check."
[[ ! -e "$d/etc/state/pending-alerts" ]]; check "  never held back, since it would never be delivered" $? 0 "" ""
d=$(installed)
out=$(TELL_EXIT=1 BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update)
out=$(TELL_EXIT=2 twes "$d" start); check "a held line the application refuses: said" $? 0 "$out" "refused installation.rolled_back"
[[ ! -e "$d/etc/state/pending-alerts" ]]; check "  and dropped" $? 0 "" ""

d=$(installed); sed -i 's|^ALERT_EMAIL=.*|ALERT_EMAIL=|' "$d/etc/install.env"
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); check "without ALERT_EMAIL nothing is mailed, and it says so" $? 1 "$out" "not mailed"

# policy
d=$(installed)
out=$(twes "$d" policy auto 13:00-14:00); check "updates inside a window" $? 0 "$out" "between 13:00 and 14:00"
check "  written in the values" 0 0 "$(grep -E '^UPDATE_(POLICY|WINDOW)=' "$d/etc/install.env" | tr '\n' ' ')" "UPDATE_POLICY=auto UPDATE_WINDOW=13:00-14:00"
check "  which stay readable by their owner alone" 0 0 "$(stat -c %a "$d/etc/install.env")" "600"
out=$(twes "$d" policy auto 1pm); check "a window not written HH:MM-HH:MM is refused" $? 1 "$out" "HH:MM-HH:MM"
out=$(twes "$d" policy auto 14:00-13:00); check "a window that ends before it starts is refused" $? 1 "$out" "ends before"
out=$(twes "$d" policy notify); check "back to notify, the window cleared" $? 0 "$(grep -E '^UPDATE_(POLICY|WINDOW)=' "$d/etc/install.env" | tr '\n' ' ')" "UPDATE_POLICY=notify UPDATE_WINDOW= "
out=$(twes "$d" policy sometimes); check "any other policy is refused" $? 2 "$out" "Usage"

# offsite
d=$(installed)
out=$(twes "$d" offsite on); check "the off-site copy needs its bucket first" $? 1 "$out" "BACKUP_S3_ENDPOINT"
sed -i -e 's|^BACKUP_S3_ENDPOINT=.*|BACKUP_S3_ENDPOINT=s3.example|' -e 's|^BACKUP_S3_BUCKET=.*|BACKUP_S3_BUCKET=b|' -e 's|^BACKUP_S3_REGION=.*|BACKUP_S3_REGION=r|' \
  -e 's|^BACKUP_S3_KEY=.*|BACKUP_S3_KEY=k|' -e 's|^BACKUP_S3_SECRET=.*|BACKUP_S3_SECRET=s|' "$d/etc/install.env"
out=$(twes "$d" offsite on); check "  given, it is switched on" $? 0 "$(grep '^BACKUP_OFFSITE=' "$d/etc/install.env")" "BACKUP_OFFSITE=on"
check "  and the installation started again over it" 0 0 "$(deployed)" "--again"

# tick
d=$(installed)
out=$(ADDRESS=192.168.1.42 twes "$d" tick); check "a new network address: started again at it" $? 0 "$(deployed)" "LAN_HOST=192.168.1.42 --again"
out=$(ALL_BAD=1 twes "$d" tick); check "unhealthy: the developer told" $? 0 "$(subject)" "ne va pas bien (queue)"
check "  and the operators" 0 0 "$(told)" "installation.unhealthy check=queue"
out=$(ALL_BAD=1 twes "$d" tick); [[ -z "$(told)" ]]; check "  once, not every quarter hour" $? 0 "$(told)" ""
out=$(twes "$d" tick); out=$(ALL_BAD=1 twes "$d" tick); check "  and again once it mended and failed anew" $? 0 "$(told)" "installation.unhealthy"

d=$(installed)
out=$(OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick)
check "a failed backup step: the developer told" $? 0 "$(subject)" "Sauvegarde en échec : copy"
check "  and the operators" 0 0 "$(told)" "installation.backup_failed step=copy"
out=$(OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick); [[ -z "$(told)" ]]; check "  once for that failure" $? 0 "$(told)" ""

d=$(installed)
out=$(OUTCOMES="due $((NOON - 6000)) ran\n" twes "$d" tick); check "a schedule silent for over an hour and a half: told" $? 0 "$(told)" "installation.backup_stopped"
out=$(OUTCOMES="due $((NOON - 6000)) ran\n" twes "$d" tick); [[ -z "$(told)" ]]; check "  once a day" $? 0 "$(told)" ""
d=$(installed); echo $((NOON - 600)) > "$d/etc/state/started-at"
out=$(OUTCOMES="due $((NOON - 6000)) ran\n" twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]; check "  but not while the computer has just started" $? 0 "$(told)" ""
d=$(installed)
out=$(OUTCOMES_DOWN=1 twes "$d" tick); check "a backup service that does not answer: told" $? 0 "$(told)" "installation.backup_stopped"

d=$(installed)
out=$(twes "$d" tick); check "notify: the owners told a release is out" $? 0 "$(told)" "installation.update_available version=$NEW previous=$OLD"
[[ "$(at_tag "$d")" == "$OLD" ]]; check "  and nothing applied" $? 0 "" ""
out=$(twes "$d" tick); [[ -z "$(told)" ]]; check "  once per release" $? 0 "$(told)" ""

d=$(installed); twes "$d" policy auto 13:00-14:00 >/dev/null
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); check "auto inside the window: applied" $? 0 "$out" "updated to $NEW"
d=$(installed); twes "$d" policy auto 15:00-16:00 >/dev/null
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "auto outside the window: waits" $? 0 "$(deployed)" ""
d=$(installed); twes "$d" policy auto 13:00-14:00 >/dev/null; echo $((NOON - 300)) > "$d/etc/state/started-at"
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "auto never at start" $? 0 "$(deployed)" ""
d=$(installed); twes "$d" policy auto 13:00-14:00 >/dev/null
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" tick)
out=$(AT=$((NOON + 600)) BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "auto tries once a day" $? 0 "$(deployed)" ""
out=$(AT=$((NOON + 86400)) BACKUP_LABEL=$LABEL twes "$d" tick); check "  and never again a release that failed here" $? 0 "$out" "applied only by hand"

out=$(twes "$d" sometimes); check "an unknown command is refused" $? 2 "$out" "Usage"
d=$(installed); rm "$d/etc/install.env"; out=$(twes "$d" status); check "no values: refused, naming where they are looked for" $? 1 "$out" "new-instance.sh --onprem"

echo "twes: $pass passed, $fail failed"
((fail == 0))
