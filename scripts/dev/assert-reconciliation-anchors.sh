#!/usr/bin/env bash
#
# Assert that every `carried` row in the consolidation's reconciliation table still points at real
# text in the spec.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# WHY THIS EXISTS, AND WHY IT IS A SCRIPT RATHER THAN A SENTENCE.
#
# `docs/archive/plans/RECONCILIATION.md` is the safety artefact for archiving the five plan files: it
# claims that every dated ruling in them was either carried into `docs/SPEC.md`, superseded, turned
# into a process rule, or deliberately dropped. A disposition table written by the same author as the
# spec agrees with itself unless something checks it -- and when something finally did, it found a
# whole handful of rulings marked `carried` that had never been carried, in a table that reported
# 276 of 276 dispositioned. NO NUMERAL IS WRITTEN HERE: this comment said "TEN" and the same commit
# then found an eleventh, which is the count-beside-the-thing-it-counts defect landing inside the fix
# for a different one. `RECONCILIATION.md`'s header enumerates them; the list is the count.
#
# So each `carried` row records a LITERAL substring of `docs/SPEC.md` in its anchor column, and this
# script is what re-checks them. It was written because the table's own header said "re-run the
# assertion rather than trusting this sentence" while there was nothing on disk to re-run -- the
# check had lived in a throwaway command. That is the control-asserted-in-prose shape this repository
# records over and over, landing on the one artefact whose entire value is being checkable.
#
# NOT A GATE, and deliberately not in `composer gate`: it guards a historical reconciliation, not a
# runtime guarantee, and the tree it reads changes only when someone edits the spec's rulings. Run it
# after any change to `docs/SPEC.md` that touches a ruling, and after any edit to the table.
#
# WHAT IT CATCHES, PROVEN BY MUTANT, AND WHAT IT DELIBERATELY DOES NOT.
#
# Rewording a carried ruling away is caught: replacing "Money is never a float." with "Amounts use a
# numeric type." turns this red naming the row. [Verified 2026-09-02, then restored byte-for-byte.]
#
# EXTENDING a ruling is NOT caught, and that is by design rather than a hole -- an anchor is a
# SUBSTRING, so "Money is never a float." growing into "Money is never a floating-point number."
# still matches. [Verified: that mutant SURVIVED.] The question this script answers is "is the ruling
# still in the spec", not "is its wording frozen"; a spec whose sentences may never grow would be a
# worse artefact. Said out loud because a check whose limits are undocumented gets trusted for things
# it does not do -- and because a surviving mutant that nobody explains reads as a weak test.
#
# Exit 0 when every anchor resolves; exit 1 naming each that does not.

set -eEuo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT
readonly TABLE="$REPO_ROOT/docs/archive/plans/RECONCILIATION.md"
readonly SPEC="$REPO_ROOT/docs/SPEC.md"

# FAIL rather than skip when an input is missing. A reconciliation check that quietly passes because
# it could not find the table is worse than one that is openly owed -- the same call `gate:schema`
# and `gate:licences` make, and the same one the false-red in `test-gates.sh`'s library check did NOT
# make, which is why an unreadable file there reads exactly like a missing reference.
for required in "$TABLE" "$SPEC"; do
  if [[ ! -r "$required" ]]; then
    printf 'assert-reconciliation-anchors: FAIL -- cannot read %s\n' "$required" >&2
    exit 1
  fi
done

# The anchor is column 9 of a nine-column row; the disposition is column 8. Rows are matched on the
# leading row number so the vocabulary table, the tally and the prose are not read as data.
#
# Three normalisations, each for a reason the table's own generator creates:
#   - `\|` is a markdown-escaped pipe, restored BEFORE the split rather than after: it is not a column
#     separator, and unescaping it afterwards fixes the anchor while leaving every column shifted;
#   - the anchor is truncated to 95 characters, which is harmless because a PREFIX of a present
#     string is itself present;
#   - whitespace is collapsed on BOTH sides, because the spec hard-wraps and an anchor spanning a
#     line break would otherwise never match text that is really there.
python3 - "$TABLE" "$SPEC" <<'PYTHON'
import re
import sys

table, spec = (open(p, encoding='utf-8').read() for p in sys.argv[1:3])
normalised_spec = re.sub(r'\s+', ' ', spec)

checked = 0
missing = []
# `\|` IS A MARKDOWN-ESCAPED PIPE, NOT A COLUMN SEPARATOR, and `str.split('|')` does not know that. One row's
# entry text quotes `test \| tail && git commit`, so it split into 12 fields instead of 11 and every column from
# the entry rightwards was read one place late: its DISPOSITION cell resolved to entry prose. That row is
# `process-rule`, so it was skipped either way and nothing went wrong -- a silent misparse waiting for the first
# `carried` row to quote a pipe. Found by round 6's completeness lens, in the `awk` it used to derive the tally.
ESCAPED_PIPE = '\x00'
for line in table.splitlines():
    if not re.match(r'^\|\s*\d+\s*\|', line):
        continue
    columns = [c.replace(ESCAPED_PIPE, '|') for c in line.replace('\\|', ESCAPED_PIPE).split('|')]
    if len(columns) < 10:
        continue
    disposition = columns[8].strip().strip('`')
    if not disposition.startswith('carried'):
        continue
    anchor = re.sub(r'\s+', ' ', columns[9]).strip()
    # `carried-as-pointer` rows deliberately have no anchor: the ruling is in force and is NOT
    # restated in the spec, which is the point of that disposition.
    if not anchor:
        if disposition == 'carried-as-pointer':
            continue
        missing.append((columns[1].strip(), 'no anchor recorded'))
        continue
    checked += 1
    if anchor not in normalised_spec:
        missing.append((columns[1].strip(), anchor))

# ANTI-VACUITY. A run that checked nothing must not report success -- that is how a check survives a
# refactor that silently stops feeding it, and this repository has been bitten by exactly that shape
# more than once.
if checked == 0:
    print('assert-reconciliation-anchors: FAIL -- zero anchors checked; the table format changed '
          'under this script, so its OK would have meant nothing', file=sys.stderr)
    sys.exit(1)

if missing:
    print(f'assert-reconciliation-anchors: FAIL -- {len(missing)} anchor(s) no longer resolve in '
          f'docs/SPEC.md (of {checked} checked).', file=sys.stderr)
    for row, anchor in missing:
        print(f'  row {row}: {anchor}', file=sys.stderr)
    print('\nEither the ruling left the spec -- in which case the disposition is now wrong and the '
          'table must say so -- or the wording moved and the anchor needs updating to the new '
          'literal text. Do not "fix" this by deleting the anchor.', file=sys.stderr)
    sys.exit(1)

print(f'assert-reconciliation-anchors: counts — carried_checked={checked} missing=0')
print(f'assert-reconciliation-anchors: OK — every one of {checked} carried ruling(s) still resolves '
      f'to literal text in docs/SPEC.md.')
PYTHON
