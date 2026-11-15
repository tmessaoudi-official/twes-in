#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The chaos harness: a whole stack of its own (compose project `twes-stress`, scripts/stress/chaos.compose.yaml, no port
# published), the demo dataset loaded, sellers drafting, issuing and paying invoices in a loop (chaos-driver.sh) while
# the api, the worker and the database are killed under them, one at a time, and started again. Then the data checks
# and the invoices' chains run, and chaos-oracle.py holds what the database kept to what the sellers asked: every sale
# kept once, numbered once, paid once, its stock moved once. Its run folder is var/stress/chaos-<time>.
#
#   chaos.sh [--sabotage] [--keep]
#     --sabotage  plants a second payment on one paid invoice before the judge reads: the run must then go red
#     --keep      leaves the stack up afterwards, to read it; `docker compose -p twes-stress … down --volumes` ends it
#
# The images are built from the working tree, untracked files included, as every compose build here is. It takes the
# gate's lock: never beside a gate (`make chaos`).
set -euo pipefail

PROJECT=twes-stress
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "chaos: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
export COMPOSE_PROJECT_NAME=$PROJECT
export COMPOSE_FILE="$root/compose.yaml:$root/scripts/stress/chaos.compose.yaml"
export COMPOSE_PROFILES=
unset LAN_ORIGIN LAN_HOST

sabotage=0 keep=0
for arg in "$@"; do
  case "$arg" in
    --sabotage) sabotage=1 ;;
    --keep) keep=1 ;;
    *) echo "chaos: unknown argument $arg (--sabotage, --keep)" >&2; exit 2 ;;
  esac
done

SELLERS=${CHAOS_SELLERS:-3}
# The sellers sell until the faults are over; SALES only bounds a seller that never hears it, and the floor is the
# least work the faults must have met to be judged.
SALES=${CHAOS_SALES:-2000}
FLOOR=${CHAOS_FLOOR:-$((SELLERS * 20))}
CHAOS_OUT="$root/var/stress/chaos-$(date +%Y%m%d-%H%M%S)"
export CHAOS_OUT CHAOS_UID=$(id -u) CHAOS_GID=$(id -g)
mkdir -p "$CHAOS_OUT"

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'chaos: %-66s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "chaos: FAIL — $*" >&2; exit 1; }
dc() { (cd "$root" && docker compose "$@"); }
ms() { date +%s%3N; }

# Everything below may kill and remove: only once compose itself says the project, and every volume, is this one's.
config=$(dc config --format json) || fail "compose could not read its files"
[[ "$(jq -r .name <<<"$config")" == "$PROJECT" ]] || fail "compose would act on project $(jq -r .name <<<"$config"), not $PROJECT"
foreign=$(jq -r --arg p "${PROJECT}_" '.volumes // {} | to_entries[] | select((.value.name // "") | startswith($p) | not) | .key' <<<"$config")
[[ -z "$foreign" ]] || fail "volumes outside $PROJECT: $foreign"
[[ -z "$(jq -r '[.services[] | select(.ports != null and (.ports | length) > 0)] | length | select(. > 0)' <<<"$config")" ]] || fail "a service publishes a port"

cleanup() {
  local status=$?
  # A red run keeps what explains it: the containers' whole output, and the api's and the worker's own log files, which
  # live in the containers and go with them.
  if ((status != 0)); then
    dc logs --no-color api worker postgres > "$CHAOS_OUT/stack.log" 2>&1 || true
    for name in api worker; do docker cp "${PROJECT}-$name-1:/app/var/log" "$CHAOS_OUT/$name-log" >/dev/null 2>&1 || true; done
  fi
  if ((keep == 0)); then dc --profile chaos down --volumes --remove-orphans >/dev/null 2>&1 || true; fi
  exit "$status"
}
trap cleanup EXIT

dc build api >"$CHAOS_OUT/build.log" 2>&1 || fail "building the api image (see $CHAOS_OUT/build.log)"
step "the api image built from the working tree"
dc up -d --wait postgres mailpit centrifugo gotenberg api worker >"$CHAOS_OUT/up.log" 2>&1 || fail "the stack did not come up (see $CHAOS_OUT/up.log)"
step "the stack up: postgres, the api, the worker, Gotenberg, Centrifugo, Mailpit"
dc exec -T api bin/console app:seed --operator-password=twes-operator-dev --operator-totp-secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP </dev/null >"$CHAOS_OUT/seed.log" 2>&1 || fail "app:seed (see $CHAOS_OUT/seed.log)"
dc exec -T api bin/console doctrine:fixtures:load --append --no-interaction </dev/null >>"$CHAOS_OUT/seed.log" 2>&1 || fail "the demo dataset (see $CHAOS_OUT/seed.log)"
step "the demo dataset loaded"

dc --profile chaos run --rm -T chaos-driver scripts/stress/chaos-driver.sh "$SELLERS" "$SALES" </dev/null >"$CHAOS_OUT/driver.log" 2>&1 &
driver=$!
for _ in $(seq 1 120); do [[ -s "$CHAOS_OUT/run.json" ]] && break; sleep 1; done
[[ -s "$CHAOS_OUT/run.json" ]] || fail "the sellers never started (see $CHAOS_OUT/driver.log)"

# One fault at a time, each started again by hand: a container Docker killed is not trusted to come back by itself.
: > "$CHAOS_OUT/faults.tsv"
fault() {
  local name=$1 at
  sleep "${2:-12}"
  at=$(ms)
  docker kill --signal KILL "${PROJECT}-$name-1" >/dev/null
  sleep 3
  dc up -d --wait "$name" >>"$CHAOS_OUT/up.log" 2>&1 || fail "the $name did not come back"
  printf '%s\t%s\t%s\n' "$name" "$at" "$(ms)" >> "$CHAOS_OUT/faults.tsv"
  step "the $name killed mid-work and started again"
}
fault api
fault worker
fault postgres
# A database killed takes the api's connections with it: the api, its worker threads holding them, must find a new one.
fault api 8
# The sellers go on a moment after the last fault came back, so that a sale made afterwards proves the stack recovered.
sleep 5
: > "$CHAOS_OUT/stop"

wait "$driver" || fail "the sellers stopped (see $CHAOS_OUT/driver.log)"
step "$(tail -n 1 "$CHAOS_OUT/driver.log")"

: > "$CHAOS_OUT/checks.tsv"
for check in app:data:check app:invoices:verify-chain; do
  code=0
  dc exec -T api bin/console "$check" </dev/null >"$CHAOS_OUT/${check//:/-}.log" 2>&1 || code=$?
  printf '%s\t%s\n' "$check" "$code" >> "$CHAOS_OUT/checks.tsv"
done
step "the data checks and the invoices' chains run"

company=$(jq -r .company "$CHAOS_OUT/run.json") customer=$(jq -r .customer "$CHAOS_OUT/run.json") product=$(jq -r .product "$CHAOS_OUT/run.json")
psql() { dc exec -T postgres psql -U twes -d twes -v ON_ERROR_STOP=1 -At "$@" </dev/null; }
if ((sabotage == 1)); then
  # Every column of one of the run's payments, but its id: the same payment kept twice.
  columns=$(psql -c "SELECT string_agg(quote_ident(column_name), ', ' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'payment' AND column_name <> 'id'")
  psql -c "INSERT INTO payment (id, $columns) SELECT gen_random_uuid(), $columns FROM payment WHERE invoice_id = (SELECT p.invoice_id FROM payment p JOIN invoice i ON i.id = p.invoice_id WHERE i.customer_id = '$customer' ORDER BY p.id LIMIT 1) LIMIT 1" >/dev/null \
    || fail "the sabotage could not plant its payment"
  step "sabotage: a second payment planted"
fi
psql -c "
  SELECT json_build_object(
    'invoices', (SELECT coalesce(json_agg(json_build_object('id', id, 'status', status, 'number', number) ORDER BY id), '[]') FROM invoice WHERE company_id = '$company' AND customer_id = '$customer'),
    'payments', (SELECT coalesce(json_agg(json_build_object('invoice', p.invoice_id, 'amount', p.amount) ORDER BY p.id), '[]') FROM payment p JOIN invoice i ON i.id = p.invoice_id WHERE i.customer_id = '$customer'),
    'onhand', (SELECT sum(quantity)::text FROM stock_movement WHERE product_id = '$product'))" > "$CHAOS_OUT/facts.json" || fail "reading the run back from the database"

code=0
dc --profile chaos run --rm -T chaos-driver -c "python3 -I scripts/stress/chaos-oracle.py /out $FLOOR" </dev/null >"$CHAOS_OUT/oracle.log" 2>&1 || code=$?
cat "$CHAOS_OUT/oracle.log"
if ((sabotage == 1)); then
  ((code != 0)) && grep -q 'payments, wanted exactly one' "$CHAOS_OUT/oracle.log" || fail "the planted payment went unseen"
  step "sabotage seen: the run is red, as it must be"
  exit 0
fi
((code == 0)) || fail "the judge found what is above (run folder $CHAOS_OUT)"
step "judged: nothing kept twice, nothing lost (run folder $CHAOS_OUT)"
