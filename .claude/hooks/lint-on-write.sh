#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# PostToolUse hook on Edit|Write: syntax-check the one file just written.
# PHP -> php -l ; shell -> bash -n. Anything else passes silently. Never blocks the turn.
set -uo pipefail
file=$(jq -r '.tool_input.file_path // empty' 2>/dev/null) || exit 0
[[ -n "${file:-}" && -f "$file" ]] || exit 0
case "$file" in
  *.php) php -l "$file" >/dev/null 2>&1 || { echo "php -l failed: $file"; php -l "$file"; } ;;
  *.sh)  bash -n "$file" 2>&1 || echo "bash -n failed: $file" ;;
esac
exit 0
