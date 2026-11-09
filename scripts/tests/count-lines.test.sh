#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/stats/count-lines.py
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# The report with every run of spaces made one, so a row reads « PHP 6 4 2 2 2 » whatever its column widths.
count() { local out; out=$(python3 -I "$SCRIPT" --repo "$d" "$@" 2>&1); local s=$?; printf '%s' "$out" | tr -s ' ' > "$TMPDIR/out"; return $s; }
commit() { git -C "$d" add -A && git -C "$d" -c user.name=T -c user.email=t@example.com commit -qm "$1"; }
echo "count-lines"

d=$TMPDIR/repo; mkdir -p "$d"; git -C "$d" init -q
mkdir -p "$d/api/src" "$d/api/tests" "$d/web/src/app" "$d/web/public/i18n" "$d/docs" "$d/scripts"
# Hand-counted: every line git counts, « code » the lines neither blank nor a comment.
printf '<?php\n// a comment\n\nfinal class A {}\n' > "$d/api/src/A.php"              # 4 lines, 2 code
printf '<?php\n// old\n' > "$d/api/src/Old.php"                                       # 2 lines, 1 code
printf '<?php\nfinal class ATest {}\n' > "$d/api/tests/ATest.php"                     # test: 2 lines, 2 code
printf 'export const x = 1;\n/* note */\nexport const y = 2;' > "$d/web/src/app/x.ts"  # no last newline: 3 lines, 2 code
printf "it('x', () => {});\n" > "$d/web/src/app/x.spec.ts"                            # test: 1 line, 1 code
printf '<p>x</p>\n<!-- c -->\n' > "$d/web/src/app/x.html"                             # 2 lines, 1 code
printf '{\n  "a": "b"\n}\n' > "$d/web/public/i18n/fr.json"                            # texts: 3 lines
printf '# A\n\ntext\n' > "$d/docs/a.md"                                               # docs: 3 lines
printf 'services: {}\n' > "$d/compose.yaml"                                           # config: 1 line
printf 'all:\n\ttrue\n' > "$d/Makefile"                                               # 2 lines, 2 code
printf '#!/usr/bin/env bash\necho hi\n' > "$d/scripts/x.sh"                           # 2 lines, 1 code
printf 'one\ntwo\n' > "$d/notes.txt"                                                  # another kind: 2 lines
printf 'caf\351\nbar\n' > "$d/latin.txt"                                              # not UTF-8, no NUL: 2 lines
printf 'a\nb\nc\nd\ne\n' > "$d/api/composer.lock"                                     # left out: a lock file
printf 'MIT\n\ntext\n' > "$d/LICENSE"                                                 # left out: a licence text
printf '\211PNG\000\001\n\002\n' > "$d/web/public/logo.png"                           # binary: a NUL, no lines
commit first; first=$(git -C "$d" rev-parse HEAD)

# The second commit: an edit, a deletion, a new file and a rename INTO an excluded path, which leaves the count.
printf '<?php\n// a comment\n\nfinal class A\n{\n}\n' > "$d/api/src/A.php"             # +3 -1: 6 lines, 4 code
git -C "$d" rm -q notes.txt                                                          # -2
printf 'export const z = 3;\n' > "$d/web/src/app/y.ts"                                # +1
mkdir -p "$d/web/src/third-party"; git -C "$d" mv api/src/Old.php web/src/third-party/Old.php  # -2
commit second
# Neither a working-tree edit nor an untracked file is counted: the tool reads the commit.
printf 'export const w = 4;\n' >> "$d/web/src/app/y.ts"; printf 'x\n' > "$d/untracked.ts"

count --out "$TMPDIR/r.txt"; s=$?
out=$(cat "$TMPDIR/out")
check "the API's PHP: app lines, app code, test lines, test code, files" $s 0 "$out" "PHP 6 4 2 2 2"
check "the web's TypeScript, a file without its last newline counted as git does" $s 0 "$out" "TypeScript 4 3 1 1 3"
check "a comment in HTML is no code" $s 0 "$out" "HTML 2 1 0 0 1"
check "a shell script's shebang reads as a comment" $s 0 "$out" "Shell 2 1 0 0 1"
check "the Makefile is its own kind" $s 0 "$out" "Makefile 2 2 0 0 1"
check "texts, docs and config outside the code total" $s 0 "$out" "screen, mail and document texts (fr/en/ar) 3 lines 1 files"
check "the standing total: code, tests, texts, docs and config" $s 0 "$out" "TOTAL 26 lines 11 files"
check "other kinds of ours: the text file that is not UTF-8, no lock, licence or binary" $s 0 "$out" "other kinds of ours 2 lines 1 files"
check "the history, the rename into an excluded path read as a removal" $s 0 "$out" "lines added 33"
check "  ... and what it removed" $s 0 "$out" "lines removed 5"
check "what stands equals what the history added less what it removed" $s 0 "$out" "check: what stands 28 (26 + other kinds 2) = net 28 (33 added - 5 removed)"
check "the commits counted" $s 0 "$out" "2 commits"
check "the report is written where --out says, with how to update it" $s 0 "$(tr -s ' ' < "$TMPDIR/r.txt")" "make count-lines"
check "  ... and the same numbers" $s 0 "$(tr -s ' ' < "$TMPDIR/r.txt")" "TOTAL 26 lines 11 files"

count --rev "$first" --out "$TMPDIR/r1.txt"; s=$?
out=$(cat "$TMPDIR/out")
check "another revision is counted as it was then" $s 0 "$out" "check: what stands 29 (25 + other kinds 4) = net 29 (29 added - 0 removed)"
check "  ... its old file still in its table" $s 0 "$out" "PHP 6 3 2 2 3"

# A merge whose own commit changes a line: the history reads no change in a merge, so the halves differ, and say so.
e=$TMPDIR/merge; mkdir -p "$e"; git -C "$e" init -q -b main; d=$e
printf 'a\n' > "$e/f.ts"; commit base
git -C "$e" checkout -q -b side; printf 'b\n' > "$e/g.ts"; commit side
git -C "$e" checkout -q main; printf 'c\n' > "$e/h.ts"; commit main
git -C "$e" -c user.name=T -c user.email=t@example.com merge -q --no-ff --no-commit side >/dev/null 2>&1
printf 'a\nadded in the merge\n' > "$e/f.ts"; commit merge
count --out "$TMPDIR/r2.txt"; s=$?
check "halves that differ fail, naming why they can" $s 1 "$(cat "$TMPDIR/out")" "differ by 1"
check "  ... the report written all the same" 0 0 "$(cat "$TMPDIR/r2.txt" 2>/dev/null)" "differ by 1"

count --rev no-such-rev --out "$TMPDIR/r3.txt"; s=$?
check "a revision that does not exist is refused" $s 2 "$(cat "$TMPDIR/out")" "no-such-rev"

echo "$pass passed, $fail failed"
((fail == 0))
