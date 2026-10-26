# SPDX-License-Identifier: AGPL-3.0-or-later
# Sourced by the deploy and on-premises overlay gates: Gotenberg's Chromium renders HTML the application writes from what
# people typed, so one escaping mistake would hand it markup. It is shut in, so that markup can do nothing: JavaScript off,
# no URL but its own working files, no download or webhook of its own, and on a network of its own (`pdf`, internal: no
# way out) that only the api and the two workers join. Every line printed is a problem.

# The flags each installation starts Gotenberg with, as compose.yaml's `command:` gives them.
PDF_RENDERER_FLAGS=(--chromium-disable-javascript "--chromium-allow-list=^file:///tmp/" --chromium-deny-private-ips
  --chromium-deny-public-ips --api-disable-download-from --webhook-disable --libreoffice-disable-routes)
# Who sends pages to Gotenberg: the api renders on request, the workers when they issue and mail.
PDF_RENDERER_CLIENTS=(api worker demo-worker)

pdf_renderer_shut_in() { # the merged configuration, as `docker compose config --format json` prints it
  local config=$1 flag client
  jq -e '.networks.pdf.internal == true' <<<"$config" >/dev/null \
    || echo "the pdf network is not internal: Gotenberg's Chromium could reach out of it"
  jq -e '(.services.gotenberg.networks // {} | keys) == ["pdf"]' <<<"$config" >/dev/null \
    || echo "gotenberg is on [$(jq -r '.services.gotenberg.networks // {} | keys | join(", ")' <<<"$config")]: it belongs on the pdf network alone"
  for client in "${PDF_RENDERER_CLIENTS[@]}"; do
    jq -e --arg s "$client" '.services[$s].networks // {} | has("pdf")' <<<"$config" >/dev/null \
      || echo "$client is not on the pdf network: it could not reach Gotenberg"
  done
  jq -r --argjson clients "$(printf '%s\n' "${PDF_RENDERER_CLIENTS[@]}" gotenberg | jq -R . | jq -s .)" '.services | to_entries[]
    | select((.value.networks // {} | has("pdf")) and (.key as $k | $clients | index($k) | not))
    | "\(.key) joins the pdf network: only the api and the workers send pages to Gotenberg"' <<<"$config"
  for flag in "${PDF_RENDERER_FLAGS[@]}"; do
    jq -e --arg f "$flag" '.services.gotenberg.command // [] | index($f)' <<<"$config" >/dev/null \
      || echo "gotenberg starts without $flag"
  done
}
