#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# PostToolUse hook on Edit|Write: syntax-check the one file just written.
# PHP -> php -l ; shell -> bash -n. Anything else passes silently. Never blocks the turn.
# A finding goes to stderr (for humans) AND to stdout as hookSpecificOutput.additionalContext: with
# exit 0 that JSON field is the only channel that reaches the model — plain stdout (this hook's first
# version), stderr and systemMessage never do (measured 2026-09-28, ~/.claude review-remediation
# row 33). Guard: test-lint-on-write.sh beside this file.
set -uo pipefail
file=$(jq -r '.tool_input.file_path // empty' 2>/dev/null) || exit 0
[[ -n "${file:-}" && -f "$file" ]] || exit 0
msg=""
case "$file" in
  *.php) php -l "$file" >/dev/null 2>&1 || msg="php -l failed: $file"$'\n'"$(php -l "$file" 2>&1)" ;;
  *.sh)  out=$(bash -n "$file" 2>&1) || msg="bash -n failed: $file"$'\n'"$out" ;;
esac
[[ -n "$msg" ]] || exit 0
printf '%s\n' "$msg" >&2
jq -n --arg m "$msg" '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: $m}}'
exit 0
