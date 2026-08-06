#!/usr/bin/env bash
# PostToolUse hook: lint the ONE file Claude just wrote or edited, with the same tool the
# quality gate uses. Exit 2 feeds the tool's own output back to Claude, which fixes it in
# the same turn instead of discovering it at `composer gate` twenty edits later.
#
# ADAPTED, not ported. pdfturbo wires `oxlint-on-write.sh` and phorj/stack wire their own
# equivalents; the shape travels (jq the payload from stdin, early `exit 0` guard, `exit 2`
# with stderr) and the CONTENT does not, because twes-in's tiers are PHP and shell. Four
# decisions were made here rather than inherited, and each has its number:
#
#   * PHP-CS-Fixer runs in CHECK mode over one path. Measured 0.33s for a single file, which
#     is cheap enough for every write.
#   * PHPStan is DELIBERATELY ABSENT. Measured 11.3s for a single file — it loads the whole
#     `src/` symbol table whatever you point it at, so there is no per-file mode to exploit.
#     `composer gate:static` covers it and a hook that costs eleven seconds per edit would
#     be turned off within an hour. Saying the number is the point: this is a measurement,
#     not a preference.
#   * NOTHING IS AUTO-FORMATTED. `--dry-run`/check only, never `fix`. In this repository a
#     doc comment's POSITION is part of its truth — `scripts/gates/no-orphaned-docblocks.php`
#     exists because three successive certification rounds filed a stranded one — so a hook
#     that silently rewrites what Claude just wrote is a worse trade than one that reports.
#   * `php -l` runs FIRST and short-circuits. A parse error makes every style complaint
#     downstream noise, and php-cs-fixer's own message for an unparseable file does not say
#     "syntax error" clearly enough to act on.
#
# The tools are the PINNED PHARS in `api/tools/bin/`, not `vendor/bin/`, for the reason
# CLAUDE.md § "Quality gate" gives: a phar runs in a checkout with no vendor tree at all. If
# the phar is absent (nobody has run `scripts/dev/fetch-tools.sh` yet) this hook is SILENT
# rather than failing — a bootstrap hook that blocks every write in a fresh clone is the one
# failure mode that would get the whole mechanism deleted.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# EVERY SUPPRESSION AND FALLBACK IN THIS FILE, ACCOUNTED FOR (the anti-bandaid gate applies to a
# hook as much as to a fix, and a hook is where an unexplained `|| true` is least likely to be
# noticed — nobody reads a hook that is working):
#   * `jq … 2>/dev/null` — a payload shape without `.tool_input` makes jq print a diagnostic, and
#     this hook's stderr is the channel Claude reads findings from. An unknown shape is a
#     LEGITIMATE condition, not a defect, so the guard below handles it and the diagnostic is
#     kept out of a channel reserved for real findings. Exercised by the empty-`file_path` case.
#   * `git rev-parse --show-toplevel 2>/dev/null` — outside a repository git prints `fatal: not a
#     git repository`. Same reasoning; the `-n "$repo"` guard is the handler.
#   * `|| exit 0` throughout — these are GUARDS, not suppression: a hook must never block a write
#     for an environmental reason. Every one is paired with a test case asserting exit 0.
# There is no `|| true` in this file, and no retry, timeout or default value.
#
# NOTE ON THE IDENTIFIER'S POSITION, three lines above rather than here at the end of the header
# where house style puts it: `spdx-headers.sh` bounds the search to the first 40 lines, and adding
# the accounting block pushed it to line 45. `gates-on-write.sh` — registered minutes earlier —
# reported it on the very edit that caused it, which is the first live evidence that these hooks
# do what they claim.
set -uo pipefail

input="$(cat)"

# `.tool_input.file_path` is Write's and Edit's field. `// empty` rather than a default, so a
# tool shape we do not know about produces an empty string and falls out of the guard below
# instead of linting a path that reads as a literal "null".
file="$(jq -r '.tool_input.file_path // empty' <<<"$input" 2>/dev/null)"
[[ -n "$file" && -f "$file" ]] || exit 0

repo="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null)}"
[[ -n "$repo" && -d "$repo" ]] || exit 0
cd "$repo" || exit 0

# Absolute → repo-relative, so the skip patterns below can be anchored. A file outside the
# repository is not ours to lint.
abs="$(cd "$(dirname "$file")" && pwd)/$(basename "$file")" || exit 0
case "$abs" in "$repo"/*) rel="${abs#"$repo"/}" ;; *) exit 0 ;; esac

# Trees we never author. `vendor/`, `node_modules/` and the pub cache are dependencies;
# `build/`, `var/` and `.dart_tool/` are generated. Linting any of them would report defects
# nobody in this repository can fix, which is the fastest way to train a reader to ignore a
# hook.
case "$rel" in
  */vendor/*|vendor/*|*/node_modules/*|node_modules/*|*/build/*|build/*|*/var/*|var/*|*/.dart_tool/*) exit 0 ;;
esac

report() {
  printf '%s\n' "$1" >&2
  printf '%s\n' "$2" >&2
  exit 2
}

case "$rel" in
  *.php)
    if ! out="$(php -l "$abs" 2>&1)"; then
      report "PHP syntax error in $rel (php -l):" "$out"
    fi
    fixer="$repo/api/tools/bin/php-cs-fixer.phar"
    [[ -f "$fixer" ]] || exit 0
    # RUN FROM `api/`, NOT FROM THE REPO ROOT, and this is not cosmetic: php-cs-fixer resolves
    # `composer.json` relative to the CURRENT DIRECTORY in order to derive the project's
    # minimum PHP version, so from the root it fails with `Failed to read file
    # "composer.json"` and every clean file reads as a violation. Caught by this hook's own
    # test suite on the first run, which is the whole reason that suite exists.
    #
    # The path is passed ABSOLUTE so one invocation covers both of the config finder's roots:
    # it scans `api/src`, `api/tests` AND `scripts/gates` — the gates are PHP we author too —
    # and a repo-relative path would only resolve for the first two.
    #
    # `--path-mode=intersection` confines the run to this one path while still honouring
    # `.php-cs-fixer.dist.php`'s own finder and rule set; without it the config's finder wins
    # and the whole tree is scanned, which is `gate:style`'s job. A path OUTSIDE the finder's
    # roots intersects to nothing and exits 0, so no scope test is needed here — which is
    # deliberate, since a scope test would be a second copy of the config's finder.
    #
    # NO `--dry-run`: php-cs-fixer 3.x REJECTS it on the `check` subcommand with `The
    # "--dry-run" option does not exist.` — `check` is already the non-mutating verb, and the
    # flag only exists on `fix`. Writing it out for emphasis therefore made every clean file
    # report a violation, which this hook's own test suite caught on the second run. Noted
    # rather than silently dropped, because the instinct to add it back is the obvious one.
    if ! out="$(cd "$repo/api" && php "$fixer" check --path-mode=intersection "$abs" 2>&1)"; then
      report "php-cs-fixer reports style violations in $rel:" "$out"
    fi
    ;;
  *.sh)
    if ! out="$(bash -n "$rel" 2>&1)"; then
      report "Shell syntax error in $rel (bash -n):" "$out"
    fi
    ;;
  *)
    # Every other type is silent BY DECISION, and the omissions are named so that adding one
    # is a deliberate edit here rather than a discovery:
    #   * `.ts`/`.html`/`.scss` — the admin tier's lint is `ng lint`, which has no per-file
    #     mode worth the startup cost, and `admin/` holds no domain code yet.
    #   * `.dart` — `flutter analyze` is whole-project and measured in tens of seconds.
    #   * `.md` — prose. `/cross-check` is the tool for a document, not a linter.
    exit 0
    ;;
esac

exit 0
