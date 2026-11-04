#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What make reset asks before anything else: on a shop's own computer, whose values file says TWES_INSTALL=onprem, the
# stack is the shop's (its compose project is twes-in too) and reset would delete its invoices and their PDFs. Refused
# there whatever CONFIRM says; a shop is taken back to a backup, never wiped. The values file is the one scripts/onprem/twes
# reads, read the same way: its last TWES_INSTALL line decides.
set -uo pipefail
values=${TWES_VALUES:-$HOME/.twes-in/install.env}
install=''
if [[ -e "$values" && ! -r "$values" ]]; then
  echo "make reset: refused — $values cannot be read, so whether this is a shop's computer is not known" >&2
  exit 1
elif [[ -e "$values" ]]; then
  install=$(awk 'index($0, "TWES_INSTALL=") == 1 { v = substr($0, 14) } END { print v }' "$values")
fi
if [[ "$install" == onprem ]]; then
  echo "make reset: refused — $values is a shop's installation (TWES_INSTALL=onprem): this computer's stack holds the" \
    "shop's invoices. A shop is taken back to a backup with twes restore-commands (docs/RUNBOOK.md « Restore a shop »)," \
    "never wiped; CONFIRM=yes does not change that." >&2
  exit 1
fi

# The values file is the caller's, while the stack is the shop's whoever runs make: Docker is asked too, for what only a
# shop's installation leaves, the volume its local backups go to or a container of the project started as a shop's.
# Docker not answering adds no reason: reset could not reach the stack either. A volume it does not know answers 1.
project=${COMPOSE_PROJECT_NAME:-twes-in}
refuse() {
  echo "make reset: refused — $1: this computer's stack is a shop's and holds its invoices. A shop is taken back to a" \
    "backup with twes restore-commands (docs/RUNBOOK.md « Restore a shop »), never wiped; CONFIRM=yes does not change that." >&2
  exit 1
}
if docker volume inspect "${project}_backup-local" >/dev/null 2>&1; then
  refuse "the volume ${project}_backup-local, where a shop's local backups go, exists"
fi
mapfile -t containers < <(docker ps -aq --filter "label=com.docker.compose.project=$project" 2>/dev/null)
if ((${#containers[@]})) && docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "${containers[@]}" 2>/dev/null |
  grep -qx 'APP_INSTALLATION=onprem'; then
  refuse "a container of compose project $project was started as a shop's (APP_INSTALLATION=onprem)"
fi
