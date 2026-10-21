#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a request did beyond its subject is said in its answer (X-Effects) and said on screen by the sayer of its kind
# (EFFECT_SAYERS). Checked both ways, so neither half is silent: a kind the API says that no registered sayer names
# reaches nobody (the web reports it as an error, too late), and a kind a sayer waits for that the API never says is a
# toast nobody will see. The API says a kind through a constant of the class that says it (`new Effect(self::X`), the
# web through a constant of the sayer's own file named in its `kinds`; every sayer is registered in app.config.ts.
# Reads the index, tests and specs excluded. Usage: effect-kinds.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
status=0

mapfile -t php < <(git -C "$root" ls-files -- 'api/src/*.php')
api_kinds=()
loose=()
for file in "${php[@]}"; do
  grep -q 'new Effect(' "$root/$file" || continue
  while IFS= read -r line; do
    case "$line" in
      kind\ *) api_kinds+=("${line#kind }") ;;
      loose\ *) loose+=("$file: ${line#loose }") ;;
    esac
  done < <(perl -0777 -ne '
    my %c; $c{$1} = $2 while /const\s+string\s+(\w+)\s*=\s*\x27([^\x27]+)\x27/g;
    while (/new\s+Effect\(\s*([^,)]+)/g) {
      my $a = $1; $a =~ s/\s+$//;
      if ($a =~ /^self::(\w+)$/ && exists $c{$1}) { print "kind $c{$1}\n" } else { print "loose $a\n" }
    }' "$root/$file")
done
if ((${#loose[@]})); then
  echo "effect-kinds: FAIL — an effect said with no constant of its class, whose kind nothing can read:"
  printf '  %s\n' "${loose[@]}"
  status=1
fi

mapfile -t ts < <(git -C "$root" ls-files -- 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
web_kinds=()
sayers=()
for file in "${ts[@]}"; do
  grep -q 'implements EffectSayer' "$root/$file" || continue
  while IFS= read -r line; do
    case "$line" in
      kind\ *) web_kinds+=("${line#kind }") ;;
      sayer\ *) sayers+=("${line#sayer }") ;;
      loose\ *) loose+=("$file: ${line#loose }"); status=1
        echo "effect-kinds: FAIL — $file names a kind that is not a constant of its own file: ${line#loose }" ;;
    esac
  done < <(perl -0777 -ne '
    my %c; $c{$1} = $2 while /export\s+const\s+(\w+)\s*=\s*\x27([^\x27]+)\x27/g;
    print "sayer $1\n" while /class\s+(\w+)\s+implements\s+EffectSayer/g;
    while (/\bkinds\s*=\s*\[([^\]]*)\]/g) {
      for my $n (grep { length } map { s/^\s+|\s+$//gr } split /,/, $1) {
        if (exists $c{$n}) { print "kind $c{$n}\n" } else { print "loose $n\n" }
      }
    }' "$root/$file")
done

if ((${#api_kinds[@]} == 0 || ${#web_kinds[@]} == 0)); then
  echo "effect-kinds: FAIL — no effect read (API kinds: ${#api_kinds[@]}, sayers' kinds: ${#web_kinds[@]}); the conventions above changed"
  exit 1
fi

config="$root/web/src/app/app.config.ts"
unregistered=()
for sayer in "${sayers[@]}"; do
  grep -Eq "provide: EFFECT_SAYERS, useExisting: ${sayer}\b" "$config" 2>/dev/null || unregistered+=("$sayer")
done
if ((${#unregistered[@]})); then
  echo "effect-kinds: FAIL — a sayer app.config.ts does not register as EFFECT_SAYERS, so it says nothing:"
  printf '  %s\n' "${unregistered[@]}"
  status=1
fi

mapfile -t said < <(printf '%s\n' "${api_kinds[@]}" | sort -u)
mapfile -t heard < <(printf '%s\n' "${web_kinds[@]}" | sort -u)
mapfile -t unheard < <(comm -23 <(printf '%s\n' "${said[@]}") <(printf '%s\n' "${heard[@]}"))
mapfile -t unsaid < <(comm -13 <(printf '%s\n' "${said[@]}") <(printf '%s\n' "${heard[@]}"))
if ((${#unheard[@]})); then
  echo "effect-kinds: FAIL — said by the API, named by no sayer:"
  printf '  %s\n' "${unheard[@]}"
  status=1
fi
if ((${#unsaid[@]})); then
  echo "effect-kinds: FAIL — named by a sayer, never said by the API:"
  printf '  %s\n' "${unsaid[@]}"
  status=1
fi
((status)) && exit 1
printf 'effect-kinds: OK — %d kinds, each said by the API and by a registered sayer\n' "${#said[@]}"
