#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every variable a browser suite reads (`process.env['X']` in web/playwright*.ts and web/e2e*/) is passed into the
# `web-tools` service, which runs them: compose hands a container only what its `environment` names, so a variable left
# out reads as unset there whatever the caller set. `CI` left out ran CI's suites without `forbidOnly`, and `MAILPIT_URL`
# left out read another stack's mails.
# Usage: e2e-environment.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=20
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "e2e-environment: unknown argument $1" >&2; exit 2 ;;
  esac
done
problems=()

# The keys of web-tools' environment: from its `environment:` line to the next key at the service's own indent.
passed=$(awk '
  /^  [A-Za-z0-9_-]+:/ { inside = ($0 ~ /^  web-tools:/); env = 0; next }
  inside && /^    environment:/ { env = 1; next }
  inside && /^    [A-Za-z]/ { env = 0 }
  inside && env && match($0, /^      [A-Z0-9_]+:/) { name = substr($0, 7, RLENGTH - 7); print name }
' "$root/compose.yaml")

declare -A readIn=()
while IFS= read -r hit; do
  file=${hit%%:*}; name=${hit##*\[\'}; name=${name%\'\]}
  [[ -n "${readIn[$name]:-}" ]] || readIn[$name]=${file#"$root/"}
done < <(grep -rHoE "process\.env\['[A-Z0-9_]+'\]" "$root"/web/playwright*.ts "$root"/web/e2e* 2>/dev/null)

for name in "${!readIn[@]}"; do
  grep -qxF -e "$name" <<<"$passed" \
    || problems+=("$name, read in ${readIn[$name]}, is not passed into web-tools: name it under its environment in compose.yaml")
done
((${#readIn[@]} >= floor)) \
  || problems+=("found ${#readIn[@]} variable(s) read, below the floor of $floor: the search reads less than it should")

if ((${#problems[@]})); then
  printf 'e2e-environment: FAIL\n'
  printf '  %s\n' "${problems[@]}" | sort
  exit 1
fi
printf 'e2e-environment: OK — %d variables read by the browser suites, each passed into web-tools\n' "${#readIn[@]}"
