#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# How often staging asks the registry whether master published a newer commit (docs/RUNBOOK.md), an install value:
# STAGING_FOLLOW_INTERVAL in /etc/twes-in/staging-follow.env, five minutes when unset. A systemd timer reads no
# environment, so this writes the value as a drop-in over infra/staging/twes-staging-follow.timer; run it again, then
# `systemctl daemon-reload`, after changing the value. Below a minute is refused: a run pulls three images and may deploy,
# and the timer already waits for one run to end before the next. The hold file and the rule that a commit which failed to
# start is not tried again stay staging-follow.sh's own.
# Usage: staging-follow-interval.sh <staging-follow.env> <drop-in directory, /etc/systemd/system/twes-staging-follow.timer.d>
set -euo pipefail

main() {
  [[ $# -eq 2 ]] || { echo "Usage: staging-follow-interval.sh <staging-follow.env> <drop-in directory>" >&2; return 2; }
  local values=$1 directory=$2 interval
  # The last line naming it wins, as systemd reads an EnvironmentFile; the file is read, never run.
  interval=$(sed -n 's/^STAGING_FOLLOW_INTERVAL=//p' "$values" | tail -n 1)
  interval=${interval:-5min}

  # Seconds, minutes or hours, a whole number of them: what both this and systemd read the same way.
  if [[ ! "$interval" =~ ^([1-9][0-9]{0,5})(s|min|h)$ ]]; then
    echo "staging-follow-interval: refused — STAGING_FOLLOW_INTERVAL is a whole number of s, min or h, such as 5min; « $interval » is not" >&2
    return 1
  fi
  local seconds=${BASH_REMATCH[1]}
  case ${BASH_REMATCH[2]} in
    min) seconds=$((seconds * 60)) ;;
    h) seconds=$((seconds * 3600)) ;;
  esac
  if ((seconds < 60)); then
    echo "staging-follow-interval: refused — STAGING_FOLLOW_INTERVAL is at least 1min; « $interval » is not" >&2
    return 1
  fi

  mkdir -p "$directory"
  # The empty line clears the timer's own five minutes: OnUnitInactiveSec adds to a list rather than replacing it.
  printf '%s\n' \
    "# Written by scripts/deploy/staging-follow-interval.sh from STAGING_FOLLOW_INTERVAL in $values: run it again after a change." \
    '[Timer]' \
    'OnUnitInactiveSec=' \
    "OnUnitInactiveSec=$interval" > "$directory/interval.conf"
  echo "staging-follow-interval: staging asks the registry every $interval ($directory/interval.conf)"
}
main "$@"
