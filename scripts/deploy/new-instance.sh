#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# A new instance's values (docs/START.md § 14): the file scripts/deploy/deploy.sh hands to compose. It fills every secret
# with random bytes of this instance's own and leaves every fact (the name, the mail relay, the bucket) blank for the
# operator, since a guessed fact deploys to the wrong place. Readable by its owner alone, never overwritten: a second run
# on an existing file would replace the keys a live database is encrypted under.
# --staging writes a staging instance's (docs/RUNBOOK.md): DEPLOY_ENV=staging, which its error reports carry and
# deploy.sh --staging requires, and its backups under the prefix staging/, so they never land where production's are.
# --onprem writes a shop's (docs/START.md § 16): TWES_INSTALL=onprem, its backups in its own folder with the off-site
# copy off, updates announced rather than applied, and no name, since the shop opens localhost and its network address,
# which scripts/onprem/twes reads at each start.
# Usage: new-instance.sh [--staging | --onprem] <file>      (such as /etc/twes-in/deploy.env, outside the checkout)
set -euo pipefail
usage() { echo "Usage: new-instance.sh [--staging | --onprem] <file>" >&2; exit 2; }
kind=production
case "${1:-}" in
  --staging) kind=staging; shift ;;
  --onprem) kind=onprem; shift ;;
esac
[[ $# -eq 1 && "$1" != -* ]] || usage
file=$1
[[ -e "$file" ]] && { echo "new-instance: $file exists; it holds a live instance's keys, so it is never written over" >&2; exit 1; }

# Letters and digits only, so a value sits as it is in a URL (the database's) or a shell line.
word() { local out=''; while ((${#out} < $1)); do out+=$(head -c 256 /dev/urandom | tr -dc 'A-Za-z0-9'); done; printf '%s' "${out:0:$1}"; }

umask 077
mkdir -p "$(dirname "$file")"
if [[ "$kind" == onprem ]]; then
  cat > "$file" <<'EOF'
# This shop's values (docs/START.md § 16). Read by scripts/onprem/twes and scripts/deploy/deploy.sh, never committed.
TWES_INSTALL=onprem

# The facts, whoever installs it gives them.
# The shop's mail account, through which invitations, documents and alerts leave. For Gmail, an app password
# (two-step verification on, then Google Account > Security > App passwords), the @ of the address written %40:
#   MAILER_DSN=smtp://shop%40gmail.com:<app password>@smtp.gmail.com:587
#   MAILER_SENDER=shop@gmail.com
MAILER_DSN=
MAILER_SENDER=
# Who is mailed when an update fails or rolls back, a backup stops or the application turns unhealthy.
ALERT_EMAIL=
# Where the backups go: blank keeps them in a Docker volume on this disk; a folder on a second disk or a USB drive
# (such as /mnt/d/twes-backups under Windows) survives this disk.
BACKUP_DIRECTORY=
# The off-site copy, on once the bucket below is given: twes offsite on.
BACKUP_OFFSITE=off
BACKUP_S3_ENDPOINT=
BACKUP_S3_BUCKET=
BACKUP_S3_REGION=
BACKUP_S3_KEY=
BACKUP_S3_SECRET=
# BACKUP_S3_PORT=443
# BACKUP_S3_URI_STYLE=host
# BACKUP_S3_PREFIX=<shop>/
# Updates: notify (the owner is told, someone runs « twes update »), auto (applied inside the window, in the shop's
# time, such as 13:00-14:00) or off. Changed with: twes policy auto 13:00-14:00 | notify | off
UPDATE_POLICY=notify
UPDATE_WINDOW=
# The monitor, optional here (docs/START.md § 15): its status page and the DSN its error tracker gave this shop.
MONITOR_URL=
SENTRY_DSN=
# A fixed network address, when the router reserves one for this machine; otherwise twes reads it at each start.
# LAN_HOST=192.168.1.20
EOF
else
  cat > "$file" <<'EOF'
# This instance's values (docs/START.md § 14). Passed to compose by scripts/deploy/deploy.sh, never committed.

# The facts, the operator's to give.
# The name this instance answers on; its DNS record points at this machine. Passkeys are bound to it: it never changes.
TWES_HOST=
# The address the certificate authority (Let's Encrypt) writes to before a certificate expires.
EDGE_TLS=
# The mail relay, such as smtp://user:pass@relay.example:587, and the address mails come from.
MAILER_DSN=
MAILER_SENDER=
# The off-site bucket the backups go to (docs/START.md § 13), at another provider than this machine.
BACKUP_S3_ENDPOINT=
BACKUP_S3_BUCKET=
BACKUP_S3_REGION=
BACKUP_S3_KEY=
BACKUP_S3_SECRET=
# BACKUP_S3_PORT=443
# BACKUP_S3_URI_STYLE=host
# The monitor, on a machine of its own (docs/START.md § 15): its status page's address, such as https://status.example,
# and the DSN its error tracker gave this instance (infra/monitor/bootstrap.py prints it).
MONITOR_URL=
SENTRY_DSN=
EOF
fi
cat >> "$file" <<EOF

# The secrets, made for this instance alone.
APP_SECRET=$(word 48)
APP_MFA_KEY=$(head -c 32 /dev/urandom | base64)
REALTIME_TOKEN_KEY=$(word 48)
REALTIME_API_KEY=$(word 48)
POSTGRES_PASSWORD=$(word 40)
# The application's own database role; the owner's password above reaches only the migrate service.
POSTGRES_APP_PASSWORD=$(word 40)
BACKUP_DATABASE_PASSPHRASE=$(word 48)
BACKUP_FILES_PASSPHRASE=$(word 48)
# What the monitor reads the instance's health with, and what the backups' heartbeats carry: both copied to its values.
MONITOR_TOKEN=$(word 48)
MONITOR_HEARTBEAT_TOKEN=$(word 48)
EOF
if [[ "$kind" == staging ]]; then
  cat >> "$file" <<'EOF'

# Staging: what its error reports and deploy.sh --staging read, and where in its bucket its backups go. The bucket above
# is staging's own, at keys that reach nothing of production's.
DEPLOY_ENV=staging
BACKUP_S3_PREFIX=staging/
EOF
fi
chmod 600 "$file"

cat <<EOF
new-instance: wrote $file (mode 600). Fill in its facts, then keep a copy of these, apart from this machine and from
the backups, where nobody but you can read them (a password manager, and a sealed copy offline):
  APP_MFA_KEY                  without it, every authenticator enrolled is lost, even from a restored backup
  BACKUP_DATABASE_PASSPHRASE   without it, no database backup can be read
  BACKUP_FILES_PASSPHRASE      without it, no copy of the files can be read
  APP_SECRET                   signs the links and tokens the instance hands out; a new one voids those
  POSTGRES_PASSWORD            the restored database still answers to it
The realtime keys can be made again at any time. Copy MONITOR_TOKEN and MONITOR_HEARTBEAT_TOKEN to the monitor's
values (scripts/monitor/new-monitor.sh); a new pair is two edits, one on each side.
EOF
if [[ "$kind" == staging ]]; then
  echo "This is staging's: give it a bucket and keys of its own, never production's, and never production's data."
fi
