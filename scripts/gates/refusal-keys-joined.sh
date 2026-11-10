#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A screen says a refusal through the `refusal` pipe (`web/src/app/shared/errors/refusal-pipe.ts`), which words the
# screen's own code, an answer it has no words for and a part another feature could not read for it. A key joined by
# hand in a template (`{{ 'products.errors.' + code | translate }}`) says only the first, and the other two as
# « products.errors.[object Object] ». The templates still joining one are held under a ceiling that only comes down: one
# more reds, naming its file and line, and one moved to the pipe lowers the ceiling here in the same change, so the
# count never grows back unseen. TypeScript is held the same way, under a ceiling of its own: a toast's key joined by hand
# (`feedback.failure(\`products.errors.${code}\`)`) or an inline template's, said through `refusalKey` once moved; specs
# are not read. A floor of templates and one of sources read red a gate gone blind.
# Usage: refusal-keys-joined.sh [--root DIR] [--ceiling N] [--floor N] [--ts-ceiling N] [--ts-floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
ceiling=11
floor=100
ts_ceiling=8
ts_floor=600
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --ceiling) ceiling=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    --ts-ceiling) ts_ceiling=$2; shift 2 ;;
    --ts-floor) ts_floor=$2; shift 2 ;;
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

mapfile -t sources < <(find "$app" -name '*.ts' ! -name '*.spec.ts' | sort)
if ((${#sources[@]} < ts_floor)); then
  echo "refusal-keys-joined: FAIL — read ${#sources[@]} sources, below the floor of $ts_floor: is web/src/app where it was?"
  exit 1
fi

# Each place a refusal's key is joined by hand: a quoted key ending in `errors.` followed by `+`, or, in TypeScript, a
# template literal whose key ends in `errors.` before a placeholder, its prefix written out or computed.
quoted="'[a-z0-9_.]*errors\.'[[:space:]]*\+"
literal='`[^`]*errors\.\$\{'
mapfile -t joined < <(grep -nE "$quoted" "${templates[@]}" | cut -d: -f1,2 | sed "s#^$root/##")
mapfile -t ts_joined < <(grep -nE -e "$quoted" -e "$literal" "${sources[@]}" | cut -d: -f1,2 | sed "s#^$root/##")
count=${#joined[@]}
ts_count=${#ts_joined[@]}
if ((count > ceiling)); then
  echo "refusal-keys-joined: FAIL — $count refusal keys joined by hand in templates, over the ceiling of $ceiling; say the refusal through the refusal pipe ({{ code | refusal: '<prefix>' }}):"
  printf '  %s\n' "${joined[@]}"
  exit 1
fi
if ((count < ceiling)); then
  echo "refusal-keys-joined: FAIL — $count refusal keys joined by hand in templates, under the ceiling of $ceiling: lower the ceiling in scripts/gates/refusal-keys-joined.sh to $count, so it never grows back"
  exit 1
fi
if ((ts_count > ts_ceiling)); then
  echo "refusal-keys-joined: FAIL — $ts_count refusal keys joined by hand in TypeScript, over the TypeScript ceiling of $ts_ceiling; say the refusal through refusalKey (shared/errors/unknown-refusal.ts) or, in an inline template, the refusal pipe:"
  printf '  %s\n' "${ts_joined[@]}"
  exit 1
fi
if ((ts_count < ts_ceiling)); then
  echo "refusal-keys-joined: FAIL — $ts_count refusal keys joined by hand in TypeScript, under the ceiling of $ts_ceiling: lower the TypeScript ceiling in scripts/gates/refusal-keys-joined.sh to $ts_count, so it never grows back"
  exit 1
fi
echo "refusal-keys-joined: OK — ${#templates[@]} templates and ${#sources[@]} sources read, $count refusal keys still joined by hand in templates and $ts_count in TypeScript, at their ceilings"
