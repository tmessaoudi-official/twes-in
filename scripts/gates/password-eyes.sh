#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every password field in the web app has its eye: the input carries a template reference and its form field holds
# `<app-password-toggle matSuffix [field]="that reference">`, so a person can check what they typed (docs/SPEC.md
# § 7, 2026-10-09 23:19). Reads templates and inline component templates from the index, specs excluded. A field
# counted below the floor means the reading broke, not that the fields went away: lower it with the change that
# removes one. Usage: password-eyes.sh [--root DIR] [--min N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
min=9
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --min) min=$2; shift 2 ;;
    *) echo "password-eyes: unknown argument $1" >&2; exit 2 ;;
  esac
done
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -0777 -ne '
  while (/<input\b[^>]*?\btype="password"[^>]*>/sg) {
    my ($tag, $at, $end) = ($&, $-[0], $+[0]);
    $checked++;
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    my ($ref) = $tag =~ /\s#(\w+)/;
    my $rest = substr($_, $end);
    $rest =~ s{</mat-form-field>.*}{}s;
    my $ok = 0;
    if (defined $ref) {
      while ($rest =~ /<app-password-toggle\b([^>]*)>/sg) {
        my $attrs = $1;
        $ok = 1 if $attrs =~ /\bmatSuffix\b/ && $attrs =~ /\[field\]="\Q$ref\E"/;
      }
    }
    print "WRONG $ARGV:$line\n" unless $ok;
  }
  END { print "CHECKED ", ($checked // 0), "\n" }
' "${files[@]}" /dev/null)
checked=$(sed -n 's/^CHECKED //p' <<<"$result")
mapfile -t wrong < <(sed -n 's/^WRONG //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'password-eyes: FAIL — %d password field(s) without their eye (fix: #ref on the input, then <app-password-toggle matSuffix [field]="ref" /> in its mat-form-field):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
if ((checked < min)); then
  printf 'password-eyes: FAIL — %d password field(s) found, fewer than the %d expected: the reading broke, or lower --min with the change that removed one\n' "$checked" "$min"
  exit 1
fi
printf 'password-eyes: OK — %d password fields have their eye\n' "$checked"
