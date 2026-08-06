#!/usr/bin/env bash
# Refuse a FORGEABLE tenancy claim being trusted in any tracked configuration.
#
# `HeaderTenantResolver` trusts an `X-Tenant-Id` header that is verified by nothing. Enabling it on a reachable
# deployment lets any caller act as any tenant whose id they can produce — a cross-tenant read of every client's
# invoices, which is a reportable data breach rather than a bug. It exists only so Wave 1's HTTP surface can be
# exercised before Wave 7 writes authentication.
#
# WHY A GATE AND NOT A COMMENT. `CLAUDE.md` § Gotchas records four separate instances of a control that existed
# only in prose and was enforced by nothing, and the whole point of this project's gate directory is that a rule
# nobody can run is not a rule. Configuration documented as development-only becomes production configuration the
# first time somebody copies a file. The precedent is `worker-mode-blocked.sh`: a capability deliberately refused
# by a gate until the wave that makes it safe, deleted WITH that wave rather than before it.
#
# WHAT IT CHECKS, and the polarity is inverted for the reason `worker-mode-blocked.sh` needed three defeats to
# learn — enumerating the forbidden thing is fail-open for every spelling nobody thought of. So:
#
#   * EVERY tracked file is in scope unless it matches a short exclusion list. Not an inclusion list of paths:
#     version 3 of the worker-mode gate was beaten by `api/.env.prod`, a Dockerfile variant and a `composer.json`
#     key, all of which an inclusion list had never heard of.
#   * A line that ASSIGNS the knob must assign exactly `0`. Not "must not say 1" — that admits `true`, `yes`,
#     `on`, `TRUE`, a quoted `"1"` and a value assembled from two files. One permitted value closes all of them at
#     once, because the gate stops asking what a value MEANS. And "assigns" is itself a derived test rather than a
#     literal-line comparison, because prose legitimately names the knob — see ASSIGNMENT_SHAPE, which exists
#     because the first version of this gate reported its own subject's PHP docblock as configuration.
#
# It needs nothing installed — no Docker, no vendor tree, no database — so it can never skip.
#
# DELETE IN WAVE 7, together with `HeaderTenantResolver`, `TWES_TRUST_TENANT_HEADER` and this gate's cases in
# `test-gates.sh`. Not before: an authenticated resolver landing while this remains enabled anywhere would leave a
# second, forgeable path to the same privilege.
#
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# The knob. One name, so it is greppable; if a second ever appears this list is where it goes.
readonly KNOB='TWES_TRUST_TENANT_HEADER'

# The ONLY value an ASSIGNMENT of the knob may carry. Exactly one, and that is the polarity inversion
# `worker-mode-blocked.sh` needed three defeats to learn: a rule of the form "must not be 1" admits `true`, `yes`,
# `on`, `TRUE`, a quoted `"1"` and a value assembled from two files, whereas one permitted literal closes every
# spelling at once — because the gate stops asking what a value MEANS.
readonly PERMITTED_VALUE='0'

# WHAT COUNTS AS AN ASSIGNMENT, as opposed to prose that merely names the knob. After the knob, only closing
# quotes, brackets and whitespace may appear before an `=` or a `:`.
#
# This rule replaced an exact-line comparison that FAILED ON THIS GATE'S OWN SUBJECT on the first run: PHP
# comments are `/** */` and `//`, not `#`, so a docblock sentence reading "TWES_TRUST_TENANT_HEADER, which is 0 in
# every committed configuration" was read as configuration and reported twice.
# `worker-mode-blocked.sh` records the identical false positive — "comment text was read as configuration" — and
# the fix here has the same shape as that one's.
#
# It deliberately still catches `$_ENV['TWES_TRUST_TENANT_HEADER'] = '1';`, which is why `.php` is NOT excluded
# from scope: a PHP file writing the environment is a real evasion route, and excluding the extension to silence a
# comment would have been fail-open for it.
readonly ASSIGNMENT_SHAPE='^[]"'"'"'[:space:]]*[:=]'

# Scope by EXCLUSION. Prose may discuss the knob freely — that is what documentation is for — and the gate's own
# source has to name it in order to check for it.
readonly -a EXCLUDED_PATTERNS=(
  '\.md$'
  '^docs/'
  '(^|/)tests?/'
  '^scripts/gates/'
  '^\.claude/'
)

if [[ "${1:-}" == "--dump-rules" ]]; then
  printf 'knob %s\n' "$KNOB"
  printf 'permitted %s\n' "$PERMITTED_VALUE"
  printf 'assignment_shape %s\n' "$ASSIGNMENT_SHAPE"
  printf 'excluded %s\n' "${EXCLUDED_PATTERNS[*]}"
  exit 0
fi

# `git ls-files`, never a filesystem walk: § Gotchas 2026-07-31 records that a parallel certification round places
# reviewer worktrees INSIDE the working tree, so a recursive walk reads several repositories at once. `--others`
# is included so a file somebody has just written — which is exactly when this mistake is made — is in scope.
mapfile -d '' -t tracked < <(
  git -C "$REPO_ROOT" ls-files -z --cached --others --exclude-standard 2>/dev/null
)

if ((${#tracked[@]} == 0)); then
  printf 'no-forgeable-tenancy-in-production: FAIL — `git ls-files` returned nothing, so this gate inspected NOTHING.\n' >&2
  printf '       Run it from inside the repository.\n' >&2
  exit 1
fi

violations=0
inspected=0

for path in "${tracked[@]}"; do
  [[ -n "$path" ]] || continue

  excluded=0
  for pattern in "${EXCLUDED_PATTERNS[@]}"; do
    if [[ "$path" =~ $pattern ]]; then
      excluded=1
      break
    fi
  done
  ((excluded)) && continue

  # A file that cannot be read is a VIOLATION, not a skip: an unreadable file is an uninspected file, and this
  # gate's whole value is that it inspected everything it claims to have inspected.
  if [[ ! -r "$REPO_ROOT/$path" ]]; then
    printf 'no-forgeable-tenancy-in-production: FAIL — %s is tracked but unreadable, so it was not inspected.\n' "$path" >&2
    violations=$((violations + 1))
    continue
  fi

  inspected=$((inspected + 1))

  # Binary files have no lines to reason about and `grep -I` skips them.
  while IFS= read -r line; do
    # COMMENT LEADERS STRIPPED ONCE, into a variable every check below reads — § Gotchas 2026-07-29: decide a
    # condition once, where every path reads it. A comment mentioning the knob is documentation, not configuration.
    #
    # THREE LEADERS, not one, and the second and third were added because the meta-suite caught them: `#` covers
    # dotenv, YAML and shell, but PHP uses `//` and `/** … */`. A `//` comment reading
    # `// set TWES_TRUST_TENANT_HEADER: it is 0 everywhere` has the knob followed by a colon, so ASSIGNMENT_SHAPE
    # matched it and the extracted "value" was a sentence. This is the same false-positive class
    # `worker-mode-blocked.sh` records — "comment text was read as configuration" — arriving through a different
    # comment syntax, which is exactly why the handling belongs in one place.
    code="${line%%#*}"
    code="${code%%//*}"
    # A docblock continuation line (` * TWES_… `). Stripped by removing a leading `*`, which cannot begin a real
    # assignment in any format this repository tracks.
    code="${code#"${code%%[![:space:]]*}"}"
    code="${code#\*}"

    if [[ "$code" != *"$KNOB"* ]]; then
      continue
    fi

    from_knob="${code#*"$KNOB"}"

    # PROSE, not configuration. See ASSIGNMENT_SHAPE for why this test exists and what it must keep catching.
    if [[ ! "$from_knob" =~ $ASSIGNMENT_SHAPE ]]; then
      continue
    fi

    # The VALUE: everything after the first `=` or `:`, stripped of quotes, whitespace and statement punctuation.
    value="${from_knob#*[:=]}"
    value="${value//\'/}"
    value="${value//\"/}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    value="${value%;}"
    value="${value%,}"

    if [[ "$value" != "$PERMITTED_VALUE" ]]; then
      printf 'no-forgeable-tenancy-in-production: FAIL — %s assigns %s a value other than %s:\n' \
        "$path" "$KNOB" "$PERMITTED_VALUE" >&2
      printf '         %s\n' "${KNOB}${from_knob}" >&2
      printf '       Trusting the X-Tenant-Id header lets ANY caller act as ANY tenant — a cross-tenant read of\n' >&2
      printf '       every client'"'"'s invoices, which is a reportable data breach rather than a bug.\n' >&2
      printf '       The ONLY permitted value is %s; `true`, `on`, `yes` and `"1"` all fail here on purpose.\n' \
        "$PERMITTED_VALUE" >&2
      printf '       If you need it locally, set it in an UNTRACKED override, never in a committed file.\n' >&2
      violations=$((violations + 1))
    fi
  done < <(grep -I -h '' "$REPO_ROOT/$path" 2>/dev/null || true)
done

# ANTI-VACUITY. A gate that inspected nothing prints OK too, and this project has caught that exact shape more
# than once — so the count is printed unconditionally and a zero is a failure.
printf 'counts — inspected=%d violations=%d\n' "$inspected" "$violations"

if ((inspected == 0)); then
  printf 'no-forgeable-tenancy-in-production: FAIL — inspected 0 files, so every assertion above was vacuous.\n' >&2
  exit 1
fi

if ((violations > 0)); then
  printf 'no-forgeable-tenancy-in-production: FAIL — %d violation(s).\n' "$violations" >&2
  exit 1
fi

printf 'no-forgeable-tenancy-in-production: OK — every assignment of %s in %d inspected file(s) is %s.\n' \
  "$KNOB" "$inspected" "$PERMITTED_VALUE"
