#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# PostToolUse hook on Edit|Write: syntax-check the one file just written.
# PHP -> php -l, in the project's tools container (`make php-lint`: the project runs nothing on the host's PHP);
# shell -> bash -n (bash is the host's wrapper layer). Anything else passes silently. Never blocks the turn.
# A finding goes to stderr (for humans) AND to stdout as hookSpecificOutput.additionalContext: with
# exit 0 that JSON field is the only channel that reaches the model — plain stdout (this hook's first
# version), stderr and systemMessage never do (measured 2026-09-28, ~/.claude review-remediation
# row 33). Guard: test-lint-on-write.sh beside this file.
set -uo pipefail
file=$(jq -r '.tool_input.file_path // empty' 2>/dev/null) || exit 0
[[ -n "${file:-}" && -f "$file" ]] || exit 0
msg=""
case "$file" in
  *.php)
    root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
    # The container sees the working tree (and nothing else of the host): a PHP file elsewhere cannot be checked here.
    [[ "$file" == "$root"/* ]] || exit 0
    out=$(timeout 120 make -s -C "$root" php-lint FILE="$file" 2>&1); rc=$?
    if ((rc != 0)); then
      # php -l says "Parse error" / "Errors parsing" on a real syntax error; anything else is the container, not the file.
      if [[ "$out" == *"Parse error"* || "$out" == *"Errors parsing"* || "$out" == *"syntax error"* ]]; then
        msg="php -l failed: $file"$'\n'"$out"
      else
        msg="php -l could not run in the tools container (exit $rc): $file"$'\n'"$out"
      fi
    fi ;;
  *.sh)  out=$(bash -n "$file" 2>&1) || msg="bash -n failed: $file"$'\n'"$out" ;;
esac
[[ -n "$msg" ]] || exit 0
printf '%s\n' "$msg" >&2
jq -n --arg m "$msg" '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: $m}}'
exit 0
