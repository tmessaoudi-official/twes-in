#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A screen says a refusal through the `refusal` pipe (`web/src/app/shared/errors/refusal-pipe.ts`), which words the
# screen's own code, an answer it has no words for and a part another feature could not read for it. A key joined by
# hand in a template (`{{ 'products.errors.' + code | translate }}`) says only the first, and the other two as
# « products.errors.[object Object] ». The templates still joining one are held under a ceiling that only comes down: one
# more reds, naming its file and line, and one moved to the pipe lowers the ceiling here in the same change, so the
# count never grows back unseen. A floor of templates read reds a gate gone blind.
# Usage: refusal-keys-joined.sh [--root DIR] [--ceiling N] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
ceiling=50
floor=100
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --ceiling) ceiling=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "refusal-keys-joined: unknown argument $1"; exit 2 ;;
  esac
done
app="$root/web/src/app"
if [[ ! -d "$app" ]]; then echo "refusal-keys-joined: FAIL — found no web/src/app"; exit 1; fi

mapfile -t templates < <(find "$app" -name '*.html' | sort)
if ((${#templates[@]} < floor)); then
  echo "refusal-keys-joined: FAIL — read ${#templates[@]} templates, below the floor of $floor: is web/src/app where it was?"
  exit 1
fi

# Each place a template joins a refusal's key by hand: a quoted key ending in `errors.` followed by `+`.
mapfile -t joined < <(grep -nE "'[a-z0-9_.]*errors\.'[[:space:]]*\+" "${templates[@]}" | cut -d: -f1,2 | sed "s#^$root/##")
count=${#joined[@]}
if ((count > ceiling)); then
  echo "refusal-keys-joined: FAIL — $count refusal keys joined by hand in templates, over the ceiling of $ceiling; say the refusal through the refusal pipe ({{ code | refusal: '<prefix>' }}):"
  printf '  %s\n' "${joined[@]}"
  exit 1
fi
if ((count < ceiling)); then
  echo "refusal-keys-joined: FAIL — $count refusal keys joined by hand in templates, under the ceiling of $ceiling: lower the ceiling in scripts/gates/refusal-keys-joined.sh to $count, so it never grows back"
  exit 1
fi
echo "refusal-keys-joined: OK — ${#templates[@]} templates read, $count refusal keys still joined by hand, at the ceiling"
