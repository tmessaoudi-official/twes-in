#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# scripts/onprem/wsl-engine.sh over a made-up /etc and /proc, with stand-ins for what it asks of Ubuntu: id, getent, apt-get
# (installing docker-ce makes docker answer), curl, gpg (the key's fingerprint as each case says), dpkg, systemctl,
# usermod and docker. Each writes down how it was called.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR
trap 'rm -rf "$TMPDIR"' EXIT
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
DOCKER_KEY=9DC858229FC7DD38854AE2D88D81803C0EBFCD88

mkdir -p "$TMPDIR/bin"
printf '#!/usr/bin/env bash\nif [[ "$1" == -u ]]; then echo "${AS_UID:-0}"; else exec /usr/bin/id "$@"; fi\n' > "$TMPDIR/bin/id"
printf '#!/usr/bin/env bash\n[[ "$1 $2" == "passwd shop" ]] && echo "shop:x:1000:1000::/home/shop:/bin/bash"\n' > "$TMPDIR/bin/getent"
cat > "$TMPDIR/bin/apt-get" <<'EOF'
#!/usr/bin/env bash
echo "apt-get $*" >> "$TMPDIR/calls"
[[ "$*" != *docker-compose-plugin* ]] || touch "$TMPDIR/docker.installed"
EOF
printf '#!/usr/bin/env bash\necho "curl $*" >> "$TMPDIR/calls"\nwhile (($#)); do [[ "$1" == -o ]] && echo key > "$2"; shift; done\n' > "$TMPDIR/bin/curl"
printf '#!/usr/bin/env bash\necho "pub:-:255:22:8D81803C0EBFCD88:"\necho "fpr:::::::::${KEY_FPR}:"\necho "fpr:::::::::D3306A018370199E527AE7997EA0A9C3F273FCD8:"\n' > "$TMPDIR/bin/gpg"
printf '#!/usr/bin/env bash\necho amd64\n' > "$TMPDIR/bin/dpkg"
printf '#!/usr/bin/env bash\necho "systemctl $*" >> "$TMPDIR/calls"\n' > "$TMPDIR/bin/systemctl"
printf '#!/usr/bin/env bash\necho "usermod $*" >> "$TMPDIR/calls"\n' > "$TMPDIR/bin/usermod"
# docker: the machine's own would answer otherwise; this one says nothing is installed until apt-get installed it.
printf '#!/usr/bin/env bash\n[[ -f "$TMPDIR/docker.installed" ]] || exit 127\necho "docker $*" >> "$TMPDIR/calls"\n' > "$TMPDIR/bin/docker"
chmod +x "$TMPDIR/bin/"*

machine() { # systemd or init as PID 1, and the wsl.conf it starts with (none when absent)
  local d; d=$(mktemp -d)
  mkdir -p "$d/proc/1" "$d/etc/apt/sources.list.d"
  echo "$1" > "$d/proc/1/comm"
  [[ -z "${2+given}" ]] || printf '%b' "$2" > "$d/etc/wsl.conf"
  printf 'NAME="Ubuntu"\nVERSION_CODENAME=noble\nUBUNTU_CODENAME=noble\n' > "$d/etc/os-release"
  echo "$d"
}
engine() { # machine, then the account
  local d=$1; shift
  rm -f "$TMPDIR/calls" "$TMPDIR/docker.installed"
  [[ -z "${INSTALLED:-}" ]] || touch "$TMPDIR/docker.installed"
  env PATH="$TMPDIR/bin:$PATH" TWES_ETC="$d/etc" TWES_PROC="$d/proc" KEY_FPR="${KEY_FPR:-$DOCKER_KEY}" \
    bash "$HERE/onprem/wsl-engine.sh" "$@" 2>&1
}
calls() { [[ -f "$TMPDIR/calls" ]] && tr '\n' '|' < "$TMPDIR/calls"; }
echo "wsl-engine"

d=$(machine systemd)
out=$(AS_UID=1000 engine "$d" shop); check "refused unless root" $? 1 "$out" "run as root"
out=$(engine "$d" 'shop; rm -rf /'); check "an account that is no plain name refused" $? 1 "$out" "a name such as shop"
out=$(engine "$d" nobody-here); check "an account Ubuntu does not have refused" $? 1 "$out" "Ubuntu has no account nobody-here"
[[ -z "$(calls)" ]]; check "  nothing done before that" $? 0 "$(calls)" ""

d=$(machine init)
out=$(engine "$d" shop); check "no systemd yet: switched on, WSL to start again" $? 10 "$out" "wsl --shutdown"
check "  in a wsl.conf of its own" 0 0 "$(cat "$d/etc/wsl.conf")" "[boot]
systemd=true"
[[ "$(cat "$d/etc/wsl.conf")" == $'[boot]\nsystemd=true' ]]; check "  and nothing else" $? 0 "$(cat "$d/etc/wsl.conf")" ""
[[ "$(stat -c %a "$d/etc/wsl.conf")" == 644 ]]; check "  readable by WSL" $? 0 "$(stat -c %a "$d/etc/wsl.conf")" ""
[[ -z "$(calls)" ]]; check "  and Docker left for the run after" $? 0 "$(calls)" ""

d=$(machine init '[network]\ngenerateResolvConf = false\n\n[Boot]\nsystemd=false\ncommand = mount -a\n')
out=$(engine "$d" shop)
[[ "$(cat "$d/etc/wsl.conf")" == $'[network]\ngenerateResolvConf = false\n\n[Boot]\nsystemd=true\ncommand = mount -a' ]]
check "a wsl.conf of the shop's: its systemd line set, every other line kept" $? 0 "$(cat "$d/etc/wsl.conf")" ""
d=$(machine init '[boot]\ncommand = mount -a\n[user]\ndefault = shop\n')
out=$(engine "$d" shop)
[[ "$(cat "$d/etc/wsl.conf")" == $'[boot]\ncommand = mount -a\nsystemd=true\n[user]\ndefault = shop' ]]
check "  a [boot] without one: the line added inside it" $? 0 "$(cat "$d/etc/wsl.conf")" ""
d=$(machine init '[user]\ndefault = shop\n')
out=$(engine "$d" shop)
[[ "$(cat "$d/etc/wsl.conf")" == $'[user]\ndefault = shop\n[boot]\nsystemd=true' ]]
check "  no [boot]: one added" $? 0 "$(cat "$d/etc/wsl.conf")" ""

d=$(machine systemd)
out=$(engine "$d" shop); check "systemd: Docker Engine installed" $? 0 "$out" "Docker Engine runs under systemd and starts with WSL; shop is in the docker group"
check "  from Docker's repository, its key checked first" 0 0 "$(calls)" \
  "apt-get update -q|apt-get install -y -q ca-certificates curl gnupg|curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o "
check "  the packages Docker's documentation names" 0 0 "$(calls)" \
  "apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin|systemctl enable --now containerd.service docker.service|usermod -aG docker shop|docker info|"
check "  its key kept" 0 0 "$(cat "$d/etc/apt/keyrings/docker.asc")" "key"
[[ "$(cat "$d/etc/apt/sources.list.d/docker.sources")" == "Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: noble
Components: stable
Architectures: amd64
Signed-By: $d/etc/apt/keyrings/docker.asc" ]]
check "  its repository for this Ubuntu, signed by that key" $? 0 "$(cat "$d/etc/apt/sources.list.d/docker.sources")" ""

d=$(machine systemd)
out=$(KEY_FPR=0000000000000000000000000000000000000000 engine "$d" shop); check "another key: refused, naming both" $? 1 "$out" "is '0000000000000000000000000000000000000000', not the $DOCKER_KEY"
[[ ! -e "$d/etc/apt/sources.list.d/docker.sources" && "$(calls)" != *docker-ce* ]]; check "  and nothing from it installed" $? 0 "$(calls)" ""

d=$(machine systemd)
out=$(INSTALLED=1 engine "$d" shop); check "installed already: not again" $? 0 "$out" "installed already"
[[ "$(calls)" != *apt-get* ]]; check "  no apt-get" $? 0 "$(calls)" ""
check "  still enabled and the account in its group" 0 0 "$(calls)" "systemctl enable --now containerd.service docker.service|usermod -aG docker shop|"

echo "wsl-engine: $pass passed, $fail failed"
((fail == 0))
