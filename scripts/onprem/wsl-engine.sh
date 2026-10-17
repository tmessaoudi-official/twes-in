#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Windows' second way (docs/START.md section 16): Docker Engine inside WSL's Ubuntu, with no Docker Desktop. Docker Desktop
# runs only once someone signs in; Docker Engine is a systemd service of Ubuntu, so the installation comes up whenever WSL
# does, which install.ps1 makes happen at Windows' start, and systemd brings Docker back after any stop of it.
# install.ps1 runs this as root once the checkout's release tag is verified, naming the Ubuntu account that runs twes:
#   wsl-engine.sh <account>
# Exit 10: systemd has just been switched on in /etc/wsl.conf; WSL must stop (wsl --shutdown) and this run again.
# Docker is installed as its own documentation says (https://docs.docker.com/engine/install/ubuntu/), from its apt
# repository, whose key must have the fingerprint below before anything from it is trusted.
set -euo pipefail
account=${1:-}
etc=${TWES_ETC:-/etc}
proc=${TWES_PROC:-/proc}
# Docker Release (CE deb), read from the key download.docker.com serves.
docker_key=9DC858229FC7DD38854AE2D88D81803C0EBFCD88

say() { echo "wsl-engine: $*"; }
refuse() { echo "wsl-engine: refused — $*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || refuse "run as root (install.ps1 does: wsl -u root)"
[[ "$account" =~ ^[a-z_][a-z0-9_-]*$ ]] || refuse "the Ubuntu account that runs twes is a name such as shop, got '$account'"
getent passwd "$account" >/dev/null || refuse "Ubuntu has no account $account"

# systemd starts Docker with Ubuntu and restarts it; WSL runs it only when /etc/wsl.conf says so, read at WSL's start.
if [[ "$(< "$proc/1/comm")" != systemd ]]; then
  conf=$etc/wsl.conf
  touch "$conf"
  # The [boot] section's systemd line set to true, added where missing; every other line kept as it is.
  awk '
    /^[[:space:]]*\[/ {
      if (boot && !done) { print "systemd=true"; done = 1 }
      boot = tolower($0) ~ /^[[:space:]]*\[boot\][[:space:]]*$/
    }
    boot && /^[[:space:]]*systemd[[:space:]]*=/ { if (!done) { print "systemd=true"; done = 1 }; next }
    { print }
    END { if (!done) { if (!boot) print "[boot]"; print "systemd=true" } }
  ' "$conf" > "$conf.twes"
  chmod 644 "$conf.twes"
  mv "$conf.twes" "$conf"
  say "systemd switched on in $conf: WSL must start again (wsl --shutdown), then this runs again"
  exit 10
fi

if command -v docker >/dev/null && docker compose version >/dev/null 2>&1; then
  say "Docker Engine and its compose plugin are installed already"
else
  apt-get update -q
  apt-get install -y -q ca-certificates curl gnupg
  key=$(mktemp)
  trap 'rm -f "$key"' EXIT
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o "$key"
  fingerprint=$(gpg --show-keys --with-colons "$key" | awk -F: '$1 == "fpr" { print $10; exit }')
  [[ "$fingerprint" == "$docker_key" ]] \
    || refuse "Docker's apt key is '$fingerprint', not the $docker_key this script carries: nothing was installed"
  install -m 0755 -d "$etc/apt/keyrings"
  install -m 0644 "$key" "$etc/apt/keyrings/docker.asc"
  codename=$(. "$etc/os-release" && echo "${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}")
  [[ -n "$codename" ]] || refuse "$etc/os-release names no Ubuntu release"
  printf '%s\n' 'Types: deb' 'URIs: https://download.docker.com/linux/ubuntu' "Suites: $codename" 'Components: stable' \
    "Architectures: $(dpkg --print-architecture)" "Signed-By: $etc/apt/keyrings/docker.asc" > "$etc/apt/sources.list.d/docker.sources"
  apt-get update -q
  apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

# Enabled, they start with Ubuntu; Docker then starts the containers again, each restarting unless stopped by hand.
systemctl enable --now containerd.service docker.service
usermod -aG docker "$account"
docker info >/dev/null || refuse "Docker does not answer: read systemctl status docker"
say "Docker Engine runs under systemd and starts with WSL; $account is in the docker group"
