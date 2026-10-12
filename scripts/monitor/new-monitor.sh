#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A new monitor's values (docs/START.md § 15): the file infra/monitor/compose.yaml is run with. It fills the monitor's own
# secrets with random bytes and leaves every fact blank for the operator, since a guessed fact watches the wrong place.
# The two tokens are the instance's: copied from its values file, so the monitor reads what the instance answers to.
# Readable by its owner alone, never overwritten: a second run would replace the key GlitchTip's sessions are signed with.
# Usage: new-monitor.sh <file>      (such as /etc/twes-monitor/monitor.env, outside the checkout)
set -euo pipefail
[[ $# -eq 1 ]] || { echo "Usage: new-monitor.sh <file>" >&2; exit 2; }
file=$1
[[ -e "$file" ]] && { echo "new-monitor: $file exists; it holds a live monitor's keys, so it is never written over" >&2; exit 1; }

# Letters and digits only, so a value sits as it is in a URL (the database's) or a shell line.
word() { local out=''; while ((${#out} < $1)); do out+=$(head -c 256 /dev/urandom | tr -dc 'A-Za-z0-9'); done; printf '%s' "${out:0:$1}"; }

umask 077
mkdir -p "$(dirname "$file")"
cat > "$file" <<EOF
# This monitor's values (docs/START.md § 15). Passed to compose with --env-file, never committed.

# The facts, the operator's to give.
# The instance watched, and the two tokens from its values file.
TWES_HOST=
MONITOR_TOKEN=
MONITOR_HEARTBEAT_TOKEN=
# The names the status page and the error tracker answer on; their DNS records point at this machine.
STATUS_HOST=
ERRORS_HOST=
# The address the certificate authority (Let's Encrypt) writes to before a certificate expires.
EDGE_TLS=
# The alerts' mail relay: an account of its own, not the instance's, so alerts still go out when the instance's is the
# trouble. STARTTLS on its port unless ALERT_SMTP_STARTTLS=false.
ALERT_SMTP_HOST=
ALERT_SMTP_PORT=587
ALERT_SMTP_USERNAME=
ALERT_SMTP_PASSWORD=
ALERT_FROM=
ALERT_TO=
# The Telegram bot that alerts (from @BotFather), and the chat it writes to.
TELEGRAM_TOKEN=
TELEGRAM_CHAT_ID=

# The secrets, made for this monitor alone.
GLITCHTIP_SECRET_KEY=$(word 50)
GLITCHTIP_DB_PASSWORD=$(word 40)
EOF
chmod 600 "$file"

echo "new-monitor: wrote $file (mode 600). Fill in its facts, the two tokens copied from the instance's values file."
