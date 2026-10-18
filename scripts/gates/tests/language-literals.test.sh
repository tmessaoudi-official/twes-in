#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/language-literals.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the catalogue, PHP under api/src, templates, and the web's sources.
tree() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/api/config" "$d/api/src/Mail" "$d/api/templates/email" "$d/web/src/app/shared/i18n" "$d/web/src/app/api"
  printf '{"default": "fr", "languages": [{"tag": "fr"}, {"tag": "en"}, {"tag": "ar"}]}\n' > "$d/api/config/languages.json"
  printf '<?php\nfinal class Greeting\n{\n    public function of(string $language): string\n    {\n        return $language;\n    }\n}\n' > "$d/api/src/Mail/Greeting.php"
  printf '<html lang="{{ app.locale }}"><p>{{ text }}</p></html>\n' > "$d/api/templates/email/hello.html.twig"
  printf "export const LANGUAGE = DEFAULT_LANGUAGE;\n" > "$d/web/src/app/hello.ts"
  printf '<p>{{ text }}</p>\n' > "$d/web/src/app/hello.html"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "language-literals gate"

d=$(tree); out=$(run "$d"); check "a tree that names no language passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$language = \$x ?? 'fr';\n" > "$d/api/src/Mail/Fallback.php"
out=$(run "$d"); check "a tag in PHP is refused with its file and line" $? 1 "$out" "api/src/Mail/Fallback.php:2"

d=$(tree); printf "const locale = \"en-GB\";\n" > "$d/web/src/app/clock.ts"
out=$(run "$d"); check "a tag with its region, in TypeScript, is refused" $? 1 "$out" "web/src/app/clock.ts:1"

d=$(tree); printf '<html lang="fr"></html>\n' > "$d/api/templates/email/old.html.twig"
out=$(run "$d"); check "a tag in a template is refused" $? 1 "$out" "old.html.twig:1"

d=$(tree); printf '<span lang="ar">x</span>\n' > "$d/web/src/app/word.html"
out=$(run "$d"); check "a tag in a web template is refused" $? 1 "$out" "word.html:1"

d=$(tree); printf "<?php\n\$words = ['fr' => 'et']; // language-literal: the French grammar of amounts in words is code\n" > "$d/api/src/Mail/Words.php"
out=$(run "$d"); check "a tag whose line says why passes" $? 0 "$out" "OK"

d=$(tree); printf '<span lang="ar">x</span> <!-- language-literal: the Arabic name is shown in Arabic -->\n' > "$d/web/src/app/word.html"
out=$(run "$d"); check "an HTML comment saying why passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$x = 'fr'; // language-literal:\n" > "$d/api/src/Mail/Empty.php"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Empty.php:2"

d=$(tree); printf "<?php\n\$x = 'fr'; // language-literal:   \n" > "$d/api/src/Mail/Blank.php"
out=$(run "$d"); check "a reason of spaces is no reason" $? 1 "$out" "Blank.php:2"

d=$(tree); printf "<?php\n\$x = ['fr' => 1]; \$y = 'a:b'; // language-literal: a key: the French table\n" > "$d/api/src/Mail/Colons.php"
out=$(run "$d"); check "a line with colons keeps its reason" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n/** Falls back to 'fr' when nothing is set. */\n" > "$d/api/src/Mail/Doc.php"
out=$(run "$d"); check "a tag named in a comment is not a literal" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$french = 'france'; \$x = 'frx'; \$y = 'end';\n" > "$d/api/src/Mail/Words.php"
out=$(run "$d"); check "a word starting like a tag is not a tag" $? 0 "$out" "OK"

d=$(tree); printf "it('says hello', () => expect(lang).toBe('fr'));\n" > "$d/web/src/app/hello.spec.ts"
printf "export const fr = 'fr';\n" > "$d/web/src/app/api/types.gen.ts"
out=$(run "$d"); check "specs and the generated API types are left out" $? 0 "$out" "OK"

d=$(tree); printf '{"languages": []}\n' > "$d/api/config/languages.json"
out=$(run "$d"); check "a catalogue with no language is refused, not read as nothing to find" $? 1 "$out" "no language"

d=$(tree); rm -r "$d/web/src/app"
out=$(run "$d"); check "a missing web/src/app is refused, not skipped" $? 1 "$out" "found no web/src/app"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
