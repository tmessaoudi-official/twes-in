#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A reader decides a lost connection by the one rule, `isUnreachable` (`web/src/app/shared/errors/unknown-refusal-api.ts`):
# no answer at all, or a gateway answering 502, 503 or 504 for an API that is restarting. A reader testing the status
# against 0 itself says a restarting API's 503 in its own words (« invalide », « refusé ») instead of « injoignable »,
# as 33 readers did. Any such test in the app's TypeScript reds with its file and line; the one place allowed asks
# another question and says why below. A floor of readers using the rule reds a gate gone blind.
# Usage: reader-network.sh [--root DIR] [--floor N]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=36
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "reader-network: unknown argument $1"; exit 2 ;;
  esac
done
app="$root/web/src/app"
if [[ ! -d "$app" ]]; then echo "reader-network: FAIL — found no web/src/app"; exit 1; fi

# Allowed, each with its reason: the submissions interceptor asks whether a write may not have been answered yet, which
# every server error leaves open too, not whether the connection is lost.
allowed=(web/src/app/shared/submissions/submission-interceptor.ts)

mapfile -t sources < <(find "$app" -name '*.ts' ! -name '*.spec.ts' | sort)
mapfile -t users < <(grep -l 'isUnreachable(' "${sources[@]}" /dev/null | grep -v 'unknown-refusal-api\.ts$')
if ((${#users[@]} < floor)); then
  echo "reader-network: FAIL — ${#users[@]} files decide the network through isUnreachable, below the floor of $floor: is web/src/app where it was?"
  exit 1
fi

found=()
while IFS= read -r hit; do
  file=${hit%%:*}
  rel=${file#"$root/"}
  skip=0
  for one in "${allowed[@]}"; do [[ $rel == "$one" ]] && skip=1; done
  ((skip)) || found+=("$rel:$(cut -d: -f2 <<<"$hit")")
done < <(grep -nE '\bstatus[[:space:]]*(===|==|!==|!=|>|<=)[[:space:]]*0\b' "${sources[@]}" /dev/null)

if ((${#found[@]} > 0)); then
  echo "reader-network: FAIL — ${#found[@]} places decide the network by the status alone; use isUnreachable(error) from shared/errors/unknown-refusal-api.ts:"
  printf '  %s\n' "${found[@]}"
  exit 1
fi
echo "reader-network: OK — ${#sources[@]} sources read, ${#users[@]} decide the network through isUnreachable, none by the status alone"
