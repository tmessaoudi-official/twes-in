#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
ORACLE=$ROOT/scripts/stress/chaos-oracle.py
CHAOS=$ROOT/scripts/stress/chaos.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
echo "chaos"

# A run of two sales, each drafted, issued and paid, the api's kill landing on the first sale's issue, the database's
# on the second's payment, sent again after a lost answer; the worker killed in between.
run() {
  local d; d=$(mktemp -d)
  printf '%s\n' \
    $'1\t1\tdraft\t1\tk1\t201\t1000\t1100\t' \
    $'1\t1\tissue\t1\tk2\t000\t1200\t1500\t' \
    $'1\t1\tissue\t2\tk2\t409\t1600\t1700\t' \
    $'1\t1\tpay\t1\tk3\t201\t1800\t1900\t' \
    $'1\t1\tdone\t0\tinv-1\t201\t1950\t10.000' \
    $'1\t2\tdraft\t1\tk4\t201\t3000\t3100\t' \
    $'1\t2\tissue\t1\tk5\t200\t3200\t3300\t' \
    $'1\t2\tpay\t1\tk6\t000\t5000\t5600\t' \
    $'1\t2\tpay\t2\tk6\t201\t6000\t6100\t' \
    $'1\t2\tdone\t0\tinv-2\t201\t6200\t10.000' > "$d/ledger.tsv"
  printf 'api\t1300\t1550\nworker\t2000\t2500\npostgres\t5100\t5900\n' > "$d/faults.tsv"
  printf 'app:data:check\t0\napp:invoices:verify-chain\t0\n' > "$d/checks.tsv"
  cat > "$d/facts.json" <<'EOF'
{"invoices": [{"id": "inv-1", "status": "paid", "number": "F-1"}, {"id": "inv-2", "status": "paid", "number": "F-2"}],
 "payments": [{"invoice": "inv-1", "amount": "10.000"}, {"invoice": "inv-2", "amount": "10.000"}],
 "onhand": "-2.000"}
EOF
  echo "$d"
}
edit() { python3 -I -c 'import json,sys; p=sys.argv[1]; d=json.load(open(p)); exec(sys.argv[2]); json.dump(d, open(p, "w"))' "$1/facts.json" "$2"; }
judge() { python3 -I "$ORACLE" "$1" "${2:-2}" 2>&1; }

d=$(run); out=$(judge "$d"); check "a run whose every sale was kept once is green" $? 0 "$out" "every sale kept once"

d=$(run); edit "$d" 'd["payments"].append({"invoice": "inv-2", "amount": "10.000"})'; out=$(judge "$d")
check "a payment sent again and kept twice is red" $? 1 "$out" "inv-2 holds 2 payments, wanted exactly one"

d=$(run); edit "$d" 'd["payments"].pop()'; out=$(judge "$d")
check "a payment the ledger saw made but the database lost is red" $? 1 "$out" "inv-2 holds 0 payments"

d=$(run); edit "$d" 'd["payments"][0]["amount"] = "9.000"'; out=$(judge "$d")
check "a payment of another amount than was due is red" $? 1 "$out" "payment of 9.000, wanted 10.000"

d=$(run); edit "$d" 'd["invoices"].append({"id": "inv-3", "status": "issued", "number": "F-3"})'; out=$(judge "$d")
check "an invoice issued that no seller saw through is red" $? 1 "$out" "inv-3 (F-3) is issued for the run"

d=$(run); edit "$d" 'd["invoices"][1]["status"] = "draft"'; out=$(judge "$d")
check "a sale paid in the ledger but a draft in the database is red" $? 1 "$out" "inv-2 was paid in the ledger"

d=$(run); edit "$d" 'd["invoices"][1]["number"] = "F-1"'; out=$(judge "$d")
check "a number given twice is red" $? 1 "$out" "the number F-1 was given twice"

d=$(run); edit "$d" 'd["onhand"] = "-3.000"'; out=$(judge "$d")
check "stock moved other than once per invoice is red" $? 1 "$out" "stands at -3.000, wanted -2"

d=$(run); sed -i 's/\t409\t/\t500\t/' "$d/ledger.tsv"; out=$(judge "$d")
check "a fault answered 500 is red" $? 1 "$out" "answered 500"

d=$(run); sed -i '0,/\t201\t1000/s//\t422\t1000/' "$d/ledger.tsv"; out=$(judge "$d")
check "a draft refused for what it said is red" $? 1 "$out" "draft attempt 1 answered 422"

d=$(run); out=$(judge "$d" 3); check "fewer sales than the floor is red: the faults met too little" $? 1 "$out" "under the floor of 3"

d=$(run); printf 'api\t9000\t9500\nworker\t2000\t2500\npostgres\t5100\t5900\n' > "$d/faults.tsv"; out=$(judge "$d")
check "a kill no request was in flight for is red" $? 1 "$out" "no request was in flight when the api was killed"

d=$(run); printf 'api\t1300\t1550\npostgres\t5100\t5900\n' > "$d/faults.tsv"; out=$(judge "$d")
check "a fault that never happened is red" $? 1 "$out" "the worker was never killed"

d=$(run); sed -i 's/\tpay\t2\tk6\t201\t6000\t6100\t/\tpay\t2\tk6\t409:in-progress\t6000\t6050\t\n1\t2\tpay\t3\tk6\t201\t6060\t6100\t/' "$d/ledger.tsv"; out=$(judge "$d")
check "a payment sent again while the first still held its key, then made, is green" $? 0 "$out" "every sale kept once"

d=$(run); sed -i 's/\tpay\t2\tk6\t201\t/\tpay\t2\tk6\t409:unanswered\t/' "$d/ledger.tsv"; out=$(judge "$d")
check "a payment made whose answer was lost, read as made, is green" $? 0 "$out" "every sale kept once"

d=$(run); sed -i 's/\tpay\t1\tk3\t201\t/\tpay\t1\tk3\t409:in-progress\t/' "$d/ledger.tsv"; out=$(judge "$d")
check "a first payment answered as still being worked on is red: its key was new" $? 1 "$out" "pay attempt 1 answered 409:in-progress"

d=$(run); sed -i 's/\tk5\t200\t/\tk5\t422\t/' "$d/ledger.tsv"; out=$(judge "$d")
check "an issue refused for what it said is red" $? 1 "$out" "issue attempt 1 answered 422"

d=$(run); sed -i 's/\tk5\t200\t/\tk5\t409\t/' "$d/ledger.tsv"; out=$(judge "$d")
check "a first issue answered 409 is red: only an issue sent again may find it done" $? 1 "$out" "issue attempt 1 answered 409"

d=$(run); printf 'api\t1300\t1550\nworker\t2000\t2500\npostgres\t5100\t7000\n' > "$d/faults.tsv"; out=$(judge "$d")
check "no sale paid after the last fault came back is red" $? 1 "$out" "no sale was paid after the last fault came back"

d=$(run); printf 'app:data:check\t1\napp:invoices:verify-chain\t0\n' > "$d/checks.tsv"; out=$(judge "$d")
check "a data check finding is red" $? 1 "$out" "app:data:check exited 1"

d=$(run); printf 'app:data:check\t0\n' > "$d/checks.tsv"; out=$(judge "$d")
check "a chain never verified is red" $? 1 "$out" "app:invoices:verify-chain never ran"

out=$(COMPOSE_PROJECT_NAME=twes-in bash "$CHAOS" 2>&1); check "chaos refuses another compose project, the live stack's first" $? 2 "$out" "it runs as twes-stress only"
out=$(bash "$CHAOS" --everything 2>&1); check "chaos refuses an argument it does not know" $? 2 "$out" "unknown argument --everything"

echo "chaos: $pass passed, $fail failed"
((fail == 0))
