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
  mkdir -p "$WORK/repo/scripts/gates" "$WORK/repo/api/src/Domain/Probe" "$WORK/repo/api/translations"
  cp "$REPO_ROOT"/scripts/gates/*.php "$REPO_ROOT"/scripts/gates/*.sh "$WORK/repo/scripts/gates/"
  cp "$REPO_ROOT"/api/translations/*.xlf "$WORK/repo/api/translations/"
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
}

# assert_gate <description> <gate script> <expected exit: 0 or 1>
assert_gate() {
  local description="$1" gate="$2" expected="$3"
  local output rc

  case "$gate" in
    *.php) output="$(cd "$WORK/repo" && php "scripts/gates/$gate" 2>&1)" ;;
    *) output="$(cd "$WORK/repo" && bash "scripts/gates/$gate" 2>&1)" ;;
  esac
  rc=$?

  if [[ "$rc" -eq "$expected" ]] || { [[ "$expected" -eq 1 ]] && [[ "$rc" -ne 0 ]]; }; then
    printf '  ok   — %s\n' "$description"
    passed=$((passed + 1))
  else
    printf '  FAIL — %s (expected exit %s, got %s)\n' "$description" "$expected" "$rc"
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

echo "== SPDX headers =="
fresh_fixture
printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace Twes\\Domain\\Probe;\n\nfinal class Sneaky {}\n' > "$WORK/repo/api/src/Domain/Probe/Sneaky.php"
assert_gate 'catches a missing header' spdx-headers.sh 1

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
