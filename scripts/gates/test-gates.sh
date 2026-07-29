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
rm "$WORK/repo/api/composer.lock"
assert_gate 'refuses to pass when no lock file was inspected' dependency-licences.php 1

echo
printf '%d passed, %d failed\n' "$passed" "$failed"
[[ "$failed" -eq 0 ]]
