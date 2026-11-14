#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The shop's script (scripts/onprem/twes) over a checkout whose origin holds two release tags, with stand-ins for
# deploy.sh (it writes down how it was called and keeps the history as the real one does), curl (the edge's health and
# the mail relay) and the probes of the network address.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR
# Every case's fixture is made here and goes with it, each keyring's agents stopped first.
trap 'for g in "$TMPDIR"/*gnupg "$TMPDIR"/*/etc/release-keys; do [[ -d "$g" ]] && GNUPGHOME=$g gpgconf --kill all 2>/dev/null; done; rm -rf "$TMPDIR"' EXIT
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
# The developer's release key and a stranger's, each a throwaway with no passphrase in a keyring of its own.
newkey() { # keyring, name: prints the fingerprint
  mkdir -m 700 "$1"
  GNUPGHOME=$1 gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key "$2 <$2@twes.test>" ed25519 sign never 2>/dev/null
  GNUPGHOME=$1 gpg --batch --with-colons --list-secret-keys | awk -F: '$1 == "fpr" { print $10; exit }'
}
DEV=$(newkey "$TMPDIR/dev-gnupg" release) OTHER=$(newkey "$TMPDIR/other-gnupg" stranger)
GNUPGHOME=$TMPDIR/dev-gnupg gpg --batch --armor --export "$DEV" > "$TMPDIR/release-key.asc"
GNUPGHOME=$TMPDIR/other-gnupg gpg --batch --armor --export "$OTHER" > "$TMPDIR/other-key.asc"
GNUPGHOME=$TMPDIR/dev-gnupg gpg --batch --armor --pinentry-mode loopback --passphrase '' --export-secret-keys "$DEV" > "$TMPDIR/secret-key.asc"
sign() { # repository, tag, by whom (SIGN_NEW for the newest: dev, the default, other or none), then its message's values
  local by=${3:-dev} message=$2${4:+$'\n\n'$4}
  case "$by" in
    dev) GNUPGHOME=$TMPDIR/dev-gnupg git -C "$1" tag -s -u "$DEV" "$2" -m "$message" ;;
    other) GNUPGHOME=$TMPDIR/other-gnupg git -C "$1" tag -s -u "$OTHER" "$2" -m "$message" ;;
    none) git -C "$1" tag -a "$2" -m "$message" ;;
  esac
}
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
    # RELEASE_CUT: the update killed while it deploys (before the release is written down), or once it is written
    # down and before its health is read (after), as a computer switched off or a task's time limit would.
    if [[ -n "${RELEASE_CUT:-}" ]]; then [[ "$RELEASE_CUT" != after ]] || echo "t deployed $rev release" >> "$records/history"; kill -9 "$PPID"; exit 137; fi
    if [[ "${RELEASE_EXIT:-0}" == 0 ]]; then echo "t deployed $rev release" >> "$records/history"; else echo "t failed $rev release" >> "$records/history"; exit 1; fi ;;
  --again)
    grep -q " $rev " "$records/history" 2>/dev/null || { echo "never deployed here" >&2; exit 1; }
    if [[ "${AGAIN_EXIT:-0}" == 0 ]]; then echo "t started $rev again" >> "$records/history"; else echo "t failed $rev again" >> "$records/history"; exit 1; fi ;;
  --compose)
    case "$*" in
      "exec -T backup twes-backup before-update") [[ -n "${BACKUP_LABEL:-}" ]] || exit 1; echo "2026 twes-backup before-update: $BACKUP_LABEL" ;;
      "exec -T api bin/console app:installation:tell"*) echo "$*" >> "$TMPDIR/told"; [[ -z "${TELL_SAYS:-}" ]] || echo "$TELL_SAYS"; exit "${TELL_EXIT:-0}" ;;
      "exec -T api bin/console app:installation:sign-ins"*)
        echo "$*" > "$TMPDIR/sign-ins.asked"
        [[ "${SIGN_INS_EXIT:-0}" == 0 ]] || { echo "service \"api\" is not running" >&2; exit 1; }
        echo "${SIGN_INS:-failed=0 locked=0}" ;;
      # The web tier's access lines of the hour, WEB_LOG's (its \n written so).
      "logs --no-log-prefix --since 60m web") printf '%b' "${WEB_LOG:-}" ;;
      "exec -T api bin/console app:seed"*) { echo "$*"; cat; } > "$TMPDIR/seeded"; exit "${SEED_EXIT:-0}" ;;
      "exec -T api bin/console app:demo:switch-off-at-install"*) echo "$*" >> "$TMPDIR/demo-off"; [[ "${DEMO_EXIT:-0}" != 0 ]] || echo "${DEMO_SAYS:-demo switched off}"; exit "${DEMO_EXIT:-0}" ;;
      # twes export: the backup folder (BACKUP_TREE, failing with BACKUP_TAR_FAILS; whether the hold stood is kept),
      # PostgreSQL's major, the last migration, the counts, the edge's authority (EDGE_CA) and the release's images.
      "exec -T backup tar -C /backup -cf - .") [[ -z "${BACKUP_TAR_FAILS:-}" ]] || exit 1
        [[ ! -f "$(dirname "$values")/state/hold" ]] || echo held > "$TMPDIR/held-while-read"; tar -C "$BACKUP_TREE" -cf - . ;;
      "exec -T postgres sh -c"*) echo "${PG_MAJOR:-18}" ;;
      "exec -T api bin/console doctrine:migrations:current"*) echo 'DoctrineMigrations\Version20261114183314' ;;
      "exec -T api bin/console app:fiscal:inventory --json"*) echo '{"fiscal":true,"companies":[{"name":"Shop","fiscal":true}],"platform":[]}'; exit "${INVENTORY_EXIT:-3}" ;;
      "exec -T edge tar -C /data/caddy/pki/authorities -cf - local") [[ -n "${EDGE_CA:-}" ]] || exit 1; tar -C "$EDGE_CA" -cf - local ;;
      "config --images") printf 'ghcr.io/x/api@sha256:aa\nghcr.io/x/web@sha256:bb\n' ;;
      "exec -T backup sh -c"*)
        [[ -z "${OUTCOMES_DOWN:-}" ]] || exit 1
        # OUTCOME_DIR: twes's own script run against that folder, on the clock of whatever runs it, as the container does.
        if [[ -n "${OUTCOME_DIR:-}" ]]; then script=${*: -1}; sh -c "${script//\/var\/lib\/twes-backup\/outcome/$OUTCOME_DIR}"; exit; fi
        printf '%b' "${OUTCOMES:-}" ;;
    esac ;;
esac
EOF
# curl: the edge's root certificate, the deep health (503 for the release named in BAD, or every release when ALL_BAD, or
# for the first HEALTH_FAILS reads, as a container stopped and started again), and the relay, whose message and netrc it
# keeps.
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
    reads=$(($(cat "$TMPDIR/health.reads" 2>/dev/null || echo 0) + 1)); echo "$reads" > "$TMPDIR/health.reads"
    if [[ -n "${ALL_BAD:-}" || "$tag" == "${BAD:-none}" ]] || ((reads <= ${HEALTH_FAILS:-0})); then if [[ "${STEP_FAILS:-}" == mixed ]]; then echo '{"status":"failing","failing":["queue","startSteps"],"readings":{"failedStartSteps":["app:invoices:seal-chain"],"oldestWaitingSeconds":0}}' > "$out"; elif [[ -n "${STEP_FAILS:-}" ]]; then echo '{"status":"failing","failing":["startSteps"],"readings":{"failedStartSteps":["app:invoices:seal-chain","app:platform:converge"],"oldestWaitingSeconds":0}}' > "$out"; else echo '{"status":"failing","failing":["queue"]}' > "$out"; fi; printf 503
    else echo '{"status":"ok","failing":[]}' > "$out"; printf 200; fi ;;
  https://api.ipify.org) [[ "${PUBLIC:-}" != silent ]] || exit 6; echo "${PUBLIC:-41.226.10.20}" ;;
  */api/auth/demo) [[ -z "${DEMO_SILENT:-}" ]] || { printf 000; exit 7; }; echo "{\"enabled\":${DEMO_OPEN:-false},\"countries\":[]}" > "$out"; printf 200 ;;
  smtp*) cp "$upload" "$TMPDIR/mail.eml"; cp "$netrc" "$TMPDIR/mail.netrc"; echo "$url" > "$TMPDIR/mail.url"; exit "${MAIL_EXIT:-0}" ;;
esac
EOF
printf '#!/usr/bin/env bash\n[[ -z "${NO_ROUTE:-}" ]] || { echo "RTNETLINK answers: Network is unreachable" >&2; exit 2; }\necho "1.1.1.1 via 192.168.1.1 dev eth0 src ${ADDRESS:-192.168.1.20} uid 1000"\n' > "$TMPDIR/bin/ip"
# powershell.exe: Windows' address, or asked its clock, Windows' time in seconds (WINDOWS_NOW, else the case's own); asked
# one of twes doctor's network probes, what its variable says, and nothing at all when it says « silent ».
cat > "$TMPDIR/bin/powershell.exe" <<'EOF'
#!/usr/bin/env bash
answer() { [[ "$1" == silent ]] || printf '%s\r\n' "$1"; }
case "$*" in
  *UtcNow*) [[ -n "${WINDOWS_NOW+set}" ]] || WINDOWS_NOW=$TWES_NOW; printf "%s\r\n" "$WINDOWS_NOW" ;;
  *NextHop*) answer "${GATEWAY:-192.168.1.1}" ;;
  *PrefixOrigin*ValidLifetime*) answer "${LEASE:-Dhcp 86400}" ;;
  *TraceRoute*) answer "${HOPS:-192.168.1.1 197.0.0.1 197.0.0.9}" ;;
  *"AddressFamily IPv6"*) answer "${IPV6:-none}" ;;
  *NATUPnP*) answer "${UPNP:-off}" ;;
  *STANDBYIDLE*) answer "${SLEEP:-0}" ;;
  *Win32_Battery*) answer "${UPS:-none}" ;;
  *) printf "%s\r\n" "${ADDRESS:-192.168.1.30}" ;;
esac
EOF
# tailscale: its netcheck report, or nothing when TAILSCALE says « silent ».
cat > "$TMPDIR/bin/tailscale" <<'EOF'
#!/usr/bin/env bash
[[ "$*" == netcheck && "${TAILSCALE:-}" != silent ]] || exit 1
printf '\nReport:\n\t* UDP: true\n\t* IPv4: yes, 41.226.10.20:41641\n\t* IPv6: no, but OS has support\n\t* MappingVariesByDestIP: false\n\t* Nearest DERP: Frankfurt\n'
EOF
# docker: its daemon answers info once DOCKER_LATE tries have been made, never when it is "never"; asked its system, it
# names DOCKER_OS, Docker Engine's Ubuntu by default.
cat > "$TMPDIR/bin/docker" <<'EOF'
#!/usr/bin/env bash
if [[ "$1" == save ]]; then echo "$*" > "$TMPDIR/docker.save"; echo images > "$3"; exit "${SAVE_EXIT:-0}"; fi
[[ "$1" == info ]] || exit 0
tries=$(($(cat "$TMPDIR/docker.tries" 2>/dev/null || echo 0) + 1)); echo "$tries" > "$TMPDIR/docker.tries"
[[ "${DOCKER_LATE:-0}" != never ]] && ((tries > ${DOCKER_LATE:-0})) || { echo "Cannot connect to the Docker daemon" >&2; exit 1; }
[[ "$*" != *--format* ]] || echo "${DOCKER_OS:-Ubuntu 24.04.1 LTS}"
EOF
# sudo and systemctl: what they were asked is written down; systemctl fails when SYSTEMCTL_EXIT says so.
printf '#!/usr/bin/env bash\necho "sudo $*" >> "$TMPDIR/systemd.calls"\nexec "$@"\n' > "$TMPDIR/bin/sudo"
printf '#!/usr/bin/env bash\necho "systemctl $*" >> "$TMPDIR/systemd.calls"\nexit "${SYSTEMCTL_EXIT:-0}"\n' > "$TMPDIR/bin/systemctl"
chmod +x "$TMPDIR/bin/"* "$TMPDIR/stand-ins/deploy.sh"

# An origin with two releases, each carrying this script and the stand-in deploy.sh, and a shop's clone of it at none.
origin() {
  local w; w=$(mktemp -d)
  mkdir -p "$w/scripts/onprem" "$w/scripts/deploy" "$w/infra/onprem"
  cp "$HERE/onprem/twes" "$w/scripts/onprem/twes"; cp -r "$HERE/onprem/lib" "$w/scripts/onprem/lib"; cp "$TMPDIR/stand-ins/deploy.sh" "$w/scripts/deploy/deploy.sh"
  cp "$HERE/deploy/new-instance.sh" "$w/scripts/deploy/new-instance.sh"; cp "$TMPDIR/release-key.asc" "$w/infra/onprem/release-key.asc"
  git -C "$w" init -q -b master && git -C "$w" add -A && git -C "$w" commit -qm one && sign "$w" "$OLD"
  echo two > "$w/two"; git -C "$w" add -A && git -C "$w" commit -qm two && sign "$w" "$NEW" "${SIGN_NEW:-dev}" "${SECURITY_NEW:+SECURITY=$SECURITY_NEW}"
  # PATCH: a patch on the old release's own branch (the maintenance lane), tagged after the newest, master left as it is.
  if [[ -n "${PATCH:-}" ]]; then
    git -C "$w" checkout -q -b "release/${OLD:1:10}" "$OLD"; echo patch > "$w/patch"; git -C "$w" add -A
    git -C "$w" commit -qm patch && sign "$w" "$PATCH"; git -C "$w" checkout -q master
  fi
  git clone -q --bare "$w" "$w.git"; echo "$w.git"
}
shop() { # the clone, and its values beside it under its own folder
  local o d; o=$(origin); d=$(mktemp -d)
  # DEEP: a folder whose keyring path is longer than a socket's, as a shop's own folder may be.
  [[ -z "${DEEP:-}" ]] || { d=$d/$(printf 'deep%.0s' {1..20}); mkdir -p "$d"; }
  git clone -q "$o" "$d/twes-in"
  mkdir -p "$d/etc"; bash "$HERE/deploy/new-instance.sh" --onprem "$d/etc/install.env" >/dev/null
  sed -i -e 's|^MAILER_DSN=.*|MAILER_DSN=smtp://shop%40gmail.com:abcd%20efgh@smtp.gmail.com:587|' \
    -e 's|^MAILER_SENDER=.*|MAILER_SENDER=shop@gmail.com|' -e 's|^ALERT_EMAIL=.*|ALERT_EMAIL=dev@example.test|' "$d/etc/install.env"
  [[ -n "${UNPINNED:-}" ]] || twes "$d" pin-key "$TMPDIR/release-key.asc" >/dev/null
  echo "$d"
}
twes() { # shop folder, then the command
  local d=$1; shift
  rm -f "$TMPDIR/demo-off" "$TMPDIR/deploy.calls" "$TMPDIR/curl.calls" "$TMPDIR/told" "$TMPDIR/mail.eml" "$TMPDIR/mail.netrc" "$TMPDIR/docker.tries" "$TMPDIR/seeded" \
    "$TMPDIR/systemd.calls" "$TMPDIR/health.reads"
  mkdir -p "$d/units"
  (cd "$TMPDIR" && env PATH="$TMPDIR/bin:$PATH" TWES_VALUES="$d/etc/install.env" TWES_ROOT="$d/twes-in" TWES_PLATFORM="${PLATFORM:-linux}" TWES_SYSTEMD_DIR="$d/units" TWES_BIN_DIR="$d/bin" \
    TWES_HEALTH_PAUSE=0 TWES_DOCKER_PAUSE=0 TWES_DOCKER_TRIES=4 TWES_NOW="${AT:-$NOON}" bash "$d/twes-in/scripts/onprem/twes" "$@" 2>&1)
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
  # Already told today that no copy leaves the computer, so each case's own mail is the one read; OFFSITE_UNTOLD forgets it.
  [[ -n "${OFFSITE_UNTOLD:-}" ]] || echo 2026-10-21 > "$d/etc/state/offsite-told"
  echo "$d"
}
bare() { local d; d=$(mktemp -d); git clone -q "$(origin)" "$d/twes-in"; echo "$d"; } # a clone, nothing else yet
value_of() { awk -F= -v k="$2" '$1 == k { v = substr($0, length(k) + 2) } END { print v }' "$1/etc/install.env"; }
FPR=$(GNUPGHOME=$TMPDIR/dev-gnupg gpg --batch --with-colons --list-keys "$DEV" | awk -F: '$1 == "fpr" { print $10; exit }')
# NIGHT: whether the computer stays on at night; no asks the closing hour (CLOSING); WINDOW: what is typed at the window
# proposed (blank takes it).
answers() {
  printf '%s\n' shop@gmail.com 'abcd efgh ijkl mnop' dev@example.test "${BACKUPS:-}" "${NIGHT:-yes}"
  [[ "${NIGHT:-yes}" == yes ]] || printf '%s\n' "${CLOSING:-19:00}"
  printf '%s\n' "${WINDOW:-}" "${CONFIRM:-yes}" op@shop.test "${PW1:-a long passphrase 2026}" "${PW2:-a long passphrase 2026}"
}
echo "twes"

# start
d=$(shop)
out=$(twes "$d" start); check "nothing deployed: start says how to install" $? 1 "$out" "twes update installs"
d=$(installed)
out=$(twes "$d" start); check "a deployed release starts again" $? 0 "$out" "https://192.168.1.20"
check "  at the address the network gave, nothing fetched" 0 0 "$(deployed)" "LAN_HOST=192.168.1.20 --again"
out=$(PLATFORM=wsl ADDRESS=10.0.0.7 twes "$d" start); check "under WSL, Windows' own address, its line end dropped" $? 0 "$(deployed)" "LAN_HOST=10.0.0.7 --again"
# Started by systemd (-Engine Wsl), Windows' PATH is not appended: powershell.exe is found where Windows keeps it, and
# a probe that cannot run at all keeps the address last read rather than moving the shop to 127.0.0.1.
mkdir -p "$TMPDIR/windows"; mv "$TMPDIR/bin/powershell.exe" "$TMPDIR/windows/powershell.exe"
out=$(PLATFORM=wsl ADDRESS=10.0.0.8 TWES_POWERSHELL=$TMPDIR/windows/powershell.exe twes "$d" start)
check "under WSL from systemd, powershell.exe off the PATH: found where Windows keeps it" $? 0 "$(deployed)" "LAN_HOST=10.0.0.8 --again"
out=$(PLATFORM=wsl TWES_POWERSHELL=$TMPDIR/nowhere.exe twes "$d" start)
check "  nowhere at all: the address last read is kept" $? 0 "$(deployed)" "LAN_HOST=10.0.0.8 --again"
check "  said so" 0 0 "$out" "Windows' address could not be read (no powershell.exe): 10.0.0.8 kept"
mv "$TMPDIR/windows/powershell.exe" "$TMPDIR/bin/powershell.exe"
out=$(NO_ROUTE=1 twes "$d" start); check "no network: this computer alone, said so" $? 0 "$out" "only this computer opens https://localhost"
check "  at 127.0.0.1" 0 0 "$(deployed)" "LAN_HOST=127.0.0.1 --again"
echo 'LAN_HOST=192.168.1.99' >> "$d/etc/install.env"
out=$(twes "$d" start); check "an address fixed in the values wins" $? 0 "$(deployed)" "LAN_HOST=192.168.1.99 --again"
d=$(installed)
out=$(DOCKER_LATE=2 twes "$d" start); check "Docker still starting at logon: waited for, then started" $? 0 "$out" "Docker answered after 2 tries"
check "  then the release" 0 0 "$(deployed)" "--again"
out=$(DOCKER_LATE=never twes "$d" start); check "Docker never answering: refused after its tries, saying what to start" $? 1 "$out" "Docker did not answer after 4 tries"
[[ -z "$(deployed)" ]]; check "  and nothing started" $? 0 "$(deployed)" ""
out=$(twes "$d" start); check "Docker up already: no word about waiting" $? 0 "$out" "running:"
[[ "$out" != *"Docker answered"* ]]; check "  (none)" $? 0 "$out" ""

# schedule
unit() { cat "$1/units/$2"; }
systemd_calls() { [[ -f "$TMPDIR/systemd.calls" ]] && tr '\n' '|' < "$TMPDIR/systemd.calls"; }
d=$(installed)
out=$(twes "$d" schedule); check "schedule on Linux: done" $? 0 "$out" "scheduled: twes-in-start.service at boot, twes-in-tick.timer every quarter hour, as user $(id -u)"
check "  start runs as this account" 0 0 "$(unit "$d" twes-in-start.service)" "User=$(id -u)"
check "  with these values" 0 0 "$(unit "$d" twes-in-start.service)" "Environment=\"TWES_VALUES=$d/etc/install.env\""
check "  this checkout's twes start" 0 0 "$(unit "$d" twes-in-start.service)" "ExecStart=$(command -v bash) \"$d/twes-in/scripts/onprem/twes\" start"
check "  once Docker runs" 0 0 "$(unit "$d" twes-in-start.service)" "Requires=docker.service
After=docker.service network-online.target"
check "  at boot" 0 0 "$(unit "$d" twes-in-start.service)" "WantedBy=multi-user.target"
check "  tick the same way" 0 0 "$(unit "$d" twes-in-tick.service)" "ExecStart=$(command -v bash) \"$d/twes-in/scripts/onprem/twes\" tick"
check "  every quarter hour" 0 0 "$(unit "$d" twes-in-tick.timer)" "OnCalendar=*:0/15"
check "  through sudo: units read again, start enabled, the timer enabled and started" 0 0 "$(systemd_calls)" \
  "sudo systemctl daemon-reload|systemctl daemon-reload|sudo systemctl enable twes-in-start.service|systemctl enable twes-in-start.service|sudo systemctl enable --now twes-in-tick.timer|systemctl enable --now twes-in-tick.timer|"
[[ "$(stat -c %a "$d/units/twes-in-start.service")" == 644 ]]; check "  units readable by systemd" $? 0 "$(stat -c '%a %n' "$d/units/"*)" ""
out=$(PLATFORM=wsl DOCKER_OS='Docker Desktop' twes "$d" schedule); check "schedule under WSL with Docker Desktop: refused, install.ps1 schedules there" $? 1 "$out" "install.ps1"
out=$(PLATFORM=wsl twes "$d" schedule); check "schedule under WSL with Docker Engine in Ubuntu: systemd's units, as on Linux" $? 0 "$out" "scheduled: twes-in-start.service at boot"
check "  once Docker runs" 0 0 "$(unit "$d" twes-in-start.service)" "Requires=docker.service"
out=$(PLATFORM=wsl DOCKER_LATE=never twes "$d" schedule); check "  Docker not answering: refused, nothing guessed" $? 1 "$out" "Docker does not answer"
out=$(PLATFORM=macos twes "$d" schedule); check "schedule on macOS: refused, not built" $? 1 "$out" "not built"
out=$(SYSTEMCTL_EXIT=1 twes "$d" schedule); check "systemctl refusing: said" $? 1 "$out" "systemd did not take"
d=$(mktemp -d)/'100% shop'; mkdir -p "$d"; cp -r "$(installed)"/. "$d"
out=$(twes "$d" schedule); check "a percent sign in a path is written as systemd reads it" $? 0 "$(unit "$d" twes-in-start.service)" "TWES_VALUES=${d//%/%%}/etc/install.env"
d=$(mktemp -d)/'a "quoted" shop'; mkdir -p "$d"; cp -r "$(installed)"/. "$d"
out=$(twes "$d" schedule); check "a double quote in a path: refused" $? 1 "$out" "cannot be written in a systemd unit"

# install
d=$(bare)
out=$(answers | twes "$d" install); check "install on a computer with nothing: done" $? 0 "$out" "installed: $NEW"
check "  start and tick scheduled, on Linux" 0 0 "$out" "scheduled: twes-in-start.service"
check "  saying where it stands: the release's download first, with how long it usually takes" 0 0 "$out" "[1/4] downloading and starting $NEW: its images, then the database's migrations (usually 5-10 min"
check "  and what comes next" 0 0 "$out" "— next: reading its health through the edge"
check "  each step's time once it ends" 0 0 "$out" "[1/4] done in "
check "  then the operator and the systemd units, the last step" 0 0 "$out" "[4/4] giving twes start and twes tick to systemd"
check "  every step written in the log too" 0 0 "$(cat "$d/etc/state/twes.log")" "[3/4] making the platform operator"
check "  its values written, a shop's" 0 0 "$(value_of "$d" TWES_INSTALL)" "onprem"
[[ "$(stat -c %a "$d/etc/install.env")" == 600 && "$(stat -c %a "$d/etc")" == 700 ]]; check "  readable by its owner alone" $? 0 "$(stat -c '%a %n' "$d/etc" "$d/etc/install.env")" ""
check "  the shop's Gmail as the relay, the address and the app password encoded, its spaces gone" 0 0 "$(value_of "$d" MAILER_DSN)" "smtp://shop%40gmail.com:abcdefghijklmnop@smtp.gmail.com:587"
check "  the sender" 0 0 "$(value_of "$d" MAILER_SENDER)" "shop@gmail.com"
check "  who is alerted" 0 0 "$(value_of "$d" ALERT_EMAIL)" "dev@example.test"
check "  updates applied by themselves at night, the window proposed taken" 0 0 "$(value_of "$d" UPDATE_POLICY) $(value_of "$d" UPDATE_WINDOW)" "auto 02:00-05:00"
check "  the release key's fingerprint shown before it is pinned" 0 0 "$out" "$FPR"
check "  pinned" 0 0 "$(GNUPGHOME=$d/etc/release-keys gpg --batch --with-colons --list-keys 2>/dev/null)" "$FPR"
check "  the newest signed release deployed" 0 0 "$(at_tag "$d")" "$NEW"
check "  a twes shortcut made" 0 0 "$(cat "$d/bin/twes" 2>&1)" "exec bash '$d/twes-in/scripts/onprem/twes' \"\$@\""
out2=$(env TWES_VALUES="$d/etc/install.env" TWES_ROOT="$d/twes-in" PATH="$TMPDIR/bin:$PATH" bash "$d/bin/twes" bogus 2>&1)
check "  which runs this checkout's twes" $? 2 "$out2" "Usage: twes install"
check "  and the end of the install says how twes is typed" 0 0 "$out" "type twes"
check "  the operator made through the application" 0 0 "$(head -n 1 "$TMPDIR/seeded")" "app:seed --no-interaction --operator-email=op@shop.test --operator-password-stdin --without-company"
check "  the password on its input" 0 0 "$(sed -n 2p "$TMPDIR/seeded")" "a long passphrase 2026"
check "  the demo switched off through the application, once the operator exists" 0 0 "$(cat "$TMPDIR/demo-off" 2>&1)" "app:demo:switch-off-at-install"
check "  and said" 0 0 "$out" "the demo is off on this computer"
[[ "$(head -n 1 "$TMPDIR/seeded")" != *passphrase* ]]; check "  never on its command line" $? 0 "$(head -n 1 "$TMPDIR/seeded")" ""
[[ "$out" != *"a long passphrase"* && "$out" != *abcd* ]]; check "  and no secret said back" $? 0 "$out" ""
out=$(twes "$d" install < /dev/null); check "install again: nothing asked, nothing redone" $? 0 "$out" "installed: $NEW"
[[ "$out" != *"[1/"* ]]; check "  and no step said, one left being none worth counting" $? 0 "$out" ""
[[ ! -e "$TMPDIR/seeded" && "$(deployed)" != *--release* ]]; check "  (no operator, no deployment)" $? 0 "$(deployed)" ""
out=$(DEMO_SAYS='demo left as chosen' twes "$d" install < /dev/null); [[ "$out" != *"the demo is off"* ]]; check "  the demo asked again, an operator's choice kept and nothing said" $? 0 "$(cat "$TMPDIR/demo-off" 2>&1)" "app:demo:switch-off-at-install"
out=$(DEMO_EXIT=1 twes "$d" install < /dev/null); check "  the application not switching the demo off: installed all the same, saying what to do" $? 0 "$out" "switch the demo off in /platform"
out=$(PLATFORM=macos twes "$d" install); check "install again on macOS: scheduling said not built" $? 0 "$out" "not scheduled on macOS"
out=$(PLATFORM=wsl DOCKER_OS='Docker Desktop' twes "$d" install); [[ "$out" != *scheduled* ]]; check "install again under WSL with Docker Desktop: scheduling left to install.ps1" $? 0 "$out" ""
out=$(PLATFORM=wsl twes "$d" install); check "install again under WSL with Docker Engine: scheduled by systemd" $? 0 "$out" "scheduled: twes-in-start.service"
d=$(bare); mkdir -p "$TMPDIR/usb"
out=$(BACKUPS=$TMPDIR/usb answers | twes "$d" install); check "a backup folder given is the backups' folder" $? 0 "$(value_of "$d" BACKUP_DIRECTORY)" "$TMPDIR/usb"
[[ "$out" != *"a dead disk"* ]]; check "  no word about this computer's own disk" $? 0 "$out" ""
check "  but no copy off the computer: what a lost one costs, and how to send one" 0 0 "$out" "no copy leaves this computer: a stolen, burnt or encrypted computer loses every backup with it; give a bucket's values (BACKUP_S3_*) in"
check "  every hour" 0 0 "$out" "then twes offsite on: the folder is copied there every hour"
check "  the folder named by an identity, written in it and in the values" 0 0 "$(cat "$TMPDIR/usb/twes-in-backups.id")" "$(value_of "$d" BACKUP_FOLDER_ID)"
[[ "$(value_of "$d" BACKUP_FOLDER_ID)" =~ ^[0-9a-f-]{36}$ ]]; check "  a uuid" $? 0 "$(value_of "$d" BACKUP_FOLDER_ID)" ""

# The drive unplugged at start: the application starts all the same, the developer told, and nothing is backed up
# into an empty folder made in its place on this computer's own disk.
echo BACKUP_OFFSITE=on >> "$d/etc/install.env"  # its own mail is the one read here
twes "$d" start >/dev/null; [[ -z "$(mailed)" ]]; check "the backups' folder there: started, nothing said" $? 0 "$(mailed)" ""
mv "$TMPDIR/usb" "$TMPDIR/usb-away"
out=$(twes "$d" start); check "the folder gone at start: started all the same" $? 0 "$(deployed)" "--again"
check "  said" 0 0 "$out" "the backups' folder $TMPDIR/usb is not there"
check "  and mailed" 0 0 "$(subject)" "Le dossier des sauvegardes n'est pas là"
check "  saying what to do" 0 0 "$(mailed)" "Rebranchez le disque"
out=$(AT=$((NOON + 900)) OUTCOMES="due $NOON ran\n" twes "$d" tick); [[ -z "$(mailed)" ]]; check "  tick: once a day, not every quarter hour" $? 0 "$(mailed)" ""
out=$(AT=$((NOON + 86400)) OUTCOMES="due $((NOON + 86400)) ran\n" twes "$d" tick); check "  and again the next day while it lasts" $? 0 "$(subject)" "Le dossier des sauvegardes n'est pas là"
mkdir -p "$TMPDIR/usb"
out=$(twes "$d" start); check "an empty folder in its place: not the backups' folder" $? 0 "$out" "is not the backups' folder"
check "  told as such" 0 0 "$(mailed)" "n'est pas celui des sauvegardes"
[[ ! -e "$TMPDIR/usb/twes-in-backups.id" ]]; check "  and never named the backups' folder in its place" $? 0 "$(ls -A "$TMPDIR/usb")" ""
rmdir "$TMPDIR/usb"; mv "$TMPDIR/usb-away" "$TMPDIR/usb"
out=$(twes "$d" start); [[ "$out" != *"backups' folder"* ]]; check "the drive back: nothing said" $? 0 "$out" ""
d=$(installed); mkdir -p "$TMPDIR/old-usb"; echo "BACKUP_DIRECTORY=$TMPDIR/old-usb" >> "$d/etc/install.env"
out=$(twes "$d" start); check "an install from before the identity: its folder named at start" $? 0 "$(cat "$TMPDIR/old-usb/twes-in-backups.id")" "$(value_of "$d" BACKUP_FOLDER_ID)"
[[ -n "$(value_of "$d" BACKUP_FOLDER_ID)" ]]; check "  in the values too" $? 0 "$(cat "$d/etc/install.env")" ""
rm -r "$TMPDIR/old-usb"
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "no update without the backups' folder, as an update backs up first" $? 1 "$out" "no update without it"
[[ "$(at_tag "$d")" == "$OLD" && "$(deployed)" != *--release* ]]; check "  nothing checked out or deployed" $? 0 "$(deployed)" ""

d=$(bare)
out=$(NIGHT=no CLOSING=19:30 answers | twes "$d" install); check "a computer switched off at night: the window an hour after closing" $? 0 "$(value_of "$d" UPDATE_WINDOW)" "20:30-21:30"
check "  proposed, so it may be changed" 0 0 "$out" "[20:30-21:30]"
d=$(bare)
out=$(NIGHT=no CLOSING=22:30 answers | twes "$d" install); check "  closing late: the window starts at midnight, never across it" $? 0 "$(value_of "$d" UPDATE_WINDOW)" "00:00-01:00"
d=$(bare)
out=$(NIGHT=no CLOSING=22:00 answers | twes "$d" install); check "  closing at 22:00: the window that would end at 24:00 starts at midnight" $? 0 "$(value_of "$d" UPDATE_WINDOW)" "00:00-01:00"
d=$(bare)
out=$(WINDOW=13:00-14:00 answers | twes "$d" install); check "another window typed: taken" $? 0 "$(value_of "$d" UPDATE_POLICY) $(value_of "$d" UPDATE_WINDOW)" "auto 13:00-14:00"
d=$(bare)
out=$(WINDOW=soon answers | twes "$d" install); check "a window that is none: refused, saying how one is written" $? 1 "$out" "a window is HH:MM-HH:MM"
d=$(bare)
out=$(NIGHT=perhaps answers | twes "$d" install); check "neither yes nor no: refused" $? 1 "$out" "yes or no"
d=$(bare)
out=$(answers | twes "$d" install); check "no backup folder: installed all the same, never blocked" $? 0 "$out" "installed: $NEW"
check "  saying in words what a dead disk costs and how to move them" 0 0 "$out" "the backups stay on this computer's own disk: a dead disk loses every invoice with its backups; plug in a second disk or a USB drive and set BACKUP_DIRECTORY in"
d=$(bare); odd="$TMPDIR/usb\nUPDATE_POLICY=off"; mkdir -p "$odd"
out=$(BACKUPS=$odd answers | twes "$d" install); check "a backup folder whose name holds a backslash is written as typed" $? 0 "$(value_of "$d" BACKUP_DIRECTORY)" "$odd"
[[ "$(grep -c '^UPDATE_POLICY=off$' "$d/etc/install.env")" == 0 ]]; check "  never read as a second value" $? 0 "$(cat "$d/etc/install.env")" ""
d=$(bare)
out=$(BACKUPS=$TMPDIR/nowhere/at/all answers | twes "$d" install); check "a backup folder that does not exist: refused" $? 1 "$out" "no folder at $TMPDIR/nowhere/at/all"
d=$(bare)
out=$(CONFIRM=no answers | twes "$d" install); check "the fingerprint not confirmed: refused" $? 1 "$out" "not pinned"
[[ ! -d "$d/etc/release-keys" && "$(deployed)" != *--release* ]]; check "  nothing pinned, nothing deployed" $? 0 "$(deployed)" ""
d=$(bare)
spaced=$(sed -E 's/(.{4})/\1 /g' <<<"${FPR,,}")
out=$(CONFIRM="$spaced" answers | twes "$d" install); check "the fingerprint typed back, spaced and in small letters: taken as yes" $? 0 "$out" "installed: $NEW"
check "  pinned" 0 0 "$(GNUPGHOME=$d/etc/release-keys gpg --batch --with-colons --list-keys 2>/dev/null)" "$FPR"
d=$(bare)
out=$(CONFIRM="$OTHER" answers | twes "$d" install); check "another key's fingerprint typed: refused, saying it is not the one shown" $? 1 "$out" "not the fingerprint shown"
[[ ! -d "$d/etc/release-keys" ]]; check "  nothing pinned" $? 0 "" ""
d=$(bare)
out=$(PW2='another one entirely' answers | twes "$d" install); check "the operator's two passwords differ: refused" $? 1 "$out" "differ"
[[ ! -e "$TMPDIR/seeded" ]]; check "  no operator made" $? 0 "" ""
out=$(printf '%s\n' op@shop.test 'a long passphrase 2026' 'a long passphrase 2026' | twes "$d" install); check "  asked again on the next run, nothing else" $? 0 "$(head -n 1 "$TMPDIR/seeded")" "--operator-email=op@shop.test"
d=$(bare)
out=$(printf '%s\n' 'not an address' | twes "$d" install); check "a mail address that is none: refused" $? 1 "$out" "not a mail address"
d=$(bare)
out=$(answers | SEED_EXIT=1 twes "$d" install); check "the application refusing the operator: said" $? 1 "$out" "operator"
out=$(twes "$d" status 2>&1); check "  the rest stays installed" 0 0 "$out" "release:  $NEW"

# update
d=$(shop)
out=$(twes "$d" update); check "a first install takes the newest release" $? 0 "$out" "updated to $NEW"
[[ "$(at_tag "$d")" == "$NEW" ]]; check "  checked out at its tag" $? 0 "$(at_tag "$d")" ""
[[ "$(deployed)" != *"before-update"* ]]; check "  with nothing to back up before it" $? 0 "$(deployed)" ""

d=$(PATCH=v2026.10.22.1 installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "a patch tagged on a release's own branch, master's newest left behind, is taken" $? 0 "$out" "updated to v2026.10.22.1"
[[ "$(at_tag "$d")" == v2026.10.22.1 && ! -e "$d/twes-in/two" && -e "$d/twes-in/patch" ]]
check "  checked out at the patch, with none of master's work since its base" $? 0 "$(at_tag "$d"); $(ls "$d/twes-in")" ""

d=$(installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "an update to the newest release" $? 0 "$out" "updated to $NEW"
check "  saying where it stands: the backup first" 0 0 "$out" "[1/3] backing up the database and the files — next: downloading and starting $NEW"
check "  then the release, with how long it usually takes" 0 0 "$out" "[2/3] downloading and starting $NEW: its images, then the database's migrations (usually a few minutes)"
check "  then its health, the last step" 0 0 "$out" "[3/3] reading its health through the edge (usually under a minute, 3 min at most) — the last step"
check "  each step's time once it ends" 0 0 "$out" "[3/3] done in "
check "  backs up first, through the release running" 0 0 "$(deployed)" "--compose exec -T backup twes-backup before-update|"
check "  then deploys the new one by digest" 0 0 "$(deployed)" "--release"
check "  keeping the backup's label" 0 0 "$(cat "$d/etc/state/last-update")" "$NEW"
check "  and the health read through the edge with the token from a file, never the command line" 0 0 "$(grep deep "$TMPDIR/curl.calls")" "-H @"
grep -q "$(awk -F= '$1=="MONITOR_TOKEN"{print $2}' "$d/etc/install.env")" "$TMPDIR/curl.calls"; check "  (the token is in no argument)" $? 1 "" ""
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "up to date: nothing done" $? 0 "$out" "up to date: $NEW"
[[ "$out" != *"[1/"* ]]; check "  and no step said" $? 0 "$out" ""

# A patch tagged after a release it does not hold would take that release's shops back onto older code: refused there.
d=$(PATCH=v2026.10.22.1 installed); git -C "$d/twes-in" checkout -q --detach "$NEW"
echo "t deployed $(git -C "$d/twes-in" rev-parse HEAD) release" >> "$d/etc/releases/history"
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "a newest tag that does not grow from the release running: refused" $? 1 "$out" "v2026.10.22.1"
[[ "$(at_tag "$d")" == "$NEW" ]]; check "  the release running kept" $? 0 "$(at_tag "$d")" ""
check "  the developer told" 0 0 "$(subject)" "v2026.10.22.1"
check "  and the operators, naming the release kept" 0 0 "$(told)" "installation.update_behind version=v2026.10.22.1 running=$NEW"

# A start step that fails only warns (docs/SPEC.md § 7, F13): it runs again at the next start, and a rollback would cost
# the shop the update and a restore over the new schema.
d=$(installed)
out=$(STEP_FAILS=1 BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); check "a release whose only failure is a start step is kept" $? 0 "$out" "updated to $NEW"
[[ "$(at_tag "$d")" == "$NEW" ]]; check "  the new release running" $? 0 "$(at_tag "$d")" ""
[[ "$(deployed)" != *--again* ]]; check "  nothing started again on the old schema" $? 0 "$(deployed)" ""
check "  the developer told which steps failed" 0 0 "$(mailed)" "startSteps (app:invoices:seal-chain, app:platform:converge)"
check "  and the operators" 0 0 "$(told)" "installation.unhealthy check=startSteps (app:invoices:seal-chain, app:platform:converge)"
! grep -qsx "$NEW" "$d/etc/state/failed-releases"; check "  not written down as a failed release" $? 0 "" ""
out=$(STEP_FAILS=1 ALL_BAD=1 twes "$d" tick); [[ "$(told)" != *installation.unhealthy* ]]; check "  the next tick tells it no second time" $? 0 "$(told)" ""
# That episode is the start steps alone: a failure of anything else later is a new one, which the watch must not miss.
out=$(AT=$((NOON + 900)) ALL_BAD=1 twes "$d" tick); check "  the queue failing later: started again once" $? 0 "$(deployed)" "--again"
check "    and told" 0 0 "$(told)" "installation.unhealthy check=queue"
d=$(installed)
out=$(STEP_FAILS=mixed ALL_BAD=1 twes "$d" tick); check "an episode of a check beside the start steps: told" $? 0 "$(told)" "check=queue,startSteps (app:invoices:seal-chain)"
out=$(AT=$((NOON + 900)) STEP_FAILS=1 ALL_BAD=1 twes "$d" tick); [[ "$(told)" != *installation.unhealthy* && "$(deployed)" != *--again* ]]
check "  the check mended, the steps still failing: nothing started, nobody told again" $? 0 "$(told)$(deployed)" ""
out=$(AT=$((NOON + 1800)) ALL_BAD=1 twes "$d" tick); check "  then the queue failing anew: started again once" $? 0 "$(deployed)" "--again"
check "    and told" 0 0 "$(told)" "installation.unhealthy check=queue"

d=$(installed)
out=$(STEP_FAILS=mixed BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); check "a start step failing beside another check: rolled back" $? 1 "$out" "starting $OLD again"

d=$(installed)
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); check "an unhealthy release is rolled back" $? 1 "$out" "starting $OLD again"
check "  its health step said to have failed, with its time" 0 0 "$out" "[3/3] failed after "
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
check "  held first, before anything is stopped" 0 0 "$(grep -m 1 '^  ' <<<"$out")" "  bash scripts/onprem/twes hold on"
# The steps after the checkout run the release gone back to, which may predate the hold: its file is removed by name.
check "  the hold ended just before the start" 0 0 "$(grep -B 1 '^  bash scripts/onprem/twes start$' <<<"$out" | head -n 1)" "  rm -f $d/etc/state/hold"
# Pasted as one block, a command reading its input would take the lines after it as that input, and they never run.
runs=$(grep -c ' run --rm ' <<<"$out"); safe=$(grep -c ' run --rm .* < /dev/null$' <<<"$out")
check "  safe to paste as one block: both restores read no input" 0 0 "$runs $safe" "2 2"
d=$(installed)
out=$(twes "$d" restore-commands); check "restore-commands with no update made here: refused" $? 1 "$out" "no update"

d=$(installed)
out=$(RELEASE_CUT=before BACKUP_LABEL=$LABEL twes "$d" update); [[ "$(at_tag "$d")" == "$NEW" ]]
check "an update cut short while it deploys: the checkout left on the new release" $? 0 "$(at_tag "$d")" ""
check "  what it was doing written down" 0 0 "$(cat "$d/etc/state/update-in-progress")" "$NEW $(git -C "$d/twes-in" rev-parse "$OLD^{commit}") $LABEL"
out=$(twes "$d" start); check "  the next start takes the shop back to the release it ran" $? 0 "$out" "the update to $NEW was cut short: starting $OLD again"
[[ "$(at_tag "$d")" == "$OLD" ]]; check "  checked out again" $? 0 "$(at_tag "$d")" ""
check "  started from its own images" 0 0 "$(deployed)" "--again"
check "  the developer mailed" 0 0 "$(subject)" "Mise à jour vers $NEW interrompue, $OLD a repris"
check "  and the operators told, with the backup's label" 0 0 "$(told)" "installation.rolled_back version=$NEW previous=$OLD label=$LABEL"
check "  the release written down as failed, so it is not applied again by itself" 0 0 "$(cat "$d/etc/state/failed-releases")" "$NEW"
[[ ! -e "$d/etc/state/update-in-progress" ]]; check "  and the journal cleared" $? 0 "" ""
out=$(twes "$d" start); [[ "$out" != *"cut short"* ]]; check "  a start after it: nothing more to take back" $? 0 "$out" "running:"

d=$(installed)
out=$(RELEASE_CUT=before BACKUP_LABEL=$LABEL twes "$d" update)
# Cut inside git's own checkout: git leaves its lock behind, and with it every later checkout refused.
: > "$d/twes-in/.git/index.lock"
out=$(twes "$d" start); check "cut inside the checkout, its lock left behind: the next start still takes the shop back" $? 0 "$out" "the update to $NEW was cut short: starting $OLD again"
[[ "$(at_tag "$d")" == "$OLD" ]]; check "  checked out again" $? 0 "$(at_tag "$d")" ""
check "  the developer mailed" 0 0 "$(subject)" "Mise à jour vers $NEW interrompue, $OLD a repris"
[[ ! -e "$d/twes-in/.git/index.lock" ]]; check "  the lock gone" $? 0 "" ""

d=$(installed)
out=$(RELEASE_CUT=after BACKUP_LABEL=$LABEL twes "$d" update)
out=$(twes "$d" tick); check "cut once deployed and before its health was read: tick takes the shop back" $? 0 "$out" "the update to $NEW was cut short: starting $OLD again"
[[ "$(at_tag "$d")" == "$OLD" ]]; check "  checked out again" $? 0 "$(at_tag "$d")" ""
check "  the operators told" 0 0 "$(told)" "installation.rolled_back version=$NEW previous=$OLD label=$LABEL"

d=$(installed)
out=$(RELEASE_CUT=before BACKUP_LABEL=$LABEL twes "$d" update)
out=$(AGAIN_EXIT=1 twes "$d" start); check "cut short, and the release it ran does not start again: the restore is mailed" $? 1 "$out" "URGENT"
check "  with its commands" 0 0 "$(mailed)" "--set=$LABEL"
[[ ! -e "$d/etc/state/update-in-progress" ]]; check "  told once: the journal cleared" $? 0 "" ""

d=$(installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" update); [[ ! -e "$d/etc/state/update-in-progress" ]]
check "an update that ends leaves no journal" $? 0 "$out" "updated to $NEW"
d=$(installed)
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update); [[ ! -e "$d/etc/state/update-in-progress" ]]
check "  nor one rolled back" $? 0 "$out" "starting $OLD again"

out=$(AT=$((NOON + 86400)) BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" update)
check "a release that failed may be tried again by hand" $? 1 "$(deployed)" "--release"
# signed releases
d=$(UNPINNED=1 shop)
out=$(twes "$d" pin-key "$TMPDIR/release-key.asc"); check "pin-key: the release key pinned, its fingerprint said" $? 0 "$out" "$DEV"
out=$(twes "$d" pin-key "$TMPDIR/secret-key.asc"); check "pin-key: a file holding a secret key refused" $? 1 "$out" "secret key"
out=$(twes "$d" pin-key "$TMPDIR/release-key.asc.none"); check "pin-key: no such file refused" $? 1 "$out" "no key file"
printf 'not a key\n' > "$TMPDIR/nothing.asc"
out=$(twes "$d" pin-key "$TMPDIR/nothing.asc"); check "pin-key: a file holding no key refused" $? 1 "$out" "no public key"
d=$(DEEP=1 UNPINNED=1 shop)
out=$(twes "$d" pin-key "$TMPDIR/release-key.asc"); check "pin-key in a deep folder: pinned all the same" $? 0 "$out" "$DEV"
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "  and a release verified and taken there" $? 0 "$(deployed)" "--release"
leftover=$(compgen -G '/tmp/twes-gpg.*'); check "  no link to the keys left behind" $? 1 "$leftover" ""
d=$(UNPINNED=1 shop)
out=$(twes "$d" update); check "no key pinned: nothing installed, saying how to pin one" $? 1 "$out" "twes pin-key"
[[ "$(deployed)" != *--release* ]]; check "  nothing deployed" $? 0 "$(deployed)" ""
d=$(SIGN_NEW=none installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "an unsigned release tag: refused" $? 1 "$out" "$NEW is not signed by the release key pinned here"
[[ "$(at_tag "$d")" == "$OLD" ]]; check "  the checkout left where it was" $? 0 "$(at_tag "$d")" ""
[[ "$(deployed)" != *--release* && "$(deployed)" != *before-update* ]]; check "  nothing backed up or deployed" $? 0 "$(deployed)" ""
check "  the developer mailed" 0 0 "$(subject)" "Mise à jour refusée"
check "  and the operators told" 0 0 "$(told)" "installation.update_refused version=$NEW"
d=$(SIGN_NEW=other installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" update); check "a tag signed by another key: refused" $? 1 "$out" "not signed by the release key pinned here"
d=$(SIGN_NEW=other installed); twes "$d" policy auto 13:00-14:00 >/dev/null
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); check "auto: a refused tag refused, the operators told" 0 0 "$(told)" "installation.update_refused"
rm -f "$d/etc/state/update-tried"; echo $((NOON + 86400 - 900)) > "$d/etc/state/last-tick" # on all day, ticking
out=$(AT=$((NOON + 86400)) BACKUP_LABEL=$LABEL twes "$d" tick); [[ -z "$(told)" && "$(deployed)" != *--release* ]]; check "  and the next day neither tried nor told again" $? 0 "$(told)$(deployed)" ""
check "  saying it waits for a hand" 0 0 "$out" "applied only by hand"
d=$(SIGN_NEW=none installed); twes "$d" policy notify >/dev/null
out=$(twes "$d" tick); [[ "$(told)" != *update_available* ]]; check "notify: an unsigned release never announced to the owners" $? 0 "$(told)" ""
check "  but the operators alerted: the default policy hides no forged tag" 0 0 "$(told)" "installation.update_refused version=$NEW"
check "  mailed too" 0 0 "$(subject)" "Mise à jour refusée"
check "  written down as refused" 0 0 "$(cat "$d/etc/state/refused-releases")" "$NEW"
out=$(AT=$((NOON + 900)) twes "$d" tick); [[ -z "$(told)" && -z "$(mailed)" ]]; check "  and the next tick alerts it no more" $? 0 "$(told)$(mailed)" ""
d=$(installed); twes "$d" policy notify >/dev/null
out=$(twes "$d" tick); [[ "$(told)" != *update_refused* ]]; check "notify: a signed release alerts no one" $? 0 "$(told)" ""


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

# A restore stops every container and runs for longer than a quarter hour: held, nothing scheduled starts them again.
d=$(installed)
out=$(twes "$d" hold on); check "hold on: what is scheduled acts on nothing until the hold ends" $? 0 "$out" "twes hold off"
out=$(ALL_BAD=1 twes "$d" tick); [[ "$(deployed)" != *--again* ]]; check "  held, an unhealthy tick starts nothing" $? 0 "$(deployed)" ""
check "  and says it is held" 0 0 "$(cat "$d/etc/state/twes.log")" "held for a restore"
[[ -z "$(told)" && -z "$(mailed)" ]]; check "  nor tells anyone the stopped installation is unhealthy" $? 0 "$(told)$(mailed)" ""
out=$(ADDRESS=192.168.1.42 twes "$d" tick); [[ "$(deployed)" != *--again* ]]; check "  nor a new address" $? 0 "$(deployed)" ""
out=$(twes "$d" start); check "  start refused while held, as the start at boot would run it mid-restore" $? 1 "$out" "held for a restore"
[[ "$(deployed)" != *--again* ]]; check "  starting nothing" $? 0 "$(deployed)" ""
out=$(twes "$d" update); check "  update refused while held" $? 1 "$out" "held for a restore"
check "  twes status says so" 0 0 "$(twes "$d" status)" "held:     for a restore since 2026-10-21"
out=$(twes "$d" hold off); check "hold off: what is scheduled acts again" $? 0 "$out" "hold ended"
out=$(HEALTH_FAILS=1 twes "$d" tick); check "  an unhealthy tick starts it again" $? 0 "$(deployed)" "--again"
out=$(twes "$d" hold); check "hold takes on or off" $? 2 "$out" "Usage"
# A start, tick or update already running when the hold is asked for is waited for: begun before it, it would start
# the installation in the middle of the restore.
d=$(installed)
( exec 9> "$d/etc/state/start.lock"; flock 9; sleep 3 ) & sleeper=$!
sleep 0.5
began=$SECONDS; out=$(twes "$d" hold on); code=$?; waited=$((SECONDS - began)); wait "$sleeper"
(( code == 0 && waited >= 2 )); check "hold on waits for a start already running ($waited s)" $? 0 "$out" "held: tick and update"
rm -f "$d/etc/state/hold"
( exec 8> "$d/etc/state/update.lock"; flock 8; sleep 6 ) & sleeper=$!
sleep 0.5
out=$(TWES_HOLD_WAIT=1 twes "$d" hold on); check "  one still running after the wait: refused" $? 1 "$out" "still running"
[[ ! -e "$d/etc/state/hold" ]]; check "  and nothing held" $? 0 "" ""
wait "$sleeper"

# tick
d=$(installed)
out=$(ADDRESS=192.168.1.42 twes "$d" tick); check "a new network address: started again at it" $? 0 "$(deployed)" "LAN_HOST=192.168.1.42 --again"
check "  and the owners and operators told the address phones now open" 0 0 "$(told)" "installation.address_moved from=192.168.1.20 to=192.168.1.42"
d=$(installed)
out=$(NO_ROUTE=1 twes "$d" tick); check "no network at all: started again on this computer alone" $? 0 "$(deployed)" "LAN_HOST=127.0.0.1 --again"
[[ "$(told)" != *address_moved* ]]; check "  and no phone told to open an address none can reach" $? 0 "$(told)" ""
# Under WSL, Linux's clock may lag Windows' after a waking, and the application dates invoices and journals with it.
wsl() { PLATFORM=wsl ADDRESS=192.168.1.20 twes "$@"; }
d=$(installed)
out=$(WINDOWS_NOW=$((NOON - 95)) wsl "$d" tick); check "under WSL, Linux's clock 95 s ahead of Windows': the operators told" $? 0 "$(told)" "installation.clock_off seconds=95"
check "  said in its log" 0 0 "$(cat "$d/etc/state/twes.log")" "Linux's clock is 95 s off Windows'"
[[ -z "$(mailed)" ]]; check "  told in the application only, as a moved address is" $? 0 "$(mailed)" ""
out=$(AT=$((NOON + 900)) WINDOWS_NOW=$((NOON + 900 - 120)) wsl "$d" tick); [[ "$(told)" != *clock_off* ]]; check "  once per episode, not every quarter hour" $? 0 "$(told)" ""
out=$(AT=$((NOON + 1800)) WINDOWS_NOW=$((NOON + 1800 + 30)) wsl "$d" tick); [[ "$(told)" != *clock_off* ]]; check "  within a minute again: nobody told" $? 0 "$(told)" ""
out=$(AT=$((NOON + 2700)) WINDOWS_NOW=$((NOON + 2700 + 61)) wsl "$d" tick); check "  then off anew, behind this time: told again" $? 0 "$(told)" "installation.clock_off seconds=61"
d=$(installed)
out=$(WINDOWS_NOW=$((NOON + 60)) wsl "$d" tick); [[ "$(told)" != *clock_off* ]]; check "a minute exactly is not off" $? 0 "$(told)" ""
out=$(AT=$((NOON + 900)) WINDOWS_NOW=$((NOON + 900)) wsl "$d" tick); [[ "$(told)" != *clock_off* && "$(cat "$d/etc/state/twes.log")" != *"clock is"* ]]; check "  nor the same time" $? 0 "$(told)" ""
d=$(installed); echo $((NOON - 300)) > "$d/etc/state/started-at"
out=$(WINDOWS_NOW=$((NOON + 600)) wsl "$d" tick); [[ "$(told)" != *clock_off* ]]; check "within ten minutes of a start or a waking: left to settle" $? 0 "$(told)" ""
check "  and said" 0 0 "$out" "within ten minutes of a start or a waking: read again at the next tick"
d=$(installed)
out=$(WINDOWS_NOW=garbled wsl "$d" tick); [[ "$(told)" != *clock_off* ]]; check "Windows' clock unreadable: nobody told" $? 0 "$(told)" ""
check "  said once" 0 0 "$out" "Windows' clock could not be read"
out=$(AT=$((NOON + 900)) WINDOWS_NOW=garbled wsl "$d" tick); [[ "$out" != *"clock could not"* ]]; check "  not every quarter hour" $? 0 "$out" ""
d=$(installed)
out=$(WINDOWS_NOW=$((NOON + 600)) twes "$d" tick); [[ "$(told)" != *clock_off* && "$out" != *clock* ]]; check "on Linux, no Windows clock is read" $? 0 "$out$(told)" ""
d=$(installed)
out=$(ALL_BAD=1 twes "$d" tick); check "unhealthy: the developer told" $? 0 "$(subject)" "ne va pas bien (queue)"
check "  and the operators" 0 0 "$(told)" "installation.unhealthy check=queue"
out=$(AT=$((NOON + 900)) ALL_BAD=1 twes "$d" tick); [[ -z "$(told)" ]]; check "  once, not every quarter hour" $? 0 "$(told)" ""
out=$(AT=$((NOON + 1800)) twes "$d" tick); out=$(AT=$((NOON + 1800)) ALL_BAD=1 twes "$d" tick)
check "  and again once it mended and failed anew" $? 0 "$(told)" "installation.unhealthy"

d=$(installed)
out=$(HEALTH_FAILS=1 twes "$d" tick); check "unhealthy, as after Docker restarted: started again by itself" $? 0 "$(deployed)" "LAN_HOST=192.168.1.20 --again"
check "  said in its log" 0 0 "$(cat "$d/etc/state/twes.log")" "unhealthy (queue): starting the installation again"
[[ "$(told)" != *installation.unhealthy* && -z "$(mailed)" ]]; check "  healthy again, nobody told" $? 0 "$(told)$(mailed)" ""
check "  and twes status says when" 0 0 "$(twes "$d" status)" "started again by tick: 1 time(s), last "
out=$(AT=$((NOON + 900)) HEALTH_FAILS=1 twes "$d" tick); check "  a new episode: started again once more" $? 0 "$(deployed)" "--again"
d=$(installed)
out=$(ALL_BAD=1 twes "$d" tick); check "still unhealthy once started again: told, saying so" $? 0 "$(mailed)" "relancée"
out=$(AT=$((NOON + 900)) ALL_BAD=1 twes "$d" tick); [[ "$(deployed)" != *--again* ]]; check "  started again once per episode, not every quarter hour" $? 0 "$(deployed)" ""
d=$(installed); echo $((NOON - 300)) > "$d/etc/state/started-at"
out=$(ALL_BAD=1 twes "$d" tick); [[ "$(deployed)" != *--again* && "$(told)" != *installation.unhealthy* ]]
check "  never within ten minutes of a start: it may still be settling" $? 0 "$(deployed)" ""
d=$(installed); exec 9> "$d/etc/state/start.lock"; flock 9
out=$(TWES_START_WAIT=1 twes "$d" start); check "a start while another runs: waits for it, then refused naming it" $? 1 "$out" "another twes start is running"
exec 9>&-

d=$(installed)
out=$(OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick)
check "a failed backup step: the developer told" $? 0 "$(subject)" "Sauvegarde en échec : copy"
check "  and the operators" 0 0 "$(told)" "installation.backup_failed step=copy"
out=$(OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick); [[ -z "$(told)" ]]; check "  once for that failure" $? 0 "$(told)" ""

# A mail that does not leave (no internet that minute, a password revoked) is kept and sent by the next tick.
d=$(installed)
out=$(MAIL_EXIT=1 OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick)
check "a mail that did not leave: said" $? 0 "$out" "the mail to dev@example.test did not leave, kept for the next tick: [twes-in"
check "  twes status counts it" 0 0 "$(MAIL_EXIT=1 twes "$d" status)" "held back: 1 mail(s)"
out=$(AT=$((NOON + 900)) OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick)
check "  the next tick sends it" $? 0 "$(subject)" "Sauvegarde en échec : copy"
check "  saying so" 0 0 "$out" "mailed to dev@example.test: [twes-in"
[[ "$(twes "$d" status)" != *"mail(s)"* ]]; check "  and none is held any more" $? 0 "$(twes "$d" status)" ""
check "  twes status says the demo is closed on this computer" 0 0 "$(twes "$d" status)" "demo:     off"
check "  and says when an operator opened it, and where to close it" 0 0 "$(DEMO_OPEN=true twes "$d" status)" "demo:     on, anyone reaching this computer may open a sandbox: /platform, Démo, to switch it off"
check "  and says when the application does not answer it" 0 0 "$(DEMO_SILENT=1 twes "$d" status)" "demo:     (the application does not answer)"
out=$(AT=$((NOON + 1800)) OUTCOMES="copy 2026-10-21T01:00:00Z failed copy exited 1\ndue $NOON ran\n" twes "$d" tick); [[ -z "$(mailed)" ]]
check "  sent once" $? 0 "$(mailed)" ""

d=$(installed)
out=$(OUTCOMES="due-age 6000 ran\n" twes "$d" tick); check "a schedule silent for over an hour and a half: told" $? 0 "$(told)" "installation.backup_stopped"
out=$(OUTCOMES="due-age 6000 ran\n" twes "$d" tick); [[ -z "$(told)" ]]; check "  once a day" $? 0 "$(told)" ""
d=$(installed); echo $((NOON - 600)) > "$d/etc/state/started-at"
out=$(OUTCOMES="due-age 6000 ran\n" twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]; check "  but not while the computer has just started" $? 0 "$(told)" ""
d=$(installed); echo $((NOON - 900)) > "$d/etc/state/last-tick"
out=$(OUTCOMES="due-age 6000 ran\n" twes "$d" tick); check "  told too when the ticks before it came every quarter hour" $? 0 "$(told)" "installation.backup_stopped"
d=$(installed)
out=$(OUTCOMES="due-age 1200 running copy\n" twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]; check "  never while a step runs within its time" $? 0 "$(told)" ""
# The schedule's age is read on the backup service's own clock: the host's and the container's may differ (Docker
# Desktop's virtual machine after a sleep), and a marker written on one clock is never compared with the other.
d=$(installed); mkdir -p "$TMPDIR/outcome"; echo "$(($(date +%s) - 6000)) ran" > "$TMPDIR/outcome/due"
out=$(OUTCOME_DIR=$TMPDIR/outcome twes "$d" tick); check "  its age read on the service's clock, whatever the host's says" $? 0 "$(told)" "installation.backup_stopped"
d=$(installed); echo "$(($(date +%s) - 600)) ran" > "$TMPDIR/outcome/due"
out=$(AT=$((NOON - 86400)) OUTCOME_DIR=$TMPDIR/outcome twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]
check "  and a host clock a day behind reads a fresh schedule as fresh" $? 0 "$(told)" ""
rm -rf "$TMPDIR/outcome"
# A clock set back after it ran ahead leaves the schedule's last word dated after now, its age below zero: the schedule
# is silent from the moment a tick first reads it so, not never.
d=$(installed)
out=$(OUTCOMES="due-age -3000 ran\n" twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]
check "a schedule last heard on a clock since set back: not told at once" $? 0 "$(told)" ""
for k in 1 2 3 4 5 6 7; do out=$(AT=$((NOON + 900 * k)) OUTCOMES="due-age -3000 ran\n" twes "$d" tick); done
check "  still silent an hour and a half later: told" $? 0 "$(told)" "installation.backup_stopped"
d=$(installed)
out=$(OUTCOMES="due-age -3000 ran\n" twes "$d" tick)
out=$(AT=$((NOON + 900)) OUTCOMES="due-age 60 ran\n" twes "$d" tick)
for k in 2 3 4 5 6 7; do out=$(AT=$((NOON + 900 * k)) OUTCOMES="due-age -60 ran\n" twes "$d" tick); done; [[ "$(told)" != *backup_stopped* ]]
check "  once heard again, a later moment ahead is counted afresh" $? 0 "$(told)" ""
d=$(installed)
out=$(OUTCOMES_DOWN=1 twes "$d" tick); check "a backup service that does not answer: told" $? 0 "$(told)" "installation.backup_stopped"

# A computer that slept (or was off) stops ticking: the first tick after it wakes finds the schedule silent since it
# slept and Docker still coming back. Every check timed from a start is timed from the waking too.
d=$(installed); echo $((NOON - 10800)) > "$d/etc/state/last-tick"
out=$(OUTCOMES="due-age 11000 ran\n" twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]
check "a computer that slept three hours: no backup alert as it wakes" $? 0 "$(told)" ""
check "  its log says it slept" 0 0 "$(cat "$d/etc/state/twes.log")" "slept or was off for 180 min"
for k in 1 2 3 4 5 6; do out=$(AT=$((NOON + 900 * k)) OUTCOMES="due-age $((11000 + 900 * k)) ran\n" twes "$d" tick); done
[[ "$(told)" != *backup_stopped* ]]; check "  nor once awake an hour and a half to the minute" $? 0 "$(told)" ""
out=$(AT=$((NOON + 6300)) OUTCOMES="due-age 17300 ran\n" twes "$d" tick)
check "  awake longer with the schedule still silent: told" $? 0 "$(told)" "installation.backup_stopped"
d=$(installed); echo $((NOON - 10800)) > "$d/etc/state/last-tick"
out=$(OUTCOMES_DOWN=1 twes "$d" tick); [[ "$(told)" != *backup_stopped* ]]; check "  a backup service not answering yet as it wakes: not told" $? 0 "$(told)" ""
d=$(installed); echo $((NOON - 10800)) > "$d/etc/state/last-tick"
out=$(ALL_BAD=1 twes "$d" tick); [[ "$(deployed)" != *--again* && "$(told)" != *installation.unhealthy* ]]
check "  unhealthy as it wakes: left to settle, as after a start" $? 0 "$(deployed)" ""

d=$(OFFSITE_UNTOLD=1 installed)
out=$(twes "$d" tick); check "no copy off the computer: the developer mailed" $? 0 "$(subject)" "Aucune copie des sauvegardes hors de cet ordinateur"
check "  saying the backups sit on this computer's own disk, and what to do" 0 0 "$(mailed)" "sur le disque de cet ordinateur"
check "  the bucket's values, then twes offsite on" 0 0 "$(mailed)" "twes offsite on"
out=$(AT=$((NOON + 900)) twes "$d" tick); [[ -z "$(mailed)" ]]; check "  once a day, not every quarter hour" $? 0 "$(mailed)" ""
out=$(AT=$((NOON + 86400)) twes "$d" tick); check "  and again the next day while it lasts" $? 0 "$(subject)" "Aucune copie des sauvegardes"
mkdir -p "$TMPDIR/usb"; echo "BACKUP_DIRECTORY=$TMPDIR/usb" >> "$d/etc/install.env"
out=$(AT=$((NOON + 2 * 86400)) twes "$d" tick); check "  on a USB drive: named, a lost computer still loses it" $? 0 "$(mailed)" "sur $TMPDIR/usb"
echo BACKUP_OFFSITE=on >> "$d/etc/install.env"
out=$(AT=$((NOON + 3 * 86400)) twes "$d" tick); [[ "$(mailed)" != *"Aucune copie"* ]]; check "  off site on: never" $? 0 "$(mailed)" ""
d=$(installed)
check "status says where the backups are and that none leaves the computer" 0 0 "$(twes "$d" status)" "backups to: this computer's own disk (Docker volume backup-local)
off site: off: a lost computer loses every backup; twes offsite on once a bucket's values are given"

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
d=$(installed); twes "$d" policy auto 13:00-14:00 >/dev/null; echo $((NOON - 10800)) > "$d/etc/state/last-tick"
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "auto never as the computer wakes" $? 0 "$(deployed)" ""
d=$(installed); twes "$d" policy auto 13:00-14:00 >/dev/null
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" tick)
out=$(AT=$((NOON + 600)) BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "auto tries once a day" $? 0 "$(deployed)" ""
echo $((NOON + 86400 - 900)) > "$d/etc/state/last-tick" # on all day, ticking
out=$(AT=$((NOON + 86400)) BACKUP_LABEL=$LABEL twes "$d" tick); check "  and never again a release that failed here" $? 0 "$out" "applied only by hand"

# A release its tag marks as mending a security defect (tag-release.sh --security) does not wait for the window: under
# auto it is applied at the next tick, the developer mailed and the owners told, whom the application mails.
d=$(SECURITY_NEW="a session kept after its password changed" installed); twes "$d" policy auto 15:00-16:00 >/dev/null
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); check "a security release, outside the window: applied at the next tick" $? 0 "$out" "updated to $NEW"
check "  saying why it did not wait" 0 0 "$out" "a session kept after its password changed"
check "  the developer mailed" 0 0 "$(subject)" "Mise à jour de sécurité vers $NEW faite"
check "  why, in the mail" 0 0 "$(mailed)" "a session kept after its password changed"
check "  the owners told, whom the application mails" 0 0 "$(told)" "installation.security_updated version=$NEW previous=$OLD"
out=$(AT=$((NOON + 900)) BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* && -z "$(told)" ]]
check "  once: the next tick finds it running" $? 0 "$(deployed)$(told)" ""
d=$(SECURITY_NEW="a session kept" installed); twes "$d" policy auto 15:00-16:00 >/dev/null; echo $((NOON - 300)) > "$d/etc/state/started-at"
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "  never at start, as the window's" $? 0 "$(deployed)" ""
d=$(SECURITY_NEW="a session kept" SIGN_NEW=other installed); twes "$d" policy auto 15:00-16:00 >/dev/null
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]
check "  a mark on a tag the pinned key does not sign: nothing applied, the tag refused" $? 0 "$(told)" "installation.update_refused version=$NEW"
[[ "$(cat "$d/etc/state/twes.log")" != *"security defect"* ]]; check "  its mark never believed, not even in words" $? 0 "$(cat "$d/etc/state/twes.log")" ""
d=$(SECURITY_NEW="a session kept" installed); twes "$d" policy auto 15:00-16:00 >/dev/null
out=$(BAD=$NEW BACKUP_LABEL=$LABEL twes "$d" tick); check "  unhealthy: taken back as any update, the tick stopping on it" $? 1 "$(at_tag "$d")" "$OLD"
[[ "$(told)" != *security_updated* ]]; check "  and nobody told it was applied" $? 0 "$(told)" ""
out=$(AT=$((NOON + 900)) BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "  then never retried by itself" $? 0 "$(deployed)" ""
d=$(SECURITY_NEW="a session kept" installed); twes "$d" policy auto 15:00-16:00 >/dev/null
out=$(twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "  its backup failing: nothing changed" $? 0 "$(deployed)" ""
out=$(AT=$((NOON + 900)) BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "  tried once a day, as the window's" $? 0 "$(deployed)" ""
echo $((NOON + 86400 - 900)) > "$d/etc/state/last-tick" # on all day, ticking
out=$(AT=$((NOON + 86400)) BACKUP_LABEL=$LABEL twes "$d" tick); check "  and the next day again" $? 0 "$out" "updated to $NEW"
# Under notify the updates are run by hand: a security release is told at once, to the owners and by mail to the
# developer, and applied by whoever runs them; under off, nothing.
d=$(SECURITY_NEW="a session kept" installed)
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* ]]; check "notify: a security release is not applied by itself" $? 0 "$(deployed)" ""
check "  the owners told it is out" 0 0 "$(told)" "installation.update_available version=$NEW previous=$OLD"
check "  the developer mailed that it waits" 0 0 "$(subject)" "Mise à jour de sécurité disponible : $NEW"
check "  why, in the mail" 0 0 "$(mailed)" "a session kept"
out=$(AT=$((NOON + 900)) twes "$d" tick); [[ -z "$(mailed)" ]]; check "  once per release" $? 0 "$(mailed)" ""
d=$(installed); out=$(twes "$d" tick); [[ -z "$(mailed)" ]]; check "  a release with no mark: no mail" $? 0 "$(mailed)" ""
d=$(SECURITY_NEW="a session kept" installed); twes "$d" policy off >/dev/null
out=$(BACKUP_LABEL=$LABEL twes "$d" tick); [[ "$(deployed)" != *"--release"* && -z "$(told)" ]]; check "off: nothing" $? 0 "$(deployed)$(told)" ""

# Signs of an attack once the shop is reachable from outside, read at each tick over the hour before: sign-ins refused
# and accounts locked (the application's journal), refusals (429) and server errors (5xx) at the web tier. Past its
# threshold, a value of the shop's, the developer is mailed, once an hour at most for each.
weblog() { # count, status: that many access lines of the web tier's format
  local i; for ((i = 0; i < $1; i++)); do printf '172.18.0.4 - - [21/Oct/2026:12:00:00 +0000] "POST /api/auth/login HTTP/1.1" %s 31 "-" "curl/8" "8.8.4.4" upstream="172.18.0.5:80" %s 0.001\\n' "$2" "$2"; done
}
d=$(installed)
out=$(SIGN_INS="failed=21 locked=0" twes "$d" tick); check "sign-ins refused past the threshold: the developer mailed" $? 0 "$(subject)" "Connexions refusées en nombre : 21 en une heure"
check "  read over the hour before" 0 0 "$(cat "$TMPDIR/sign-ins.asked")" "--since=$((NOON - 3600))"
check "  saying what to look at" 0 0 "$(mailed)" "twes status"
out=$(AT=$((NOON + 900)) SIGN_INS="failed=40 locked=0" twes "$d" tick); [[ -z "$(mailed)" ]]; check "  once an hour at most" $? 0 "$(mailed)" ""
out=$(AT=$((NOON + 3600)) SIGN_INS="failed=40 locked=0" twes "$d" tick); check "  again an hour later while it lasts" $? 0 "$(subject)" "Connexions refusées en nombre : 40 en une heure"
d=$(installed); out=$(SIGN_INS="failed=20 locked=0" twes "$d" tick); [[ -z "$(mailed)" ]]; check "  at the threshold itself: nothing" $? 0 "$(mailed)" ""
d=$(installed); echo ALERT_SIGN_IN_FAILURES=5 >> "$d/etc/install.env"
out=$(SIGN_INS="failed=6 locked=0" twes "$d" tick); check "  the threshold is the shop's value" $? 0 "$(subject)" "Connexions refusées en nombre : 6 en une heure"
d=$(installed); out=$(SIGN_INS="failed=10 locked=3" twes "$d" tick); check "accounts locked past the threshold: mailed" $? 0 "$(subject)" "Comptes verrouillés : 3 en une heure"
d=$(installed); out=$(SIGN_INS="failed=10 locked=2" twes "$d" tick); [[ -z "$(mailed)" ]]; check "  two in an hour: nothing" $? 0 "$(mailed)" ""
d=$(installed); out=$(WEB_LOG="$(weblog 101 429)$(weblog 3 200)" twes "$d" tick); check "refusals (429) past the threshold: mailed" $? 0 "$(subject)" "Requêtes refusées (429) : 101 en une heure"
check "  the web tier's hour read" 0 0 "$(deployed)" "logs --no-log-prefix --since 60m web"
d=$(installed); out=$(WEB_LOG="$(weblog 11 502)" twes "$d" tick); check "server errors (5xx) past the threshold: mailed" $? 0 "$(subject)" "Erreurs du serveur (5xx) : 11 en une heure"
d=$(installed); out=$(WEB_LOG="$(weblog 10 503)$(weblog 200 404)" twes "$d" tick); [[ -z "$(mailed)" ]]; check "  ten, and any number of 404s: nothing" $? 0 "$(mailed)" ""
d=$(installed); out=$(SIGN_INS_EXIT=1 twes "$d" tick); check "the journal unread: said, nothing mailed, the tick goes on" $? 0 "$(cat "$d/etc/state/twes.log")" "sign-ins not read"

# twes doctor --network: what the developer reads at the shop before choosing how it is reached from outside. It runs
# before anything is installed, changes nothing, and a probe that answers nothing says so on its own line.
b=$(bare); out=$(PLATFORM=wsl twes "$b" doctor --network); check "doctor --network runs before any install" $? 0 "$out" "platform:  wsl"
for line in "engine:    Ubuntu 24.04.1 LTS" "gateway:   192.168.1.1" "lease:     DHCP, 24 h left: a reservation at the router keeps this address" \
  "public:    41.226.10.20" "route:     192.168.1.1 197.0.0.1 197.0.0.9: one router between this computer and the internet" \
  "ipv6:      no public IPv6 address" "upnp:      off" "  * UDP: true" "  * Nearest DERP: Frankfurt" "sleep:     never on mains" \
  "ups:       none: a power cut stops the database mid-write"; do
  check "  says $line" 0 0 "$out" "$line"
done
[[ ! -e "$b/etc" ]]; check "  and writes nothing" $? 0 "$(ls -A "$b")" ""
b=$(bare)
for probe in "GATEWAY|gateway:   (not read" "LEASE|lease:     (not read" "PUBLIC|public:    (not read" "HOPS|route:     (not read" \
  "IPV6|ipv6:      (not read" "UPNP|upnp:      (not read" "TAILSCALE|tailscale: (not read" "SLEEP|sleep:     (not read" "UPS|ups:       (not read"; do
  out=$(export "${probe%%|*}=silent"; PLATFORM=wsl twes "$b" doctor --network); check "  a ${probe%%|*} probe answering nothing says so" $? 0 "$out" "${probe#*|}"
done
out=$(DOCKER_LATE=never PLATFORM=wsl twes "$b" doctor --network); check "  an engine not answering says so" $? 0 "$out" "engine:    (not read: Docker does not answer)"
out=$(HOPS="192.168.1.1 100.72.0.1 41.0.0.1" PLATFORM=wsl twes "$b" doctor --network)
check "  a provider's shared address on the way out is CGNAT" 0 0 "$out" "behind the provider's NAT (CGNAT): no port opened at the router reaches this computer"
out=$(HOPS="192.168.1.1 192.168.0.1 41.0.0.1" PLATFORM=wsl twes "$b" doctor --network)
check "  a second private router on the way out is said" 0 0 "$out" "behind a second router: a port opened at the first does not reach this computer"
out=$(LEASE="Manual 4294967295" IPV6="2a01:e0a::1" UPNP="on 2" SLEEP=1200 UPS="battery 2" PLATFORM=wsl twes "$b" doctor --network)
for line in "lease:     fixed on this computer" "ipv6:      2a01:e0a::1: reachable from outside unless the router filters it" \
  "upnp:      on, 2 mapping(s): a program here may open a port by itself" "sleep:     after 20 min on mains: nobody reaches the shop while it sleeps" \
  "ups:       a battery (status 2)"; do
  check "  says $line" 0 0 "$out" "$line"
done
out=$(TWES_TAILSCALE=$TMPDIR/nowhere PLATFORM=wsl twes "$b" doctor --network); check "  tailscale absent is said" 0 0 "$out" "tailscale: not installed"
out=$(twes "$b" doctor --network); check "  off Windows, Windows' probes say why" 0 0 "$out" "sleep:     (not read: not under Windows)"
check "  and the gateway is read from Linux's route" 0 0 "$out" "gateway:   192.168.1.1"
out=$(NO_ROUTE=1 twes "$b" doctor --network); check "  off Windows with no route, the gateway says so" $? 0 "$out" "gateway:   (not read: no route)"
check "  and every probe after it still runs" 0 0 "$out" "ups:       (not read: not under Windows)"
out=$(twes "$b" doctor); check "  doctor alone asks for --network" $? 2 "$out" "Usage"

# Where the shop is reached from outside (TAILNET_HOST, PUBLIC_HOST and its ports, each empty while off), said by status.
d=$(installed); out=$(twes "$d" status)
check "status says the shop is reached on its own network only" 0 0 "$out" "address:  https://localhost, https://192.168.1.20"
check "  no tailnet" 0 0 "$out" "tailnet:  off: reached from the shop's network only"
check "  and the public path off" 0 0 "$out" "public:   off"
printf 'TAILNET_HOST=shop-pc.tail0000.ts.net\nPUBLIC_HOST=shop.example.org\nPUBLIC_HTTPS_PORT=8443\nPUBLIC_HTTP_PORT=\n' >> "$d/etc/install.env"
out=$(twes "$d" status)
check "  the tailnet name once given, which mailed links carry" 0 0 "$out" "tailnet:  https://shop-pc.tail0000.ts.net, which mailed links carry"
check "  the public name with its ports" 0 0 "$out" "public:   https://shop.example.org:8443 (plain HTTP off)"
sed -i 's/^PUBLIC_HTTPS_PORT=8443$/PUBLIC_HTTPS_PORT=443/; s/^PUBLIC_HTTP_PORT=$/PUBLIC_HTTP_PORT=80/' "$d/etc/install.env"
check "  the default port left out, plain HTTP named" 0 0 "$(twes "$d" status)" "public:   https://shop.example.org (plain HTTP on 80)"

out=$(twes "$d" sometimes); check "an unknown command is refused" $? 2 "$out" "Usage"
d=$(installed); rm "$d/etc/install.env"; out=$(twes "$d" status); check "no values: refused, naming where they are looked for" $? 1 "$out" "twes install writes them"

# What install.ps1 runs in Ubuntu before anything from the checkout runs, read out of install.ps1 itself and run with the
# shop's folder as home: the tag HEAD is at, verified against the fingerprint the installer carries.
checkout_check() { # shop folder
  local line
  line=$(sed -n "s/^\$verify = '\(.*\)'\$/\1/p" "$HERE/onprem/install.ps1")
  [[ -n "$line" ]] || { echo "install.ps1 holds no \$verify line"; return 99; }
  line=${line//\'\'/\'}; line=${line//RELEASE_KEY/$FPR}
  (HOME=$1 bash -c "$line" 2>&1)
}
d=$(installed); out=$(checkout_check "$d"); check "installer: a clean checkout at a tag the developer signed passes" $? 0 "$out" ""
echo '# changed' >> "$d/twes-in/scripts/onprem/twes"; out=$(checkout_check "$d"); check "  a changed file refused" $? 3 "$out" ""
d=$(installed); touch "$d/twes-in/extra.sh"; out=$(checkout_check "$d"); check "  a file of its own refused" $? 3 "$out" ""
d=$(installed); git -C "$d/twes-in" commit -q --allow-empty -m three; out=$(checkout_check "$d"); check "  a checkout at no tag refused" $? 4 "$out" ""
d=$(SIGN_NEW=none installed); git -C "$d/twes-in" checkout -q --detach "$NEW"; out=$(checkout_check "$d"); check "  an unsigned tag refused" $? 5 "$out" ""
d=$(SIGN_NEW=other installed); git -C "$d/twes-in" checkout -q --detach "$NEW"; out=$(checkout_check "$d"); check "  a tag signed by another key refused" $? 5 "$out" ""
d=$(installed); cp "$TMPDIR/other-key.asc" "$d/twes-in/infra/onprem/release-key.asc"
git -C "$d/twes-in" commit -qam "another key"; sign "$d/twes-in" v2026.10.22.1 other; git -C "$d/twes-in" checkout -q --detach v2026.10.22.1
out=$(checkout_check "$d"); check "  a tag signed by the key a forged checkout carries refused" $? 5 "$out" ""

# Windows PowerShell 5.1 reads a script without a byte-order mark in the computer's ANSI code page.
# grep answers 1 when no line holds anything else, 2 when it could not read the file.
out=$(LC_ALL=C grep -n '[^ -~	]' "$HERE/onprem/install.ps1" 2>&1); check "the Windows installer is plain ASCII" $? 1 "$out" ""

# twes export and twes verify-export: one archive, its MANIFEST sealed with the person's passphrase, read back whole.
PASS='a long shop passphrase'
mkdir -p "$TMPDIR/backup-tree/pgbackrest" "$TMPDIR/edge-ca/local"; echo wal > "$TMPDIR/backup-tree/pgbackrest/archive"; echo root > "$TMPDIR/edge-ca/local/root.crt"
exported() { BACKUP_TREE=$TMPDIR/backup-tree BACKUP_LABEL=$LABEL twes "$@"; } # shop folder, then export's arguments
archive_in() { compgen -G "$1/twes-export-*.tar" | head -n 1; }
unsealed() { # sealed file, file: opened with the passphrase in a keyring of the test's own
  local g; g=$(mktemp -d); printf '%s' "$PASS" > "$g/p"
  GNUPGHOME=$g gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$g/p" --decrypt --output "$2" "$1"; local s=$?
  GNUPGHOME=$g gpgconf --kill all 2>/dev/null; rm -rf "$g"; return "$s"
}
sealed() { # file, sealed file: what one holding the passphrase writes
  local g; g=$(mktemp -d); printf '%s' "$PASS" > "$g/p"
  GNUPGHOME=$g gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$g/p" --symmetric --output "$2" "$1"; local s=$?
  GNUPGHOME=$g gpgconf --kill all 2>/dev/null; rm -rf "$g"; return "$s"
}
repacked() { # archive, then what to do in its unpacked folder: the archive written again from that folder
  local a=$1 u; u=$(mktemp -d); shift; tar -C "$u" -xf "$a"; (cd "$u" && eval "$*"); tar -C "$u" -cf "$a" .; rm -rf "$u"
}
d=$(installed); o=$(mktemp -d)
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | EDGE_CA=$TMPDIR/edge-ca exported "$d" export "$o"); check "export: one archive written" $? 0 "$out" "exported to $o/twes-export-"
a=$(archive_in "$o"); members=$(tar -tf "$a" | sed 's|^\./||' | LC_ALL=C sort | tr '\n' ' ')
check "  it holds the MANIFEST, its seal and every member" 0 0 "$members" "MANIFEST MANIFEST.gpg backup.tar ca.tar.gpg counts.json release-keys.tar releases.tar state.tar values.env.gpg "
check "  no plain values, no images unless asked" 0 0 "$( [[ "$members" != *"values.env "* && "$members" != *images.tar* ]] && echo clean)" clean
check "  its sha256 beside it" 0 0 "$(cd "$o" && sha256sum -c "$(basename "$a").sha256" 2>&1)" ": OK"
u=$(mktemp -d); tar -C "$u" -xf "$a"
check "  the MANIFEST names the release, PostgreSQL, the migration, the backup and the key slots" 0 0 "$(tr '\n' '|' < "$u/MANIFEST")" "release $OLD $(git -C "$d/twes-in" rev-parse HEAD)|postgres-major 18|migration DoctrineMigrations\Version20261114183314|backup-label $LABEL|key-slots "
check "  APP_MFA_KEY among the key slots" 0 0 "$(grep '^key-slots' "$u/MANIFEST")" " APP_MFA_KEY"
check "  no value written in the MANIFEST" 0 0 "[$(grep -cF -e "$(value_of "$d" APP_MFA_KEY)" "$u/MANIFEST")]" "[0]"
check "  the counts are app:fiscal:inventory's" 0 0 "$(cat "$u/counts.json")" '"fiscal":true'
unsealed "$u/values.env.gpg" "$u/values.env"; check "  the values open with the passphrase, APP_MFA_KEY in them" $? 0 "[$(grep -cxF -e "APP_MFA_KEY=$(value_of "$d" APP_MFA_KEY)" "$u/values.env")]" "[1]"
check "  the backup folder whole" 0 0 "$(tar -xOf "$u/backup.tar" ./pgbackrest/archive)" wal
check "  held while the backups were read, the hold given back after" 0 0 "$(cat "$TMPDIR/held-while-read" 2>&1)$( [[ -f "$d/etc/state/hold" ]] || echo ' released')" "held released"
check "  backed up first" 0 0 "$(deployed)" "--compose exec -T backup twes-backup before-update|"
check "  nothing left behind in the folder" 0 0 "[$(cd "$o" && ls -A | tr '\n' ' ')]" "[$(basename "$a") $(basename "$a").sha256 ]"
rm -rf "$u"; rm -f "$TMPDIR/held-while-read"
out=$(printf '%s\n' "$PASS" | twes "$d" verify-export "$a"); check "verify-export: the archive as sealed" $? 0 "$out" "verified: $a, 7 members as sealed"
check "  it says what the MANIFEST holds" 0 0 "$out" "backup-label $LABEL"
out=$(printf 'not the passphrase\n' | twes "$d" verify-export "$a"); check "  a wrong passphrase refused" $? 1 "$out" "the passphrase does not open the MANIFEST"
cp "$a" "$o/changed.tar"; repacked "$o/changed.tar" 'echo "{}" > counts.json'
out=$(printf '%s\n' "$PASS" | twes "$d" verify-export "$o/changed.tar"); check "  a member changed after the MANIFEST refused" $? 1 "$out" "counts.json was changed after the MANIFEST"
cp "$a" "$o/dropped.tar"; repacked "$o/dropped.tar" 'rm state.tar'
out=$(printf '%s\n' "$PASS" | twes "$d" verify-export "$o/dropped.tar"); check "  a member dropped refused" $? 1 "$out" "state.tar is named by the MANIFEST and missing"
cp "$a" "$o/extra.tar"; repacked "$o/extra.tar" 'echo x > extra.sh'
out=$(printf '%s\n' "$PASS" | twes "$d" verify-export "$o/extra.tar"); check "  a member added refused" $? 1 "$out" "extra.sh is in the archive and not in the MANIFEST"
# Whoever changes a member and writes its sum in the MANIFEST again, without the passphrase, cannot seal it again.
cp "$a" "$o/forged.tar"; repacked "$o/forged.tar" 'echo "{}" > counts.json; sed -i "s/^sha256 [0-9a-f]* counts.json$/sha256 $(sha256sum counts.json | cut -d" " -f1) counts.json/" MANIFEST'
out=$(printf '%s\n' "$PASS" | twes "$d" verify-export "$o/forged.tar"); check "  a MANIFEST written again without the passphrase refused" $? 1 "$out" "the MANIFEST was changed after it was sealed"
# One holding the passphrase who sealed a MANIFEST without a required member: an archive of another format, or cut.
cp "$a" "$o/unlisted.tar"; repacked "$o/unlisted.tar" 'grep -v " state.tar$" MANIFEST > m; mv m MANIFEST; rm state.tar MANIFEST.gpg; sealed MANIFEST MANIFEST.gpg'
out=$(printf '%s\n' "$PASS" | twes "$d" verify-export "$o/unlisted.tar"); check "  a required member missing from the MANIFEST refused" $? 1 "$out" "state.tar is missing from the MANIFEST"
d=$(installed); o=$(mktemp -d)
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | exported "$d" export "$o" --with-images); check "export --with-images: the release's images saved" $? 0 "$(tar -tf "$(archive_in "$o")")$(cat "$TMPDIR/docker.save")" "images.tar"
check "  by the digests compose names" 0 0 "$(cat "$TMPDIR/docker.save")" "save -o"
check "  no authority where the edge has none" 0 0 "[$(tar -tf "$(archive_in "$o")" | grep -c ca.tar.gpg)]" "[0]"
d=$(installed); o=$(mktemp -d)
out=$(printf 'too short\ntoo short\n' | exported "$d" export "$o"); check "export: a short passphrase refused" $? 1 "$out" "a passphrase of 12 characters at least: nothing exported"
out=$(printf '%s\n%s\n' "$PASS" "another passphrase" | exported "$d" export "$o"); check "  two passphrases differing refused" $? 1 "$out" "the two passphrases differ"
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | BACKUP_LABEL='' twes "$d" export "$o"); check "  no final backup, nothing exported" $? 1 "$out" "the final backup was not made: nothing exported"
check "  the hold given back, nothing left in the folder" 0 0 "[$( [[ -f "$d/etc/state/hold" ]] && echo held)$(ls -A "$o")]" "[]"
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | BACKUP_TAR_FAILS=1 exported "$d" export "$o"); check "  a backup folder unread refused" $? 1 "$out" "the backup folder could not be read: nothing exported"
check "  and leaves no part behind" 0 0 "[$( [[ -f "$d/etc/state/hold" ]] && echo held)$(ls -A "$o")]" "[]"
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | INVENTORY_EXIT=1 exported "$d" export "$o"); check "  a database that cannot be counted refused" $? 1 "$out" "could not be counted (app:fiscal:inventory exited 1)"
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | exported "$d" export "$o/none"); check "  a folder that is not there refused" $? 1 "$out" "is not a folder this account can write"
twes "$d" hold on >/dev/null; out=$(printf '%s\n%s\n' "$PASS" "$PASS" | exported "$d" export "$o"); check "  on hold refused" $? 1 "$out" "no export until twes hold off"
twes "$d" hold off >/dev/null; sed -i 's/^APP_MFA_KEY=.*/APP_MFA_KEY=/' "$d/etc/install.env"
out=$(printf '%s\n%s\n' "$PASS" "$PASS" | exported "$d" export "$o"); check "  no APP_MFA_KEY refused" $? 1 "$out" "the values hold no APP_MFA_KEY"
check "  none of them wrote anything" 0 0 "[$(ls -A "$o")]" "[]"
out=$(twes "$d" export); check "  no folder named: its usage" $? 2 "$out" "export <folder> [--with-images]"
out=$(twes "$d" export "$o" --with-everything); check "  an unknown option: its usage" $? 2 "$out" "verify-export <archive>"

echo "twes: $pass passed, $fail failed"
((fail == 0))
