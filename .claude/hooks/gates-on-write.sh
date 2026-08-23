#!/usr/bin/env bash
# PostToolUse hook: re-run the CHEAP REAL GATES whose subject the file Claude just wrote
# belongs to. Exit 2 feeds their own messages back so the defect is fixed in the same turn.
#
# THIS HOOK OWNS NO RULES. Every check below is `bash`/`php` on a script in `scripts/gates/`
# — the same invocation `composer gate` makes, with the same exit code and the same message.
# That is the whole design, and it is the lesson of this session's previous commit ("call the
# consumer's own parser instead of a second, worse one"): a write-time copy of a gate's rules
# is a second rule set that drifts from the first, and § Gotchas records four separate
# instances of exactly that. The most a hook may own is the ROUTING — which gate to run for
# which file — and even that is deliberately sloppy, see below.
#
# ROUTING IS BIASED TOWARDS OVER-RUNNING, which is the opposite of this repository's usual
# fail-closed instinct and therefore needs its reason stated. Under-routing is harmless: the
# gate still runs in `composer gate` before anything is committed, so the worst case is that a
# defect is found thirty seconds later instead of now. Over-routing costs milliseconds. So
# when in doubt this hook runs the gate. Concretely: EVERY `.php` write runs the whole
# architecture set rather than working out whether the path is under `Domain/`, because the
# five of them together are 290ms [measured] and a per-gate scope test here would be a
# duplicate of each gate's own scope — the very thing the paragraph above forbids.
#
# WHAT IS DELIBERATELY NOT HERE, with the measurement that decided it:
#   * `test-gates.sh` — 48s, and it is the gates' own test suite rather than a gate. It runs
#     in `composer gate`, last.
#   * `schema-tenancy.php` — needs a migrated PostgreSQL database. A hook that fails because
#     the container's cluster is down (which § Gotchas records as routine here) would block
#     every write for a reason that is not a defect.
#   * `compose-config.sh` — needs `docker compose` and renders three configurations.
#   * `shell-syntax.sh` — REDUNDANT here, not skipped: `lint-on-write.sh` already runs
#     `bash -n` on the one file that changed, which is the same check with a narrower subject.
#
# `worker-mode-blocked.sh` IS here despite being 3196ms [measured], because it is the one
# control CLAUDE.md says must never skip, and it only fires on config writes — a `.env`, a
# compose file, a Dockerfile, a Caddyfile, a Makefile or a tracked `.json`. Those are rare
# edits, and three seconds on a rare edit buys the check that five previous versions of that
# control were defeated for lacking.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# SUPPRESSIONS AND FALLBACKS, ACCOUNTED FOR. `jq … 2>/dev/null` and
# `git rev-parse … 2>/dev/null` are the same two legitimate conditions `lint-on-write.sh`
# documents — an unknown payload shape and a non-repository — each handled by the guard beneath
# it, with the diagnostic kept out of the channel Claude reads findings from. `git read-tree
# HEAD 2>/dev/null` is the test for "this repository has a commit", and its failure branch is
# explicit. **There is no `|| true` anywhere in this file, and the one place the obvious spelling
# would have put one is where the throwaway index is built — see the comment there for why it is
# a bandaid that would silently reintroduce the exact defect the block closes.**
set -uo pipefail

input="$(cat)"
file="$(jq -r '.tool_input.file_path // empty' <<<"$input" 2>/dev/null)"
[[ -n "$file" ]] || exit 0

repo="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null)}"
[[ -n "$repo" && -d "$repo" ]] || exit 0
cd "$repo" || exit 0

# A DELETED file still matters — `locale-key-parity.php` and the SPDX coverage check both read
# the tracked SET, so removing a catalogue is exactly as interesting as adding one. So this
# resolves the path WITHOUT requiring the file to exist, unlike `lint-on-write.sh`, whose
# subject is the file's own contents.
case "$file" in
  /*) abs="$file" ;;
  *)  abs="$repo/$file" ;;
esac
case "$abs" in "$repo"/*) rel="${abs#"$repo"/}" ;; *) exit 0 ;; esac

case "$rel" in
  */vendor/*|vendor/*|*/node_modules/*|node_modules/*|*/build/*|build/*|*/var/*|var/*|*/.dart_tool/*) exit 0 ;;
esac

# The gates to run, as "label:command" so a failure can name itself. Built as a list rather
# than run inline so that EVERY applicable gate reports, not just the first — a write can
# introduce two defects and being told about one of them is how the second survives a turn.
gates=()

case "$rel" in
  *.php)
    gates+=(
      "layer-dependencies:php scripts/gates/layer-dependencies.php"
      "no-ambient-calls-in-domain:php scripts/gates/no-ambient-calls-in-domain.php"
      "no-orm-attributes-in-domain:bash scripts/gates/no-orm-attributes-in-domain.sh"
      "no-orphaned-docblocks:php scripts/gates/no-orphaned-docblocks.php"
      "no-owner-connection-in-application:php scripts/gates/no-owner-connection-in-application.php"
    )
    ;;
esac

# SPDX runs for every extension the gate itself has in scope, and that list is READ FROM THE
# GATE rather than restated here — `--dump-rules` exists for exactly this, and a hand-copied
# extension list would be stale the next time one is added (`ini` in August, `py` the day
# after). 420ms [measured] for the whole tree.
spdx_extensions="$(bash scripts/gates/spdx-headers.sh --dump-rules 2>/dev/null | sed -n 's/^extensions //p')"
if [[ -z "$spdx_extensions" ]]; then
  # THE DUMP FAILING ROUTES *TOWARDS* RUNNING THE GATE, not away from it — the one place in
  # this hook where the bias is stated as code rather than as a comment. An empty extension
  # list means the introspection broke, and treating that as "no extensions match" would turn a
  # broken derivation into a silent skip: the vacuity shape § Gotchas records four times. The
  # gate is 420ms and decides for itself whether the file is in scope, so running it needlessly
  # costs nothing that matters.
  gates+=("spdx-headers:bash scripts/gates/spdx-headers.sh")
else
  for ext in $spdx_extensions; do
    if [[ "$rel" == *."$ext" ]]; then
      gates+=("spdx-headers:bash scripts/gates/spdx-headers.sh")
      break
    fi
  done
fi

# The translation catalogues. Named by DIRECTORY rather than by the `messages.<locale>.xlf`
# spelling, because a new domain (`validators.fr.xlf`) is precisely the case where parity is
# most likely to be broken and a filename pattern would not see it.
case "$rel" in
  api/translations/*) gates+=("locale-key-parity:php scripts/gates/locale-key-parity.php") ;;
esac

case "$rel" in
  Makefile|*/Makefile) gates+=("makefile-conventions:bash scripts/gates/makefile-conventions.sh") ;;
esac

# The dependency manifests and locks, both directions: a manifest edit is what precedes a lock
# change, and invariant 8(a) is owed at the moment a dependency is ADDED rather than resolved.
case "$rel" in
  */composer.json|composer.json|*/composer.lock|composer.lock \
  |*/package.json|package.json|*/package-lock.json|package-lock.json \
  |*/pubspec.yaml|pubspec.yaml|*/pubspec.lock|pubspec.lock \
  |THIRD-PARTY-NOTICES.md)
    gates+=("dependency-licences:php scripts/gates/dependency-licences.php")
    ;;
esac

# The worker-mode text sweep. Its own scope is exclusion-based over every tracked file, so any
# path list here is necessarily narrower than the gate's — which is FINE per the routing note
# above, and is why the list is the config surface rather than an attempt at completeness.
case "$rel" in
  *.env|*.env.*|.env|.env.*|*compose*.y*ml|*Dockerfile*|*Caddyfile*|Makefile|*/Makefile|*.json)
    gates+=("worker-mode-blocked:bash scripts/gates/worker-mode-blocked.sh")
    ;;
esac

# The forgeable-tenancy sweep. ROUTED SINCE 2026-08-23 (round 4, R4K-4) — before that it was in
# `gate:architecture` and reachable from this hook for NO file type, while also being absent from the
# "deliberately not here" list above, so no decision was recorded in either direction. It is a SECURITY
# gate: `HeaderTenantResolver` trusts an `X-Tenant-Id` header verified by nothing, so a deployment where
# `TWES_TRUST_TENANT_HEADER` is not `0` lets any caller act as any tenant whose id they can produce.
#
# THE CONFIG SURFACE, NOT EVERY WRITE, and the reason is a measurement rather than the usual bias. This
# hook's own note above says "over-routing costs milliseconds" and prefers to run a gate when in doubt;
# that premise does not hold here — this one is **5.2s, 6.4s, 11.6s** over three runs [measured
# 2026-08-23], the most expensive gate in the set. Routing it on `*.php`, the commonest write there is,
# would put five to eleven seconds on every edit to buy a check of a knob that appears in no PHP file
# except a docblock, which this gate's own first version false-positived on.
#
# So: dotenv, compose and YAML (`api/config/services.yaml` is where the knob is WIRED —
# `$permitted: '%env(bool:TWES_TRUST_TENANT_HEADER)%'`), Dockerfiles, the entrypoint shell, and tracked
# JSON. That covers every file in the tree that carries the knob today. `composer gate` still runs it
# over EVERYTHING tracked, so a `.php` assignment is caught before it can be committed — which is the
# same trade the note above describes: under-routing defers a finding, it does not lose one.
case "$rel" in
  *.env|*.env.*|*compose*.y*ml|*.yaml|*.yml|*Dockerfile*|*.sh|*.json)
    gates+=("no-forgeable-tenancy:bash scripts/gates/no-forgeable-tenancy-in-production.sh")
    ;;
esac

((${#gates[@]})) || exit 0

# A FILE CLAUDE HAS JUST CREATED IS UNTRACKED, AND HALF THE GATES CANNOT SEE IT.
#
# Every gate here enumerates its subjects from `git ls-files` rather than a filesystem walk —
# ruled in § Gotchas 2026-07-31, because a parallel certification round places reviewer
# worktrees INSIDE the working tree and a recursive walk then reads several repositories at
# once. But the gates split on ONE flag, and the split is invisible from the outside:
#
#   `--cached --others --exclude-standard`  spdx-headers.sh, shell-syntax.sh   — sees new files
#   `--cached` only                        no-orphaned-docblocks.php,          — does NOT
#                                          no-owner-connection-in-application.php,
#                                          worker-mode-blocked.sh, compose-config.sh
#
# So without the block below, writing a BRAND-NEW `.php` file gets it checked by the SPDX gate
# and silently ignored by the orphaned-docblock gate — the one that exists because three
# successive certification rounds filed a stranded doc comment. A hook that reports clean on a
# file that will fail at commit time is precisely the "a control that silently does not run"
# shape this repository records four times, and a new file is the highest-risk write there is.
#
# `GIT_INDEX_FILE` is the fix, and it is the gates' OWN mechanism rather than a workaround:
# `git ls-files` honours it, so pointing it at a THROWAWAY index containing HEAD's tree plus
# this one path makes every `--cached` gate see the file, with the real index untouched. Two
# alternatives were rejected: `git add --intent-to-add` on the real index (a hook must not
# leave state behind — the developer would find a staged path they never staged), and teaching
# each gate `--others` (four gates changed to serve a hook, and `--others` in `worker-mode-
# blocked.sh` would additionally widen a security control's scope for an unrelated reason).
#
# Contents are read from the WORKING TREE by every gate, so the index only ever contributes
# the NAME. That is why a stale HEAD tree is harmless here.
if git rev-parse --verify -q HEAD >/dev/null 2>&1; then
  scratch_index="$(mktemp -u "${TMPDIR:-/tmp}/twes-hook-index.XXXXXX")"
  if GIT_INDEX_FILE="$scratch_index" git read-tree HEAD 2>/dev/null; then
    # `--force` so a path under a `.gitignore` rule is still added; the routing above has
    # already excluded the generated trees, and anything else reaching here is ours.
    #
    # NO `|| true` HERE, DELIBERATELY. The obvious spelling is
    # `git add … 2>/dev/null || true`, and it would be a bandaid with no evidence behind it:
    # `git add` failing means the new file is NOT in the throwaway index, so the `--cached`
    # gates silently do not see it — which is the exact defect this whole block exists to
    # close, reintroduced by the line meant to be tolerant of it. Instead the scratch index is
    # DISCARDED on failure, so the gates run against the real index: the same degradation,
    # reached deliberately, and identical to the behaviour before this block existed.
    if [[ -f "$abs" ]] && ! GIT_INDEX_FILE="$scratch_index" git add --force -- "$rel" 2>/dev/null; then
      rm -f "$scratch_index"
      scratch_index=""
    fi
    [[ -n "$scratch_index" ]] && export GIT_INDEX_FILE="$scratch_index"
  fi
fi
cleanup() { [[ -n "${GIT_INDEX_FILE:-}" ]] && rm -f "$GIT_INDEX_FILE"; }
trap cleanup EXIT

failed=0
report=""
for entry in "${gates[@]}"; do
  label="${entry%%:*}"
  command="${entry#*:}"
  if ! out="$($command 2>&1)"; then
    failed=$((failed + 1))
    report+="── ${label} FAILED (scripts/gates/) ──"$'\n'"${out}"$'\n'
  fi
done

if ((failed)); then
  printf 'gates-on-write: %d gate(s) fail after writing %s.\n' "$failed" "$rel" >&2
  printf '%s' "$report" >&2
  exit 2
fi

exit 0
