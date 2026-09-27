#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Guard for lint-on-write.sh. Run it after any edit there: bash .claude/hooks/test-lint-on-write.sh
#
# The contract: never block (exit 0 always), silent on a clean file, and a finding reaches the MODEL.
# With exit 0 the only channel that does is stdout JSON `hookSpecificOutput.additionalContext`;
# plain stdout, stderr and `systemMessage` never reach it (measured 2026-09-28 with random markers,
# 5 variants x 2 models — ~/.claude review-remediation row 33). Findings also go to stderr for humans.
set -uo pipefail
SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lint-on-write.sh"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok   $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }

# run <file> → sets RC, OUT (stdout), ERR (stderr)
run() {
  RC=0
  OUT=$(printf '{"tool_input":{"file_path":"%s"}}' "$1" | bash "$SCRIPT" 2>"$TMP/err") || RC=$?
  ERR=$(cat "$TMP/err")
}
ctx() { printf '%s' "$OUT" | jq -r '.hookSpecificOutput.additionalContext // empty' 2>/dev/null; }

echo "lint-on-write.sh — advisory contract"

printf '<?php\nfunction f( {\n' > "$TMP/bad.php"
run "$TMP/bad.php"
[[ $RC == 0 ]] && ok "exit 0 on a PHP syntax error (never blocks)" || bad "exit $RC on a PHP syntax error"
[[ "$(ctx)" == *"php -l failed"* ]] && ok "PHP finding reaches the model (additionalContext)" \
  || bad "no additionalContext for a PHP error; stdout='${OUT:0:80}'"
[[ "$(printf '%s' "$OUT" | jq -r '.hookSpecificOutput.hookEventName // empty' 2>/dev/null)" == PostToolUse ]] \
  && ok "hookEventName is PostToolUse" || bad "hookEventName missing"
[[ "$ERR" == *"php -l failed"* ]] && ok "PHP finding also on stderr" || bad "stderr lacks the PHP finding"

printf 'if then\n' > "$TMP/bad.sh"
run "$TMP/bad.sh"
[[ $RC == 0 && "$(ctx)" == *"bash -n failed"* ]] && ok "shell finding reaches the model" \
  || bad "shell finding: rc=$RC stdout='${OUT:0:80}'"

printf '<?php\necho 1;\n' > "$TMP/good.php"; printf 'echo ok\n' > "$TMP/good.sh"; echo x > "$TMP/n.txt"
for f in good.php good.sh n.txt; do
  run "$TMP/$f"
  [[ $RC == 0 && -z "$OUT" && -z "$ERR" ]] && ok "silent on $f" || bad "noise on $f: rc=$RC out='$OUT' err='$ERR'"
done

echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
