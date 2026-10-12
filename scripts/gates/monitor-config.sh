#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What the monitor promises (docs/START.md § 15), read from infra/monitor/:
#   ports      only the edge publishes, on 80, 443 and 443/udp: Docker's published ports pass by the host's firewall
#   required   every value has no default, so a monitor missing one stops before starting anything
#   sign-up    GlitchTip lets nobody sign up or open an organisation
#   logs       every service rotates its output, 10 MB × 5
#   public     the status page is public, so every line of gatus.yaml hides its address, host, port, conditions and
#              errors, and alerts by mail and Telegram
#   names      a name is plain letters, digits and spaces (Gatus takes no heartbeat for an accented one), and the backups'
#              heartbeats are exactly the ones infra/postgres/backup/twes-backup sends
#   HSTS       infra/monitor/Caddyfile sends max-age=31536000 to each of its two hosts, no includeSubDomains, no preload
# gatus.yaml is read line by line, in the shape it is written: a line per endpoint (`  - name:`), each pointing at the
# `&alerts` and `&hidden` anchors the first one sets. No YAML parser is on the licence job.
# Usage: monitor-config.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }
finish() {
  if ((${#problems[@]})); then
    printf 'monitor-config: FAIL — %d problem(s) with the monitor (docs/START.md § 15):\n' "${#problems[@]}"
    printf '  %s\n' "${problems[@]}"
    exit 1
  fi
  printf 'monitor-config: OK — only the edge publishes, %d values required, sign-up closed, %d lines hidden and alerting\n' "${#REQUIRED[@]}" "$lines"
  exit 0
}

REQUIRED=(TWES_HOST STATUS_HOST ERRORS_HOST EDGE_TLS MONITOR_TOKEN MONITOR_HEARTBEAT_TOKEN ALERT_SMTP_HOST ALERT_SMTP_PORT
  ALERT_SMTP_USERNAME ALERT_SMTP_PASSWORD ALERT_FROM ALERT_TO TELEGRAM_TOKEN TELEGRAM_CHAT_ID GLITCHTIP_SECRET_KEY
  GLITCHTIP_DB_PASSWORD)
lines=0
monitor=$root/infra/monitor
for f in "$monitor/compose.yaml" "$monitor/gatus.yaml" "$monitor/Caddyfile" "$root/infra/postgres/backup/twes-backup"; do
  [[ -f "$f" ]] || problem "found no ${f#"$root"/}"
done
((${#problems[@]})) && finish

env_file=$(mktemp); trap 'rm -f "$env_file"' EXIT
compose() {
  printf '%s=gate\n' "$@" > "$env_file"
  (cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose --env-file "$env_file" -f infra/monitor/compose.yaml config --format json 2>&1)
}
config=$(compose "${REQUIRED[@]}") || { problem "docker compose config failed: $config"; finish; }
for name in "${REQUIRED[@]}"; do
  without=()
  for other in "${REQUIRED[@]}"; do [[ "$other" == "$name" ]] || without+=("$other"); done
  out=$(compose "${without[@]}")
  if [[ $? -eq 0 || "$out" != *"$name"* ]]; then problem "$name is not required: a monitor without it would start on a default"; fi
done

while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | (select(.key != "edge" and ((.value.ports // []) | length) > 0)
     | "\(.key) publishes \([.value.ports[] | "\(.published // "?"):\(.target)"] | join(", ")): only the edge may"),
    (select(.value.logging.driver != "json-file" or .value.logging.options["max-size"] != "10m"
       or .value.logging.options["max-file"] != "5")
     | "\(.key) does not rotate its output at 10m × 5 (logging: *logging)")' <<<"$config")
edge_ports=$(jq -r '[.services.edge.ports // [] | .[] | "\(.published):\(.target)/\(.protocol // "tcp")"] | sort | join(" ")' <<<"$config")
[[ "$edge_ports" == "443:443/tcp 443:443/udp 80:80/tcp" ]] || problem "the edge publishes [$edge_ports], wanted 80, 443 and 443/udp"
for key in ENABLE_USER_REGISTRATION ENABLE_ORGANIZATION_CREATION; do
  got=$(jq -r --arg k "$key" '.services.glitchtip.environment[$k] // "(unset)"' <<<"$config")
  [[ "$got" == false ]] || problem "glitchtip $key is $got: a stranger could make an account on the error tracker"
done

# One record per endpoint: section, name, whether it points at the hidden set, whether it alerts.
records=$(awk '
  /^[a-z-]+:/ { section = $1; sub(/:$/, "", section) }
  /^  - name: / { if (name != "") print kind "\t" name "\t" hides "\t" alerts; kind = section; name = substr($0, 11); hides = 0; alerts = 0; next }
  /^    ui: [*&]hidden$/ { hides = 1 }
  /^    alerts: [*&]alerts$/ { alerts = 1 }
  END { if (name != "") print kind "\t" name "\t" hides "\t" alerts }
' "$monitor/gatus.yaml")
hidden=$(awk '/^    ui: &hidden$/ { on = 1; next } on && /^      hide-[a-z]+: true$/ { sub(/^ +/, ""); sub(/: true$/, ""); print; next } on { exit }' "$monitor/gatus.yaml" | sort | tr '\n' ' ')
for flag in hide-conditions hide-errors hide-hostname hide-port hide-url; do
  [[ " $hidden" == *" $flag "* ]] || problem "gatus.yaml's &hidden set lacks $flag: true, so every line shows that detail on the public page"
done
expected=()
while IFS=$'\t' read -r section name hides alerts; do
  [[ -z "$section" ]] && continue
  lines=$((lines + 1))
  [[ "$name" =~ ^[A-Za-z0-9\ ]+$ ]] || problem "gatus.yaml line \"$name\" is not plain letters, digits and spaces: Gatus takes no heartbeat for an accented name"
  [[ "$section" == endpoints && "$hides" != 1 ]] && problem "gatus.yaml line $name does not hide its details (ui: *hidden) on the public page"
  [[ "$alerts" == 1 ]] || problem "gatus.yaml line $name alerts nobody (alerts: *alerts)"
  [[ "$section" == external-endpoints ]] && expected+=("$name")
done <<<"$records"
((lines > 0)) || problem "gatus.yaml has no line at all: read in the shape it is written, one '  - name:' per line"
sent=$(grep -oE "trap [\"']heartbeat [A-Za-z]+ " "$root/infra/postgres/backup/twes-backup" | awk '{print $3}' | sort -u | tr '\n' ' ')
taken=$(printf '%s\n' "${expected[@]}" | sort -u | tr '\n' ' ')
[[ "$sent" == "$taken" ]] || problem "the backups send heartbeats [${sent% }] and gatus.yaml takes [${taken% }]: each must be the other"

caddy=$monitor/Caddyfile
hsts=$(grep -cE '^[[:space:]]*header Strict-Transport-Security' "$caddy")
exact=$(grep -cE '^[[:space:]]*header Strict-Transport-Security "max-age=31536000"$' "$caddy")
[[ "$hsts" == 2 && "$exact" == 2 ]] || problem "infra/monitor/Caddyfile must send exactly Strict-Transport-Security \"max-age=31536000\" for each of its two hosts (no includeSubDomains, no preload)"
finish
