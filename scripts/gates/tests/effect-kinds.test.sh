#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/effect-kinds.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
api() {
  printf "<?php\nfinal class ChangeCost\n{\n    public const string FOLLOWED = 'product.price_followed';\n\n    public const string MOVED = 'product.margin_moved';\n\n    private function said(): void\n    {\n        \$this->effects->said(new Effect(self::FOLLOWED, \$id, ['previousPrice' => \$p]));\n        \$this->effects->said(new Effect(self::MOVED, \$id));\n    }\n}\n" > "$1/api/src/Module/Products/Application/ChangeCost.php"
}
sayer() {
  printf "export const FOLLOWED = 'product.price_followed';\nexport const MOVED = 'product.margin_moved';\n\n@Injectable({ providedIn: 'root' })\nexport class PriceEffects implements EffectSayer {\n  readonly kinds = [FOLLOWED, MOVED] as const;\n}\n" > "$1/web/src/app/products/price-effects.ts"
}
config() {
  printf "export const appConfig = {\n  providers: [\n    { provide: EFFECT_SAYERS, useExisting: %s, multi: true },\n  ],\n};\n" "$2" > "$1/web/src/app/app.config.ts"
}
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q
  mkdir -p "$d/api/src/Module/Products/Application" "$d/web/src/app/products" "$d/web/src/app/shared/effects"
  printf "export interface EffectSayer { readonly kinds: readonly string[]; }\n" > "$d/web/src/app/shared/effects/effects.ts"
  api "$d"; sayer "$d"; config "$d" PriceEffects
  echo "$d"
}
echo "effect-kinds gate"
d=$(repo); git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "every kind the API says has a registered sayer, and the reverse, passes" $? 0 "$out" "OK — 2 kinds"
d=$(repo); printf "<?php\nfinal class Count\n{\n    public const string COUNTED = 'stock.counted';\n\n    public function x(): void\n    {\n        \$this->effects->said(new Effect(self::COUNTED, null));\n    }\n}\n" > "$d/api/src/Module/Products/Application/Count.php"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a kind the API says that no screen says is named" $? 1 "$out" "stock.counted"
d=$(repo); printf "export const SPARE = 'product.spare';\nexport class SpareEffects implements EffectSayer {\n  readonly kinds = [SPARE];\n}\n" > "$d/web/src/app/products/spare-effects.ts"
printf "export const appConfig = { providers: [\n  { provide: EFFECT_SAYERS, useExisting: PriceEffects, multi: true },\n  { provide: EFFECT_SAYERS, useExisting: SpareEffects, multi: true },\n] };\n" > "$d/web/src/app/app.config.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a kind a sayer waits for that the API never says is named" $? 1 "$out" "product.spare"
d=$(repo); config "$d" OtherEffects; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a sayer the app does not register as EFFECT_SAYERS is named" $? 1 "$out" "PriceEffects"
d=$(repo); printf "<?php\nfinal class Loose\n{\n    public function x(): void\n    {\n        \$this->effects->said(new Effect('product.loose', null));\n    }\n}\n" > "$d/api/src/Module/Products/Application/Loose.php"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an effect said with no constant of its class is named, since nothing can read its kind" $? 1 "$out" "Loose.php"
d=$(repo); mkdir -p "$d/api/tests"; printf "<?php\n\$e = new Effect('test.only', null);\n" > "$d/api/tests/X.php"
printf "export const T = 'test.web';\nexport class T2 implements EffectSayer { readonly kinds = [T]; }\n" > "$d/web/src/app/products/t.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "tests and specs neither say nor hear a kind" $? 0 "$out" "OK — 2 kinds"
d=$(repo); rm "$d/api/src/Module/Products/Application/ChangeCost.php" "$d/web/src/app/products/price-effects.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree where neither side yields a kind reds rather than passing over nothing" $? 1 "$out" "no effect"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
