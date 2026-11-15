#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The chaos harness's seller (scripts/stress/chaos.sh): signs in as the demo dataset's owner, opens Carthage Conseil,
# then several sellers in parallel each draft an invoice of one unit of the run's product, issue it and record its
# payment in full, again and again, while chaos.sh kills the api, the worker and the database under them, until chaos.sh
# writes /out/stop once the last fault came back: a fixed count of sales ended before the first kill. Every
# attempt is a line of /out/ledger.tsv; an attempt with no answer, or a server's 5xx, is sent again with the same
# Idempotency-Key once the api answers its health check, as the web's submission interceptor does. The oracle
# (chaos-oracle.py) then holds what the database kept to that ledger.
#
#   chaos-driver.sh [sellers] [most sales per seller]
set -uo pipefail

# The api is addressed as localhost and reached at its container: the session cookie is Secure, which curl sends over
# plain HTTP to localhost alone, and the CSRF check holds the Origin to the address the request was sent to.
API=http://localhost
ORIGIN=$API
TO=(--connect-to "localhost:80:${CHAOS_API_HOST:-api}:80")
EMAIL=owner@twes.local
PASSWORD=twes-role-test-2026
COMPANY_NAME='Carthage Conseil'
SELLERS=${1:-3}
SALES=${2:-2000}
OUT=/out
JAR=$OUT/session.jar
CSRF=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
LEDGER=$OUT/ledger.tsv

now() { date +%s%3N; }
uuid() { cat /proc/sys/kernel/random/uuid; }
die() { echo "chaos-driver: $*" >&2; exit 1; }

# One request: sets CODE (000 when nothing answered) and BODY. A 409 about the key itself is written with its problem,
# 409:in-progress or 409:unanswered, which the oracle tells from a refusal.
send() {
  local method=$1 path=$2 body=${3:-} key=${4:-} out
  local args=("${TO[@]}" -sS --max-time 30 -b "$JAR" -X "$method" -H "Origin: $ORIGIN" -H "csrf-token: $CSRF" -H 'Content-Type: application/json' -H 'Accept: application/json')
  [[ -n "$key" ]] && args+=(-H "Idempotency-Key: $key")
  [[ -n "$body" ]] && args+=(--data "$body")
  out=$(curl "${args[@]}" -w '\n%{http_code}' "$API$path" 2>/dev/null) || true
  CODE=${out##*$'\n'}
  BODY=${out%$'\n'*}
  [[ "$CODE" =~ ^[0-9]{3}$ ]] || CODE=000
  if [[ "$CODE" == 409 ]]; then
    case "$(jq -r '.type? // empty' <<<"$BODY" 2>/dev/null)" in
      /problems/submission-in-progress) CODE=409:in-progress ;;
      /problems/submission-unanswered) CODE=409:unanswered ;;
    esac
  fi
}

healthy() {
  local i
  for i in $(seq 1 240); do
    [[ "$(curl "${TO[@]}" -sS --max-time 3 -o /dev/null -w '%{http_code}' "$API/api/health" 2>/dev/null)" == 200 ]] && return 0
    sleep 0.5
  done
  return 1
}

# A write asked until it is answered by something other than the network or a server fault: each attempt a ledger line.
# A key still held by an attempt the api lost is taken back once older than app.long_request.max_seconds (240 s): the
# web says it and the person sends again, as this does every 10 s.
#   seller sale step attempt key code started ended
asked() {
  local seller=$1 sale=$2 step=$3 method=$4 path=$5 body=$6 key=$7 attempt t0
  for attempt in $(seq 1 40); do
    t0=$(now)
    send "$method" "$path" "$body" "$key"
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$seller" "$sale" "$step" "$attempt" "$key" "$CODE" "$t0" "$(now)" >> "$LEDGER.$seller"
    case "$CODE" in
      000|500|502|503|504) healthy || return 1 ;;
      409:in-progress) sleep 10 ;;
      *) return 0 ;;
    esac
  done
  return 1
}

sign_in() {
  local code company proof
  code=$(curl "${TO[@]}" -sS -c "$JAR" -o /dev/null -w '%{http_code}' -H "Origin: $ORIGIN" -H "csrf-token: $CSRF" -H 'Content-Type: application/json' \
    --data "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" "$API/api/auth/login") || true
  [[ "$code" == 200 ]] || die "signing in as $EMAIL answered $code"
  send GET /api/me/companies
  company=$(jq -r --arg name "$COMPANY_NAME" '(if type == "array" then . else .member end) | map(select(.name == $name))[0].companyId // empty' <<<"$BODY")
  [[ -n "$company" ]] || die "$EMAIL is in no company named $COMPANY_NAME: $BODY"
  send POST /api/me/company "{\"companyId\":\"$company\"}"
  [[ "$CODE" == 2* ]] || die "opening $COMPANY_NAME answered $CODE: $BODY"
  send GET /api/auth/me
  proof=$(jq -r '.companyProof // empty' <<<"$BODY")
  if [[ -n "$proof" ]]; then
    send POST "/api/companies/$company/proof" "{\"password\":\"$PASSWORD\"}"
    [[ "$CODE" == 204 ]] || die "proving who is at the screen for $COMPANY_NAME answered $CODE: $BODY"
  fi
  COMPANY=$company
}

# The run's own customer and product, so its sales are told apart from the dataset's. An invoice names its customer by
# a billing address, without which its issue is refused.
the_run() {
  local run units unit
  run=$(date +%s)
  send POST "/api/companies/$COMPANY/customers" "{\"number\":\"CHAOS-$run\",\"kind\":\"individual\",\"taxRegime\":\"standard\",\"name\":\"Chaos $run\",\"billingAddressLine1\":\"1 rue du Lac\",\"billingCity\":\"Tunis\",\"billingCountryCode\":\"TN\"}" "$(uuid)"
  [[ "$CODE" == 201 ]] || die "creating the run's customer answered $CODE: $BODY"
  CUSTOMER=$(jq -r .id <<<"$BODY")
  send GET "/api/companies/$COMPANY/product-options"
  units=$BODY
  unit=$(jq -r '(.units | map(select(.code == "C62"))[0] // .units[0]).id' <<<"$units")
  send POST "/api/companies/$COMPANY/products" "{\"reference\":\"CHAOS-$run\",\"name\":\"Chaos $run\",\"kind\":\"goods\",\"unitId\":\"$unit\",\"unitPriceNet\":\"10\"}" "$(uuid)"
  [[ "$CODE" == 201 ]] || die "creating the run's product answered $CODE: $BODY"
  PRODUCT=$(jq -r .id <<<"$BODY")
  printf '{"company":"%s","customer":"%s","product":"%s"}\n' "$COMPANY" "$CUSTOMER" "$PRODUCT" > "$OUT/run.json"
}

sell() {
  local seller=$1 sale id key status due day
  for sale in $(seq 1 "$SALES"); do
    [[ -e "$OUT/stop" ]] && break
    asked "$seller" "$sale" draft POST "/api/companies/$COMPANY/invoices" \
      "{\"customerId\":\"$CUSTOMER\",\"customerReference\":\"S$seller-$sale\",\"lines\":[{\"productId\":\"$PRODUCT\",\"quantity\":\"1\",\"unitPriceNet\":\"10\",\"taxComponentIds\":[]}]}" "$(uuid)" || return 1
    # A draft made whose answer was lost cannot be named: it stays a draft, which nothing judges.
    [[ "$CODE" == 201 ]] || { echo "seller $seller sale $sale: the draft answered $CODE: $BODY" >&2; continue; }
    id=$(jq -r .id <<<"$BODY")
    key=$(uuid)
    asked "$seller" "$sale" issue POST "/api/companies/$COMPANY/invoices/$id/issue" 'null' "$key" || return 1
    # The issue takes no Idempotency-Key: sent again after a lost answer, it finds the invoice issued already (409), and
    # the seller reads the invoice as a person's screen would.
    [[ "$CODE" == 200 || "$CODE" == 409 ]] || { echo "seller $seller sale $sale: the issue of $id answered $CODE: $BODY" >&2; continue; }
    send GET "/api/companies/$COMPANY/invoices/$id"
    status=$(jq -r .status <<<"$BODY"); due=$(jq -r .amountDue <<<"$BODY"); day=$(jq -r .issueDate <<<"$BODY")
    [[ "$status" != draft ]] || { echo "seller $seller sale $sale: $id still a draft after its issue" >&2; continue; }
    asked "$seller" "$sale" pay POST "/api/companies/$COMPANY/invoices/$id/payments" \
      "{\"date\":\"$day\",\"amount\":\"$due\",\"method\":\"cash\"}" "$(uuid)" || return 1
    [[ "$CODE" == 201 || "$CODE" == 409:unanswered ]] || { echo "seller $seller sale $sale: the payment of $id answered $CODE: $BODY" >&2; continue; }
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$seller" "$sale" done 0 "$id" "$CODE" "$(now)" "$due" >> "$LEDGER.$seller"
  done
}

healthy || die "the api never answered its health check"
sign_in
the_run
for seller in $(seq 1 "$SELLERS"); do sell "$seller" & done
failed=0
for job in $(jobs -p); do wait "$job" || failed=$((failed + 1)); done
cat "$LEDGER".* > "$LEDGER"
rm -f "$LEDGER".* "$JAR"
echo "chaos-driver: $SELLERS sellers, $(grep -c $'\tdone\t' "$LEDGER") paid; $failed seller(s) stopped"
((failed == 0))
