#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a person just did (saved, added, recorded, invited) is said by a toast through the Feedback port, never by a
# status line a screen keeps under its title (docs/SPEC.md § 7, 2026-09-17, row 48): one voice, one place, and the
# line never lingers after the next edit. A role="status" element stays only where it names the page's own state,
# and each of those is listed below by its test id, so adding one is a decision. Reads templates and inline
# component templates from the index, specs excluded. Usage: outcomes-as-toasts.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# The page states: the session that ended, the sign-up request sent in place of its form, the slow request, an empty
# palette search, a record another person saved while it was being edited here, and a declared payment waiting for the
# operator's decision — that one is what the subscription IS until it is answered, days after the toast that said the
# declaration was recorded (2026-09-17) — and how much of the open record is unsaved, which is what the form IS until
# it is saved or discarded and the only thing on the page saying it was left half-filled (2026-09-19 23:18, row 73).
# `stock-drawing-unsaved` is that same count for the plan's rectangle form, which does not go through `RecordBar`:
# there a rectangle is dragged rather than typed, and nothing else on the screen says the plan was moved and not yet
# saved (2026-09-21 21:30, row 83). `stock-repeat-summary` is what the repeat panel WILL create — a count, a run of
# codes, or the reason it cannot be made — which changes under the person's fingers as they type and is the state the
# panel IS before anything is created; what the repeat DID is a toast like every other outcome (2026-09-21 23:20).
# `stock-map-not-saved` is the board's own word for the same state, put where the eye is while a piece is dragged,
# since the form holding the count stands beside the board rather than under it (2026-09-22 20:44, finding B).
# `product-scan-loading`, `product-scan-found` and `product-scan-none` are the scan card's whole content — looking the
# code up, the product it names, or that none does: what the card IS, arriving after it opened, and never the outcome
# of something the person did there (2026-09-23 01:10). `phone-loading`, `phone-ended`, `phone-pair-opening` and
# `phone-pair-status` are what a phone lent as a scanner IS, on the phone and in the computer's dialog: being linked,
# no longer linked, the link being made, waiting for the phone or connected to it (2026-09-23 13:07, slice 4).
page_states=' login-expired signup-sent activity-slow command-empty record-changed record-changes stock-drawing-unsaved stock-repeat-summary stock-map-not-saved subscription-waiting product-scan-loading product-scan-found product-scan-none phone-loading phone-ended phone-pair-opening phone-pair-status '
mapfile -t files < <(git -C "$root" ls-files -- 'web/src/app/*.html' 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
result=$(cd "$root" && perl -0777 -ne '
  while (/<[a-z][\w-]*\b[^>]*?\brole="status"[^>]*>/sg) {
    my ($tag, $at) = ($&, $-[0]);
    my $line = 1 + (substr($_, 0, $at) =~ tr/\n//);
    my $id = $tag =~ /\bdata-testid="([^"]+)"/ ? $1 : "(no test id)";
    print "STATUS $ARGV:$line $id\n";
  }
' "${files[@]}" /dev/null)
checked=0; wrong=()
while IFS= read -r entry; do
  [[ -z "$entry" ]] && continue
  checked=$((checked + 1))
  id=${entry##* }
  [[ "$page_states" == *" $id "* ]] || wrong+=("$entry")
done < <(sed -n 's/^STATUS //p' <<<"$result")
if ((${#wrong[@]})); then
  printf 'outcomes-as-toasts: FAIL — %d status line(s) that are not a page state (fix: feedback.success(key) from the component, or list a real page state in this gate):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
printf 'outcomes-as-toasts: OK — %d status lines, all page states\n' "$checked"
