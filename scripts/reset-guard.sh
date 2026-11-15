#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What make reset asks before anything else: on a shop's own computer, whose values file says TWES_INSTALL=onprem, the
# stack is the shop's (its compose project is twes-in too) and reset would delete its invoices and their PDFs. Refused
# there whatever CONFIRM says; a shop is taken back to a backup, never wiped. The values file is the one scripts/onprem/twes
# reads, read the same way: its last TWES_INSTALL line decides.
#
# With --shop-only (make reset-empty) only the shop is asked about: app:platform:reset refuses anything fiscal itself,
# as it never empties an issued document in place.
set -uo pipefail
what='make reset'
[[ "${1:-}" != --shop-only ]] || what='make reset-empty'
values=${TWES_VALUES:-$HOME/.twes-in/install.env}
install=''
if [[ -e "$values" && ! -r "$values" ]]; then
  echo "$what: refused — $values cannot be read, so whether this is a shop's computer is not known" >&2
  exit 1
elif [[ -e "$values" ]]; then
  install=$(awk 'index($0, "TWES_INSTALL=") == 1 { v = substr($0, 14) } END { print v }' "$values")
fi
if [[ "$install" == onprem ]]; then
  echo "$what: refused — $values is a shop's installation (TWES_INSTALL=onprem): this computer's stack holds the" \
    "shop's invoices. A shop is taken back to a backup with twes restore-commands (docs/RUNBOOK.md « Restore a shop »)," \
    "never wiped; CONFIRM=yes does not change that." >&2
  exit 1
fi

# The values file is the caller's, while the stack is the shop's whoever runs make: Docker is asked too, for what only a
# shop's installation leaves, the volume its local backups go to or a container of the project started as a shop's.
# Docker not answering adds no reason: reset could not reach the stack either. A volume it does not know answers 1.
project=${COMPOSE_PROJECT_NAME:-twes-in}
refuse() {
  echo "$what: refused — $1: this computer's stack is a shop's and holds its invoices. A shop is taken back to a" \
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

# Issued documents: a database holding any is not wiped by a bare reset, CONFIRM=yes or not. The application counts
# them by its own definition (app:fiscal:inventory, exit 3 when any is there). Going on needs WITH_ISSUED_DOCUMENTS=yes
# and the counts typed back, as twes reset asks on a shop. No database volume: nothing to count. A database the api
# cannot count (not running, or the count failing) is refused as an unreadable values file is: not taken for empty.
[[ "${1:-}" != --shop-only ]] || exit 0
if docker volume inspect "${project}_postgres-data" >/dev/null 2>&1; then
  err=$(mktemp); counted=$(docker compose exec -T api bin/console app:fiscal:inventory --json --no-interaction 2>"$err"); code=$?
  said=$(cat "$err"); rm -f "$err"
  if ((code != 0 && code != 3)); then
    echo "make reset: refused — could not count the issued documents of the database in volume ${project}_postgres-data" \
      "(app:fiscal:inventory exited $code: ${said:-no message}). Start the stack (make up) and run make reset again, so" \
      "what it would delete is known first." >&2
    exit 1
  fi
  if ((code == 3)); then
    read -r records companies all < <(jq -r '[([.companies[].records[] | select(.kind == "fiscal") | .count] | add // 0),
      ([.companies[] | select(.fiscal)] | length), (.companies | length)] | @tsv' <<< "$counted")
    if [[ ! "${records:-}" =~ ^[0-9]+$ ]]; then
      echo "make reset: refused — could not read the count app:fiscal:inventory printed; nothing deleted." >&2
      exit 1
    fi
    sentence="erase $records fiscal records in $companies of $all companies of $project"
    if [[ "${WITH_ISSUED_DOCUMENTS:-}" != yes ]]; then
      echo "make reset: refused — the database holds $records fiscal records in $companies of $all companies (issued" \
        "documents and their ledger, app:fiscal:inventory lists them). Back them up first if they matter; to delete them" \
        "knowingly run make reset WITH_ISSUED_DOCUMENTS=yes and type the counts back. CONFIRM=yes does not change that." >&2
      exit 1
    fi
    printf 'make reset deletes issued documents. Type « %s » to go on: ' "$sentence"
    read -r typed
    if [[ "$typed" != "$sentence" ]]; then
      echo "make reset: refused — the sentence typed was not « $sentence ». Nothing deleted." >&2
      exit 1
    fi
  fi
fi
