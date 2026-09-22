#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/presentation-settings-parity.sh
pass=0
fail=0
check() {
  if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then
    pass=$((pass + 1))
    echo "  ok   $1"
  else
    fail=$((fail + 1))
    echo "  FAIL $1 (exit $2, wanted $3 with '$5')"
    echo "$4" | sed 's/^/       /'
  fi
}

# A tree holding the two sides this gate compares, and nothing else it reads.
repo() {
  local d
  d=$(mktemp -d)
  mkdir -p "$d/api/src/Settings/Application" "$d/web/src/app/shared/settings"
  cat > "$d/api/src/Settings/Application/PresentationSettings.php" <<'PHP'
<?php
final readonly class PresentationSettings implements DeclaresSettings
{
    public function settings(): iterable
    {
        yield new SettingDefinition('presentation.accent', SettingType::Colour, '#1f6feb', $c, $s, 'l', 'core');
        yield new SettingDefinition('presentation.scheme', SettingType::Enum, 'auto', $c, $s, 'l', 'core');
        yield new SettingDefinition('presentation.density', SettingType::Enum, 'comfortable', $c, $s, 'l', 'core');
        yield new SettingDefinition('presentation.sidebar', SettingType::Enum, 'expanded', $c, $s, 'l', 'core');
        yield new SettingDefinition('presentation.list.<id>', SettingType::Json, null, $c, $s, 'l', 'core', keyPattern: self::LIST);
    }
}
PHP
  cat > "$d/web/src/app/shared/settings/settings-registry.ts" <<'TS'
export const PRESENTATION = {
  accent: defineSetting('presentation.accent', DEFAULT_ACCENT, parseAccent),
  scheme: defineSetting<SchemePreference>('presentation.scheme', 'auto', oneOf('auto')),
  density: defineSetting<Density>('presentation.density', 'comfortable', oneOf('compact')),
  sidebar: defineSetting<SidebarState>('presentation.sidebar', 'expanded', oneOf('rail')),
} as const;
TS
  echo "$d"
}

d=$(repo)
out=$("$GATE" --root "$d" 2>&1)
check "agreeing sides pass" "$?" 0 "$out" "OK"

# The defect this gate exists for: a key the SPA writes and the API never declares. `ApiSettings.set` swallows the
# refusal (`.catch(() => {})`), so the choice lives until the page is reloaded and is then silently gone — which is
# how `presentation.sidebar-settings` shipped unpersisted, and how `presentation.plan-labels` nearly did.
d=$(repo)
cat >> "$d/web/src/app/shared/settings/settings-registry.ts" <<'TS'
export const MORE = {
  plan: defineSetting('presentation.plan-labels', 'code', oneOf('code')),
} as const;
TS
out=$("$GATE" --root "$d" 2>&1)
check "a key the API does not declare reds" "$?" 1 "$out" "presentation.plan-labels"

# The mirror: a key declared on the API that no page can read is dead weight, and usually a rename half-done.
d=$(repo)
cat > "$d/web/src/app/shared/settings/settings-registry.ts" <<'TS'
export const PRESENTATION = {
  accent: defineSetting('presentation.accent', DEFAULT_ACCENT, parseAccent),
  scheme: defineSetting<SchemePreference>('presentation.scheme', 'auto', oneOf('auto')),
  sidebar: defineSetting<SidebarState>('presentation.sidebar', 'expanded', oneOf('rail')),
} as const;
TS
out=$("$GATE" --root "$d" 2>&1)
check "a key no page reads reds" "$?" 1 "$out" "presentation.density"

# The patterned keys are built by interpolation in the SPA (`presentation.list.${listId}`), so they are invisible to
# any grep for a literal and must be excluded by NAME rather than counted as missing.
d=$(repo)
out=$("$GATE" --root "$d" 2>&1)
check "a <id> pattern is not reported missing" "$?" 0 "$out" "OK"

# Non-vacuity: a discovery input that yields nothing must red rather than compare two empty sets and pass.
d=$(repo)
: > "$d/web/src/app/shared/settings/settings-registry.ts"
out=$("$GATE" --root "$d" 2>&1)
check "an empty registry reds instead of passing" "$?" 1 "$out" "floor"

d=$(repo)
: > "$d/api/src/Settings/Application/PresentationSettings.php"
out=$("$GATE" --root "$d" 2>&1)
check "an empty declaration reds instead of passing" "$?" 1 "$out" "floor"

echo "presentation-settings-parity.test: $pass passed, $fail failed"
[[ "$fail" -eq 0 ]]
