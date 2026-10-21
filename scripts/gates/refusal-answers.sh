#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A failure an interceptor has said in words (the demo's refusal, a write still being made or whose answer was lost) is
# not said again by the screen: `saidAlready` reads it under the feature's refusal, which keeps the API's answer it was
# built from through `refusedFor(answer, new XRefused(…))`. A refusal built without it hides that answer, and its screen
# says the failure a second time, or something else. Every `new …Refused(` in web/src/app is handed to `refusedFor`, or
# its line or the one above says why it has no answer: `// refusal-answer: none, <why>`.
# Usage: refusal-answers.sh [--root DIR] [--floor N]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
floor=90
while (($#)); do
  case $1 in
    --root) root=$2; shift 2 ;;
    --floor) floor=$2; shift 2 ;;
    *) echo "refusal-answers: unknown argument $1"; exit 2 ;;
  esac
done
app="$root/web/src/app"
if [[ ! -d "$app" ]]; then echo "refusal-answers: FAIL — found no web/src/app"; exit 1; fi

mapfile -t files < <(grep -rlE --include='*.ts' 'new [A-Z][A-Za-z]*Refused\(' "$app" | grep -vE '\.spec\.ts$|/shared/testing/' | sort)
# perl reads each file whole, so a construction written over several lines is still seen with what wraps it.
report=$(perl -0777 -ne '
  my $text = $_;
  while ($text =~ /new [A-Z][A-Za-z]*Refused\(/g) {
    my $at = $-[0];
    my $line = 1 + (substr($text, 0, $at) =~ tr/\n//);
    my $before = substr($text, 0, $at);
    if ($before =~ /refusedFor\(\s*[\w.]+\s*,\s*\z/) { print "wrapped\n"; next; }
    my @lines = split /\n/, $text, -1;
    my $here = $lines[$line - 1] // "";
    my $above = $line > 1 ? $lines[$line - 2] : "";
    if ("$here\n$above" =~ m{//[ \t]*refusal-answer:[ \t]*\S}) { print "excused\n"; next; }
    (my $code = $here) =~ s/^\s+//;
    print "bare $ARGV:$line: ", substr($code, 0, 110), "\n";
  }
' "${files[@]}" 2>&1)

wrapped=$(grep -c '^wrapped$' <<<"$report")
problems=$(grep '^bare ' <<<"$report" | sed "s#^bare $root/##")
if [[ -n "$problems" ]]; then
  printf 'refusal-answers: FAIL — a refusal built without the answer it came from, so saidAlready cannot read it (hand it to refusedFor(answer, …), or say // refusal-answer: none, <why>):\n'
  printf '  %s\n' "$problems"
  exit 1
fi
if ((wrapped < floor)); then
  printf 'refusal-answers: FAIL — only %d refusals handed to refusedFor, below the floor of %d: the search no longer finds the adapters\n' "$wrapped" "$floor"
  exit 1
fi
printf 'refusal-answers: OK — %d refusals keep the answer they were built from\n' "$wrapped"
