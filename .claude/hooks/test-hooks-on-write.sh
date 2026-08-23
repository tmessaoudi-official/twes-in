#!/usr/bin/env bash
# Test suite for the two PostToolUse write-time hooks.
#
# It exists because this repository has recorded four separate times that a control nothing
# executes is not a control — the handoff hook's one guarded write path, `test-gates.sh`
# reporting 33/33 for a gate that detected nothing, `PERMISSIVE_FOR_FONT_ASSETS` declared and
# read by nothing, and the integration suite skipping the tenancy proof while reporting OK. A
# PostToolUse hook is the worst possible place to repeat it: it runs unattended, its output is
# only ever seen when it FAILS, and the failure mode that matters most (silently exiting 0 for
# a file it should have checked) is indistinguishable from working correctly.
#
# So every assertion below is one of two shapes: a file that MUST be reported (exit 2 with the
# tool's own message), or a file that must be silent (exit 0). The second half is not padding —
# a hook that reports on everything gets switched off, so a false positive is a real defect.
#
# Run: bash .claude/hooks/test-hooks-on-write.sh
#
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
LINT="$HERE/lint-on-write.sh"
GATES="$HERE/gates-on-write.sh"
PASS=0; FAIL=0

ok()  { printf '  ok   — %s\n' "$1"; PASS=$((PASS+1)); }
bad() { printf '  FAIL — %s\n' "$1"; FAIL=$((FAIL+1)); }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Drive a hook exactly as Claude Code does: the JSON payload on stdin, `CLAUDE_PROJECT_DIR`
# in the environment. `jq -n` builds the payload rather than a printf template, so a path
# containing a quote cannot produce a test that passes for the wrong reason.
run_hook() {
  local hook="$1" path="$2"
  jq -n --arg p "$path" '{tool_input: {file_path: $p}}' \
    | CLAUDE_PROJECT_DIR="$REPO" bash "$hook" >"$TMP/out" 2>"$TMP/err"
  printf '%s' $?
}

# `expect <label> <hook> <path> <code> [needle...]` — assert the exit code, and when the code
# is 2 also assert the message CONTAINS each needle. Asserting the message and not only the
# code is the discipline § Gotchas records: a crash and a detection are indistinguishable by
# exit code alone, and `set -uo pipefail` means a typo'd variable exits non-zero too.
expect() {
  local label="$1" hook="$2" path="$3" want="$4"; shift 4
  local got; got="$(run_hook "$hook" "$path")"
  if [[ "$got" != "$want" ]]; then
    bad "$label (want exit $want, got $got; stderr: $(head -c 200 "$TMP/err"))"
    return
  fi
  local needle
  for needle in "$@"; do
    if ! grep -qF -- "$needle" "$TMP/err"; then
      bad "$label (exit $want as expected, but the message lacks '$needle': $(head -c 300 "$TMP/err"))"
      return
    fi
  done
  ok "$label"
}

echo "lint-on-write.sh — the per-file tool"

# ── 1. The two things it exists to catch ────────────────────────────────────────
BROKEN_PHP="$REPO/api/src/ZZTestBrokenSyntax.php"
printf '<?php\nthis is not php {{{\n' > "$BROKEN_PHP"
expect "reports a PHP parse error, naming php -l" \
  "$LINT" "$BROKEN_PHP" 2 "php -l" "ZZTestBrokenSyntax.php"
rm -f "$BROKEN_PHP"

# Style, not syntax: valid PHP that php-cs-fixer rejects. No `declare(strict_types=1)`, no
# SPDX header, wrong brace placement — the fixture is deliberately valid so that a pass here
# cannot be the parse check firing instead.
UGLY_PHP="$REPO/api/src/ZZTestUglyStyle.php"
printf '<?php\nnamespace Twes;\nclass ZZTestUglyStyle {\n    public function x(){return 1;}\n}\n' > "$UGLY_PHP"
expect "reports a style violation, naming php-cs-fixer" \
  "$LINT" "$UGLY_PHP" 2 "php-cs-fixer" "ZZTestUglyStyle.php"
rm -f "$UGLY_PHP"

BROKEN_SH="$REPO/scripts/zz-test-broken.sh"
printf '#!/usr/bin/env bash\nif [[ 1 == 1 ]]; then\n  echo unterminated\n' > "$BROKEN_SH"
expect "reports a shell syntax error, naming bash -n" \
  "$LINT" "$BROKEN_SH" 2 "bash -n" "zz-test-broken.sh"
rm -f "$BROKEN_SH"

# ── 2. Silence, which is half the contract ──────────────────────────────────────
expect "silent on a clean tracked PHP file" "$LINT" "$REPO/api/src/Domain/Money/Money.php" 0
expect "silent on a clean tracked shell script" "$LINT" "$REPO/scripts/gates/shell-syntax.sh" 0
expect "silent on a Markdown file (prose is not linted)" "$LINT" "$REPO/CLAUDE.md" 0
expect "silent on a file that does not exist" "$LINT" "$REPO/api/src/NoSuchFile.php" 0
expect "silent on an empty file_path" "$LINT" "" 0
expect "silent on a path outside the repository" "$LINT" "/etc/hostname" 0

# A dependency tree must never be linted: those defects are not ours to fix, and reporting
# them is the fastest way to train a reader to ignore this hook.
VENDOR_UGLY="$REPO/api/vendor/zz-test-ugly.php"
if [[ -d "$REPO/api/vendor" ]]; then
  printf '<?php\nclass Zz{public function x(){return 1;}}\n' > "$VENDOR_UGLY"
  expect "silent on a file under vendor/" "$LINT" "$VENDOR_UGLY" 0
  rm -f "$VENDOR_UGLY"
else
  ok "silent on a file under vendor/ (skipped — no vendor tree in this checkout)"
fi

# PHPStan's absence is a DECISION with a measurement behind it (11.3s per file), so it is
# pinned: if somebody adds it, this assertion fails and they must delete it on purpose.
if grep -qE '^[^#]*phpstan' "$LINT"; then
  bad "lint-on-write.sh invokes PHPStan — 11.3s per write; gate:static covers it"
else
  ok "PHPStan stays out of the write path (measured 11.3s per file)"
fi

# The no-auto-format ruling, likewise pinned rather than trusted. `fix` is php-cs-fixer's
# mutating subcommand; `check` is the only one permitted here.
if grep -E '^[^#]*php-cs-fixer' "$LINT" | grep -qE '\bfix\b'; then
  bad "lint-on-write.sh runs php-cs-fixer in FIX mode — comment position is load-bearing here"
else
  ok "php-cs-fixer runs in check mode only (nothing is auto-formatted)"
fi

echo
echo "gates-on-write.sh — the real gates, routed"

# ── 3. It runs a real gate and reports the gate's OWN message ───────────────────
# A PHP file with no SPDX header. This asserts BOTH that the routing fires for `.php` and that
# what comes back is `spdx-headers.sh`'s own wording rather than a paraphrase — the hook owning
# no rules is the whole design, and a paraphrase would be the first sign it had started to.
NO_SPDX="$REPO/api/src/ZZTestNoSpdx.php"
printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace Twes;\n\nfinal class ZZTestNoSpdx\n{\n}\n' > "$NO_SPDX"
expect "runs spdx-headers and echoes ITS message" \
  "$GATES" "$NO_SPDX" 2 "spdx-headers" "ZZTestNoSpdx.php"
rm -f "$NO_SPDX"

# An orphaned doc comment — the defect three successive certification rounds filed, and the
# single strongest reason this hook is worth its runtime.
#
# THIS CASE IS ALSO THE ONE THAT PINS THE THROWAWAY-INDEX BLOCK, and that is why the fixture is
# deliberately left UNTRACKED: `no-orphaned-docblocks.php` enumerates `git ls-files` WITHOUT
# `--others`, so without `GIT_INDEX_FILE` it inspects zero new files and this assertion sees
# exit 0. [Verified 2026-08-23: replacing the `export GIT_INDEX_FILE` line with a no-op costs
# exactly one pass and produces exactly one failure, and this is the case that flips. The
# DIRECTION is stated and the TOTALS are not — they were written here as 27 -> 26 and the suite
# reports 28, in a file whose own subject is that this suite reports its own count.] Do not "fix" a future failure
# here by staging the fixture — staging it would make the assertion pass for the wrong reason
# and retire the only coverage that block has.
ORPHAN="$REPO/api/src/ZZTestOrphan.php"
cat > "$ORPHAN" <<'PHPEOF'
<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Twes;

/**
 * This block documents nothing, because PHP attaches only the LATER one.
 */

/**
 * This is the one that attaches.
 */
final class ZZTestOrphan
{
}
PHPEOF
expect "runs no-orphaned-docblocks and echoes ITS message" \
  "$GATES" "$ORPHAN" 2 "no-orphaned-docblocks"
rm -f "$ORPHAN"

# A domain file reading the clock ambiently — the P0 that has no `use` statement to grep for.
AMBIENT="$REPO/api/src/Domain/ZZTestAmbient.php"
cat > "$AMBIENT" <<'PHPEOF'
<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Twes\Domain;

final class ZZTestAmbient
{
    public function now(): int
    {
        return time();
    }
}
PHPEOF
expect "runs no-ambient-calls-in-domain and echoes ITS message" \
  "$GATES" "$AMBIENT" 2 "no-ambient-calls-in-domain"
rm -f "$AMBIENT"

# ── 4. Routing: the non-PHP subjects each reach their own gate ──────────────────
# A locale catalogue missing a key in one language. This is the defect that would render an
# Arabic-speaking user's entire error message as the raw key.
BROKEN_LOCALE="$REPO/api/translations/zztest.fr.xlf"
cat > "$BROKEN_LOCALE" <<'XMLEOF'
<?xml version="1.0" encoding="UTF-8"?>
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
  <file source-language="en" target-language="fr" datatype="plaintext" original="file.ext">
    <body>
      <trans-unit id="1" resname="zztest.only_in_french">
        <source>zztest.only_in_french</source>
        <target>seulement ici</target>
      </trans-unit>
    </body>
  </file>
</xliff>
XMLEOF
expect "a write under api/translations/ routes to locale-key-parity" \
  "$GATES" "$BROKEN_LOCALE" 2 "locale-key-parity"
rm -f "$BROKEN_LOCALE"

expect "silent on a clean locale catalogue" "$GATES" "$REPO/api/translations/messages.fr.xlf" 0

# ── 5. Silence again, and the routing that must NOT fire ────────────────────────
expect "silent on a clean tracked PHP file" "$GATES" "$REPO/api/src/Domain/Money/Money.php" 0
expect "silent on a Markdown file that is not THIRD-PARTY-NOTICES" "$GATES" "$REPO/CLAUDE.md" 0
expect "silent on an empty file_path" "$GATES" "" 0
expect "silent on a path outside the repository" "$GATES" "/etc/hostname" 0
expect "silent on a file under vendor/" "$GATES" "$REPO/api/vendor/autoload.php" 0

# A DELETED path must still be routed — parity and SPDX coverage both read the tracked SET, so
# removing a catalogue is exactly as interesting as adding one. `lint-on-write.sh` requires the
# file to exist because its subject is the contents; this one must not.
expect "routes a path that no longer exists (the tracked SET still matters)" \
  "$GATES" "$REPO/api/translations/messages.fr.xlf.deleted" 0

# ── 6. A BROKEN DERIVATION ROUTES TOWARDS THE GATE, NOT PAST IT ────────────────
# The SPDX extension list is read from the gate's own `--dump-rules` rather than restated, so
# the failure mode is that the dump breaks and the list comes back empty. Treating that as "no
# extension matched" would turn a broken derivation into a silent skip — the vacuity shape
# § Gotchas records four times — so the hook runs the gate anyway.
#
# EXECUTED rather than grepped, using the sandbox-repo technique CLAUDE.md prescribes for shell
# isolation: a throwaway tree whose `spdx-headers.sh` FAILS on `--dump-rules` and FAILS LOUDLY
# when invoked normally. A hook that skipped the gate would exit 0 here.
SANDBOX="$TMP/sandbox"
mkdir -p "$SANDBOX/scripts/gates" "$SANDBOX/api/src"
cat > "$SANDBOX/scripts/gates/spdx-headers.sh" <<'STUBEOF'
#!/usr/bin/env bash
# Stub: refuses to introspect, and reports a violation when actually run.
[[ "${1:-}" == "--dump-rules" ]] && exit 3
echo "STUB-SPDX-RAN: pretend violation" >&2
exit 1
STUBEOF
# The other gates the `.php` routing reaches must exist and pass, or this case would go green
# for the wrong reason — a different gate's failure, not the SPDX one's.
for stub in layer-dependencies.php no-ambient-calls-in-domain.php no-orphaned-docblocks.php \
            no-owner-connection-in-application.php; do
  printf '<?php exit(0);\n' > "$SANDBOX/scripts/gates/$stub"
done
printf '#!/usr/bin/env bash\nexit 0\n' > "$SANDBOX/scripts/gates/no-orm-attributes-in-domain.sh"
printf '<?php\n' > "$SANDBOX/api/src/Whatever.php"
sandbox_run() {
  jq -n --arg p "$SANDBOX/api/src/Whatever.php" '{tool_input: {file_path: $p}}' \
    | CLAUDE_PROJECT_DIR="$SANDBOX" bash "$GATES" >"$TMP/out" 2>"$TMP/err"
  printf '%s' $?
}
got="$(sandbox_run)"
if [[ "$got" == "2" ]] && grep -qF "STUB-SPDX-RAN" "$TMP/err"; then
  ok "a failed --dump-rules still runs the SPDX gate (never a silent skip)"
else
  bad "a failed --dump-rules skipped the SPDX gate (exit $got; stderr: $(head -c 200 "$TMP/err"))"
fi

# ── 7. The hook owns no rules — pinned, because this is the design ─────────────
# Every check must be an invocation of a script under scripts/gates/. If a future edit
# inlines a grep-based rule here, that is a second rule set and this assertion says so.
inlined="$(grep -nE '^\s*gates\+=' -A6 "$GATES" \
  | grep -E '^\s*[0-9]+[-:]\s*"' \
  | grep -vE 'scripts/gates/' || true)"
if [[ -n "$inlined" ]]; then
  bad "gates-on-write.sh has a check that is not a scripts/gates/ invocation:"$'\n'"$inlined"
else
  ok "every check is an invocation of a real gate (this hook owns no rules)"
fi

# THE ROUTED SET, CHECKED IN BOTH DIRECTIONS — and it was one-directional until 2026-08-23 (round 4,
# R4K-4). It asserted only that three named gates stay OUT, and never that a gate which is NOT excluded
# is IN. So `no-forgeable-tenancy-in-production.sh` — a SECURITY gate — was routed for no file type at
# all, was absent from the hook's own "deliberately not here" list, and nothing here could notice
# either. A gate is easier to add than to finish adding, which is exactly what the missing direction
# stopped anybody seeing.
#
# THE EXCLUSION SET IS DECIDED ONCE, into a variable both directions read. § Gotchas 2026-07-29 is
# explicit about this after the handoff hook guarded one of its two write paths: two lists that must
# agree are one list and one silent exception. Adding a gate to `EXCLUDED_FROM_WRITE_PATH` now has to
# be a deliberate edit here, which is a visible diff, rather than the absence of a thought.
#
# The membership comes from `git ls-files`, never `ls` — a parallel certification round places reviewer
# worktrees inside the working tree (§ Gotchas 2026-07-31), and `lib/` holds libraries rather than gates.
EXCLUDED_FROM_WRITE_PATH=(
  test-gates.sh        # 48s, and it is the gates' own suite rather than a gate
  schema-tenancy.php   # needs a migrated PostgreSQL database
  compose-config.sh    # needs `docker compose`
  shell-syntax.sh      # redundant here: lint-on-write.sh already runs `bash -n` on the one file written
)

for forbidden in "${EXCLUDED_FROM_WRITE_PATH[@]}"; do
  if grep -qE "^[^#]*$forbidden" "$GATES"; then
    bad "gates-on-write.sh runs $forbidden — too slow, needs an external service, or is redundant here"
  else
    ok "$forbidden stays out of the write path"
  fi
done

# AND THE OTHER DIRECTION: every gate that is NOT excluded must actually be invoked somewhere.
while IFS= read -r gate_path; do
  gate_name="$(basename "$gate_path")"

  skip=''
  for forbidden in "${EXCLUDED_FROM_WRITE_PATH[@]}"; do
    [[ "$gate_name" == "$forbidden" ]] && skip=1 && break
  done
  [[ -n "$skip" ]] && continue

  if grep -qE "^[^#]*scripts/gates/$gate_name" "$GATES"; then
    ok "$gate_name is routed by the write path"
  else
    bad "$gate_name is in scripts/gates/ but gates-on-write.sh never runs it, and it is not in
     EXCLUDED_FROM_WRITE_PATH — so no decision is recorded either way. Route it, or exclude it
     deliberately with the reason beside it."
  fi
done < <(cd "$REPO" && git ls-files -- scripts/gates \
  | grep -E '\.(sh|php)$' \
  | grep -v '^scripts/gates/lib/')

echo
echo "$PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
