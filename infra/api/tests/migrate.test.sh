#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The migrate service's script (infra/api/migrate.sh) against a stand-in console standing for a database just restored
# from a backup: it answers reads at once, as a server replaying its WAL in hot standby does, and refuses anything that
# writes until it is promoted, a few tries later. The migrations and the application role's grants both write.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

mkdir -p "$TMPDIR/bin" "$TMPDIR/app/bin" "$TMPDIR/app/migrations"
touch "$TMPDIR/app/migrations/Version1.php"
# bin/console as the script runs it (`php bin/console …`): a probe counts as one try; promoted once PROMOTED_AFTER tries
# have been made, or never when it is "never".
cat > "$TMPDIR/bin/php" <<'EOF'
#!/usr/bin/env bash
shift
tries=$(cat "$TMPDIR/tries" 2>/dev/null || echo 0)
promoted() { [[ "$PROMOTED_AFTER" != never ]] && ((tries >= PROMOTED_AFTER)); }
case "$*" in
  dbal:run-sql*)
    statement=${*: -1}
    echo "$statement" >> "$TMPDIR/probes"
    tries=$((tries + 1)); echo "$tries" > "$TMPDIR/tries"
    if [[ "$statement" == *"SELECT 1"* && "$statement" != *pg_current_xact_id* ]]; then exit 0; fi
    promoted || { echo "SQLSTATE[25006]: Read only sql transaction: cannot assign TransactionIds during recovery" >&2; exit 1; } ;;
  doctrine:migrations:migrate*|app:database:app-role*)
    promoted || { echo "SQLSTATE[25006]: cannot execute in a read-only transaction" >&2; exit 1; }
    echo "${1}" >> "$TMPDIR/ran" ;;
esac
EOF
printf '#!/usr/bin/env bash\n:\n' > "$TMPDIR/bin/sleep"
chmod +x "$TMPDIR/bin/"*

migrate() { (cd "$TMPDIR/app" && rm -f "$TMPDIR/tries" "$TMPDIR/probes" "$TMPDIR/ran" && PATH="$TMPDIR/bin:$PATH" PROMOTED_AFTER=$1 sh "$HERE/migrate.sh" 2>&1); }

out=$(migrate 3); check "a database still in recovery is waited for until it takes writes" $? 0 "$(cat "$TMPDIR/ran" 2>/dev/null)" "doctrine:migrations:migrate"
check "  then the role's grants" 0 0 "$(cat "$TMPDIR/ran" 2>/dev/null)" "app:database:app-role"
check "  each try a statement only a writable server answers" 0 0 "$(sort -u "$TMPDIR/probes")" "pg_current_xact_id"
check "  three tries" 0 0 "$(wc -l < "$TMPDIR/probes" | tr -d ' ')" "3"
out=$(migrate 1); check "a server that takes writes at once is not waited for" $? 0 "$(wc -l < "$TMPDIR/probes" | tr -d ' ')" "1"
out=$(migrate never); check "a server that never takes writes: refused after a minute's tries, saying why" $? 1 "$out" "cannot assign TransactionIds during recovery"
[[ ! -e "$TMPDIR/ran" ]]; check "  and nothing run" $? 0 "" ""

echo "migrate: $pass passed, $fail failed"
((fail == 0))
