# SPDX-License-Identifier: AGPL-3.0-or-later
# twes reset, sourced by scripts/onprem/twes after scripts/onprem/lib/export.sh, whose export it writes before anything
# goes: the installation taken back to a new one, its operator made again, nothing else in it.
#
# What it holds is counted first (app:fiscal:inventory --summary). Holding nothing fiscal, the installation is emptied in
# place (app:platform:reset, its schema kept). Holding issued documents or their ledger, it is never emptied in place, as
# the database keeps an issued invoice while its keeping time runs and nothing releases it for a reset: it is refused
# unless --with-issued-documents, then the installation's name and the sentence naming the counts are typed, and its
# database and files volumes are removed whole, the edge's authority and the backups' folder kept. Either way, under a
# hold of its own: an export kept aside beside the values, read back with its passphrase before anything is removed,
# carrying the reset's own record; the backups moved aside in their folder, never deleted, and a new stanza begun, a
# base backup made at once; the record written as a line outside the volumes (resets.log beside the values), as the
# journal goes with the rest. A reset cut short after it began keeps the hold: its export takes the shop back.

# The volumes a reset holding issued documents removes: the database, the stored files, what the api shares between
# its workers, the archive's spool and the nightly dump. The edge's authority (edge-data, edge-config), which the shop's
# phones trust, the backups' own folder (backup-local) and their outcomes stay.
reset_volumes=(postgres-data api-files api-share pgbackrest-spool backup-dump)
reset_record='' reset_held='' reset_began='' reset_archive=''

json_string() { local s=${1//\\/\\\\}; s=${s//\"/\\\"}; printf '"%s"' "$s"; }

reset_installation() { # --with-issued-documents or nothing
  local acknowledged=${1:-} kept summary code counted fiscal companies project typed sentence email first second passphrase stamp at aside mode emptied volume
  kept=$(dirname "$values")/kept-aside
  mkdir -p "$kept"; chmod 700 "$kept"
  export_ready "$kept" "no reset"

  code=0
  summary=$(compose exec -T api bin/console app:fiscal:inventory --summary --no-interaction) || code=$?
  ((code == 0 || code == 3)) && [[ "$summary" =~ fiscal-records=([0-9]+)\ fiscal-companies=([0-9]+)\ companies=([0-9]+) ]] \
    || refuse "what the installation holds could not be counted (app:fiscal:inventory exited $code): nothing reset"
  counted=${BASH_REMATCH[1]} fiscal=${BASH_REMATCH[2]} companies=${BASH_REMATCH[3]}
  project=$(compose config --no-interpolate | sed -n 's/^name: //p' | head -n 1)
  [[ "$project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || refuse "the installation's compose project could not be read: nothing reset"
  if ((code == 3)); then
    [[ "$acknowledged" == --with-issued-documents ]] \
      || refuse "the installation holds $counted fiscal records in $fiscal of $companies companies (issued documents and their ledger, which the law keeps): it is reset only knowingly, twes reset --with-issued-documents, its name and the counts typed back"
    mode=volumes
  else
    mode=in-place
  fi
  say "reset: $companies companies here, $counted fiscal records in $fiscal of them; an export is kept aside in $kept first"
  typed=$(ask "Type the installation's name, $project, to reset it:")
  [[ "$typed" == "$project" ]] || refuse "the name typed is not $project: nothing reset"
  if [[ "$mode" == volumes ]]; then
    sentence="erase $counted fiscal records in $fiscal of $companies companies of $project"
    typed=$(ask "Issued documents are erased with their volumes. Type « $sentence » to go on:")
    [[ "$typed" == "$sentence" ]] || refuse "the sentence typed was not « $sentence »: nothing reset"
  fi
  # Asked before anything goes, so a typo never leaves the installation without its operator.
  email=$(recall operator)
  [[ -n "$email" ]] || email=$(address_asked "The platform operator's mail address (the developer's sign-in):")
  first=$(ask "The password of the operator made again, $email:" secret); second=$(ask "Again:" secret)
  [[ -n "$first" && "$first" == "$second" ]] || refuse "the two passwords differ: nothing reset"
  passphrase=$(export_passphrase) || exit 1

  # Given back if it ends before anything went; kept once it began, so neither tick nor a start runs a half-reset
  # installation: the export kept aside takes it back.
  trap 'rm -rf "$export_work" "$export_plain" "$export_check_work" "$reset_record"; reset_ended' EXIT
  hold on >/dev/null
  reset_held=yes
  stamp=$(date -u -d "@$(now)" +%Y%m%d-%H%M%S) at=$(date -u -d "@$(now)" +%Y-%m-%dT%H:%M:%SZ)
  aside=before-reset-$stamp
  reset_record=$(mktemp -t twes-reset.XXXXXX)
  printf '{"scope":"installation","mode":"%s","installation":%s,"by":%s,"at":"%s","companies":%d,"fiscalCompanies":%d,"fiscalRecords":%d,"backupsAside":"%s","volumesRemoved":%s}\n' \
    "$mode" "$(json_string "$project")" "$(json_string "$(id -un)")" "$at" "$companies" "$fiscal" "$counted" "$aside" \
    "$([[ "$mode" == volumes ]] && printf '["%s"]' "$(IFS=,; echo "${reset_volumes[*]}" | sed 's/,/","/g')" || echo '[]')" > "$reset_record"

  export_write "$kept" '' "$passphrase" "$reset_record"
  reset_archive=$export_archive
  export_check "$reset_archive" "$passphrase" > /dev/null
  say "reset: the export kept aside reads back whole: $reset_archive"
  printf '%s began %s export=%s\n' "$at" "$(cat "$reset_record")" "$reset_archive" >> "$(dirname "$values")/resets.log"
  reset_began=yes

  if [[ "$mode" == in-place ]]; then
    emptied=$(printf '%s\n' "$project" | compose run -T --rm --no-deps migrate bin/console app:platform:reset --force \
      "--installation=$project" "--by=$(id -un)" --json) || refuse "app:platform:reset did not empty the installation: read its answer above"
    say "reset: emptied in place $emptied"
  fi
  compose down || refuse "the installation could not be stopped"
  # Moved in their own folder, so the archive and every backup stay where the restore commands look for them.
  compose run -T --rm --no-deps restore sh -c "set -e; mkdir -p /backup/$aside; for d in pgbackrest restic; do if [ -e /backup/\$d ]; then mv /backup/\$d /backup/$aside/; fi; done" < /dev/null \
    || refuse "the backups could not be moved aside in their folder"
  say "reset: the backups moved aside, under $aside in their folder"
  if [[ "$mode" == volumes ]]; then
    for volume in "${reset_volumes[@]}"; do
      ! docker volume inspect "${project}_$volume" > /dev/null 2>&1 || docker volume rm "${project}_$volume" > /dev/null \
        || refuse "the volume ${project}_$volume could not be removed"
    done
    say "reset: removed the volumes ${reset_volumes[*]} of $project"
  fi
  LAN_HOST=$(cat "$state/lan-host") bash "$deploy" --again "$values" || refuse "the installation did not start again"
  printf '%s\n' "$first" | compose exec -T api bin/console app:seed --no-interaction "--operator-email=$email" \
    --operator-password-stdin --without-company >&2 || refuse "the application did not make the operator: read its answer above"
  echo "$email" > "$state/operator"
  compose exec -T backup twes-backup init || refuse "the backups' new stanza was not made: read the backup service's answer above"
  compose exec -T backup twes-backup before-update | sed -n 's/.*before-update: //p' | grep -q . \
    || refuse "the first backup after the reset was not made: read the backup service's answer above"

  printf '%s done %s export=%s\n' "$(date -u -d "@$(now)" +%Y-%m-%dT%H:%M:%SZ)" "$(cat "$reset_record")" "$reset_archive" >> "$(dirname "$values")/resets.log"
  reset_began=''
  say "reset: $project is a new installation, its operator $email; the export kept aside is $reset_archive, its passphrase the one just typed"
}

reset_ended() {
  [[ -n "$reset_held" ]] || return 0
  if [[ -n "$reset_began" ]]; then
    say "the reset stopped after it began: the installation stays held; ${reset_archive:-the export kept aside} takes it back (docs/RUNBOOK.md « Restore a shop »), then twes hold off" >&2
  else
    hold off > /dev/null
  fi
}
