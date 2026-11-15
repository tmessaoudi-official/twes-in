# SPDX-License-Identifier: AGPL-3.0-or-later
# twes export and twes verify-export, sourced by scripts/onprem/twes, whose helpers (say, refuse, ask, compose, value,
# running, tag_of, hold, on_hold) and folders (values, state, records, keys) they use.
#
# One archive carries what moving the installation to another computer needs and no backup alone holds: the backup
# folder with a final backup made for it, the values (every key slot among them, APP_MFA_KEY first) sealed with a
# passphrase of the person's own (gpg --symmetric), the pinned release keys, the deployment record, the script's state,
# the edge's local authority sealed the same way when there is one, and the images with --with-images. Its MANIFEST
# names the release, PostgreSQL's major, the last migration, the backup's label, the key slots' names, the counts of
# what the database holds (app:fiscal:inventory) and the sha256 of every member; MANIFEST.gpg is the MANIFEST sealed with
# the same passphrase, so only who holds it could have written a MANIFEST that verifies: the archive's MAC.

# What every archive holds; ca.tar.gpg and images.tar only when there is a local authority, or when asked for.
export_members=(counts.json values.env.gpg backup.tar releases.tar state.tar release-keys.tar)
export_optional=(ca.tar.gpg images.tar)
# Read by the EXIT trap, after the function's own variables are gone.
export_work='' export_held='' export_plain=''

# A passphrase reaches gpg through a file of mode 600 and a keyring of its own, never the command line nor the person's
# own keyring; the file and the keyring go with the call.
export_gpg() { # passphrase, then gpg's arguments
  local home pass status=0 secret=$1; shift
  home=$(mktemp -d -t twes-export-gpg.XXXXXX)
  pass=$home/pass
  (umask 077 && printf '%s' "$secret" > "$pass")
  GNUPGHOME=$home gpg --batch --quiet --no-tty --no-symkey-cache --pinentry-mode loopback --passphrase-file "$pass" "$@" || status=$?
  GNUPGHOME=$home gpgconf --kill all 2>/dev/null || true # gpgconf may find no agent to stop: nothing was started then
  rm -rf "$home"
  return "$status"
}
export_seal() { export_gpg "$1" --symmetric --cipher-algo AES256 --output "$3" "$2"; } # passphrase, file, sealed file
export_open() { export_gpg "$1" --decrypt --output "$3" "$2"; }                         # passphrase, sealed file, file

export_passphrase() {
  local first second
  first=$(ask "A passphrase to seal the values and the authority with (12 characters at least; the new computer asks it):" secret)
  ((${#first} >= 12)) || refuse "a passphrase of 12 characters at least: nothing exported"
  second=$(ask "Again:" secret)
  [[ "$first" == "$second" ]] || refuse "the two passphrases differ: nothing exported"
  printf '%s' "$first"
}

# The names of the values that are keys, never their values: what a move must carry or lose every secret sealed under it.
export_key_slots() {
  awk -F= '$1 ~ /^[A-Z0-9_]+$/ && $1 ~ /(_KEY|_SECRET|_PASSPHRASE|_PASSWORD|_TOKEN)$/ && $2 != "" { print $1 }' "$values" | sort -u | tr '\n' ' ' | sed 's/ $//'
}

export_installation() { # folder, --with-images or nothing
  local folder=$1 images=${2:-} present=() list=() why commit tag label major migration counts code slots stamp work archive passphrase member
  [[ -d "$folder" && -w "$folder" ]] || refuse "$folder is not a folder this account can write: nothing exported"
  commit=$(running)
  [[ -n "$commit" ]] || refuse "nothing is deployed here: there is nothing to export"
  ! on_hold || refuse "$(hold_said): no export until twes hold off"
  why=$(backup_folder_ready) || refuse "$why: no export without it, as an export backs up first"
  slots=$(export_key_slots)
  [[ " $slots " == *" APP_MFA_KEY "* ]] || refuse "the values hold no APP_MFA_KEY: an export without it loses every authenticator"
  passphrase=$(export_passphrase) || exit 1
  tag=$(tag_of "$commit")
  stamp=$(date -u -d "@$(now)" +%Y%m%d-%H%M%S)
  umask 077
  work=$(mktemp -d "$folder/.twes-export.XXXXXX")
  export_work=$work
  # Held while it reads, so neither tick nor an update restarts or moves the installation under it; the hold is given
  # back however the export ends, and anything recorded after the final backup stays here. An export cut short (the
  # computer switched off, the process killed) leaves its mark, this boot and this process, by which on_hold gives the
  # hold back rather than keep the shop shut.
  trap 'rm -rf "$export_work" "$export_plain"; [[ -z "$export_held" ]] || { rm -f "$state/export-hold"; hold off >/dev/null; }' EXIT
  hold on >/dev/null
  export_held=yes
  printf '%s %s\n' "$(cat /proc/sys/kernel/random/boot_id)" "$$" > "$state/export-hold"

  say "export: a final backup first"
  label=$(compose exec -T backup twes-backup before-update | sed -n 's/.*before-update: //p' | tail -n 1) || label=''
  [[ "$label" =~ ^[0-9]{8}-[0-9]{6}F(_[0-9]{8}-[0-9]{6}[DI])?$ ]] || refuse "the final backup was not made: nothing exported"
  major=$(compose exec -T postgres sh -c 'cat "$PGDATA/PG_VERSION"' | tr -d '[:space:]') || major=''
  [[ "$major" =~ ^[0-9]+$ ]] || refuse "PostgreSQL's major version could not be read: nothing exported"
  migration=$(compose exec -T api bin/console doctrine:migrations:current --no-interaction | tr -d '[:space:]') || migration=''
  [[ -n "$migration" ]] || refuse "the database's last migration could not be read: nothing exported"
  code=0
  counts=$(compose exec -T api bin/console app:fiscal:inventory --json --no-interaction) || code=$?
  ((code == 0 || code == 3)) || refuse "the database could not be counted (app:fiscal:inventory exited $code): nothing exported"
  printf '%s\n' "$counts" > "$work/counts.json"

  compose exec -T backup tar -C /backup -cf - . > "$work/backup.tar" || refuse "the backup folder could not be read: nothing exported"
  # Nothing secret is ever written plain where the archive goes, a USB stick as often as not, where neither a mode nor
  # a delete keeps the bytes: the values are sealed from their own file, the authority from this computer's TMPDIR.
  export_seal "$passphrase" "$values" "$work/values.env.gpg" || refuse "gpg could not seal the values: nothing exported"
  tar -C "$records" -cf "$work/releases.tar" .
  # The export's own hold stays here: a computer given this state would otherwise start held.
  tar -C "$state" --exclude='./*.lock' --exclude=./hold --exclude=./export-hold -cf "$work/state.tar" .
  tar -C "$keys" --exclude='./S.*' -cf "$work/release-keys.tar" .
  # The edge's local authority, which the shop's phones trust: carried, sealed, so they keep trusting the new computer.
  # An edge that never made one answers tar's « No such file », said nowhere: there is no authority to carry then.
  export_plain=$(mktemp -t twes-export-ca.XXXXXX)
  if compose exec -T edge tar -C /data/caddy/pki/authorities -cf - local > "$export_plain" 2>/dev/null && [[ -s "$export_plain" ]]; then
    export_seal "$passphrase" "$export_plain" "$work/ca.tar.gpg" || refuse "gpg could not seal the authority: nothing exported"
  fi
  rm -f "$export_plain"
  if [[ "$images" == --with-images ]]; then
    mapfile -t list < <(compose config --images)
    ((${#list[@]})) || refuse "the release's images could not be listed: nothing exported"
    docker save -o "$work/images.tar" "${list[@]}" || refuse "docker could not save the images: nothing exported"
  fi

  {
    echo "format twes-export 1"
    echo "created $(date -u -d "@$(now)" +%Y-%m-%dT%H:%M:%SZ)"
    echo "host $(uname -n)"
    echo "release ${tag:--} $commit"
    echo "postgres-major $major"
    echo "migration $migration"
    echo "backup-label $label"
    echo "key-slots $slots"
    for member in "${export_members[@]}" "${export_optional[@]}"; do
      [[ ! -f "$work/$member" ]] || echo "sha256 $(sha256sum "$work/$member" | cut -d' ' -f1) $member"
    done
  } > "$work/MANIFEST"
  export_seal "$passphrase" "$work/MANIFEST" "$work/MANIFEST.gpg" || refuse "gpg could not seal the MANIFEST: nothing exported"

  archive=$folder/twes-export-$(uname -n)-$stamp.tar
  present=()
  for member in "${export_members[@]}" "${export_optional[@]}"; do [[ ! -f "$work/$member" ]] || present+=("$member"); done
  tar -C "$work" -cf "$archive.part" MANIFEST MANIFEST.gpg "${present[@]}"
  mv -f "$archive.part" "$archive"
  (cd "$folder" && sha256sum "$(basename "$archive")" > "$(basename "$archive").sha256")
  say "exported to $archive (sha256 in $(basename "$archive").sha256): the backup $label, release ${tag:-$commit}, PostgreSQL $major; keep the passphrase apart from the archive, the new computer asks it"
}

# The archive read back: its MANIFEST unsealed with the passphrase and equal to the one beside it, every member it
# names present with its sha256, every required member there, and nothing it does not name. Only the two MANIFESTs are
# unpacked; each member is read through sha256sum where it lies, as a shop's backups may be larger than TMPDIR.
verify_export() { # archive
  local archive=$1 work passphrase member name sum inside=() listed=() problems=()
  [[ -f "$archive" ]] || refuse "$archive is not a file"
  mapfile -t inside < <(tar -tf "$archive") && ((${#inside[@]})) || refuse "$archive is not an archive tar reads"
  [[ " ${inside[*]} " == *" MANIFEST "* && " ${inside[*]} " == *" MANIFEST.gpg "* ]] || refuse "$archive holds no sealed MANIFEST: not a twes export"
  work=$(mktemp -d -t twes-verify.XXXXXX)
  export_work=$work
  trap 'rm -rf "$export_work"' EXIT
  tar -C "$work" -xf "$archive" MANIFEST MANIFEST.gpg || refuse "the MANIFEST of $archive could not be read"
  passphrase=$(ask "The export's passphrase:" secret) || exit 1
  export_open "$passphrase" "$work/MANIFEST.gpg" "$work/MANIFEST.opened" || refuse "the passphrase does not open the MANIFEST: a wrong passphrase, or an archive changed"
  cmp -s "$work/MANIFEST" "$work/MANIFEST.opened" || problems+=("the MANIFEST was changed after it was sealed")
  while read -r sum name; do
    [[ -n "$name" ]] || continue
    listed+=("$name")
    if [[ " ${inside[*]} " != *" $name "* ]]; then problems+=("$name is named by the MANIFEST and missing")
    elif [[ "$(tar -xOf "$archive" "$name" | sha256sum | cut -d' ' -f1)" != "$sum" ]]; then problems+=("$name was changed after the MANIFEST")
    fi
  done < <(awk '$1 == "sha256" { print $2, $3 }' "$work/MANIFEST.opened")
  for member in "${export_members[@]}"; do
    [[ " ${listed[*]} " == *" $member "* ]] || problems+=("$member is missing from the MANIFEST")
  done
  for member in "${inside[@]}"; do
    case "$member" in MANIFEST | MANIFEST.gpg) continue ;; esac
    [[ " ${listed[*]} " == *" $member "* ]] || problems+=("$member is in the archive and not in the MANIFEST")
  done
  if ((${#problems[@]})); then
    printf 'twes: %s\n' "${problems[@]}" >&2
    refuse "$archive does not verify: ${#problems[@]} problem(s) above"
  fi
  grep -v '^sha256 ' "$work/MANIFEST.opened"
  say "verified: $archive, ${#listed[@]} members as sealed"
}
