# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# ==================================================================================================================
# THE WORKER ORACLE. Ask the SERVER whether worker mode is on, instead of asking the files.
#
# WHY IT EXISTS. Five successive text-scanning versions of this control were each defeated within one certification
# round, and the fifth REGRESSED against the fourth. Every version enumerated something -- forbidden values, then
# locations, then path patterns, then a normalisation chain, then a comment/continuation grammar -- and the last one
# became the attack surface itself. `frankenphp adapt` resolves the effective configuration the way the server will:
# it splices every `{$...}` placeholder from the real environment, parses Caddy's grammar, follows an `import`, and
# emits the JSON the server would run. A seam in any spelling, a `SERVER_NAME` that closes one block and opens
# another, a YAML block scalar, an indirection, a directive nested inside `php_server` -- all reduce to one question
# the server itself answers.
#
# WHY PYTHON RATHER THAN THE BASH IT REPLACED. The first version built `docker -e KEY=VALUE` arguments in bash from a
# separator-joined list read with `read -r`, which CANNOT CARRY A MULTI-LINE VALUE -- and a multi-line value is
# precisely the attack. Two live injections (a YAML block-scalar seam and a multi-line `SERVER_NAME`) were silently
# truncated before reaching the server, so the oracle adapted a configuration nobody would ever run and reported it
# clean. Here the environment travels as a dict into `subprocess.run` with a list argv: no shell, no quoting, no
# separator, and a newline is just a byte in a string.
#
# FAILURE IS A VIOLATION, never a pass. Three distinct verdicts, kept distinct because collapsing them is what made
# the bash version report "a WORKER" on a clean tree: adapt exited non-zero; adapt succeeded but its output is not
# JSON; adapt succeeded and the config contains a worker.
# ==================================================================================================================

import json
import subprocess
import sys

# Every relation `caddy_config_paths` identified, and every service whose environment the server would splice. A
# service with no SERVER_NAME is not serving HTTP, so adapting for it would assert nothing.
PLACEHOLDER_THAT_MEANS_SERVING = 'SERVER_NAME'


def serving_services(rendered):
    for name, service in sorted((rendered.get('services') or {}).items()):
        env = service.get('environment') or {}
        if isinstance(env, list):
            env = dict((entry.split('=', 1) + [''])[:2] for entry in env)
        env = {k: ('' if v is None else str(v)) for k, v in env.items()}
        if PLACEHOLDER_THAT_MEANS_SERVING in env:
            yield name, env


def adapt(image, repo_root, caddy_relative, env):
    argv = ['docker', 'run', '--rm']
    for key, value in sorted(env.items()):
        argv += ['-e', '%s=%s' % (key, value)]
    argv += [
        '-v', '%s/%s:/etc/caddy/Caddyfile:ro' % (repo_root, caddy_relative),
        '--entrypoint', 'frankenphp', image,
        'adapt', '--config', '/etc/caddy/Caddyfile',
    ]
    done = subprocess.run(argv, capture_output=True, text=True)
    if done.returncode != 0:
        first = (done.stderr or done.stdout or '').strip().split('\n')[0]
        return 'unparseable-config', first
    try:
        return 'ok', json.dumps(json.loads(done.stdout)).count('"workers"')
    except (ValueError, TypeError):
        return 'not-json', (done.stdout or '')[:120]


def main():
    image, repo_root, overlay = sys.argv[1], sys.argv[2], sys.argv[3]
    configs = [c for c in sys.argv[4].split('\n') if c]
    count_path = sys.argv[5]
    rendered = json.loads(sys.stdin.read())

    checked = 0
    failures = 0

    for service, env in serving_services(rendered):
        for caddy_relative in configs:
            verdict, detail = adapt(image, repo_root, caddy_relative, env)
            checked += 1

            if verdict == 'unparseable-config':
                print('  FAIL — %s: the effective Caddy config for `%s` (%s) DOES NOT PARSE, so "no worker" was '
                      'never asserted. An unverifiable configuration is a violation, not a pass: %s'
                      % (overlay, service, caddy_relative, detail))
                failures += 1
            elif verdict == 'not-json':
                print('  FAIL — %s: `frankenphp adapt` succeeded for `%s` (%s) but its output is not JSON, so '
                      '"no worker" was never asserted.' % (overlay, service, caddy_relative))
                failures += 1
            elif detail != 0:
                print('  FAIL — %s: `frankenphp adapt` reports a WORKER in the effective config for `%s` (%s). '
                      'Worker mode is blocked until the client portal (Wave 10) has its own random_bytes(32) token: '
                      'UuidV7 seeds per PROCESS, so a resident worker lets a recoverable seed span TENANTS. See '
                      'CLAUDE.md Gotchas 2026-08-05.' % (overlay, service, caddy_relative))
                failures += 1

    if checked == 0:
        print('  FAIL — %s: the worker oracle ran ZERO times, so it asserted nothing.' % overlay)
        failures += 1
    elif failures == 0:
        print('  ok   — %s: `frankenphp adapt` reports no worker in %d effective config(s)' % (overlay, checked))

    # The COUNT goes to a FILE, not to stderr. The first version swapped file descriptors in the caller
    # (`2>&1 1>&3 3>&-` inside a command substitution) and the whole block silently produced NO OUTPUT AT ALL --
    # a gate that printed nothing and asserted nothing. A path argument cannot be got wrong that way, and it keeps
    # the caller from having to grep its own human output for its own verdict.
    with open(count_path, 'w', encoding='utf-8') as handle:
        handle.write('%d\n' % failures)


main()
