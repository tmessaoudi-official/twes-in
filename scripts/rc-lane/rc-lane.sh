#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The RC lane: the API on PHP's next release candidate and Symfony's next minor, so what an upgrade breaks is known
# before the release (docs/plans/version-updates.plan.md row 17; the probe it repeats is that plan's « Probe » section).
# It never touches the working tree: api/ is copied into the toolchain's temporary directory, which sits outside it,
# and only that copy is moved to the next Symfony and installed.
#
#   rc-lane.sh run <out-dir>                 in the RC image (`make rc-lane`): copy api/, move it to $RC_SYMFONY
#                                            (default 8.2), resolve, list the container's deprecations, PHPStan, the
#                                            suite on a database of its own (TEST_TOKEN=_rc), then the summary.
#                                            Exits 0 once the summary is written, whatever the steps found: the lane
#                                            reports, it never blocks; a step's failure is a warning on GitHub.
#   rc-lane.sh rewrite <composer.json> <X.Y> move Flex's Symfony line and every symfony/* constraint on the current
#                                            minor to X.Y.*, betas allowed, stable releases preferred
#   rc-lane.sh summary <out-dir>             <out-dir>/summary.md from steps.tsv (step, exit), versions.txt,
#                                            deprecations.log and each step's <step>.log
#
# php-cs-fixer is not run: it refuses a PHP it does not know yet (« supports PHP syntax only up to PHP 8.5 »), which the
# summary says. Exit 2: a usage or a refused input.
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

usage() { sed -n '9,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2; echo "usage: rc-lane.sh run|rewrite|summary …" >&2; exit 2; }

rewrite() {
  [[ $# -eq 2 && -f "$1" ]] || usage
  [[ "$2" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "rc-lane: the Symfony minor must read X.Y, not « $2 »" >&2; exit 2; }
  python3 -I - "$1" "$2" <<'EOF'
import json, re, sys
path, minor = sys.argv[1], sys.argv[2]
d = json.load(open(path))
current = ((d.get('extra') or {}).get('symfony') or {}).get('require')
if not current:
    sys.exit(print('rc-lane: ' + path + ' names no extra.symfony.require, the Symfony line Flex holds', file=sys.stderr) or 2)
if current == minor + '.*':
    sys.exit(print('rc-lane: ' + path + ' is already on ' + minor, file=sys.stderr) or 2)
moved = 0
for section in ('require', 'require-dev'):
    for name, constraint in (d.get(section) or {}).items():
        if name.startswith('symfony/') and constraint == current:
            d[section][name] = minor + '.*'
            moved += 1
d['extra']['symfony']['require'] = minor + '.*'
d['minimum-stability'] = 'beta'
d['prefer-stable'] = True
with open(path + '.new', 'w') as out:
    json.dump(d, out, indent=4, ensure_ascii=False)
    out.write('\n')
import os
os.replace(path + '.new', path)
print(f'{moved} constraints moved to {minor}.*, and Flex\'s line; betas allowed, stable preferred')
EOF
}

summary() {
  [[ $# -eq 1 && -d "$1" ]] || usage
  local dir=$1 md=$1/summary.md step code count
  {
    echo "# RC lane"
    echo
    if [[ -s "$dir/versions.txt" ]]; then sed 's/^/- /' "$dir/versions.txt"; else echo "- versions: not read"; fi
    echo
    echo "| Step | Result |"
    echo "|---|---|"
    while IFS=$'\t' read -r step code; do
      [[ -n "$step" ]] || continue
      if [[ "$code" == 0 ]]; then echo "| $step | passed |"; else echo "| $step | failed (exit $code) |"; fi
    done < "$dir/steps.tsv"
    if grep -q $'^resolve\t[1-9]' "$dir/steps.tsv"; then
      echo
      echo "Composer could not install the next Symfony, so the deprecations, PHPStan and the suite were not run."
    fi
    echo
    echo "php-cs-fixer is not run here: it refuses a PHP it does not know yet."
    echo
    if [[ -f "$dir/deprecations.log" ]]; then
      count=$(grep -c '^ \* ' "$dir/deprecations.log")
      echo "## $count deprecations in the container"
      echo
      grep '^ \* ' "$dir/deprecations.log" | sed 's/^ \* /- /'
    else
      echo "## deprecations: not read"
    fi
    while IFS=$'\t' read -r step code; do
      [[ -n "$step" && "$code" != 0 && -f "$dir/$step.log" ]] || continue
      echo
      echo "## $step: the last lines"
      echo
      echo '```'
      tail -n 40 "$dir/$step.log"
      echo '```'
    done < "$dir/steps.tsv"
  } > "$md.new" && mv "$md.new" "$md"
  if [[ "${GITHUB_ACTIONS:-}" == true ]]; then
    while IFS=$'\t' read -r step code; do
      [[ -n "$step" && "$code" != 0 ]] && echo "::warning title=RC lane::$step failed (exit $code); the run's summary has its last lines"
    done < "$dir/steps.tsv"
  fi
  return 0
}

# step <name> <command…>: runs one step in the copy, its output in <out>/<name>.log, its exit in steps.tsv.
step() {
  local name=$1; shift
  ( cd "$work" && "$@" ) > "$out/$name.log" 2>&1
  local code=$?
  printf '%s\t%s\n' "$name" "$code" >> "$out/steps.tsv"
  echo "rc-lane: $name exit=$code"
  return "$code"
}

run() {
  [[ $# -eq 1 ]] || usage
  local tmp
  tmp=$(mkdir -p "${TMPDIR:-/tmp}" && cd "${TMPDIR:-/tmp}" && pwd -P) && [[ -n "$tmp" ]] \
    || { echo "rc-lane: TMPDIR (${TMPDIR:-/tmp}) cannot be made or read; the copy of api/ needs it" >&2; exit 2; }
  case "$tmp/" in "$(cd "$root" && pwd -P)/"*)
    echo "rc-lane: the copy of api/ is moved to the next Symfony, so it must sit outside the working tree; TMPDIR is $tmp" >&2; exit 2;;
  esac
  out=$(mkdir -p "$1" && cd "$1" && pwd)
  # The copy keeps the repository's layout: the API's tests read beside api/ (web's catalogues, docs/spec's vectors,
  # the gates), so every other top-level entry is a link to the tree's, read as the gate reads it; only api/ is copied.
  local base=$tmp/rc-lane entry
  work=$base/api
  rm -rf "$base" "$out"/*.log "$out/steps.tsv" "$out/summary.md" "$out/versions.txt"
  mkdir -p "$work"
  for entry in "$root"/* "$root"/.[!.]*; do
    [[ -e "$entry" && "${entry##*/}" != api ]] && ln -s "$entry" "$base/${entry##*/}"
  done
  tar -C "$root/api" --exclude=./vendor --exclude=./var -cf - . | tar -C "$work" -xf - || { echo "rc-lane: could not copy api/" >&2; exit 1; }
  : > "$out/steps.tsv"
  rewrite "$work/composer.json" "${RC_SYMFONY:-8.2}" || exit 1
  if step resolve composer update 'symfony/*' -W --no-interaction --no-progress; then
    { php -r 'echo "PHP ", PHP_VERSION, "\n";'; (cd "$work" && bin/console --version --no-ansi); } > "$out/versions.txt" 2>&1
    step container bin/console debug:container --deprecations --no-interaction --no-ansi
    cp "$out/container.log" "$out/deprecations.log"
    step static composer stan
    # The suite's database is its own, twes_test_rc (doctrine.yaml's suffix), made here, since only twes_test is made
    # when postgres starts (infra/postgres/init.sql); paratest then copies it per worker, as for the gate.
    if step database env TEST_TOKEN=_rc bin/console doctrine:database:create --connection=owner --if-not-exists --env=test --no-interaction; then
      step suite env TEST_TOKEN=_rc bash "$root/scripts/fiscal/fetch-xsd.sh" "$tmp/rc-lane-xsd" -- composer test
    fi
  fi
  summary "$out"
  echo "rc-lane: $out/summary.md"
}

case "${1:-}" in
  rewrite) shift; rewrite "$@";;
  summary) shift; summary "$@";;
  run) shift; run "$@";;
  *) usage;;
esac
