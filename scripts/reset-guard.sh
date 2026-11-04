#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What make reset asks before anything else: on a shop's own computer, whose values file says TWES_INSTALL=onprem, the
# stack is the shop's (its compose project is twes-in too) and reset would delete its invoices and their PDFs. Refused
# there whatever CONFIRM says; a shop is taken back to a backup, never wiped. The values file is the one scripts/onprem/twes
# reads, read the same way: its last TWES_INSTALL line decides.
set -uo pipefail
values=${TWES_VALUES:-$HOME/.twes-in/install.env}
[[ -e "$values" ]] || exit 0
if [[ ! -r "$values" ]]; then
  echo "make reset: refused — $values cannot be read, so whether this is a shop's computer is not known" >&2
  exit 1
fi
install=$(awk 'index($0, "TWES_INSTALL=") == 1 { v = substr($0, 14) } END { print v }' "$values")
if [[ "$install" == onprem ]]; then
  echo "make reset: refused — $values is a shop's installation (TWES_INSTALL=onprem): this computer's stack holds the" \
    "shop's invoices. A shop is taken back to a backup with twes restore-commands (docs/RUNBOOK.md « Restore a shop »)," \
    "never wiped; CONFIRM=yes does not change that." >&2
  exit 1
fi
