---
name: pre-commit
description: Use before every git commit — analyses staged changes for blast-radius, produces the four-dimension evidence table (Coverage, Docs, Config, Blast radius) from the global framework's Rule 6, then presents the exact git commit command for manual execution.
user-invocable: true
args: "[--message=<draft-message>]"
disallowed-tools: AskUserQuestion
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  twes-in ADAPTATION (2026-07-29) of the pdfturbo container port (2026-07-27), which itself came
  from the developer's machine bundle `claude-setup-global-20260722` via the phorj port. The port's
  machinery (plain-text questions, reviewer-subagent certification, `var/claude/**` reports, the
  ≤5-agent cap) is kept verbatim; what was RE-GROUNDED is the domain. pdfturbo's PDF-editor hooks —
  export byte-identity claims, redaction burns, DOCX in-place saves, IndexedDB/`SCHEMA_VERSION`
  persistence, 3-locale key sync, canvas/jsdom test split — are gone, because twes-in has no
  analogue for them. What is load-bearing here instead: money and tax arithmetic, invoice/quote
  state machines, multi-tenant data isolation, API-contract stability for the Angular and Flutter
  clients, e-invoicing (Peppol / EN16931 / Factur-X) schema validity, PDF document rendering, and DB
  migration safety. These deltas OVERRIDE the body below wherever they conflict:

  1. QUESTIONS ARE PLAIN TEXT. `AskUserQuestion` TIMES OUT in this cloud container, so a gate that
     "asks" cannot fire. Every "invoke ask-human" below means: print the question, a minimal
     concrete example, numbered options, and the recommended option FIRST with its reason, as
     ordinary prose — then STOP and wait. Protocol: `.claude/skills/ask-human/SKILL.md`.
  2. NO `advisor()` HERE — the tool does not exist in this environment. Independent certification =
     fresh-context read-only reviewer subagents, run as the three twes-in lenses
     (`domain-correctness-reviewer`, `tenancy-security-reviewer`, `completeness-reviewer` — see
     `/converge`). All three are REAL agent definitions in `.claude/agents/` — spawn them by name
     via the Agent tool rather than re-describing their charter inline, so each lens's attack surface
     stays in one place. Self-grading is the last resort and MUST be disclosed as self-graded.
  3. REPORTS GO TO `var/claude/…` in the repo — gitignored via `/var`, survives
     compaction inside the session, never committed. NOT `~/.claude/projects/…`: that is wiped when
     the container is reclaimed, so a report written there is lost. Never `git add` a report regardless
     — being ignored is what keeps them out of history, not what makes staging them harmless.
  4. `--scope=global|both` IS REMOVED wherever it appears: `~/.claude/` in this container is
     GENERATED from repo files by `scripts/claude-bootstrap/install.sh`, so auditing it audits a copy.
  5. ≤5 concurrent subagents (10 caused ~50% rate-limit failures upstream). Every pipeline agent
     writes its raw output to `var/claude/<stage>/raw/` BEFORE returning — autocompact fires at 80%
     here and in-conversation results do not survive it.
  6. PROJECT RULES WIN on any conflict: `/home/user/twes-in/CLAUDE.md`. It EXISTS and is
     authoritative — READ IT. It carries the licensing invariants, the certification ladder, the
     git-autonomy override, the architecture rules (hexagonal, framework-free `Domain/`), the quality
     gate, and the in-repo plan home (`docs/plans/<topic>.plan.md`, each plan carrying its own
     `## Decisions Log`). On any conflict with a delta above, CLAUDE.md wins.
  7. THREE TOOLCHAINS, NONE OF THEM BUILT YET. twes-in is a Symfony (PHP) REST API + an Angular
     admin front end + a Flutter mobile/desktop app, over Postgres, with Docker Compose for local
     dev. As of 2026-07-29 there is **no `src/` tree of any kind** — what the repo does hold is
     `CLAUDE.md`, `README.md`, `VISION.md`, `LICENSE`, `LICENSING.md`, `THIRD-PARTY-NOTICES.md`,
     two plan files under `docs/plans/` (both authoritative — one is mandatory reading before any
     application code), `.claude/`, `scripts/claude-bootstrap/`, and `.gitignore` (which is where
     `/var`, `/qa-shots/` and the reference-clone guards these deltas rely on are declared). So: never hardcode a build, test
     or lint command. Read `composer.json`, `package.json` and `pubspec.yaml` for the real script names
     (typically `vendor/bin/phpunit` / `vendor/bin/phpstan` / `vendor/bin/php-cs-fixer` for the API,
     `npm run lint` / `npm run test` / `ng build` for Angular, `flutter analyze` / `flutter test`
     for the app — verify, do not assume). When the stack a step needs is absent, say so and skip
     the step. A finding invented about code that does not exist is worse than an empty report.
  8. CLEAN-ROOM, AND IT IS LEGALLY LOAD-BEARING. twes-in is *inspired by* Invoice Ninja, NOT forked
     from it. Read-only study clones live under `/tmp/xxx/` and are outside this repo: never copy
     code or text from them, never cite a `/tmp/xxx` path as project source, and never treat
     "upstream does it differently" as a finding on its own.
═══════════════════════════════════════════════════════════════════════════════════════════════ -->
## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then immediately STOP — do not execute any other steps. (`--help` takes precedence over all other flags.)
>
> ```
> /pre-commit — Staged-diff gate: blast-radius analysis + four-dimension evidence table + exact commit command.
>
> Usage: /pre-commit [--message=<draft-message>]
>
> Flags:
>   --message=<text>   Seed the commit message with a draft (Claude will refine it)
> ```

---

## Differentiation from related skills

| Skill | Scope | Use when |
|-------|-------|----------|
| `/sweep` | Full codebase, read-only smell review | You want architectural smell detection across all files, no git integration |
| `/pre-commit` | Staged diff only, commit ritual gate | You are about to commit — need blast-radius check + evidence table + commit command |

---

## Side effects

**None** — this skill is read-only. It runs `git diff --staged` and `grep` to analyse staged changes, then displays a report and commit command. It never calls `git commit` and never modifies any file. Output is displayed in conversation only — not persisted to disk; the git commit is the durable artifact.

---

## Step 0 — Precondition checks

**No task gate here (twes-in adaptation).** Upstream stops to confirm size and intent before Step 1.
This repo's standing directive is no interrupts, and `git add` / `git commit` / `git push` are all
**autonomously authorised** (CLAUDE.md § "Git autonomy") — so this skill's job is the evidence table
and the blast-radius check, never asking permission. State the task size in one line and continue.

**When this skill DOES stop**: only if an evidence row comes back INCOMPLETE (Step 5) — that is a real
finding, not a check-in, and it is reported in plain text per `/ask-human`.

**Git checks** (in order; stop at first failure):
1. Verify `git` is installed: `command -v git` — if not found, report `ERROR: git not found in PATH — cannot run /pre-commit` and stop.
2. Verify inside a git repo: `git rev-parse --is-inside-work-tree 2>/dev/null` — if fails, report `ERROR: Not inside a git repository` and stop.
3. Check staged changes exist: `git diff --staged --name-only` — if empty, report `ERROR: No staged changes. Stage files with git add before running /pre-commit.` and stop.
4. Detect active merge/rebase: test for `.git/MERGE_HEAD` and `.git/rebase-merge/` — if either exists, add `WARN: Merge or rebase in progress — evidence table will be produced but commit command will not be shown until the merge/rebase completes.`

---

## Step 1 — Inventory staged changes

Run `git diff --staged --stat` and `git diff --staged --name-only`. For each staged file, record:
- Status: Added / Modified / Deleted / Renamed
- File path and extension
- Lines changed summary

Classify each file:
- **Public interface** — CLI flags, env vars, public functions, documented commands, **REST API contracts (routes, request/response shapes, enums, error codes, pagination)**, hook behaviour, SKILL.md
- **Internal implementation** — logic, business rules, private helpers
- **Tests** — test files, fixtures, test helpers
- **Config/infra** — Dockerfiles, compose files, Makefile, shell scripts, YAML config, **DB migrations**
- **Docs** — CLAUDE.md, README, `docs/plans/<topic>.plan.md`, agent definitions

Two twes-in classifications that outrank the rest when they apply: a staged **DB migration** is
Config/infra *and* a blast-radius item (state whether it is reversible, and what it does to money or
tax columns); a staged change to an **API response shape** is a Public-interface change with two
independent consumers — the Angular admin and the Flutter app — so name both in the blast radius or
show the change is purely additive.

---

## Step 2 — Blast-radius analysis

For each file in the **Public interface** or **Config/infra** categories:
1. Extract the changed symbol, flag, function name, or path from the diff
2. Search all references **in the repo** — not `~/.claude/`, which is generated from repo files by `scripts/claude-bootstrap/install.sh` (adaptation delta 4) and would only echo the copy:
   `git grep -l -- "<symbol>" 2>/dev/null` (falls back to `grep -rl -- "<symbol>" "${CLAUDE_PROJECT_DIR:-$PWD}" --exclude-dir={.git,vendor,node_modules,var,build}`)
   For an API symbol, search the client trees too — a field name renamed in the API is a blast-radius item in the Angular and Flutter code that reads it.
3. For each hit, read the relevant line and determine if it is a caller, doc reference, or config entry that may need updating
4. Flag any reference NOT already present in the staged diff as a **potential blast-radius item**

If a staged file is a deletion: note that all remaining callers are blast-radius items.

---

## Step 3 — Four-dimension evidence table

Produce the completion gate table from CLAUDE.md Rule 6 — the reasoning framework installed by `scripts/claude-bootstrap/install.sh`, whose source of truth in this repo is `scripts/claude-bootstrap/CLAUDE-global.md` § 6. The project-level `CLAUDE.md` wins on any conflict; read its § "Quality gate" for the per-tier commands and its § "Licensing invariants" before staging anything whose provenance is an upstream clone:

```
| Dimension    | Status | Evidence |
|--------------|--------|---------|
| Coverage     | OK / INCOMPLETE | <test files staged for the stack that owns the change — read `composer.json` / `package.json` / `pubspec.yaml` for the actual runner rather than naming one from memory; OR `bash -n` / exit-code checks if infra; OR "stack not present yet — N/A with reason"> |
| Docs         | OK / INCOMPLETE | <SKILL.md / README / help text staged, OR "no public interface changed"> |
| Config       | OK / INCOMPLETE | <CLAUDE.md / skill or agent definition / compose or env sample staged, OR "no config impact — <reason>"> |
| Blast radius | OK / INCOMPLETE | <grep hits accounted for, OR list of unresolved references> |
```

**INCOMPLETE** rows block the commit command in Step 5. List exactly what must be staged to resolve each INCOMPLETE row.

Output is displayed in conversation only — not persisted to disk; the git commit is the durable artifact.

---

## Step 4 — Commit message

Parse `--message=<text>` from args if provided. Otherwise derive a draft from the staged diff:
- One imperative-mood subject line (≤72 chars): what changed and the functional reason
- Optionally 1-3 short bullet lines for non-obvious context

**Never append `Co-Authored-By: Claude`**, any Claude attribution, or a `Claude-Session` trailer — author and committer are `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>` (project `CLAUDE.md` § "Git autonomy", where this was RULED; restated in the global framework's Rule 10).

---

## Step 5 — Present commit command

If all four evidence rows are **OK**:

```
All four dimensions satisfied. Run this command:

git commit -m "$(cat <<'EOF'
<commit message here>
EOF
)"
```

If any row is **INCOMPLETE**: list what is missing and do NOT present the commit command. The commit is blocked until all dimensions are resolved — add the missing staged changes and re-run `/pre-commit`.

---

## Error handling summary

| Condition | Behaviour |
|-----------|----------|
| `git` binary not in PATH | ERROR + stop immediately |
| Not inside a git repo | ERROR + stop immediately |
| No staged changes | ERROR + stop immediately |
| Active merge or rebase | WARN — continue to evidence table, suppress commit command |
| `grep` unavailable or returns non-zero | Skip that symbol; note in blast-radius row as "grep unavailable for `<symbol>`" |
| Staged deletion | Note that all remaining callers of the deleted item are blast-radius items |
| Binary file staged | Note in coverage row as "binary file — no diff available; verify manually" |
