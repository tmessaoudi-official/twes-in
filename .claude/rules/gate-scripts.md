---
paths:
  - "scripts/gates/**"
  - "scripts/tests/**"
---
# Writing a gate or its test — twes-in

What a session needs while working in `scripts/gates/` or `scripts/tests/`, beside CLAUDE.md, which keeps the process, the licensing
invariants, git, the commands and the lessons that apply before any file is read. A new lesson of this area goes at
the end of § Lessons here.

## Lessons

- The toolchain's `TMPDIR` is outside the working tree: `vendoredFonts` asks git which paths are ignored, and a fixture
  built under `var/` (ignored) is invisible to it, which read as four gate-test failures that passed on the host.
- A gate that checks the leaves does not check the branch: `permission-labels.sh` passed green while the two biggest
  group HEADINGS above those labels were raw dotted keys. Ask what sits one level up from whatever a new gate
  enumerates (2026-09-20). Its floor is what then caught the follow-up — the gate read the `KnownPermissions` PORT,
  whose file exists and holds no groups, so three headings vanished silently; a discovery input that names a file must
  red when the file yields nothing, and the floor must sit ABOVE what the other half alone produces.
- The other half of that: a key BUILT by interpolation is invisible to the gate that greps for it. `VenuePlanSettings`
  first wrote `"settings.venue.shape.$shape.$side"` in a loop, so `setting-labels.sh` saw eleven keys where nineteen
  were declared and passed — its floor was below what the other declarations alone make (2026-09-21). Write a key a
  gate must find out in full, and set the floor so losing one file's worth reds.
- `GROUPS` is a bash special variable (the current user's group ids): assigning to it is silently ignored and it
  expands to a number. A test fixture whose JSON came out as `{1000,...}` was that, not a quoting bug (2026-09-20).
- A mutant that does not TYPE-CHECK tests nothing, and reads exactly like a test that fails to notice: two sabotages
  showed no failures because the build had died, not because the guarantee was uncovered. Print the tally, not just
  the failures — an absent `Tests N passed` line is the tell (2026-09-20).
- A test's `mktemp -d` with no `trap … EXIT` stays in the toolchain's TMPDIR, which lives on the host's tmpfs across runs: 35,098
  per-case git fixtures (about 22 inodes each) used all of `/tmp`'s inodes and froze every session on the machine (2026-10-09).
  Every gate test now runs through `scripts/gates/tests/no-leftovers.sh`; a new one starts with the `TMPDIR=$(mktemp -d)` line.
  A PHP test's `tempnam` is the same leak: `ApiTestCase::uploadFile` left one file per upload, 142 a suite run, until it removed it.
- A test that makes commits or tags exports `GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1` (and `no-leftovers.sh`
  does for every gate test): on the host, the developer's global `commit.gpgsign` signed every fixture with the real
  release key, and a locked agent turned the same tests red. A repository's own `.git/config` still applies (2026-10-17).
- `git commit-tree` never reads `commit.gpgsign`: every `make commit-gated` from 21 to 23 Oct went to master unsigned
  (67, and 9 more on 10 Oct that a recovery rebuilt and moved there by `update-ref`; all re-signed on 2026-10-24). A commit made through plumbing
  passes `-S` itself; check `git log --format=%G?` after one. A gate test exporting `GIT_CONFIG_GLOBAL=/dev/null` cannot
  see it: its fixture sets the repository's own `commit.gpgsign` and a stand-in `gpg.program`.
- An exclude pathspec lies under the toolchain's git: `git ls-files -- 'web/src/app/*.ts' ':!*.spec.ts'` listed the files
  on the host's git 2.56 and NOTHING in the tools image's 2.47 once a spec was staged, so `read-retry.sh`'s test passed
  on the host and reddened the snapshot gate. Filter with `| grep -v '\.spec\.ts$'`, as the sibling gates do, and run a
  new gate's test once through `make tools CMD=…` before gating (2026-11-06).
