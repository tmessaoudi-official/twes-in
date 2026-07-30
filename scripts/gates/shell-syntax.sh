#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# GATE: every tracked shell script parses.
#
# WHY THIS EXISTS NOW rather than with infra/ in Wave 12, which is where the quality-gate table used to
# defer it. Certification round 11 pointed out that ten shell scripts are already tracked and already pass
# `bash -n` — including the other gates themselves and provision-test-database.sh, which is the fixture the
# whole tenancy proof runs on. Deferring the check to the wave that adds MORE scripts left the ones that
# exist unchecked, and a syntax error in a gate is the worst possible place for one: the gate stops
# detecting and its non-zero exit reads as a detection. That is the exact confusion CLAUDE.md § Gotchas
# records for test-gates.sh ("a crash and a detection are indistinguishable otherwise").
#
# DISCOVERY IS FROM git, NOT A HAND-WRITTEN LIST. A list is what goes stale the day somebody adds a script,
# and then this gate looks exhaustive while covering a subset — the failure mode this repository has now
# recorded four times. `--cached --others --exclude-standard` is the same triple spdx-headers.sh uses, and
# the reasons are the same: UNTRACKED files are included, because a script somebody has just written and
# not yet committed is exactly when a syntax check is worth having, while `--exclude-standard` keeps
# gitignored build output out. A shell script without a .sh extension would be missed by a name match, so
# the shebang sweep below covers extensionless executables too — this repo has none today, which is
# precisely why a name-only sweep would look complete.
#
# `bash -n` only parses. It does not run, does not source, and cannot see an undefined variable or a
# misspelled command — so this is a floor, not a review. What breaks without it is silent: a script with a
# syntax error fails at the moment it is invoked, which for a gate or a provisioning script is deep inside
# somebody else's debugging session.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

checked=0
failed=0

# Both arms, unioned and de-duplicated: *.sh by name, plus any file whose first line names a shell.
while IFS= read -r file; do
    [ -n "$file" ] || continue

    if ! bash -n "$file" 2>&1; then
        echo "shell-syntax: FAIL — ${file} does not parse"
        failed=$((failed + 1))
    fi

    checked=$((checked + 1))
done < <(
    {
        git ls-files --cached --others --exclude-standard '*.sh'
        git grep -l -I --untracked -e '^#!.*\(ba\)\?sh' -- ':!*.sh' 2>/dev/null || true
    } | sort -u
)

# ANTI-VACUITY, and not a formality: `git ls-files` in a non-repository, or run from the wrong directory,
# returns nothing and every loop above is skipped. A gate that inspected zero files must not print OK — the
# same vacuous-pass this project has now found in test-gates.sh, in the tenancy fixture, and in the
# integration suite that reported OK after skipping all 62 of its cases.
if [ "$checked" -eq 0 ]; then
    echo "shell-syntax: FAIL — no shell script was inspected, so this gate would pass vacuously. Run it"
    echo "       from the repository, and check that 'git ls-files' works here."
    exit 1
fi

if [ "$failed" -gt 0 ]; then
    echo "counts — inspected=${checked} failed=${failed}"
    exit 1
fi

echo "shell-syntax: OK — ${checked} shell script(s) parse."
echo "counts — inspected=${checked} failed=0"
