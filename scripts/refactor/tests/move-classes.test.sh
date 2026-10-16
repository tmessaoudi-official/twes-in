#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
MOVER=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/move-classes.py
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
has() { if grep -qF -e "$3" "$2"; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 ($2 lacks '$3')"; sed 's/^/       /' "$2"; fi; }
lacks() { if ! grep -qF -e "$3" "$2"; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 ($2 still has '$3')"; sed 's/^/       /' "$2"; fi; }

# A tree shaped like the real one: one context whose ApiPlatform folder is flat, a sibling naming a moved class by its
# short name alone, a config and a script naming it in full, and a class whose name merely starts alike.
tree() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/api/src/Shop/Infrastructure/ApiPlatform" "$d/api/src/Shop/Domain" "$d/api/config" "$d/api/tests" "$d/scripts"
  cat > "$d/api/src/Shop/Infrastructure/ApiPlatform/OrderResource.php" <<'PHP'
<?php

namespace App\Shop\Infrastructure\ApiPlatform;

use App\Shop\Domain\Order;

/** Read through OrderProvider. */
#[ApiResource(provider: OrderProvider::class)]
final class OrderResource
{
    public function __construct(public Order $order, public ?OrderView $view = null)
    {
    }
}
PHP
  cat > "$d/api/src/Shop/Infrastructure/ApiPlatform/OrderProvider.php" <<'PHP'
<?php

namespace App\Shop\Infrastructure\ApiPlatform;

final class OrderProvider
{
    public function provide(): OrderResource
    {
        return new OrderResource(new \App\Shop\Domain\Order());
    }
}
PHP
  cat > "$d/api/src/Shop/Infrastructure/ApiPlatform/OrderView.php" <<'PHP'
<?php

namespace App\Shop\Infrastructure\ApiPlatform;

final class OrderView
{
    public static function of(OrderResource $resource): self
    {
        return new self();
    }
}
PHP
  cat > "$d/api/src/Shop/Infrastructure/ApiPlatform/OrderResourceLater.php" <<'PHP'
<?php

namespace App\Shop\Infrastructure\ApiPlatform;

final class OrderResourceLater
{
}
PHP
  cat > "$d/api/src/Shop/Domain/Order.php" <<'PHP'
<?php

namespace App\Shop\Domain;

final class Order
{
}
PHP
  cat > "$d/api/tests/OrderTest.php" <<'PHP'
<?php

namespace App\Tests;

use App\Shop\Infrastructure\ApiPlatform\OrderResource;
use App\Shop\Infrastructure\ApiPlatform\OrderResource as Read;
use App\Shop\Infrastructure\ApiPlatform\OrderResourceLater;

final class OrderTest
{
    private const string NAME = 'App\\Shop\\Infrastructure\\ApiPlatform\\OrderResource';
}
PHP
  printf 'services:\n    App\\Shop\\Infrastructure\\ApiPlatform\\OrderResource: ~\n' > "$d/api/config/services.yaml"
  printf '#!/usr/bin/env bash\ncat api/src/Shop/Infrastructure/ApiPlatform/OrderResource.php\n' > "$d/scripts/read.sh"
  git -C "$d" init -q && git -C "$d" add -A \
    && git -C "$d" -c user.name=t -c user.email=t@t commit -qm fixture || exit 2
  echo "$d"
}
map() { printf '%s\n' "$@" > "$1/map.txt.tmp"; }
run() { python3 -I "$MOVER" --root "$1" "${@:2}" 2>&1; }
A=api/src/Shop/Infrastructure/ApiPlatform
echo "move-classes"

d=$(tree)
printf 'Shop/Infrastructure/ApiPlatform/OrderResource.php -> Shop/Infrastructure/ApiPlatform/Resource\n' > "$d/map.txt"
out=$(run "$d" "$d/map.txt"); check "a dry run says what it would move" $? 0 "$out" "OrderResource.php -> Shop/Infrastructure/ApiPlatform/Resource/OrderResource.php"
[[ -f "$d/$A/OrderResource.php" && -z "$(git -C "$d" status --porcelain -- api scripts)" ]]
check "a dry run changes nothing" $? 0 "" ""

out=$(run "$d" --apply "$d/map.txt"); check "applied, it moves the class" $? 0 "$out" "moved 1"
[[ -f "$d/$A/Resource/OrderResource.php" && ! -e "$d/$A/OrderResource.php" ]]; check "the file is at its new path" $? 0 "" ""
git -C "$d" status --porcelain -- "$A" | grep -q '^R'; check "through git, as a rename" $? 0 "" ""
has "its namespace line follows it" "$d/$A/Resource/OrderResource.php" 'namespace App\Shop\Infrastructure\ApiPlatform\Resource;'
has "a sibling it named by short name is imported" "$d/$A/Resource/OrderResource.php" 'use App\Shop\Infrastructure\ApiPlatform\OrderProvider;'
has "every such sibling, a type in its signature too" "$d/$A/Resource/OrderResource.php" 'use App\Shop\Infrastructure\ApiPlatform\OrderView;'
has "a sibling naming it by short name imports it" "$d/$A/OrderProvider.php" 'use App\Shop\Infrastructure\ApiPlatform\Resource\OrderResource;'
has "an import elsewhere is rewritten" "$d/api/tests/OrderTest.php" 'use App\Shop\Infrastructure\ApiPlatform\Resource\OrderResource;'
has "an alias is kept" "$d/api/tests/OrderTest.php" 'use App\Shop\Infrastructure\ApiPlatform\Resource\OrderResource as Read;'
has "a name written with doubled backslashes is rewritten" "$d/api/tests/OrderTest.php" "'App\\\\Shop\\\\Infrastructure\\\\ApiPlatform\\\\Resource\\\\OrderResource'"
has "a name merely starting alike is left alone" "$d/api/tests/OrderTest.php" 'use App\Shop\Infrastructure\ApiPlatform\OrderResourceLater;'
has "a config naming it is rewritten" "$d/api/config/services.yaml" 'App\Shop\Infrastructure\ApiPlatform\Resource\OrderResource: ~'
has "a script naming its path is rewritten" "$d/scripts/read.sh" "$A/Resource/OrderResource.php"
lacks "an unrelated class gains no import" "$d/$A/OrderResourceLater.php" 'use '
has "a fully-qualified name in code is left as it is" "$d/$A/OrderProvider.php" 'new \App\Shop\Domain\Order()'

# Two classes moved together into one folder name each other with no import, as before.
d=$(tree)
printf '%s\n' 'Shop/Infrastructure/ApiPlatform/OrderResource.php -> Shop/Infrastructure/ApiPlatform/Resource' \
  'Shop/Infrastructure/ApiPlatform/OrderView.php -> Shop/Infrastructure/ApiPlatform/Resource' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "two classes move together" $? 0 "$out" "moved 2"
lacks "together, they need no import of each other" "$d/$A/Resource/OrderResource.php" 'use App\Shop\Infrastructure\ApiPlatform\Resource\OrderView;'
lacks "nor of their old place" "$d/$A/Resource/OrderView.php" 'use App\Shop\Infrastructure\ApiPlatform\OrderResource;'

# A class moved into a folder of its own name, with a sibling moved there first: the sibling's new name starts with
# the class's old one, and is no use of it.
d=$(tree)
printf '<?php\n\nnamespace App\\Shop\\Domain;\n\nfinal class Cost\n{\n}\n' > "$d/api/src/Shop/Domain/Cost.php"
printf '<?php\n\nnamespace App\\Shop\\Domain;\n\nfinal class ReadCost\n{\n}\n' > "$d/api/src/Shop/Domain/ReadCost.php"
printf '<?php\n\nuse App\\Shop\\Domain\\ReadCost;\nuse App\\Shop\\Domain\\Cost;\n\n$n = '"'"'App\\\\Shop\\\\Domain\\\\ReadCost'"'"';\n' > "$d/api/tests/CostTest.php"
git -C "$d" add -A && git -C "$d" -c user.name=t -c user.email=t@t commit -qm costs
printf '%s\n' 'Shop/Domain/ReadCost.php -> Shop/Domain/Cost' 'Shop/Domain/Cost.php -> Shop/Domain/Cost' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a class moves into a folder of its own name" $? 0 "$out" "moved 2"
has "the sibling moved first keeps its own new name" "$d/api/tests/CostTest.php" 'use App\Shop\Domain\Cost\ReadCost;'
has "the class takes its own" "$d/api/tests/CostTest.php" 'use App\Shop\Domain\Cost\Cost;'
lacks "neither is renamed twice" "$d/api/tests/CostTest.php" 'Cost\Cost\ReadCost'
has "a name in doubled backslashes takes its new name" "$d/api/tests/CostTest.php" 'App\\Shop\\Domain\\Cost\\ReadCost'
lacks "nor is it renamed twice" "$d/api/tests/CostTest.php" 'Cost\\Cost\\ReadCost'

# A folder already named like a class that moves holds classes of its own: their names start with the class's.
d=$(tree)
mkdir -p "$d/api/src/Shop/Domain/Order"
printf '<?php\n\nnamespace App\\Shop\\Domain\\Order;\n\nfinal class Extra\n{\n}\n' > "$d/api/src/Shop/Domain/Order/Extra.php"
printf '<?php\n\nuse App\\Shop\\Domain\\Order\\Extra;\n' > "$d/api/tests/ExtraTest.php"
git -C "$d" add -A && git -C "$d" -c user.name=t -c user.email=t@t commit -qm extra
printf 'Shop/Domain/Order.php -> Shop/Domain/Model\n' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a class moves beside a folder of its name" $? 0 "$out" "moved 1"
has "what that folder holds keeps its name" "$d/api/tests/ExtraTest.php" 'use App\Shop\Domain\Order\Extra;'

# A class moved next to one it imported drops that import, now of its own namespace.
d=$(tree)
printf 'Shop/Infrastructure/ApiPlatform/OrderResource.php -> Shop/Domain\n' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a class moves beside what it imported" $? 0 "$out" "moved 1"
lacks "its import of its own namespace goes" "$d/api/src/Shop/Domain/OrderResource.php" 'use App\Shop\Domain\Order;'

# What is refused changes nothing.
d=$(tree)
printf 'Shop/Infrastructure/ApiPlatform/Missing.php -> Shop/Domain\n' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a mapping naming no file is refused" $? 1 "$out" "Missing.php"
[[ -z "$(git -C "$d" status --porcelain -- api scripts)" ]]; check "and changes nothing" $? 0 "" ""

d=$(tree)
printf 'Shop/Domain/Order.php -> Shop/Infrastructure/ApiPlatform\n' > "$d/map.txt"
cp "$d/api/src/Shop/Domain/Order.php" "$d/$A/Order.php"
out=$(run "$d" --apply "$d/map.txt"); check "a move onto an existing file is refused" $? 1 "$out" "exists"

d=$(tree)
mkdir -p "$d/api/tests/Architecture"
printf "<?php\nfinal class SourceLayoutTest\n{\n    private const array KEPT = [\n        'App\\\\Shop\\\\Domain\\\\Order',\n    ];\n}\n" > "$d/api/tests/Architecture/SourceLayoutTest.php"
printf 'Shop/Domain/Order.php -> Shop/Domain/Model\n' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a class kept by its name is refused" $? 1 "$out" "kept"
[[ -f "$d/api/src/Shop/Domain/Order.php" ]]; check "and stays" $? 0 "" ""

d=$(tree)
printf '%s\n' 'Shop/Infrastructure/ApiPlatform/OrderView.php -> Shop/Infrastructure/ApiPlatform/Output' \
  'Shop/Infrastructure/ApiPlatform/Missing.php -> Shop/Domain' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a refusal among good lines is refused whole" $? 1 "$out" "Missing.php"
[[ -z "$(git -C "$d" status --porcelain -- api scripts)" ]]; check "and moves none of them" $? 0 "" ""

d=$(tree)
cp "$d/api/src/Shop/Domain/Order.php" "$d/$A/Order.php"
printf '%s\n' 'Shop/Infrastructure/ApiPlatform/OrderView.php -> Shop/Infrastructure/ApiPlatform/Output' \
  'Shop/Domain/Order.php -> Shop/Infrastructure/ApiPlatform' > "$d/map.txt"
out=$(run "$d" --apply "$d/map.txt"); check "a target that exists among good lines is refused whole" $? 1 "$out" "exists already"
[[ ! -e "$d/$A/Output/OrderView.php" ]]; check "and moves none of them" $? 0 "" ""

d=$(tree)
printf 'garbage line\n' > "$d/map.txt"
out=$(run "$d" "$d/map.txt"); check "a line that is no mapping is refused" $? 1 "$out" "garbage line"

echo "move-classes: $pass passed, $fail failed"
((fail == 0))
