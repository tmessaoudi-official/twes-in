#!/usr/bin/env bash
# twes-in Claude-container bootstrap — restores the developer's global reasoning framework into the
# EPHEMERAL remote container (a fresh ~/.claude every session), so the project CLAUDE.md's routing
# reference ("the global reasoning framework, ~/.claude/CLAUDE.md") resolves everywhere.
#
# THE REPO IS THE TRUTH: the three framework docs are copied UNCONDITIONALLY, every session.
#
# This replaced `cp -u`, and the header here used to justify that with a claim that is FALSE — it said
# `cp -u` "copies only when the repo copy is NEWER than the target, so a hand-edited (newer) ~/.claude
# file is never clobbered". `cp -u` copies when the SOURCE is newer, and a fresh `git clone` stamps
# every repo file with the clone time, so in this container the repo copy is ALWAYS newer and it
# clobbered unconditionally anyway. After a hand-edit of the target it silently did nothing instead.
# Neither behaviour was chosen; the timestamps decided, which is nondeterminism in the one hook that
# defines how every session reasons. Diagnosed in the `rent-watch` port and brought back here
# 2026-08-06, along with `test-install.sh`, which pins the contract.
#
# The one thing unconditional copying must NOT do is destroy a pre-existing global framework with no
# way back — so a target that predates this hook is snapshotted ONCE to `<name>.pre-bootstrap.bak`,
# and that snapshot is never written again. "Predates this hook" is inferred from the ABSENCE of the
# snapshot, so a later run cannot overwrite the original with our own copy; that ordering is the whole
# trick. Wired as a SessionStart hook in .claude/settings.json; safe to run by hand.
#
# SCOPE IS DELIBERATELY NARROW: this script copies three documentation files INTO ~/.claude and does
# nothing else. It must never copy anything OUT of ~/.claude into the repo — ~/.claude.json holds the
# oauth account, userID and machineID, and the working tree is one `git add -A` away from history.
# (The upstream port this was adapted from did exactly that; the block was removed here on purpose.)
#
# The repo-native skills (.claude/skills/*) and agents (.claude/agents/*) need NO install — Claude
# Code reads them in place from the clone.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
dest="${HOME}/.claude"

mkdir -p "$dest"

# install_doc <repo-source> <target-name>
install_doc() {
  local src="$1" name="$2"
  local target="$dest/$name"
  local backup="$target.pre-bootstrap.bak"

  if [[ -f "$target" && ! -e "$backup" ]] && ! cmp -s "$src" "$target"; then
    cp -p "$target" "$backup" 2>/dev/null \
      && printf 'claude-bootstrap: kept your previous %s as %s\n' "$name" "${backup##*/}" >&2
  fi

  cp -f "$src" "$target"
}

install_doc "$here/CLAUDE-global.md" CLAUDE.md
install_doc "$here/THINKING.md"      THINKING.md
install_doc "$here/BLAST-RADIUS.md"  BLAST-RADIUS.md

# var/claude/ is the in-repo, gitignored home for everything the review skills and the PreCompact
# handoff hook write. Created here so a skill never has to guess whether it exists.
mkdir -p "${CLAUDE_PROJECT_DIR:-$here/../..}/var/claude"

exit 0
