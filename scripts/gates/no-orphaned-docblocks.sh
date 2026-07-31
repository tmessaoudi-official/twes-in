#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# NO ORPHANED DOCBLOCKS: a `*/` immediately followed by a `/**` means the first block documents nothing.
#
# WHY THIS EXISTS, and why it is a gate rather than a habit. A docblock's POSITION is part of its truth: PHP
# attaches it to the next declaration, so when two appear in a row the first one silently stops describing
# anything. Nothing else in this repository can see that. `php -l` is happy — comments are comments;
# `php-cs-fixer` is happy — it reformats docblocks but does not ask what they attach to [Verified: it reported
# `Found 0 of 69 files that can be fixed` over a tree carrying four of them]; and PHPStan would only catch the
# subset where the orphaning also deletes a `@param`/`@return` generic, which is a side effect rather than the
# defect.
#
# It has now been filed by a certification round THREE TIMES, and the second closure is why this exists rather
# than a fourth manual sweep:
#
#   - round 16 found two, and fixed those two;
#   - round 17 found that the round-16 fix had itself created one — the corrected `superuserConnection()`
#     docblock was moved to sit above a different test's docblock, so the correction was orphaned WHILE the
#     superseded text it replaced stayed attached to the method. Both were then wrong at once;
#   - and a sweep with this detector over every TRACKED php file found four more that round 17's reviewer had
#     not seen, because it scoped its sweep to `api/` and two were in `scripts/gates/` — including one in the
#     licensing gate that had lost a `@return array<string, list<string>>`.
#
# That is the shape `CLAUDE.md` § Gotchas records repeatedly: a rule enforced by memory is not a rule. So the
# rule is enforced here, over `git ls-files` rather than a directory walk — the same choice `shell-syntax.sh`
# makes, and for the same reason round 17 had to make it in `test-gates.sh`: a parallel review round puts full
# copies of this repository at `.claude/worktrees/<agent>/`, INSIDE the tree, so a recursive walk reads several
# repositories and reports findings that belong to none of them.
#
# DELIBERATELY NOT A PHP TOKENIZER PASS. The two layer gates use `token_get_all()` because they must not match a
# `use` inside a string or a comment. Here the subject matter IS the comments, and the pattern is purely
# positional — two adjacent block-comment delimiters at the start of their lines. A tokenizer would also see
# `T_DOC_COMMENT` tokens, but it would still be answering the same positional question with more machinery.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# The extensions inspected. PHP is the only language here whose doc comments BIND to a declaration, which is what
# makes an orphan a defect rather than an oddity: a stray block comment in a shell script or a JSON-adjacent file
# documents nothing in the first place, so there is nothing for it to stop documenting.
EXTENSIONS=(php)

if [[ "${1:-}" == "--dump-rules" ]]; then
  # The meta-suite generates one case per extension from this, so deleting an entry deletes its own case.
  printf 'extension\t%s\n' "${EXTENSIONS[@]}"
  exit 0
fi

cd "$REPO_ROOT"

patterns=()
for extension in "${EXTENSIONS[@]}"; do
  patterns+=("*.${extension}")
done

# `-z` and `xargs -0`, so a path containing a space or a newline cannot split into two paths and silently skip
# the file it named.
mapfile -t -d '' files < <(git ls-files -z -- "${patterns[@]}")

inspected=0
violations=()

for file in "${files[@]}"; do
  inspected=$((inspected + 1))

  # `prev` holds the previous line. A closing `*/` alone on its line, immediately followed by an opening `/**`
  # alone on its line, is the signature. Both must be alone: `*/ /**` on one line is not this defect, and a
  # `/**` that opens with text after it is still an opening delimiter but does not arise in this codebase's
  # style, so matching the strict form keeps the false-positive rate at zero.
  while IFS= read -r hit; do
    violations+=("${file}:${hit}")
  done < <(awk '
    prev ~ /^[[:space:]]*\*\/[[:space:]]*$/ && $0 ~ /^[[:space:]]*\/\*\*[[:space:]]*$/ { print NR }
    { prev = $0 }
  ' "$file")
done

# Printed UNCONDITIONALLY, before the verdict, so the meta-suite can prove the gate actually looked at
# something. A gate that inspected zero files reports "OK" indistinguishably from one that inspected the tree,
# and `CLAUDE.md` § Gotchas records a fixture that omitted its input making every assertion about it vacuous
# while the suite stayed green.
printf 'counts — inspected=%d violations=%d\n' "$inspected" "${#violations[@]}"

if [[ $inspected -eq 0 ]]; then
  printf 'no-orphaned-docblocks: FAIL — inspected NO files, so this gate proved nothing.\n' >&2
  printf '  Expected tracked files matching: %s\n' "${patterns[*]}" >&2
  exit 1
fi

if [[ ${#violations[@]} -gt 0 ]]; then
  printf 'no-orphaned-docblocks: FAIL — a docblock is followed immediately by another, so the first documents nothing.\n' >&2
  for violation in "${violations[@]}"; do
    printf '  %s — the docblock ending here is followed by another docblock, so PHP attaches only the SECOND\n' "$violation" >&2
    printf '     one to the declaration below. Move the stranded block to the declaration it describes, or\n' >&2
    printf '     merge the two. Do not leave it: a docblock that describes nothing is read as though it did.\n' >&2
  done
  exit 1
fi

printf 'no-orphaned-docblocks: OK — %d file(s) carry no stranded docblock.\n' "$inspected"
