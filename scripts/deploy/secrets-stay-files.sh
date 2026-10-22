# SPDX-License-Identifier: AGPL-3.0-or-later
# Sourced by the checks that boot an installation for real (make deploy-check, make onprem-check): the running
# containers hold none of the installation's secrets in their environment, where `docker inspect` shows it to whoever
# reaches the Docker socket, only as files under /run/secrets (docs/SPEC.md § 7, Docker secrets). The secrets are the
# ones the merged configuration mounts, each value read from the values file. The caller defines `dc` (its compose,
# given the values file) and `fail`.
# shellcheck shell=bash
secrets_stay_files() { # values file
  local merged name value container env count=0
  merged=$(dc config) || fail "docker compose config failed"
  declare -A wanted=()
  while IFS= read -r name; do
    value=$(awk -v k="$name" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2) } END { print v }' "$1")
    [[ -n "$value" ]] && wanted[$name]=$value
  done < <(awk '/^[^ #]/ { top = $0 } top == "secrets:" && $1 == "environment:" { print $2 }' <<<"$merged")
  ((${#wanted[@]})) || fail "the configuration mounts no secret with a value"
  for container in $(dc ps -q); do
    env=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container")
    for name in "${!wanted[@]}"; do
      [[ "$env" != *"${wanted[$name]}"* ]] \
        || fail "$(docker inspect --format '{{.Name}}' "$container") holds the value of $name in its environment"
    done
    count=$((count + 1))
  done
  ((count)) || fail "no container is running to read"
  echo "secrets stay files: ${#wanted[@]} secrets in none of $count containers' environments"
}
