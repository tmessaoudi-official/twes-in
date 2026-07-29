#!/usr/bin/env bash
#
# Tests for the gates.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# WHY THIS EXISTS. The six gates in this directory ARE the enforcement of every architecture and
# licensing invariant in CLAUDE.md. Nothing was testing them. An early `return 0;` in any one of them
# would make `composer gate:architecture` report OK forever and no test would notice — which is the
# same trap this project already recorded in CLAUDE.md § Gotchas from the handoff-hook failure:
# "a test that greps source instead of running code proves nothing", and "a guard on one write path is
# not a guard".
#
# A certification round then proved the gap concretely: `gmdate()`, `$_ENV`, `$_SERVER`, string
# callables, `new $className()` and `#[\Doctrine\ORM\Mapping\Entity]` all passed every gate.
#
# Each case below injects a violation into a throwaway copy of the repository and asserts the gate
# exits non-zero. The repository itself is never modified.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPO_ROOT

WORK="$(mktemp -d)"
readonly WORK
trap 'rm -rf "$WORK"' EXIT

passed=0
failed=0

# Build a minimal fake repository that the gates accept: a Domain/ tree, translations, a lock file.
fresh_fixture() {
  rm -rf "$WORK/repo"
  # Application/ as well as Domain/: FORBIDDEN_BY_LAYER has two entries and only one was ever covered,
  # so deleting the Application rule outright still gave 33/33.
  mkdir -p "$WORK/repo/scripts/gates" "$WORK/repo/api/src/Domain/Probe" \
           "$WORK/repo/api/src/Application/Probe" "$WORK/repo/api/translations"
  cp "$REPO_ROOT"/scripts/gates/*.php "$REPO_ROOT"/scripts/gates/*.sh "$WORK/repo/scripts/gates/"
  cp "$REPO_ROOT"/api/translations/*.xlf "$WORK/repo/api/translations/"
  # BOTH manifest and lock. Omitting composer.json made every assertion about the
  # THIRD-PARTY-NOTICES.md check vacuous: directRequirements() returned [] and the check ran zero times,
  # so deleting it wholesale still gave 33/33.
  cp "$REPO_ROOT"/api/composer.json "$WORK/repo/api/"
  cp "$REPO_ROOT"/api/composer.lock "$WORK/repo/api/"
  cp "$REPO_ROOT"/THIRD-PARTY-NOTICES.md "$WORK/repo/"
  # The documents that STATE the licensing rule, so the case below can check they agree with the gate that
  # ENFORCES it. Round 6 found the gate permitting nine identifiers while both documents said five "and
  # nothing else" — a licensing decision taken as a build fix.
  cp "$REPO_ROOT"/CLAUDE.md "$REPO_ROOT"/LICENSING.md "$WORK/repo/"
  mkdir -p "$WORK/repo/.claude/agents"
  cp "$REPO_ROOT"/.claude/agents/completeness-reviewer.md "$WORK/repo/.claude/agents/"
  # The Angular tier's manifest AND lock. Omitting either would make every npm-licence assertion below
  # vacuous while the suite stayed green -- exactly the defect already recorded for api/composer.json.
  mkdir -p "$WORK/repo/admin"
  cp "$REPO_ROOT"/admin/package.json "$WORK/repo/admin/"
  cp "$REPO_ROOT"/admin/package-lock.json "$WORK/repo/admin/"
  mkdir -p "$WORK/repo/admin/src"
  # The Flutter manifest. Its lock carries no licence field (see the gate's OWED entry), so the notices
  # check is the ONLY examination this tier's dependencies get -- which makes covering it more important
  # here than for the tiers that also have a licence check.
  mkdir -p "$WORK/repo/mobile"
  cp "$REPO_ROOT"/mobile/pubspec.yaml "$WORK/repo/mobile/"

  # A GIT WORK TREE, because spdx-headers.sh asks git which files exist in order to prove its search roots
  # COVER every source file — the inventory direction that was missing when `api/phpunit.xml` sat unscanned
  # with no licence identifier. Without `git init` here that half of the gate would take its
  # not-a-work-tree branch in every case below, which is the same vacuity as a fixture omitting an input:
  # every assertion about coverage would pass without exercising it.
  git -C "$WORK/repo" init -q

  cat > "$WORK/repo/api/src/Domain/Probe/Clean.php" <<'PHP'
<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Probe;

final class Clean
{
    public function pure(string $value): string
    {
        return bcadd($value, '0', 3);
    }
}
PHP

  mkdir -p "$WORK/repo/api/tests/Unit"
  cat > "$WORK/repo/api/tests/Unit/CleanTest.php" <<'PHP'
<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit;

final class CleanTest {}
PHP

  cat > "$WORK/repo/api/src/Application/Probe/CleanHandler.php" <<'PHP'
<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Probe;

use Twes\Domain\Probe\Clean;

final class CleanHandler
{
    public function handle(Clean $clean, string $value): string
    {
        return $clean->pure($value);
    }
}
PHP
}

# assert_gate <description> <gate script> <expected exit: 0 or 1> [expected output substring]
#
# THE EXIT CODE IS NOT ENOUGH, and an earlier version of this function proved it. It accepted *any*
# non-zero exit for an expected-failure case and never looked at the output, so a gate replaced with
# `throw new RuntimeException('boom')` passed all 33 cases while printing `ok — catches time`. A crash and
# a detection are indistinguishable by exit code alone.
#
# So: an expected FAILURE must also print the gate's own FAIL marker, plus — where given — a substring
# naming the specific violation. An expected PASS must print "OK". Both directions are checked, because a
# gate that cannot pass is as broken as one that cannot fail.
assert_gate() {
  local description="$1" gate="$2" expected="$3" expected_output="${4:-}"
  local output rc

  case "$gate" in
    *.php) output="$(cd "$WORK/repo" && php "scripts/gates/$gate" 2>&1)" ;;
    *) output="$(cd "$WORK/repo" && bash "scripts/gates/$gate" 2>&1)" ;;
  esac
  rc=$?

  # The marker a gate prints is not always its filename: no-ambient-calls-in-domain.php announces itself
  # as "no-ambient-calls", and no-orm-attributes-in-domain.sh as "no-orm-attributes".
  local gate_name="${gate%%.*}"
  gate_name="${gate_name%-in-domain}"
  local wanted="$expected_output"

  if [[ -z "$wanted" ]]; then
    if (( expected == 0 )); then
      wanted="${gate_name}: OK"
    else
      wanted="${gate_name}: FAIL"
    fi
  fi

  local why=""

  if (( expected == 0 )); then
    (( rc == 0 )) || why="expected exit 0, got $rc"
  else
    (( rc != 0 )) || why="expected a non-zero exit, got 0"
  fi

  if [[ -z "$why" ]] && ! printf '%s' "$output" | grep -qF -- "$wanted"; then
    why="exit code was right but the output never said \"$wanted\" — a crash, not a detection?"
  fi

  if [[ -z "$why" ]]; then
    printf '  ok   — %s\n' "$description"
    passed=$((passed + 1))
  else
    printf '  FAIL — %s (%s)\n' "$description" "$why"
    printf '%s\n' "$output" | sed 's/^/         /'
    failed=$((failed + 1))
  fi
}

# inject <php body inside the class> — writes a Domain file containing it, header included
inject() {
  {
    printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\n'
    printf 'declare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nfinal class Sneaky\n{\n'
    printf '%s\n' "$1"
    printf '}\n'
  } > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
}

echo "== the clean fixture must pass every gate =="
fresh_fixture
assert_gate "clean: layer-dependencies" layer-dependencies.php 0
assert_gate "clean: no-ambient-calls" no-ambient-calls-in-domain.php 0
assert_gate "clean: no-orm-attributes" no-orm-attributes-in-domain.sh 0
assert_gate "clean: spdx-headers" spdx-headers.sh 0
assert_gate "clean: locale-key-parity" locale-key-parity.php 0
assert_gate "clean: dependency-licences" dependency-licences.php 0

echo "== ambient clock, randomness, environment and I/O =="
for pair in \
  'time:    public function f(): int { return time(); }' \
  'gmdate:  public function f(): string { return gmdate("Y"); }' \
  'getdate: public function f(): array { return getdate(); }' \
  'strftime:public function f(): string { return strftime("%Y"); }' \
  'date_create_immutable: public function f(): mixed { return date_create_immutable(); }' \
  'random_int: public function f(): int { return random_int(1, 2); }' \
  'getenv:  public function f(): mixed { return getenv("HOME"); }' \
  'file_get_contents: public function f(): mixed { return file_get_contents("/etc/hosts"); }' \
  'new DateTimeImmutable: public function f(): mixed { return new \DateTimeImmutable(); }' \
; do
  fresh_fixture
  inject "${pair#*:}"
  assert_gate "catches ${pair%%:*}" no-ambient-calls-in-domain.php 1
done

echo "== the evasions a token-and-import check misses =="
fresh_fixture; inject '    public function f(): mixed { return $_ENV["SECRET"] ?? null; }'
assert_gate 'catches $_ENV' no-ambient-calls-in-domain.php 1

fresh_fixture; inject '    public function f(): mixed { return $_SERVER["REQUEST_TIME"] ?? null; }'
assert_gate 'catches $_SERVER (an ambient clock read)' no-ambient-calls-in-domain.php 1

fresh_fixture; inject '    public function f(): mixed { $g = "time"; return $g(); }'
assert_gate 'catches a string callable' no-ambient-calls-in-domain.php 1

fresh_fixture; inject '    public function f(): mixed { return call_user_func("time"); }'
assert_gate 'catches call_user_func' no-ambient-calls-in-domain.php 1

fresh_fixture; inject '    public function f(): array { return array_map("time", [null]); }'
assert_gate 'catches a callable passed to array_map' no-ambient-calls-in-domain.php 1

fresh_fixture; inject '    public function f(): mixed { $c = "\DateTimeImmutable"; return new $c(); }'
assert_gate 'catches new $dynamicClass' no-ambient-calls-in-domain.php 1

echo "== Doctrine in the domain layer =="
fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity]\nfinal class Sneaky {}\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches #[ORM\Entity] with an import' no-orm-attributes-in-domain.sh 1

fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\n#[\\Doctrine\\ORM\\Mapping\\Entity]\nfinal class Sneaky {}\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches #[\Doctrine\ORM\Mapping\Entity] fully qualified' no-orm-attributes-in-domain.sh 1

fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse Doctrine\\ORM\\EntityManagerInterface;\n\nfinal class Sneaky { public function f(EntityManagerInterface $em): void {} }\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches use Doctrine\ORM\EntityManagerInterface' no-orm-attributes-in-domain.sh 1

echo "== outward dependencies =="
fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse Twes\\Infrastructure\\Tenancy\\TenantId;\n\nfinal class Sneaky { public function f(): string { return TenantId::class; } }\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches an outward use into Infrastructure' layer-dependencies.php 1

fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse Brick\\Math\\BigDecimal;\n\nfinal class Sneaky { public function f(): string { return BigDecimal::class; } }\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches a vendor namespace in Domain' layer-dependencies.php 1

echo "== outward dependencies from Application, not only from Domain =="
fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Application\\Probe;\n\nuse Twes\\Infrastructure\\Tenancy\\TenantId;\n\nfinal class Leak { public function f(): string { return TenantId::class; } }\n' > "$WORK/repo/api/src/Application/Probe/Leak.php"
assert_gate 'catches Application -> Infrastructure' layer-dependencies.php 1 'Application references Twes\Infrastructure'

fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Application\\Probe;\n\nuse Twes\\UI\\Http\\Response;\n\nfinal class Leak { public function f(): string { return Response::class; } }\n' > "$WORK/repo/api/src/Application/Probe/Leak.php"
assert_gate 'catches Application -> UI' layer-dependencies.php 1 'Application references Twes\UI'

echo "== a direct dependency missing from THIRD-PARTY-NOTICES.md =="
fresh_fixture
python3 - "$WORK/repo/api/composer.json" <<'PYADD'
import json, sys, pathlib
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d.setdefault('require', {})['acme/undocumented-lib'] = '^1.0'
p.write_text(json.dumps(d, indent=4))
PYADD
assert_gate 'catches a direct dependency absent from the notices' dependency-licences.php 1 'acme/undocumented-lib'

echo "== SPDX headers =="
fresh_fixture
printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nfinal class Sneaky {}\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches a missing header' spdx-headers.sh 1

fresh_fixture
mkdir -p "$WORK/repo/api/tests/Unit"
printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Tests\\Unit;\n\nfinal class NoHeaderTest {}\n' > "$WORK/repo/api/tests/Unit/NoHeaderTest.php"
assert_gate 'catches a missing header OUTSIDE api/src' spdx-headers.sh 1 'api/tests/Unit/NoHeaderTest.php'

fresh_fixture
{
  printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nfinal class Sneaky {\n'
  for _ in $(seq 1 40); do printf '    // padding\n'; done
  printf '    // TODO: add the SPDX-License-Identifier: AGPL-3.0-or-later header one day\n}\n'
} > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches a header merely MENTIONED far down the file' spdx-headers.sh 1

echo "== locale key parity =="
fresh_fixture
python3 - "$WORK/repo/api/translations/messages.ar.xlf" <<'PY'
import re, sys, pathlib
p = pathlib.Path(sys.argv[1])
p.write_text(re.sub(r'      <trans-unit id="6".*?</trans-unit>\n', '', p.read_text(), flags=re.S))
PY
assert_gate 'catches a key missing from one locale' locale-key-parity.php 1

fresh_fixture
rm "$WORK/repo/api/translations/messages.ar.xlf"
assert_gate 'catches a missing locale entirely' locale-key-parity.php 1

echo "== dependency licences =="
fresh_fixture
python3 - "$WORK/repo/api/composer.lock" <<'PY'
import json, sys, pathlib
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d['packages'][0]['license'] = ['GPL-3.0-or-later']
p.write_text(json.dumps(d, indent=4))
PY
assert_gate 'catches a copyleft dependency' dependency-licences.php 1

fresh_fixture
python3 - "$WORK/repo/api/composer.lock" <<'PY'
import json, sys, pathlib
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d['packages'][0]['license'] = []
p.write_text(json.dumps(d, indent=4))
PY
assert_gate 'catches a dependency declaring no licence' dependency-licences.php 1

fresh_fixture
# EVERY lock, not just the API's: with the Angular tier scaffolded, removing one leaves the other to be
# counted and the "inspected nothing" guard is never reached. A vacuity case that is itself vacuous.
rm "$WORK/repo/api/composer.lock" "$WORK/repo/admin/package-lock.json"
assert_gate 'refuses to pass when no lock file was inspected' dependency-licences.php 1


# ============================================================================================
# GENERATED CASES — driven from each gate's own rule data via `--dump-rules`.
#
# Round 3 showed why hand-picked cases are not enough: 60 of 71 banned names, 7 of 9 superglobals,
# two of three forbidden layer pairs, most SEARCH_ROOTS and most extensions could all be deleted
# with this suite still reporting 37/37, because it pinned THE FIXTURE'S INSTANCES rather than the
# RULE SETS.
#
# Generating cases from the rule data fixes "present but not enforced". It does NOT fix "entry
# deleted" — removing a rule would remove its own generated case. So there are two mechanisms, and
# both are needed:
#
#   1. BASELINE assertions below: each rule set must be a SUPERSET of a committed list. Deleting an
#      entry fails here. Adding one is free, and adding one to the baseline is a deliberate act.
#   2. GENERATED cases: one execution per entry currently present, proving it actually fires.
# ============================================================================================

# assert_contains <description> <haystack> <needle...>
assert_contains() {
  local description="$1" haystack="$2"; shift 2
  local missing=()

  for needle in "$@"; do
    printf '%s' "$haystack" | grep -qF -- "\"$needle\"" || printf '%s' "$haystack" | grep -qwF -- "$needle" || missing+=("$needle")
  done

  if (( ${#missing[@]} == 0 )); then
    printf '  ok   — %s\n' "$description"
    passed=$((passed + 1))
  else
    printf '  FAIL — %s (absent from the gate: %s)\n' "$description" "${missing[*]}"
    failed=$((failed + 1))
  fi
}

echo "== BASELINE: rule sets must not shrink =="
fresh_fixture
AMBIENT_RULES="$(cd "$WORK/repo" && php scripts/gates/no-ambient-calls-in-domain.php --dump-rules)"
LAYER_RULES="$(cd "$WORK/repo" && php scripts/gates/layer-dependencies.php --dump-rules)"
SPDX_RULES="$(cd "$WORK/repo" && bash scripts/gates/spdx-headers.sh --dump-rules)"
ORM_RULES="$(cd "$WORK/repo" && bash scripts/gates/no-orm-attributes-in-domain.sh --dump-rules)"
LICENCE_RULES="$(cd "$WORK/repo" && php scripts/gates/dependency-licences.php --dump-rules)"

# assert_at_least <description> <actual> <committed minimum>
#
# WHY A COUNT AND NOT ONLY NAMES. The named baseline below closes "an entry is present but not enforced"
# and, for the entries it names, "an entry was deleted". It cannot close deletion for the entries it does
# NOT name — and a review measured that residue exactly: the baseline names 41 of 97 banned functions, so
# 56 were still deletable with the suite reporting 183/183, because generating a case from the rule data
# means deleting an entry deletes its own case. A committed SIZE closes that for every entry, including
# every entry added in future, which enumerating names never will. Raising a number here is a deliberate
# act; a shrink is a failure.
assert_at_least() {
  local description="$1" actual="$2" minimum="$3" reported="${4:-$2}"

  if (( actual >= minimum )); then
    printf '  ok   — %s (%d >= %d)\n' "$description" "$reported" "$minimum"
    passed=$((passed + 1))
  else
    # The message must not diagnose the opposite cause. When this helper is used as a MAXIMUM the caller
    # passes an arithmetic complement, so `$actual` is not the set's size and "a rule was deleted" is exactly
    # backwards — a rule was ADDED. The fourth argument carries the number a human should be told about, and
    # the wording covers both directions. Round 6 filed this: a check that fires while pointing the
    # maintainer in the wrong direction is the same shape as the log line that said "kept" two lines before
    # clobbering the file.
    printf '  FAIL — %s (reported %d, threshold %d). A rule set changed size. If it SHRANK, a rule was deleted and its generated case went with it; if it GREW past a maximum, a rule was added that needs a deliberate decision, not a build fix.\n' \
      "$description" "$reported" "$minimum"
    failed=$((failed + 1))
  fi
}

# count_rules <json> <python expression over `r`>
count_rules() {
  printf '%s' "$1" | python3 -c "import json,sys; r=json.load(sys.stdin); print($2)"
}

assert_at_least "ambient: BANNED_FUNCTIONS has not shrunk" \
  "$(count_rules "$AMBIENT_RULES" "len(r['functions'])")" 97
assert_at_least "ambient: BANNED_VARIABLES has not shrunk" \
  "$(count_rules "$AMBIENT_RULES" "len(r['variables'])")" 9
assert_at_least "ambient: BANNED_INSTANTIATIONS has not shrunk" \
  "$(count_rules "$AMBIENT_RULES" "len(r['instantiations'])")" 2
assert_at_least "layers: forbidden pairs have not shrunk" \
  "$(count_rules "$LAYER_RULES" "sum(len(v) for v in r['layers'].values())")" 5
assert_at_least "orm: forbidden patterns have not shrunk" \
  "$(count_rules "$ORM_RULES" "len(r['patterns'])")" 8
assert_at_least "spdx: search roots have not shrunk" \
  "$(printf '%s' "$SPDX_RULES" | sed -n 's/^roots //p' | wc -w)" 11
assert_at_least "spdx: individually-listed files have not shrunk" \
  "$(printf '%s' "$SPDX_RULES" | sed -n 's/^files //p' | wc -w)" 4

# A MAXIMUM, uniquely: every entry here removes a directory from the header requirement, so this list
# growing is the failure mode, not shrinking. assert_at_least with a negated count is the same assertion.
excluded_count="$(printf '%s' "$SPDX_RULES" | sed -n 's/^excluded //p' | wc -w)"
assert_at_least "spdx: the exclusion list has not GROWN beyond 6" "$((12 - excluded_count))" 6 "$excluded_count"
assert_at_least "spdx: extensions have not shrunk" \
  "$(printf '%s' "$SPDX_RULES" | sed -n 's/^extensions //p' | wc -w)" 12

# THE LICENSING LISTS, and this gate was the only one with no introspection at all: round 5 added GPL-3.0,
# AGPL-3.0 and MPL-2.0 to PERMISSIVE and every case stayed green. Both directions are asserted, because both
# are dangerous here — a SHRINK breaks the build for no reason, and GROWTH is a legal act.
assert_contains "licences: the permissive set survives" "$LICENCE_RULES" \
  MIT Apache-2.0 BSD-2-Clause BSD-3-Clause ISC 0BSD MIT-0 CC0-1.0 BlueOak-1.0.0
assert_contains "licences: the build-time-data exception survives" "$LICENCE_RULES" CC-BY-4.0 CC-BY-3.0
assert_contains "licences: every lock file is still inspected" "$LICENCE_RULES" \
  /api/composer.lock /admin/package-lock.json
assert_at_least "licences: PERMISSIVE has not shrunk" \
  "$(count_rules "$LICENCE_RULES" "len(r['permissive'])")" 9
assert_at_least "licences: lock files have not shrunk" \
  "$(count_rules "$LICENCE_RULES" "len(r['lock_files'])")" 2

# A MAXIMUM on PERMISSIVE, for the same reason the SPDX exclusion list has one: every identifier added here
# permits a class of dependency, and a copyleft one satisfies the AGPL branch while killing the commercial
# one. Nine today; raising this number is a licensing decision and must be a deliberate edit to this file.
permissive_count="$(count_rules "$LICENCE_RULES" "len(r['permissive'])")"
assert_at_least "licences: PERMISSIVE has not GROWN beyond 9" "$((18 - permissive_count))" 9 "$permissive_count"
build_time_count="$(count_rules "$LICENCE_RULES" "len(r['build_time_data'])")"
assert_at_least "licences: the build-time-data exception has not GROWN beyond 2" \
  "$((4 - build_time_count))" 2 "$build_time_count"

echo "== GENERATED: every permissive identifier must actually be ACCEPTED =="
# The other direction from the copyleft cases: an identifier on the list that the gate rejects anyway is a
# rule that is present but not honoured, which the round-3 lesson says is exactly as bad as one that is
# absent. One case per entry, driven from the gate's own data.
while read -r licence; do
  fresh_fixture
  python3 - "$WORK/repo/api/composer.lock" "$licence" <<'PYLIC'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d['packages'][0]['license'] = [sys.argv[2]]
p.write_text(json.dumps(d, indent=4))
PYLIC
  assert_gate "accepts ${licence}" dependency-licences.php 0
done < <(printf '%s' "$LICENCE_RULES" | python3 -c "
import json,sys
for l in json.load(sys.stdin)['permissive']:
    print(l)
")

# The names whose loss would matter most, per category. Not exhaustive by design — exhaustive would
# duplicate the gate — but every entry here is one a reviewer named or one whose absence is a known
# class of defect.
assert_contains "ambient: the clock family survives" "$AMBIENT_RULES" \
  time microtime hrtime date gmdate getdate strftime gettimeofday mktime strtotime date_create_immutable
assert_contains "ambient: the randomness family survives" "$AMBIENT_RULES" \
  rand mt_rand random_int random_bytes uniqid str_shuffle openssl_random_pseudo_bytes
assert_contains "ambient: the environment family survives" "$AMBIENT_RULES" \
  getenv putenv ini_get get_cfg_var filter_input getopt php_sapi_name getcwd
assert_contains "ambient: the I/O family survives" "$AMBIENT_RULES" \
  file_get_contents file_put_contents fopen readfile opendir mkdir touch curl_init stream_socket_client
assert_contains "ambient: command execution survives" "$AMBIENT_RULES" \
  shell_exec exec system passthru proc_open popen
assert_contains "ambient: superglobals survive" "$AMBIENT_RULES" \
  '$_ENV' '$_SERVER' '$_GET' '$_POST' '$_REQUEST' '$_COOKIE' '$_FILES' '$_SESSION' '$GLOBALS'
assert_contains "ambient: clock instantiations survive" "$AMBIENT_RULES" DateTime DateTimeImmutable
assert_contains "layers: every forbidden pair survives" "$LAYER_RULES" \
  'Twes\\Application' 'Twes\\Infrastructure' 'Twes\\UI'
assert_contains "spdx: every search root survives" "$SPDX_RULES" \
  api/src api/tests api/tools api/config api/bin api/public api/migrations admin/src mobile/lib mobile/test scripts
assert_contains "spdx: every extension survives" "$SPDX_RULES" php ts dart sh xml sql yaml yml
assert_contains "spdx: the individually-listed files survive" "$SPDX_RULES" \
  api/phpunit.xml api/.php-cs-fixer.dist.php mobile/pubspec.yaml mobile/analysis_options.yaml
# The EXCLUSION list is the one place where growth is dangerous rather than harmless: every entry added is a
# directory the header requirement stops applying to. Pinned by name AND by maximum size, so widening it is
# a deliberate edit to this file rather than a quiet one to the gate.
assert_contains "spdx: the exclusion list is exactly the Flutter platform dirs" "$SPDX_RULES" \
  mobile/android mobile/ios mobile/linux mobile/macos mobile/windows mobile/web

echo "== GENERATED: every banned FUNCTION must actually fire =="
while read -r name; do
  fresh_fixture
  inject "    public function f(): mixed { return ${name}(1); }"
  assert_gate "fires on ${name}()" no-ambient-calls-in-domain.php 1 "${name}()"
done < <(printf '%s' "$AMBIENT_RULES" | python3 -c "
import json,sys
rules = json.load(sys.stdin)
# exit/die are language constructs with their own branch, and 'random' is a namespace not a callable.
skip = {'exit', 'die', 'eval', 'random'}
for n in rules['functions']:
    if n not in skip:
        print(n)
")

echo "== the LANGUAGE CONSTRUCTS, which cannot be generated as name() calls =="
# exit/die/include/require and the backtick are not T_STRING, so each has its own branch in the gate and
# each needs its own case. Round 3 proved it: disabling the T_EXIT branch kept the whole suite green,
# because the generated function cases skip these names by construction.
fresh_fixture; inject '    public function f(): void { exit(1); }'
assert_gate 'fires on exit' no-ambient-calls-in-domain.php 1 'exit is ambient'

fresh_fixture; inject '    public function f(): void { die("no"); }'
assert_gate 'fires on die' no-ambient-calls-in-domain.php 1 'die is ambient'

fresh_fixture; inject '    public function f(): mixed { return include "/etc/hosts"; }'
assert_gate 'fires on include' no-ambient-calls-in-domain.php 1 'include is ambient'

fresh_fixture; inject '    public function f(): mixed { return require "/etc/hosts"; }'
assert_gate 'fires on require' no-ambient-calls-in-domain.php 1 'require is ambient'

fresh_fixture; inject '    public function f(): mixed { return `id`; }'
assert_gate 'fires on the backtick operator' no-ambient-calls-in-domain.php 1 'backtick operator is ambient'

# eval is T_EVAL, the same family as T_EXIT — and it was in BANNED_FUNCTIONS, advertised by --dump-rules
# as enforced, skipped by name in the generated loop above, and matched by no branch at all. One eval
# evaded every ban in the table simultaneously.
fresh_fixture; inject '    public function f(): mixed { return eval("return time() . getenv(\"HOME\");"); }'
assert_gate 'fires on eval' no-ambient-calls-in-domain.php 1 'eval is ambient'

echo "== the EVASIONS a review found: alternative spellings of a banned call =="
# '\time' is a valid callable — PHP strips the leading backslash — and the string branch did not, while
# the qualified-name branch eighteen lines away did.
fresh_fixture; inject '    public function f(): mixed { $g = "\\time"; return $g(); }'
assert_gate 'fires on a backslash-prefixed string callable' no-ambient-calls-in-domain.php 1 'string callable'

fresh_fixture; inject '    public function f(): array { return array_map("\\getenv", ["HOME"]); }'
assert_gate 'fires on a backslash-prefixed callable in array_map' no-ambient-calls-in-domain.php 1 "'getenv' as a string callable"

# `use function time as now;` — the imported name is followed by `as`, not `(`, so the "only an actual
# call counts" rule skipped it, and the alias is in no denylist. Written at file scope, so inject() (which
# writes inside a class body) cannot be used.
fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse function time as now;\n\nfinal class Sneaky { public function f(): int { return now(); } }\n' \
  > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'fires on use function time as now' no-ambient-calls-in-domain.php 1 'use function time is ambient'

fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse function Foo\\{time, getenv};\n\nfinal class Sneaky {}\n' \
  > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'fires on a GROUPED use function import' no-ambient-calls-in-domain.php 1 'use function getenv is ambient'

# The other direction: a legitimate `use function` must not be flagged, or the rule above is unusable.
fresh_fixture
printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nuse function bcadd;\n\nfinal class Sneaky { public function f(): string { return bcadd("1", "2", 3); } }\n' \
  > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'does NOT fire on use function bcadd' no-ambient-calls-in-domain.php 0

echo "== VACUITY: a gate that inspected nothing must not report OK =="
# Round 3 fixed exactly this in layer-dependencies.php and left its two siblings — reading the SAME input
# — printing "does not exist yet, nothing to check" and exiting 0. Relocating api/src/Domain would have
# left both domain-purity P0s unchecked with composer gate:architecture still green. The fix in that one
# gate also had no case of its own: deleting it kept the suite at 183/183.
for gate in layer-dependencies.php no-ambient-calls-in-domain.php no-orm-attributes-in-domain.sh; do
  fresh_fixture
  rm -rf "$WORK/repo/api/src/Domain"
  assert_gate "refuses to pass with Domain/ ABSENT: ${gate%%.*}" "$gate" 1

  fresh_fixture
  find "$WORK/repo/api/src/Domain" -name '*.php' -delete
  assert_gate "refuses to pass with Domain/ EMPTY: ${gate%%.*}" "$gate" 1
done

echo "== SPDX: the search roots must be proven to COVER every source file =="
# The set complement, which nothing checked: `api/phpunit.xml` had no identifier and the gate reported OK,
# because api/ itself is not a root and so both files at its top level were invisible.
fresh_fixture
printf '# no header\n' > "$WORK/repo/api/uncovered-by-any-root.sh"
assert_gate 'catches a source file under NO search root' spdx-headers.sh 1 'under NO search root'

fresh_fixture
printf '<?xml version="1.0"?>\n<phpunit/>\n' > "$WORK/repo/api/phpunit.xml"
assert_gate 'checks the individually-listed api/phpunit.xml' spdx-headers.sh 1 'api/phpunit.xml'

# The exclusion must be NARROW: a header-less file under an excluded Flutter platform directory is fine
# (it is upstream's generated template, and stamping our copyright on it would be the actual error), but a
# header-less file in our own mobile/lib is not.
fresh_fixture
mkdir -p "$WORK/repo/mobile/android/app/src/main/res/values"
printf '<resources/>\n' > "$WORK/repo/mobile/android/app/src/main/res/values/styles.xml"
assert_gate 'ignores generated Flutter platform scaffolding' spdx-headers.sh 0

fresh_fixture
mkdir -p "$WORK/repo/mobile/lib"
printf '// no header\nvoid main() {}\n' > "$WORK/repo/mobile/lib/unlicensed.dart"
assert_gate 'still requires a header in our own mobile/lib' spdx-headers.sh 1 'mobile/lib/unlicensed.dart'

echo "== GENERATED: every banned SUPERGLOBAL must actually fire =="
while read -r name; do
  fresh_fixture
  inject "    public function f(): mixed { return ${name}['x'] ?? null; }"
  assert_gate "fires on ${name}" no-ambient-calls-in-domain.php 1 "${name}"
done < <(printf '%s' "$AMBIENT_RULES" | python3 -c "
import json,sys
for n in json.load(sys.stdin)['variables']:
    print(n)
")

echo "== GENERATED: every banned INSTANTIATION must actually fire =="
while read -r name; do
  fresh_fixture
  inject "    public function f(): mixed { return new \\${name}(); }"
  assert_gate "fires on new ${name}" no-ambient-calls-in-domain.php 1 "new ${name}"
done < <(printf '%s' "$AMBIENT_RULES" | python3 -c "
import json,sys
for n in json.load(sys.stdin)['instantiations']:
    print(n)
")

echo "== GENERATED: every forbidden LAYER PAIR must actually fire =="
while read -r layer prefix; do
  fresh_fixture
  target="${prefix//\\//}"
  mkdir -p "$WORK/repo/api/src/${layer}/Probe"
  printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\%s\\Probe;\n\nuse %s\\Probe\\Leaked;\n\nfinal class Leak { public function f(): string { return Leaked::class; } }\n' \
    "$layer" "$prefix" > "$WORK/repo/api/src/${layer}/Probe/Leak.php"
  assert_gate "fires on ${layer} -> ${prefix}" layer-dependencies.php 1 "references ${prefix}"
done < <(printf '%s' "$LAYER_RULES" | python3 -c "
import json,sys
for layer, prefixes in json.load(sys.stdin)['layers'].items():
    for p in prefixes:
        print(layer, p)
")

echo "== GENERATED: every SPDX search root and extension must actually be scanned =="
while read -r root; do
  fresh_fixture
  mkdir -p "$WORK/repo/${root}"
  printf '# no licence header here\n' > "$WORK/repo/${root}/unlicensed.sh"
  assert_gate "scans ${root}" spdx-headers.sh 1 "${root}/unlicensed.sh"
done < <(printf '%s' "$SPDX_RULES" | sed -n 's/^roots //p' | tr ' ' '\n')

while read -r extension; do
  fresh_fixture
  printf 'no licence header here\n' > "$WORK/repo/api/src/unlicensed.${extension}"
  assert_gate "scans *.${extension}" spdx-headers.sh 1 "unlicensed.${extension}"
done < <(printf '%s' "$SPDX_RULES" | sed -n 's/^extensions //p' | tr ' ' '\n')

echo "== GENERATED: every forbidden DOCTRINE pattern must actually fire =="
# Three of eight patterns were covered by hand. The gate now pairs each pattern with a line that must trip
# it — a test cannot derive a matching sample from a grep regex, so the gate declares one.
while IFS= read -r sample; do
  fresh_fixture
  printf '<?php\n\n/*\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\n%s\n\nfinal class Coupled {}\n' \
    "$sample" > "$WORK/repo/api/src/Domain/Probe/Coupled.php"
  assert_gate "fires on ${sample}" no-orm-attributes-in-domain.sh 1 'no-orm-attributes: FAIL'
done < <(printf '%s' "$ORM_RULES" | python3 -c "
import json,sys
for p in json.load(sys.stdin)['patterns']:
    print(p)
")

echo "== GENERATED: the licence gate must read BOTH lock sections and EVERY package =="
for section in packages packages-dev; do
  for position in 0 -1; do
    fresh_fixture
    python3 - "$WORK/repo/api/composer.lock" "$section" "$position" <<'PYLOCK'
import json, sys, pathlib
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d[sys.argv[2]][int(sys.argv[3])]['license'] = ['GPL-3.0-or-later']
p.write_text(json.dumps(d, indent=4))
PYLOCK
    where=$([[ "$position" == "0" ]] && echo first || echo last)
    assert_gate "catches copyleft on the ${where} ${section} entry" dependency-licences.php 1 'not permissive'
  done
done

echo "== the NPM licence path, which reads a lockfileVersion 3 lock without node_modules =="
# A whole tier's dependency tree arrived with the Angular scaffold, and the gate had been reporting it as
# "owed -- Wave 8" while the lock file sat beside it unread. These cases exist so that cannot recur.
fresh_fixture
python3 - "$WORK/repo/admin/package-lock.json" <<'PYNPM'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d['packages']['node_modules/tslib']['license'] = 'GPL-3.0-or-later'
p.write_text(json.dumps(d))
PYNPM
assert_gate 'catches a copyleft RUNTIME npm dependency' dependency-licences.php 1 'RUNTIME — we distribute this'

fresh_fixture
python3 - "$WORK/repo/admin/package-lock.json" <<'PYNPM'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
# A CC-BY package promoted to runtime. Tolerated as dev-only build-time data; never as something we ship.
e = d['packages']['node_modules/caniuse-lite']
e['dev'] = False
p.write_text(json.dumps(d))
PYNPM
assert_gate 'catches a CC-BY dependency promoted to RUNTIME' dependency-licences.php 1 'caniuse-lite'

fresh_fixture
python3 - "$WORK/repo/admin/package-lock.json" <<'PYNPM'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
del d['packages']['node_modules/tslib']['license']
p.write_text(json.dumps(d))
PYNPM
assert_gate 'catches an npm dependency declaring NO licence' dependency-licences.php 1 'declares NO licence'

# The other direction: a dev-only CC-BY must still PASS, or the split is pointless and the gate is just
# a stricter flat list wearing two names.
fresh_fixture
assert_gate 'accepts CC-BY on a DEV-only build-time data package' dependency-licences.php 0

fresh_fixture
python3 - "$WORK/repo/admin/package-lock.json" <<'PYNPM'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d['lockfileVersion'] = 4
p.write_text(json.dumps(d))
PYNPM
assert_gate 'refuses an unknown lockfileVersion rather than reading nothing' dependency-licences.php 1 'this gate reads version 3'

fresh_fixture
python3 - "$WORK/repo/admin/package.json" <<'PYNPM'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1]); d = json.loads(p.read_text())
d['dependencies']['acme-undocumented-widget'] = '^1.0.0'
p.write_text(json.dumps(d))
PYNPM
assert_gate 'catches a direct npm dependency absent from the notices' dependency-licences.php 1 'acme-undocumented-widget'

echo "== the PUB path: pubspec.yaml direct dependencies against the notices =="
fresh_fixture
python3 - "$WORK/repo/mobile/pubspec.yaml" <<'PYPUB'
import pathlib, sys
p = pathlib.Path(sys.argv[1])
p.write_text(p.read_text().replace('  cupertino_icons:', '  acme_unrecorded_pkg: ^1.0.0\n  cupertino_icons:', 1))
PYPUB
assert_gate 'catches a pub dependency absent from the notices' dependency-licences.php 1 'acme_unrecorded_pkg'

# The parser must not read pubspec.yaml's TOP-LEVEL `flutter:` block (assets, fonts, uses-material-design)
# as a dependency section. If it did, every asset key would be demanded in the notices and the gate would be
# unusable the moment this tier declares its first font.
fresh_fixture
python3 - "$WORK/repo/mobile/pubspec.yaml" <<'PYPUB'
import pathlib, sys
p = pathlib.Path(sys.argv[1])
p.write_text(p.read_text().rstrip() + '\n\nflutter:\n  uses-material-design: true\n  assets:\n  fonts:\n')
PYPUB
assert_gate 'does NOT treat the top-level flutter: block as dependencies' dependency-licences.php 0

echo "== SPDX: the newly in-scope authored file types =="
# R4-8 closed the missing *roots*; round 5 found the missing *extensions*. admin/src/app/app.html is OURS —
# it replaced the generated welcome page — and carried no identifier beside a .ts sibling that did.
for extension in html scss css js; do
  fresh_fixture
  mkdir -p "$WORK/repo/admin/src"
  printf 'no licence header here\n' > "$WORK/repo/admin/src/unlicensed.${extension}"
  assert_gate "scans admin/src/*.${extension}" spdx-headers.sh 1 "unlicensed.${extension}"
done

echo "== the licensing rule must be stated the same way everywhere it is stated =="
# THE DRIFT THAT ACTUALLY HAPPENED. The gate was widened from five identifiers to nine without amending
# CLAUDE.md invariant 8(a), LICENSING.md or the reviewer charter — so a session following CLAUDE.md had to
# refuse a dependency the gate accepted, and a reviewer following its own charter had to file a P0 against
# the gate. Four artefacts, one rule: this case makes disagreement fail rather than wait for a review.
for document in CLAUDE.md LICENSING.md .claude/agents/completeness-reviewer.md; do
  fresh_fixture
  missing=()

  while read -r licence; do
    grep -qF -- "$licence" "$WORK/repo/${document}" || missing+=("$licence")
  done < <(printf '%s' "$LICENCE_RULES" | python3 -c "
import json,sys
r = json.load(sys.stdin)
for l in r['permissive'] + r['build_time_data']:
    print(l)
")

  if (( ${#missing[@]} == 0 )); then
    printf '  ok   — %s states every licence identifier the gate enforces\n' "$document"
    passed=$((passed + 1))
  else
    printf '  FAIL — %s does not mention: %s. The gate enforces a rule this document does not state; amend both, or they disagree.\n' \
      "$document" "${missing[*]}"
    failed=$((failed + 1))
  fi
done

echo
printf '%d passed, %d failed\n' "$passed" "$failed"
[[ "$failed" -eq 0 ]]
