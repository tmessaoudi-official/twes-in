# dev / prod separation Plan

Developer instruction, 2026-08-04: *"i think we should distinguish more between dev and prod. for example
build-admin should have dev and prod version! even make up in dev should be that vendor are in a volume so
developing and debugging with an ide should work flawlessly! and maybe there are other things! dev should be an
easy env to debug and test and prod should be very optimized and closed and secure!"*

The governing sentence is the last one, and the two halves pull in opposite directions on purpose:

| | dev | prod |
|---|---|---|
| optimise for | **the developer's loop** — edit, step-debug, inspect, re-run | **the request** — throughput, and the smallest reachable surface |
| acceptable cost | a slower, larger, more open container | a container that is painful to poke at |
| the test of a change | *can I set a breakpoint and see why this number is wrong?* | *what can an attacker who reaches PHP execution do next?* |

Everything below is derived from that table. Where the two want different things, they get different artefacts
rather than one artefact with a flag — because a shared artefact is how a dev convenience reaches production.

## Decisions Log

- [2026-08-04 22:55] AGREED: dev and prod get SEPARATE Dockerfile TARGETS (`dev`, `runtime`) sharing one `base`
  stage, rather than one image whose behaviour is switched by environment variables. A shared image cannot both
  omit Xdebug and contain it.
- [2026-08-04 22:55] AGREED: **`api/vendor` is BIND-MOUNTED from the host in dev, not held in a named volume** —
  the developer asked for "vendor in a volume", and this keeps the GOAL (an IDE that resolves vendor classes and
  can step into vendor frames) while rejecting the mechanism, because a named volume cannot achieve it: an IDE
  indexes the project directory on the host, and a named volume lives in root-owned `/var/lib/docker/volumes/`
  where nothing indexes it. Installed BY THE CONTAINER (`make install`), which is what answers CLAUDE.md's
  standing objection to a host-installed vendor tree ("the container runs whatever PHP version resolved on the
  host"). The container's PHP resolves it; the host merely holds the bytes.
- [2026-08-04 22:55] AGREED: dev keeps a **named volume for `api/var`** only. Cache and logs are the one thing
  that genuinely wants to be container-side: they are regenerated, they are large, and on a bind mount they
  produce root-owned files in the developer's working tree.
- [2026-08-04 22:55] AGREED: Xdebug ships in the `dev` target ONLY, in `xdebug.mode=off` by default and armed
  per-request by a trigger. An always-on debugger costs 2–5× on every request, so "on but triggered" is what
  makes it usable rather than something the developer turns off and forgets to turn back on.
- [2026-08-04 22:55] AGREED: the front-end tiers take a BUILD ARGUMENT for their configuration
  (`NG_CONFIGURATION`, `FLUTTER_BUILD_MODE`) rather than gaining a second Dockerfile. Unlike the API tier there
  is no security delta between a dev and a prod bundle — only optimisation and source maps — so one artefact
  with a flag is correct here and a second Dockerfile would be duplication.
- [2026-08-04 22:55] AGREED: `--no-web-resources-cdn` stays on the Flutter build in BOTH modes. It is a GDPR
  control (it stops the engine fetching fonts from `fonts.gstatic.com`), not an optimisation, and two tests
  assert the built bundle reaches no external origin. A dev bundle that phones home is still phoning home.

- [2026-08-05 01:30] AGREED: production drops ALL Linux capabilities on EVERY service, adding back only what each
  provably needs — established by dropping ALL and reading the failure, never by reputation. Asserted by
  `scripts/gates/compose-config.sh` as a full-set check over the rendered configuration, prod-scoped, with two
  mutants proving it fires.
- [2026-08-05 01:30] AGREED: pid limits are expressed as `deploy.resources.limits.pids`, not the top-level
  `pids_limit`. Compose normalises the latter into the former and then refuses both as a duplicate; the chosen
  spelling also sits beside `memory`, which is how every other limit in the file is written.

## Formal Plan

### A. The API image gains a `dev` target

`base` (PHP + extensions) is shared. Then:

- `vendor` — `composer install --no-dev`, optimised autoloader. Feeds `runtime`.
- `runtime` — PROD. Unchanged in kind: warm prod cache, `chmod 555`, preload, JIT, no Composer, no dev deps.
- `dev` — NEW. Derives from `base`. Carries Xdebug, Composer, `git`/`unzip`. No warm prod cache (the source
  mount hides it), no `555`, no preload. Every one of those absences is a positive dev property, not a
  degradation: the cache must be writable because the framework recompiles on every edit.

### B. Dev mounts the whole `api/` tree

Today the overlay mounts `src`, `config`, `public`, `migrations`, `templates` individually and deliberately
leaves `vendor` in the image. That is exactly what breaks the IDE: `api/vendor` does not exist on the host at
all, so nothing resolves `Symfony\...`, and a breakpoint in a vendor frame has no file to open.

So: `../api:/app` read-write, with `api-dev-var:/app/var` layered on top so cache and log stay container-side.

Consequence to accept: `api/vendor` must exist before the stack can serve. `make up` therefore ensures it.

### C. Makefile

| target | does |
|---|---|
| `install` | run Composer INSIDE the api container, output onto the host tree |
| `deps` | `install` only if `api/vendor/autoload.php` is missing — the cheap guard `up` depends on |
| `composer CMD="require foo/bar"` | arbitrary Composer, in the container, so the host needs no PHP |
| `build-front` | production bundles (unchanged behaviour, explicit configuration) |
| `build-front-dev` | development bundles — source maps, unminified |
| `debug-on` / `debug-off` | flip `XDEBUG_MODE` without editing a file |

### D. Prod hardening pass

`compose.prod.yaml` ALREADY had more than this section first assumed — `no-new-privileges`, `read_only`, `tmpfs`,
memory limits, log rotation and replica counts were all present. Checking before writing turned a planned list of
six additions into three real gaps:

- **capabilities** — nothing dropped any. This is the significant one, because capabilities are orthogonal to
  `read_only`: Docker's default set includes `NET_RAW`, which is raw sockets on the network the database sits on.
- **pid limits** — memory was bounded, process count was not, so a fork bomb was a host-level denial of service.
- **`migrate` was absent from the file entirely** — the one container holding the OWNER credential had no
  hardening at all. Found by a full-set check rather than by reading the list of long-running services.

### E. Verify

Both stacks must come up. `make up` → six services, `/health/ready` 200. `make config-prod` must render. The
API gate must stay green throughout.

## Owed / not done in this pass

- An `ng serve` HMR dev server for the admin tier (a bundle rebuild is not a dev loop). Noted, not built.
- FrankenPHP worker mode in prod — the single largest throughput win available, and reachable now that
  `autoload_runtime.php` is in use. Deliberately NOT enabled here: it changes request isolation semantics and
  wants its own certification round.
