#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a person just did (saved, added, recorded, invited) is said by a toast through the Feedback port, never by a
# status line a screen keeps under its title: one voice, one place, and the
# line never lingers after the next edit. A role="status" element stays only where it names the page's own state,
# and each of those is listed below by its test id, so adding one is a decision. Reads templates and inline
# component templates from the index, specs excluded. Usage: outcomes-as-toasts.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# The page states: the session that ended, the sign-up or password-link request sent, and a password chosen, in place of its form, the slow request, an empty
# palette search, a record another person saved while it was being edited here, and a declared payment waiting for the
# operator's decision — that one is what the subscription IS until it is answered, days after the toast that said the
# declaration was recorded — and how much of the open record is unsaved, which is what the form IS until
# it is saved or discarded and the only thing on the page saying it was left half-filled.
# `stock-drawing-unsaved` is that same count for the plan's rectangle form, which does not go through `RecordBar`:
# there a rectangle is dragged rather than typed, and nothing else on the screen says the plan was moved and not yet
# saved. `stock-repeat-summary` is what the repeat panel WILL create — a count, a run of
# codes, or the reason it cannot be made — which changes under the person's fingers as they type and is the state the
# panel IS before anything is created; what the repeat DID is a toast like every other outcome.
# `stock-map-not-saved` is the board's own word for the same state, put where the eye is while a piece is dragged,
# since the form holding the count stands beside the board rather than under it.
# `product-scan-loading`, `product-scan-found` and `product-scan-none` are the scan card's whole content — looking the
# code up, the product it names, or that none does: what the card IS, arriving after it opened, and never the outcome
# of something the person did there. `phone-loading`, `phone-ended`, `phone-pair-opening` and
# `phone-pair-status` are what a phone lent as a scanner IS, on the phone and in the computer's dialog: being linked,
# no longer linked, the link being made, waiting for the phone or connected to it.
# `placement-status` is how much of a delivery shared over several places is still unplaced: it changes under the
# person's fingers with every quantity typed and is what the rows ARE until they add up, while what saving did is a toast.
# `documents-preview-loading` and `documents-preview-message` are what a design's preview IS: the picture on its way, or
# why there is none (no invoice yet, invoices not readable, a failure); what saving the design did is a toast.
# `new-version` is that the page runs an older build than the server holds: true until the page is reloaded, whatever
# the person does meanwhile, and never the outcome of something they did.
# `stock-volume-missing` and `stock-volume-failed` say the stock map's volume cannot be shown here, which stays true for as long
# as the volume is open, and the plan beside it shows the same floor.
# `stock-map-found` (and `stock-map-note`, a delivery note's lines looked for together) is what the map's search found — where the product is, on which floors, what lies undrawn — and
# what the board IS while the search stands, read again when stock moves; choosing the product was the person's act,
# and its outcome is the plan lit, not a moment to toast.
# `phone-photo-sending` is a photo on its way from the phone, what the phone IS until the answer; that it went is a toast.
# `stop-mail-done` is what the public stop page shows in place of its form once the link has stopped the mail, as
# `reset-done` does for a password chosen: there is no form left to say it under.
# `list-loading` is what a list IS until its first answer arrives, said in place of « vide », which a shop owner reads as
# lost data; it is never the outcome of something the person did there.
# `erasure-banner` is that an erasure of the company's data can still be undone: true on every page until it is undone or
# its 24 hours end, whoever made it and whatever the owner does meanwhile; that it was made or undone is a toast.
# `erasure-waiting` is the same state seen from « Effacer des données », said where the next erasure would be chosen.
page_states=' list-loading phone-photo-sending stop-mail-done new-version login-expired signup-sent forgot-sent reset-done activity-slow command-empty record-changed record-changes stock-drawing-unsaved stock-repeat-summary stock-map-not-saved subscription-waiting product-scan-loading product-scan-found product-scan-none phone-loading phone-ended phone-pair-opening phone-pair-status placement-status documents-preview-loading documents-preview-message stock-map-found stock-map-note stock-volume-missing stock-volume-failed erasure-banner erasure-waiting '
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
